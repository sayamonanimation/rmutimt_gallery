<?php
declare(strict_types=1);

/**
 * PDO connection bootstrap — MySQL / MariaDB.
 *
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   // $pdo is available
 *
 * ตั้งค่าได้ 2 แบบ (ไม่ต้องแก้ไฟล์นี้):
 *
 *  A) สร้างไฟล์ .env ที่รากโปรเจกต์ (คัดจาก .env.example)
 *       DB_HOST=sqlXXX.infinityfree.com
 *       DB_NAME=if0_12345678_gallery
 *       DB_USER=if0_12345678
 *       DB_PASS=your-db-password
 *
 *  B) ตั้ง environment variable บนโฮส (DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS)
 *
 * ค่า default ด้านล่างใช้ตอนรันในเครื่อง (XAMPP/Laragon)
 */

require_once __DIR__ . '/env.php';

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'rmutimt_gallery';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[DB] Connection failed: ' . $e->getMessage());
    exit('Database connection failed. Please check configuration.');
}
