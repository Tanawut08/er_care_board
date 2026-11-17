<?php
// ===== ER Care Board — จอมอนิเตอร์ผู้ป่วยห้องฉุกเฉิน (อ่านอย่างเดียว) =====
// ผู้พัฒนา: ธนาวุฒิ สีดวง นักวิชาการคอมพิวเตอร์ปฏิบัติการ โรงพยาบาลบ้านไผ่
require __DIR__ . '/lib.php';
$CFG = cb_config();   // อ่าน config.php (ถ้ายังไม่มีจะพาไปหน้าติดตั้ง)
date_default_timezone_set($CFG['timezone']);
cb_gate($CFG, isset($_GET['api']));   // จำกัด IP / รหัสผ่านเข้าจอ (ถ้าเปิดใช้)

// อายุผลลัพธ์ที่ใช้ร่วมกันระหว่างจอ (วินาที) — ควรสั้นกว่ารอบ poll ของจอ
define('TL_CACHE_SEC', (int)$CFG['cache_sec']);

function tl_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO(
        "mysql:host=" . $GLOBALS['CFG']['db_host'] . ";port=" . (int)$GLOBALS['CFG']['db_port'] . ";dbname=" . $GLOBALS['CFG']['db_name'] . ";charset=utf8",
        $GLOBALS['CFG']['db_user'], $GLOBALS['CFG']['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => true, PDO::ATTR_TIMEOUT => 5]
    );
    $db->exec("SET NAMES 'utf8'");
    return $db;
}

function tl_json($d, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// NEWS2 (RCP 2017, SpO2 scale 1) คำนวณจากค่าที่มี — ระดับความรู้สึกตัวและการให้ออกซิเจนไม่มีในระบบ
// (กรอกแค่ ~1%) จึงสมมติว่า Alert/หายใจอากาศปกติ และแจ้ง 'missing' ให้จอแสดงว่าเป็นคะแนนประมาณการ
function tl_news2(?int $sbp, ?int $pr, ?int $o2, ?float $bt, ?int $rr): array {
    $parts = [
        'RR'  => $rr === null ? null : ($rr <= 8 ? 3 : ($rr <= 11 ? 1 : ($rr <= 20 ? 0 : ($rr <= 24 ? 2 : 3)))),
        'SpO2'=> $o2 === null ? null : ($o2 <= 91 ? 3 : ($o2 <= 93 ? 2 : ($o2 <= 95 ? 1 : 0))),
        'SBP' => $sbp === null ? null : ($sbp <= 90 ? 3 : ($sbp <= 100 ? 2 : ($sbp <= 110 ? 1 : ($sbp <= 219 ? 0 : 3)))),
        'PR'  => $pr === null ? null : ($pr <= 40 ? 3 : ($pr <= 50 ? 1 : ($pr <= 90 ? 0 : ($pr <= 110 ? 1 : ($pr <= 130 ? 2 : 3))))),
        'BT'  => $bt === null ? null : ($bt <= 35.0 ? 3 : ($bt <= 36.0 ? 1 : ($bt <= 38.0 ? 0 : ($bt <= 39.0 ? 1 : 2)))),
    ];
    $missing = array_keys(array_filter($parts, fn($x) => $x === null));
    $known = array_filter($parts, fn($x) => $x !== null);
    if (!$known) return ['score' => null, 'risk' => '', 'missing' => $missing];
    $score = array_sum($known);
    $risk = $score >= 7 ? 'high' : ($score >= 5 ? 'med' : (in_array(3, $known, true) ? 'lowmed' : 'low'));
    return ['score' => $score, 'risk' => $risk, 'missing' => $missing];
}

// ค่าที่เป็นไปไม่ได้ทางสรีระสำหรับผู้ป่วยที่ยังรออยู่ใน ER — ส่วนใหญ่คือกรอกผิดช่อง/พิมพ์ผิด
// ไม่ได้ตัดสินว่าผิดแน่ แค่ให้จอขึ้น "ตรวจสอบค่า" แทนการเตือนแดง และไม่เอาไปคิด NEWS2
function tl_suspect(?int $age, ?int $sbp, ?int $dbp, ?int $pr, ?int $o2, ?float $bt, ?int $rr): array {
    $child = $age !== null && $age < 12;
    $s = [];
    if ($sbp !== null && ($sbp < 50 || $sbp > 260 || ($dbp !== null && ($dbp < 20 || $dbp > 160 || $dbp >= $sbp)))) $s[] = 'bp';
    if ($pr !== null && ($pr < ($child ? 50 : 30) || $pr > 220)) $s[] = 'pr';
    if ($o2 !== null && ($o2 < 50 || $o2 > 100)) $s[] = 'o2';
    if ($rr !== null && ($rr < 4 || $rr > ($child ? 80 : 60))) $s[] = 'rr';
    if ($bt !== null && ($bt < 32 || $bt > 43)) $s[] = 'bt';
    return $s;
}

// สัญญาณชีพล่าสุดของแต่ละค่าจาก opdscreen_bp (เก็บทุกครั้งที่วัด ทั้งพยาบาลกรอกและเครื่องวัดส่งเข้า)
// แต่ละค่าเลือกแยกกัน เพราะเครื่องวัดส่งแค่ BP/ชีพจร/SpO₂ — ถ้าเอาทั้งแถวล่าสุด อุณหภูมิ/หายใจจะหายเป็นว่าง
function tl_latest_vitals(PDO $db, array $vns): array {
    if (!$vns) return [];
    $in = implode(',', array_fill(0, count($vns), '?'));
    $ord = 'ORDER BY screen_date DESC, screen_time DESC, opdscreen_bp_id DESC SEPARATOR \'|\'';
    $st = $db->prepare("SELECT vn,
        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN bps > 0 AND bpd > 0 THEN CONCAT(ROUND(bps), '/', ROUND(bpd)) END $ord), '|', 4) bp,
        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN pulse > 0 THEN pulse END $ord), '|', 4) pr,
        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN o2sat > 0 THEN o2sat END $ord), '|', 4) o2,
        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN temperature > 0 THEN temperature END $ord), '|', 4) bt,
        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN rr > 0 THEN rr END $ord), '|', 4) rr,
        TIMESTAMPDIFF(MINUTE, MAX(TIMESTAMP(screen_date, screen_time)), NOW()) ago
        FROM opdscreen_bp WHERE vn IN ($in) GROUP BY vn");
    $st->execute($vns);
    $out = [];
    // แนวโน้ม: ค่าล่าสุดเทียบกับค่ากลาง (median) ของ 3 ครั้งก่อนหน้า — ใช้ median เพราะเครื่องวัดอัตโนมัติ
    // ส่งค่าทุก 5-10 นาทีและแกว่ง ถ้าเทียบแค่ครั้งก่อนจะขึ้นลูกศรมั่ว
    // [เกณฑ์เปลี่ยนแปลงขั้นต่ำ, ทิศที่แย่ลง]
    $rule = ['bp' => [15, 'down'], 'pr' => [15, 'up'], 'rr' => [4, 'up'], 'o2' => [3, 'down'], 'bt' => [0.6, 'up']];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $tr = [];
        foreach ($rule as $f => [$min, $worse]) {
            $vals = $x[$f] === null ? [] : explode('|', $x[$f]);
            $x[$f] = $vals ? $vals[0] : null;               // ค่าล่าสุด
            if (count($vals) < 2) continue;
            $num = function ($v) use ($f) { return (float)($f === 'bp' ? explode('/', $v)[0] : $v); };  // BP ดูตัวบน
            $prev = array_map($num, array_slice($vals, 1, 3)); sort($prev);
            $med = $prev[intdiv(count($prev), 2)];
            $d = $num($vals[0]) - $med;
            if (abs($d) >= $min) $tr[$f] = ['dir' => $d > 0 ? 'up' : 'down', 'bad' => ($d > 0 ? 'up' : 'down') === $worse,
                                            'from' => $f === 'bt' ? round($med, 1) : (int)$med];
        }
        $x['tr'] = $tr;
        $out[$x['vn']] = $x;
    }
    return $out;
}

// ---------- API ----------
$api = $_GET['api'] ?? '';
if ($api !== '') {
    try {
        if ($api === 'data') {
            // ---- pooling ผลลัพธ์: หลายจอ poll พร้อมกัน → query ฐานข้อมูลครั้งเดียวต่อ TL_CACHE_SEC วินาที ----
            // ใช้ไฟล์ล็อกกันหลายจอ query ซ้อนกันตอน cache หมดอายุพร้อมกัน (จอแรกทำ จอที่เหลือรอแล้วใช้ผลเดียวกัน)
            $cf = sys_get_temp_dir() . '/er_careboard_' . md5(__DIR__) . '.json';
            $serve = function () use ($cf) {
                if (!is_file($cf) || time() - filemtime($cf) >= TL_CACHE_SEC) return false;
                $c = json_decode((string)@file_get_contents($cf), true);
                if (!is_array($c) || empty($c['ok'])) return false;
                $c['now'] = time(); $c['cached'] = true;
                tl_json($c);
            };
            $serve();
            $lk = @fopen($cf . '.lock', 'c');
            if ($lk) flock($lk, LOCK_EX);
            $serve();   // อาจมีจออื่นเพิ่งเติม cache ให้ระหว่างที่รอล็อก
        }
        $db = tl_db();
        if ($api === 'data') {
            $sql = "SELECT e.vn, o.oqueue, e.er_emergency_type AS lvl, e.enter_er_time,
                    e.finish_time, o.ovstost AS oc, os.name AS oname,
                    TIMESTAMPDIFF(MINUTE, e.enter_er_time, NOW()) AS el,
                    (SELECT COUNT(*) FROM lab_head l WHERE l.vn=e.vn) AS lab_n,
                    (SELECT COUNT(*) FROM lab_head l WHERE l.vn=e.vn AND l.report_date IS NULL) AS lab_p,
                    (SELECT MIN(TIMESTAMP(l.order_date, l.order_time)) FROM lab_head l WHERE l.vn=e.vn AND l.report_date IS NULL) AS pending_lab_at,
                    (SELECT TIMESTAMPDIFF(MINUTE, MIN(TIMESTAMP(l.order_date, l.order_time)), NOW()) FROM lab_head l WHERE l.vn=e.vn AND l.report_date IS NULL) AS lab_wait,
                    (SELECT COALESCE(NULLIF(w.shortname,''), NULLIF(w.name,''), i.ward) FROM ipt i LEFT JOIN ward w ON w.ward=i.ward
                       WHERE i.vn=e.vn AND i.dchdate IS NULL LIMIT 1) AS admit_ward,
                    LEFT(p.fname,4) AS fname, p.fname AS fname_full, p.lname AS lname_full, p.hn, p.birthday,
                    s.bps AS s_bps, s.bpd AS s_bpd, s.pulse AS s_pr, COALESCE(s.o2sat, s.spo2) AS s_o2, s.temperature AS s_bt, s.rr AS s_rr,
                    s.cc AS cc,
                    (SELECT GROUP_CONCAT(DISTINCT a.agent SEPARATOR ', ') FROM opd_allergy a WHERE a.hn=p.hn AND (a.allergy_cancel_status IS NULL OR a.allergy_cancel_status<>'Y')) AS allergy
                FROM er_regist e
                INNER JOIN ovst o ON o.vn=e.vn
                INNER JOIN patient p ON p.hn=o.hn
                LEFT JOIN ovstost os ON os.ovstost=o.ovstost
                LEFT JOIN opdscreen s ON s.vn=e.vn
                WHERE e.leave_datetime IS NULL AND e.vstdate >= curdate() - INTERVAL 1 DAY
                ORDER BY e.er_emergency_type, o.oqueue";
            $pts = [];
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $latest = tl_latest_vitals($db, array_column($rows, 'vn'));
            foreach ($rows as $r) {
                $L = $latest[$r['vn']] ?? [];
                $pick = function ($k, $fallback) use ($L) { return (isset($L[$k]) && $L[$k] !== null && $L[$k] !== '') ? $L[$k] : $fallback; };
                $bpL = isset($L['bp']) && $L['bp'] ? explode('/', $L['bp']) : null;
                $v = [$bpL ? $bpL[0] : $r['s_bps'], $bpL ? $bpL[1] : $r['s_bpd'], $pick('pr', $r['s_pr']),
                      $pick('o2', $r['s_o2']), $pick('bt', $r['s_bt']), $pick('rr', $r['s_rr'])];
                
                $age = $r['birthday'] ? (int)floor((time() - strtotime($r['birthday'])) / 31557600) : null;
                $hn = (string)$r['hn'];
                $num = function ($x, $f = false) { return ($x === null || $x === '' || (float)$x == 0.0) ? null : ($f ? (float)$x : (int)round((float)$x)); };
                [$sbp, $dbp, $prv, $o2v, $btv, $rrv] = [$num($v[0]), $num($v[1]), $num($v[2]), $num($v[3]), $num($v[4], true), $num($v[5])];
                $sus = tl_suspect($age, $sbp, $dbp, $prv, $o2v, $btv, $rrv);
                if ($age !== null && $age < 16) {
                    // NEWS2 ออกแบบและตรวจสอบมาในผู้ใหญ่เท่านั้น (RCP: ไม่ใช้กับอายุ < 16 ปี)
                    $news = ['score' => null, 'risk' => '', 'missing' => [], 'child' => true];
                } else {
                    // ค่าที่น่าสงสัยไม่เอามาคิด — นับเป็น "ไม่มีค่า" แทน กัน NEWS2 สูงเกินจริงจากการกรอกผิด
                    $news = tl_news2(in_array('bp', $sus) ? null : $sbp, in_array('pr', $sus) ? null : $prv,
                                     in_array('o2', $sus) ? null : $o2v, in_array('bt', $sus) ? null : $btv, in_array('rr', $sus) ? null : $rrv);
                }
                $pts[$r['vn']] = [
                    'vn' => $r['vn'], 'q' => (string)$r['oqueue'], 'lvl' => (int)$r['lvl'],
                    'name' => $GLOBALS['CFG']['mask_name'] ? (string)$r["fname"] . '***' : trim($r['fname_full'] . ' ' . $r['lname_full']), 'age' => $age,
                    'hn' => $GLOBALS['CFG']['mask_name'] ? 'HN:**x' . substr($hn, -4) : 'HN:' . $hn,
                    'bp' => ($sbp && $dbp) ? $sbp . '/' . $dbp : null,
                    'pr' => $prv, 'o2' => $o2v, 'temp' => $btv, 'rr' => $rrv,
                    'vs_ago' => isset($L['ago']) ? (int)$L['ago'] : null,
                    'tr' => (object)($L['tr'] ?? []), 'oc' => (string)$r['oc'],   // วัดล่าสุดกี่นาทีที่แล้ว (null = มีแค่ค่าคัดกรอง)
                    'sus'  => $sus,
                    'news' => $news,
                    'allergy' => (bool)$r['allergy'],
                    // ข้อมูลสุขภาพละเอียด (อาการสำคัญ/ชื่อยาที่แพ้) ส่งเฉพาะเมื่อพยาบาลล็อกอินแล้ว — จอสาธารณะเห็นแค่ป้ายแพ้ยา
                    'cc' => (string)$r['cc'],
                    'allergy_txt' => (string)$r['allergy'],
                    'status' => (string)$r['oname'], 'el' => (int)$r['el'],
                    'limit' => $GLOBALS['CFG']['limit_min'][(int)$r['lvl']] ?? 0,
                    'lab_n' => (int)$r['lab_n'], 'lab_p' => (int)$r['lab_p'], 'lab_wait' => $r['lab_wait'] === null ? null : (int)$r['lab_wait'],
                    'ward' => $r['admit_ward'],
                    'stage' => cb_stage(['finish_time'=>$r['finish_time'],'ovstost_code'=>$r['oc'],'pending_lab_at'=>$r['pending_lab_at']]),
                ];
            }
            if ($pts) {
                $vns = array_keys($pts); $in = implode(',', array_fill(0, count($vns), '?'));
                foreach ($pts as &$pp) { $pp['crit'] = []; $pp['abn'] = 0; $pp['xray'] = []; } unset($pp);
                // ผลแล็บที่ออกแล้วและผิดปกติ: critical_result='Y' = ค่าวิกฤต (พบน้อยมาก ~10 ใน 7,000 ผล)
                // abnormal_result='Y' = ผิดปกติทั่วไป
                $st = $db->prepare("SELECT h.vn, o.lab_items_name_ref nm, o.lab_order_result r, o.critical_result c
                    FROM lab_head h JOIN lab_order o ON o.lab_order_number = h.lab_order_number
                    WHERE h.vn IN ($in) AND o.lab_order_result <> '' AND (o.critical_result = 'Y' OR o.abnormal_result = 'Y')");
                $st->execute($vns);
                foreach ($st as $x) {
                    if ($x['c'] === 'Y') { if (count($pts[$x['vn']]['crit']) < 3) $pts[$x['vn']]['crit'][] = trim($x['nm']) . ' ' . trim($x['r']); }
                    else $pts[$x['vn']]['abn']++;
                }
                // เอกซเรย์ที่ยังไม่เสร็จ: ยังไม่ confirm ครบ หรือมีรายการค้างใน wait_xray_list
                // (xray_head.report_date ว่างทั้งหมด จึงใช้ 2 ฟิลด์นี้แทน)
                $st = $db->prepare("SELECT vn, COALESCE(NULLIF(wait_xray_list,''), xray_list) nm,
                        TIMESTAMPDIFF(MINUTE, order_date_time, NOW()) w
                    FROM xray_head WHERE vn IN ($in) AND (confirm_all <> 'Y' OR (wait_xray_list IS NOT NULL AND wait_xray_list <> ''))");
                $st->execute($vns);
                foreach ($st as $x) $pts[$x['vn']]['xray'][] = ['nm' => mb_substr((string)$x['nm'], 0, 40), 'w' => max(0, (int)$x['w'])];
            }
            $out = ['ok'=>true,'now'=>time(),'ver'=>(string)filemtime(__FILE__),'patients'=>array_values($pts)];
            // เขียนแบบ tmp + rename ให้จออื่นไม่อ่านเจอไฟล์ครึ่งๆ
            $tmp = $cf . '.' . getmypid();
            if (@file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) { @chmod($tmp, 0600); @rename($tmp, $cf); }   // แคชมีข้อมูลผู้ป่วย: อ่านได้เฉพาะเจ้าของ
            if (!empty($lk)) { flock($lk, LOCK_UN); fclose($lk); }
            tl_json($out);
        }

        tl_json(['ok'=>false,'err'=>'unknown'], 404);
    } catch (Throwable $e) {
        error_log('[er_timeline] ' . $e->getMessage());
        tl_json(['ok'=>false,'err'=>'server'], 500);
    }
}
header('Cache-Control: no-cache, must-revalidate');
header('X-Frame-Options: SAMEORIGIN'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ER Care Board · <?= htmlspecialchars($CFG['hospital']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f1f5f9;--card:#fff;--line:#e2e8f0;--text:#1e293b;--mut:#64748b;--hd:#f8fafc}
html.dark{--bg:#0f172a;--card:#1e293b;--line:#334155;--text:#e2e8f0;--mut:#94a3b8;--hd:#162032}
body{font-family:'Prompt',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px}
header{background:var(--card);border-bottom:1px solid var(--line);padding:10px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
h1{font-size:18px}h1 small{display:block;font-size:12px;font-weight:400;color:var(--mut)}
.live{background:#fee2e2;color:#dc2626;border-radius:99px;padding:1px 8px;font-size:11px;margin-left:6px}
.stats{display:flex;gap:8px;flex-wrap:wrap}.stat{border:1px solid var(--line);border-radius:10px;padding:5px 12px;font-weight:600;background:var(--card)}
.stat.clock{font-size:20px;color:#b45309;background:#fffbeb;border-color:#fcd34d}.stat.r{color:#dc2626}.stat.b{color:#2563eb}.stat.g{color:#16a34a}
.bar{padding:8px 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
button,select,input{font-family:inherit;font-size:13px}
.btn{border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:8px;padding:6px 12px;cursor:pointer}
.btn.pri{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.btn.or{background:#ea580c;color:#fff;border-color:#ea580c}
.chip{border:1px solid var(--line);border-radius:99px;padding:3px 12px;cursor:pointer;background:var(--card);color:var(--text)}
.chip.on{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
.sp{flex:1}.pg{font-size:12px;color:var(--mut);border:1px solid var(--line);border-radius:99px;padding:2px 10px;background:var(--card)}
.wrap{padding:0 16px 8px;overflow:auto;scroll-behavior:smooth}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px;padding:4px 0 12px}
.lv1{--c:#dc2626}.lv2{--c:#ec4899}.lv3{--c:#eab308}.lv4{--c:#22c55e}.lv5{--c:#94a3b8}
.card{background:var(--card);border:1px solid var(--line);border-left:7px solid var(--c);border-radius:12px;padding:10px 12px;display:grid;gap:7px;align-content:start}
.card.alert{box-shadow:0 0 0 2px #ef4444aa;background:#fef2f2}html.dark .card.alert{background:#3a1515}
.top{display:flex;align-items:center;gap:8px}
.q{background:var(--c);color:#fff;border-radius:8px;padding:1px 10px;font-weight:700;font-size:16px}
.lvb{border:1.5px solid var(--c);color:var(--c);border-radius:99px;font-size:12px;font-weight:600;padding:0 8px}
.nm{font-weight:600;font-size:15px;margin-left:auto;text-align:right}.nm small{display:block;font-weight:400;color:var(--mut);font-size:11px}
.vit{display:flex;gap:5px;flex-wrap:wrap}.vit span{background:var(--hd);border:1px solid var(--line);border-radius:6px;padding:0 7px;font-size:12px}
.vit span.bad{color:#dc2626;border-color:#fca5a5;background:#fef2f2;font-weight:600}
.st{font-weight:600;font-size:16px;color:#1d4ed8}html.dark .st{color:#93c5fd}
.row{display:flex;justify-content:space-between;gap:8px;font-size:12.5px;color:var(--mut)}.row b{color:var(--text);font-weight:600}
.over{color:#dc2626 !important;font-weight:600}
.stg{display:flex;gap:3px}.stg i{flex:1;height:6px;border-radius:3px;background:var(--line)}.stg i.on{background:#3b82f6}.stg i.cur{background:#f59e0b}
.stl{display:flex;justify-content:space-between;font-size:10.5px;color:var(--mut)}.stl .cur{color:#d97706;font-weight:600}
.tasks{display:flex;flex-wrap:wrap;gap:5px}
.task{display:inline-flex;align-items:center;gap:4px;border:1.5px solid #93c5fd;border-radius:99px;padding:1px 9px 1px 6px;font-size:12px;cursor:pointer;background:var(--card);color:var(--text)}
.task.due{border-color:#ef4444;background:#fef2f2;color:#b91c1c;font-weight:600}html.dark .task.due{background:#450a0a;color:#fca5a5}
.task.done{opacity:.5;text-decoration:line-through;border-color:var(--line)}.task.auto{border-style:dashed}
.news{font-weight:700;border-radius:8px;padding:0 9px;color:#fff;font-size:13px}.news.low{background:#16a34a}.news.lowmed{background:#ca8a04}.news.med{background:#ea580c}.news.high{background:#dc2626}.news.na{background:#94a3b8}
.cc{font-size:13.5px;font-weight:500}.alg{background:#fee2e2;color:#b91c1c;border-radius:6px;padding:1px 8px;font-weight:600;font-size:12px}
.none{color:var(--mut);font-size:12px}
.wd{background:#dcfce7;color:#166534;border-radius:6px;padding:1px 8px;font-weight:600;font-size:12.5px}
.pop{position:fixed;inset:0;background:#0008;display:none;align-items:center;justify-content:center;z-index:9;padding:16px}.pop.on{display:flex}
.pop .box{background:var(--card);color:var(--text);border-radius:14px;padding:18px;width:min(420px,100%);display:grid;gap:10px}
.pop label{display:grid;gap:3px;font-size:12px;color:var(--mut)}.pop input,.pop select{padding:7px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text)}
.pop .brow{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.err{color:#dc2626;font-size:12px;min-height:1em}
.empty{padding:50px;text-align:center;color:var(--mut);grid-column:1/-1}

/* ===== Glanceable skin: ตัวใหญ่ ตัดสีชัด อ่านไกลได้ ===== */
:root{--bg:#e9eef5;--card:#fff;--line:#cfd8e3;--text:#0b1220;--mut:#4b5b70;--hd:#f1f5f9}
html.dark{--bg:#0b1220;--card:#162033;--line:#2b3b57;--text:#f4f7fc;--mut:#a7b5cc;--hd:#1d2a42}
html{font-size:15px}body{background:var(--bg);font-size:1rem}
header{background:#0f2a52;color:#fff;border:0}h1{font-size:1.35rem}h1 small{color:#b8c8e4;font-size:.75rem}
.live{background:#ef4444;color:#fff;border:0}
.stat{background:#ffffff1f;border:0;color:#fff;font-size:1.05rem;border-radius:10px}
.stat.clock{background:#fff;color:#0f2a52;font-size:1.25rem}.stat.r{background:#ef4444;color:#fff}.stat.b{background:#2563eb;color:#fff}.stat.g{background:#16a34a;color:#fff}
.btn,.chip{font-size:.85rem;background:var(--card);border:1.5px solid var(--line);color:var(--text)}
.btn.pri,.chip.on{background:#0f2a52;border-color:#0f2a52;color:#fff}.btn.or{background:#ea580c;border-color:#ea580c}
.grid{grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.lv3{--t:#111}.lv1,.lv2,.lv4,.lv5{--t:#fff}
.card{padding:0;gap:0;border:2px solid var(--c);border-left:2px solid var(--c);border-radius:14px;overflow:hidden;box-shadow:none;background:var(--card)}
.card.alert{background:var(--card);border-color:#dc2626;border-width:3px;animation:none}
.top{background:var(--c);color:var(--t);padding:8px 14px;gap:10px}
.q{background:#fff;color:#111;font-size:1.25rem;border-radius:8px;padding:0 12px;font-weight:800}
.lvb{border:0;background:#0003;color:var(--t);font-size:.8rem;font-weight:600;padding:2px 10px}
.nm{color:var(--t);font-size:1.1rem}.nm small{color:var(--t);opacity:.85;font-size:.75rem}
.card>*:not(.top){margin:0 14px}.card>.top+*{margin-top:10px}.card>*:last-child{margin-bottom:12px}.card{padding-bottom:0}
.card{display:flex;flex-direction:column;gap:8px}
.vit{display:flex;flex-wrap:wrap;gap:5px}
.vit span{flex:1 1 auto;min-width:0;white-space:nowrap;text-align:center;font-size:.95rem;font-weight:700;padding:3px 7px;border-radius:8px;background:var(--hd);border:1.5px solid var(--line)}
.vit span.bad{background:#dc2626;color:#fff;border-color:#dc2626}
.st{font-size:1.25rem;font-weight:700;color:var(--text)}
.row{font-size:.95rem}.row b{font-size:1.05rem}
.news{font-size:1.1rem;padding:2px 14px;border-radius:8px}
.cc{font-size:1.05rem;font-weight:600;background:var(--hd);border-left:4px solid var(--c);padding:4px 10px;border-radius:0 6px 6px 0}
.alg{font-size:.9rem;background:#dc2626;color:#fff;border:0}.wd{background:#16a34a;color:#fff;border:0;font-size:.9rem}
.stg i{height:8px;background:var(--line)}.stg i.on{background:#2563eb}.stg i.cur{background:#f59e0b;box-shadow:none}.stl{font-size:.7rem}
.task{font-size:.9rem;border-width:2px;animation:none}.task.due{background:#dc2626;color:#fff;border-color:#dc2626;animation:none}
.over{color:#dc2626 !important}html.dark .over{color:#ff8a8a !important}
.pop .box{background:var(--card);border:1px solid var(--line)}
.ic{width:1.15em;height:1.15em;vertical-align:-.2em;margin-right:.35em;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
.vit span{display:flex;align-items:center;justify-content:center}.vit .ic{margin-right:.2em;width:1em;height:1em}
.row span{display:inline-flex;align-items:center}.cc{display:flex;align-items:center}.alg,.wd{display:inline-flex;align-items:center}
.dot{display:inline-block;width:.8em;height:.8em;border-radius:50%;margin-right:.4em;background:var(--c);vertical-align:-.05em;border:1px solid #0003}
.cc{background:color-mix(in srgb,var(--c) 14%,var(--card));color:var(--text);border:1.5px solid color-mix(in srgb,var(--c) 55%,transparent);border-left:6px solid var(--c);border-radius:8px;padding:6px 10px;font-size:1.1rem;font-weight:700;line-height:1.3}
.cc .ic{color:var(--text);width:1.2em;height:1.2em}
.crit{background:#dc2626;color:#fff;border-radius:8px;padding:5px 10px;font-weight:700;display:flex;align-items:center;font-size:.95rem}.crit .ic{color:#fff}
.row b{text-align:right;min-width:0}
body.compact{font-size:.82rem}body.compact .grid{grid-template-columns:repeat(auto-fill,minmax(245px,1fr));gap:8px}
body.compact .card{gap:5px}body.compact .top{padding:5px 9px}body.compact .q{font-size:1rem}body.compact .lvb{display:none}body.compact .nm{font-size:.95rem}
body.compact .cc{font-size:.9rem;padding:3px 7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
body.compact .vit span{font-size:.8rem;padding:2px 4px}body.compact .st{font-size:1rem}body.compact .stl{display:none}body.compact .stg i{height:5px}
body.compact .row{font-size:.8rem}body.compact .row b{font-size:.85rem}body.compact .news{font-size:.85rem;padding:1px 8px}body.compact .card>*:not(.top){margin-left:9px;margin-right:9px}
.lt{--a:#8b5cf6;--b:#6d28d9;display:flex;align-items:center;gap:10px;border-radius:12px;padding:6px 10px 6px 6px;
  background:color-mix(in srgb,var(--a) 13%,var(--card));border:1.5px solid color-mix(in srgb,var(--a) 50%,transparent)}
.lt.labok{--a:#22c55e;--b:#15803d}.lt.abn{--a:#f59e0b;--b:#c2410c}.lt.xray{--a:#06b6d4;--b:#0e7490}
.lt.crit{--a:#ef4444;--b:#b91c1c;background:#dc2626;border-color:#991b1b;color:#fff;animation:blink 1.2s infinite}
@keyframes blink{50%{background:#f87171}}
.ib{width:2.5em;height:2.5em;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;color:#fff;
  background:linear-gradient(135deg,var(--a),var(--b));box-shadow:0 3px 8px -2px var(--b),inset 0 1px 0 #fff5}
.lt.crit .ib{background:#fff;color:#b91c1c;box-shadow:0 0 0 3px #fff4}
.ib .ic{margin:0;width:1.35em;height:1.35em;stroke-width:2.2}
.lx{display:grid;min-width:0;line-height:1.2}.lx small{font-size:.72rem;opacity:.8;font-weight:600;letter-spacing:.2px}.lx b{font-size:1rem;font-weight:700;word-break:break-word}
body.compact .lt{padding:3px 7px 3px 4px;gap:7px}body.compact .ib{width:2em;height:2em}body.compact .lx b{font-size:.85rem}body.compact .lx small{font-size:.65rem}
.vit span{flex:1 1 28%}
body.compact .stl{display:flex;font-size:.6rem}
.news{line-height:1.5}
.card.stale{border-color:#94a3b8;border-style:dashed;opacity:.62}.card.stale .top{background:#94a3b8;color:#fff}
.stl-flag{background:#f1f5f9;color:#475569;border-radius:8px;padding:4px 8px;font-size:.78rem;font-weight:600;display:flex;align-items:center;margin-top:8px}
html.dark .stl-flag{background:#1d2a42;color:#a7b5cc}
.stat.conn{background:#ffffff1f;font-weight:500;font-size:.85rem;display:flex;align-items:center}
.stat.conn.lost{background:#f59e0b;color:#111;font-weight:700;animation:blink2 1s infinite}@keyframes blink2{50%{opacity:.55}}
.card>*{order:2}.card>.top{order:0}.card>.stl-flag{order:1;margin-top:8px}
/* หัวการ์ด: คิวใหญ่ซ้าย (2 แถว) | ชื่อ · ระดับ+อายุ/HN ขวา — ห้ามตัดบรรทัด */
.top{display:grid;grid-template-columns:auto minmax(0,1fr);column-gap:10px;row-gap:3px;align-items:center}
.top .q{grid-row:1 / span 2;white-space:nowrap;align-self:center}
.top .nm{grid-column:2;grid-row:1;margin:0;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:right;line-height:1.25}
.top .nm small{display:inline;margin-left:6px}
.top .lvb{grid-column:2;grid-row:2;justify-self:end;white-space:nowrap;max-width:100%;overflow:hidden;text-overflow:ellipsis}
body.compact .top .lvb{display:inline-block;font-size:.65rem;padding:1px 7px}
.cc{display:-webkit-box !important;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cc .ic{vertical-align:-.25em}

/* ===== รองรับกล่อง Android TV (TX6 ฯลฯ): WebView รุ่นเก่า ไม่มี color-mix / flex gap, GPU อ่อน, ใช้รีโมต ===== */
.lv1{--soft:#fde8e8;--ln:#f3a5a5}.lv2{--soft:#fde7f2;--ln:#f4a3cb}.lv3{--soft:#fdf6dc;--ln:#ecd27a}.lv4{--soft:#e3f8ea;--ln:#8fdcaa}.lv5{--soft:#eef1f5;--ln:#c4ccd8}
html.dark .lv1{--soft:#3a1d24}html.dark .lv2{--soft:#3a1d31}html.dark .lv3{--soft:#3a3320}html.dark .lv4{--soft:#1b3a2a}html.dark .lv5{--soft:#26324a}
.cc{background:var(--soft);border:1.5px solid var(--ln);border-left:6px solid var(--c)}
.lt{background:var(--ltb);border:1.5px solid var(--a)}
.lt{--ltb:#f1ebfe}.lt.labok{--ltb:#e5f8ec}.lt.abn{--ltb:#fef3e0}.lt.xray{--ltb:#e0f7fb}
html.dark .lt{--ltb:#2a2342}html.dark .lt.labok{--ltb:#183526}html.dark .lt.abn{--ltb:#3a2c17}html.dark .lt.xray{--ltb:#15333a}
.lt.crit{background:#dc2626}
.lvb{background:rgba(0,0,0,.2)}
.pop{top:0;right:0;bottom:0;left:0}
/* flex gap → margin (Chrome < 84 ไม่รองรับ gap ใน flex) */
header,.stats,.bar,.row,.stg,.lt{gap:0 !important}
.stats>*{margin-left:8px}.bar>*{margin-right:8px;margin-bottom:4px}
.row>b{margin-left:8px}.stg i+i{margin-left:3px}.lt .ib{margin-right:10px}
.card{display:grid !important;grid-template-columns:minmax(0,1fr);gap:8px}body.compact .card{gap:5px}
.vit{display:grid !important;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px}
.vit span{grid-column:span 2}.vit span:nth-child(4):nth-last-child(2),.vit span:nth-child(5):last-child{grid-column:span 3}
.vit span:nth-child(4):last-child{grid-column:span 6}
/* รีโมต: เห็นชัดว่ากำลังเลือกปุ่มไหน */
.btn:focus,.chip:focus{outline:3px solid #f59e0b;outline-offset:2px}
button.chip{font-family:inherit}
/* GPU อ่อน: เหลือแอนิเมชันเฉพาะค่าวิกฤต/ขาดการเชื่อมต่อ ปิดเงาที่กินแรง */
.wrap{scroll-behavior:auto}
.ib{box-shadow:none}
@media (prefers-reduced-motion:reduce){.lt.crit,.stat.conn.lost,.live{animation:none}}
.stage .stx{font-size:.85rem;color:var(--mut);margin-top:4px}.stage .stx b{color:var(--text);font-weight:700}
body.compact .stage .stx{font-size:.78rem}body.compact .stl{display:none}
.stale-bar{position:fixed;left:0;right:0;bottom:0;background:#334155;color:#e2e8f0;padding:7px 16px;font-size:.85rem;line-height:1.9;z-index:5;max-height:4.6em;overflow:hidden}
.stale-bar>span{display:inline-block;margin-right:8px;vertical-align:middle}
.sb-h{font-weight:700;color:#fff}.sb-h .ic{vertical-align:-.2em}.sb-s{color:#94a3b8}
.sb-i{background:#ffffff1a;border-radius:99px;padding:0 10px;line-height:1.7;white-space:nowrap}.sb-i small{color:#94a3b8}
.sb-i .dot{border-color:#fff6}.sb-i.hi{background:#dc2626;color:#fff;font-weight:700}.sb-i.hi small{color:#fecaca}
.cc{display:flex !important;align-items:flex-start;-webkit-line-clamp:unset;overflow:visible}
.cc .ic{margin-top:.1em}
.cct{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-width:0}
body.compact .cct{-webkit-line-clamp:1}
.vit span.sus{background:#fffbeb;color:#92400e;border:1.5px dashed #f59e0b}
html.dark .vit span.sus{background:#3a2c17;color:#fcd34d}
.susrow{background:#fef3c7;color:#92400e;border:1.5px dashed #f59e0b;border-radius:8px;padding:3px 9px;font-size:.85rem;font-weight:700;display:flex;align-items:center;flex-wrap:wrap}
.susrow small{font-weight:500;margin-left:4px;opacity:.8}html.dark .susrow{background:#3a2c17;color:#fcd34d}
.vsago{font-size:.75rem;color:var(--mut);display:flex;align-items:center;margin-top:-3px}.vsago .ic{width:1em;height:1em}
.vsago.old{color:#b45309;font-weight:700}
.minis{grid-column:1 / -1;display:grid;grid-template-columns:repeat(auto-fill,minmax(780px,1fr));gap:6px}
.minis-h{grid-column:1 / -1;font-size:.8rem;font-weight:700;color:var(--mut);margin-top:2px}
.mini{display:flex;align-items:center;background:var(--card);border:1.5px solid var(--line);border-left:7px solid var(--c);border-radius:10px;padding:5px 10px;font-size:.9rem;white-space:nowrap;overflow:hidden}
.mini>span{margin-right:10px;overflow:hidden;text-overflow:ellipsis}
.mq{background:var(--c);color:var(--t);font-weight:800;border-radius:6px;padding:0 8px;flex:none}
.mn{font-weight:700;flex:none}.mn small{font-weight:400;color:var(--mut)}
.mcc{flex:1 1 auto;min-width:90px;color:var(--mut)}.mv{flex:none;font-weight:600;font-variant-numeric:tabular-nums}
.malg{flex:none;color:#dc2626}.mst{flex:none;font-weight:700;max-width:11em}.mel{flex:none;margin-right:0 !important;color:var(--mut)}
.mel.over{color:#dc2626;font-weight:700}
.toast{position:fixed;left:50%;top:22%;transform:translateX(-50%);z-index:20;min-width:620px;max-width:90vw;display:flex;align-items:center;
  background:#fff;color:#0b1220;border-radius:18px;padding:22px 28px 26px;box-shadow:0 20px 60px rgba(0,0,0,.45);border:4px solid #7c3aed;overflow:hidden}
.toast.xray{border-color:#0891b2}.toast.crit{border-color:#dc2626;background:#fff1f1}
.toast .ti{width:84px;height:84px;border-radius:50%;flex:none;margin-right:22px;display:flex;align-items:center;justify-content:center;color:#fff;background:#7c3aed}
.toast.xray .ti{background:#0891b2}.toast.crit .ti{background:#dc2626}
.toast .ti .ic{width:48px;height:48px;margin:0;stroke-width:2.2}
.toast .tt{font-size:1.6rem;font-weight:800;color:#6d28d9}.toast.xray .tt{color:#0e7490}.toast.crit .tt{color:#b91c1c}
.toast .tq{font-size:1.5rem;font-weight:700;margin-top:4px}.toast .tq small{font-weight:400;color:#64748b;font-size:1rem}
.toast .tq .q{background:var(--c);color:var(--t);border-radius:8px;padding:0 12px;margin-right:6px}
.toast .tm{font-size:1.2rem;margin-top:4px;color:#334155;font-weight:600}
.toast .tbar{position:absolute;left:0;right:0;bottom:0;height:7px;background:#e2e8f0}
.toast .tbar i{display:block;height:100%;background:#7c3aed;animation-name:tshrink;animation-timing-function:linear;animation-fill-mode:forwards;transform-origin:left}
.toast.xray .tbar i{background:#0891b2}.toast.crit .tbar i{background:#dc2626}
@keyframes tshrink{from{transform:scaleX(1)}to{transform:scaleX(0)}}
.tr{font-weight:900;margin-left:3px;color:var(--mut)}.tr.bad{color:#dc2626}.vit span.bad .tr{color:#fff}
.vsago.due{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;color:#c2410c;font-weight:800;background:#ffedd5;border-radius:6px;padding:2px 7px;margin-top:0}.vsago.due small{font-weight:600;margin-left:3px}
html.dark .vsago.due{background:#3a2412;color:#fdba74}
.card.done{opacity:.6;border-style:dashed;border-color:#94a3b8 !important;box-shadow:none !important;animation:none !important}
.doneflag{order:1;background:#dcfce7;color:#166534;border-radius:8px;padding:4px 8px;font-size:.8rem;font-weight:700;display:flex;align-items:center;margin-top:8px}
.mini.done{opacity:.55;border-style:dashed}
.toast.new{border-color:var(--c);border-width:6px;background:#fff}
.toast.new .ti{background:var(--c);color:var(--t)}.toast.new .tt{color:var(--c);font-size:1.9rem}.toast.new .tbar i{background:var(--c)}
.toast.new.lv1{animation:tpulse 1s infinite}@keyframes tpulse{50%{box-shadow:0 0 0 10px rgba(220,38,38,.35),0 20px 60px rgba(0,0,0,.45)}}
.toast.new.lv3 .tt{color:#a16207}
.lt.crit .ib .ic{color:#b91c1c}
#credit{position:fixed;right:10px;bottom:3px;font-size:11px;color:#94a3b8;z-index:5;pointer-events:none}
</style>
</head>
<body>
<header>
  <div><h1><?= htmlspecialchars($CFG['hospital']) ?> · กระดานติดตามผู้ป่วยห้องฉุกเฉิน (ER Care Board) <span class="live">● LIVE</span>
    <small>ข้อมูลสำคัญในการรักษาของผู้ป่วยแต่ละราย <?= $CFG['mask_name'] ? '(ปิดบังชื่อ/HN บางส่วน)' : '' ?></small></h1></div>
  <div class="stats">
    <div class="stat clock" id="clock">--:--:--</div>
    <div class="stat conn" id="conn">กำลังโหลด…</div>
    <div class="stat" id="sBeds">ผู้ป่วยใน ER -</div>
    <div class="stat r" id="sOver">รอเกินเวลา 0</div>
    <div class="stat b" id="sLab">รอผลแล็บ 0</div>
    <div class="stat g" id="sNews">NEWS2 สูง 0</div>
  </div>
</header>
<div class="bar">
  <span>ระดับ:</span>
  <button class="chip on" data-l="">ทั้งหมด</button>
  <button class="chip lv1" data-l="1"><i class="dot"></i>วิกฤต</button><button class="chip lv2" data-l="2"><i class="dot"></i>ฉุกเฉินหนัก</button><button class="chip lv3" data-l="3"><i class="dot"></i>เร่งด่วน</button><button class="chip lv4" data-l="4"><i class="dot"></i>ไม่รุนแรง</button><button class="chip lv5" data-l="5"><i class="dot"></i>ทั่วไป</button>
  <span class="sp"></span><span class="pg" id="pg" style="display:none"></span>
  <button class="btn" id="bSize" title="ขนาดการ์ด">ขนาด: อัตโนมัติ</button>
  <button class="btn" id="bDark">◐</button>
  <button class="btn" id="bFull" title="เต็มจอ"><svg class="ic" style="margin:0" viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg></button>
</div>
<div class="wrap"><div class="grid" id="grid"></div></div>
<div class="stale-bar" id="staleBar" style="display:none"></div>
<div class="toast" id="toast" style="display:none"></div>

<script>
const LV = {1:['แดง','วิกฤต · ทันที'],2:['ชมพู','ฉุกเฉินหนัก · ≤10-15 น.'],3:['เหลือง','เร่งด่วน · ≤30 น.'],4:['เขียว','ไม่รุนแรง · ≤60 น.'],5:['ขาว','ทั่วไป · 60-120 น.']};
const STAGES = ['ลงทะเบียน','ตรวจรักษา','รับยา','เสร็จสิ้น'];
let lastOk = 0, D = null, filterL = '', tOff = 0, lastTouch = 0;
const $ = s => document.querySelector(s);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const nowS = () => Math.floor(Date.now() / 1000) + tOff;
const hm = ts => { const d = new Date(ts * 1000); return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0'); };
try { if (localStorage.er_tl_dark === '1') document.documentElement.classList.add('dark'); } catch (e) {}

// ===== แจ้งเตือนผลแล็บ / X-Ray ออก: เทียบกับรอบ poll ก่อนหน้า แล้วโชว์ popup ทีละรายการ ~8 วิ =====
// รอบแรกหลังเปิดจอไม่แจ้ง (ไม่มีค่าก่อนหน้าให้เทียบ) กันเด้งรัวตอนเปิดเครื่อง
const TOAST_MS = 8000;
let toastQ = [], toastOn = false;
function detectResults(oldList, newList) {
  const old = {}; oldList.forEach(p => old[p.vn] = p);
  newList.forEach(p => {
    const o = old[p.vn], lvl = p.lvl || 5;
    // ผู้ป่วยใหม่ หรือถูกปรับระดับขึ้นเป็นวิกฤต/ฉุกเฉินหนัก
    if (lvl <= 2 && !isDone(p) && !isStale(p) && (!o || (o.lvl || 5) > 2)) {
      const v = [p.bp ? 'BP ' + p.bp : '', p.pr ? 'PR ' + p.pr : '', p.rr ? 'RR ' + p.rr : '', p.o2 ? 'O₂ ' + p.o2 + '%' : ''].filter(Boolean).join(' · ');
      toastQ.push({p, kind: 'new', icon: 'warn', ms: 10000, prio: lvl === 1 ? 3 : 1,
        title: (o ? 'ปรับระดับเป็น' : 'ผู้ป่วยใหม่ · ') + (lvl === 1 ? 'วิกฤต' : 'ฉุกเฉินหนัก'),
        msg: [p.cc, v].filter(Boolean).join(' — ') || 'รอข้อมูลคัดกรอง'});
    }
    if (!o) return;
    const doneNow = (p.lab_n || 0) - (p.lab_p || 0), doneBefore = (o.lab_n || 0) - (o.lab_p || 0);
    if (doneNow > doneBefore) {
      const newCrit = (p.crit || []).filter(c => (o.crit || []).indexOf(c) < 0);
      toastQ.push({p, prio: newCrit.length ? 2 : 0, kind: newCrit.length ? 'crit' : 'lab', icon: newCrit.length ? 'warn' : 'lab',
        title: newCrit.length ? 'ผลแล็บออก · มีค่าวิกฤต' : 'ผลแล็บออกแล้ว',
        msg: newCrit.length ? newCrit.join(' · ') : (p.lab_p ? `ออกแล้ว ${doneNow}/${p.lab_n} ใบ · เหลือ ${p.lab_p}` : `ออกครบ ${p.lab_n}/${p.lab_n} ใบ`)});
    }
    const nowX = (p.xray || []).map(x => x.nm), doneX = (o.xray || []).map(x => x.nm).filter(n => nowX.indexOf(n) < 0);
    if (doneX.length) toastQ.push({p, prio: 0, kind: 'xray', icon: 'xray', title: 'X-Ray เสร็จแล้ว', msg: doneX.join(', ')});
  });
  // ลำดับ: ผู้ป่วยวิกฤตใหม่ > ค่าแล็บวิกฤต > ผู้ป่วยฉุกเฉินหนักใหม่ > ผลแล็บ/X-Ray
  toastQ.sort((a, b) => (b.prio || 0) - (a.prio || 0));
  nextToast();
}
function nextToast() {
  if (toastOn || !toastQ.length) return;
  const t = toastQ.shift(), el = $('#toast'), p = t.p, lvl = p.lvl || 5;
  toastOn = true;
  el.className = 'toast ' + t.kind + ' lv' + lvl;
  el.innerHTML = `<div class="ti">${ic(t.icon)}</div><div class="tb"><div class="tt">${t.title}</div>
    <div class="tq"><span class="q">คิว ${esc(p.q)}</span> ${esc(p.name)} <small>${p.age != null ? p.age + ' ปี' : ''}</small></div>
    <div class="tm">${esc(t.msg)}</div></div><div class="tbar"><i style="animation-duration:${t.ms || TOAST_MS}ms"></i></div>`;
  el.style.display = '';
  setTimeout(() => { el.style.display = 'none'; toastOn = false; setTimeout(nextToast, 400); }, t.ms || TOAST_MS);
}
async function load() {
  try { const r = await fetch('?api=data', {credentials: 'same-origin'}); if (r.status === 401 || r.status === 403) { location.reload(); return; }
    const j = await r.json(); if (!j.ok) return;
    // แก้ไฟล์หน้านี้บนเซิร์ฟเวอร์เมื่อไร จอทุกเครื่อง (รวมแอป Android) รีโหลดเองในรอบ poll ถัดไป
    if (D && D.ver && j.ver && j.ver !== D.ver) { location.reload(); return; }
    if (D) detectResults(D.patients, j.patients);
    D = j; lastOk = Date.now(); tOff = j.now - Math.floor(Date.now() / 1000); render(); } catch (e) {}
}

function news(n) {
  if (n && n.child) return '<span class="news na" title="NEWS2 ใช้กับผู้ป่วยอายุ 16 ปีขึ้นไปเท่านั้น">ไม่ใช้กับเด็ก</span>';
  if (!n || n.score == null) return '<span class="news na">-</span>';
  const t = {low:'ต่ำ', lowmed:'ต่ำ-กลาง', med:'ปานกลาง', high:'สูง'}[n.risk];
  return `<span class="news ${n.risk}" title="${n.missing.length ? 'ไม่ได้นำมาคิด (ไม่มีค่า/ค่าน่าสงสัย): ' + n.missing.join(', ') : ''}">${n.score} · ${t}${n.missing.length ? ' *' : ''}</span>`;
}
const IC = {
  bp:'<path d="M12 14l4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
  pr:'<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
  rr:'<path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2"/><path d="M9.6 4.6A2 2 0 1 1 11 8H2"/><path d="M12.6 19.4A2 2 0 1 0 14 16H2"/>',
  o2:'<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
  bt:'<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
  clock:'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  lab:'<path d="M10 2v7.31"/><path d="M14 9.3V1.99"/><path d="M8.5 2h7"/><path d="M14 9.3a6.5 6.5 0 1 1-4 0"/><path d="M5.52 16h12.96"/>',
  warn:'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  note:'<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 12h6"/><path d="M9 16h4"/>',
  ward:'<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
  xray:'<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
  news:'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
};
const ic = n => `<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">${IC[n]}</svg>`;
const TR_NAME = {bp: 'ความดันตัวบน', pr: 'ชีพจร', rr: 'อัตราหายใจ', o2: 'SpO₂', bt: 'อุณหภูมิ'};
function arrow(p, k) {
  const t = p.tr && p.tr[k]; if (!t) return '';
  return `<b class="tr ${t.bad ? 'bad' : ''}" title="${TR_NAME[k]} ${t.dir === 'up' ? 'เพิ่มขึ้น' : 'ลดลง'} จากประมาณ ${t.from}">${t.dir === 'up' ? '↑' : '↓'}</b>`;
}
function vital(icon, val, bad, tip, sus) {
  if (sus) return `<span class="sus" title="${tip} — ค่าน่าสงสัย กรุณาตรวจสอบ">${ic(icon)}${val}?</span>`;
  return `<span class="${bad ? 'bad' : ''}" title="${tip}">${ic(icon)}${val}</span>`;
}
const SUS_NAME = {bp: 'ความดัน', pr: 'ชีพจร', o2: 'SpO₂', rr: 'หายใจ', bt: 'อุณหภูมิ'};
function tile(kind, icon, label, val) {
  return `<div class="lt ${kind}"><span class="ib">${ic(icon)}</span><div class="lx"><small>${label}</small><b>${val}</b></div></div>`;
}
// เกิน 12 ชม. ส่วนใหญ่คือเคสที่ออกจาก ER ไปแล้วแต่ยังไม่ได้บันทึกเวลาออก — ไม่ให้แย่งกรอบแดงจากคนไข้ที่ต้องดูจริง
const isStale = p => p.el > 720;
// สถานะที่แปลว่าจบการรักษาใน ER แล้ว แค่ยังไม่ได้บันทึกเวลาออก (กลับบ้าน/ส่ง รพ.สต./ส่งดูแลต่อ/เสียชีวิต/ปฏิเสธ/หนี/ไม่รอตรวจ)
// ไม่รวม 02 Admit และ 03 ส่งต่อ — ยังอยู่ใน ER รอย้าย/รอรถ ต้องดูแลต่อ
const DONE_CODES = <?= json_encode(array_map('strval', $CFG['done_codes']), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const isDone = p => DONE_CODES.indexOf(p.oc) >= 0;
// ความถี่วัดสัญญาณชีพซ้ำตามระดับ (นาที) — ระดับทั่วไปไม่กำหนด
const RECHECK = <?= json_encode((object)$CFG['recheck_min'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const vsDue = p => !isDone(p) && p.vs_ago != null && RECHECK[p.lvl] && p.vs_ago > RECHECK[p.lvl];
const vitalBad = p => { const b = p.bp ? parseInt(p.bp) : 0;
  return (b && (b < 90 || b > 180)) || (p.pr && (p.pr > 120 || p.pr < 50)) || (p.rr && (p.rr > 24 || p.rr < 10))
    || (p.o2 && p.o2 < 94) || (p.temp != null && (p.temp >= 38 || p.temp < 36)); };
// คนที่ต้องได้การ์ดเต็มเสมอ เมื่อจอแน่นจนต้องแบ่งชั้น
const needCard = p => !isDone(p) && ((p.lvl || 5) <= 2 || isHot(p) || vitalBad(p) || (p.sus && p.sus.length > 0)
  || vsDue(p) || (p.news && (p.news.risk === 'med' || p.news.risk === 'lowmed' || p.news.risk === 'high')));
function mini(p) {
  const lvl = p.lvl || 5, over = p.limit && p.el > p.limit && !isDone(p);
  const t = p.el >= 60 ? Math.floor(p.el / 60) + ' ชม. ' + (p.el % 60) + ' น.' : p.el + ' น.';
  const v = [p.bp ? 'BP ' + esc(p.bp) : '', p.pr ? 'PR ' + p.pr : '', p.o2 ? 'O₂ ' + p.o2 + '%' : '', p.temp != null ? 'T ' + p.temp : ''].filter(Boolean).join(' · ');
  return `<div class="mini lv${lvl} ${isDone(p) ? 'done' : ''}"><span class="mq">คิว ${esc(p.q)}</span>
    <span class="mn">${esc(p.name)} <small>${p.age != null ? p.age + ' ปี' : ''}</small></span>
    <span class="mcc">${esc(p.cc || '')}</span><span class="mv">${v}</span>
    ${p.allergy ? `<span class="malg" title="แพ้ยา">${ic('warn')}</span>` : ''}
    <span class="mst">${isDone(p) ? '✓ ' : ''}${esc(p.status || '-')}</span><span class="mel ${over ? 'over' : ''}">${t}</span></div>`;
}
const isHot = p => !isDone(p) && ((p.limit && p.el > p.limit) || (p.news && p.news.risk === 'high') || (p.crit && p.crit.length > 0));
function x_t(m) { return m >= 60 ? Math.floor(m / 60) + ' ชม.' : m + ' น.'; }
function card(p, now) {
  const sg = p.stage || {stage: 1}, over = p.limit && p.el > p.limit && !isStale(p) && !isDone(p);
  const t = p.el >= 60 ? Math.floor(p.el / 60) + ' ชม. ' + (p.el % 60) + ' น.' : p.el + ' นาที';
  const lvl = p.lvl || 5;
  const bps = p.bp ? parseInt(p.bp) : 0, sus = k => !!(p.sus && p.sus.indexOf(k) >= 0);
  const stale = isStale(p), done = isDone(p), hot = !stale && isHot(p);
  return `<div class="card lv${lvl} ${hot || (p.crit && p.crit.length) ? 'alert' : ''} ${stale ? 'stale' : ''} ${done ? 'done' : ''}">
    ${done ? `<div class="doneflag">${ic('ward')}จำหน่ายแล้ว · รอออกจากระบบ</div>` : ''}
    ${stale ? `<div class="stl-flag">${ic('clock')}ค้างในระบบเกิน 12 ชม. · ตรวจสอบปิดเคส</div>` : ''}
    <div class="top"><span class="q">คิว ${esc(p.q)}</span><span class="lvb">${LV[lvl] ? LV[lvl][1] : '-'}</span>
      <div class="nm">${esc(p.name)}<small>${p.age != null ? p.age + ' ปี · ' : ''}${esc(p.hn)}</small></div></div>
    ${p.cc ? `<div class="cc">${ic('note')}<span class="cct">${esc(p.cc)}</span></div>` : ''}
    <div class="vit">${p.bp ? vital('bp', esc(p.bp) + arrow(p, 'bp'), bps < 90 || bps > 180, 'ความดัน (mmHg)', sus('bp')) : ''}${p.pr ? vital('pr', p.pr + arrow(p, 'pr'), p.pr > 120 || p.pr < 50, 'ชีพจร (ครั้ง/นาที)', sus('pr')) : ''}${p.rr ? vital('rr', p.rr + arrow(p, 'rr'), p.rr > 24 || p.rr < 10, 'อัตราหายใจ (ครั้ง/นาที)', sus('rr')) : ''}${p.o2 ? vital('o2', p.o2 + '%' + arrow(p, 'o2'), p.o2 < 94, 'ออกซิเจนในเลือด SpO₂', sus('o2')) : ''}${p.temp != null ? vital('bt', p.temp + arrow(p, 'bt'), p.temp >= 38 || p.temp < 36, 'อุณหภูมิ (°C)', sus('bt')) : ''}</div>
    ${p.vs_ago != null ? `<div class="vsago ${vsDue(p) ? 'due' : ''}" title="${RECHECK[p.lvl] ? 'ระดับนี้ควรวัดทุก ' + RECHECK[p.lvl] + ' นาที' : ''}">${ic('clock')}${vsDue(p) ? 'ถึงเวลาวัด V/S · ล่าสุด ' : 'วัดล่าสุด '}${p.vs_ago < 1 ? 'เมื่อสักครู่' : (p.vs_ago >= 60 ? Math.floor(p.vs_ago / 60) + ' ชม. ' + (p.vs_ago % 60) + ' น.' : p.vs_ago + ' นาที') + 'ที่แล้ว'}</div>` : ''}
    ${p.sus && p.sus.length ? `<div class="susrow">${ic('warn')}ตรวจสอบค่า: ${p.sus.map(k => SUS_NAME[k]).join(', ')} <small>(ไม่นำมาคิด NEWS2)</small></div>` : ''}
    <div class="row"><span>${ic('news')}NEWS2</span>${news(p.news)}</div>
    ${p.allergy ? `<div><span class="alg">${ic('warn')}แพ้ยา${p.allergy_txt ? ': ' + esc(p.allergy_txt) : ''}</span></div>` : ''}
    <div class="st">${esc(p.status || '-')}</div>
    <div class="row"><span>${ic('clock')}อยู่ ER</span><b class="${over ? 'over' : ''}">${t}${over ? ' (เกิน ' + p.limit + ' น.)' : ''}</b></div>
    ${p.crit && p.crit.length ? tile('crit', 'warn', 'ค่าวิกฤต', p.crit.map(esc).join(' · ')) : ''}
    ${p.lab_n ? tile(p.lab_p ? 'lab' : 'labok', 'lab', 'ผลแล็บ', `ออกแล้ว ${p.lab_n - p.lab_p}/${p.lab_n}` + (p.lab_p ? ` · เหลือ ${p.lab_p}` + (p.lab_wait != null ? ` (รอ ${p.lab_wait} น.)` : '') : ' ✓')) : ''}
    ${p.abn ? tile('abn', 'lab', 'ผลผิดปกติ', p.abn + ' รายการ') : ''}
    ${p.xray && p.xray.length ? tile('xray', 'xray', 'X-Ray รอ', p.xray.map(x => esc(x.nm)).join(', ') + ' (' + x_t(p.xray[0].w) + ')') : ''}
    <div class="stage"><div class="stg">${STAGES.map((n, i) => `<i class="${i + 1 < sg.stage ? 'on' : i + 1 === sg.stage ? 'cur' : ''}"></i>`).join('')}</div>
      <div class="stx">ขั้นที่ ${sg.stage}/4 · <b>${esc(sg.th || STAGES[sg.stage - 1])}</b></div></div>
    ${p.ward ? `<div><span class="wd">${ic('ward')}รับไว้รักษา → ${esc(p.ward)}</span></div>` : ''}
  </div>`;
}

let sizeMode = 'auto'; try { sizeMode = localStorage.er_tl_size || 'auto'; } catch (e) {}
const _qs = new URLSearchParams(location.search).get('size'); if (['auto','normal','compact'].includes(_qs)) sizeMode = _qs;
const SIZE_TXT = {auto: 'อัตโนมัติ', normal: 'ปกติ', compact: 'ย่อ'};
function sizeLabel() {
  const c = document.body.classList.contains('compact');
  $('#bSize').textContent = 'ขนาด: ' + SIZE_TXT[sizeMode] + (sizeMode === 'auto' ? (c ? ' (ย่อ)' : ' (ปกติ)') : '');
}
function applySize(n) {
  // โหมดอัตโนมัติเริ่มที่ขนาดปกติเสมอ แล้วให้ fitZoom() ตัดสินว่าล้นจอจริงหรือไม่ค่อยย่อ
  document.body.classList.toggle('compact', sizeMode === 'compact');
  sizeLabel();
}
$('#bSize').onclick = () => { sizeMode = {auto: 'normal', normal: 'compact', compact: 'auto'}[sizeMode]; try { localStorage.er_tl_size = sizeMode; } catch (e) {} render(); };
function render() {
  if (!D) return;
  const now = nowS(), pts = D.patients;
  $('#sBeds').textContent = `ผู้ป่วยใน ER ${pts.filter(p => !isStale(p)).length} ราย`;
  $('#sOver').textContent = 'รอเกินเวลา ' + pts.filter(p => p.limit && p.el > p.limit && !isStale(p) && !isDone(p)).length;
  $('#sLab').textContent = 'รอผลแล็บ ' + pts.filter(p => p.lab_p).length;
  $('#sNews').textContent = 'NEWS2 สูง ' + pts.filter(p => p.news && p.news.risk === 'high').length;
  // เรียง: เคสค้างในระบบไปท้าย → ระดับ → ในระดับเดียวกัน คนที่ต้องดูด่วนขึ้นก่อน → NEWS2 สูงก่อน
  const list = pts.filter(p => !filterL || String(p.lvl) === filterL).sort((a, b) =>
    (isStale(a) - isStale(b)) || (isDone(a) - isDone(b)) || ((a.lvl || 5) - (b.lvl || 5)) || (isHot(b) - isHot(a))
    || (((b.news && b.news.score) || 0) - ((a.news && a.news.score) || 0)));
  const live = list.filter(p => !isStale(p)), stale = list.filter(isStale);
  applySize(live.length);
  const sb = $('#staleBar');
  if (stale.length) {
    // เคสค้างเรียงระดับรุนแรงก่อน — ถ้ามีวิกฤตปนอยู่ต้องเห็นเป็นตัวแรก
    sb.innerHTML = `<span class="sb-h">${ic('clock')}ค้างในระบบเกิน 12 ชม. ${stale.length} ราย</span><span class="sb-s">ตรวจสอบ/ปิดเคสใน HOSxP:</span>`
      + stale.map(p => `<span class="sb-i lv${p.lvl || 5} ${(p.lvl || 5) <= 2 ? 'hi' : ''}"><i class="dot"></i>คิว ${esc(p.q)}${(p.lvl || 5) <= 2 ? ' · ' + LV[p.lvl][1].split(' · ')[0] : ''} <small>${Math.floor(p.el / 60)} ชม.</small></span>`).join('');
    sb.style.display = '';
  } else sb.style.display = 'none';
  const w = $('.wrap'), keep = w.scrollTop;
  const g = $('#grid');
  g.innerHTML = live.length ? live.map(p => card(p, now)).join('') : '<div class="empty">ไม่มีผู้ป่วยในขณะนี้</div>';
  if (!fitZoom(false)) {
    // ล้นจอ → แบ่งชั้น: คนที่ต้องดูได้การ์ดเต็ม คนที่เหลือเป็นแถวย่อบรรทัดเดียว (ไม่เลื่อนหน้า)
    const full = live.filter(needCard), rest = live.filter(p => !needCard(p));
    if (rest.length) {
      g.innerHTML = full.map(p => card(p, now)).join('')
        + `<div class="minis"><div class="minis-h">ผู้ป่วยอาการคงที่ ${rest.length} ราย</div>${rest.map(mini).join('')}</div>`;
    }
    fitZoom(true);
  }
  w.style.scrollBehavior = 'auto'; w.scrollTop = keep; w.style.scrollBehavior = '';
}

// ผู้ป่วยล้นจอ: จำกัดความสูงพอดีจอ + เลื่อนหน้าอัตโนมัติ
function fit() {
  const w = $('.wrap'); const sbh = $('#staleBar').style.display === 'none' ? 0 : $('#staleBar').offsetHeight;
  w.style.maxHeight = Math.max(240, window.innerHeight - w.getBoundingClientRect().top - sbh - 4) + 'px';
  const pageH = Math.max(1, w.clientHeight), pages = Math.max(1, Math.ceil((w.scrollHeight - 1) / pageH));
  const el = $('#pg'); el.style.display = pages > 1 ? '' : 'none';
  el.textContent = 'หน้า ' + Math.min(pages, Math.round(w.scrollTop / pageH) + 1) + '/' + pages + ' · เลื่อนอัตโนมัติ';
}
// คนไข้น้อย → ขยายการ์ด (zoom) ให้เต็มจอ อ่านจากไกลได้; คนไข้เยอะ → zoom 1 แล้วใช้โหมดย่อ/เลื่อนหน้าแทน
// หา zoom สูงสุดที่ทุกการ์ดยังอยู่ในจอเดียวด้วย binary search (ทำตอน render/resize เท่านั้น ไม่ทำตอน scroll)
function fitZoom(allowShrink) {
  const w = $('.wrap'), g = $('#grid');
  g.style.zoom = 1; fit();
  // วัดจากตำแหน่งจริงบนจอ (getBoundingClientRect) — scrollHeight ไม่นับ zoom ถูกในบางเวอร์ชัน
  const fits = () => { const gr = g.getBoundingClientRect(), wr = w.getBoundingClientRect();
    return gr.bottom <= wr.top + w.clientHeight - 6 && gr.right <= wr.left + w.clientWidth + 1; };
  if (!fits() && sizeMode === 'auto' && !document.body.classList.contains('compact')) {
    document.body.classList.add('compact'); sizeLabel(); fit();   // ขนาดปกติล้นจอ → ลองโหมดย่อก่อน
  }
  if (!fits()) {
    if (!allowShrink) return false;
    // แบ่งชั้นแล้วยังล้น → ย่อทั้งหน้าลงทีละนิด ต่ำสุด 70% (เล็กกว่านี้อ่านจากไกลไม่ออก)
    for (let z = 0.95; z >= 0.7; z -= 0.05) { g.style.zoom = z.toFixed(2); if (fits()) { fit(); return true; } }
    fit(); return false;                     // ยังล้น — เลื่อนหน้าเป็นทางสุดท้าย
  }
  // ไล่จากใหญ่ลงเล็ก: zoom ที่ใหญ่ขึ้นทำให้จำนวนคอลัมน์ลดลงด้วย ผลจึงไม่ไล่ระดับเรียบ ใช้ binary search ไม่ได้
  for (let z = 2.4; z > 1; z -= 0.05) {
    g.style.zoom = z.toFixed(2);
    if (fits()) { fit(); return true; }
  }
  g.style.zoom = 1; fit(); return true;
}
function autoPage() {
  const w = $('.wrap');
  if (Date.now() - lastTouch < 30000  || w.scrollHeight <= w.clientHeight + 2) return;
  if (w.scrollTop + w.clientHeight >= w.scrollHeight - 2) w.scrollTop = 0; else w.scrollTop += w.clientHeight - 12;
  setTimeout(fit, 700);
}
['wheel','touchstart','mousedown','keydown'].forEach(ev => addEventListener(ev, () => lastTouch = Date.now(), {passive: true}));
addEventListener('resize', fitZoom);
// ฟอนต์ Prompt โหลดเสร็จทีหลัง ตัวอักษรใหญ่ขึ้น → คำนวณขนาดใหม่ ไม่งั้นการ์ดล่างล้นใต้แถบ
if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { if (D) render(); }); $('.wrap').addEventListener('scroll', fit);

$('#bDark').onclick = () => { const on = document.documentElement.classList.toggle('dark'); try { localStorage.er_tl_dark = on ? '1' : '0'; } catch (e) {} };
$('#bFull').onclick = () => document.fullscreenElement ? document.exitFullscreen() : (document.documentElement.requestFullscreen && document.documentElement.requestFullscreen());
document.querySelectorAll('.chip').forEach(c => c.onclick = () => {
  document.querySelectorAll('.chip').forEach(x => x.classList.remove('on')); c.classList.add('on'); filterL = c.dataset.l; $('.wrap').scrollTop = 0; render();
});
setInterval(() => {
  const c = $('#conn'), age = (Date.now() - lastOk) / 1000;
  const t = lastOk ? new Date(lastOk).toTimeString().slice(0, 5) : '--:--';
  c.classList.toggle('lost', !lastOk || age > <?= (int)$CFG['poll_sec'] * 3 ?>);
  c.textContent = (!lastOk || age > <?= (int)$CFG['poll_sec'] * 3 ?>) ? '⚠ ขาดการเชื่อมต่อ · ข้อมูลเมื่อ ' + t : 'อัปเดต ' + Math.floor(age) + ' วิที่แล้ว';
}, 1000);
setInterval(() => { $('#clock').textContent = new Date(nowS() * 1000).toTimeString().slice(0, 8); }, 1000);
setInterval(render, 15000);
setInterval(load, <?= (int)$CFG['poll_sec'] * 1000 ?>);
setInterval(autoPage, 10000);
load();
</script>
<div id="credit">พัฒนาโดย <?= htmlspecialchars($CFG['dev_name']) ?> · <?= htmlspecialchars($CFG['dev_title']) ?> · <?= htmlspecialchars($CFG['dev_org']) ?></div>
</body>
</html>
