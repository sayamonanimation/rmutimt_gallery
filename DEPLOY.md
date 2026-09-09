# คู่มือขึ้นเว็บออนไลน์ฟรี (MySQL + Shared Host)

ระบบนี้ใช้ **PHP + MySQL** และเก็บไฟล์ผลงานไว้บน **OpenDrive**
คู่มือนี้ใช้ **InfinityFree** (โฮสฟรี รองรับ PHP 8.2 + MySQL + ให้โดเมนฟรี `*.rf.gd` / `*.great-site.net`)

> โฮสฟรีอื่นที่ใช้ขั้นตอนคล้ายกัน: **000webhost, AwardSpace, ByetHost**
> ถ้าอยากได้เสถียร/เร็วกว่า: **Hostinger** (~฿79/เดือน) ขั้นตอนเหมือนกันเป๊ะ

---

## ภาพรวม 4 ขั้น

1. สมัคร InfinityFree + สร้าง MySQL database
2. อัปโหลดไฟล์เว็บเข้า `htdocs/`
3. Import ข้อมูลเดิม (`rmutimt_gallery.sql`) ผ่าน phpMyAdmin
4. สร้างไฟล์ `.env` ใส่รหัสฐานข้อมูล + OpenDrive

---

## ขั้นที่ 1 — สมัคร + สร้างฐานข้อมูล

1. สมัคร https://infinityfree.com (ฟรี) → ยืนยันอีเมล
2. **Create Account** → เลือกโดเมนฟรี (เช่น `rmutimt-gallery.rf.gd`) → รอ ~5 นาทีให้ระบบเตรียม
3. เข้า **Control Panel** ของ account นั้น → **MySQL Databases**
4. สร้าง database เช่นชื่อ `rmutimt_gallery` → กด Create
5. จดค่าที่ได้ (จะใช้ในขั้นที่ 4):

   | ค่า | ตัวอย่าง |
   |---|---|
   | MySQL Host Name | `sqlXXX.infinityfree.com` |
   | MySQL Database Name | `if0_00000000_rmutimt_gallery` |
   | MySQL User Name | `if0_00000000` |
   | MySQL Password | (รหัสเดียวกับตอนล็อกอิน Control Panel) |

---

## ขั้นที่ 2 — อัปโหลดไฟล์เว็บ

1. ใน Control Panel → **Online File Manager** (หรือใช้ FTP: ค่าอยู่ในหน้า "FTP Accounts")
2. เข้าโฟลเดอร์ **`htdocs`** → ลบไฟล์ `index2.html` ที่มีมาให้ทิ้ง
3. อัปโหลดไฟล์ **ทั้งหมดในโฟลเดอร์นี้** เข้าไปใน `htdocs/`
   - วิธีเร็ว: แตกไฟล์ `rmutimt_gallery.rar` แล้ว zip เฉพาะ "ข้างใน" โฟลเดอร์ → อัปโหลด .zip → กด Extract ใน File Manager
   - ต้องได้โครงแบบนี้: `htdocs/index.php`, `htdocs/config/`, `htdocs/admin/` ...
4. ตรวจว่าโฟลเดอร์เหล่านี้มีอยู่และเขียนได้: `uploads/` (+ `admins`, `advisors`, `authors`), `storage/temp/`
   - InfinityFree ปกติให้สิทธิ์เขียนอยู่แล้ว ถ้าไม่ ให้คลิกขวาโฟลเดอร์ → Permissions → 755

---

## ขั้นที่ 3 — Import ข้อมูลเดิม

1. Control Panel → **phpMyAdmin** → เลือก database `if0_00000000_rmutimt_gallery` ทางซ้าย
2. แท็บ **Import** → **Choose File** → เลือก `rmutimt_gallery.sql`
   (ไฟล์นี้อยู่ที่ `~/Downloads/rmutimt_gallery/rmutimt_gallery.sql`)
3. กด **Go** → รอจนขึ้น "Import has been successfully finished"
4. ควรเห็นตาราง: `academic_years`, `advisors`, `categories`, `projects`, `project_members`, `system_settings`, `users`

> ไฟล์ `.sql` ใหญ่เกินลิมิต? แตกไฟล์เป็นตาราง ๆ หรือใช้เครื่องมือ **BigDump** (InfinityFree รองรับ)

---

## ขั้นที่ 4 — ตั้งค่า `.env`

1. ใน File Manager → เข้า `htdocs/` → **New File** ชื่อ `.env`
2. ใส่เนื้อหานี้ (แก้ค่าตามขั้นที่ 1 + OpenDrive):

   ```
   DB_HOST=sqlXXX.infinityfree.com
   DB_PORT=3306
   DB_NAME=if0_00000000_rmutimt_gallery
   DB_USER=if0_00000000
   DB_PASS=รหัสผ่านฐานข้อมูล

   OD_USERNAME=rmuticlass@gmail.com
   OD_PASSWORD=รหัสผ่าน-opendrive
   OD_FOLDER_ID=folder-id-ปลายทาง
   OD_API_BASE=https://dev.opendrive.com/api/v1
   ```

   > **Folder ID ของ OpenDrive**: ล็อกอิน opendrive.com → เข้าโฟลเดอร์ปลายทาง →
   > ดู URL `.../folders/XXXXXXXX` — ส่วน `XXXXXXXX` คือ `OD_FOLDER_ID`

3. เซฟ → ไฟล์ `.htaccess` ที่มากับโปรเจกต์กันไม่ให้คนอื่นเปิด `.env` ผ่านเบราว์เซอร์อยู่แล้ว

---

## เสร็จ — ทดสอบ

เปิด `https://rmutimt-gallery.rf.gd`

- หน้าแรกมีการ์ดผลงานขึ้นครบ, ค้นหา/ฟิลเตอร์ทำงาน
- เข้าสู่ระบบด้วยบัญชีแอดมินเดิม (อีเมล `...@rmuti.ac.th` — ใช้รหัสผ่านเดิมของคุณ)
- ลองอัปโหลดผลงานใหม่ (ไฟล์ควรขึ้น OpenDrive และบันทึกลง MySQL)

### ⚠️ ข้อจำกัดของ InfinityFree ที่ควรรู้
- ครั้งแรกที่เปิดเว็บ จะมีหน้า "กำลังตรวจสอบเบราว์เซอร์" ~5 วินาที (ระบบกันบอท) — ปกติ
- บางครั้ง **AJAX/ค้นหาอัตโนมัติ** อาจไม่ทำงานในช่วงแรก ให้รีเฟรชหน้า 1 ครั้ง
- จำกัดขนาดอัปโหลด ~10MB/ไฟล์ (ไฟล์ผลงานใหญ่ผ่าน OpenDrive อยู่แล้ว จึงไม่กระทบ)
- ถ้าเจอปัญหา AJAX บ่อย → ย้ายไป **Hostinger** (~฿79/เดือน) หรือ **000webhost** ไม่มีปัญหานี้

### ปัญหาที่พบบ่อย
| อาการ | แก้ |
|---|---|
| `Database connection failed` | เช็คค่าใน `.env` โดยเฉพาะ `DB_HOST` (ต้องเป็น `sqlXXX...` ไม่ใช่ `localhost`) |
| หน้าเว็บขาว/500 | เปิด `.htaccess` ลองลบบล็อก `php_value` ออก (บางโฮสไม่รองรับ) |
| อัปโหลดรูปไม่ได้ | ตั้ง permission โฟลเดอร์ `uploads/` เป็น 755 หรือ 777 |
| อัปโหลดไฟล์ใหญ่ไม่ผ่าน | โฮสฟรีจำกัด ~10MB ต่อไฟล์ — ไฟล์ผลงานใหญ่ให้ผ่าน OpenDrive (ระบบทำอยู่แล้ว) |

---

## หมายเหตุ
- โค้ดชุดนี้ (branch `main`) เป็นเวอร์ชัน **MySQL** (เหมือนต้นฉบับ) — creds ทั้งหมดอ่านจาก `.env` ไม่มี hardcode
- เวอร์ชัน PostgreSQL/Supabase อยู่ที่ branch **`supabase-postgres`**
- ไฟล์ `rmutimt_gallery.sql` / `.env` ไม่ถูก push ขึ้น GitHub (อยู่ใน `.gitignore`)
