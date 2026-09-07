<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_verify(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $projectId = (int) ($_POST['project_id'] ?? 0);
    $newStatusRaw = strtolower(trim((string) ($_POST['new_status'] ?? 'pending')));

    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid project id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // รองรับ: approved / pending (ถ้ามี rejected ส่งมาก็ถือเป็น pending)
    $allowed = ['approved', 'pending', 'rejected'];
    if (!in_array($newStatusRaw, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newStatus = $newStatusRaw === 'approved' ? 'approved' : 'pending';

    $stmt = $pdo->prepare('UPDATE projects SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $newStatus, ':id' => $projectId]);

    // rowCount อาจเป็น 0 ได้แม้โปรเจกต์มีอยู่ (สถานะเดิมเหมือนกัน)
    if ($stmt->rowCount() <= 0) {
        $checkStmt = $pdo->prepare('SELECT id FROM projects WHERE id = :id LIMIT 1');
        $checkStmt->execute([':id' => $projectId]);
        $exists = $checkStmt->fetchColumn() !== false;
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Project not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $newStatus,
        'message' => $newStatus === 'approved' ? 'อนุมัติโครงงานเรียบร้อยแล้ว' : 'ยกเลิกการอนุมัติโครงงานเรียบร้อยแล้ว'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[update_status] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error'], JSON_UNESCAPED_UNICODE);
    exit;
}

