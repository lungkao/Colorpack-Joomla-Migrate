# Colorpack Joomla Migrate V1.0.0

**[EN]** Single-file PHP tool for moving a Joomla 3 / 4 / 5 site to a new server.
Fixes `configuration.php` paths, tests the database, imports huge `.sql` / `.sql.gz` dumps in
timeout-proof rounds with automatic error fixing, and ships the small utilities you always need
after a move (`.htaccess`, folders, cache, session table). No dependencies, no installation:
upload → open → migrate → delete.

**[TH]** เครื่องมือย้ายเว็บ Joomla 3 / 4 / 5 ไป server ใหม่แบบไฟล์เดียว
แก้ path ใน `configuration.php` ให้อัตโนมัติ ทดสอบฐานข้อมูล นำเข้าไฟล์ `.sql` / `.sql.gz` ขนาดใหญ่แบบแบ่งรอบไม่ติด timeout
พร้อมระบบแก้ error การนำเข้าอัตโนมัติ และเครื่องมือเสริมที่ต้องใช้หลังย้ายทุกครั้ง (`.htaccess`, โฟลเดอร์, cache, ตาราง session)
ไม่ต้องติดตั้ง — อัปโหลด → เปิด → ย้าย → ลบทิ้ง

**File / ไฟล์:** [`jmigrate.php`](jmigrate.php) (V1.0.0 · requires PHP 7.0+ with `mysqli`, `zlib` for `.gz` / ต้องใช้ PHP 7.0+ พร้อม `mysqli` และ `zlib` สำหรับ `.gz`)

**Interface / หน้าจอ:** ไทย / English — switch in the top bar / สลับได้ที่แถบด้านบน

| ไทย | English |
|-----|---------|
| ![TH](screenshot-th.png) | ![EN](screenshot-en.png) |

---

## วิธีใช้ / Quick start

1. Edit the access key at the top of the file (**required**):
   ```php
   const MIGRATE_KEY = 'changeme123';   // <<< change this
   ```
2. Upload `jmigrate.php` **and your database dump** (`.sql`, `.sql.gz` or a DirectAdmin `.gz` backup)
   into the site root — the same folder as `configuration.php`.
3. Open `https://yoursite.com/jmigrate.php?key=YOUR_KEY`.
4. Work down the page: **1** environment → **2** database & config → **3** import → **4** tools → **5** delete the tool.

> **Delete the tool from the server when you are done** (there is a button for it). It can rewrite
> `configuration.php` and your database.

### TH — ขั้นตอน

1. แก้ `MIGRATE_KEY` ในไฟล์เป็นรหัสของคุณเอง
2. อัปโหลด `jmigrate.php` และไฟล์ dump ฐานข้อมูล ไปไว้ที่ root ของเว็บ (ที่เดียวกับ `configuration.php`)
3. เปิด `https://โดเมน/jmigrate.php?key=รหัสของคุณ`
4. ทำตามลำดับ **1** สภาพแวดล้อม → **2** ฐานข้อมูลและ config → **3** นำเข้า → **4** เครื่องมือเสริม → **5** ลบเครื่องมือ

> ทำเสร็จแล้ว **ลบไฟล์ออกจาก server ทันที** (มีปุ่มให้) เพราะเครื่องมือนี้แก้ `configuration.php` และฐานข้อมูลได้

---

## What it does / ทำอะไรได้บ้าง

**1. Environment check** — site root, PHP version and extensions (`mysqli`, `zlib`, GD/WebP), `open_basedir`,
whether the current `tmp_path` / `log_path` still point at the *old* server, and whether `.htaccess` exists.

**2. Database & configuration.php** — form for host / db / user / password with a connection test that reports
the MySQL version, `max_allowed_packet` and every table prefix found in the database (and warns when the prefix in
`configuration.php` does not match). `tmp_path` / `log_path` are pre-filled with the correct paths for the new server.
Every save backs up the previous file as `configuration.php.bak-<timestamp>`.

**2.1 configuration.php health check** — detects and (where safe) auto-fixes: UTF-8 BOM, whitespace before `<?php`,
trailing `?>` / junk after the class, duplicate keys, unbalanced braces, PHP syntax errors, missing or empty keys,
bad `dbprefix`, short `secret`, `live_site` pointing at another domain, file permissions.

**2.2 Migration config** — Debug, Error Reporting (default / none / simple / maximum / development), Site Offline
(+ message), SEF, URL Rewriting, System Cache, with one-click *troubleshoot* and *production* presets.

**3. Chunked import** — reads the dump statement by statement and works in rounds of ~8 seconds (`STEP_SECONDS`),
remembering its position in `jmigrate-state.json`, so a multi-GB dump never hits a 504 / `max_execution_time`.
Pause and **resume** at any time. `.gz` files are extracted in rounds too (with a guard against duplicate writes).
Options:
- **Skip data** for tables whose name contains given words (tables are created but left empty — ideal for
  `session`, `*_logs`, `rsseo_error_links`, `flexicontent_items_versions`, …)
- **Convert MyISAM / Aria → InnoDB** while importing (falls back per table when InnoDB refuses)
- **Fix import errors automatically** (see below)
- **Dry run** — read only; reports the largest tables so you can decide what to skip
- Progress bar, statements / skipped / converted / fixed / error counters, and a log of every fix applied

**4. Tools** — create `tmp` / `logs` / `cache` folders, create `.htaccess` from `htaccess.txt` (or a basic one),
truncate the session table, clear Joomla cache, convert existing MyISAM tables to InnoDB.

**5. Self-destruct** — removes `jmigrate.php`, the state file and the config backups.


### TH — ทำอะไรได้บ้าง

**1. ตรวจสภาพแวดล้อม** — root ของเว็บ, เวอร์ชัน PHP และ extension (`mysqli`, `zlib`, GD/WebP), `open_basedir`,
เช็คว่า `tmp_path` / `log_path` ปัจจุบันยังชี้ไป server *เก่า* หรือไม่ และมี `.htaccess` หรือไม่

**2. ฐานข้อมูลและ configuration.php** — ฟอร์มกรอก host / db / user / password พร้อมปุ่มทดสอบที่รายงานเวอร์ชัน MySQL,
`max_allowed_packet` และ prefix ตารางทุกชุดที่มีในฐานข้อมูล (เตือนเมื่อ prefix ใน `configuration.php` ไม่ตรง)
ช่อง `tmp_path` / `log_path` เติม path ที่ถูกต้องของ server ใหม่ให้ล่วงหน้า ทุกครั้งที่บันทึกจะสำรองไฟล์เดิมเป็น `configuration.php.bak-<เวลา>`

**2.1 ตรวจสุขภาพ configuration.php** — ตรวจและแก้อัตโนมัติ (เฉพาะที่ปลอดภัย): BOM, ช่องว่างก่อน `<?php`, `?>` ท้ายไฟล์และขยะหลัง class,
คีย์ซ้ำ, วงเล็บไม่สมดุล, PHP syntax error, คีย์หายหรือว่าง, `dbprefix` ผิดรูปแบบ, `secret` สั้น, `live_site` ชี้โดเมนอื่น, permission ของไฟล์

**2.2 คอนฟิกช่วงย้ายเว็บ** — Debug, Error Reporting (default / none / simple / maximum / development), Site Offline (+ ข้อความ),
SEF, URL Rewriting, System Cache พร้อมปุ่มลัด *โหมดหาสาเหตุ* และ *โหมดใช้งานจริง*

**3. นำเข้าแบบแบ่งรอบ** — อ่าน dump ทีละคำสั่ง ทำงานรอบละประมาณ 8 วินาที (`STEP_SECONDS`) และจำตำแหน่งไว้ใน `jmigrate-state.json`
ไฟล์หลาย GB จึงไม่ติด 504 / `max_execution_time` หยุดและ**ทำต่อ**ได้ทุกเมื่อ ไฟล์ `.gz` แตกเป็นรอบเช่นกัน (มีตัวกันเขียนซ้ำ)
ตัวเลือก:
- **ข้ามข้อมูล** ของตารางที่ชื่อมีคำที่กำหนด (สร้างตารางแต่ไม่ใส่ข้อมูล เหมาะกับ `session`, `*_logs`, `rsseo_error_links`, `flexicontent_items_versions`, …)
- **แปลง MyISAM / Aria → InnoDB** ระหว่างนำเข้า (ตารางไหนแปลงไม่ได้จะถอยกลับให้เอง)
- **แก้ error การนำเข้าอัตโนมัติ** (ดูตารางด้านล่าง)
- **Dry run** — อ่านอย่างเดียว แล้วสรุปตารางที่ใหญ่ที่สุดเพื่อตัดสินใจว่าจะข้ามอะไร
- แถบความคืบหน้า, ตัวนับคำสั่ง / ข้าม / แปลง / แก้ / error และ log ของทุกการแก้ที่ใช้

**4. เครื่องมือเสริม** — สร้างโฟลเดอร์ `tmp` / `logs` / `cache`, สร้าง `.htaccess` จาก `htaccess.txt` (หรือแบบพื้นฐาน),
ล้างตาราง session, ล้าง cache ของ Joomla, แปลงตาราง MyISAM ที่มีอยู่เป็น InnoDB

**5. ลบตัวเอง** — ลบ `jmigrate.php`, ไฟล์ state และไฟล์สำรอง config

### Automatic import-error fixing / การแก้ error อัตโนมัติ

| Error | Fix | การแก้ |
|-------|-----|--------|
| MySQL 8 dump on MariaDB / MySQL 5.x (`utf8mb4_0900_*`) | collation rewritten to `utf8mb4_unicode_ci` / `utf8mb4_bin` (pre-emptively) | แทน collation เป็น `utf8mb4_unicode_ci` / `utf8mb4_bin` ล่วงหน้า |
| `DEFINER=` in views / triggers / procedures | removed | ลบออก |
| `SET @@GLOBAL.GTID_PURGED`, `SQL_LOG_BIN` | skipped | ข้าม |
| INSERT larger than `max_allowed_packet` | split into several INSERTs at row boundaries | แบ่งเป็นหลาย INSERT ตามขอบเขตแถว |
| 1273 / 1115 unknown collation or charset | replaced (`unicode_520_ci`, `utf8mb3`, …) | แทนด้วยตัวที่ server รู้จัก |
| 1286 unknown engine | `ENGINE=InnoDB` | เปลี่ยนเป็น InnoDB |
| 1118 row size too large | InnoDB + `ROW_FORMAT=DYNAMIC` → long `varchar` → `TEXT` → MyISAM | ลองตามลำดับจนผ่าน |
| 1071 key too long | `ROW_FORMAT=DYNAMIC` → `(191)` index prefix on long text columns | ใส่ prefix `(191)` ให้ index |
| 1062 duplicate entry | `INSERT IGNORE` | เปลี่ยนเป็น `INSERT IGNORE` |
| 1050 table exists | `CREATE TABLE IF NOT EXISTS` | สร้างเฉพาะเมื่อยังไม่มี |
| 1064 syntax (`DEFINER`, `ALGORITHM`, `VISIBLE`) | offending clause removed | ลบส่วนที่ server ไม่รู้จัก |
| 1227 / 1044 / 1419 privilege errors | privileged `SET` skipped, `DEFINER` removed | ข้าม `SET` พิเศษ / ลบ `DEFINER` |
| 1067 / 1292 invalid default | strict mode off → `0000-00-00` default → `NULL` | ปิด strict mode → เปลี่ยน default เป็น `NULL` |
| 1214 no FULLTEXT support | that table falls back to MyISAM | ตารางนั้นถอยกลับเป็น MyISAM |
| 2006 / 2013 server has gone away | reconnects and splits the INSERT, then continues | เชื่อมต่อใหม่ แบ่ง INSERT แล้วทำต่อ |

### Options (top of the file) / ตัวเลือก (ด้านบนของไฟล์)

| Option | Meaning | ความหมาย |
|--------|---------|----------|
| `MIGRATE_KEY` | Access key, passed as `?key=` (required) | รหัสเข้าใช้ ส่งผ่าน `?key=` (จำเป็น) |
| `STEP_SECONDS` | Seconds of work per round (default 8; lower it if your server times out early) | เวลาทำงานต่อรอบ (ค่าเริ่มต้น 8 ถ้า server timeout ไวให้ลดลง) |
| `GZ_CHUNK` | Upper bound of bytes extracted from a `.gz` per round | เพดานจำนวน byte ที่แตกจาก `.gz` ต่อรอบ |
| `BMC_URL` | Buy-me-a-coffee link shown in the footer | ลิงก์ Buy me a coffee ที่แสดงใน footer |

---

## Notes / ข้อควรรู้

**[EN]**
- Very large `.gz` files: extraction through the page re-reads from the start each round, so for files over a few
  hundred MB it is faster to extract first (DirectAdmin File Manager → Extract, or `gunzip -k file.gz`) and import the `.sql`.
- Dumps that use `DELIMITER` (stored procedures / triggers) are not supported — Joomla dumps normally have none.
- Statements that fail because an earlier `CREATE TABLE` failed show up as repeated `1146` errors; look at the first
  error for that table.
- Never leave `jmigrate.php` or your `.sql` dump in `public_html` after migrating.

**[TH]**
- ไฟล์ `.gz` ใหญ่มาก: การแตกผ่านหน้าเว็บต้องอ่านซ้ำจากต้นไฟล์ทุกรอบ ถ้าไฟล์เกินไม่กี่ร้อย MB ให้แตกก่อน
  (File Manager ของ DirectAdmin → Extract หรือ `gunzip -k ไฟล์.gz`) แล้วนำเข้าไฟล์ `.sql` จะเร็วกว่า
- ไม่รองรับ dump ที่ใช้ `DELIMITER` (stored procedure / trigger) ซึ่ง dump ของ Joomla ปกติไม่มี
- คำสั่งที่พังเพราะ `CREATE TABLE` ก่อนหน้าพังไปแล้ว จะขึ้นเป็น error `1146` ซ้ำ ๆ ให้ดู error แรกของตารางนั้น
- ห้ามทิ้ง `jmigrate.php` หรือไฟล์ `.sql` ไว้ใน `public_html` หลังย้ายเสร็จ

---

## Changelog / บันทึกการแก้ไข

### 1.0.0 — 2026-10-25
**[EN]** First public release.
- configuration.php path auto-detection, health check with safe auto-fix, migration config switches
- Database form with connection test and table-prefix detection
- Chunked, resumable import of `.sql` / `.sql.gz` / DirectAdmin `.gz` with per-round extraction guard
- Automatic import-error fixing (MySQL 8 collations, DEFINER, key length, row size, duplicates, packet size, reconnect)
- MyISAM / Aria → InnoDB conversion during import and for existing tables
- Skip-data list, dry run with largest-table report
- Tools: folders, `.htaccess`, session truncate, cache clear, self-destruct
- Thai / English interface, Colorpack footer

**[TH]** เวอร์ชันแรกที่เผยแพร่
- ตรวจ path ของ configuration.php อัตโนมัติ, ตรวจสุขภาพไฟล์พร้อมแก้อัตโนมัติ, คอนฟิกช่วงย้ายเว็บ
- ฟอร์มฐานข้อมูลพร้อมทดสอบการเชื่อมต่อและหา prefix ตาราง
- นำเข้า `.sql` / `.sql.gz` / `.gz` จาก DirectAdmin แบบแบ่งรอบ ทำต่อได้ มีตัวกันเขียนซ้ำตอนแตกไฟล์
- แก้ error การนำเข้าอัตโนมัติ (collation MySQL 8, DEFINER, key ยาวเกิน, row size, duplicate, packet, เชื่อมต่อใหม่)
- แปลง MyISAM / Aria → InnoDB ทั้งระหว่างนำเข้าและตารางที่มีอยู่
- รายการข้ามข้อมูล, dry run พร้อมสรุปตารางที่ใหญ่ที่สุด
- เครื่องมือเสริม: โฟลเดอร์, `.htaccess`, ล้าง session, ล้าง cache, ลบตัวเอง
- หน้าจอไทย / อังกฤษ, footer ของ Colorpack

---

## Credits / เครดิต

© 2026 บริษัท คัลเลอร์แพค ครีเอชั่น จำกัด (Colorpack Creation Co., Ltd.) · [colorpack.co.th](https://colorpack.co.th/)

If this tool saved you a late night, you can buy me a coffee / ถ้าเครื่องมือนี้ช่วยให้คุณไม่ต้องอดนอน เลี้ยงกาแฟได้ที่นี่

👉&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="https://buymeacoffee.com/cheuachatchai" >![buy_me_a_coffee](https://github.com/conseilgouz/plg_system_cgwebp_j4/assets/19435246/4fda4cb5-64f1-4717-81ae-c71a0fc26c2d)</a>
