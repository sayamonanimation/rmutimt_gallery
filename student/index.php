<?php
require_once __DIR__ . '/../config/session.php';
start_secure_session();

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../index.php');
    exit;
}

// Redirect ไปยังหน้า My Projects ทันที
header('Location: my_projects.php');
exit;
