<?php
declare(strict_types=1);

/**
 * PDO connection bootstrap — PostgreSQL / Supabase.
 *
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   // $pdo is available
 *
 * ตั้งค่าได้ 2 แบบ (ผ่าน environment variable หรือไฟล์ .env):
 *
 *  A) ใช้ connection string เดียว (แนะนำ — คัดลอกจาก Supabase ได้เลย)
 *       DATABASE_URL=postgresql://postgres.xxxx:PASSWORD@aws-0-<region>.pooler.supabase.com:5432/postgres
 *
 *  B) แยกเป็นตัวแปร
 *       DB_HOST=aws-0-<region>.pooler.supabase.com
 *       DB_PORT=5432
 *       DB_NAME=postgres
 *       DB_USER=postgres.xxxx
 *       DB_PASS=your-db-password
 *
 * หมายเหตุ Supabase:
 *  - ใช้ "Session pooler" (พอร์ต 5432) เพราะรองรับ prepared statement ของ PDO
 *    และใช้ได้กับโฮสที่มีแต่ IPv4  (Connection string หาได้ที่
 *    Project Settings -> Database -> Connection string -> "Session pooler")
 *  - บังคับเชื่อมต่อแบบ SSL (sslmode=require) อยู่แล้ว
 */

require_once __DIR__ . '/env.php';

$dbHost = null;
$dbPort = null;
$dbName = null;
$dbUser = null;
$dbPass = null;

$databaseUrl = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL') ?: '';
if ($databaseUrl !== '') {
    $parts = parse_url($databaseUrl);
    if (is_array($parts)) {
        $dbHost = $parts['host'] ?? null;
        $dbPort = isset($parts['port']) ? (string) $parts['port'] : null;
        $dbUser = isset($parts['user']) ? rawurldecode($parts['user']) : null;
        $dbPass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
        $dbName = isset($parts['path']) ? ltrim($parts['path'], '/') : null;
    }
}

$dbHost = $dbHost ?: (getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = $dbPort ?: (getenv('DB_PORT') ?: '5432');
$dbName = $dbName ?: (getenv('DB_NAME') ?: 'postgres');
$dbUser = $dbUser ?: (getenv('DB_USER') ?: 'postgres');
if ($dbPass === null || $dbPass === '') {
    $dbPass = getenv('DB_PASS');
    if ($dbPass === false) {
        $dbPass = '';
    }
}

$sslMode = getenv('DB_SSLMODE') ?: 'require';

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
    $dbHost,
    $dbPort,
    $dbName,
    $sslMode
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    // ให้ session ใช้ UTC เท่ากับพฤติกรรมเดิมของ MySQL dump (time_zone = "+00:00")
    $pdo->exec("SET TIME ZONE 'UTC'");
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[DB] Connection failed: ' . $e->getMessage());
    exit('Database connection failed. Please check configuration.');
}
