<?php
/**
 * jbackup.php — สร้างแพ็กเกจย้ายเว็บ Joomla จาก server เดิม (ไฟล์ + ฐานข้อมูล)
 * © บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด (COLORPACK CREATION CO., LTD.) — https://colorpack.co.th/
 *
 * วิธีใช้
 *  - แก้ MIGRATE_KEY ให้เป็นรหัสของคุณ (ใช้รหัสเดียวกับ jmigrate.php บน server ใหม่ จะดึงแพ็กเกจข้ามเครื่องได้)
 *  - อัปโหลดไว้ที่ root ของเว็บเดิม (ที่เดียวกับ configuration.php) แล้วเปิด jbackup.php กรอกรหัสในหน้า login
 *  - กด "สร้างแพ็กเกจ" ระบบจะ dump ฐานข้อมูลและบีบอัดไฟล์แบบแบ่งรอบ ไม่ติด timeout
 *  - ได้โฟลเดอร์ jbackup-<ชื่อ>/ ที่มี db.sql.gz, site-part1.zip …, manifest.json
 *  - บน server ใหม่ เปิด jmigrate.php → ส่วน "กู้จากแพ็กเกจ" ใส่ URL ของไฟล์นี้ จะดึงแพ็กเกจไปเอง
 *  - เสร็จแล้วลบไฟล์นี้และโฟลเดอร์แพ็กเกจออกจาก server
 */

const MIGRATE_KEY  = 'changeme123';        // <<< แก้เป็นรหัสของคุณ (ต้องตรงกับ jmigrate.php)
const TOOL_TITLE   = 'Joomla Backup Tool';
const TOOL_VERSION = '1.1.1';
const STEP_SECONDS = 8;                    // เวลาทำงานต่อรอบ
const PART_SIZE    = 500 * 1024 * 1024;    // ขนาดสูงสุดของ zip แต่ละส่วน
const STORE_OVER   = 64 * 1024 * 1024;     // ไฟล์ใหญ่กว่านี้เก็บแบบไม่บีบอัด (เร็วกว่า)
const MAX_ENTRIES  = 60000;                // จำนวนไฟล์สูงสุดต่อส่วน (จำกัดของ zip ธรรมดา)
const ROWS_PER_Q   = 1000;                 // แถวต่อ 1 คำสั่ง INSERT

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);
@ini_set('memory_limit', '512M');
@set_time_limit(0);
@ignore_user_abort(true);
header('X-Robots-Tag: noindex, nofollow');

define('ROOT', __DIR__);
define('CFG_FILE', ROOT . '/configuration.php');

// ---------------------------------------------------------------- auth: login form (cookie) หรือ ?key= สำหรับเครื่องมือเรียกข้ามเครื่อง
function auth_token() { return hash_hmac('sha256', 'jm-auth|' . __FILE__ . '|' . @filemtime(__FILE__), MIGRATE_KEY); }
function auth_ok() {
    $k = isset($_REQUEST['key']) ? (string)$_REQUEST['key'] : '';
    if ($k !== '' && hash_equals(MIGRATE_KEY, $k)) return true;
    $c = isset($_COOKIE['jm_auth']) ? (string)$_COOKIE['jm_auth'] : '';
    return $c !== '' && hash_equals(auth_token(), $c);
}
function auth_cookie($on) { @setcookie('jm_auth', $on ? auth_token() : '', $on ? time() + 86400 : time() - 3600, dirname($_SERVER['SCRIPT_NAME']) ?: '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true); }
if (isset($_GET['logout'])) { auth_cookie(false); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
$loginErr = false;
if (isset($_POST['login_key'])) {
    if (hash_equals(MIGRATE_KEY, (string)$_POST['login_key'])) { auth_cookie(true); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (isset($_POST['lang']) ? '?lang=' . ($_POST['lang'] === 'en' ? 'en' : 'th') : '')); exit; }
    sleep(1); $loginErr = true;
}
$key = isset($_REQUEST['key']) ? (string)$_REQUEST['key'] : '';
if (!auth_ok()) {
    if (isset($_REQUEST['action'])) { header('HTTP/1.1 403 Forbidden'); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'msg' => 'auth required']); exit; }
    $L = isset($_GET['lang']) ? $_GET['lang'] : (isset($_COOKIE['jm_lang']) ? $_COOKIE['jm_lang'] : 'th'); $en = $L === 'en';
    header('HTTP/1.1 401 Unauthorized'); header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="' . ($en ? 'en' : 'th') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . TOOL_TITLE . '</title>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#111;font-family:"Kanit","Noto Sans Thai",system-ui,sans-serif;color:#111;padding:24px;box-sizing:border-box}
.box{background:#fff;border-radius:20px;padding:36px 32px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
h1{font-size:24px;font-weight:600;margin:18px 0 4px}p{margin:0 0 20px;color:#666;font-size:15px}
input{width:100%;box-sizing:border-box;min-height:50px;padding:10px 14px;border:2px solid #e1e6eb;border-radius:12px;font:18px "Kanit",system-ui,sans-serif;letter-spacing:.1em}input:focus{outline:none;border-color:#3b8fe0;box-shadow:0 0 0 4px rgba(59,143,224,.28)}
button{width:100%;min-height:50px;margin-top:14px;border:0;border-radius:14px;background:#3b8fe0;color:#fff;font:500 17px "Kanit",system-ui,sans-serif;cursor:pointer}button:hover{background:#2f7ac4}
.err{background:#fde8e8;color:#b81b1c;border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:15px}
.lang{text-align:center;margin-top:18px;font-size:14px}.lang a{color:#666;text-decoration:none;margin:0 6px}.lang a.on{color:#111;font-weight:600}
.dots{display:flex;gap:6px}.dots i{width:12px;height:12px;border-radius:50%}</style></head><body>
<form class="box" method="post" autocomplete="off">
<div class="dots"><i style="background:#3b8fe0"></i><i style="background:#6cc04a"></i><i style="background:#f7941c"></i><i style="background:#ef2b2c"></i></div>
<h1>' . TOOL_TITLE . '</h1><p>' . ($en ? 'Enter the access key set as <code>MIGRATE_KEY</code> in this file' : 'กรอกรหัสที่ตั้งไว้ใน <code>MIGRATE_KEY</code> ของไฟล์นี้') . '</p>'
    . ($loginErr ? '<div class="err">' . ($en ? 'Wrong key' : 'รหัสไม่ถูกต้อง') . '</div>' : '')
    . '<input type="password" name="login_key" placeholder="' . ($en ? 'Access key' : 'รหัสเข้าใช้') . '" autofocus required><input type="hidden" name="lang" value="' . ($en ? 'en' : 'th') . '">
<button type="submit">' . ($en ? 'Sign in' : 'เข้าใช้งาน') . '</button>
<div class="lang"><a href="?lang=th" class="' . ($en ? '' : 'on') . '">ไทย</a>|<a href="?lang=en" class="' . ($en ? 'on' : '') . '">EN</a></div>
</form></body></html>';
    exit;
}

// ---------------------------------------------------------------- language
$LANG = isset($_GET['lang']) ? $_GET['lang'] : (isset($_COOKIE['jm_lang']) ? $_COOKIE['jm_lang'] : 'th');
$LANG = $LANG === 'en' ? 'en' : 'th';
if (isset($_GET['lang'])) @setcookie('jm_lang', $LANG, time() + 86400 * 30, '/');
$GLOBALS['JM_LANG'] = $LANG;
function t($s) { return ($GLOBALS['JM_LANG'] === 'en' && isset($GLOBALS['JM_EN'][$s])) ? $GLOBALS['JM_EN'][$s] : $s; }
$GLOBALS['JM_EN'] = [
    'สร้างแพ็กเกจย้ายเว็บจาก server เดิม ·' => 'Build a migration package on the old server ·',
    'ยังใช้รหัส key ค่าเริ่มต้นอยู่ ควรแก้ค่า' => 'You are still using the default key — change',
    'ในไฟล์ก่อนใช้งานจริง และใช้รหัสเดียวกับ jmigrate.php บน server ใหม่' => 'in the file before real use, and use the same key in jmigrate.php on the new server',
    'ไม่พบ configuration.php ในโฟลเดอร์นี้ ต้องวางไฟล์นี้ไว้ที่ root ของเว็บ' => 'configuration.php not found here — put this file in the site root',
    'เว็บที่จะสำรอง' => 'Site to back up', 'root เว็บ' => 'site root', 'เวอร์ชัน Joomla' => 'Joomla version', 'ฐานข้อมูล' => 'Database',
    'พื้นที่ดิสก์ว่าง' => 'Free disk space', 'ขนาดโฟลเดอร์เว็บ (ประมาณ)' => 'Site folder size (approx.)', 'ไม่ทราบ' => 'unknown',
    'PHP' => 'PHP', 'มี' => 'present', 'ไม่มี' => 'missing',
    'ตั้งค่าแพ็กเกจ' => 'Package settings', 'ชื่อแพ็กเกจ' => 'Package name',
    'ข้ามข้อมูลของตารางที่ชื่อมีคำเหล่านี้ (สร้างตารางแต่ไม่ใส่ข้อมูล)' => 'Skip data for tables whose name contains (tables are created but left empty)',
    'โฟลเดอร์/ไฟล์ที่ไม่เอา (คั่นด้วย , เทียบจาก root)' => 'Folders / files to exclude (comma-separated, relative to root)',
    'ข้ามไฟล์ backup เก่าที่ root (*.zip *.gz *.tar *.sql)' => 'Skip old backup files in the root (*.zip *.gz *.tar *.sql)',
    'รวมฐานข้อมูล' => 'Include the database', 'รวมไฟล์เว็บ' => 'Include site files',
    'ขนาดต่อส่วน (MB)' => 'Part size (MB)',
    'สร้างแพ็กเกจ' => 'Build package', 'ทำต่อจากจุดเดิม' => 'Resume', 'หยุดชั่วคราว' => 'Pause', 'ยังไม่เริ่ม' => 'Not started',
    'ความคืบหน้า' => 'Progress',
    'กำลัง dump ฐานข้อมูล' => 'Dumping database', 'ตาราง' => 'tables', 'แถว' => 'rows',
    'กำลังสำรวจไฟล์' => 'Scanning files', 'กำลังบีบอัดไฟล์' => 'Compressing files', 'ไฟล์' => 'files', 'ส่วน' => 'parts',
    'กำลังคำนวณ checksum' => 'Computing checksums', 'เสร็จแล้ว' => 'Finished',
    'server กำลังทำงานรอบก่อนหน้าอยู่ รอสักครู่...' => 'The server is still finishing the previous round, waiting...',
    'รอบนี้ล้มเหลว (' => 'This round failed (', ') ลองใหม่ครั้งที่ ' => ') retry #', 'ล้มเหลวติดต่อกัน 3 ครั้ง: ' => 'Failed 3 times in a row: ',
    'แพ็กเกจที่มี' => 'Packages', 'ไม่มีแพ็กเกจ' => 'No packages yet', 'ลบ' => 'Delete', 'ดาวน์โหลด' => 'Download',
    'ลบแพ็กเกจนี้?' => 'Delete this package?', 'ลบแล้ว' => 'Deleted',
    'วิธีนำไปใช้บน server ใหม่' => 'How to use it on the new server',
    'เปิด jmigrate.php บน server ใหม่ → ส่วน "กู้จากแพ็กเกจ" → วาง URL นี้ ระบบจะดึงไฟล์ทุกส่วนไปเองแบบแบ่งรอบ (ต้องใช้ key เดียวกัน)' => 'Open jmigrate.php on the new server → "Restore from package" → paste this URL. It pulls every part itself in rounds (same key required).',
    'หรือดาวน์โหลดทุกไฟล์ในโฟลเดอร์แพ็กเกจแล้วอัปโหลดไปวางที่ root ของ server ใหม่ในโฟลเดอร์ชื่อเดียวกัน' => 'Or download every file in the package folder and upload them to the new server root, in a folder with the same name.',
    'เสร็จแล้วให้ลบ jbackup.php และโฟลเดอร์แพ็กเกจออกจาก server นี้' => 'When done, delete jbackup.php and the package folders from this server',
    'ลบไฟล์เครื่องมือนี้' => 'Delete this tool', 'ออกจากระบบ' => 'Sign out', 'ลบ jbackup.php ออกจาก server? (แพ็กเกจจะยังอยู่)' => 'Delete jbackup.php from the server? (packages stay)',
    'เชื่อมต่อฐานข้อมูลไม่ได้: ' => 'Cannot connect to the database: ',
    'ไม่มีอะไรให้สำรอง (ปิดทั้งฐานข้อมูลและไฟล์)' => 'Nothing to back up (both database and files are off)',
    'สร้างโฟลเดอร์แพ็กเกจไม่ได้ ตรวจ permission ของ root' => 'Could not create the package folder — check root permissions',
    'ข้ามไฟล์ใหญ่กว่า 4 GB: ' => 'Skipped file over 4 GB: ', 'อ่านไฟล์ไม่ได้: ' => 'Could not read file: ',
    'บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด' => 'Colorpack Creation Co., Ltd.', 'บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด สงวนลิขสิทธิ์' => 'Colorpack Creation Co., Ltd. All rights reserved.',
    'ข้อมูล (ตาราง / แถว / ไฟล์ / ขนาด)' => 'Contents (tables / rows / files / size)', 'คำเตือน' => 'Warnings',
    'URL สำหรับดึงจาก server ใหม่' => 'URL to pull from the new server',
];

// ---------------------------------------------------------------- helpers
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function json_out($d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fmt_bytes($b) { if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB'; if ($b >= 1048576) return round($b / 1048576, 1) . ' MB'; if ($b >= 1024) return round($b / 1024) . ' KB'; return (int)$b . ' B'; }
function cp_logo($h = 40) { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 680 172" class="cp-logo" style="height:' . (int)$h . 'px;width:auto;display:block" role="img" aria-label="COLORPACK CREATIONS"><text class="cp-w" x="0" y="92" font-size="112" fill="#2BA7DF" textLength="372" lengthAdjust="spacingAndGlyphs">COLOR</text><text class="cp-w" x="388" y="92" font-size="112" fill="currentColor" opacity="0.92" textLength="292" lengthAdjust="spacingAndGlyphs">PACK</text><circle cx="272" cy="118" r="11" fill="#3B8FE0"/><circle cx="310" cy="118" r="11" fill="#6CC04A"/><circle cx="348" cy="118" r="11" fill="#F7941D"/><circle cx="386" cy="118" r="11" fill="#EF2B2B"/><text class="cp-t" x="2" y="164" font-size="32" fill="currentColor" opacity="0.75" textLength="676" lengthAdjust="spacing">CREATIONS</text></svg>'; }
function read_config() {
    if (!file_exists(CFG_FILE)) return null;
    $src = file_get_contents(CFG_FILE); $cfg = [];
    if (preg_match_all('/public\s+\$(\w+)\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;]+);/s', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $v = trim($x[2]); if ($v !== '' && ($v[0] === "'" || $v[0] === '"')) $v = stripcslashes(substr($v, 1, -1)); $cfg[$x[1]] = $v; }
    }
    return $cfg;
}
function joomla_version() {
    foreach ([ROOT . '/libraries/src/Version.php', ROOT . '/libraries/cms/version/version.php'] as $f) {
        if (!is_file($f)) continue; $s = file_get_contents($f);
        if (preg_match('/MAJOR_VERSION\s*=\s*(\d+).*?MINOR_VERSION\s*=\s*(\d+).*?PATCH_VERSION\s*=\s*(\d+)/s', $s, $m)) return "$m[1].$m[2].$m[3]";
        if (preg_match('/\$RELEASE\s*=\s*\'([\d.]+)\'.*?\$DEV_LEVEL\s*=\s*\'(\d+)\'/s', $s, $m)) return "$m[1].$m[2]";
    }
    return '';
}
function db_connect($host, $user, $pass, $db, &$err = '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $port = 3306; $socket = null;
    if (strpos($host, ':') !== false) { list($host, $p) = explode(':', $host, 2); if (is_numeric($p)) $port = (int)$p; else $socket = $p; }
    $l = @mysqli_init(); @mysqli_options($l, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (!@mysqli_real_connect($l, $host, $user, $pass, $db, $port, $socket)) { $err = '[' . mysqli_connect_errno() . '] ' . mysqli_connect_error(); return null; }
    @mysqli_set_charset($l, 'utf8mb4'); return $l;
}
function pkg_dir($name) { return ROOT . '/jbackup-' . preg_replace('/[^A-Za-z0-9_.-]/', '', $name); }
function load_state($dir) { $s = @json_decode(@file_get_contents("$dir/state.json"), true); return is_array($s) ? $s : null; }
function save_state($dir, $s) { file_put_contents("$dir/state.json", json_encode($s, JSON_UNESCAPED_UNICODE)); }
function list_packages() {
    $out = [];
    foreach (glob(ROOT . '/jbackup-*', GLOB_ONLYDIR) ?: [] as $d) {
        $st = load_state($d); $size = 0; $files = [];
        foreach (glob("$d/*") ?: [] as $f) { if (is_file($f) && !in_array(basename($f), ['state.json', 'state.lock', 'files.list', 'cdr.bin'])) { $size += filesize($f); $files[] = basename($f); } }
        sort($files);
        $out[] = ['name' => substr(basename($d), 8), 'dir' => basename($d), 'size' => $size, 'files' => $files, 'done' => $st ? !empty($st['done']) : false, 'phase' => $st ? $st['phase'] : '', 'started' => $st ? $st['started'] : filemtime($d)];
    }
    usort($out, function ($a, $b) { return $b['started'] - $a['started']; });
    return $out;
}

// ---------------------------------------------------------------- ZIP writer (streaming, append-only, one standalone archive per part)
function dos_time($ts) { $d = getdate($ts); if ($d['year'] < 1980) { $d['year'] = 1980; $d['mon'] = 1; $d['mday'] = 1; $d['hours'] = $d['minutes'] = $d['seconds'] = 0; }
    return [(($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1)), ((($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'])]; }
function jz_add($zipPath, $cdrPath, $rel, $abs, $isDir, &$state) {
    $zp = fopen($zipPath, 'c+b'); if (!$zp) return 'open';
    fseek($zp, 0, SEEK_END);
    $cd = fopen($cdrPath, 'ab');
    $name = $isDir ? rtrim($rel, '/') . '/' : $rel;
    $nameB = $name; $size = $isDir ? 0 : filesize($abs); $mt = @filemtime($abs) ?: time();
    list($dt, $dd) = dos_time($mt);
    $method = (!$isDir && $size > 0 && $size < STORE_OVER) ? 8 : 0;
    $offset = ftell($zp);
    // local header (crc/sizes patched after data)
    fwrite($zp, pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dt, $dd, 0, 0, 0, strlen($nameB), 0) . $nameB);
    $crc = 0; $csize = 0; $usize = 0;
    if (!$isDir && $size > 0) {
        $in = fopen($abs, 'rb'); if (!$in) { fclose($zp); fclose($cd); return 'read'; }
        $hc = hash_init('crc32b');
        if ($method === 8) {
            $ctx = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
            while (!feof($in)) { $buf = fread($in, 1048576); if ($buf === '' || $buf === false) break; hash_update($hc, $buf); $usize += strlen($buf); $out = deflate_add($ctx, $buf, ZLIB_NO_FLUSH); if ($out !== '') { fwrite($zp, $out); $csize += strlen($out); } }
            $out = deflate_add($ctx, '', ZLIB_FINISH); fwrite($zp, $out); $csize += strlen($out);
        } else {
            while (!feof($in)) { $buf = fread($in, 4194304); if ($buf === '' || $buf === false) break; hash_update($hc, $buf); $usize += strlen($buf); fwrite($zp, $buf); $csize += strlen($buf); }
        }
        fclose($in);
        $crc = hexdec(hash_final($hc));
    }
    $end = ftell($zp);
    fseek($zp, $offset + 14); fwrite($zp, pack('VVV', $crc, $csize, $usize)); fseek($zp, $end); fclose($zp);
    $ext = $isDir ? 0x10 : 0; $perm = $isDir ? 040755 : 0100644; $extAttr = ($perm << 16) | $ext;
    fwrite($cd, pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031E, 20, 0x0800, $method, $dt, $dd, $crc, $csize, $usize, strlen($nameB), 0, 0, 0, 0, $extAttr, $offset) . $nameB);
    fclose($cd);
    $state['part_entries']++; $state['part_bytes'] = $end; $state['files_done'] += $isDir ? 0 : 1; $state['bytes_done'] += $usize;
    return '';
}
function jz_close($zipPath, $cdrPath, $entries) {
    $zp = fopen($zipPath, 'c+b'); fseek($zp, 0, SEEK_END); $cdOff = ftell($zp);
    $cd = file_get_contents($cdrPath); fwrite($zp, $cd);
    fwrite($zp, pack('VvvvvVVv', 0x06054b50, 0, 0, $entries, $entries, strlen($cd), $cdOff, 0));
    fclose($zp); @unlink($cdrPath);
}

// ---------------------------------------------------------------- exclusions
function excluded($rel, $opts) {
    foreach ($opts['excludes'] as $ex) { if ($ex === '') continue; if ($rel === $ex || strpos($rel, $ex . '/') === 0) return true; }
    if (strpos($rel, 'jbackup-') === 0 && strpos($rel, '/') === false) return true;
    if ($rel === 'jbackup.php' || $rel === 'jmigrate.php' || $rel === 'jmigrate-state.json' || strpos($rel, 'configuration.php.bak-') === 0) return true;
    if (!empty($opts['skip_old']) && strpos($rel, '/') === false && preg_match('/\.(zip|gz|tar|tgz|sql|rar|7z|jpa)$/i', $rel)) return true;
    return false;
}

// ---------------------------------------------------------------- phases
function phase_db($dir, &$st, $t0) {
    $o = $st['opts']; $err = '';
    $link = db_connect($o['host'], $o['user'], $o['pass'], $o['db'], $err);
    if (!$link) return 'เชื่อมต่อฐานข้อมูลไม่ได้: ' . $err;
    mysqli_query($link, 'SET SESSION sql_mode = ""');
    $gz = gzopen("$dir/db.sql.gz", $st['db']['started'] ? 'ab6' : 'wb6');
    if (!$st['db']['started']) {
        $st['db']['started'] = true;
        $r = mysqli_query($link, "SHOW FULL TABLES"); $tables = []; $views = [];
        while ($r && ($x = mysqli_fetch_row($r))) { if ($x[1] === 'VIEW') $views[] = $x[0]; else $tables[] = $x[0]; }
        $st['db']['tables'] = $tables; $st['db']['views'] = $views; $st['db']['ti'] = 0; $st['db']['offset'] = 0; $st['db']['rows'] = 0;
        gzwrite($gz, "-- jbackup " . TOOL_VERSION . " dump\n-- Host: {$o['host']}  Database: {$o['db']}\n-- Date: " . date('c') . "\n\n/*!40101 SET NAMES utf8mb4 */;\n/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n");
    }
    $skip = array_filter(array_map('trim', explode(',', $o['skip'])));
    while ($st['db']['ti'] < count($st['db']['tables'])) {
        $t = $st['db']['tables'][$st['db']['ti']]; $tq = '`' . str_replace('`', '``', $t) . '`';
        if ($st['db']['offset'] === 0) {
            $r = mysqli_query($link, "SHOW CREATE TABLE $tq"); $x = $r ? mysqli_fetch_row($r) : null;
            if (!$x) { $st['warnings'][] = "SHOW CREATE TABLE $t: " . mysqli_error($link); $st['db']['ti']++; continue; }
            gzwrite($gz, "--\n-- Table structure for table `$t`\n--\n\nDROP TABLE IF EXISTS $tq;\n" . $x[1] . ";\n\n");
            $doSkip = false; foreach ($skip as $p) { if ($p !== '' && stripos($t, $p) !== false) { $doSkip = true; break; } }
            if ($doSkip) { $st['db']['skipped'][] = $t; $st['db']['ti']++; continue; }
            // primary key for stable paging
            $pk = []; $r = mysqli_query($link, "SHOW KEYS FROM $tq WHERE Key_name = 'PRIMARY'"); while ($r && ($k = mysqli_fetch_assoc($r))) $pk[(int)$k['Seq_in_index']] = $k['Column_name'];
            ksort($pk); $st['db']['order'] = $pk ? ' ORDER BY ' . implode(',', array_map(function ($c) { return '`' . $c . '`'; }, $pk)) : '';
            // keyset paging เมื่อ PK เป็นคอลัมน์เดียว (เร็วกว่า OFFSET มากกับตารางใหญ่)
            $st['db']['keycol'] = count($pk) === 1 ? reset($pk) : ''; $st['db']['lastkey'] = null;
            gzwrite($gz, "--\n-- Dumping data for table `$t`\n--\n\n");
        }
        while (true) {
            if (microtime(true) - $t0 > STEP_SECONDS) { gzclose($gz); mysqli_close($link); return ''; }
            if (!empty($st['db']['keycol'])) {
                $kc = '`' . $st['db']['keycol'] . '`'; $where = $st['db']['lastkey'] === null ? '' : " WHERE $kc > '" . mysqli_real_escape_string($link, $st['db']['lastkey']) . "'";
                $r = mysqli_query($link, "SELECT * FROM $tq$where ORDER BY $kc LIMIT " . ROWS_PER_Q);
            } else {
                $r = mysqli_query($link, "SELECT * FROM $tq{$st['db']['order']} LIMIT " . ROWS_PER_Q . " OFFSET {$st['db']['offset']}");
            }
            if (!$r) { $st['warnings'][] = "SELECT $t: " . mysqli_error($link); break; }
            if (mysqli_num_rows($r) === 0) { mysqli_free_result($r); break; }
            $fields = mysqli_fetch_fields($r); $bin = []; foreach ($fields as $i => $f) $bin[$i] = ($f->flags & MYSQLI_BINARY_FLAG) && in_array($f->type, [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_GEOMETRY]);
            $cols = '(' . implode(',', array_map(function ($f) { return '`' . $f->name . '`'; }, $fields)) . ')';
            $vals = []; $len = 0; $n = 0; $keyIdx = -1;
            if (!empty($st['db']['keycol'])) foreach ($fields as $i => $f) if ($f->name === $st['db']['keycol']) $keyIdx = $i;
            while ($row = mysqli_fetch_row($r)) {
                if ($keyIdx >= 0) $st['db']['lastkey'] = $row[$keyIdx];
                $parts = [];
                foreach ($row as $i => $v) {
                    if ($v === null) $parts[] = 'NULL';
                    elseif ($bin[$i]) $parts[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    elseif (is_int($v) || is_float($v)) $parts[] = (string)$v;
                    elseif (preg_match('/^-?(0|[1-9]\d{0,17})(\.\d+)?$/', $v) && $fields[$i]->type !== MYSQLI_TYPE_STRING && $fields[$i]->type !== MYSQLI_TYPE_VAR_STRING && $fields[$i]->type !== MYSQLI_TYPE_BLOB) $parts[] = $v;
                    else $parts[] = "'" . mysqli_real_escape_string($link, $v) . "'";
                }
                $line = '(' . implode(',', $parts) . ')'; $vals[] = $line; $len += strlen($line); $n++;
                if ($len > 900000) { gzwrite($gz, "INSERT INTO $tq $cols VALUES\n" . implode(",\n", $vals) . ";\n"); $vals = []; $len = 0; }
            }
            if ($vals) gzwrite($gz, "INSERT INTO $tq $cols VALUES\n" . implode(",\n", $vals) . ";\n");
            mysqli_free_result($r);
            $st['db']['offset'] += $n; $st['db']['rows'] += $n;
            if ($n < ROWS_PER_Q) break;
        }
        gzwrite($gz, "\n"); $st['db']['ti']++; $st['db']['offset'] = 0;
    }
    foreach ($st['db']['views'] as $v) {
        $vq = '`' . str_replace('`', '``', $v) . '`'; $r = mysqli_query($link, "SHOW CREATE TABLE $vq"); $x = $r ? mysqli_fetch_row($r) : null;
        if ($x) gzwrite($gz, "--\n-- View `$v`\n--\n\nDROP VIEW IF EXISTS $vq;\n" . preg_replace('/\bDEFINER\s*=\s*\S+\s*/i', '', $x[1]) . ";\n\n");
    }
    gzwrite($gz, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n-- Dump completed on " . date('c') . "\n");
    gzclose($gz); mysqli_close($link);
    $st['db']['done'] = true; $st['phase'] = !empty($st['opts']['files']) ? 'scan' : 'checksum';
    return '';
}
function phase_scan($dir, &$st, $t0) {
    $o = $st['opts']; $lf = fopen("$dir/files.list", 'ab');
    if (!isset($st['scan']['stack'])) { $st['scan'] = ['stack' => [''], 'files' => 0, 'bytes' => 0, 'dirs' => 0]; }
    while ($st['scan']['stack']) {
        if (microtime(true) - $t0 > STEP_SECONDS) { fclose($lf); return ''; }
        $rel = array_pop($st['scan']['stack']); $abs = $rel === '' ? ROOT : ROOT . '/' . $rel;
        $dh = @opendir($abs); if (!$dh) { $st['warnings'][] = 'อ่านไฟล์ไม่ได้: ' . $rel; continue; }
        $subdirs = []; $empty = true;
        while (($e = readdir($dh)) !== false) {
            if ($e === '.' || $e === '..') continue;
            $r = $rel === '' ? $e : "$rel/$e"; $a = "$abs/$e";
            if (excluded($r, $o)) continue;
            if (is_link($a)) continue;
            if (is_dir($a)) { $subdirs[] = $r; $empty = false; continue; }
            if (!is_file($a) || !is_readable($a)) { $st['warnings'][] = 'อ่านไฟล์ไม่ได้: ' . $r; continue; }
            $sz = filesize($a);
            if ($sz >= 4294967295) { $st['warnings'][] = 'ข้ามไฟล์ใหญ่กว่า 4 GB: ' . $r; continue; }
            fwrite($lf, "F\t$sz\t$r\n"); $st['scan']['files']++; $st['scan']['bytes'] += $sz; $empty = false;
        }
        closedir($dh);
        if ($empty && $rel !== '') { fwrite($lf, "D\t0\t$rel\n"); }
        $st['scan']['dirs']++;
        rsort($subdirs); foreach ($subdirs as $sd) $st['scan']['stack'][] = $sd;
    }
    fclose($lf);
    $st['phase'] = 'zip'; $st['zip'] = ['pos' => 0, 'part' => 0, 'parts' => []]; $st['part_entries'] = 0; $st['part_bytes'] = 0; $st['files_done'] = 0; $st['bytes_done'] = 0;
    return '';
}
function phase_zip($dir, &$st, $t0) {
    $lf = fopen("$dir/files.list", 'rb'); if (!$lf) { $st['phase'] = 'checksum'; return ''; }
    fseek($lf, $st['zip']['pos']);
    $partSize = max(50 * 1048576, (int)$st['opts']['part_mb'] * 1048576);
    while (true) {
        if (microtime(true) - $t0 > STEP_SECONDS) break;
        $line = fgets($lf); if ($line === false) break;
        $line = rtrim($line, "\n"); if ($line === '') { $st['zip']['pos'] = ftell($lf); continue; }
        list($type, $sz, $rel) = explode("\t", $line, 3);
        // open a new part when needed
        if ($st['zip']['part'] === 0 || $st['part_bytes'] >= $partSize || $st['part_entries'] >= MAX_ENTRIES) {
            if ($st['zip']['part'] > 0) { jz_close("$dir/site-part{$st['zip']['part']}.zip", "$dir/cdr.bin", $st['part_entries']); $st['zip']['parts'][] = ['file' => "site-part{$st['zip']['part']}.zip", 'entries' => $st['part_entries']]; }
            $st['zip']['part']++; $st['part_entries'] = 0; $st['part_bytes'] = 0; @unlink("$dir/cdr.bin"); @unlink("$dir/site-part{$st['zip']['part']}.zip");
        }
        $e = jz_add("$dir/site-part{$st['zip']['part']}.zip", "$dir/cdr.bin", $rel, ROOT . '/' . $rel, $type === 'D', $st);
        if ($e !== '') $st['warnings'][] = 'อ่านไฟล์ไม่ได้: ' . $rel;
        $st['zip']['pos'] = ftell($lf);
    }
    $eof = feof($lf) || fgets($lf) === false; fclose($lf);
    if ($eof) {
        if ($st['zip']['part'] > 0) { jz_close("$dir/site-part{$st['zip']['part']}.zip", "$dir/cdr.bin", $st['part_entries']); $st['zip']['parts'][] = ['file' => "site-part{$st['zip']['part']}.zip", 'entries' => $st['part_entries']]; }
        @unlink("$dir/files.list"); $st['phase'] = 'checksum';
    }
    return '';
}
function phase_checksum($dir, &$st, $t0) {
    $files = []; if (!empty($st['opts']['database'])) $files[] = 'db.sql.gz';
    foreach ($st['zip']['parts'] ?? [] as $p) $files[] = $p['file'];
    if (!isset($st['sums'])) $st['sums'] = [];
    foreach ($files as $f) {
        if (isset($st['sums'][$f])) continue;
        if (microtime(true) - $t0 > STEP_SECONDS) return '';
        $st['sums'][$f] = ['md5' => md5_file("$dir/$f"), 'size' => filesize("$dir/$f")];
    }
    $cfg = read_config();
    $man = ['tool' => 'jbackup', 'version' => TOOL_VERSION, 'created' => date('c'), 'name' => $st['name'], 'site' => ['root' => ROOT, 'host' => $_SERVER['HTTP_HOST'], 'joomla' => joomla_version(), 'dbprefix' => $cfg['dbprefix'] ?? '', 'db' => $st['opts']['db'], 'live_site' => $cfg['live_site'] ?? ''],
        'database' => !empty($st['opts']['database']) ? ['file' => 'db.sql.gz', 'tables' => count($st['db']['tables'] ?? []), 'rows' => $st['db']['rows'] ?? 0, 'skipped' => $st['db']['skipped'] ?? []] : null,
        'files' => !empty($st['opts']['files']) ? ['parts' => array_column($st['zip']['parts'] ?? [], 'file'), 'count' => $st['files_done'] ?? 0, 'bytes' => $st['bytes_done'] ?? 0] : null,
        'checksums' => $st['sums'], 'warnings' => $st['warnings']];
    file_put_contents("$dir/manifest.json", json_encode($man, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $st['phase'] = 'done'; $st['done'] = true; $st['finished'] = time();
    return '';
}

// ---------------------------------------------------------------- actions
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
if ($action === 'download' || $action === 'manifest') {
    $pkg = basename((string)$_GET['pkg']); $dir = ROOT . '/' . $pkg;
    if (strpos($pkg, 'jbackup-') !== 0 || !is_dir($dir)) { header('HTTP/1.1 404 Not Found'); exit; }
    $f = $action === 'manifest' ? 'manifest.json' : basename((string)$_GET['f']);
    $path = "$dir/$f";
    if (!is_file($path) || in_array($f, ['state.json', 'state.lock', 'files.list', 'cdr.bin'])) { header('HTTP/1.1 404 Not Found'); exit; }
    $size = filesize($path); $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); header('HTTP/1.1 206 Partial Content'); header("Content-Range: bytes $start-$end/$size"); }
    header('Content-Type: ' . ($f === 'manifest.json' ? 'application/json' : 'application/octet-stream')); header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1)); if ($action === 'download') header('Content-Disposition: attachment; filename="' . $f . '"');
    header('X-File-Size: ' . $size);
    $fp = fopen($path, 'rb'); fseek($fp, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(1048576, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
    fclose($fp); exit;
}
if ($action !== '') {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if ($action === 'list') json_out(['ok' => true, 'packages' => list_packages()]);
    if ($action === 'delete') {
        $dir = pkg_dir($in['name']); if (!is_dir($dir)) json_out(['ok' => false, 'msg' => 'not found']);
        foreach (glob("$dir/*") ?: [] as $f) @unlink($f); @rmdir($dir); json_out(['ok' => true, 'msg' => t('ลบแล้ว')]);
    }
    if ($action === 'selfdestruct') { $ok = @unlink(__FILE__); json_out(['ok' => $ok, 'msg' => $ok ? t('ลบแล้ว') : 'cannot delete']); }
    if ($action === 'start') {
        if (empty($in['database']) && empty($in['files'])) json_out(['ok' => false, 'msg' => t('ไม่มีอะไรให้สำรอง (ปิดทั้งฐานข้อมูลและไฟล์)')]);
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$in['name']) ?: date('Ymd-His');
        $dir = pkg_dir($name);
        if (!is_dir($dir) && !@mkdir($dir, 0755)) json_out(['ok' => false, 'msg' => t('สร้างโฟลเดอร์แพ็กเกจไม่ได้ ตรวจ permission ของ root')]);
        @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n"); @file_put_contents("$dir/index.html", '');
        foreach (['db.sql.gz', 'files.list', 'cdr.bin', 'manifest.json', 'state.json'] as $f) @unlink("$dir/$f"); foreach (glob("$dir/site-part*.zip") ?: [] as $f) @unlink($f);
        if (!empty($in['database'])) { $e = ''; $l = db_connect($in['host'], $in['user'], $in['pass'], $in['db'], $e); if (!$l) json_out(['ok' => false, 'msg' => t('เชื่อมต่อฐานข้อมูลไม่ได้: ') . $e]); mysqli_close($l); }
        $ex = array_values(array_filter(array_map(function ($x) { return trim(trim($x), '/'); }, explode(',', (string)$in['excludes']))));
        $st = ['name' => $name, 'started' => time(), 'phase' => !empty($in['database']) ? 'db' : 'scan', 'done' => false, 'warnings' => [],
            'opts' => ['host' => $in['host'], 'user' => $in['user'], 'pass' => $in['pass'], 'db' => $in['db'], 'skip' => (string)$in['skip'], 'excludes' => $ex, 'skip_old' => !empty($in['skip_old']), 'database' => !empty($in['database']), 'files' => !empty($in['files']), 'part_mb' => (int)$in['part_mb'] ?: 500],
            'db' => ['started' => false, 'done' => false, 'tables' => [], 'ti' => 0, 'offset' => 0, 'rows' => 0, 'skipped' => []], 'scan' => [], 'zip' => ['pos' => 0, 'part' => 0, 'parts' => []], 'files_done' => 0, 'bytes_done' => 0, 'part_entries' => 0, 'part_bytes' => 0];
        save_state($dir, $st); json_out(['ok' => true, 'state' => $st]);
    }
    if ($action === 'step') {
        $dir = pkg_dir($in['name']); $st = load_state($dir); if (!$st) json_out(['ok' => false, 'msg' => 'no state']);
        if ($st['done']) json_out(['ok' => true, 'state' => $st]);
        $lock = fopen("$dir/state.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) json_out(['ok' => true, 'busy' => true, 'state' => $st]);
        $st = load_state($dir); $t0 = microtime(true); $err = '';
        switch ($st['phase']) {
            case 'db': $err = phase_db($dir, $st, $t0); break;
            case 'scan': $err = phase_scan($dir, $st, $t0); break;
            case 'zip': $err = phase_zip($dir, $st, $t0); break;
            case 'checksum': $err = phase_checksum($dir, $st, $t0); break;
        }
        if (count($st['warnings']) > 300) $st['warnings'] = array_slice($st['warnings'], -300);
        save_state($dir, $st); flock($lock, LOCK_UN); fclose($lock);
        if ($err !== '') json_out(['ok' => false, 'msg' => t($err), 'state' => $st]);
        json_out(['ok' => true, 'state' => $st, 'elapsed' => round(microtime(true) - $t0, 1)]);
    }
    json_out(['ok' => false, 'msg' => 'unknown action']);
}

// ---------------------------------------------------------------- page
$cfg = read_config(); $jv = joomla_version(); $free = @disk_free_space(ROOT) ?: 0;
$pkgs = list_packages();
$selfUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
?>
<!DOCTYPE html>
<html lang="<?= $LANG ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Joomla Backup Tool</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{--red:#ef2b2c;--orange:#f7941c;--green:#6cc04a;--blue:#3b8fe0;--sky:#2ba7df;--gray:#666666;--ink:#111111;--paper:#ffffff;--ground:#f4f6f8;--line:#e1e6eb;
    --red-tint:#fde8e8;--orange-tint:#fef0dc;--green-tint:#e8f5e1;--blue-tint:#e3effb;--red-deep:#b81b1c;--orange-deep:#9a5300;--green-deep:#2f7a1a;--blue-deep:#1f5fa8;--blue-hover:#2f7ac4;
    --terminal:#1b1f24;--terminal-text:#d6dee6;--shadow-card:0 1px 2px rgba(17,17,17,.05),0 10px 30px rgba(17,17,17,.06);--shadow-focus:0 0 0 4px rgba(59,143,224,.28);
    --font-body:"Kanit","Noto Sans Thai",system-ui,sans-serif;--font-mono:"JetBrains Mono","IBM Plex Mono",ui-monospace,Menlo,monospace;--radius-sm:10px;--radius-md:14px;--radius-lg:20px}
  @media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--gray:#a0a6ad;--ink:#f2f4f6;--paper:#1b1f24;--ground:#121417;--line:#2a3038;--red-tint:#3a1a1a;--orange-tint:#3a2a12;--green-tint:#1a3315;--blue-tint:#162a40;--red-deep:#ff8a8a;--orange-deep:#ffc27a;--green-deep:#9be07f;--blue-deep:#8fc3ff;--blue-hover:#5aa3ee;--terminal:#0c0e11;--terminal-text:#c9d2db;--shadow-card:0 1px 2px rgba(0,0,0,.4),0 10px 30px rgba(0,0,0,.35);color-scheme:dark}}
  *{box-sizing:border-box} body{margin:0;background:var(--ground);color:var(--ink);font-family:var(--font-body);font-size:17px;line-height:1.6;-webkit-font-smoothing:antialiased} a{color:var(--blue-deep)}
  .topbar{background:var(--ink);color:var(--paper)} .topbar .in{max-width:1080px;margin:0 auto;padding:20px 24px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 28px}
  .brand{display:flex;align-items:center;gap:16px} .brand .t{font-size:24px;font-weight:600;line-height:1.2}.brand .s{font-size:15px;color:#a0a6ad}
  .wrap{max-width:1080px;margin:0 auto;padding:28px 24px 8px;display:flex;flex-direction:column;gap:24px}
  .card{background:var(--paper);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);padding:28px 32px;margin:0} @media(max-width:640px){.card{padding:20px 16px}.wrap{padding:20px 16px 8px}}
  h2{margin:0 0 18px;font-size:24px;font-weight:600;line-height:1.3;display:flex;align-items:center;gap:14px;text-wrap:balance}
  h2::before{content:attr(data-n);width:40px;height:40px;border-radius:12px;background:var(--blue);color:#fff;font-size:18px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;flex:none}
  h2[data-n="2"]::before{background:var(--green);color:#111} h2[data-n="3"]::before{background:var(--orange);color:#111} h2[data-n="4"]::before{background:var(--red)}
  label{display:block;font-size:15px;font-weight:500;color:var(--gray);margin:16px 0 8px;line-height:1.35}
  input[type=text],input[type=password],input[type=number],select,textarea{width:100%;min-height:48px;padding:10px 14px;border:2px solid var(--line);border-radius:var(--radius-sm);font:17px/24px var(--font-body);background:var(--paper);color:var(--ink)}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--blue);box-shadow:var(--shadow-focus)}
  .row{display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:0 24px} @media(max-width:640px){.row{grid-template-columns:1fr}}
  button{min-height:48px;padding:0 22px;border:2px solid transparent;border-radius:var(--radius-md);font:500 17px/24px var(--font-body);cursor:pointer;background:var(--blue);color:#fff;margin:14px 10px 0 0;display:inline-flex;align-items:center;gap:8px}
  button:hover{background:var(--blue-hover)} button.sec{background:var(--paper);color:var(--ink);border-color:var(--line)}button.sec:hover{background:var(--paper);border-color:var(--ink)}
  button.danger{background:var(--paper);color:var(--red-deep);border-color:var(--red)}button.danger:hover{background:var(--red-tint)} button:disabled{opacity:.45;cursor:default}
  .ok{color:var(--green-deep);font-weight:500}.bad{color:var(--red-deep);font-weight:500}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:15px;font-weight:500;line-height:20px;background:var(--ground);color:var(--gray)}
  .warn{background:var(--orange-tint);color:var(--orange-deep);border-radius:var(--radius-md);padding:16px 18px;font-size:17px;line-height:1.55}
  .info{background:var(--blue-tint);color:var(--blue-deep);border-radius:var(--radius-md);padding:16px 18px;font-size:17px;line-height:1.55;margin-top:14px}
  .mono,code{font-family:var(--font-mono);font-size:15px} code{background:var(--ground);border:1px solid var(--line);padding:1px 7px;border-radius:6px}
  pre{background:var(--terminal);color:var(--terminal-text);border:0;border-radius:var(--radius-md);padding:16px 18px;font-family:var(--font-mono);font-size:15px;line-height:1.6;white-space:pre-wrap;word-break:break-all;max-height:320px;overflow:auto;margin:14px 0 0}
  .bar{height:14px;background:var(--line);border-radius:999px;overflow:hidden;margin:18px 0 10px} .bar i{display:block;height:100%;width:0;background:var(--blue);border-radius:999px;transition:width .3s}
  .stat{font-size:15px;color:var(--gray);line-height:1.6}
  table{border-collapse:collapse;width:100%;font-size:17px}td,th{padding:12px;border-bottom:1px solid var(--line);vertical-align:top;word-break:break-all;line-height:1.5;text-align:left}td:first-child{color:var(--gray);width:200px;word-break:normal} th{font-size:15px;font-weight:500;color:var(--gray)}
  .chk{display:flex;align-items:flex-start;gap:12px;font-size:17px;line-height:1.55;margin-top:14px} .chk input{width:22px;height:22px;margin:2px 0 0;accent-color:var(--blue);flex:none}.chk label{margin:0;font-size:17px;color:var(--ink);font-weight:400}
  .cp-footer{max-width:1080px;margin:16px auto 0;padding:28px 24px 36px;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:20px 32px;border-top:1px solid var(--line);font-size:15px;line-height:1.6;color:var(--gray)}
  .cp-w{font-family:"Kanit","Nunito",system-ui,sans-serif;font-weight:700}.cp-t{font-family:"Kanit","Nunito",system-ui,sans-serif;font-weight:300}
  .cp-footer .mark{display:flex;align-items:center;gap:16px;color:var(--ink)} .cp-footer .co{font-weight:600;color:var(--ink);font-size:17px}.cp-footer .co-en{font-size:13px;letter-spacing:.08em}
  .cp-footer .cr{text-align:right;line-height:1.7} @media(max-width:720px){.cp-footer .cr{text-align:left}td:first-child{width:130px}}
  .bmc{display:inline-flex;align-items:center;gap:8px;margin-top:8px;padding:8px 16px;border-radius:999px;background:var(--orange);color:#111;font-weight:500;text-decoration:none;font-size:15px}.bmc:hover{filter:brightness(.95);color:#111}
  .pk{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px 20px;padding:16px 0;border-bottom:1px solid var(--line)} .pk:last-child{border-bottom:0}
  .pk .files a{display:inline-block;margin:4px 8px 0 0}
</style>
</head>
<body>
<header class="topbar"><div class="in">
  <div class="brand">
    <a href="https://colorpack.co.th/" target="_blank" rel="noopener" style="color:#fff;display:block;line-height:0;text-decoration:none"><?= cp_logo(48) ?></a>
    <div style="width:2px;height:40px;background:rgba(255,255,255,.2);border-radius:2px"></div>
    <div><div class="t">Joomla Backup Tool</div><div class="s"><?= t('สร้างแพ็กเกจย้ายเว็บจาก server เดิม ·') ?> <?= h($_SERVER['HTTP_HOST']) ?></div></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="pill" style="background:var(--blue);color:#fff">v<?= TOOL_VERSION ?></span>
    <span class="pill" style="background:rgba(255,255,255,.12);color:#fff;gap:8px"><a href="?lang=th" style="color:<?= $LANG === 'th' ? '#fff' : '#a0a6ad' ?>;text-decoration:none;font-weight:<?= $LANG === 'th' ? '600' : '400' ?>">ไทย</a><span style="color:#5B6572">|</span><a href="?lang=en" style="color:<?= $LANG === 'en' ? '#fff' : '#a0a6ad' ?>;text-decoration:none;font-weight:<?= $LANG === 'en' ? '600' : '400' ?>">EN</a></span>
    <span class="pill mono" style="background:rgba(255,255,255,.12);color:#fff"><?= h(ROOT) ?></span>
    <a href="?logout=1" class="pill" style="background:rgba(255,255,255,.12);color:#fff;text-decoration:none"><?= t('ออกจากระบบ') ?></a>
  </div>
</div></header>
<div class="wrap">
<?php if (MIGRATE_KEY === 'changeme123'): ?><div class="warn"><?= t('ยังใช้รหัส key ค่าเริ่มต้นอยู่ ควรแก้ค่า') ?> <code>MIGRATE_KEY</code> <?= t('ในไฟล์ก่อนใช้งานจริง และใช้รหัสเดียวกับ jmigrate.php บน server ใหม่') ?></div><?php endif; ?>
<?php if (!$cfg): ?><div class="warn"><?= t('ไม่พบ configuration.php ในโฟลเดอร์นี้ ต้องวางไฟล์นี้ไว้ที่ root ของเว็บ') ?></div><?php endif; ?>

<div class="card">
  <h2 data-n="1"><?= t('เว็บที่จะสำรอง') ?></h2>
  <table>
    <tr><td><?= t('root เว็บ') ?></td><td class="mono"><?= h(ROOT) ?></td></tr>
    <tr><td><?= t('เวอร์ชัน Joomla') ?></td><td><?= $jv ? h($jv) : '<span class=bad>' . t('ไม่ทราบ') . '</span>' ?></td></tr>
    <tr><td><?= t('ฐานข้อมูล') ?></td><td><?= h(($cfg['db'] ?? '-') . ' @ ' . ($cfg['host'] ?? '-')) ?> · prefix <code><?= h($cfg['dbprefix'] ?? '-') ?></code></td></tr>
    <tr><td><?= t('PHP') ?></td><td><?= h(PHP_VERSION) ?> · mysqli <?= extension_loaded('mysqli') ? '<span class=ok>' . t('มี') . '</span>' : '<span class=bad>' . t('ไม่มี') . '</span>' ?> · zlib <?= function_exists('deflate_init') ? '<span class=ok>' . t('มี') . '</span>' : '<span class=bad>' . t('ไม่มี') . '</span>' ?></td></tr>
    <tr><td><?= t('พื้นที่ดิสก์ว่าง') ?></td><td><?= $free ? fmt_bytes($free) : t('ไม่ทราบ') ?></td></tr>
  </table>
</div>

<div class="card">
  <h2 data-n="2"><?= t('ตั้งค่าแพ็กเกจ') ?></h2>
  <div class="row">
    <div><label><?= t('ชื่อแพ็กเกจ') ?></label><input type="text" id="name" value="<?= h(preg_replace('/[^A-Za-z0-9_.-]/', '-', preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'])) . '-' . date('Ymd')) ?>"></div>
    <div><label><?= t('ขนาดต่อส่วน (MB)') ?></label><input type="number" id="part_mb" value="500" min="50" max="3900"></div>
    <div><label>Host</label><input type="text" id="host" value="<?= h($cfg['host'] ?? 'localhost') ?>"></div>
    <div><label>Database</label><input type="text" id="db" value="<?= h($cfg['db'] ?? '') ?>"></div>
    <div><label>User</label><input type="text" id="user" value="<?= h($cfg['user'] ?? '') ?>"></div>
    <div><label>Password</label><input type="password" id="pass" value="<?= h($cfg['password'] ?? '') ?>"></div>
  </div>
  <label><?= t('ข้ามข้อมูลของตารางที่ชื่อมีคำเหล่านี้ (สร้างตารางแต่ไม่ใส่ข้อมูล)') ?></label>
  <input type="text" id="skip" value="session, rsseo_error_links, rsseo_logs, rsseo_visitors, rsfirewall_logs, action_logs, _logs, flexicontent_items_versions">
  <label><?= t('โฟลเดอร์/ไฟล์ที่ไม่เอา (คั่นด้วย , เทียบจาก root)') ?></label>
  <input type="text" id="excludes" value="cache, administrator/cache, tmp, logs, administrator/logs, .git, .well-known/acme-challenge">
  <div class="chk"><input type="checkbox" id="skip_old" checked><label for="skip_old"><?= t('ข้ามไฟล์ backup เก่าที่ root (*.zip *.gz *.tar *.sql)') ?></label></div>
  <div class="chk"><input type="checkbox" id="database" checked><label for="database"><?= t('รวมฐานข้อมูล') ?></label></div>
  <div class="chk"><input type="checkbox" id="files" checked><label for="files"><?= t('รวมไฟล์เว็บ') ?></label></div>
  <button id="btnStart" onclick="startBackup()"><?= t('สร้างแพ็กเกจ') ?></button>
  <button class="sec" id="btnStop" onclick="stopBackup()" disabled><?= t('หยุดชั่วคราว') ?></button>
  <div class="bar"><i id="barfill"></i></div>
  <div class="stat" id="prog"><?= t('ยังไม่เริ่ม') ?></div>
  <pre id="log" style="display:none"></pre>
</div>

<div class="card">
  <h2 data-n="3"><?= t('แพ็กเกจที่มี') ?></h2>
  <div id="pkgs"></div>
  <div class="info"><b><?= t('วิธีนำไปใช้บน server ใหม่') ?></b><br><?= t('เปิด jmigrate.php บน server ใหม่ → ส่วน "กู้จากแพ็กเกจ" → วาง URL นี้ ระบบจะดึงไฟล์ทุกส่วนไปเองแบบแบ่งรอบ (ต้องใช้ key เดียวกัน)') ?><br><span class="mono" style="word-break:break-all"><?= h($selfUrl) ?></span><br><?= t('หรือดาวน์โหลดทุกไฟล์ในโฟลเดอร์แพ็กเกจแล้วอัปโหลดไปวางที่ root ของ server ใหม่ในโฟลเดอร์ชื่อเดียวกัน') ?></div>
</div>

<div class="card">
  <h2 data-n="4"><?= t('เสร็จแล้ว') ?></h2>
  <p class="stat" style="margin:0"><?= t('เสร็จแล้วให้ลบ jbackup.php และโฟลเดอร์แพ็กเกจออกจาก server นี้') ?></p>
  <button class="danger" onclick="if(confirm(t('ลบ jbackup.php ออกจาก server? (แพ็กเกจจะยังอยู่)')))api('selfdestruct',{}).then(()=>location.reload())"><?= t('ลบไฟล์เครื่องมือนี้') ?></button>
</div>
</div>

<footer class="cp-footer">
  <div class="mark"><?= cp_logo(40) ?><div><div class="co">บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด</div><div class="co-en">COLORPACK CREATION CO., LTD.</div></div></div>
  <div class="cr">
    <div>© <?= date('Y') ?> <?= t('บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด สงวนลิขสิทธิ์') ?></div>
    <div><a href="https://colorpack.co.th/" target="_blank" rel="noopener">colorpack.co.th</a> · Joomla Backup Tool v<?= TOOL_VERSION ?> · <a href="https://github.com/lungkao/Colorpack-Joomla-Migrate" target="_blank" rel="noopener">GitHub</a></div>
    <div><a class="bmc" href="https://buymeacoffee.com/cheuachatchai" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 8h1a4 4 0 1 1 0 8h-1"></path><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"></path><path d="M6 2v2"></path><path d="M10 2v2"></path><path d="M14 2v2"></path></svg>Buy me a coffee</a></div>
  </div>
</footer>

<script>
const KEY = <?= json_encode($key) ?>;
const T = <?= json_encode($LANG === 'en' ? $GLOBALS['JM_EN'] : new stdClass, JSON_UNESCAPED_UNICODE) ?>;
const t = s => (T[s] !== undefined ? T[s] : s);
const $ = id => document.getElementById(id);
const fmt = n => n >= 1073741824 ? (n/1073741824).toFixed(2)+' GB' : n >= 1048576 ? (n/1048576).toFixed(1)+' MB' : Math.round(n/1024)+' KB';
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(action, data) {
  const r = await fetch(location.pathname + '?action=' + action + (KEY ? '&key=' + encodeURIComponent(KEY) : ''), {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data || {})});
  const txt = await r.text(); try { return JSON.parse(txt); } catch (e) { return {ok:false, msg:'HTTP ' + r.status + ': ' + txt.slice(0, 200)}; }
}
let running = false, current = null;
function setUi(on) { running = on; $('btnStart').disabled = on; $('btnStop').disabled = !on; }
function stopBackup() { running = false; }
function logLine(s) { const l = $('log'); l.style.display = 'block'; l.textContent += s + '\n'; l.scrollTop = l.scrollHeight; }
function opts() { return {name:$('name').value, part_mb:$('part_mb').value, host:$('host').value, user:$('user').value, pass:$('pass').value, db:$('db').value, skip:$('skip').value, excludes:$('excludes').value, skip_old:$('skip_old').checked, database:$('database').checked, files:$('files').checked}; }
function render(s, el) {
  let pct = 0, txt = '';
  if (s.phase === 'db') { const n = s.db.tables.length || 1; pct = (s.db.ti / n) * 30; txt = t('กำลัง dump ฐานข้อมูล') + ' ' + s.db.ti + ' / ' + s.db.tables.length + ' ' + t('ตาราง') + ' · ' + s.db.rows.toLocaleString() + ' ' + t('แถว'); }
  else if (s.phase === 'scan') { pct = 32; txt = t('กำลังสำรวจไฟล์') + ' ' + (s.scan.files || 0).toLocaleString() + ' ' + t('ไฟล์') + ' · ' + fmt(s.scan.bytes || 0); }
  else if (s.phase === 'zip') { const tot = s.scan.bytes || 1; pct = 35 + (s.bytes_done / tot) * 60; txt = t('กำลังบีบอัดไฟล์') + ' ' + s.files_done.toLocaleString() + ' / ' + s.scan.files.toLocaleString() + ' ' + t('ไฟล์') + ' · ' + fmt(s.bytes_done) + ' / ' + fmt(tot) + ' · ' + s.zip.part + ' ' + t('ส่วน'); }
  else if (s.phase === 'checksum') { pct = 97; txt = t('กำลังคำนวณ checksum'); }
  else if (s.done) { pct = 100; txt = '<span class=ok>✔ ' + t('เสร็จแล้ว') + '</span> ' + (s.db.rows ? s.db.tables.length + ' ' + t('ตาราง') + ' · ' + s.db.rows.toLocaleString() + ' ' + t('แถว') + ' · ' : '') + (s.files_done ? s.files_done.toLocaleString() + ' ' + t('ไฟล์') + ' · ' + fmt(s.bytes_done) + ' · ' + s.zip.parts.length + ' ' + t('ส่วน') : ''); }
  $('barfill').style.width = pct.toFixed(1) + '%'; $('prog').innerHTML = txt + (el ? ' <span class=muted>(' + el + 's)</span>' : '');
  if (s.warnings && s.warnings.length) { const l = $('log'); l.style.display = 'block'; l.textContent = t('คำเตือน') + ':\n' + s.warnings.join('\n'); }
}
async function startBackup() {
  const r = await api('start', opts()); if (!r.ok) { $('prog').innerHTML = '<span class=bad>' + esc(r.msg) + '</span>'; return; }
  current = r.state.name; $('log').textContent = ''; $('log').style.display = 'none'; runLoop();
}
async function resumeBackup(name) { current = name; runLoop(); }
async function runLoop() {
  setUi(true); let fails = 0;
  while (running) {
    let r; try { r = await api('step', {name: current}); } catch (e) { r = {ok:false, msg:'network: ' + e}; }
    if (!r.ok) { if (r.state) render(r.state); if (++fails <= 3) { logLine(t('รอบนี้ล้มเหลว (') + r.msg + t(') ลองใหม่ครั้งที่ ') + fails); await new Promise(x => setTimeout(x, 3000)); continue; } $('prog').innerHTML = '<span class=bad>' + esc(r.msg) + '</span>'; break; }
    fails = 0;
    if (r.busy) { $('prog').innerHTML = t('server กำลังทำงานรอบก่อนหน้าอยู่ รอสักครู่...'); await new Promise(x => setTimeout(x, 3000)); continue; }
    render(r.state, r.elapsed);
    if (r.state.done) { loadPackages(); break; }
  }
  setUi(false);
}
async function loadPackages() {
  const r = await api('list', {}); const el = $('pkgs');
  if (!r.ok || !r.packages.length) { el.innerHTML = '<div class="stat">' + t('ไม่มีแพ็กเกจ') + '</div>'; return; }
  el.innerHTML = r.packages.map(p => '<div class="pk"><div><b>' + esc(p.name) + '</b> <span class="pill ' + (p.done ? '' : '') + '" style="' + (p.done ? 'background:var(--green-tint);color:var(--green-deep)' : 'background:var(--orange-tint);color:var(--orange-deep)') + '">' + (p.done ? t('เสร็จแล้ว') : esc(p.phase)) + '</span> <span class="stat">' + fmt(p.size) + '</span><div class="files">' + p.files.map(f => '<a href="?action=download&pkg=' + encodeURIComponent(p.dir) + '&f=' + encodeURIComponent(f) + '">' + esc(f) + '</a>').join('') + '</div><div class="stat mono" style="word-break:break-all;margin-top:6px">' + t('URL สำหรับดึงจาก server ใหม่') + ': ' + esc(location.origin + location.pathname) + '</div></div><div>' + (p.done ? '' : '<button class="sec" onclick="resumeBackup(' + JSON.stringify(p.name) + ')">' + t('ทำต่อจากจุดเดิม') + '</button>') + '<button class="danger" onclick="if(confirm(t(\'ลบแพ็กเกจนี้?\')))api(\'delete\',{name:' + JSON.stringify(p.name) + '}).then(loadPackages)">' + t('ลบ') + '</button></div></div>').join('');
}
loadPackages();
</script>
</body></html>
