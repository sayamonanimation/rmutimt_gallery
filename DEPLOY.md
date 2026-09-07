# คู่มือขึ้นระบบออนไลน์ (Supabase + PHP Host)

ระบบนี้แยกเป็น 2 ส่วน:

| ส่วน | ใช้บริการ | หน้าที่ |
|---|---|---|
| ฐานข้อมูล | **Supabase** (PostgreSQL) | เก็บข้อมูลผู้ใช้ / ผลงาน / อาจารย์ / หมวดหมู่ |
| ตัวเว็บ (PHP) | **Render.com** (หรือที่อื่น) | รันโค้ด PHP |
| ไฟล์ผลงาน (วิดีโอ/ไฟล์แนบ) | **OpenDrive** (เหมือนเดิม) | ไม่มีการเปลี่ยนแปลง |

---

## ขั้นที่ 1 — สร้างฐานข้อมูลบน Supabase

1. สมัคร/เข้าสู่ระบบ https://supabase.com → **New project**
   - ตั้งรหัสผ่านฐานข้อมูล (**Database Password**) แล้วจดไว้
   - เลือก Region ใกล้ไทยที่สุด เช่น `Southeast Asia (Singapore)`
2. เปิดเมนู **SQL Editor** → **New query**
3. เปิดไฟล์ `database_supabase.sql` (อยู่บนเครื่อง local — **ไม่ได้ commit ขึ้น repo** เพราะมี
   bcrypt hash + อีเมลผู้ใช้จริง) → คัดลอกทั้งหมด → วาง → กด **Run**
   - สคริปต์นี้สร้างตารางทั้งหมด + ย้ายข้อมูลเดิม (ผลงาน 20, อาจารย์ 8, ผู้ใช้ 10 ฯลฯ)
   - รันซ้ำได้ (มันจะ DROP แล้วสร้างใหม่ทุกครั้ง)
   - ถ้าต้องการสร้างใหม่จาก MySQL dump:  `python3 tools/convert_mysql_to_pg.py`
   - repo มีเฉพาะ `tools/pg_schema.sql` (โครงสร้างตารางล้วน ไม่มีข้อมูล) ไว้อ้างอิง
4. ตรวจว่าข้อมูลเข้าครบ: ไปที่ **Table Editor** จะเห็นตาราง `projects`, `users`, `advisors` ...

### เอา Connection String มาใช้

1. **Project Settings → Database → Connection string**
2. เลือกแท็บ **URI** และเลือกโหมด **Session pooler** (สำคัญ — พอร์ต `5432`)
   > อย่าใช้ "Transaction pooler" (พอร์ต 6543) เพราะ PDO ของ PHP จะใช้ prepared statement ไม่ได้
3. จะได้สตริงหน้าตาแบบนี้ (แทน `[YOUR-PASSWORD]` ด้วยรหัสผ่านจริง):
   ```
   postgresql://postgres.abcdefgh:MY_PASSWORD@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres
   ```
   เก็บไว้ใช้เป็นค่า `DATABASE_URL` ในขั้นถัดไป

---

## ขั้นที่ 2 — ขึ้นเว็บ PHP บน Render (ฟรี)

Render ใช้ `Dockerfile` ที่อยู่ในโปรเจกต์นี้ (มี PHP 8.2 + `pdo_pgsql` + `gd` + `curl` ครบ)

1. push โค้ดทั้งโฟลเดอร์ขึ้น GitHub (repo ใหม่)
   - ไฟล์ `.gitignore` กันไม่ให้ `.env` และรูปใน `uploads/` หลุดขึ้นไปแล้ว
2. เข้า https://render.com → **New → Web Service** → เลือก repo
3. ตั้งค่า:
   - **Language / Runtime:** `Docker`
   - **Instance Type:** `Free`
4. หัวข้อ **Environment Variables** เพิ่ม:
   | Key | Value |
   |---|---|
   | `DATABASE_URL` | (Session pooler URI จากขั้นที่ 1) |
   | `OD_USERNAME` | อีเมลบัญชี OpenDrive |
   | `OD_PASSWORD` | รหัสผ่านบัญชี OpenDrive |
   | `OD_FOLDER_ID` | Folder ID ปลายทางบน OpenDrive |
5. กด **Create Web Service** → รอ build เสร็จ → เปิด URL `https://xxxx.onrender.com`

> หรือใช้ไฟล์ [`render.yaml`](render.yaml): New → **Blueprint** → เลือก repo แล้วกรอก env ทีหลัง

### ข้อจำกัดของ Render Free
- เว็บจะ "หลับ" หลังไม่มีคนเข้า ~15 นาที ครั้งแรกที่เข้าใหม่จะช้า ~30 วิ
- ดิสก์เป็นแบบชั่วคราว → **รูปโปรไฟล์** ที่อัปโหลด (`uploads/`) จะหายเมื่อ redeploy
  (ไฟล์ผลงานหลักไม่กระทบ เพราะอยู่บน OpenDrive)
  - แก้ได้โดยอัปเกรดเป็น plan `Starter` (~$7/เดือน) แล้วเพิ่ม Persistent Disk mount ที่ `/var/www/html/uploads`

---

## ทางเลือกโฮสอื่น

### Railway (มีเครดิตฟรี ~$5/เดือน)
- New Project → Deploy from GitHub → Railway อ่าน `Dockerfile` เอง
- เพิ่ม Variables ชุดเดียวกับด้านบน

### Shared hosting ที่รองรับ PHP 8.1–8.2 (เช่น Hostinger ~฿70/เดือน)
- ต้องเปิด extension: `pdo_pgsql`, `curl`, `gd`, `fileinfo`
- อัปโหลดไฟล์ในโฟลเดอร์นี้ทั้งหมดขึ้น `public_html/`
- สร้างไฟล์ `.env` (คัดจาก `.env.example`) แล้วเติม `DATABASE_URL`
  ระบบมีตัวโหลด `.env` ให้แล้ว (`config/env.php`) — ไม่ต้องตั้งใน panel ก็ได้
- ตั้งสิทธิ์โฟลเดอร์ `uploads/` และ `storage/temp/` เป็น `755` (หรือ `775`)

---

## ทดสอบหลัง deploy
- เปิดหน้าแรก → การ์ดผลงานขึ้นครบ, กล่องค้นหา/ฟิลเตอร์ทำงาน
- เข้าสู่ระบบด้วยบัญชีแอดมินเดิม (อีเมล `...@rmuti.ac.th`)
- แอดมิน: เพิ่ม/ลบปีการศึกษา, อนุมัติผลงาน, เปิด-ปิดรับสมัคร
- นักศึกษา: สมัครสมาชิก, อัปโหลดผลงานใหม่ (เช็คว่าไฟล์ขึ้น OpenDrive และผลงานบันทึกลง Supabase)

## หมายเหตุการเปลี่ยนแปลงโค้ด (สรุป)
- `config/db.php` — เปลี่ยนจาก MySQL เป็น PostgreSQL (อ่านค่าจาก `DATABASE_URL` / env)
- `config/env.php` — ตัวโหลดไฟล์ `.env` (ใหม่)
- `database_supabase.sql` — schema + ข้อมูลเดิม เวอร์ชัน PostgreSQL (ใหม่)
- ปรับเควรีที่เป็นของ MySQL: `RAND()`→`random()`, `ON DUPLICATE KEY`→`ON CONFLICT`,
  `SHOW TABLES/COLUMNS`→`information_schema`, `LIKE`→`ILIKE` (ค้นหา), `LAST_INSERT_ID`→`RETURNING id`
- ไฟล์ MySQL เดิม (`database.sql`, `rmutimt_gallery.sql`) เก็บไว้อ้างอิงเท่านั้น ไม่ใช้แล้ว
