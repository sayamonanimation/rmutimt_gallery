<?php
declare(strict_types=1);

/**
 * Minimal .env loader (ไม่ต้องใช้ Composer).
 *
 * โหลดไฟล์ .env ที่ราก project แล้วเซ็ตเป็น environment variable
 * เฉพาะคีย์ที่ยังไม่ถูกกำหนดไว้จริง (ค่าจาก host/panel จะชนะไฟล์ .env เสมอ)
 *
 * ใช้กับโฮสที่ตั้ง environment variable ผ่านแผงควบคุมไม่ได้ (เช่น shared hosting)
 * บนโฮสที่ตั้ง env ได้ (Render / Railway) ไม่ต้องมีไฟล์ .env ก็ทำงานได้ปกติ
 */

if (!function_exists('rmutimt_env')) {
    /**
     * อ่านค่า environment variable แบบ fallback หลายทาง
     *
     * บางโฮส (เช่น InfinityFree) ปิดผลของ putenv() ไว้ (เพื่อความปลอดภัยของ shared hosting)
     * ทำให้ getenv() คืนค่า false แม้จะเรียก putenv() ไปแล้วในโค้ดเดียวกัน
     * ฟังก์ชันนี้จึงเช็ค $_ENV / $_SERVER เป็นสำรองด้วย
     */
    function rmutimt_env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        return $default;
    }
}

if (!function_exists('rmutimt_load_env')) {
    function rmutimt_load_env(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            // ตัด quote ครอบค่า (ถ้ามี)
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // ถ้ามีค่าจริงจาก environment อยู่แล้ว ไม่ทับ
            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

rmutimt_load_env(dirname(__DIR__) . '/.env');
