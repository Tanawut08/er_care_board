<?php
// ฟังก์ชันร่วม: โหลด config + คำนวณขั้นตอนการรักษา
// ผู้พัฒนา: ธนาวุฒิ สีดวง นักวิชาการคอมพิวเตอร์ปฏิบัติการ โรงพยาบาลบ้านไผ่

function cb_config_file(): string {
    $d = getenv('ER_CONFIG_DIR');   // Docker: โฟลเดอร์ที่เป็น volume เก็บค่าตั้งค่าถาวร
    return rtrim($d ?: __DIR__, '/') . '/config.php';
}

function cb_config(bool $redirect = true): array {
    $def = is_file(__DIR__ . '/config.sample.php') ? require __DIR__ . '/config.sample.php' : [];
    $f = cb_config_file();
    if (!is_file($f)) {
        if ($redirect) { header('Location: install.php'); exit; }
        return $def;
    }
    $c = require $f;
    return array_merge($def, is_array($c) ? $c : []);
}

// ขั้นตอน 1-4: ลงทะเบียน → ตรวจรักษา → รับยา → เสร็จสิ้น
function cb_stage(array $row): array {
    $finished = !empty($row['finish_time']);
    $pending_at = !empty($row['pending_lab_at']) ? $row['pending_lab_at'] : null;
    $detail_th = $detail_en = '';
    if ($finished)                                 { $stage = 4; $th = 'เสร็จสิ้น'; $en = 'Completed'; }
    elseif (($row['ovstost_code'] ?? '') === '05') { $stage = 3; $th = 'รอรับยา'; $en = 'Waiting for medication'; }
    elseif ($pending_at) {
        $stage = 2; $th = 'รอผลแล็บ'; $en = 'Waiting for lab';
        $mins = max(0, (int)floor((time() - strtotime($pending_at)) / 60));
        $detail_th = $mins < 1 ? 'เพิ่งส่งตรวจ' : $mins . ' นาที';
        $detail_en = $mins < 1 ? 'just sent' : $mins . ' min';
    }
    else                                           { $stage = 2; $th = 'กำลังตรวจรักษา'; $en = 'Under treatment'; }
    return ['stage' => $stage, 'th' => $th, 'en' => $en, 'detail_th' => $detail_th, 'detail_en' => $detail_en];
}

// ---------- การเข้าถึงหน้าจอ: จำกัด IP + รหัสผ่านเข้าจอ (เปิดใช้ใน config) ----------
function cb_ip_allowed(string $ip, array $list): bool {
    if (!$list) return true;
    $b = @inet_pton($ip);
    if ($b === false) return false;
    foreach ($list as $r) {
        $r = trim((string)$r); if ($r === '') continue;
        [$net, $bits] = array_pad(explode('/', $r, 2), 2, null);
        $nb = @inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($b)) continue;
        $bits = $bits === null ? strlen($b) * 8 : max(0, min((int)$bits, strlen($b) * 8));
        $full = intdiv($bits, 8); $rem = $bits % 8;
        if (substr($b, 0, $full) !== substr($nb, 0, $full)) continue;
        if ($rem) { $m = (0xFF << (8 - $rem)) & 0xFF; if ((ord($b[$full]) & $m) !== (ord($nb[$full]) & $m)) continue; }
        return true;
    }
    return false;
}

// กันเดารหัส: ผิด 5 ครั้งใน 10 นาทีจาก IP เดียวกัน → ล็อกชั่วคราว
function cb_throttle_file(string $tag): string { return sys_get_temp_dir() . '/er_cb_fail_' . md5($tag . ($_SERVER['REMOTE_ADDR'] ?? '')); }
function cb_throttled(string $tag): bool {
    $f = cb_throttle_file($tag);
    $n = is_file($f) && time() - filemtime($f) < 600 ? (int)@file_get_contents($f) : 0;
    return $n >= 5;
}
function cb_fail(string $tag): void {
    $f = cb_throttle_file($tag);
    $n = is_file($f) && time() - filemtime($f) < 600 ? (int)@file_get_contents($f) : 0;
    @file_put_contents($f, $n + 1, LOCK_EX); @chmod($f, 0600); usleep(700000);
}

function cb_view_token(array $c): string { return hash_hmac('sha256', 'view', (string)$c['view_pass_hash']); }

// เรียกต้นหน้า index.php: ไม่ผ่านเงื่อนไข → หยุดทันที
function cb_gate(array $c, bool $isApi): void {
    if (!cb_ip_allowed($_SERVER['REMOTE_ADDR'] ?? '', (array)($c['allow_ips'] ?? []))) {
        http_response_code(403); exit($isApi ? '{"ok":false,"err":"forbidden"}' : 'Forbidden — IP นี้ไม่ได้รับอนุญาต');
    }
    if (empty($c['view_pass_hash'])) return;
    $tok = cb_view_token($c);
    if (hash_equals($tok, (string)($_COOKIE['er_cb_view'] ?? ''))) return;
    if ($isApi) { http_response_code(401); header('Content-Type: application/json'); exit('{"ok":false,"err":"auth"}'); }
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vpw'])) {
        if (cb_throttled('view')) $msg = 'ผิดหลายครั้ง กรุณารอ 10 นาที';
        elseif (password_verify($_POST['vpw'], $c['view_pass_hash'])) {
            setcookie('er_cb_view', $tok, ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
        } else { cb_fail('view'); $msg = 'รหัสผ่านไม่ถูกต้อง'; }
    }
    header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ER Care Board</title>'
       . '<style>body{font-family:sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}form{background:#fff;padding:32px;border-radius:14px;box-shadow:0 2px 12px #0002;text-align:center;min-width:300px}'
       . 'input{width:100%;padding:12px;font-size:20px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;margin:14px 0}button{padding:12px 28px;font-size:18px;border:0;border-radius:8px;background:#2563eb;color:#fff}.e{color:#dc2626}</style></head><body>'
       . '<form method="post"><h2>' . $h($c['hospital']) . '</h2><div>ER Care Board</div><input type="password" name="vpw" placeholder="รหัสผ่านเข้าจอ" autofocus>'
       . ($msg ? '<div class="e">' . $h($msg) . '</div>' : '') . '<button>เข้าสู่ระบบ</button></form></body></html>';
    exit;
}
