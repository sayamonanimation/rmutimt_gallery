<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
start_secure_session();

$fileId = trim((string) ($_GET['id'] ?? ''));
if ($fileId === '') {
    http_response_code(400);
    exit('Missing file id');
}

// Legacy Google Drive public thumbnails (no PHP API client required).
if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $fileId) === 1) {
    $size = isset($_GET['sz']) && is_string($_GET['sz']) && preg_match('/^w\d+$/i', $_GET['sz']) === 1 ? $_GET['sz'] : 'w600';
    $target = 'https://drive.google.com/thumbnail?id=' . rawurlencode($fileId) . '&sz=' . $size;
    header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    header('Location: ' . $target, true, 302);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Image not available';
