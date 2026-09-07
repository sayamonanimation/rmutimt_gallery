<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';

function redirectWithQuery(string $path, array $params = []): never
{
    $query = http_build_query($params);
    $location = $path . ($query !== '' ? ('?' . $query) : '');
    header('Location: ' . $location);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithQuery('./index.php', ['error' => 'signin_failed']);
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    redirectWithQuery('./index.php', ['error' => 'invalid_csrf', 'modal' => 'signin']);
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';

if ($action === 'signup') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $studentId === '' || $email === '' || $password === '' || $confirmPassword === '') {
        redirectWithQuery('./index.php', ['error' => 'missing_fields', 'modal' => 'signup']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectWithQuery('./index.php', ['error' => 'invalid_email', 'modal' => 'signup']);
    }
    if (strlen($password) < 8) {
        redirectWithQuery('./index.php', ['error' => 'password_too_short', 'modal' => 'signup']);
    }
    if ($password !== $confirmPassword) {
        redirectWithQuery('./index.php', ['error' => 'password_mismatch', 'modal' => 'signup']);
    }

    try {
        $registrationOpen = true;
        try {
            $settingStmt = $pdo->prepare("SELECT value_text FROM system_settings WHERE key_name = 'student_registration_open' LIMIT 1");
            $settingStmt->execute();
            $settingRow = $settingStmt->fetch();
            if ($settingRow && isset($settingRow['value_text'])) {
                $registrationOpen = (string) $settingRow['value_text'] === '1';
            }
        } catch (Throwable $e) {
            // If settings table is missing/unavailable, keep registration open by default.
            error_log('[AUTH] read registration setting failed: ' . $e->getMessage());
        }

        if (!$registrationOpen) {
            redirectWithQuery('./index.php', ['error' => 'registration_closed', 'modal' => 'signup']);
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetch();
        if ($existing) {
            redirectWithQuery('./index.php', ['error' => 'email_exists', 'modal' => 'signup']);
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO users (name, student_id, email, password, role)
             VALUES (:name, :student_id, :email, :password, 'student')"
        );
        $insert->execute([
            ':name' => $name,
            ':student_id' => $studentId,
            ':email' => $email,
            ':password' => $hashedPassword,
        ]);

        redirectWithQuery('./index.php', ['success' => 'signup_success', 'modal' => 'signin']);
    } catch (Throwable $e) {
        error_log('[AUTH] signup failed: ' . $e->getMessage());
        redirectWithQuery('./index.php', ['error' => 'signup_failed', 'modal' => 'signup']);
    }
}

if ($action === 'signin') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        redirectWithQuery('./index.php', ['error' => 'signin_missing_fields', 'modal' => 'signin']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectWithQuery('./index.php', ['error' => 'signin_invalid_email', 'modal' => 'signin']);
    }

    try {
        $user = null;
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, password, role, avatar_filename FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$user || !isset($user['password']) || !password_verify($password, (string) $user['password'])) {
            redirectWithQuery('./index.php', ['error' => 'signin_invalid_credentials', 'modal' => 'signin']);
        }

        session_regenerate_id(true);
        csrf_rotate_token();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = (string) $user['role'];
        $_SESSION['name'] = (string) $user['name'];
        $_SESSION['user_name'] = (string) $user['name'];
        $avatarFn = trim((string) ($user['avatar_filename'] ?? ''));
        if ($avatarFn !== '') {
            $_SESSION['profile_image'] = '../uploads/admins/' . $avatarFn;
        } else {
            unset($_SESSION['profile_image']);
        }

        if ($_SESSION['role'] === 'admin') {
            header('Location: ./admin/manage_projects.php');
            exit;
        }

        header('Location: ./student/my_projects.php');
        exit;
    } catch (Throwable $e) {
        error_log('[AUTH] signin failed: ' . $e->getMessage());
        redirectWithQuery('./index.php', ['error' => 'signin_failed', 'modal' => 'signin']);
    }
}

redirectWithQuery('./index.php', ['error' => 'signin_failed']);


