<?php
// หน้าตั้งค่าผ่านเว็บ: ติดตั้งครั้งแรก (ตั้งรหัสผ่านผู้ดูแล) และแก้ค่าภายหลัง (ต้องใส่รหัสผ่านผู้ดูแล)
// ผู้พัฒนา: ธนาวุฒิ สีดวง นักวิชาการคอมพิวเตอร์ปฏิบัติการ โรงพยาบาลบ้านไผ่
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
$c = cb_config(false);
$cf = cb_config_file();
$exists = is_file($cf);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = ''; $ok = false;
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$authed = !$exists || !empty($_SESSION['cb_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); exit('bad request'); }
    if (isset($_POST['login'])) {
        if (cb_throttled('admin')) $msg = 'ผิดหลายครั้ง กรุณารอ 10 นาที';
        elseif (password_verify($_POST['pw'] ?? '', $c['admin_hash'] ?? '')) { $_SESSION['cb_admin'] = 1; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(16)); $authed = true; }
        else { cb_fail('admin'); $msg = 'รหัสผ่านไม่ถูกต้อง'; }
    } elseif ($authed) {
        foreach (['db_host','db_name','db_user','hospital','timezone'] as $k) $c[$k] = trim($_POST[$k] ?? $c[$k]);
        if (($_POST['db_pass'] ?? '') !== '') $c['db_pass'] = $_POST['db_pass'];   // เว้นว่าง = ใช้รหัสเดิม
        $c['db_port'] = (int)($_POST['db_port'] ?? 3306) ?: 3306;
        $c['mask_name'] = !empty($_POST['mask_name']);
        $c['poll_sec'] = max(5, (int)($_POST['poll_sec'] ?? 30));
        $c['cache_sec'] = max(1, min((int)($_POST['cache_sec'] ?? 15), $c['poll_sec']));
        foreach (['limit_min','recheck_min'] as $g) foreach ([1,2,3,4,5] as $l) if (isset($_POST[$g][$l])) $c[$g][$l] = max(0, (int)$_POST[$g][$l]);
        $c['done_codes'] = array_values(array_filter(array_map('trim', explode(',', $_POST['done_codes'] ?? '')), 'strlen'));
        if (!empty($_POST['view_pass_off'])) $c['view_pass_hash'] = '';
        elseif (($_POST['view_pw'] ?? '') !== '') $c['view_pass_hash'] = password_hash($_POST['view_pw'], PASSWORD_DEFAULT);
        $c['allow_ips'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['allow_ips'] ?? '')), 'strlen'));
        foreach ($c['allow_ips'] as $ip) { $b = explode('/', $ip)[0]; if (@inet_pton($b) === false) { $msg = "IP/เครือข่ายไม่ถูกต้อง: $ip"; break; } }
        if ($msg) {}
        elseif (!$exists && strlen($_POST['admin_pw'] ?? '') < 6) $msg = 'ตั้งรหัสผ่านผู้ดูแลอย่างน้อย 6 ตัวอักษร';
        else {
            if (($_POST['admin_pw'] ?? '') !== '') $c['admin_hash'] = password_hash($_POST['admin_pw'], PASSWORD_DEFAULT);
            try {
                $db = new PDO("mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8", $c['db_user'], $c['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $miss = [];
                foreach (['er_regist','ovst','ovstost','patient','opdscreen','opdscreen_bp','lab_head','lab_order','xray_head','opd_allergy','ipt','ward'] as $t) {
                    $q = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=?"); $q->execute([$c['db_name'], $t]);
                    if (!$q->fetch()) $miss[] = $t;
                }
                if ($miss) $msg = 'เชื่อมต่อได้ แต่ไม่พบตาราง: ' . implode(', ', $miss);
                else {
                    $n = $db->query("SELECT COUNT(*) FROM er_regist WHERE leave_datetime IS NULL AND enter_er_time > NOW() - INTERVAL 12 HOUR")->fetchColumn();
                    if (@file_put_contents($cf, "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX) === false) $msg = 'เขียนไฟล์ตั้งค่าไม่ได้ — ตรวจสิทธิ์โฟลเดอร์';
                    else { @chmod($cf, 0640); $ok = true; $exists = true; $_SESSION['cb_admin'] = 1; $msg = "บันทึกแล้ว ✓ พบผู้ป่วยอยู่ใน ER ขณะนี้ $n ราย"; }
                }
            } catch (Throwable $e) { $msg = 'เชื่อมต่อไม่ได้: ' . $e->getMessage(); }
        }
    }
}
?><!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ตั้งค่า ER Care Board</title>
<style>body{font-family:sans-serif;background:#f1f5f9;margin:0;padding:24px;color:#1e293b}form{max-width:520px;margin:auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 10px #0001}
label{display:block;margin:12px 0 4px;font-size:14px;color:#475569}input[type=text],input[type=password],input[type=number]{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
button{margin-top:18px;padding:10px 20px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:16px;cursor:pointer}.m{padding:10px;border-radius:8px;margin:12px 0}.ok{background:#dcfce7}.er{background:#fee2e2}small{color:#94a3b8}
.g{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}.g span{font-size:12px;color:#64748b;text-align:center}h3{margin:22px 0 0;font-size:15px}.f{text-align:center;margin-top:14px;font-size:12px;color:#94a3b8}</style></head><body>
<form method="post"><h2>ตั้งค่า ER Care Board</h2>
<input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
<?php if ($msg): ?><div class="m <?= $ok ? 'ok' : 'er' ?>"><?= $h($msg) ?></div><?php endif; ?>
<?php if ($ok): ?><p><a href="index.php">เปิดหน้าจอมอนิเตอร์ →</a></p><?php endif; ?>
<?php if (!$authed): ?>
<label>รหัสผ่านผู้ดูแล</label><input type="password" name="pw" autofocus><button name="login" value="1">เข้าสู่ระบบ</button>
<?php else: ?>
<label>ชื่อโรงพยาบาล</label><input type="text" name="hospital" value="<?= $h($c['hospital']) ?>">
<h3>ฐานข้อมูล HOSxP</h3>
<label>Host</label><input type="text" name="db_host" value="<?= $h($c['db_host']) ?>">
<label>Port</label><input type="number" name="db_port" value="<?= $h($c['db_port']) ?>">
<label>ชื่อฐานข้อมูล</label><input type="text" name="db_name" value="<?= $h($c['db_name']) ?>">
<label>ผู้ใช้ (แนะนำสิทธิ์ SELECT อย่างเดียว)</label><input type="text" name="db_user" value="<?= $h($c['db_user']) ?>">
<label>รหัสผ่าน DB <?= $exists ? '<small>(เว้นว่าง = ใช้ค่าเดิม)</small>' : '' ?></label><input type="password" name="db_pass" autocomplete="new-password">
<h3>การแสดงผล</h3>
<label><input type="checkbox" name="mask_name" <?= $c['mask_name'] ? 'checked' : '' ?>> ปิดบังชื่อ/HN บางส่วน</label>
<label>Timezone</label><input type="text" name="timezone" value="<?= $h($c['timezone']) ?>">
<label>จอถามข้อมูลทุก (วินาที)</label><input type="number" name="poll_sec" value="<?= $h($c['poll_sec']) ?>">
<label>แคชร่วมทุกจอ (วินาที)</label><input type="number" name="cache_sec" value="<?= $h($c['cache_sec']) ?>">
<h3>เกณฑ์ตามระดับ (1=วิกฤต … 5=ทั่วไป)</h3>
<label>เวลาใน ER ไม่ควรเกิน (นาที, 0 = ไม่จำกัด)</label>
<div class="g"><?php foreach ([1,2,3,4,5] as $l): ?><div><span>ระดับ <?= $l ?></span><input type="number" name="limit_min[<?= $l ?>]" value="<?= $h($c['limit_min'][$l] ?? 0) ?>"></div><?php endforeach; ?></div>
<label>เตือนวัด V/S ซ้ำทุก (นาที)</label>
<div class="g"><?php foreach ([1,2,3,4,5] as $l): ?><div><span>ระดับ <?= $l ?></span><input type="number" name="recheck_min[<?= $l ?>]" value="<?= $h($c['recheck_min'][$l] ?? 0) ?>"></div><?php endforeach; ?></div>
<label>รหัสสถานะ ovstost ที่ถือว่าจบการรักษา (คั่นด้วยจุลภาค)</label><input type="text" name="done_codes" value="<?= $h(implode(',', $c['done_codes'])) ?>">
<h3>ควบคุมการเข้าถึงหน้าจอ</h3>
<label>รหัสผ่านเข้าจอมอนิเตอร์ <small>(<?= !empty($c['view_pass_hash']) ? 'เปิดใช้อยู่ · เว้นว่าง = ไม่เปลี่ยน' : 'ว่าง = ไม่ใช้รหัสผ่าน' ?>)</small></label><input type="password" name="view_pw" autocomplete="new-password">
<?php if (!empty($c['view_pass_hash'])): ?><label><input type="checkbox" name="view_pass_off"> ปิดรหัสผ่านเข้าจอ</label><?php endif; ?>
<label>IP/เครือข่ายที่อนุญาต <small>(คั่นด้วยจุลภาคหรือขึ้นบรรทัดใหม่ เช่น 192.168.0.0/24 · ว่าง = ทุก IP)</small></label><input type="text" name="allow_ips" value="<?= $h(implode(', ', $c['allow_ips'])) ?>">
<h3>ผู้ดูแล</h3>
<label>รหัสผ่านผู้ดูแล <?= $exists ? '<small>(เว้นว่าง = ไม่เปลี่ยน)</small>' : '<small>(อย่างน้อย 6 ตัว)</small>' ?></label><input type="password" name="admin_pw" autocomplete="new-password">
<button>ทดสอบและบันทึก</button>
<?php endif; ?>
<div class="f">พัฒนาโดย <?= $h($c['dev_name']) ?> · <?= $h($c['dev_title']) ?> · <?= $h($c['dev_org']) ?></div></form></body></html>
