<?php
/**
 * jmigrate.php — เครื่องมือย้ายเว็บ Joomla (3 / 4 / 5) ไป server ใหม่
 * © บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด (COLORPACK CREATION CO., LTD.) — https://colorpack.co.th/
 *
 * ความสามารถ
 *  1. ตรวจและแก้ path ใน configuration.php ให้ตรงกับ server ใหม่อัตโนมัติ (tmp_path, log_path)
 *  2. ฟอร์มกรอกข้อมูลฐานข้อมูล พร้อมปุ่มทดสอบการเชื่อมต่อ และหา prefix ตารางให้
 *  3. นำเข้าไฟล์ .sql หรือ .sql.gz ที่วางไว้ในโฟลเดอร์เดียวกัน แบบแบ่งรอบ (chunked)
 *     ทำงานทีละไม่กี่วินาทีต่อรอบ จึงไม่ติด timeout แม้ไฟล์จะใหญ่หลายร้อย MB
 *     หยุดแล้วทำต่อจากจุดเดิมได้ (resume)
 *  4. เครื่องมือเสริม: สร้างโฟลเดอร์ tmp/logs, กู้ .htaccess จาก htaccess.txt, ล้างตาราง session
 *
 * วิธีใช้
 *  - แก้ค่า MIGRATE_KEY ด้านล่างให้เป็นรหัสของคุณเอง
 *  - อัปโหลดไฟล์นี้ + ไฟล์ .sql/.sql.gz ไว้ที่ root ของเว็บ (ที่เดียวกับ configuration.php)
 *  - เปิด https://your-domain/jmigrate.php?key=รหัสของคุณ
 *  - ทำเสร็จแล้วกดปุ่ม "ลบไฟล์เครื่องมือนี้" ท้ายหน้า
 */

const MIGRATE_KEY   = 'changeme123';   // <<< แก้เป็นรหัสของคุณ
const STEP_SECONDS  = 8;               // เวลาทำงานต่อรอบ (วินาที) ต่ำกว่า timeout ของ server
const TOOL_VERSION  = '1.1.0';
const BMC_URL       = 'https://buymeacoffee.com/cheuachatchai'; // Buy me a coffee
const GZ_CHUNK      = 512 * 1024 * 1024; // เพดานขนาดที่แตก gz ต่อรอบ (bytes) ปกติจบที่เวลาก่อน

// ---------------------------------------------------------------- bootstrap
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);
@ini_set('memory_limit', '512M');
@set_time_limit(0);
@ignore_user_abort(true);
header('X-Robots-Tag: noindex, nofollow');

define('ROOT', __DIR__);
define('CFG_FILE', ROOT . '/configuration.php');
define('STATE_FILE', ROOT . '/jmigrate-state.json');

// ---------------------------------------------------------------- language (th / en)
$LANG = isset($_GET['lang']) ? $_GET['lang'] : (isset($_COOKIE['jm_lang']) ? $_COOKIE['jm_lang'] : 'th');
$LANG = $LANG === 'en' ? 'en' : 'th';
if (isset($_GET['lang'])) @setcookie('jm_lang', $LANG, time() + 86400 * 30, '/');
$GLOBALS['JM_LANG'] = $LANG;
function t($s) { return ($GLOBALS['JM_LANG'] === 'en' && isset($GLOBALS['JM_EN'][$s])) ? $GLOBALS['JM_EN'][$s] : $s; }
$GLOBALS['JM_EN'] = [
    'กู้จากแพ็กเกจ (jbackup)' => 'Restore from package (jbackup)',
    'แพ็กเกจคือโฟลเดอร์ jbackup-ชื่อ/ ที่สร้างด้วย jbackup.php บน server เดิม มี db.sql.gz, site-partN.zip และ manifest.json ดึงตรงจาก server เดิมผ่าน HTTPS หรืออัปโหลดโฟลเดอร์มาวางที่ root ของเว็บนี้ก็ได้' => 'A package is a jbackup-<name>/ folder made by jbackup.php on the old server: db.sql.gz, site-partN.zip and manifest.json. Pull it straight from the old server over HTTPS, or upload the folder to this site\'s root.',
    'URL ของ jbackup.php บน server เดิม (ใช้ MIGRATE_KEY เดียวกัน)' => 'URL of jbackup.php on the old server (same MIGRATE_KEY)',
    'ดูแพ็กเกจบน server เดิม' => 'List packages on the old server',
    'แพ็กเกจ' => 'Package',
    'อยู่บน server นี้แล้ว' => 'already on this server',
    'ยังไม่มีแพ็กเกจ — ใส่ URL ด้านบนแล้วกด "ดูแพ็กเกจบน server เดิม" หรืออัปโหลดโฟลเดอร์ jbackup-* มาวางแล้วรีเฟรช' => 'No package yet — enter the URL above and press "List packages on the old server", or upload a jbackup-* folder and refresh',
    'แตกไฟล์เว็บทับโฟลเดอร์นี้ (configuration.php เดิมจะถูกสำรองเป็น .bak-restore)' => 'Extract site files into this folder (the current configuration.php is backed up as .bak-restore)',
    'ดึงและแตกแพ็กเกจ' => 'Pull and extract package',
    'แทนที่โดเมน / path เดิมในฐานข้อมูล' => 'Replace old domain / path in the database',
    'ทำหลังนำเข้าฐานข้อมูลแล้ว รองรับค่าที่เป็น JSON และ serialized (params ของ Joomla, YOOtheme, Gantry) ไม่ทำให้ข้อมูลพัง' => 'Run after importing the database. Handles JSON and serialized values (Joomla, YOOtheme, Gantry params) without corrupting them.',
    'โดเมนเดิม' => 'Old domain',
    'โดเมนใหม่' => 'New domain',
    'path เดิมของเว็บ' => 'Old site path',
    'path ใหม่' => 'New path',
    'ทดสอบก่อน (นับแถวที่จะเปลี่ยน ไม่แก้จริง)' => 'Test first (count rows that would change, no writes)',
    'แทนที่ในฐานข้อมูล' => 'Replace in database',
    'กำลังติดต่อ server เดิม...' => 'Contacting the old server...',
    'บน server เดิม' => 'on the old server',
    'ยังสร้างไม่เสร็จ' => 'not finished yet',
    'พบ' => 'Found',
    'server เดิมยังไม่มีแพ็กเกจ' => 'The old server has no packages yet',
    'กำลังดึงแพ็กเกจ' => 'Pulling package',
    'ไฟล์' => 'files',
    'กำลังแตกไฟล์' => 'Extracting',
    'ส่วน' => 'part',
    'คำเตือน' => 'Warnings',
    'เสร็จแล้ว' => 'Finished',
    'ต่อไป: ข้อ 4 นำเข้าฐานข้อมูล (เลือกไฟล์ db.sql.gz ให้แล้ว) → ข้อ 2 บันทึก configuration.php → แทนที่โดเมน/path ด้านล่าง' => 'Next: step 4 import the database (db.sql.gz is pre-selected) → step 2 save configuration.php → replace domain/path below',
    'ไม่มีคู่ข้อความที่จะแทนที่' => 'Nothing to replace',
    'แทนที่ในฐานข้อมูลจริง? ควรสำรองหรือทดสอบก่อน' => 'Replace in the live database? Back up or test first.',
    'ทดสอบ' => 'Test',
    'ตาราง' => 'tables',
    'แถวที่ตรวจ' => 'rows checked',
    'แถวที่เปลี่ยน' => 'rows changed',
    'ทดสอบเสร็จ จะเปลี่ยน' => 'Test finished — would change',
    'แทนที่แล้ว' => 'Replaced',
    'แถว' => 'rows',
    'ใส่ URL ของ jbackup.php บน server เดิม' => 'Enter the URL of jbackup.php on the old server',
    'server เดิมปฏิเสธ (403): MIGRATE_KEY ของ jbackup.php กับ jmigrate.php ต้องตรงกัน' => 'The old server refused (403): MIGRATE_KEY must be the same in jbackup.php and jmigrate.php',
    'ติดต่อ jbackup.php ไม่ได้ (HTTP ' => 'Cannot reach jbackup.php (HTTP ',
    'อ่าน manifest จาก server เดิมไม่ได้ (HTTP ' => 'Cannot read the manifest from the old server (HTTP ',
    'สร้างโฟลเดอร์แพ็กเกจไม่ได้' => 'Cannot create the package folder',
    'ดาวน์โหลดไม่สำเร็จ: ' => 'Download failed: ',
    'checksum ไม่ตรง ดาวน์โหลดใหม่: ' => 'Checksum mismatch, downloading again: ',
    'ไม่พบแพ็กเกจ' => 'Package not found',
    'ไฟล์ส่วนไม่ครบหรือ checksum ไม่ตรง: ' => 'Part missing or checksum mismatch: ',
    ' (PHP จะ fatal error "Cannot redeclare")' => ' (PHP will fatal with "Cannot redeclare")',
    ' (บรรทัด ' => ' (line ',
    ' (มี error ' => ' (',
    ' (เขียนไม่ได้!)' => ' (not writable!)',
    ' <span class=bad>(พื้นที่ไม่พอ!)</span>' => ' <span class=bad>(not enough disk space!)</span>',
    ' ครบแล้ว' => ', all done',
    ' คำสั่ง · ข้าม ' => ' statements · skipped ',
    ' คำสั่ง · แปลงเป็น InnoDB ' => ' statements · converted to InnoDB ',
    ' คำสั่ง)' => ' statements)',
    ' ตัวอักษร)' => ' characters)',
    ' ตั้งแต่ต้น?
ตารางที่ชื่อซ้ำในฐานข้อมูลจะถูกแทนที่ตามคำสั่งใน dump' => ' from the beginning?
Tables with the same name will be replaced as the dump instructs.',
    ' ตาราง' => ' tables',
    ' บรรทัด (เก็บค่าล่าสุด)' => ' line(s) (kept the last value)',
    ' รายการ ดูด้านล่าง)' => ' errors, see below)',
    ' เสร็จแล้ว (' => ' finished (',
    ' เหลืออีก %d ตาราง กดปุ่มนี้ซ้ำจนกว่าจะครบ' => ', %d left — press this button again until done',
    ' แตกแล้วประมาณ <b>' => ' extracts to about <b>',
    ' ไม่ได้' => ' failed',
    ' — กด "ทดสอบการเชื่อมต่อ" ในข้อ 2 เพื่อตรวจ prefix แล้วบันทึก config' => ' — press "Test connection" in step 2 to verify the prefix, then save the config',
    ' — กำลังหยุดหลังจบรอบนี้...' => ' — stopping after this round...',
    '$%s ว่าง' => '$%s is empty',
    '(collation MySQL 8, DEFINER, key ยาวเกิน, row size, duplicate, คำสั่งใหญ่เกิน packet ฯลฯ)' => '(MySQL 8 collations, DEFINER, key too long, row size, duplicates, statements over max packet, etc.)',
    '(ปิดเว็บชั่วคราวระหว่างย้าย)' => '(take the site down while migrating)',
    '(แสดง query / profiling ท้ายหน้า)' => '(show queries / profiling at the bottom of pages)',
    '(ไม่จำกัด)' => '(unrestricted)',
    ') ลองใหม่ครั้งที่ ' => ') retry #',
    ') อาจทำให้ redirect วน/รูปไม่ขึ้น ปกติควรเว้นว่าง' => ') — may cause redirect loops / missing images; normally leave it empty',
    ') ให้อัปโหลดไฟล์เว็บให้ครบก่อน' => ') — upload the site files first',
    '<br><span class=bad>prefix ในช่องด้านล่างไม่ตรงกับที่พบในฐานข้อมูล (ควรเป็น ' => '<br><span class=bad>The prefix in the field below does not match the database (should be ',
    '<br>prefix ที่พบ: ' => '<br>Prefixes found: ',
    '<br>ฐานข้อมูลยังว่าง (ยังไม่ได้นำเข้า)' => '<br>The database is empty (nothing imported yet)',
    '<p style="font-family:sans-serif">ต้องเปิดด้วย <code>jmigrate.php?key=รหัสที่ตั้งไว้ในไฟล์</code></p>' => '<p style="font-family:sans-serif">Open with <code>jmigrate.php?key=the key set in the file</code></p>',
    '<span class=bad>หยุดเพราะ error — แก้แล้วกด "ทำต่อจากจุดเดิม" หรือติ๊ก "เจอ error แล้วข้ามไป"</span>' => '<span class=bad>Stopped on an error — fix it and press "Resume", or tick "Skip errors and continue"</span>',
    '<span class=bad>ไม่มี (เปิด .gz ไม่ได้)</span>' => '<span class=bad>missing (cannot open .gz)</span>',
    '<span class=bad>ไม่มี</span>' => '<span class=bad>missing</span>',
    '<span class=bad>ไม่มี</span> (ใช้เครื่องมือข้อ 5 สร้างได้)' => '<span class=bad>missing</span> (create it with the tools in step 5)',
    '<span class=ok>มี</span>' => '<span class=ok>present</span>',
    '<span class=ok>✔ เชื่อมต่อได้</span> MySQL ' => '<span class=ok>✔ Connected</span> MySQL ',
    '<span class=ok>✔ ไม่พบความผิดปกติ</span>' => '<span class=ok>✔ No problems found</span>',
    'DROP แล้วสร้างใหม่' => 'DROP and recreate',
    'File Manager ของ DirectAdmin (ปุ่ม Extract)' => 'DirectAdmin\'s File Manager (Extract button)',
    'InnoDB + ROW_FORMAT=DYNAMIC (index ยาว)' => 'InnoDB + ROW_FORMAT=DYNAMIC (long index)',
    'PHP อ่านไฟล์ไม่ได้ (owner/permission ผิด)' => 'PHP cannot read the file (wrong owner/permission)',
    'System Cache (ปิดไว้ระหว่างย้ายจะเห็นผลการแก้ทันที)' => 'System Cache (keep it off while migrating so changes show immediately)',
    'URL Rewriting (ต้องมี .htaccess)' => 'URL Rewriting (requires .htaccess)',
    '[2006] MySQL server has gone away (คำสั่งใหญ่เกิน max_allowed_packet หรือ wait_timeout ต่ำ)' => '[2006] MySQL server has gone away (statement larger than max_allowed_packet, or wait_timeout too low)',
    '[2006] MySQL server has gone away และต่อใหม่ไม่ได้: ' => '[2006] MySQL server has gone away and reconnect failed: ',
    'copy ไม่สำเร็จ' => 'copy failed',
    'database (ค่าเริ่มต้น)' => 'database (default)',
    'dbprefix ควรเป็นตัวอักษร/ตัวเลขแล้วลงท้ายด้วย _ (ตอนนี้: ' => 'dbprefix should be letters/digits ending with _ (currently: ',
    'default (ตาม php.ini)' => 'default (per php.ini)',
    'gunzip -k ไฟล์.gz' => 'gunzip -k file.gz',
    'gzseek ไม่สำเร็จ' => 'gzseek failed',
    'live_site (ปกติเว้นว่าง)' => 'live_site (normally empty)',
    'live_site ชี้ไปโดเมนอื่น (' => 'live_site points to another domain (',
    'log_path (ตรวจให้แล้ว)' => 'log_path (auto-detected)',
    'log_path ปัจจุบัน' => 'current log_path',
    'maximum (แสดงทุก error — ใช้ตอนหาสาเหตุ)' => 'maximum (show every error — for troubleshooting)',
    'none (ไม่แสดง)' => 'none (hide)',
    'permission ของไฟล์เป็น %s (ควรเป็น 644 ระหว่างแก้ และ 444 เมื่อเสร็จ)' => 'file permission is %s (should be 644 while editing, 444 when done)',
    'root เว็บ' => 'site root',
    's/รอบ)' => 's/round)',
    's/รอบ, seek ' => 's/round, seek ',
    'secret สั้นผิดปกติ (' => 'secret is unusually short (',
    'server ตอบกลับไม่ใช่ JSON (HTTP ' => 'server did not return JSON (HTTP ',
    'tmp_path (ตรวจให้แล้ว)' => 'tmp_path (auto-detected)',
    'tmp_path ปัจจุบัน' => 'current tmp_path',
    'การแก้อัตโนมัติที่ใช้:
' => 'Automatic fixes applied:
',
    'กำลังทดสอบ...' => 'Testing...',
    'กำลังแตกไฟล์ .gz → ' => 'Extracting .gz → ',
    'ข้อความตอน offline' => 'Offline message',
    'ข้าม DELIMITER (ไม่รองรับ) ที่ offset ' => 'Skipped DELIMITER (unsupported) at offset ',
    'ข้ามข้อมูลของตารางที่ชื่อมีคำเหล่านี้ (คั่นด้วย , — สร้างตารางแต่ไม่ใส่ข้อมูล)' => 'Skip data for tables whose name contains (comma-separated — tables are created but left empty)',
    'ข้ามคำสั่ง GTID/SQL_LOG_BIN' => 'Skipped GTID/SQL_LOG_BIN statement',
    'ข้ามคำสั่ง SET ที่ต้องมีสิทธิ์พิเศษ' => 'Skipped SET statement requiring special privileges',
    'ฐานข้อมูล และ configuration.php' => 'Database and configuration.php',
    'ตรวจสุขภาพ configuration.php' => 'configuration.php health check',
    'ตรวจใหม่' => 'Re-check',
    'ตารางที่มีข้อมูลใหญ่ที่สุด:
' => 'Largest tables by data size:
',
    'ถอยกลับเป็น MyISAM' => 'Fell back to MyISAM',
    'ถอยกลับเป็น MyISAM (FULLTEXT)' => 'Fell back to MyISAM (FULLTEXT)',
    'ทดสอบการเชื่อมต่อ' => 'Test connection',
    'ทดสอบอ่านไฟล์อย่างเดียว ไม่รันคำสั่งลงฐานข้อมูล (dry run)' => 'Read the file only, do not execute anything against the database (dry run)',
    'ทำต่อจากจุดเดิม (' => 'Resume (',
    'แก้อัตโนมัติ (BOM / ช่องว่าง / ?> ท้ายไฟล์ / คีย์ซ้ำ)' => 'Auto-fix (BOM / whitespace / trailing ?> / duplicate keys)',
    'นำเข้า ' => 'Importing ',
    'นำเข้าฐานข้อมูล (แบ่งรอบ ป้องกัน timeout)' => 'Import database (chunked, timeout-proof)',
    'นำเข้าเสร็จแล้ว ' => 'Import finished: ',
    'บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด' => 'Colorpack Creation Co., Ltd.',
    'บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด สงวนลิขสิทธิ์' => 'Colorpack Creation Co., Ltd. All rights reserved.',
    'บันทึกลง configuration.php' => 'Save to configuration.php',
    'บันทึกคอนฟิก' => 'Save config',
    'บันทึกแล้ว (สำรองเป็น %s)' => 'Saved (backup: %s)',
    'บันทึกแล้ว (สำรองไฟล์เดิมเป็น %s)' => 'Saved (previous file backed up as %s)',
    'ปน line ending ทั้ง CRLF และ LF (ไม่ร้ายแรง)' => 'Mixed CRLF and LF line endings (harmless)',
    'ปิด strict mode แล้วลองใหม่' => 'Disabled strict mode and retried',
    'พื้นที่ดิสก์ว่าง:' => 'Free disk space:',
    'มี .htaccess อยู่แล้ว ไม่ได้แก้' => '.htaccess already exists, left unchanged',
    'มี ?> ปิดท้ายไฟล์ (ไม่จำเป็น และเสี่ยงต่อ output หลุด)' => 'Closing ?> at end of file (unnecessary, risks stray output)',
    'มี ?> ปิดท้ายไฟล์ตามด้วยข้อความ/บรรทัดว่าง (ทำให้ส่ง output ก่อน header)' => 'Closing ?> followed by text/blank lines (sends output before headers)',
    'มี BOM (UTF-8 byte order mark) นำหน้าไฟล์ ทำให้เกิด "headers already sent" / หน้าว่าง' => 'UTF-8 BOM at the start of the file — causes "headers already sent" / blank pages',
    'มีคีย์ซ้ำ: ' => 'Duplicate keys: ',
    'มีช่องว่าง/บรรทัดว่างก่อน <?php' => 'Whitespace/blank lines before <?php',
    'มีอยู่แล้ว ' => 'Already exists ',
    'ยังใช้รหัส key ค่าเริ่มต้นอยู่ ควรแก้ค่า' => 'You are still using the default key — change',
    'ยังไม่เริ่ม' => 'Not started',
    'ย้ายเว็บ Joomla ไป server ใหม่ ·' => 'Move a Joomla site to a new server ·',
    'รอบนี้ล้มเหลว (' => 'This round failed (',
    'ระหว่างนำเข้า (ตารางไหนแปลงไม่ได้จะใช้ engine เดิมและแจ้งใน log)' => 'during import (tables that cannot be converted keep their engine and are reported in the log)',
    'ลบ ?> และข้อความหลัง } ปิด class' => 'Removed ?> and text after the closing }',
    'ลบ BOM' => 'Removed BOM',
    'ลบ DEFINER' => 'Removed DEFINER',
    'ลบ DEFINER/ALGORITHM' => 'Removed DEFINER/ALGORITHM',
    'ลบ VISIBLE/INVISIBLE' => 'Removed VISIBLE/INVISIBLE',
    'ลบ jmigrate.php ออกจาก server?' => 'Delete jmigrate.php from the server?',
    'ลบ jmigrate.php, state และไฟล์สำรอง config แล้ว' => 'Deleted jmigrate.php, the state file and config backups',
    'ลบคีย์ซ้ำ ' => 'Removed duplicate keys: ',
    'ลบช่องว่างหน้า <?php' => 'Removed whitespace before <?php',
    'ลบไฟล์ cache %d ไฟล์' => 'Deleted %d cache files',
    'ลบไฟล์เครื่องมือนี้' => 'Delete this tool',
    'ลบไฟล์ไม่ได้ กรุณาลบเอง' => 'Could not delete the file — please delete it manually',
    'ล้มเหลวติดต่อกัน 3 ครั้ง: ' => 'Failed 3 times in a row: ',
    'ล้าง cache ของ Joomla' => 'Clear Joomla cache',
    'ล้างตาราง %s แล้ว' => 'Truncated table %s',
    'ล้างตาราง session' => 'Truncate session table',
    'ล้างไม่ได้: ' => 'Could not truncate: ',
    'วงเล็บปีกกา { } ไม่สมดุล (ไฟล์อาจถูกตัดท้ายตอนอัปโหลด)' => 'Unbalanced braces { } (file may have been truncated during upload)',
    'ว่าง' => 'empty',
    'สภาพแวดล้อม server ใหม่' => 'New server environment',
    'สร้าง ' => 'Created ',
    'สร้าง .htaccess จาก htaccess.txt' => 'Create .htaccess from htaccess.txt',
    'สร้าง .htaccess จาก htaccess.txt แล้ว' => 'Created .htaccess from htaccess.txt',
    'สร้างโฟลเดอร์ tmp / logs / cache' => 'Create tmp / logs / cache folders',
    'สร้างไม่ได้ ' => 'Could not create ',
    'คอนฟิกสำหรับช่วงย้ายเว็บ' => 'Migration config',
    'หยุดชั่วคราว' => 'Pause',
    'หยุดเพราะ error: ' => 'Stopped on error: ',
    'หรือ SSH' => 'or via SSH',
    'อยู่นอก open_basedir (path ของ server เก่า)' => 'outside open_basedir (old server path)',
    'อ่านไฟล์ครบแล้ว (dry run) ' => 'File read completely (dry run): ',
    'เขียน .htaccess ไม่ได้' => 'Could not write .htaccess',
    'เขียน configuration.php ไม่ได้ ตรวจ permission' => 'Could not write configuration.php — check permissions',
    'เขียน configuration.php ไม่ได้ ตรวจ permission (ควรเป็น 644 ชั่วคราว)' => 'Could not write configuration.php — check permissions (temporarily set to 644)',
    'เขียนไฟล์ ' => 'Could not write file ',
    'เขียนไฟล์ไม่สำเร็จ (ดิสก์เต็ม?)' => 'Write failed (disk full?)',
    'เขียนไฟล์ไม่ได้' => 'Could not write file',
    'เขียนไม่ได้' => 'not writable',
    'เครื่องมือเสริม' => 'Extra tools',
    'เคล็ดลับ: ติ๊ก dry run แล้วกดเริ่ม จะอ่านไฟล์อย่างเดียว (เร็วมาก) และสรุปว่าตารางไหนมีข้อมูลใหญ่ที่สุด เอาชื่อไปใส่ช่อง "ข้าม" ได้ก่อนนำเข้าจริง' => 'Tip: tick dry run and start — it only reads the file (very fast) and lists the largest tables, so you can add them to the "skip" field before the real import',
    'เจอ error แล้วข้ามไปทำต่อ (ไม่หยุด)' => 'Skip errors and continue (do not stop)',
    'เชื่อมต่อฐานข้อมูลไม่ได้: ' => 'Cannot connect to the database: ',
    'เชื่อมต่อไม่ได้: ' => 'Cannot connect: ',
    'เปลี่ยน default 0000-00-00 เป็น NULL/CURRENT_TIMESTAMP' => 'Changed default 0000-00-00 to NULL',
    'เปลี่ยน engine เป็น InnoDB' => 'Changed engine to InnoDB',
    'เปลี่ยน varchar ยาวเป็น TEXT' => 'Changed long varchar to TEXT',
    'เปิดไฟล์ gz ไม่ได้' => 'Cannot open the gz file',
    'เปิดไฟล์ไม่ได้' => 'Cannot open the file',
    'เมื่อเว็บใช้งานได้แล้ว ให้ลบเครื่องมือนี้ออกทันที (รวมไฟล์ state และไฟล์สำรอง configuration.php) และอย่าลืมลบไฟล์ .sql ที่อัปโหลดมาด้วย' => 'Once the site works, remove this tool immediately (including the state file and configuration.php backups), and delete the uploaded .sql files too',
    'เริ่มนำเข้า ' => 'Start importing ',
    'เริ่มนำเข้าใหม่ตั้งแต่ต้น' => 'Start import from the beginning',
    'เสร็จแล้ว' => 'Finished',
    'แก้ error การนำเข้าอัตโนมัติ' => 'Fix import errors automatically',
    'แทน collation ที่ server ไม่รู้จัก' => 'Replaced a collation unknown to this server',
    'แบ่ง INSERT หลัง server ตัดการเชื่อมต่อ' => 'Split INSERT after the server dropped the connection',
    'แบ่ง INSERT ใหญ่เป็นหลายคำสั่ง' => 'Split a large INSERT into several statements',
    'แปลง collation utf8mb4_0900_* → unicode_ci' => 'Converted collation utf8mb4_0900_* → unicode_ci',
    'แปลงตาราง MyISAM / Aria เป็น' => 'Convert MyISAM / Aria tables to',
    'แปลงตาราง MyISAM ที่มีอยู่ (ตาม prefix) เป็น InnoDB' => 'Convert existing MyISAM tables (by prefix) to InnoDB',
    'แปลงเป็น InnoDB แล้ว %d ตาราง' => 'Converted %d tables to InnoDB',
    'โหมดหาสาเหตุ: debug + maximum' => 'Troubleshoot mode: debug + maximum',
    'โหมดใช้งานจริง: ปิด debug, error=default, เปิดเว็บ' => 'Production mode: debug off, error=default, site online',
    'ใช้ได้' => 'OK',
    'ในโฟลเดอร์นี้ (' => 'in this folder (',
    'ในไฟล์ก่อนใช้งานจริง และลบไฟล์นี้ทันทีเมื่อเสร็จ' => 'in the file before real use, and delete this file as soon as you are done',
    'ใส่ prefix (191) ให้ index บนคอลัมน์ข้อความยาว' => 'Added a (191) prefix to indexes on long text columns',
    'ให้ได้ไฟล์ .sql ก่อน แล้วค่อยเลือกไฟล์ .sql ที่นี่ ส่วนการนำเข้าไม่มีปัญหาเรื่องขนาด' => 'to get a .sql file first, then pick that .sql here. The import itself has no size limit.',
    'ไฟล์ .gz ใหญ่มาก การแตกไฟล์ผ่านหน้านี้จะช้าลงเรื่อย ๆ ในรอบท้าย ๆ (ต้องอ่านซ้ำจากต้นไฟล์ทุกรอบ) แนะนำให้แตกไฟล์ด้วย' => 'This .gz is very large. Extracting it here gets slower in later rounds (each round re-reads from the start). Extract it with',
    'ไฟล์ที่จะนำเข้า' => 'File to import',
    'ไฟล์ไม่ได้ขึ้นต้นด้วย <?php' => 'File does not start with <?php',
    'ไม่ทราบ' => 'unknown',
    'ไม่พบ' => 'Not found:',
    'ไม่พบ class JConfig' => 'class JConfig not found',
    'ไม่พบ configuration.php' => 'configuration.php not found',
    'ไม่พบไฟล์' => 'File not found',
    'ไม่พบไฟล์ .sql หรือ .sql.gz ในโฟลเดอร์นี้ ให้อัปโหลดไฟล์ dump มาวางไว้ที่เดียวกับ jmigrate.php แล้วรีเฟรช' => 'No .sql or .sql.gz file in this folder. Upload the dump next to jmigrate.php and refresh.',
    'ไม่พบไฟล์ configuration.php' => 'configuration.php file not found',
    'ไม่มี htaccess.txt จึงสร้าง .htaccess แบบพื้นฐานให้' => 'No htaccess.txt found, so a basic .htaccess was created',
    'ไม่มีคีย์ $%s' => 'Missing key $%s',
    'ไม่มีอะไรต้องแก้อัตโนมัติ' => 'Nothing to fix automatically',
    'ไม่มีโฟลเดอร์' => 'folder missing',
    'ไม่มีไฟล์ให้นำเข้า' => 'No file to import',
    '✔ ไม่พบความผิดปกติ' => '✔ No problems found',
];

$key = isset($_REQUEST['key']) ? (string)$_REQUEST['key'] : '';
if (!hash_equals(MIGRATE_KEY, $key)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    echo t('<p style="font-family:sans-serif">ต้องเปิดด้วย <code>jmigrate.php?key=รหัสที่ตั้งไว้ในไฟล์</code></p>');
    exit;
}

// ---------------------------------------------------------------- helpers
function cp_logo($h = 40) { return str_replace('class="cp-logo"', 'class="cp-logo" style="height:' . (int)$h . 'px;width:auto;display:block"', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 680 172" class="cp-logo" role="img" aria-label="COLORPACK CREATIONS"><text class="cp-w" x="0" y="92" font-size="112" fill="#2BA7DF" textLength="372" lengthAdjust="spacingAndGlyphs">COLOR</text><text class="cp-w" x="388" y="92" font-size="112" fill="currentColor" opacity="0.92" textLength="292" lengthAdjust="spacingAndGlyphs">PACK</text><circle cx="272" cy="118" r="11" fill="#3B8FE0"/><circle cx="310" cy="118" r="11" fill="#6CC04A"/><circle cx="348" cy="118" r="11" fill="#F7941D"/><circle cx="386" cy="118" r="11" fill="#EF2B2B"/><text class="cp-t" x="2" y="164" font-size="32" fill="currentColor" opacity="0.75" textLength="676" lengthAdjust="spacing">CREATIONS</text></svg>'); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function json_out($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function in_basedir($path) {
    $ob = ini_get('open_basedir');
    if (!$ob) return true;
    foreach (explode(PATH_SEPARATOR, $ob) as $a) { $a = rtrim($a, '/'); if ($a !== '' && ($path === $a || strpos($path, $a . '/') === 0)) return true; }
    return false;
}
function path_status($p) {
    if ($p === '' || $p === null) return [false, t('ว่าง')];
    if (!in_basedir($p)) return [false, t('อยู่นอก open_basedir (path ของ server เก่า)')];
    if (!is_dir($p)) return [false, t('ไม่มีโฟลเดอร์')];
    if (!is_writable($p)) return [false, t('เขียนไม่ได้')];
    return [true, t('ใช้ได้')];
}
function read_config() {
    if (!file_exists(CFG_FILE)) return null;
    $src = file_get_contents(CFG_FILE);
    $cfg = [];
    if (preg_match_all('/public\s+\$(\w+)\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;]+);/s', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $v = trim($x[2]);
            if ($v !== '' && ($v[0] === "'" || $v[0] === '"')) $v = stripcslashes(substr($v, 1, -1));
            $cfg[$x[1]] = $v;
        }
    }
    return $cfg;
}
function write_config(array $set) {
    $src = file_get_contents(CFG_FILE);
    foreach ($set as $k => $v) {
        $line = 'public $' . $k . " = '" . addcslashes($v, "'\\") . "';";
        $re = '/public\s+\$' . preg_quote($k, '/') . '\s*=\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;]+);/s';
        if (preg_match($re, $src)) $src = preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $src, 1);
        else $src = preg_replace('/\}\s*$/', "\t$line\n}", $src, 1);
    }
    $backup = CFG_FILE . '.bak-' . date('Ymd-His');
    @copy(CFG_FILE, $backup);
    if (!is_writable(CFG_FILE)) @chmod(CFG_FILE, 0644);
    $ok = @file_put_contents(CFG_FILE, $src) !== false;
    if (function_exists('opcache_invalidate')) @opcache_invalidate(CFG_FILE, true);
    return [$ok, basename($backup)];
}
function db_connect($host, $user, $pass, $db, &$err = '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $port = 3306; $socket = null;
    if (strpos($host, ':') !== false) { list($host, $p) = explode(':', $host, 2); if (is_numeric($p)) $port = (int)$p; else $socket = $p; }
    $link = @mysqli_init();
    @mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (!@mysqli_real_connect($link, $host, $user, $pass, $db !== '' ? $db : null, $port, $socket)) {
        $err = '[' . mysqli_connect_errno() . '] ' . mysqli_connect_error();
        return null;
    }
    @mysqli_set_charset($link, 'utf8mb4');
    return $link;
}
function list_prefixes($link) {
    $out = [];
    $r = mysqli_query($link, 'SHOW TABLES');
    while ($r && ($t = mysqli_fetch_row($r))) {
        if (preg_match('/^([A-Za-z0-9]+_)/', $t[0], $m)) {
            $p = $m[1];
            if (!isset($out[$p])) $out[$p] = ['count' => 0, 'joomla' => false];
            $out[$p]['count']++;
            if (strtolower(substr($t[0], strlen($p))) === 'extensions') $out[$p]['joomla'] = true;
        }
    }
    uasort($out, function ($a, $b) { return ($b['joomla'] - $a['joomla']) ?: ($b['count'] - $a['count']); });
    return $out;
}
function sql_files() {
    $files = [];
    foreach (glob(ROOT . '/*.{sql,sql.gz,gz}', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) $files[basename($f)] = filesize($f); }
    foreach (glob(ROOT . '/jbackup-*/db.sql{,.gz}', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) $files[basename(dirname($f)) . '/' . basename($f)] = filesize($f); }
    return $files;
}
function safe_rel($f) { $f = str_replace('\\', '/', (string)$f); return preg_match('#^(jbackup-[A-Za-z0-9_.-]+/)?[^/\0]+$#', $f) && strpos($f, '..') === false ? $f : ''; }
function load_state() { $s = @json_decode(@file_get_contents(STATE_FILE), true); return is_array($s) ? $s : []; }
function save_state($s) { @file_put_contents(STATE_FILE, json_encode($s, JSON_UNESCAPED_UNICODE)); }

/** ตรวจความผิดปกติของ configuration.php คืนค่า [issues[], fixable(bool)] */
function check_config() {
    $issues = []; $fixable = false;
    if (!file_exists(CFG_FILE)) return [[t('ไม่พบไฟล์ configuration.php')], false];
    $raw = file_get_contents(CFG_FILE);
    $perm = substr(sprintf('%o', fileperms(CFG_FILE)), -4);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $issues[] = t('มี BOM (UTF-8 byte order mark) นำหน้าไฟล์ ทำให้เกิด "headers already sent" / หน้าว่าง'); $fixable = true; }
    if (!preg_match('/^\xEF?\xBB?\xBF?<\?php/', $raw)) {
        if (preg_match('/^[\xEF\xBB\xBF]*\s+<\?php/', $raw)) { $issues[] = t('มีช่องว่าง/บรรทัดว่างก่อน <?php'); $fixable = true; }
        else $issues[] = t('ไฟล์ไม่ได้ขึ้นต้นด้วย <?php');
    }
    if (preg_match('/\?>\s*\S/', $raw) || preg_match('/\}\s*\?>\s*\n\s*\n/', $raw) || preg_match('/\?>[ \t]*\n(.|\n)+$/', $raw)) { $issues[] = t('มี ?> ปิดท้ายไฟล์ตามด้วยข้อความ/บรรทัดว่าง (ทำให้ส่ง output ก่อน header)'); $fixable = true; }
    elseif (preg_match('/\?>\s*$/', $raw)) { $issues[] = t('มี ?> ปิดท้ายไฟล์ (ไม่จำเป็น และเสี่ยงต่อ output หลุด)'); $fixable = true; }
    if (!preg_match('/class\s+JConfig\b/', $raw)) $issues[] = t('ไม่พบ class JConfig');
    if (substr_count($raw, '{') !== substr_count($raw, '}')) $issues[] = t('วงเล็บปีกกา { } ไม่สมดุล (ไฟล์อาจถูกตัดท้ายตอนอัปโหลด)');
    // parse check
    $parseErr = '';
    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
        @exec('php -l ' . escapeshellarg(CFG_FILE) . ' 2>&1', $o, $rc);
        if ($rc !== 0 && $o) $parseErr = implode(' ', $o);
    }
    if ($parseErr === '' && !class_exists('JConfig', false)) {
        try { ob_start(); include CFG_FILE; ob_end_clean(); }
        catch (\Throwable $e) { ob_end_clean(); $parseErr = $e->getMessage() . t(' (บรรทัด ') . $e->getLine() . ')'; }
    }
    if ($parseErr !== '') $issues[] = 'PHP syntax error: ' . h($parseErr);
    // duplicate / missing keys
    preg_match_all('/public\s+\$(\w+)\s*=/', $raw, $m);
    $dups = array_filter(array_count_values($m[1]), function ($c) { return $c > 1; });
    if ($dups) { $issues[] = t('มีคีย์ซ้ำ: ') . implode(', ', array_keys($dups)) . t(' (PHP จะ fatal error "Cannot redeclare")'); $fixable = true; }
    $cfg = read_config();
    foreach (['host', 'user', 'db', 'dbprefix', 'secret', 'tmp_path', 'log_path'] as $k) {
        if (!isset($cfg[$k])) $issues[] = sprintf(t('ไม่มีคีย์ $%s'), $k);
        elseif (trim($cfg[$k]) === '' && $k !== 'password') $issues[] = sprintf(t('$%s ว่าง'), $k);
    }
    if (isset($cfg['dbprefix']) && !preg_match('/^[A-Za-z0-9]+_$/', $cfg['dbprefix'])) $issues[] = t('dbprefix ควรเป็นตัวอักษร/ตัวเลขแล้วลงท้ายด้วย _ (ตอนนี้: ') . h($cfg['dbprefix']) . ')';
    if (isset($cfg['secret']) && strlen($cfg['secret']) < 16) $issues[] = t('secret สั้นผิดปกติ (') . strlen($cfg['secret']) . t(' ตัวอักษร)');
    if (!empty($cfg['live_site']) && stripos($cfg['live_site'], $_SERVER['HTTP_HOST']) === false) $issues[] = t('live_site ชี้ไปโดเมนอื่น (') . h($cfg['live_site']) . t(') อาจทำให้ redirect วน/รูปไม่ขึ้น ปกติควรเว้นว่าง');
    if (strpos($raw, "\r\n") !== false && strpos(str_replace("\r\n", '', $raw), "\n") !== false) $issues[] = t('ปน line ending ทั้ง CRLF และ LF (ไม่ร้ายแรง)');
    if ($perm !== '0444' && $perm !== '0644' && $perm !== '0640' && $perm !== '0600') $issues[] = sprintf(t('permission ของไฟล์เป็น %s (ควรเป็น 644 ระหว่างแก้ และ 444 เมื่อเสร็จ)'), $perm);
    if (!is_readable(CFG_FILE)) $issues[] = t('PHP อ่านไฟล์ไม่ได้ (owner/permission ผิด)');
    return [$issues, $fixable];
}
/** แก้ความผิดปกติที่แก้อัตโนมัติได้: BOM, ช่องว่างหน้า <?php, ?> ท้ายไฟล์, คีย์ซ้ำ */
function fix_config() {
    $raw = file_get_contents(CFG_FILE); $orig = $raw; $done = [];
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $raw = substr($raw, 3); $done[] = t('ลบ BOM'); }
    if (preg_match('/^\s+<\?php/', $raw)) { $raw = ltrim($raw); $done[] = t('ลบช่องว่างหน้า <?php'); }
    // ตัดทุกอย่างหลัง } ปิด class ตัวสุดท้าย
    $last = strrpos($raw, '}');
    if ($last !== false && trim(substr($raw, $last + 1)) !== '') { $raw = substr($raw, 0, $last + 1) . "\n"; $done[] = t('ลบ ?> และข้อความหลัง } ปิด class'); }
    // คีย์ซ้ำ: เก็บตัวสุดท้าย
    preg_match_all('/^[ \t]*public\s+\$(\w+)\s*=.*?;[ \t]*\r?\n/ms', $raw, $m, PREG_OFFSET_CAPTURE);
    $seen = []; $remove = [];
    for ($i = count($m[0]) - 1; $i >= 0; $i--) { $k = $m[1][$i][0]; if (isset($seen[$k])) $remove[] = $m[0][$i]; else $seen[$k] = true; }
    if ($remove) {
        usort($remove, function ($a, $b) { return $b[1] - $a[1]; });
        foreach ($remove as $r) $raw = substr($raw, 0, $r[1]) . substr($raw, $r[1] + strlen($r[0]));
        $done[] = t('ลบคีย์ซ้ำ ') . count($remove) . t(' บรรทัด (เก็บค่าล่าสุด)');
    }
    if ($raw === $orig) return [true, t('ไม่มีอะไรต้องแก้อัตโนมัติ')];
    @copy(CFG_FILE, CFG_FILE . '.bak-' . date('Ymd-His'));
    if (!is_writable(CFG_FILE)) @chmod(CFG_FILE, 0644);
    $ok = @file_put_contents(CFG_FILE, $raw) !== false;
    if (function_exists('opcache_invalidate')) @opcache_invalidate(CFG_FILE, true);
    return [$ok, $ok ? implode(', ', $done) : t('เขียนไฟล์ไม่ได้')];
}

$defaultTmp = ROOT . '/tmp';
$defaultLog = is_dir(ROOT . '/administrator/logs') || !is_dir(ROOT . '/logs') ? ROOT . '/administrator/logs' : ROOT . '/logs';

// ---------------------------------------------------------------- SQL stream parser
/**
 * อ่านไฟล์ .sql ต่อจาก offset แล้วรันทีละ statement จนครบเวลา STEP_SECONDS
 */
function import_step($file, $offset, $opts, $state) {
    $t0 = microtime(true);
    $fh = fopen($file, 'rb');
    if (!$fh) return ['error' => t('เปิดไฟล์ไม่ได้')];
    $size = filesize($file);
    fseek($fh, $offset);

    $dry = !empty($opts['dryrun']);
    $err = ''; $link = null;
    if (!$dry) {
        $link = db_connect($opts['host'], $opts['user'], $opts['pass'], $opts['db'], $err);
        if (!$link) { fclose($fh); return ['error' => t('เชื่อมต่อฐานข้อมูลไม่ได้: ') . $err]; }
        mysqli_query($link, 'SET SESSION sql_mode = ""');
        mysqli_query($link, 'SET FOREIGN_KEY_CHECKS = 0');
        mysqli_query($link, 'SET UNIQUE_CHECKS = 0');
        mysqli_query($link, 'SET SESSION wait_timeout = 600');
    }
    $ctx = ['innodb' => $innodb, 'autofix' => !empty($opts['autofix']), 'mariadb' => false, 'packet' => 1048576, 'opts' => $opts];
    if ($link) {
        $ctx['mariadb'] = stripos(mysqli_get_server_info($link), 'mariadb') !== false;
        $r = mysqli_query($link, 'SHOW VARIABLES LIKE "max_allowed_packet"'); $v = $r ? mysqli_fetch_row($r) : null;
        if ($v) $ctx['packet'] = (int)$v[1];
    }
    $fixes = isset($state['fixes']) && is_array($state['fixes']) ? $state['fixes'] : [];

    $skip = array_filter(array_map('trim', explode(',', (string)$opts['skip'])));
    $queries = (int)$state['queries']; $skipped = (int)$state['skipped']; $errors = $state['errors'];
    $tables = isset($state['tables']) && is_array($state['tables']) ? $state['tables'] : [];
    $converted = (int)(isset($state['converted']) ? $state['converted'] : 0);
    $innodb = !empty($opts['innodb']);
    $buf = ''; $inQuote = ''; $inComment = false; $stmtStart = $offset; $lastGood = $offset;
    $done = false; $fatal = null; $eof = false;

    while (true) {
        if (microtime(true) - $t0 > STEP_SECONDS) break;
        $line = fgets($fh);
        if ($line === false) {
            // ถึงท้ายไฟล์: ถ้ายังมีคำสั่งค้างอยู่ (ไม่มี ; ปิด) ให้รันเป็นคำสั่งสุดท้าย
            $rest = ltrim($buf);
            while ($rest !== '' && ($rest[0] === '#' || substr($rest, 0, 2) === '--')) { $nl = strpos($rest, "\n"); $rest = $nl === false ? '' : ltrim(substr($rest, $nl + 1)); }
            $done = true;
            if (trim($rest) === '' || trim($rest) === ';') { $lastGood = $size; break; }
            $line = ";\n"; $buf .= $line; $eof = true;
        } else {
            $buf .= $line;
        }
        // ---- scan line to find whether a statement ends here
        $len = strlen($line); $i = 0; $ended = !empty($eof);
        while ($i < $len) {
            if ($inComment) {
                $p = strpos($line, '*/', $i);
                if ($p === false) { $i = $len; break; }
                $inComment = false; $i = $p + 2; continue;
            }
            if ($inQuote !== '') {
                $i += strcspn($line, $inQuote . ($inQuote === '`' ? '' : '\\'), $i);
                if ($i >= $len) break;
                if ($line[$i] === '\\') { $i += 2; continue; }
                $inQuote = ''; $i++; continue;
            }
            $i += strcspn($line, "'\"`;#-/", $i);
            if ($i >= $len) break;
            $ch = $line[$i];
            if ($ch === "'" || $ch === '"' || $ch === '`') { $inQuote = $ch; $i++; continue; }
            if ($ch === '#' || ($ch === '-' && substr($line, $i, 2) === '--' && ($i + 2 >= $len || ctype_space($line[$i + 2])))) { $i = $len; break; }
            if ($ch === '/' && substr($line, $i, 2) === '/*') { $inComment = true; $i += 2; continue; }
            if ($ch === '-' || $ch === '/') { $i++; continue; }
            if ($ch === ';') { if (trim(substr($line, $i + 1)) === '') { $ended = true; } $i++; continue; }
        }
        if (!$ended) continue;

        // ---- a complete statement
        // ตัดบรรทัด comment นำหน้า (-- หรือ #) ออกก่อนรัน
        $stmt = ltrim($buf); $buf = '';
        while ($stmt !== '' && ($stmt[0] === '#' || substr($stmt, 0, 2) === '--')) {
            $nl = strpos($stmt, "\n");
            $stmt = $nl === false ? '' : ltrim(substr($stmt, $nl + 1));
        }
        $stmt = rtrim($stmt);
        $stmtEndOffset = $eof ? $size : ftell($fh);
        if ($stmt === '' || $stmt === ';') { $lastGood = $stmtEndOffset; if ($eof) break; continue; }
        if (stripos($stmt, 'DELIMITER') === 0) { $errors[] = t('ข้าม DELIMITER (ไม่รองรับ) ที่ offset ') . $stmtStart; $lastGood = $stmtEndOffset; continue; }

        $doSkip = false;
        if (preg_match('/^(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`?([^`\s(]+)`?/i', $stmt, $m)) {
            $tables[$m[1]] = (isset($tables[$m[1]]) ? $tables[$m[1]] : 0) + strlen($stmt);
            foreach ($skip as $pat) { if ($pat !== '' && stripos($m[1], $pat) !== false) { $doSkip = true; break; } }
        }
        if ($doSkip) { $skipped++; $lastGood = $stmtEndOffset; $stmtStart = $stmtEndOffset; continue; }

        // ---- รันคำสั่ง พร้อมระบบแก้ error อัตโนมัติ
        if (!$dry) {
            $res = exec_with_fixes($link, $stmt, $ctx, $fixes);
            if ($res['converted']) $converted++;
            if (!$res['ok']) {
                $msg = $res['msg'] . ' — ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 120);
                if ($res['fatal']) { $fatal = $msg; break; }
                $errors[] = $msg;
                if (count($errors) > 200) $errors = array_slice($errors, -200);
                if (empty($opts['ignore'])) { $fatal = $msg; break; }
            }
        } elseif ($innodb && stripos($stmt, 'CREATE TABLE') === 0 && preg_match('/ENGINE\s*=\s*(MyISAM|Aria)\b/i', $stmt)) {
            $converted++;
        }
        $queries++;
        $lastGood = $stmtEndOffset; $stmtStart = $stmtEndOffset;
    }
    fclose($fh);
    if ($link) mysqli_close($link);

    return [
        'offset' => $lastGood, 'size' => $size, 'done' => $done && !$fatal,
        'queries' => $queries, 'skipped' => $skipped, 'errors' => $errors, 'fatal' => $fatal, 'tables' => $tables, 'converted' => $converted, 'fixes' => $fixes,
        'elapsed' => round(microtime(true) - $t0, 1),
    ];
}


// ---------------------------------------------------------------- auto-fix engine
/** นับการแก้แต่ละชนิด */
function fix_note(&$fixes, $k) { $fixes[$k] = (isset($fixes[$k]) ? $fixes[$k] : 0) + 1; }

/** แก้ล่วงหน้าก่อนรัน (ไม่ต้องรอ error) */
function prefix_fixes($stmt, $ctx, &$fixes) {
    $kw = strtoupper(substr(ltrim($stmt), 0, 12));
    // คำสั่งจาก MySQL 8 dump ที่ user ธรรมดารันไม่ได้
    if (preg_match('/^SET\s+@@(GLOBAL|SESSION)\.(GTID_PURGED|SQL_LOG_BIN)/i', $stmt) || preg_match('/^SET\s+(GLOBAL\s+)?(GTID_PURGED|SQL_LOG_BIN)/i', $stmt)) { fix_note($fixes, t('ข้ามคำสั่ง GTID/SQL_LOG_BIN')); return null; }
    // DEFINER ใน view / trigger / procedure ต้องมี SUPER
    if (preg_match('/\bDEFINER\s*=\s*\S+/i', $stmt)) { $stmt = preg_replace('/\bDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\w+)@(`[^`]*`|\'[^\']*\'|\w+)\s*/i', '', $stmt); fix_note($fixes, t('ลบ DEFINER')); }
    if (strpos($kw, 'CREATE') === 0 || strpos($kw, 'ALTER') === 0) {
        // collation ของ MySQL 8 บน MariaDB / MySQL 5.x
        if ($ctx['mariadb'] && preg_match('/utf8mb4_0900_\w+/i', $stmt)) {
            $stmt = preg_replace(['/utf8mb4_0900_as_cs/i', '/utf8mb4_0900_\w+/i'], ['utf8mb4_bin', 'utf8mb4_unicode_ci'], $stmt);
            fix_note($fixes, t('แปลง collation utf8mb4_0900_* → unicode_ci'));
        }
        // InnoDB ตามตัวเลือก
        if ($ctx['innodb'] && strpos($kw, 'CREATE TABLE') === 0 && preg_match('/ENGINE\s*=\s*(MyISAM|Aria)\b/i', $stmt)) {
            $stmt = preg_replace('/ENGINE\s*=\s*(MyISAM|Aria)\b/i', 'ENGINE=InnoDB', $stmt);
        }
    }
    return $stmt;
}

/** คืน statement ที่แก้แล้วตาม error ที่เจอ หรือ null ถ้าแก้ไม่ได้ */
function reactive_fix($stmt, $errno, $errmsg, $attempt, $ctx, &$fixes, &$note) {
    $isCreate = stripos(ltrim($stmt), 'CREATE TABLE') === 0;
    $isInsert = preg_match('/^(INSERT|REPLACE)\b/i', ltrim($stmt));
    switch ((int)$errno) {
        case 1273: case 1115: // Unknown collation / charset
            $n = preg_replace(['/utf8mb4_0900_as_cs/i', '/utf8mb4_0900_\w+/i', '/utf8mb4_unicode_520_ci/i', '/utf8_unicode_520_ci/i', '/\butf8mb3\b/i'], ['utf8mb4_bin', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_ci', 'utf8_unicode_ci', 'utf8'], $stmt);
            if ($n === $stmt && preg_match('/COLLATE[= ]\s*(\w+)/i', $errmsg . ' ' . $stmt)) $n = preg_replace('/(utf8mb4)_\w+/i', '$1_unicode_ci', $stmt);
            $note = t('แทน collation ที่ server ไม่รู้จัก'); return $n !== $stmt ? $n : null;
        case 1286: // Unknown storage engine
            $note = t('เปลี่ยน engine เป็น InnoDB'); return preg_replace('/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $stmt);
        case 1118: // Row size too large
            if ($attempt === 1) { $note = 'InnoDB + ROW_FORMAT=DYNAMIC'; $n = preg_replace('/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $stmt); $n = preg_replace('/\s*ROW_FORMAT\s*=\s*\w+/i', '', $n); return preg_replace('/ENGINE=InnoDB/i', 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC', $n, 1); }
            if ($attempt === 2) { $note = t('เปลี่ยน varchar ยาวเป็น TEXT'); $n = preg_replace_callback('/\bvarchar\((\d+)\)/i', function ($m) { return (int)$m[1] >= 1000 ? 'TEXT' : $m[0]; }, $stmt); return $n !== $stmt ? $n : null; }
            if ($attempt === 3) { $note = t('ถอยกลับเป็น MyISAM'); return preg_replace('/ENGINE\s*=\s*\w+(\s*ROW_FORMAT\s*=\s*\w+)?/i', 'ENGINE=MyISAM', $stmt); }
            return null;
        case 1071: // Specified key was too long
            if ($attempt === 1 && !preg_match('/ROW_FORMAT\s*=\s*DYNAMIC/i', $stmt)) { $note = t('InnoDB + ROW_FORMAT=DYNAMIC (index ยาว)'); $n = preg_replace('/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $stmt); return preg_replace('/ENGINE=InnoDB/i', 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC', $n, 1); }
            $note = t('ใส่ prefix (191) ให้ index บนคอลัมน์ข้อความยาว'); $n = shorten_index_prefix($stmt); return $n !== $stmt ? $n : null;
        case 1062: // Duplicate entry
            if ($isInsert && !preg_match('/^INSERT\s+IGNORE/i', ltrim($stmt))) { $note = 'INSERT → INSERT IGNORE'; return preg_replace('/^(\s*)INSERT\s+/i', '$1INSERT IGNORE ', $stmt, 1); }
            return null;
        case 1050: // Table already exists
            if ($isCreate) { $note = 'CREATE TABLE IF NOT EXISTS'; return preg_replace('/^(\s*)CREATE\s+TABLE\s+(?!IF)/i', '$1CREATE TABLE IF NOT EXISTS ', $stmt, 1); }
            return null;
        case 1064: // syntax error — ลองลบ DEFINER / SQL SECURITY / ALGORITHM
            if (preg_match('/DEFINER|SQL SECURITY|ALGORITHM=/i', $stmt)) { $note = t('ลบ DEFINER/ALGORITHM'); return preg_replace(['/\bDEFINER\s*=\s*\S+\s*/i', '/\bSQL SECURITY \w+\s*/i', '/\bALGORITHM\s*=\s*\w+\s*/i'], '', $stmt); }
            if (preg_match('/\bvisible\b|\binvisible\b/i', $stmt) && $isCreate) { $note = t('ลบ VISIBLE/INVISIBLE'); return preg_replace('/\s+(IN)?VISIBLE\b/i', '', $stmt); }
            return null;
        case 1227: case 1044: case 1419: // ต้องมี SUPER / สิทธิ์ไม่พอ
            if (preg_match('/^SET\s/i', ltrim($stmt))) { $note = t('ข้ามคำสั่ง SET ที่ต้องมีสิทธิ์พิเศษ'); return ''; }
            if (preg_match('/\bDEFINER\b/i', $stmt)) { $note = t('ลบ DEFINER'); return preg_replace('/\bDEFINER\s*=\s*\S+\s*/i', '', $stmt); }
            return null;
        case 1067: case 1292: case 1264: // ค่า default/วันที่ไม่ถูกต้องเพราะ sql_mode เข้มงวด
            if ($attempt === 1) { $note = t('ปิด strict mode แล้วลองใหม่'); return $stmt; }
            if ($isCreate && preg_match('/\b(datetime|timestamp|date)\b[^,]*DEFAULT\s+\'0000-00-00[^\']*\'/i', $stmt)) { $note = t('เปลี่ยน default 0000-00-00 เป็น NULL/CURRENT_TIMESTAMP'); return preg_replace('/(\b(?:datetime|timestamp|date)\b[^,]*?)\s+NOT NULL\s+DEFAULT\s+\'0000-00-00[^\']*\'/i', '$1 NULL DEFAULT NULL', $stmt); }
            return null;
        case 1214: // engine นี้ไม่รองรับ FULLTEXT (InnoDB เก่า)
            $note = t('ถอยกลับเป็น MyISAM (FULLTEXT)'); return preg_replace('/ENGINE\s*=\s*\w+(\s*ROW_FORMAT\s*=\s*\w+)?/i', 'ENGINE=MyISAM', $stmt);
        case 1813: case 1146: // tablespace exists / table not exist — ลอง drop แล้วสร้างใหม่
            if ($isCreate && $attempt === 1 && preg_match('/CREATE\s+TABLE\s+(?:IF NOT EXISTS\s+)?`?([^`\s(]+)`?/i', $stmt, $m)) { $note = t('DROP แล้วสร้างใหม่'); return "DROP TABLE IF EXISTS `{$m[1]}`;\n" . $stmt; }
            return null;
    }
    return null;
}

/** ใส่ (191) ให้คอลัมน์ข้อความใน KEY/UNIQUE KEY (ไม่แตะ PRIMARY KEY) */
function shorten_index_prefix($stmt) {
    $types = [];
    if (preg_match_all('/^\s*`([^`]+)`\s+(varchar|char|text|mediumtext|longtext|tinytext)(?:\((\d+)\))?/im', $stmt, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) $types[$c[1]] = [strtolower($c[2]), isset($c[3]) ? (int)$c[3] : 0];
    }
    return preg_replace_callback('/^(\s*(?:UNIQUE\s+|FULLTEXT\s+)?KEY\s+`[^`]+`\s*)\(([^)]*(?:\([^)]*\)[^)]*)*)\)/im', function ($mm) use ($types) {
        if (stripos($mm[1], 'FULLTEXT') !== false) return $mm[0];
        $cols = preg_replace_callback('/`([^`]+)`(\(\d+\))?/', function ($c) use ($types) {
            if (!empty($c[2])) return $c[0];
            if (!isset($types[$c[1]])) return $c[0];
            list($t, $len) = $types[$c[1]];
            if (strpos($t, 'text') !== false || $len > 191) return '`' . $c[1] . '`(191)';
            return $c[0];
        }, $mm[2]);
        return $mm[1] . '(' . $cols . ')';
    }, $stmt);
}

/** แบ่ง INSERT แบบหลาย VALUES ให้เล็กกว่า $limit bytes ต่อคำสั่ง */
function split_insert($stmt, $limit) {
    if (!preg_match('/^(\s*(?:INSERT|REPLACE)\b.*?\bVALUES\s*)\(/is', $stmt, $m)) return null;
    $head = $m[1]; $pos = strlen($m[0]) - 1; $len = strlen($stmt);
    $rows = []; $depth = 0; $q = ''; $rowStart = $pos;
    for ($i = $pos; $i < $len; $i++) {
        $ch = $stmt[$i];
        if ($q !== '') { if ($ch === '\\') { $i++; continue; } if ($ch === $q) $q = ''; continue; }
        if ($ch === "'" || $ch === '"') { $q = $ch; continue; }
        if ($ch === '(') { if ($depth === 0) $rowStart = $i; $depth++; continue; }
        if ($ch === ')') { $depth--; if ($depth === 0) $rows[] = substr($stmt, $rowStart, $i - $rowStart + 1); continue; }
    }
    if (count($rows) < 2) return null;
    $out = []; $cur = '';
    foreach ($rows as $r) {
        if ($cur !== '' && strlen($head) + strlen($cur) + strlen($r) + 2 > $limit) { $out[] = $head . $cur . ';'; $cur = ''; }
        $cur .= ($cur === '' ? '' : ",\n") . $r;
    }
    if ($cur !== '') $out[] = $head . $cur . ';';
    return $out;
}

/** รัน statement พร้อมแก้ error อัตโนมัติ คืน ['ok','msg','fatal','converted'] */
function exec_with_fixes(&$link, $stmt, $ctx, &$fixes) {
    $orig = $stmt; $converted = false;
    if ($ctx['autofix'] || $ctx['innodb']) {
        $stmt = prefix_fixes($stmt, $ctx, $fixes);
        if ($stmt === null) return ['ok' => true, 'msg' => '', 'fatal' => false, 'converted' => false];
        if ($ctx['innodb'] && stripos(ltrim($orig), 'CREATE TABLE') === 0 && preg_match('/ENGINE\s*=\s*(MyISAM|Aria)\b/i', $orig)) $converted = true;
    }
    // คำสั่งใหญ่กว่า max_allowed_packet → แบ่งก่อนเลย
    if (strlen($stmt) > $ctx['packet'] * 0.9 && ($parts = split_insert($stmt, (int)($ctx['packet'] * 0.6)))) {
        fix_note($fixes, t('แบ่ง INSERT ใหญ่เป็นหลายคำสั่ง'));
        foreach ($parts as $p) { $r = exec_with_fixes($link, $p, array_merge($ctx, ['packet' => PHP_INT_MAX]), $fixes); if (!$r['ok']) return $r; }
        return ['ok' => true, 'msg' => '', 'fatal' => false, 'converted' => false];
    }
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        if ($stmt === '') return ['ok' => true, 'msg' => '', 'fatal' => false, 'converted' => false];
        if (mysqli_multi_query_safe($link, $stmt)) return ['ok' => true, 'msg' => '', 'fatal' => false, 'converted' => $converted];
        $errno = mysqli_errno($link); $errmsg = mysqli_error($link);
        if ($errno == 2006 || $errno == 2013) {
            // server ตัดการเชื่อมต่อ: ต่อใหม่ แล้วถ้าเป็น INSERT ให้แบ่งย่อย
            $o = $ctx['opts']; $e = '';
            $nl = db_connect($o['host'], $o['user'], $o['pass'], $o['db'], $e);
            if (!$nl) return ['ok' => false, 'msg' => t('[2006] MySQL server has gone away และต่อใหม่ไม่ได้: ') . $e, 'fatal' => true, 'converted' => false];
            mysqli_query($nl, 'SET SESSION sql_mode = ""'); mysqli_query($nl, 'SET FOREIGN_KEY_CHECKS = 0'); mysqli_query($nl, 'SET UNIQUE_CHECKS = 0');
            @mysqli_close($link); $link = $nl; // ใช้ connection ใหม่ต่อไปทั้งรอบ
            $parts = split_insert($stmt, max(65536, (int)(strlen($stmt) / 4)));
            if ($parts) {
                fix_note($fixes, t('แบ่ง INSERT หลัง server ตัดการเชื่อมต่อ'));
                foreach ($parts as $p) { if (!mysqli_query($link, $p)) return ['ok' => false, 'msg' => '[' . mysqli_errno($link) . '] ' . mysqli_error($link), 'fatal' => mysqli_errno($link) == 2006, 'converted' => false]; }
                return ['ok' => true, 'msg' => '', 'fatal' => false, 'converted' => false];
            }
            return ['ok' => false, 'msg' => t('[2006] MySQL server has gone away (คำสั่งใหญ่เกิน max_allowed_packet หรือ wait_timeout ต่ำ)'), 'fatal' => true, 'converted' => false];
        }
        if (!$ctx['autofix']) break;
        $note = '';
        if (in_array($errno, [1067, 1292, 1264]) && $attempt === 1) mysqli_query($link, 'SET SESSION sql_mode = ""');
        $fixed = reactive_fix($stmt, $errno, $errmsg, $attempt, $ctx, $fixes, $note);
        if ($fixed === null) break;
        if ($fixed === '') { fix_note($fixes, $note); return ['ok' => true, 'msg' => '', 'fatal' => false, 'converted' => false]; }
        if ($errno == 1118 && $attempt === 3) $converted = false;
        if ($errno == 1214) $converted = false;
        fix_note($fixes, $note);
        $stmt = $fixed;
    }
    return ['ok' => false, 'msg' => '[' . $errno . '] ' . $errmsg, 'fatal' => false, 'converted' => false];
}
/** รองรับ statement ที่เราต่อ DROP นำหน้าไว้ (มี ; คั่น) */
function mysqli_multi_query_safe($link, $stmt) {
    if (preg_match('/^DROP TABLE IF EXISTS `[^`]+`;\n/', $stmt, $m)) { mysqli_query($link, rtrim($m[0], ";\n")); $stmt = substr($stmt, strlen($m[0])); }
    return mysqli_query($link, $stmt);
}

/** แตกไฟล์ .gz เป็น .sql ทีละส่วน (ทำจนครบเวลาต่อรอบ) */
function gunzip_step($gzFile, $outFile, $outOffset) {
    $t0 = microtime(true);
    // กันกรณีรอบก่อน client หลุดแต่ server ยังเขียนต่อ: ตัดไฟล์ปลายทางให้ตรงกับตำแหน่งที่บันทึกไว้
    if ($outOffset === 0) { @unlink($outFile); }
    elseif (is_file($outFile) && filesize($outFile) !== $outOffset) {
        $tf = fopen($outFile, 'r+b'); if ($tf) { ftruncate($tf, min($outOffset, filesize($outFile))); fclose($tf); }
        clearstatcache(true, $outFile);
        if (filesize($outFile) < $outOffset) $outOffset = filesize($outFile);
    }
    $gz = gzopen($gzFile, 'rb');
    if (!$gz) return ['error' => t('เปิดไฟล์ gz ไม่ได้')];
    if ($outOffset > 0 && gzseek($gz, $outOffset) !== 0) { gzclose($gz); return ['error' => t('gzseek ไม่สำเร็จ')]; }
    $seekTime = round(microtime(true) - $t0, 1);
    $out = fopen($outFile, $outOffset === 0 ? 'wb' : 'ab');
    if (!$out) { gzclose($gz); return ['error' => t('เขียนไฟล์ ') . basename($outFile) . t(' ไม่ได้')]; }
    $written = 0; $done = false;
    while ($written < GZ_CHUNK && (microtime(true) - $t0 < STEP_SECONDS || $written < 4 * 1048576)) {
        $data = gzread($gz, 1024 * 1024);
        if ($data === false || $data === '') { $done = gzeof($gz); break; }
        if (fwrite($out, $data) === false) { fclose($out); gzclose($gz); return ['error' => t('เขียนไฟล์ไม่สำเร็จ (ดิสก์เต็ม?)')]; }
        $written += strlen($data);
        if (gzeof($gz)) { $done = true; break; }
    }
    fclose($out); gzclose($gz);
    return ['offset' => $outOffset + $written, 'done' => $done, 'compressed' => filesize($gzFile), 'seek' => $seekTime, 'elapsed' => round(microtime(true) - $t0, 1)];
}
/** ขนาดโดยประมาณหลังแตก gz (ISIZE ใน 4 byte สุดท้าย เป็น mod 2^32) */
function gz_isize($f) {
    $fh = fopen($f, 'rb'); if (!$fh) return 0;
    fseek($fh, -4, SEEK_END); $b = fread($fh, 4); fclose($fh);
    $v = unpack('V', $b); $iz = $v[1]; $cs = filesize($f);
    while ($iz < $cs * 2) $iz += 4294967296; // ถ้าเล็กกว่าไฟล์บีบอัด x2 แปลว่าเกิน 4GB มาแล้ว
    return $iz;
}

// ---------------------------------------------------------------- restore from package (jbackup)
function rs_state_file() { return ROOT . '/jmigrate-restore.json'; }
function rs_load() { $s = @json_decode(@file_get_contents(rs_state_file()), true); return is_array($s) ? $s : []; }
function rs_save($s) { @file_put_contents(rs_state_file(), json_encode($s, JSON_UNESCAPED_UNICODE)); }
function rs_local_packages() {
    $out = [];
    foreach (glob(ROOT . '/jbackup-*', GLOB_ONLYDIR) ?: [] as $d) {
        $m = @json_decode(@file_get_contents("$d/manifest.json"), true);
        $out[] = ['dir' => basename($d), 'name' => substr(basename($d), 8), 'manifest' => is_array($m) ? $m : null, 'files' => array_map('basename', array_filter(glob("$d/*") ?: [], 'is_file'))];
    }
    return $out;
}
/** HTTP helper: GET with optional Range; returns [status, body, headers] */
function rs_http($url, $from = null, $len = null, $post = null, $timeout = 25) {
    $headers = []; if ($from !== null) $headers[] = 'Range: bytes=' . $from . '-' . ($len ? $from + $len - 1 : '');
    if (function_exists('curl_init')) {
        $ch = curl_init($url); $hdr = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'jmigrate/' . TOOL_VERSION,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdr) { $hdr .= $h; return strlen($h); }]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post)); curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json'])); }
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false) return [0, '', $err];
        return [$code, $body, $hdr];
    }
    $opts = ['http' => ['method' => $post !== null ? 'POST' : 'GET', 'header' => implode("\r\n", array_merge($headers, $post !== null ? ['Content-Type: application/json'] : [])), 'timeout' => $timeout, 'ignore_errors' => true, 'user_agent' => 'jmigrate/' . TOOL_VERSION]];
    if ($post !== null) $opts['http']['content'] = json_encode($post);
    $body = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0; if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    return [$code, $body === false ? '' : $body, isset($http_response_header) ? implode("\n", $http_response_header) : ''];
}
function rs_source_base($url) { // normalise to .../jbackup.php
    $url = trim($url); if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!preg_match('#jbackup\.php#i', $url)) $url = rtrim(preg_replace('#\?.*$#', '', $url), '/') . '/jbackup.php';
    return preg_replace('#\?.*$#', '', $url);
}
/** อ่าน central directory ของ zip (ไม่ต้องพึ่ง ext/zip) → เขียนรายการ entries ลงไฟล์ list */
function rs_zip_index($zip, $listFile) {
    $fh = fopen($zip, 'rb'); if (!$fh) return 'open';
    $size = filesize($zip); $tail = min($size, 65536 + 22); fseek($fh, $size - $tail); $buf = fread($fh, $tail);
    $p = strrpos($buf, "PK\x05\x06"); if ($p === false) { fclose($fh); return 'eocd'; }
    $e = unpack('vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoff', substr($buf, $p + 4, 18));
    fseek($fh, $e['cdoff']); $cd = fread($fh, $e['cdsize']); fclose($fh);
    $out = fopen($listFile, 'wb'); $pos = 0; $n = 0;
    while ($pos + 46 <= strlen($cd) && substr($cd, $pos, 4) === "PK\x01\x02") {
        $h = unpack('vver/vvneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voff', substr($cd, $pos + 4, 42));
        $name = substr($cd, $pos + 46, $h['nlen']);
        $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if ($name === '' || strpos($name, '..') !== false || $name[0] === '/' || strpos($name, "\\") !== false || strpos($name, "\0") !== false) continue;
        fwrite($out, implode("\t", [$h['off'], $h['method'], $h['csize'], $h['usize'], $h['crc'], $name]) . "\n"); $n++;
    }
    fclose($out); return $n;
}
function rs_extract_entry($fh, $off, $method, $csize, $usize, $crc, $name, &$warn) {
    $dest = ROOT . '/' . $name;
    if (substr($name, -1) === '/') { if (!is_dir($dest)) @mkdir($dest, 0755, true); return true; }
    $dir = dirname($dest); if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (basename($name) === 'jmigrate.php' || basename($name) === 'jbackup.php') return true; // อย่าทับตัวเอง
    if ($name === 'configuration.php' && is_file($dest) && !is_file($dest . '.bak-restore')) @copy($dest, $dest . '.bak-restore');
    fseek($fh, $off); $lh = fread($fh, 30); if (strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") { $warn[] = "bad local header: $name"; return false; }
    $l = unpack('vnlen/velen', substr($lh, 26, 4)); fseek($fh, $off + 30 + $l['nlen'] + $l['elen']);
    $out = @fopen($dest, 'wb'); if (!$out) { $warn[] = "cannot write: $name"; return false; }
    $hc = hash_init('crc32b'); $left = $csize;
    if ($method === 8) {
        $ctx = inflate_init(ZLIB_ENCODING_RAW);
        while ($left > 0) { $chunk = fread($fh, min(1048576, $left)); if ($chunk === '' || $chunk === false) break; $left -= strlen($chunk); $d = inflate_add($ctx, $chunk, ZLIB_NO_FLUSH); if ($d !== '') { fwrite($out, $d); hash_update($hc, $d); } }
        $d = inflate_add($ctx, '', ZLIB_FINISH); if ($d !== '') { fwrite($out, $d); hash_update($hc, $d); }
    } elseif ($method === 0) {
        while ($left > 0) { $chunk = fread($fh, min(4194304, $left)); if ($chunk === '' || $chunk === false) break; $left -= strlen($chunk); fwrite($out, $chunk); hash_update($hc, $chunk); }
    } else { fclose($out); $warn[] = "unsupported method $method: $name"; return false; }
    fclose($out);
    if ($usize > 0 && hexdec(hash_final($hc)) !== $crc) { $warn[] = "crc mismatch: $name"; return false; }
    return true;
}
/** serialized-safe replace สำหรับค่าในฐานข้อมูล */
function rs_replace_value($v, $pairs) {
    if ($v === null || $v === '') return $v;
    $hit = false; foreach ($pairs as $p) { if ($p[0] !== '' && strpos($v, $p[0]) !== false) { $hit = true; break; } }
    if (!$hit) return $v;
    if (preg_match('/^[aOs]:\d+[:{]/', $v)) { // serialized PHP
        $u = @unserialize($v, ['allowed_classes' => false]);
        if ($u !== false || $v === 'b:0;') { $u = rs_replace_deep($u, $pairs); return serialize($u); }
    }
    if (($v[0] === '{' || $v[0] === '[') && ($j = json_decode($v, true)) !== null) { // JSON (Joomla params)
        return json_encode(rs_replace_deep($j, $pairs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    foreach ($pairs as $p) if ($p[0] !== '') $v = str_replace($p[0], $p[1], $v);
    return $v;
}
function rs_replace_deep($x, $pairs) {
    if (is_string($x)) return rs_replace_value($x, $pairs);
    if (is_array($x)) { $o = []; foreach ($x as $k => $v) $o[is_string($k) ? rs_replace_value($k, $pairs) : $k] = rs_replace_deep($v, $pairs); return $o; }
    return $x;
}

// ---------------------------------------------------------------- AJAX actions
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
if ($action !== '') {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    if ($action === 'testdb') {
        $err = '';
        $link = db_connect($in['host'], $in['user'], $in['pass'], $in['db'], $err);
        if (!$link) json_out(['ok' => false, 'msg' => $err]);
        $ver = mysqli_get_server_info($link);
        $pre = list_prefixes($link);
        $r = mysqli_query($link, 'SHOW VARIABLES LIKE "max_allowed_packet"');
        $map = $r ? mysqli_fetch_row($r) : null;
        mysqli_close($link);
        json_out(['ok' => true, 'version' => $ver, 'prefixes' => $pre, 'max_allowed_packet' => $map ? round($map[1] / 1048576, 1) . ' MB' : '?']);
    }

    if ($action === 'saveconfig') {
        if (!file_exists(CFG_FILE)) json_out(['ok' => false, 'msg' => t('ไม่พบ configuration.php')]);
        $set = [
            'host' => $in['host'], 'user' => $in['user'], 'password' => $in['pass'], 'db' => $in['db'],
            'dbprefix' => $in['prefix'], 'tmp_path' => $in['tmp_path'], 'log_path' => $in['log_path'],
        ];
        if (!empty($in['session_handler'])) $set['session_handler'] = $in['session_handler'];
        if (isset($in['live_site'])) $set['live_site'] = $in['live_site'];
        foreach (['tmp_path', 'log_path'] as $k) { if (!is_dir($set[$k])) @mkdir($set[$k], 0755, true); }
        list($ok, $backup) = write_config($set);
        json_out(['ok' => $ok, 'msg' => $ok ? sprintf(t('บันทึกแล้ว (สำรองไฟล์เดิมเป็น %s)'), $backup) : t('เขียน configuration.php ไม่ได้ ตรวจ permission (ควรเป็น 644 ชั่วคราว)')]);
    }

    if ($action === 'savetoggles') {
        if (!file_exists(CFG_FILE)) json_out(['ok' => false, 'msg' => t('ไม่พบ configuration.php')]);
        $set = [
            'debug' => !empty($in['debug']) ? '1' : '0',
            'error_reporting' => in_array($in['error_reporting'], ['default', 'none', 'simple', 'maximum', 'development']) ? $in['error_reporting'] : 'default',
            'offline' => !empty($in['offline']) ? '1' : '0',
        ];
        if (isset($in['offline_message'])) $set['offline_message'] = $in['offline_message'];
        if (isset($in['sef'])) $set['sef'] = !empty($in['sef']) ? '1' : '0';
        if (isset($in['sef_rewrite'])) $set['sef_rewrite'] = !empty($in['sef_rewrite']) ? '1' : '0';
        if (isset($in['caching'])) $set['caching'] = !empty($in['caching']) ? '1' : '0';
        list($ok, $backup) = write_config($set);
        json_out(['ok' => $ok, 'msg' => $ok ? sprintf(t('บันทึกแล้ว (สำรองเป็น %s)'), $backup) : t('เขียน configuration.php ไม่ได้ ตรวจ permission')]);
    }

    if ($action === 'checkcfg') {
        list($issues, $fixable) = check_config();
        json_out(['ok' => true, 'issues' => $issues, 'fixable' => $fixable]);
    }
    if ($action === 'fixcfg') {
        list($ok, $msg) = fix_config();
        list($issues) = check_config();
        json_out(['ok' => $ok, 'msg' => $msg, 'issues' => $issues]);
    }

    // ---- restore from package
    if ($action === 'rs_remote_list') {
        $base = rs_source_base($in['source']); if ($base === '') json_out(['ok' => false, 'msg' => t('ใส่ URL ของ jbackup.php บน server เดิม')]);
        list($code, $body) = rs_http($base . '?key=' . urlencode(MIGRATE_KEY) . '&action=list', null, null, []);
        $j = json_decode($body, true);
        if ($code === 403) json_out(['ok' => false, 'msg' => t('server เดิมปฏิเสธ (403): MIGRATE_KEY ของ jbackup.php กับ jmigrate.php ต้องตรงกัน')]);
        if (!$j || empty($j['ok'])) json_out(['ok' => false, 'msg' => t('ติดต่อ jbackup.php ไม่ได้ (HTTP ') . $code . '): ' . substr(strip_tags((string)$body), 0, 120)]);
        json_out(['ok' => true, 'base' => $base, 'packages' => $j['packages']]);
    }
    if ($action === 'rs_pull') {
        $t0 = microtime(true); $st = rs_load();
        $base = rs_source_base($in['source']); $pkg = 'jbackup-' . preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$in['name']);
        if (!empty($in['reset']) || empty($st['pull']) || $st['pull']['pkg'] !== $pkg || $st['pull']['base'] !== $base) {
            list($code, $body) = rs_http($base . '?key=' . urlencode(MIGRATE_KEY) . '&action=manifest&pkg=' . urlencode($pkg));
            $man = json_decode($body, true);
            if ($code !== 200 || !$man || empty($man['checksums'])) json_out(['ok' => false, 'msg' => t('อ่าน manifest จาก server เดิมไม่ได้ (HTTP ') . $code . ')']);
            $dir = ROOT . '/' . $pkg; if (!is_dir($dir) && !@mkdir($dir, 0755)) json_out(['ok' => false, 'msg' => t('สร้างโฟลเดอร์แพ็กเกจไม่ได้')]);
            @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n"); file_put_contents("$dir/manifest.json", $body);
            $files = []; foreach ($man['checksums'] as $f => $c) $files[] = ['f' => $f, 'size' => (int)$c['size'], 'md5' => $c['md5'], 'got' => is_file("$dir/$f") ? filesize("$dir/$f") : 0, 'ok' => false];
            $st['pull'] = ['pkg' => $pkg, 'base' => $base, 'files' => $files, 'i' => 0, 'done' => false, 'total' => array_sum(array_column($files, 'size')), 'bytes' => 0, 'errors' => []];
            foreach ($files as $f) $st['pull']['bytes'] += min($f['got'], $f['size']);
        }
        $p = &$st['pull']; $dir = ROOT . '/' . $pkg; $chunk = 8 * 1048576;
        while ($p['i'] < count($p['files'])) {
            $f = &$p['files'][$p['i']];
            if ($f['ok']) { $p['i']++; continue; }
            if ($f['got'] > $f['size']) { @unlink("$dir/{$f['f']}"); $p['bytes'] -= $f['got']; $f['got'] = 0; }
            while ($f['got'] < $f['size']) {
                if (microtime(true) - $t0 > STEP_SECONDS) { rs_save($st); json_out(['ok' => true, 'state' => $st['pull'], 'elapsed' => round(microtime(true) - $t0, 1)]); }
                $want = min($chunk, $f['size'] - $f['got']);
                list($code, $body) = rs_http($base . '?key=' . urlencode(MIGRATE_KEY) . '&action=download&pkg=' . urlencode($pkg) . '&f=' . urlencode($f['f']), $f['got'], $want, null, 60);
                if ($code !== 206 && !($code === 200 && $f['got'] === 0)) { $p['errors'][] = "{$f['f']}: HTTP $code"; rs_save($st); json_out(['ok' => false, 'msg' => t('ดาวน์โหลดไม่สำเร็จ: ') . "{$f['f']} (HTTP $code)", 'state' => $st['pull']]); }
                if ($code === 200) $body = substr($body, 0, $f['size']);
                $fp = fopen("$dir/{$f['f']}", 'c+b'); fseek($fp, $f['got']); fwrite($fp, $body); fclose($fp);
                $f['got'] += strlen($body); $p['bytes'] += strlen($body);
                if (strlen($body) === 0) { $p['errors'][] = "{$f['f']}: empty response"; rs_save($st); json_out(['ok' => false, 'msg' => t('ดาวน์โหลดไม่สำเร็จ: ') . $f['f'], 'state' => $st['pull']]); }
            }
            $f['ok'] = md5_file("$dir/{$f['f']}") === $f['md5'];
            if (!$f['ok']) { $p['errors'][] = "{$f['f']}: checksum mismatch"; @unlink("$dir/{$f['f']}"); $p['bytes'] -= $f['got']; $f['got'] = 0; rs_save($st); json_out(['ok' => false, 'msg' => t('checksum ไม่ตรง ดาวน์โหลดใหม่: ') . $f['f'], 'state' => $st['pull']]); }
            $p['i']++;
        }
        $p['done'] = true; rs_save($st); json_out(['ok' => true, 'state' => $st['pull'], 'elapsed' => round(microtime(true) - $t0, 1)]);
    }
    if ($action === 'rs_extract') {
        $t0 = microtime(true); $st = rs_load();
        $pkg = basename((string)$in['pkg']); $dir = ROOT . '/' . $pkg; if (strpos($pkg, 'jbackup-') !== 0 || !is_dir($dir)) json_out(['ok' => false, 'msg' => t('ไม่พบแพ็กเกจ')]);
        $man = @json_decode(@file_get_contents("$dir/manifest.json"), true);
        $parts = $man && !empty($man['files']['parts']) ? $man['files']['parts'] : array_map('basename', glob("$dir/site-part*.zip") ?: []);
        if (!empty($in['reset']) || empty($st['extract']) || $st['extract']['pkg'] !== $pkg) {
            foreach ($parts as $pf) { $c = $man['checksums'][$pf] ?? null; if ($c && (!is_file("$dir/$pf") || md5_file("$dir/$pf") !== $c['md5'])) json_out(['ok' => false, 'msg' => t('ไฟล์ส่วนไม่ครบหรือ checksum ไม่ตรง: ') . $pf]); }
            $st['extract'] = ['pkg' => $pkg, 'parts' => $parts, 'pi' => 0, 'pos' => 0, 'entries' => 0, 'files' => 0, 'bytes' => 0, 'done' => false, 'warnings' => [], 'total' => $man['files']['count'] ?? 0];
        }
        $e = &$st['extract'];
        while ($e['pi'] < count($e['parts'])) {
            $zip = "$dir/{$e['parts'][$e['pi']]}"; $list = "$zip.list";
            if (!is_file($list)) { $n = rs_zip_index($zip, $list); if (!is_int($n)) { $e['warnings'][] = "cannot read $zip ($n)"; $e['pi']++; $e['pos'] = 0; continue; } }
            $lf = fopen($list, 'rb'); fseek($lf, $e['pos']); $zh = fopen($zip, 'rb');
            while (($line = fgets($lf)) !== false) {
                list($off, $method, $csize, $usize, $crc, $name) = explode("\t", rtrim($line, "\n"), 6);
                if (rs_extract_entry($zh, (int)$off, (int)$method, (int)$csize, (int)$usize, (int)$crc, $name, $e['warnings'])) { if (substr($name, -1) !== '/') { $e['files']++; $e['bytes'] += (int)$usize; } }
                $e['entries']++; $e['pos'] = ftell($lf);
                if (microtime(true) - $t0 > STEP_SECONDS) { fclose($lf); fclose($zh); rs_save($st); json_out(['ok' => true, 'state' => $e, 'elapsed' => round(microtime(true) - $t0, 1)]); }
            }
            fclose($lf); fclose($zh); @unlink($list); $e['pi']++; $e['pos'] = 0;
        }
        $e['done'] = true; if (count($e['warnings']) > 200) $e['warnings'] = array_slice($e['warnings'], -200);
        // เติมค่าจาก manifest ให้ส่วนฐานข้อมูล
        $e['suggest'] = ['prefix' => $man['site']['dbprefix'] ?? '', 'db_file' => ($man && !empty($man['database']['file']) && is_file("$dir/{$man['database']['file']}")) ? "$pkg/{$man['database']['file']}" : '', 'old_host' => $man['site']['host'] ?? '', 'old_root' => $man['site']['root'] ?? ''];
        rs_save($st); json_out(['ok' => true, 'state' => $e, 'elapsed' => round(microtime(true) - $t0, 1)]);
    }
    if ($action === 'rs_replace') {
        $t0 = microtime(true); $st = rs_load(); $err = '';
        $link = db_connect($in['host'], $in['user'], $in['pass'], $in['db'], $err); if (!$link) json_out(['ok' => false, 'msg' => t('เชื่อมต่อฐานข้อมูลไม่ได้: ') . $err]);
        $pairs = []; foreach ((array)$in['pairs'] as $p) { if (isset($p[0], $p[1]) && $p[0] !== '' && $p[0] !== $p[1]) $pairs[] = [(string)$p[0], (string)$p[1]]; }
        if (!$pairs) json_out(['ok' => false, 'msg' => t('ไม่มีคู่ข้อความที่จะแทนที่')]);
        $prefix = (string)$in['prefix'];
        if (!empty($in['reset']) || empty($st['replace']) || $st['replace']['pairs'] !== $pairs) {
            $r = mysqli_query($link, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE '" . mysqli_real_escape_string($link, str_replace('_', '\\_', $prefix)) . "%'");
            $tables = []; while ($r && ($x = mysqli_fetch_row($r))) $tables[] = $x[0];
            $st['replace'] = ['pairs' => $pairs, 'tables' => $tables, 'ti' => 0, 'last' => null, 'offset' => 0, 'rows' => 0, 'changed' => 0, 'done' => false, 'log' => []];
        }
        $rp = &$st['replace']; $dry = !empty($in['dryrun']);
        $selfRef = false; foreach ($pairs as $p) if (strpos($p[1], $p[0]) !== false) $selfRef = true; // ค่าใหม่มีค่าเก่าอยู่ข้างใน → แถวยังตรง LIKE หลังแก้
        while ($rp['ti'] < count($rp['tables'])) {
            $t = $rp['tables'][$rp['ti']]; $tq = '`' . str_replace('`', '``', $t) . '`';
            $r = mysqli_query($link, "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . mysqli_real_escape_string($link, $t) . "' ORDER BY ORDINAL_POSITION");
            $cols = []; $pk = []; $all = [];
            while ($r && ($c = mysqli_fetch_assoc($r))) { $all[] = $c['COLUMN_NAME']; if ($c['COLUMN_KEY'] === 'PRI') $pk[] = $c['COLUMN_NAME']; if (in_array($c['DATA_TYPE'], ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'json'])) $cols[] = $c['COLUMN_NAME']; }
            if (!$cols || !$pk) { $rp['ti']++; $rp['last'] = null; $rp['offset'] = 0; continue; }
            $like = implode(' OR ', array_map(function ($c) use ($pairs, $link) { $o = []; foreach ($pairs as $p) $o[] = '`' . $c . '` LIKE \'%' . mysqli_real_escape_string($link, str_replace(['%', '_'], ['\\%', '\\_'], $p[0])) . '%\''; return implode(' OR ', $o); }, $cols));
            $pkq = implode(',', array_map(function ($c) { return '`' . $c . '`'; }, $pk)); $sel = implode(',', array_map(function ($c) { return '`' . $c . '`'; }, array_unique(array_merge($pk, $cols))));
            while (true) {
                if (microtime(true) - $t0 > STEP_SECONDS) { mysqli_close($link); rs_save($st); json_out(['ok' => true, 'state' => $rp, 'elapsed' => round(microtime(true) - $t0, 1)]); }
                $r = mysqli_query($link, "SELECT $sel FROM $tq WHERE ($like) ORDER BY $pkq LIMIT 300 OFFSET {$rp['offset']}");
                if (!$r) { $rp['log'][] = "$t: " . mysqli_error($link); break; }
                $n = 0; $chg = 0;
                while ($row = mysqli_fetch_assoc($r)) {
                    $n++; $set = [];
                    foreach ($cols as $c) { $nv = rs_replace_value($row[$c], $pairs); if ($nv !== $row[$c]) $set[] = '`' . $c . '` = \'' . mysqli_real_escape_string($link, $nv) . '\''; }
                    if (!$set) continue;
                    $where = implode(' AND ', array_map(function ($c) use ($row, $link) { return '`' . $c . '` = \'' . mysqli_real_escape_string($link, $row[$c]) . '\''; }, $pk));
                    if (!$dry) { if (!mysqli_query($link, "UPDATE $tq SET " . implode(', ', $set) . " WHERE $where")) $rp['log'][] = "$t: " . mysqli_error($link); }
                    $chg++;
                }
                mysqli_free_result($r); $rp['rows'] += $n; $rp['changed'] += $chg; if ($chg) $rp['log'][] = "$t: $chg";
                // ถ้าแทนที่จริง แถวที่แก้แล้วจะไม่ตรง LIKE อีก จึงไม่เลื่อน offset; ถ้า dry run ต้องเลื่อน
                $rp['offset'] += ($dry || $selfRef) ? $n : ($n - $chg);
                if ($n < 300) break;
            }
            $rp['ti']++; $rp['offset'] = 0;
        }
        mysqli_close($link); $rp['done'] = true; rs_save($st); json_out(['ok' => true, 'state' => $rp, 'elapsed' => round(microtime(true) - $t0, 1)]);
    }

    if ($action === 'import') {
        $file = safe_rel($in['file']);
        $path = ROOT . '/' . $file;
        if ($file === '' || !is_file($path)) json_out(['ok' => false, 'msg' => t('ไม่พบไฟล์')]);
        $state = load_state();
        $reset = !empty($in['reset']) || empty($state['file']) || $state['file'] !== $file;
        if ($reset) {
            $state = ['file' => $file, 'phase' => preg_match('/\.gz$/i', $file) ? 'gunzip' : 'import', 'gz_offset' => 0, 'offset' => 0, 'queries' => 0, 'skipped' => 0, 'errors' => [], 'done' => false, 'started' => time()];
        }
        if ($state['done']) json_out(['ok' => true, 'state' => $state]);
        $opts = ['host' => $in['host'], 'user' => $in['user'], 'pass' => $in['pass'], 'db' => $in['db'], 'skip' => $in['skip'], 'ignore' => !empty($in['ignore']), 'dryrun' => !empty($in['dryrun']), 'innodb' => !empty($in['innodb']), 'autofix' => !empty($in['autofix'])];

        if ($state['phase'] === 'gunzip') {
            $sqlFile = ROOT . '/' . preg_replace('/\.gz$/i', '', $file);
            if (!preg_match('/\.sql$/i', $sqlFile)) $sqlFile .= '.sql';
            $r = gunzip_step($path, $sqlFile, (int)$state['gz_offset']);
            if (isset($r['error'])) json_out(['ok' => false, 'msg' => $r['error']]);
            $state['gz_offset'] = $r['offset']; $state['gz_compressed'] = $r['compressed']; $state['sql_file'] = substr($sqlFile, strlen(ROOT) + 1);
            if ($r['done']) { $state['phase'] = 'import'; $state['offset'] = 0; }
            save_state($state);
            json_out(['ok' => true, 'state' => $state, 'elapsed' => $r['elapsed'], 'seek' => $r['seek']]);
        }

        $sqlPath = ROOT . '/' . (isset($state['sql_file']) ? $state['sql_file'] : $file);
        $r = import_step($sqlPath, (int)$state['offset'], $opts, $state);
        if (isset($r['error'])) json_out(['ok' => false, 'msg' => $r['error']]);
        $state['offset'] = $r['offset']; $state['size'] = $r['size']; $state['queries'] = $r['queries'];
        $state['skipped'] = $r['skipped']; $state['errors'] = $r['errors']; $state['tables'] = $r['tables']; $state['converted'] = $r['converted']; $state['fixes'] = $r['fixes'];
        if ($r['fatal']) { $state['fatal'] = $r['fatal']; save_state($state); json_out(['ok' => false, 'msg' => $r['fatal'], 'state' => $state]); }
        unset($state['fatal']);
        if ($r['done']) { $state['done'] = true; $state['finished'] = time(); }
        save_state($state);
        json_out(['ok' => true, 'state' => $state, 'elapsed' => $r['elapsed']]);
    }

    if ($action === 'tools') {
        $msgs = [];
        $what = $in['what'];
        if ($what === 'dirs') {
            foreach ([$defaultTmp, $defaultLog, ROOT . '/cache', ROOT . '/administrator/cache'] as $d) {
                if (!is_dir($d)) $msgs[] = (@mkdir($d, 0755, true) ? t('สร้าง ') : t('สร้างไม่ได้ ')) . str_replace(ROOT, '', $d);
                else $msgs[] = t('มีอยู่แล้ว ') . str_replace(ROOT, '', $d) . (is_writable($d) ? '' : t(' (เขียนไม่ได้!)'));
            }
        } elseif ($what === 'htaccess') {
            if (file_exists(ROOT . '/.htaccess')) $msgs[] = t('มี .htaccess อยู่แล้ว ไม่ได้แก้');
            elseif (file_exists(ROOT . '/htaccess.txt')) $msgs[] = @copy(ROOT . '/htaccess.txt', ROOT . '/.htaccess') ? t('สร้าง .htaccess จาก htaccess.txt แล้ว') : t('copy ไม่สำเร็จ');
            else {
                $ht = "Options -Indexes\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteCond %{REQUEST_URI} !^/index\\.php\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule .* index.php [L]\n</IfModule>\n";
                $msgs[] = @file_put_contents(ROOT . '/.htaccess', $ht) ? t('ไม่มี htaccess.txt จึงสร้าง .htaccess แบบพื้นฐานให้') : t('เขียน .htaccess ไม่ได้');
            }
        } elseif ($what === 'session') {
            $err = ''; $link = db_connect($in['host'], $in['user'], $in['pass'], $in['db'], $err);
            if (!$link) $msgs[] = t('เชื่อมต่อไม่ได้: ') . $err;
            else {
                $t = $in['prefix'] . 'session';
                $msgs[] = mysqli_query($link, "TRUNCATE TABLE `$t`") ? sprintf(t('ล้างตาราง %s แล้ว'), $t) : t('ล้างไม่ได้: ') . mysqli_error($link);
                mysqli_close($link);
            }
        } elseif ($what === 'innodb') {
            $err = ''; $link = db_connect($in['host'], $in['user'], $in['pass'], $in['db'], $err);
            if (!$link) $msgs[] = t('เชื่อมต่อไม่ได้: ') . $err;
            else {
                $t0 = microtime(true); $n = 0; $left = 0;
                $r = mysqli_query($link, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE IN ('MyISAM','Aria') AND TABLE_NAME LIKE '" . mysqli_real_escape_string($link, $in['prefix']) . "%'");
                $list = []; while ($r && ($x = mysqli_fetch_row($r))) $list[] = $x[0];
                foreach ($list as $t) {
                    if (microtime(true) - $t0 > 20) { $left++; continue; }
                    if (mysqli_query($link, "ALTER TABLE `$t` ENGINE=InnoDB")) $n++; else $msgs[] = "$t: " . mysqli_error($link);
                }
                $msgs[] = sprintf(t('แปลงเป็น InnoDB แล้ว %d ตาราง'), $n) . ($left ? sprintf(t(' เหลืออีก %d ตาราง กดปุ่มนี้ซ้ำจนกว่าจะครบ'), $left) : t(' ครบแล้ว'));
                mysqli_close($link);
            }
        } elseif ($what === 'clearcache') {
            $n = 0;
            foreach ([ROOT . '/cache', ROOT . '/administrator/cache'] as $d) {
                if (!is_dir($d)) continue;
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) { if (basename($f) === 'index.html') continue; $f->isDir() ? @rmdir($f) : (@unlink($f) && $n++); }
            }
            $msgs[] = sprintf(t('ลบไฟล์ cache %d ไฟล์'), $n);
        } elseif ($what === 'selfdestruct') {
            @unlink(STATE_FILE);
            foreach (glob(ROOT . '/configuration.php.bak-*') ?: [] as $b) @unlink($b);
            $ok = @unlink(__FILE__);
            $msgs[] = $ok ? t('ลบ jmigrate.php, state และไฟล์สำรอง config แล้ว') : t('ลบไฟล์ไม่ได้ กรุณาลบเอง');
        }
        json_out(['ok' => true, 'msg' => implode("\n", $msgs)]);
    }
    json_out(['ok' => false, 'msg' => 'unknown action']);
}

// ---------------------------------------------------------------- page
$cfg = read_config();
$files = sql_files();
$rsLocal = rs_local_packages();
$state = load_state();
$ob = ini_get('open_basedir');
list($tmpOk, $tmpMsg) = path_status(isset($cfg['tmp_path']) ? $cfg['tmp_path'] : '');
list($logOk, $logMsg) = path_status(isset($cfg['log_path']) ? $cfg['log_path'] : '');
$hasHt = file_exists(ROOT . '/.htaccess');
?>
<!DOCTYPE html>
<html lang="<?= $LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Joomla Migrate Tool</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  /* ---- Colorpack theme tokens (design system "Colorpack") ---- */
  :root{--red:#ef2b2c;--orange:#f7941c;--green:#6cc04a;--blue:#3b8fe0;--sky:#2ba7df;--gray:#666666;--ink:#111111;--paper:#ffffff;--ground:#f4f6f8;--line:#e1e6eb;
    --red-tint:#fde8e8;--orange-tint:#fef0dc;--green-tint:#e8f5e1;--blue-tint:#e3effb;--red-deep:#b81b1c;--orange-deep:#9a5300;--green-deep:#2f7a1a;--blue-deep:#1f5fa8;--blue-hover:#2f7ac4;
    --terminal:#1b1f24;--terminal-text:#d6dee6;--shadow-card:0 1px 2px rgba(17,17,17,.05),0 10px 30px rgba(17,17,17,.06);--shadow-focus:0 0 0 4px rgba(59,143,224,.28);
    --font-body:"Kanit","Noto Sans Thai",system-ui,sans-serif;--font-mono:"JetBrains Mono","IBM Plex Mono",ui-monospace,Menlo,monospace;--radius-sm:10px;--radius-md:14px;--radius-lg:20px}
  @media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--gray:#a0a6ad;--ink:#f2f4f6;--paper:#1b1f24;--ground:#121417;--line:#2a3038;--red-tint:#3a1a1a;--orange-tint:#3a2a12;--green-tint:#1a3315;--blue-tint:#162a40;--red-deep:#ff8a8a;--orange-deep:#ffc27a;--green-deep:#9be07f;--blue-deep:#8fc3ff;--blue-hover:#5aa3ee;--terminal:#0c0e11;--terminal-text:#c9d2db;--shadow-card:0 1px 2px rgba(0,0,0,.4),0 10px 30px rgba(0,0,0,.35);color-scheme:dark}}
  *{box-sizing:border-box}
  body{margin:0;background:var(--ground);color:var(--ink);font-family:var(--font-body);font-size:17px;line-height:1.6;-webkit-font-smoothing:antialiased}
  a{color:var(--blue-deep)}
  .topbar{background:var(--ink);color:var(--paper)}
  .topbar .in{max-width:1080px;margin:0 auto;padding:20px 24px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 28px}
  .brand{display:flex;align-items:center;gap:16px}
  .brand .t{font-size:24px;font-weight:600;line-height:1.2}.brand .s{font-size:15px;color:#a0a6ad}
  .wrap{max-width:1080px;margin:0 auto;padding:28px 24px 8px;display:flex;flex-direction:column;gap:24px}
  .card{background:var(--paper);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);padding:28px 32px;margin:0}
  @media(max-width:640px){.card{padding:20px 16px}.wrap{padding:20px 16px 8px}}
  h2{margin:0 0 18px;font-size:24px;font-weight:600;line-height:1.3;display:flex;align-items:center;gap:14px;text-wrap:balance}
  h2::before{content:attr(data-n);width:40px;height:40px;border-radius:12px;background:var(--blue);color:#fff;font-size:18px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;flex:none}
  h2[data-n="2"]::before,h2[data-n="2.1"]::before,h2[data-n="2.2"]::before{background:var(--green);color:#111}
  h2[data-n="2.1"]::before,h2[data-n="2.2"]::before{font-size:15px}
  h2[data-n="3"]::before{background:var(--orange);color:#111}
  h2[data-n="4"]::before{background:var(--red)}
  h2[data-n="5"]::before{background:var(--blue)}
  h2[data-n="6"]::before{background:var(--ink);color:var(--paper)}
  label{display:block;font-size:15px;font-weight:500;color:var(--gray);margin:16px 0 8px;line-height:1.35}
  input[type=text],input[type=password],select{width:100%;min-height:48px;padding:10px 14px;border:2px solid var(--line);border-radius:var(--radius-sm);font:17px/24px var(--font-body);background:var(--paper);color:var(--ink)}
  input:focus,select:focus{outline:none;border-color:var(--blue);box-shadow:var(--shadow-focus)}
  .row{display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:0 24px} @media(max-width:640px){.row{grid-template-columns:1fr}}
  button{min-height:48px;padding:0 22px;border:2px solid transparent;border-radius:var(--radius-md);font:500 17px/24px var(--font-body);cursor:pointer;background:var(--blue);color:#fff;margin:14px 10px 0 0;display:inline-flex;align-items:center;gap:8px;transition:background .15s,border-color .15s}
  button:hover{background:var(--blue-hover)} button:focus-visible{outline:none;box-shadow:var(--shadow-focus)}
  button.sec{background:var(--paper);color:var(--ink);border-color:var(--line)}button.sec:hover{background:var(--paper);border-color:var(--ink)}
  button.danger{background:var(--paper);color:var(--red-deep);border-color:var(--red)}button.danger:hover{background:var(--red-tint)} button:disabled{opacity:.45;cursor:default}
  .ok{color:var(--green-deep);font-weight:500}.bad{color:var(--red-deep);font-weight:500}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:15px;font-weight:500;line-height:20px;background:var(--ground);color:var(--gray)}
  .warn{background:var(--orange-tint);color:var(--orange-deep);border-radius:var(--radius-md);padding:16px 18px;font-size:17px;line-height:1.55;margin-bottom:4px}
  .mono,code{font-family:var(--font-mono);font-size:15px}
  code{background:var(--ground);border:1px solid var(--line);padding:1px 7px;border-radius:6px}
  pre{background:var(--terminal);color:var(--terminal-text);border:0;border-radius:var(--radius-md);padding:16px 18px;font-family:var(--font-mono);font-size:15px;line-height:1.6;white-space:pre-wrap;word-break:break-all;max-height:320px;overflow:auto;margin:14px 0 0}
  .bar{height:14px;background:var(--line);border-radius:999px;overflow:hidden;margin:18px 0 10px}
  .bar i{display:block;height:100%;width:0;background:var(--blue);border-radius:999px;transition:width .3s}
  .stat{font-size:15px;color:var(--gray);line-height:1.6}
  table{border-collapse:collapse;width:100%;font-size:17px}td{padding:12px 12px;border-bottom:1px solid var(--line);vertical-align:top;word-break:break-all;line-height:1.5}td:first-child{color:var(--gray);width:200px;word-break:normal}
  .chk{display:flex;align-items:flex-start;gap:12px;font-size:17px;line-height:1.55;margin-top:14px}
  .chk input{width:22px;height:22px;margin:2px 0 0;accent-color:var(--blue);flex:none}.chk label{margin:0;font-size:17px;color:var(--ink);font-weight:400}
  ul{margin:0;padding-left:22px;font-size:17px;line-height:1.7}
  .cp-footer{max-width:1080px;margin:16px auto 0;padding:28px 24px 36px;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:20px 32px;border-top:1px solid var(--line);font-size:15px;line-height:1.6;color:var(--gray)}
  .cp-w{font-family:"Kanit","Nunito",system-ui,sans-serif;font-weight:700}.cp-t{font-family:"Kanit","Nunito",system-ui,sans-serif;font-weight:300}
  .cp-footer .mark{display:flex;align-items:center;gap:16px;color:var(--ink)}
  .cp-footer .co{font-weight:600;color:var(--ink);font-size:17px}.cp-footer .co-en{font-size:13px;letter-spacing:.08em}
  .cp-footer .cr{text-align:right;line-height:1.7} @media(max-width:720px){.cp-footer .cr{text-align:left}td:first-child{width:130px}}
  .bmc{display:inline-flex;align-items:center;gap:8px;margin-top:8px;padding:8px 16px;border-radius:999px;background:var(--orange);color:#111;font-weight:500;text-decoration:none;font-size:15px}.bmc:hover{filter:brightness(.95);color:#111}
</style>
</head>
<body>
<header class="topbar"><div class="in">
  <div class="brand">
    <a href="https://colorpack.co.th/" target="_blank" rel="noopener" style="color:#fff;display:block;line-height:0;text-decoration:none" title="COLORPACK CREATIONS"><?= cp_logo(48) ?></a>
    <div style="width:2px;height:40px;background:rgba(255,255,255,.2);border-radius:2px"></div>
    <div><div class="t">Joomla Migrate Tool</div><div class="s"><?= t('ย้ายเว็บ Joomla ไป server ใหม่ ·') ?> <?= h($_SERVER['HTTP_HOST']) ?></div></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="pill" style="background:var(--blue);color:#fff">v<?= TOOL_VERSION ?></span>
    <span class="pill" style="background:rgba(255,255,255,.12);color:#fff;gap:8px"><a href="?key=<?= h(urlencode($key)) ?>&amp;lang=th" style="color:<?= $LANG === 'th' ? '#fff' : '#a0a6ad' ?>;text-decoration:none;font-weight:<?= $LANG === 'th' ? '600' : '400' ?>">ไทย</a><span style="color:#5B6572">|</span><a href="?key=<?= h(urlencode($key)) ?>&amp;lang=en" style="color:<?= $LANG === 'en' ? '#fff' : '#a0a6ad' ?>;text-decoration:none;font-weight:<?= $LANG === 'en' ? '600' : '400' ?>">EN</a></span>
    <span class="pill mono" style="background:rgba(255,255,255,.12);color:#fff"><?= h(ROOT) ?></span>
  </div>
</div></header>
<div class="wrap">

<?php if (MIGRATE_KEY === 'changeme123'): ?>
<div class="warn"><?= t('ยังใช้รหัส key ค่าเริ่มต้นอยู่ ควรแก้ค่า') ?> <code>MIGRATE_KEY</code> <?= t('ในไฟล์ก่อนใช้งานจริง และลบไฟล์นี้ทันทีเมื่อเสร็จ') ?></div>
<?php endif; ?>
<?php if (!$cfg): ?>
<div class="warn"><?= t('ไม่พบ') ?> <code>configuration.php</code> <?= t('ในโฟลเดอร์นี้ (') ?><?= h(ROOT) ?><?= t(') ให้อัปโหลดไฟล์เว็บให้ครบก่อน') ?></div>
<?php endif; ?>

<div class="card">
  <h2 data-n="1"><?= t('สภาพแวดล้อม server ใหม่') ?></h2>
  <table>
    <tr><td><?= t('root เว็บ') ?></td><td class="mono"><?= h(ROOT) ?></td></tr>
    <tr><td>PHP</td><td><?= h(PHP_VERSION) ?> · mysqli <?= extension_loaded('mysqli') ? t('<span class=ok>มี</span>') : t('<span class=bad>ไม่มี</span>') ?> · zlib <?= function_exists('gzopen') ? t('<span class=ok>มี</span>') : t('<span class=bad>ไม่มี (เปิด .gz ไม่ได้)</span>') ?> · GD/WebP <?= function_exists('imagewebp') ? t('<span class=ok>มี</span>') : t('<span class=bad>ไม่มี</span>') ?></td></tr>
    <tr><td>open_basedir</td><td class="mono"><?= h($ob ?: t('(ไม่จำกัด)')) ?></td></tr>
    <tr><td><?= t('tmp_path ปัจจุบัน') ?></td><td><span class="mono"><?= h($cfg['tmp_path'] ?? '-') ?></span> <span class="<?= $tmpOk ? 'ok' : 'bad' ?>"><?= h($tmpMsg) ?></span></td></tr>
    <tr><td><?= t('log_path ปัจจุบัน') ?></td><td><span class="mono"><?= h($cfg['log_path'] ?? '-') ?></span> <span class="<?= $logOk ? 'ok' : 'bad' ?>"><?= h($logMsg) ?></span></td></tr>
    <tr><td>.htaccess</td><td><?= $hasHt ? t('<span class=ok>มี</span>') : t('<span class=bad>ไม่มี</span> (ใช้เครื่องมือข้อ 5 สร้างได้)') ?></td></tr>
  </table>
</div>

<div class="card">
  <h2 data-n="2"><?= t('ฐานข้อมูล และ configuration.php') ?></h2>
  <div class="row">
    <div><label>Host</label><input type="text" id="host" value="<?= h($cfg['host'] ?? 'localhost') ?>"></div>
    <div><label>Database</label><input type="text" id="db" value="<?= h($cfg['db'] ?? '') ?>"></div>
    <div><label>User</label><input type="text" id="user" value="<?= h($cfg['user'] ?? '') ?>"></div>
    <div><label>Password</label><input type="password" id="pass" value="<?= h($cfg['password'] ?? '') ?>"></div>
  </div>
  <button onclick="testDb()"><?= t('ทดสอบการเชื่อมต่อ') ?></button>
  <div id="dbres" class="stat" style="margin-top:8px"></div>

  <div class="row">
    <div><label>Table prefix</label>
      <input type="text" id="prefix" list="prefixes" value="<?= h($cfg['dbprefix'] ?? '') ?>"><datalist id="prefixes"></datalist></div>
    <div><label>Session handler</label>
      <select id="session_handler">
        <?php foreach (['database' => t('database (ค่าเริ่มต้น)'), 'file' => 'file', 'none' => 'none'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($cfg['session_handler'] ?? 'database') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label><?= t('tmp_path (ตรวจให้แล้ว)') ?></label><input type="text" id="tmp_path" value="<?= h($defaultTmp) ?>"></div>
    <div><label><?= t('log_path (ตรวจให้แล้ว)') ?></label><input type="text" id="log_path" value="<?= h($defaultLog) ?>"></div>
    <div><label><?= t('live_site (ปกติเว้นว่าง)') ?></label><input type="text" id="live_site" value="<?= h($cfg['live_site'] ?? '') ?>"></div>
  </div>
  <button onclick="saveConfig()"><?= t('บันทึกลง configuration.php') ?></button>
  <div id="cfgres" class="stat" style="margin-top:8px"></div>
</div>

<div class="card">
  <h2 data-n="2.1"><?= t('ตรวจสุขภาพ configuration.php') ?></h2>
  <?php list($cfgIssues, $cfgFixable) = check_config(); ?>
  <div id="cfgissues">
  <?php if (!$cfgIssues): ?><span class="ok"><?= t('✔ ไม่พบความผิดปกติ') ?></span>
  <?php else: ?><ul style="margin:0;padding-left:20px;font-size:13px"><?php foreach ($cfgIssues as $i): ?><li class="bad"><?= $i ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <button class="sec" onclick="checkCfg()"><?= t('ตรวจใหม่') ?></button>
  <button onclick="fixCfg()" <?= $cfgFixable ? '' : 'disabled' ?> id="btnFix"><?= t('แก้อัตโนมัติ (BOM / ช่องว่าง / ?> ท้ายไฟล์ / คีย์ซ้ำ)') ?></button>
  <div id="fixres" class="stat" style="margin-top:8px"></div>
</div>

<div class="card">
  <h2 data-n="2.2"><?= t('คอนฟิกสำหรับช่วงย้ายเว็บ') ?></h2>
  <div class="row">
    <div>
      <div class="chk"><input type="checkbox" id="t_debug" <?= ($cfg['debug'] ?? '0') == '1' ? 'checked' : '' ?>><label for="t_debug" style="margin:0"><b>Debug System</b> <?= t('(แสดง query / profiling ท้ายหน้า)') ?></label></div>
      <div class="chk"><input type="checkbox" id="t_offline" <?= ($cfg['offline'] ?? '0') == '1' ? 'checked' : '' ?>><label for="t_offline" style="margin:0"><b>Site Offline</b> <?= t('(ปิดเว็บชั่วคราวระหว่างย้าย)') ?></label></div>
      <div class="chk"><input type="checkbox" id="t_sef" <?= ($cfg['sef'] ?? '1') == '1' ? 'checked' : '' ?>><label for="t_sef" style="margin:0">SEF URLs</label></div>
      <div class="chk"><input type="checkbox" id="t_sef_rewrite" <?= ($cfg['sef_rewrite'] ?? '0') == '1' ? 'checked' : '' ?>><label for="t_sef_rewrite" style="margin:0"><?= t('URL Rewriting (ต้องมี .htaccess)') ?></label></div>
      <div class="chk"><input type="checkbox" id="t_caching" <?= ($cfg['caching'] ?? '0') != '0' ? 'checked' : '' ?>><label for="t_caching" style="margin:0"><?= t('System Cache (ปิดไว้ระหว่างย้ายจะเห็นผลการแก้ทันที)') ?></label></div>
    </div>
    <div>
      <label>Error Reporting</label>
      <select id="t_error_reporting">
        <?php foreach (['default' => t('default (ตาม php.ini)'), 'none' => t('none (ไม่แสดง)'), 'simple' => 'simple', 'maximum' => t('maximum (แสดงทุก error — ใช้ตอนหาสาเหตุ)'), 'development' => 'development'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($cfg['error_reporting'] ?? 'default') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <label><?= t('ข้อความตอน offline') ?></label>
      <input type="text" id="t_offline_message" value="<?= h($cfg['offline_message'] ?? 'This site is down for maintenance.<br />Please check back again soon.') ?>">
    </div>
  </div>
  <button onclick="saveToggles()"><?= t('บันทึกคอนฟิก') ?></button>
  <button class="sec" onclick="presetDebug()"><?= t('โหมดหาสาเหตุ: debug + maximum') ?></button>
  <button class="sec" onclick="presetLive()"><?= t('โหมดใช้งานจริง: ปิด debug, error=default, เปิดเว็บ') ?></button>
  <div id="togres" class="stat" style="margin-top:8px"></div>
</div>

<div class="card">
  <h2 data-n="3"><?= t('กู้จากแพ็กเกจ (jbackup)') ?></h2>
  <div class="stat"><?= t('แพ็กเกจคือโฟลเดอร์ jbackup-ชื่อ/ ที่สร้างด้วย jbackup.php บน server เดิม มี db.sql.gz, site-partN.zip และ manifest.json ดึงตรงจาก server เดิมผ่าน HTTPS หรืออัปโหลดโฟลเดอร์มาวางที่ root ของเว็บนี้ก็ได้') ?></div>
  <label><?= t('URL ของ jbackup.php บน server เดิม (ใช้ MIGRATE_KEY เดียวกัน)') ?></label>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
    <input type="text" id="rs_source" placeholder="https://old-domain.com/jbackup.php" style="flex:1 1 360px">
    <button class="sec" style="margin-top:0" onclick="rsRemoteList()"><?= t('ดูแพ็กเกจบน server เดิม') ?></button>
  </div>
  <label><?= t('แพ็กเกจ') ?></label>
  <select id="rs_pkg">
    <?php foreach ($rsLocal as $lp): ?>
      <option value="local:<?= h($lp['name']) ?>"><?= h($lp['name']) ?> — <?= t('อยู่บน server นี้แล้ว') ?><?= $lp['manifest'] ? ' · ' . h($lp['manifest']['site']['host'] ?? '') . ' · Joomla ' . h($lp['manifest']['site']['joomla'] ?? '?') : '' ?></option>
    <?php endforeach; ?>
    <?php if (!$rsLocal): ?><option value=""><?= t('ยังไม่มีแพ็กเกจ — ใส่ URL ด้านบนแล้วกด "ดูแพ็กเกจบน server เดิม" หรืออัปโหลดโฟลเดอร์ jbackup-* มาวางแล้วรีเฟรช') ?></option><?php endif; ?>
  </select>
  <div class="chk"><input type="checkbox" id="rs_do_extract" checked><label for="rs_do_extract"><?= t('แตกไฟล์เว็บทับโฟลเดอร์นี้ (configuration.php เดิมจะถูกสำรองเป็น .bak-restore)') ?></label></div>
  <button id="rs_btn" onclick="rsStart(true)"><?= t('ดึงและแตกแพ็กเกจ') ?></button>
  <button class="sec" id="rs_resume" onclick="rsStart(false)"><?= t('ทำต่อจากจุดเดิม') ?></button>
  <button class="sec" id="rs_stop" onclick="rsStop()" disabled><?= t('หยุดชั่วคราว') ?></button>
  <div class="bar"><i id="rs_bar"></i></div>
  <div class="stat" id="rs_prog"><?= t('ยังไม่เริ่ม') ?></div>
  <pre id="rs_log" style="display:none"></pre>
  <div style="margin-top:22px;padding-top:18px;border-top:1px solid var(--line)">
    <div style="font-size:20px;font-weight:500"><?= t('แทนที่โดเมน / path เดิมในฐานข้อมูล') ?></div>
    <div class="stat"><?= t('ทำหลังนำเข้าฐานข้อมูลแล้ว รองรับค่าที่เป็น JSON และ serialized (params ของ Joomla, YOOtheme, Gantry) ไม่ทำให้ข้อมูลพัง') ?></div>
    <div class="row">
      <div><label><?= t('โดเมนเดิม') ?></label><input type="text" id="rp_from1" placeholder="old-domain.com"></div>
      <div><label><?= t('โดเมนใหม่') ?></label><input type="text" id="rp_to1" value="<?= h($_SERVER['HTTP_HOST']) ?>"></div>
      <div><label><?= t('path เดิมของเว็บ') ?></label><input type="text" id="rp_from2" class="mono" placeholder="/home/olduser/domains/old-domain.com/public_html"></div>
      <div><label><?= t('path ใหม่') ?></label><input type="text" id="rp_to2" class="mono" value="<?= h(ROOT) ?>"></div>
    </div>
    <div class="chk"><input type="checkbox" id="rp_dry"><label for="rp_dry"><?= t('ทดสอบก่อน (นับแถวที่จะเปลี่ยน ไม่แก้จริง)') ?></label></div>
    <button onclick="rpStart()" id="rp_btn"><?= t('แทนที่ในฐานข้อมูล') ?></button>
    <div class="stat" id="rp_prog"></div>
    <pre id="rp_log" style="display:none"></pre>
  </div>
</div>

<div class="card">
  <h2 data-n="4"><?= t('นำเข้าฐานข้อมูล (แบ่งรอบ ป้องกัน timeout)') ?></h2>
  <?php if (!$files): ?>
    <div class="warn"><?= t('ไม่พบไฟล์ .sql หรือ .sql.gz ในโฟลเดอร์นี้ ให้อัปโหลดไฟล์ dump มาวางไว้ที่เดียวกับ jmigrate.php แล้วรีเฟรช') ?></div>
  <?php endif; ?>
  <?php $free = @disk_free_space(ROOT) ?: 0; ?>
  <div class="stat"><?= t('พื้นที่ดิสก์ว่าง:') ?> <?= $free ? round($free / 1073741824, 2) . ' GB' : t('ไม่ทราบ') ?>
  <?php $gzWarn = false; foreach ($files as $f => $sz) { if (preg_match('/\.gz$/i', $f)) { $iz = gz_isize(ROOT . '/' . $f); echo ' · ' . h($f) . t(' แตกแล้วประมาณ <b>') . round($iz / 1073741824, 2) . ' GB</b>'; if ($sz > 200 * 1048576) $gzWarn = true; if ($free && $iz > $free) echo t(' <span class=bad>(พื้นที่ไม่พอ!)</span>'); } } ?></div>
  <?php if ($gzWarn): ?>
  <div class="warn" style="margin-top:8px"><?= t('ไฟล์ .gz ใหญ่มาก การแตกไฟล์ผ่านหน้านี้จะช้าลงเรื่อย ๆ ในรอบท้าย ๆ (ต้องอ่านซ้ำจากต้นไฟล์ทุกรอบ) แนะนำให้แตกไฟล์ด้วย') ?> <b><?= t('File Manager ของ DirectAdmin (ปุ่ม Extract)') ?></b> <?= t('หรือ SSH') ?> <code><?= t('gunzip -k ไฟล์.gz') ?></code> <?= t('ให้ได้ไฟล์ .sql ก่อน แล้วค่อยเลือกไฟล์ .sql ที่นี่ ส่วนการนำเข้าไม่มีปัญหาเรื่องขนาด') ?></div>
  <?php endif; ?>
  <label><?= t('ไฟล์ที่จะนำเข้า') ?></label>
  <select id="file">
    <?php foreach ($files as $f => $sz): ?>
      <option value="<?= h($f) ?>" <?= (isset($state['file']) && $state['file'] === $f) ? 'selected' : '' ?>><?= h($f) ?> (<?= round($sz / 1048576, 1) ?> MB)</option>
    <?php endforeach; ?>
  </select>
  <div class="stat" style="margin-top:6px"><?= t('เคล็ดลับ: ติ๊ก dry run แล้วกดเริ่ม จะอ่านไฟล์อย่างเดียว (เร็วมาก) และสรุปว่าตารางไหนมีข้อมูลใหญ่ที่สุด เอาชื่อไปใส่ช่อง "ข้าม" ได้ก่อนนำเข้าจริง') ?></div>
  <label><?= t('ข้ามข้อมูลของตารางที่ชื่อมีคำเหล่านี้ (คั่นด้วย , — สร้างตารางแต่ไม่ใส่ข้อมูล)') ?></label>
  <input type="text" id="skip" value="session, rsseo_error_links, rsseo_logs, rsseo_visitors, rsfirewall_logs, action_logs, _logs">
  <div class="chk"><input type="checkbox" id="ignore" checked><label for="ignore" style="margin:0"><?= t('เจอ error แล้วข้ามไปทำต่อ (ไม่หยุด)') ?></label></div>
  <div class="chk"><input type="checkbox" id="autofix" checked><label for="autofix" style="margin:0"><b><?= t('แก้ error การนำเข้าอัตโนมัติ') ?></b> <?= t('(collation MySQL 8, DEFINER, key ยาวเกิน, row size, duplicate, คำสั่งใหญ่เกิน packet ฯลฯ)') ?></label></div>
  <div class="chk"><input type="checkbox" id="innodb" checked><label for="innodb" style="margin:0"><?= t('แปลงตาราง MyISAM / Aria เป็น') ?> <b>InnoDB</b> <?= t('ระหว่างนำเข้า (ตารางไหนแปลงไม่ได้จะใช้ engine เดิมและแจ้งใน log)') ?></label></div>
  <div class="chk"><input type="checkbox" id="dryrun"><label for="dryrun" style="margin:0"><?= t('ทดสอบอ่านไฟล์อย่างเดียว ไม่รันคำสั่งลงฐานข้อมูล (dry run)') ?></label></div>
  <button id="btnStart" onclick="startImport(true)"><?= t('เริ่มนำเข้าใหม่ตั้งแต่ต้น') ?></button>
  <?php if (!empty($state['file']) && empty($state['done'])): ?>
    <button class="sec" id="btnResume" onclick="startImport(false)"><?= t('ทำต่อจากจุดเดิม (') ?><?= h($state['file']) ?>)</button>
  <?php endif; ?>
  <button class="sec" id="btnStop" onclick="stopImport()" disabled><?= t('หยุดชั่วคราว') ?></button>
  <div class="bar"><i id="barfill"></i></div>
  <div class="stat" id="prog"><?= !empty($state['done']) ? t('นำเข้า ') . h($state['file']) . t(' เสร็จแล้ว (') . (int)$state['queries'] . t(' คำสั่ง)') : t('ยังไม่เริ่ม') ?></div>
  <pre id="log" style="display:none"></pre>
</div>

<div class="card">
  <h2 data-n="5"><?= t('เครื่องมือเสริม') ?></h2>
  <button class="sec" onclick="tool('dirs')"><?= t('สร้างโฟลเดอร์ tmp / logs / cache') ?></button>
  <button class="sec" onclick="tool('htaccess')"><?= t('สร้าง .htaccess จาก htaccess.txt') ?></button>
  <button class="sec" onclick="tool('session')"><?= t('ล้างตาราง session') ?></button>
  <button class="sec" onclick="tool('clearcache')"><?= t('ล้าง cache ของ Joomla') ?></button>
  <button class="sec" onclick="tool('innodb')"><?= t('แปลงตาราง MyISAM ที่มีอยู่ (ตาม prefix) เป็น InnoDB') ?></button>
  <pre id="toolres" style="display:none"></pre>
</div>

<div class="card">
  <h2 data-n="6"><?= t('เสร็จแล้ว') ?></h2>
  <p class="stat"><?= t('เมื่อเว็บใช้งานได้แล้ว ให้ลบเครื่องมือนี้ออกทันที (รวมไฟล์ state และไฟล์สำรอง configuration.php) และอย่าลืมลบไฟล์ .sql ที่อัปโหลดมาด้วย') ?></p>
  <button class="danger" onclick="if(confirm(t('ลบ jmigrate.php ออกจาก server?')))tool('selfdestruct')"><?= t('ลบไฟล์เครื่องมือนี้') ?></button>
</div>
</div>

<footer class="cp-footer">
  <div class="mark">
    <?= cp_logo(40) ?>
    <div>
      <div class="co"><?= t('บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด') ?></div>
      <div class="co-en">COLORPACK CREATION CO., LTD.</div>
    </div>
  </div>
  <div class="cr">
    <div>© <?= date('Y') ?> <?= t('บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด สงวนลิขสิทธิ์') ?></div>
    <div><a href="https://colorpack.co.th/" target="_blank" rel="noopener">colorpack.co.th</a> · Joomla Migrate Tool v<?= TOOL_VERSION ?> · <a href="https://github.com/lungkao/Colorpack-Joomla-Migrate" target="_blank" rel="noopener">GitHub</a></div>
    <div><a class="bmc" href="<?= BMC_URL ?>" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 8h1a4 4 0 1 1 0 8h-1"></path><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"></path><path d="M6 2v2"></path><path d="M10 2v2"></path><path d="M14 2v2"></path></svg>Buy me a coffee</a></div>
  </div>
</footer>

<script>
const KEY = <?= json_encode($key) ?>;
const T = <?= json_encode($LANG === 'en' ? $GLOBALS['JM_EN'] : new stdClass, JSON_UNESCAPED_UNICODE) ?>;
const t = s => (T[s] !== undefined ? T[s] : s);
const $ = id => document.getElementById(id);
const dbData = () => ({host:$('host').value, user:$('user').value, pass:$('pass').value, db:$('db').value, prefix:$('prefix').value});
async function api(action, data) {
  const r = await fetch(location.pathname + '?key=' + encodeURIComponent(KEY) + '&action=' + action, {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data || {})});
  const txt = await r.text();
  try { return JSON.parse(txt); } catch(e) { return {ok:false, msg:t('server ตอบกลับไม่ใช่ JSON (HTTP ') + r.status + '): ' + txt.slice(0,300)}; }
}
const fmt = n => (n/1048576).toFixed(1) + ' MB';

async function testDb() {
  $('dbres').innerHTML = t('กำลังทดสอบ...');
  const r = await api('testdb', dbData());
  if (!r.ok) { $('dbres').innerHTML = '<span class=bad>✘ ' + esc(r.msg) + '</span>'; return; }
  let html = t('<span class=ok>✔ เชื่อมต่อได้</span> MySQL ') + esc(r.version) + ' · max_allowed_packet ' + esc(r.max_allowed_packet);
  const dl = $('prefixes'); dl.innerHTML = '';
  const keys = Object.keys(r.prefixes);
  if (keys.length) {
    html += t('<br>prefix ที่พบ: ') + keys.map(p => '<code>' + esc(p) + '</code> ' + r.prefixes[p].count + t(' ตาราง') + (r.prefixes[p].joomla ? ' (Joomla)' : '')).join(' · ');
    keys.forEach(p => { const o = document.createElement('option'); o.value = p; dl.appendChild(o); });
    const best = keys.find(p => r.prefixes[p].joomla);
    if (best && !$('prefix').value) $('prefix').value = best;
    if (best && $('prefix').value !== best) html += t('<br><span class=bad>prefix ในช่องด้านล่างไม่ตรงกับที่พบในฐานข้อมูล (ควรเป็น ') + esc(best) + ')</span>';
  } else html += t('<br>ฐานข้อมูลยังว่าง (ยังไม่ได้นำเข้า)');
  $('dbres').innerHTML = html;
}
async function saveConfig() {
  const d = dbData();
  d.tmp_path = $('tmp_path').value; d.log_path = $('log_path').value;
  d.session_handler = $('session_handler').value; d.live_site = $('live_site').value;
  const r = await api('saveconfig', d);
  $('cfgres').innerHTML = (r.ok ? '<span class=ok>✔ ' : '<span class=bad>✘ ') + esc(r.msg) + '</span>';
}

async function checkCfg() {
  const r = await api('checkcfg', {});
  renderIssues(r.issues); $('btnFix').disabled = !r.fixable;
}
function renderIssues(issues) {
  $('cfgissues').innerHTML = issues.length ? '<ul style="margin:0;padding-left:20px;font-size:13px">' + issues.map(i => '<li class=bad>' + i + '</li>').join('') + '</ul>' : t('<span class=ok>✔ ไม่พบความผิดปกติ</span>');
}
async function fixCfg() {
  const r = await api('fixcfg', {});
  $('fixres').innerHTML = (r.ok ? '<span class=ok>✔ ' : '<span class=bad>✘ ') + esc(r.msg) + '</span>';
  if (r.issues) renderIssues(r.issues);
}
function toggleData() {
  return {debug: $('t_debug').checked ? 1 : 0, offline: $('t_offline').checked ? 1 : 0, sef: $('t_sef').checked ? 1 : 0,
    sef_rewrite: $('t_sef_rewrite').checked ? 1 : 0, caching: $('t_caching').checked ? 1 : 0,
    error_reporting: $('t_error_reporting').value, offline_message: $('t_offline_message').value};
}
async function saveToggles() {
  const r = await api('savetoggles', toggleData());
  $('togres').innerHTML = (r.ok ? '<span class=ok>✔ ' : '<span class=bad>✘ ') + esc(r.msg) + '</span>';
}
function presetDebug() { $('t_debug').checked = true; $('t_error_reporting').value = 'maximum'; $('t_caching').checked = false; saveToggles(); }
function presetLive() { $('t_debug').checked = false; $('t_error_reporting').value = 'default'; $('t_offline').checked = false; saveToggles(); }

// ---- restore from package
let rsRunning = false;
function rsUi(on) { rsRunning = on; $('rs_btn').disabled = on; $('rs_resume').disabled = on; $('rs_stop').disabled = !on; }
function rsStop() { rsRunning = false; }
function rsLog(s) { const l = $('rs_log'); l.style.display = 'block'; l.textContent += s + '\n'; l.scrollTop = l.scrollHeight; }
async function rsRemoteList() {
  $('rs_prog').textContent = t('กำลังติดต่อ server เดิม...');
  const r = await api('rs_remote_list', {source: $('rs_source').value});
  if (!r.ok) { $('rs_prog').innerHTML = '<span class=bad>' + esc(r.msg) + '</span>'; return; }
  const sel = $('rs_pkg'); [...sel.options].filter(o => o.value.startsWith('remote:')).forEach(o => o.remove());
  r.packages.forEach(p => { const o = document.createElement('option'); o.value = 'remote:' + p.name; o.textContent = p.name + ' — ' + t('บน server เดิม') + ' · ' + fmt(p.size) + (p.done ? '' : ' · ' + t('ยังสร้างไม่เสร็จ')); sel.insertBefore(o, sel.firstChild); });
  if (r.packages.length) { sel.selectedIndex = 0; $('rs_prog').innerHTML = '<span class=ok>✔ ' + t('พบ') + ' ' + r.packages.length + ' ' + t('แพ็กเกจ') + '</span>'; } else $('rs_prog').textContent = t('server เดิมยังไม่มีแพ็กเกจ');
}
async function rsStart(reset) {
  const v = $('rs_pkg').value; if (!v) return;
  const [kind, name] = v.split(/:(.*)/s);
  rsUi(true); if (reset) $('rs_log').textContent = '';
  let ok = true;
  if (kind === 'remote') ok = await rsLoop('rs_pull', {source: $('rs_source').value, name, reset}, s => {
    const pct = s.total ? s.bytes / s.total * 100 : 0; $('rs_bar').style.width = (pct * 0.5).toFixed(1) + '%';
    $('rs_prog').textContent = t('กำลังดึงแพ็กเกจ') + ' ' + fmt(s.bytes) + ' / ' + fmt(s.total) + ' · ' + t('ไฟล์') + ' ' + (s.i + 1) + '/' + s.files.length;
  });
  if (ok && $('rs_do_extract').checked) ok = await rsLoop('rs_extract', {pkg: 'jbackup-' + name, reset}, s => {
    const pct = s.total ? s.files / s.total * 100 : 0; $('rs_bar').style.width = (50 + pct * 0.5).toFixed(1) + '%';
    $('rs_prog').textContent = t('กำลังแตกไฟล์') + ' ' + s.files.toLocaleString() + ' / ' + (s.total || '?') + ' ' + t('ไฟล์') + ' · ' + fmt(s.bytes) + ' · ' + t('ส่วน') + ' ' + (s.pi + 1) + '/' + s.parts.length;
    if (s.done && s.suggest) {
      if (s.suggest.prefix) $('prefix').value = s.suggest.prefix;
      if (s.suggest.old_host) $('rp_from1').value = s.suggest.old_host;
      if (s.suggest.old_root) $('rp_from2').value = s.suggest.old_root;
      if (s.suggest.db_file) { const sel = $('file'); let o = [...sel.options].find(x => x.value === s.suggest.db_file); if (!o) { o = document.createElement('option'); o.value = s.suggest.db_file; o.textContent = s.suggest.db_file; sel.appendChild(o); } sel.value = s.suggest.db_file; }
      if (s.warnings.length) rsLog(t('คำเตือน') + ':\n' + s.warnings.join('\n'));
    }
  });
  if (ok) { $('rs_bar').style.width = '100%'; $('rs_prog').innerHTML = '<span class=ok>✔ ' + t('เสร็จแล้ว') + '</span> — ' + t('ต่อไป: ข้อ 4 นำเข้าฐานข้อมูล (เลือกไฟล์ db.sql.gz ให้แล้ว) → ข้อ 2 บันทึก configuration.php → แทนที่โดเมน/path ด้านล่าง'); }
  rsUi(false);
}
async function rsLoop(action, data, render) {
  let first = true, fails = 0;
  while (rsRunning) {
    const d = Object.assign({}, data, {reset: first && data.reset}); first = false;
    let r; try { r = await api(action, d); } catch (e) { r = {ok:false, msg:'network: ' + e}; }
    if (!r.ok) { if (r.state) render(r.state); if (++fails <= 3) { rsLog(t('รอบนี้ล้มเหลว (') + r.msg + t(') ลองใหม่ครั้งที่ ') + fails); await new Promise(x => setTimeout(x, 3000)); continue; } $('rs_prog').innerHTML = '<span class=bad>' + esc(r.msg) + '</span>'; return false; }
    fails = 0; render(r.state); if (r.state.done) return true;
  }
  return false;
}
async function rpStart() {
  const pairs = [[$('rp_from1').value.trim(), $('rp_to1').value.trim()], [$('rp_from2').value.trim(), $('rp_to2').value.trim()]].filter(p => p[0] && p[0] !== p[1]);
  if (!pairs.length) { $('rp_prog').innerHTML = '<span class=bad>' + t('ไม่มีคู่ข้อความที่จะแทนที่') + '</span>'; return; }
  const dry = $('rp_dry').checked;
  if (!dry && !confirm(t('แทนที่ในฐานข้อมูลจริง? ควรสำรองหรือทดสอบก่อน'))) return;
  $('rp_btn').disabled = true; $('rp_log').textContent = ''; let first = true;
  while (true) {
    const d = Object.assign(dbData(), {pairs, dryrun: dry, reset: first}); first = false;
    const r = await api('rs_replace', d);
    if (!r.ok) { $('rp_prog').innerHTML = '<span class=bad>' + esc(r.msg) + '</span>'; break; }
    const s = r.state; $('rp_prog').textContent = (dry ? t('ทดสอบ') + ': ' : '') + t('ตาราง') + ' ' + s.ti + '/' + s.tables.length + ' · ' + t('แถวที่ตรวจ') + ' ' + s.rows.toLocaleString() + ' · ' + t('แถวที่เปลี่ยน') + ' ' + s.changed.toLocaleString();
    if (s.log.length) { const l = $('rp_log'); l.style.display = 'block'; l.textContent = s.log.join('\n'); }
    if (s.done) { $('rp_prog').innerHTML = '<span class=ok>✔ ' + (dry ? t('ทดสอบเสร็จ จะเปลี่ยน') : t('แทนที่แล้ว')) + ' ' + s.changed.toLocaleString() + ' ' + t('แถว') + '</span>'; break; }
  }
  $('rp_btn').disabled = false;
}

let running = false;
function setUi(on) { running = on; $('btnStart').disabled = on; $('btnStop').disabled = !on; const rb = $('btnResume'); if (rb) rb.disabled = on; }
function stopImport() { running = false; $('prog').textContent += t(' — กำลังหยุดหลังจบรอบนี้...'); }
function logLine(s) { const l = $('log'); l.style.display = 'block'; l.textContent += s + '\n'; l.scrollTop = l.scrollHeight; }

async function startImport(reset) {
  if (!$('file').value) return alert('ไม่มีไฟล์ให้นำเข้า');
  if (reset && !confirm(t('เริ่มนำเข้า ') + $('file').value + t(' ตั้งแต่ต้น?\nตารางที่ชื่อซ้ำในฐานข้อมูลจะถูกแทนที่ตามคำสั่งใน dump'))) return;
  setUi(true); $('log').textContent = '';
  let first = reset, fails = 0;
  while (running) {
    const d = dbData(); d.file = $('file').value; d.skip = $('skip').value; d.ignore = $('ignore').checked; d.dryrun = $('dryrun').checked; d.innodb = $('innodb').checked; d.autofix = $('autofix').checked; d.reset = first;
    first = false;
    let r;
    try { r = await api('import', d); } catch (e) { r = {ok:false, msg:'network: ' + e}; }
    if (!r.ok) {
      if (r.state && r.state.fatal) { logLine(t('หยุดเพราะ error: ') + r.msg); $('prog').innerHTML = t('<span class=bad>หยุดเพราะ error — แก้แล้วกด "ทำต่อจากจุดเดิม" หรือติ๊ก "เจอ error แล้วข้ามไป"</span>'); break; }
      if (++fails <= 3) { logLine(t('รอบนี้ล้มเหลว (') + r.msg + t(') ลองใหม่ครั้งที่ ') + fails); await new Promise(x => setTimeout(x, 2000)); continue; }
      logLine(t('ล้มเหลวติดต่อกัน 3 ครั้ง: ') + r.msg); $('prog').innerHTML = '<span class=bad>' + esc(r.msg) + '</span>'; break;
    }
    fails = 0;
    const s = r.state;
    if (s.phase === 'gunzip') {
      $('barfill').style.width = '0%';
      $('prog').textContent = t('กำลังแตกไฟล์ .gz → ') + s.sql_file + ' : ' + fmt(s.gz_offset) + ' (' + r.elapsed + t('s/รอบ, seek ') + r.seek + 's)';
    } else {
      const pct = s.size ? Math.min(100, s.offset / s.size * 100) : 0;
      $('barfill').style.width = pct.toFixed(1) + '%';
      $('prog').textContent = t('นำเข้า ') + pct.toFixed(1) + '% · ' + fmt(s.offset) + ' / ' + fmt(s.size) + ' · ' + s.queries + t(' คำสั่ง · ข้าม ') + s.skipped + ' · error ' + s.errors.length + ' (' + r.elapsed + t('s/รอบ)');
      if (s.errors.length) { const l = $('log'); l.style.display = 'block'; l.textContent = s.errors.join('\n'); l.scrollTop = l.scrollHeight; }
    }
    if (s.done) {
      $('barfill').style.width = '100%';
      $('prog').innerHTML = '<span class=ok>✔ ' + ($('dryrun').checked ? t('อ่านไฟล์ครบแล้ว (dry run) ') : t('นำเข้าเสร็จแล้ว ')) + s.queries + t(' คำสั่ง · แปลงเป็น InnoDB ') + (s.converted || 0) + t(' ตาราง') + (s.errors.length ? t(' (มี error ') + s.errors.length + t(' รายการ ดูด้านล่าง)') : '') + '</span>' + ($('dryrun').checked ? '' : t(' — กด "ทดสอบการเชื่อมต่อ" ในข้อ 2 เพื่อตรวจ prefix แล้วบันทึก config'));
      const fx = Object.entries(s.fixes || {});
      if (fx.length) logLine(t('การแก้อัตโนมัติที่ใช้:\n') + fx.map(([k,v]) => '  ' + String(v).padStart(5) + ' × ' + k).join('\n'));
      const tb = Object.entries(s.tables || {}).sort((a,b) => b[1]-a[1]).slice(0, 15);
      if (tb.length) logLine(t('ตารางที่มีข้อมูลใหญ่ที่สุด:\n') + tb.map(([t,b]) => '  ' + fmt(b).padStart(10) + '  ' + t).join('\n'));
      break;
    }
  }
  setUi(false);
  const rb = $('btnResume'); if (rb) rb.disabled = false;
}
async function tool(what) {
  const d = dbData(); d.what = what;
  const r = await api('tools', d);
  const p = $('toolres'); p.style.display = 'block'; p.textContent = r.msg;
  if (what === 'selfdestruct' && r.ok) setTimeout(() => location.reload(), 1500);
}
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>
</body></html>
