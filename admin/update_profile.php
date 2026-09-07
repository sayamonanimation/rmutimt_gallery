<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
start_secure_session();

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? null)) {
    header('Location: ./manage_projects.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$fallback = './manage_projects.php';
$referer = $fallback;
$rawRef = $_SERVER['HTTP_REFERER'] ?? '';
if (is_string($rawRef) && $rawRef !== '') {
    $refHost = parse_url($rawRef, PHP_URL_HOST);
    $selfHost = $_SERVER['HTTP_HOST'] ?? '';
    if (is_string($refHost) && $refHost !== '' && is_string($selfHost) && strcasecmp($refHost, $selfHost) === 0) {
        $referer = $rawRef;
    }
}

$file = $_FILES['profile_image'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    header('Location: ' . $referer);
    exit;
}

require_once __DIR__ . '/image_compress.php';

$uploadDir = __DIR__ . '/../uploads/admins/';
$newFilename = saveLocalUploadedImageAsWebp($file, $uploadDir, 'profile_' . $userId . '_', 500);

if ($newFilename === null) {
    header('Location: ' . $referer);
    exit;
}
$destPath = $uploadDir . $newFilename;

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_filename VARCHAR(255)");
} catch (Throwable $e) {
    // column may already exist
}

$oldName = '';
try {
    $q = $pdo->prepare('SELECT avatar_filename FROM users WHERE id = :id AND role = :role LIMIT 1');
    $q->execute([':id' => $userId, ':role' => 'admin']);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $oldName = trim((string) ($row['avatar_filename'] ?? ''));
    }
} catch (Throwable $e) {
    @unlink($destPath);
    header('Location: ' . $referer);
    exit;
}

try {
    $u = $pdo->prepare('UPDATE users SET avatar_filename = :fn WHERE id = :id AND role = :role');
    $u->execute([':fn' => $newFilename, ':id' => $userId, ':role' => 'admin']);
} catch (Throwable $e) {
    @unlink($destPath);
    header('Location: ' . $referer);
    exit;
}

if ($oldName !== '' && $oldName !== $newFilename) {
    $oldPath = $uploadDir . basename($oldName);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

$_SESSION['profile_image'] = '../uploads/admins/' . $newFilename;

header('Location: ' . $referer);
exit;
