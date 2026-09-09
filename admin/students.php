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

$flashError = '';
$flashSuccess = '';
$hasStudentIdColumn = true;

try {
    $columnCheckStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'student_id'");
    $hasStudentIdColumn = (bool) ($columnCheckStmt && $columnCheckStmt->fetch());
} catch (Throwable $e) {
    error_log('[ADMIN_STUDENTS] check student_id column failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'คำขอไม่ถูกต้องหรือโทเค็นหมดอายุ กรุณาลองใหม่อีกครั้ง';
    header('Location: students.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_student') {
    $fullName = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $studentCode = trim((string) ($_POST['student_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($fullName === '' || $email === '' || $password === '' || ($hasStudentIdColumn && $studentCode === '')) {
        $_SESSION['flash_error'] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        header('Location: students.php');
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        header('Location: students.php');
        exit;
    }
    if (mb_strlen($password) < 8) {
        $_SESSION['flash_error'] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        header('Location: students.php');
        exit;
    }

    try {
        $dupEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $dupEmail->execute([':email' => $email]);
        if ($dupEmail->fetch()) {
            $_SESSION['flash_error'] = 'อีเมลนี้ถูกใช้งานแล้ว';
            header('Location: students.php');
            exit;
        }

        if ($hasStudentIdColumn) {
            $dupStudentId = $pdo->prepare('SELECT id FROM users WHERE student_id = :student_id LIMIT 1');
            $dupStudentId->execute([':student_id' => $studentCode]);
            if ($dupStudentId->fetch()) {
                $_SESSION['flash_error'] = 'รหัสนักศึกษานี้ถูกใช้งานแล้ว';
                header('Location: students.php');
                exit;
            }
        }

        if ($hasStudentIdColumn) {
            $insert = $pdo->prepare(
                "INSERT INTO users (name, student_id, email, password, role)
                 VALUES (:name, :student_id, :email, :password, 'student')"
            );
            $insert->execute([
                ':name' => $fullName,
                ':student_id' => $studentCode,
                ':email' => $email,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO users (name, email, password, role)
                 VALUES (:name, :email, :password, 'student')"
            );
            $insert->execute([
                ':name' => $fullName,
                ':email' => $email,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        $_SESSION['flash_success'] = 'เพิ่มบัญชีนักศึกษาเรียบร้อยแล้ว';
        header('Location: students.php');
        exit;
    } catch (Throwable $e) {
        error_log('[ADMIN_STUDENTS] create student failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'ไม่สามารถเพิ่มบัญชีนักศึกษาได้ กรุณาลองใหม่อีกครั้ง';
        header('Location: students.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $fullName = trim((string) ($_POST['edit_full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['edit_email'] ?? '')));
    $studentCode = trim((string) ($_POST['edit_student_code'] ?? ''));
    $password = (string) ($_POST['edit_password'] ?? '');

    if ($studentId <= 0 || $fullName === '' || $email === '') {
        $_SESSION['flash_error'] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        header('Location: students.php');
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        header('Location: students.php');
        exit;
    }

    try {
        $dupEmail = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1");
        $dupEmail->execute([':email' => $email, ':id' => $studentId]);
        if ($dupEmail->fetch()) {
            $_SESSION['flash_error'] = 'อีเมลนี้ถูกใช้งานแล้ว';
            header('Location: students.php');
            exit;
        }

        if ($hasStudentIdColumn && $studentCode !== '') {
            $dupStudentId = $pdo->prepare("SELECT id FROM users WHERE student_id = :student_id AND id <> :id LIMIT 1");
            $dupStudentId->execute([':student_id' => $studentCode, ':id' => $studentId]);
            if ($dupStudentId->fetch()) {
                $_SESSION['flash_error'] = 'รหัสนักศึกษานี้ถูกใช้งานแล้ว';
                header('Location: students.php');
                exit;
            }
        }

        if ($hasStudentIdColumn && $password !== '') {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET name = :name, email = :email, student_id = :student_id, password = :password
                 WHERE id = :id AND role = 'student'"
            );
            $stmt->execute([
                ':name' => $fullName,
                ':email' => $email,
                ':student_id' => $studentCode,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $studentId,
            ]);
        } elseif ($hasStudentIdColumn) {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET name = :name, email = :email, student_id = :student_id
                 WHERE id = :id AND role = 'student'"
            );
            $stmt->execute([
                ':name' => $fullName,
                ':email' => $email,
                ':student_id' => $studentCode,
                ':id' => $studentId,
            ]);
        } elseif ($password !== '') {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET name = :name, email = :email, password = :password
                 WHERE id = :id AND role = 'student'"
            );
            $stmt->execute([
                ':name' => $fullName,
                ':email' => $email,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $studentId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET name = :name, email = :email
                 WHERE id = :id AND role = 'student'"
            );
            $stmt->execute([
                ':name' => $fullName,
                ':email' => $email,
                ':id' => $studentId,
            ]);
        }

        $_SESSION['flash_success'] = 'อัปเดตข้อมูลนักศึกษาเรียบร้อยแล้ว';
        header('Location: students.php');
        exit;
    } catch (Throwable $e) {
        error_log('[ADMIN_STUDENTS] edit student failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'ไม่สามารถอัปเดตข้อมูลนักศึกษาได้ กรุณาลองใหม่อีกครั้ง';
        header('Location: students.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student_id'])) {
    $deleteStudentId = (int) ($_POST['delete_student_id'] ?? 0);
    if ($deleteStudentId <= 0) {
        $_SESSION['flash_error'] = 'คำขอลบไม่ถูกต้อง';
        header('Location: students.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'student'");
        $stmt->execute([':id' => $deleteStudentId]);
        $_SESSION['flash_success'] = 'ลบบัญชีนักศึกษาเรียบร้อยแล้ว';
        header('Location: students.php');
        exit;
    } catch (Throwable $e) {
        error_log('[ADMIN_STUDENTS] delete student failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'ไม่สามารถลบบัญชีนักศึกษาได้ กรุณาลองใหม่อีกครั้ง';
        header('Location: students.php');
        exit;
    }
}

if (isset($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (isset($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$adminName = trim((string) ($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Administrator'));
$adminEmail = '';
try {
    $userStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => (int) $_SESSION['user_id']]);
    $currentAdmin = $userStmt->fetch();
    if ($currentAdmin) {
        $adminEmail = trim((string) ($currentAdmin['email'] ?? ''));
    }
} catch (Throwable $e) {
    error_log('[ADMIN_STUDENTS] load admin failed: ' . $e->getMessage());
}

$avatar = $adminName !== '' ? (function_exists('mb_substr') ? mb_substr($adminName, 0, 1, 'UTF-8') : substr($adminName, 0, 1)) : 'A';
$avatar = strtoupper((string) $avatar);

$search = trim((string) ($_GET['search'] ?? ''));
$searchLower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
$searchCompact = preg_replace('/\s+/u', '', $searchLower);
$searchCompact = is_string($searchCompact) ? $searchCompact : $searchLower;
$students = [];

try {
    $sql = $hasStudentIdColumn
        ? "SELECT id, name, student_id, email, avatar_filename FROM users WHERE role = 'student' ORDER BY id ASC"
        : "SELECT id, name, '' AS student_id, email, avatar_filename FROM users WHERE role = 'student' ORDER BY id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($search !== '') {
        $students = array_values(array_filter($students, static function (array $student) use ($searchLower, $searchCompact): bool {
            $name = (string) ($student['name'] ?? '');
            $studentId = (string) ($student['student_id'] ?? '');
            $email = (string) ($student['email'] ?? '');

            $lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            $lowerStudentId = function_exists('mb_strtolower') ? mb_strtolower($studentId, 'UTF-8') : strtolower($studentId);
            $lowerEmail = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);

            $compactName = preg_replace('/\s+/u', '', $lowerName);
            $compactStudentId = preg_replace('/\s+/u', '', $lowerStudentId);
            $compactEmail = preg_replace('/\s+/u', '', $lowerEmail);

            $compactName = is_string($compactName) ? $compactName : $lowerName;
            $compactStudentId = is_string($compactStudentId) ? $compactStudentId : $lowerStudentId;
            $compactEmail = is_string($compactEmail) ? $compactEmail : $lowerEmail;

            return mb_strpos($lowerName, $searchLower) !== false
                || mb_strpos($lowerStudentId, $searchLower) !== false
                || mb_strpos($lowerEmail, $searchLower) !== false
                || mb_strpos($compactName, $searchCompact) !== false
                || mb_strpos($compactStudentId, $searchCompact) !== false
                || mb_strpos($compactEmail, $searchCompact) !== false;
        }));
    }
} catch (Throwable $e) {
    error_log('[ADMIN_STUDENTS] load students failed: ' . $e->getMessage());
}

$limit = 8;
$totalRecords = count($students);

$searchQuery = isset($_GET['search']) ? '&search=' . urlencode((string) $_GET['search']) : '';

$totalPages = (int) ceil($totalRecords / $limit);
if ($totalPages < 1) {
    $totalPages = 1;
}

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$visibleStudents = array_slice($students, $offset, $limit);
$totalStudents = $totalRecords;

$pageTitle = 'จัดการนักศึกษา';
$pageTitleDataTh = 'จัดการนักศึกษา';
$pageTitleDataEn = 'Manage Students';
$pageSubtitle = 'จัดการข้อมูลนักศึกษาในระบบ';
$pageSubtitleDataTh = 'จัดการข้อมูลนักศึกษาในระบบ';
$pageSubtitleDataEn = 'Manage student accounts in the system';
$actionButtonId = 'open-create-student-modal';
$actionButtonLabel = 'เพิ่มนักศึกษาใหม่';
$actionButtonLabelDataTh = 'เพิ่มนักศึกษาใหม่';
$actionButtonLabelDataEn = 'Add New Student';
$actionButtonIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H3a1 1 0 110-2h6V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Students - RMUTI MT Gallery</title>
  <?php require_once __DIR__ . '/../config/site_theme_head.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/../config/site_tailwind_config.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root { --mt-red: #D32F2F; --mt-red-dark: #B71C1C; --bg-secondary: #F4F5F6; --border: #E0E0E0; --text-muted: #757575; }
    body { font-family: 'DM Sans', sans-serif; background: var(--bg-secondary); }
    .font-display { font-family: 'Cormorant Garamond', serif; }
    .modal-overlay {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, 0.45);
      z-index: 1000;
      padding: 16px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 300ms ease-out, visibility 300ms ease-out;
    }
    .modal-overlay.open {
      visibility: visible;
      pointer-events: auto;
    }
    .modal-overlay.is-visible {
      opacity: 1;
    }
    .modal-panel {
      transition: all 300ms ease-out;
      transform: scale(0.95);
      opacity: 0;
    }
    .modal-overlay.is-visible .modal-panel {
      transform: scale(1);
      opacity: 1;
    }
  </style>
  <?php require_once __DIR__ . '/../config/site_dark_styles.php'; ?>
</head>
<body class="dark:bg-[#121212] dark:text-gray-100">
  <div class="flex h-screen overflow-hidden">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-full overflow-hidden">
      <main class="flex-1 overflow-y-auto p-6 md:p-8">
        <div class="mx-auto max-w-7xl">
          <?php include __DIR__ . '/topbar.php'; ?>
          <?php include __DIR__ . '/flash.php'; ?>

        <form method="get" class="mt-4">
          <label for="students-search" class="sr-only">ค้นหานักศึกษา</label>
          <div class="relative w-full max-w-[460px]">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-[#B4BAC0]">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a.75.75 0 101.06-1.06l-2.25-2.249A6 6 0 009 3zm-4.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" clip-rule="evenodd" /></svg>
            </span>
            <input id="students-search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" type="text" placeholder="ค้นหาด้วยชื่อ, รหัสนักศึกษา, หรืออีเมล..." class="h-10 w-full rounded-lg border border-[#E2E4E8] bg-white pl-9 pr-3 text-xs text-[#444] outline-none placeholder:text-[#B6BBC1] focus:border-[#CDD2D8] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" />
          </div>
        </form>

        <section id="students-list-section" class="mt-4 overflow-hidden rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333]">
          <div class="w-full overflow-x-auto">
          <div class="min-w-[800px]">
          <div class="grid grid-cols-12 gap-3 border-b border-[var(--border)] px-5 py-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">
            <div class="col-span-1">#</div>
            <div class="col-span-1">Avatar</div>
            <div class="col-span-3">Student ID</div>
            <div class="col-span-3">Full Name</div>
            <div class="col-span-3">Email</div>
            <div class="col-span-1 text-right">Actions</div>
          </div>

          <?php if (empty($visibleStudents)): ?>
            <div class="px-5 py-8 text-center text-sm text-[var(--text-muted)]">ไม่พบข้อมูลนักศึกษา</div>
          <?php else: ?>
            <?php foreach ($visibleStudents as $index => $student): ?>
              <?php
                $studentName = (string) ($student['name'] ?? '-');
                $studentEmail = (string) ($student['email'] ?? '-');
                $studentCode = trim((string) ($student['student_id'] ?? ''));
                if ($studentCode === '') {
                    $studentCode = '-';
                }
                $avatarFilename = trim((string) ($student['avatar_filename'] ?? ''));
                $studentAvatarUrl = '';
                if ($avatarFilename !== '') {
                    if (file_exists(__DIR__ . '/../uploads/admins/' . $avatarFilename)) {
                        $studentAvatarUrl = '../uploads/admins/' . rawurlencode($avatarFilename);
                    } elseif (file_exists(__DIR__ . '/../uploads/students/' . $avatarFilename)) {
                        $studentAvatarUrl = '../uploads/students/' . rawurlencode($avatarFilename);
                    } elseif (file_exists(__DIR__ . '/../uploads/avatars/' . $avatarFilename)) {
                        $studentAvatarUrl = '../uploads/avatars/' . rawurlencode($avatarFilename);
                    }
                }
                $studentInitial = $studentName !== '' ? (function_exists('mb_substr') ? mb_substr($studentName, 0, 1, 'UTF-8') : substr($studentName, 0, 1)) : 'S';
                $studentInitial = strtoupper((string) $studentInitial);
              ?>
              <div class="grid grid-cols-12 items-center gap-3 border-b border-[var(--border)] px-5 py-3">
                <div class="col-span-1 text-xs text-[#9AA0A6]"><?= (int) $offset + $index + 1 ?></div>
                <div class="col-span-1">
                  <?php if ($studentAvatarUrl !== ''): ?>
                    <img
                      src="<?= htmlspecialchars($studentAvatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                      alt="<?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?>"
                      class="h-9 w-9 rounded-full object-cover border border-[#E2E8F0] dark:border-gray-700 shadow-sm"
                    />
                  <?php else: ?>
                    <div class="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-tr from-[#D32F2F] to-[#EF4444] text-xs font-bold text-white shadow-sm border border-red-200 dark:border-red-900/40">
                      <?= htmlspecialchars($studentInitial, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="col-span-3 text-sm font-semibold text-[#1E1E1E] dark:text-gray-100"><?= htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="col-span-3 text-sm text-[#1E1E1E] dark:text-gray-100"><?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="col-span-3 text-sm text-[#6B7280] dark:text-gray-300"><?= htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="col-span-1 flex justify-end gap-2">
                  <button type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-muted)] hover:text-[#1E1E1E] dark:bg-transparent dark:border-transparent dark:text-blue-400 dark:hover:bg-blue-400/10 dark:hover:text-blue-300" aria-label="แก้ไขนักศึกษา" onclick='openEditStudentModal(<?= (int) ($student["id"] ?? 0) ?>, <?= json_encode($studentName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($studentCode === '-' ? '' : $studentCode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($studentEmail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg>
                  </button>
                  <button type="button" class="open-delete-student-btn inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" aria-label="ลบนักศึกษา" data-delete-id="<?= (int) ($student['id'] ?? 0) ?>" data-student-name="<?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?>" data-student-code="<?= htmlspecialchars($studentCode, ENT_QUOTES, 'UTF-8') ?>" data-student-email="<?= htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          </div>
          </div>
          <div class="students-pagination flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-3">
            <!-- ส่วนแสดงจำนวนรายการ (ฝั่งซ้าย) -->
            <div class="text-[11px] text-[var(--text-muted)]">
              แสดง <?= $offset + 1 ?>-<?= min($offset + $limit, $totalRecords) ?> จากทั้งหมด <?= $totalRecords ?> รายการ
            </div>

            <!-- ส่วนปุ่มกด (ฝั่งขวา) -->
            <div class="flex flex-wrap items-center gap-2">
              <!-- ปุ่ม Previous -->
              <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $searchQuery ?>" class="inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] bg-white px-3 text-[11px] font-medium text-[#6B7280] transition-colors hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800">Previous</a>
              <?php else: ?>
                <span class="pointer-events-none inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] bg-[#F3F4F6] px-3 text-[11px] font-medium text-[#B0B6BD] dark:bg-[#262626] dark:text-gray-500 dark:border-[#333333]">Previous</span>
              <?php endif; ?>

              <!-- ตัวเลขหน้า (ระบบ Ellipsis) -->
              <?php
              $visiblePages = [];
              for ($i = 1; $i <= $totalPages; $i++) {
                  if ($i === 1 || $i === $totalPages || ($i >= $page - 1 && $i <= $page + 1)) {
                      $visiblePages[] = $i;
                  }
              }
              $visiblePages = array_values(array_unique($visiblePages));
              $lastPage = 0;
              ?>
              <?php foreach ($visiblePages as $i): ?>
                <?php if ($lastPage > 0 && $i - $lastPage > 1): ?>
                  <!-- จุดไข่ปลา (Ellipsis) -->
                  <span class="inline-flex h-8 min-w-[24px] items-end justify-center px-1 text-[14px] font-medium text-[#B0B6BD] tracking-widest pb-1">...</span>
                <?php endif; ?>

                <!-- ปุ่มตัวเลข -->
                <?php if ($i === $page): ?>
                  <span aria-current="page" data-page-number="<?= $i ?>" style="background:#D32F2F;border-color:#D32F2F;color:#FFFFFF;" class="inline-flex h-8 min-w-[32px] items-center justify-center rounded-md border px-2 text-[11px] font-medium shadow-sm"><?= $i ?></span>
                <?php else: ?>
                  <a href="?page=<?= $i ?><?= $searchQuery ?>" data-page-number="<?= $i ?>" class="inline-flex h-8 min-w-[32px] items-center justify-center rounded-md border border-[#DFE2E6] bg-white px-2 text-[11px] font-medium text-[#6B7280] transition-colors hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800"><?= $i ?></a>
                <?php endif; ?>

                <?php $lastPage = $i; ?>
              <?php endforeach; ?>

              <!-- ปุ่ม Next -->
              <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $searchQuery ?>" class="inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] bg-white px-3 text-[11px] font-medium text-[#6B7280] transition-colors hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800">Next</a>
              <?php else: ?>
                <span class="pointer-events-none inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] bg-[#F3F4F6] px-3 text-[11px] font-medium text-[#B0B6BD] dark:bg-[#262626] dark:text-gray-500 dark:border-[#333333]">Next</span>
              <?php endif; ?>
            </div>
          </div>
        </section>
        </div>
      </main>
    </div>
  </div>

  <div id="create-student-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel w-full max-w-[560px] rounded-2xl bg-[#F2F3F4] border-t-4 border-[var(--mt-red)] shadow-[0_24px_60px_rgba(0,0,0,0.35)] overflow-hidden dark:bg-[#1e1e1e] dark:border-[#333333]">
      <div class="flex items-start justify-between border-b border-[#D7D9DB] px-6 py-5 dark:border-[#333333]">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-xl bg-[#FFEDEE] text-[var(--mt-red)] dark:bg-rose-950/40 dark:text-rose-400 grid place-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/><path d="M15.5 7a.75.75 0 01.75.75V9h1.25a.75.75 0 010 1.5h-1.25v1.25a.75.75 0 01-1.5 0V10.5H13.5a.75.75 0 010-1.5h1.25V7.75A.75.75 0 0115.5 7z"/></svg>
          </div>
          <h2 class="font-display text-[30px] leading-none text-[#17191C] dark:text-white">Create New Student Account</h2>
        </div>
        <button type="button" id="close-create-student-modal" class="text-3xl leading-none text-[#9AA0A6] hover:text-[#5F6368] dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
      </div>

      <form method="post" action="./students.php" class="px-6 py-5">
        <input type="hidden" name="action" value="create_student" />
        <?= csrf_input() ?>

        <div class="space-y-4">
          <div>
            <label for="create_student_name" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">FULL NAME</label>
            <div class="relative">
              <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/></svg>
              </span>
              <input id="create_student_name" name="name" type="text" class="h-12 w-full rounded-xl border border-[#DDE1E4] bg-[#F2F3F4] pl-11 pr-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="Enter full name" required />
            </div>
          </div>

          <?php if ($hasStudentIdColumn): ?>
            <div>
              <label for="create_student_id" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">STUDENT ID</label>
              <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V7.414A2 2 0 0017.414 6L14 2.586A2 2 0 0012.586 2H4zm2 4a1 1 0 000 2h8a1 1 0 100-2H6zm0 4a1 1 0 100 2h5a1 1 0 100-2H6z"/></svg>
                </span>
                <input id="create_student_id" name="student_id" type="text" class="h-12 w-full rounded-xl border border-[#DDE1E4] bg-[#F2F3F4] pl-11 pr-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="ระบุรหัสนักศึกษา 12 หลัก" data-th-placeholder="ระบุรหัสนักศึกษา 12 หลัก" data-en-placeholder="Enter 12-digit Student ID" required />
              </div>
            </div>
          <?php endif; ?>

          <div>
            <label for="create_student_email" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">EMAIL</label>
            <div class="relative">
              <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.94 6.34A2 2 0 014.62 5h10.76a2 2 0 011.68 1.34L10 10.28 2.94 6.34z"/><path d="M18 8.12l-7.45 4.16a1 1 0 01-1.1 0L2 8.12V14a2 2 0 002 2h12a2 2 0 002-2V8.12z"/></svg>
              </span>
              <input id="create_student_email" name="email" type="email" class="h-12 w-full rounded-xl border border-[#DDE1E4] bg-[#F2F3F4] pl-11 pr-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="student@rmuti.ac.th" required />
            </div>
          </div>

          <div>
            <label for="create_student_password" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">PASSWORD</label>
            <div class="relative">
              <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 8V6a5 5 0 1110 0v2h1a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1h1zm2 0h6V6a3 3 0 10-6 0v2z" clip-rule="evenodd"/></svg>
              </span>
              <input id="create_student_password" name="password" type="password" minlength="8" class="h-12 w-full rounded-xl border border-[#DDE1E4] bg-[#F2F3F4] pl-11 pr-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="••••••••" required />
            </div>
            <p class="mt-1 text-xs text-[#9AA0A6] dark:text-gray-400">รหัสผ่านอย่างน้อย 8 ตัวอักษร</p>
          </div>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3">
          <button type="button" id="cancel-create-student-modal" class="h-12 rounded-xl border border-[#D5D8DB] bg-white text-[16px] font-semibold text-[#555] hover:bg-[#F8F9FA] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
          <button type="submit" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border-0 bg-[var(--mt-red)] text-[16px] font-semibold text-white hover:bg-[var(--mt-red-dark)]">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/><path d="M15.5 7a.75.75 0 01.75.75V9h1.25a.75.75 0 010 1.5h-1.25v1.25a.75.75 0 01-1.5 0V10.5H13.5a.75.75 0 010-1.5h1.25V7.75A.75.75 0 0115.5 7z"/></svg>
            Create Student
          </button>
        </div>
      </form>
    </div>
  </div>
  <div id="edit-student-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel w-full max-w-[560px] rounded-2xl bg-white border-t-4 border-[var(--mt-red)] shadow-[0_24px_60px_rgba(0,0,0,0.35)] overflow-hidden dark:bg-[#1e1e1e] dark:border-[#333333]">
      <div class="flex items-start justify-between border-b border-[#E5E7EB] px-6 py-5 dark:border-[#333333]">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-xl bg-[#FFEDEE] text-[var(--mt-red)] dark:bg-rose-950/40 dark:text-rose-400 grid place-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg>
          </div>
          <h2 class="font-display text-[28px] leading-none text-[#17191C] dark:text-white">Edit Student Account</h2>
        </div>
        <button type="button" id="close-edit-student-modal" class="text-3xl leading-none text-[#9AA0A6] hover:text-[#5F6368] dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
      </div>

      <form method="post" action="./students.php" class="px-6 py-5">
        <input type="hidden" name="edit_student" value="1" />
        <input type="hidden" name="student_id" id="edit_student_id" />
        <?= csrf_input() ?>
        <div class="space-y-4">
          <div>
            <label for="edit_student_full_name" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">FULL NAME</label>
            <input id="edit_student_full_name" name="edit_full_name" type="text" class="h-12 w-full rounded-xl border border-[#DDE1E4] px-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required />
          </div>
          <?php if ($hasStudentIdColumn): ?>
            <div>
              <label for="edit_student_code" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">STUDENT ID</label>
              <input id="edit_student_code" name="edit_student_code" type="text" class="h-12 w-full rounded-xl border border-[#DDE1E4] px-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="ระบุรหัสนักศึกษา 12 หลัก" data-th-placeholder="ระบุรหัสนักศึกษา 12 หลัก" data-en-placeholder="Enter 12-digit Student ID" />
            </div>
          <?php endif; ?>
          <div>
            <label for="edit_student_email" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">EMAIL</label>
            <input id="edit_student_email" name="edit_email" type="email" class="h-12 w-full rounded-xl border border-[#DDE1E4] px-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required />
          </div>
          <div>
            <label for="edit_student_password" class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">PASSWORD</label>
            <input id="edit_student_password" name="edit_password" type="password" class="h-12 w-full rounded-xl border border-[#DDE1E4] px-4 text-[15px] outline-none focus:border-[var(--mt-red)] focus:ring-2 focus:ring-[rgba(211,47,47,0.12)] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="••••••••" />
          </div>
        </div>
        <div class="mt-6 grid grid-cols-2 gap-3">
          <button type="button" id="cancel-edit-student-modal" class="h-12 rounded-xl border border-[#E0E0E0] bg-white text-[16px] font-semibold text-[#555] hover:bg-[#F8F9FA] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
          <button type="submit" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border-0 bg-[var(--mt-red)] text-[16px] font-semibold text-white hover:bg-[var(--mt-red-dark)]">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.3 7.3a1 1 0 01-1.415 0l-3.3-3.3a1 1 0 111.414-1.414l2.593 2.592 6.593-6.592a1 1 0 011.415 0z" clip-rule="evenodd"/></svg>
            Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
  <div id="delete-student-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel w-full max-w-[420px] rounded-2xl bg-white border-t-4 border-[var(--mt-red)] shadow-[0_24px_60px_rgba(0,0,0,0.35)] overflow-hidden dark:bg-[#1e1e1e] dark:border-[#333333]">
      <div class="flex items-start justify-between border-b border-[#E5E7EB] px-6 py-5 dark:border-[#333333]">
        <div class="flex min-w-0 flex-1 items-start gap-3">
          <div class="h-10 w-10 shrink-0 rounded-xl bg-[#FFEDEE] text-[var(--mt-red)] dark:bg-rose-950/40 dark:text-rose-400 grid place-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.5 2a1 1 0 00-.894.553L7.382 3H5a1 1 0 100 2h.293l.854 10.243A2 2 0 008.14 17h3.72a2 2 0 001.993-1.757L14.707 5H15a1 1 0 100-2h-2.382l-.224-.447A1 1 0 0011.5 2h-3zM9 8a1 1 0 012 0v5a1 1 0 11-2 0V8z" clip-rule="evenodd"/></svg>
          </div>
          <div class="min-w-0">
            <h2 class="font-display text-[24px] leading-none text-[#17191C] dark:text-white">ลบนักศึกษา</h2>
            <p class="mt-1.5 text-sm leading-snug text-[var(--text-muted)] dark:text-gray-400">การลบบัญชีนี้ไม่สามารถย้อนกลับได้ กรุณายืนยันอีกครั้ง</p>
          </div>
        </div>
        <button type="button" id="close-delete-student-modal" class="shrink-0 text-3xl leading-none text-[#9AA0A6] hover:text-[#5F6368] dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
      </div>
      <div class="px-6 py-5">
        <div class="mb-2 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">บัญชีที่เลือก</div>
        <div class="rounded-xl border border-[var(--border)] bg-[#F8F9FA] px-4 py-3 dark:bg-[#262626] dark:border-gray-700">
          <div id="delete_student_display_name" class="truncate text-sm font-semibold text-[#1E1E1E] dark:text-gray-100"></div>
          <div id="delete_student_display_code" class="mt-1 truncate text-sm text-[var(--text-muted)]"></div>
          <div id="delete_student_display_email" class="mt-1 truncate text-sm text-[var(--text-muted)]"></div>
        </div>
        <div class="mt-6 grid grid-cols-2 gap-3">
          <button type="button" id="cancel-delete-student-modal" class="h-12 rounded-xl border border-[#E0E0E0] bg-white text-[16px] font-semibold text-[#555] hover:bg-[#F8F9FA] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">ยกเลิก</button>
          <button type="button" id="confirm-delete-student-btn" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl border-0 bg-[var(--mt-red)] text-[16px] font-semibold text-white transition-all duration-200 hover:-translate-y-[1px] hover:bg-[var(--mt-red-dark)]">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
            ลบถาวร
          </button>
        </div>
      </div>
    </div>
  </div>

  <form id="delete-student-form" method="post" action="./students.php" hidden>
    <?= csrf_input() ?>
    <input type="hidden" name="delete_student_id" id="delete_student_id_input" value="" />
  </form>

  <script>
    (function () {
      const createOverlay = document.getElementById('create-student-modal');
      const editOverlay = document.getElementById('edit-student-modal');
      const deleteOverlay = document.getElementById('delete-student-modal');
      const openCreateBtn = document.getElementById('open-create-student-modal');
      const closeCreateBtn = document.getElementById('close-create-student-modal');
      const cancelCreateBtn = document.getElementById('cancel-create-student-modal');
      const studentsListSection = document.getElementById('students-list-section');
      const deleteForm = document.getElementById('delete-student-form');
      const deleteIdInput = document.getElementById('delete_student_id_input');
      const closeEditBtn = document.getElementById('close-edit-student-modal');
      const cancelEditBtn = document.getElementById('cancel-edit-student-modal');
      const closeDeleteBtn = document.getElementById('close-delete-student-modal');
      const cancelDeleteBtn = document.getElementById('cancel-delete-student-modal');
      const confirmDeleteBtn = document.getElementById('confirm-delete-student-btn');

      function animateOpen(overlay) {
        if (!overlay) return;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () {
          overlay.classList.add('is-visible');
        });
      }
      function animateClose(overlay) {
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        window.setTimeout(function () {
          overlay.classList.remove('open');
          overlay.setAttribute('aria-hidden', 'true');
        }, 300);
      }
      function openCreateModal() {
        if (!createOverlay) return;
        animateOpen(createOverlay);
      }
      function closeCreateModal() {
        if (!createOverlay) return;
        animateClose(createOverlay);
      }
      function openEditModal() {
        if (!editOverlay) return;
        closeCreateModal();
        animateOpen(editOverlay);
      }
      function closeEditModal() {
        if (!editOverlay) return;
        animateClose(editOverlay);
      }
      function openDeleteModal() {
        if (!deleteOverlay) return;
        closeCreateModal();
        closeEditModal();
        animateOpen(deleteOverlay);
      }
      function resetDeleteSubmitButton() {
        if (!(confirmDeleteBtn instanceof HTMLButtonElement)) return;
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.classList.remove('opacity-75', 'cursor-not-allowed');
        confirmDeleteBtn.innerHTML = confirmDeleteBtn.dataset.originalHtml || 'Delete';
      }

      function closeDeleteModal() {
        if (!deleteOverlay) return;
        animateClose(deleteOverlay);
        resetDeleteSubmitButton();
      }

      function showDeleteConfirmModal(id, name, code, email) {
        if (deleteIdInput) deleteIdInput.value = String(id);
        const elName = document.getElementById('delete_student_display_name');
        const elCode = document.getElementById('delete_student_display_code');
        const elEmail = document.getElementById('delete_student_display_email');
        if (elName) elName.textContent = name || '';
        if (elCode) elCode.textContent = (code && code !== '-') ? ('รหัสนักศึกษา: ' + code) : 'รหัสนักศึกษา: -';
        if (elEmail) elEmail.textContent = email || '';
        openDeleteModal();
      }

      if (openCreateBtn) openCreateBtn.addEventListener('click', openCreateModal);
      if (closeCreateBtn) closeCreateBtn.addEventListener('click', closeCreateModal);
      if (cancelCreateBtn) cancelCreateBtn.addEventListener('click', closeCreateModal);
      if (closeEditBtn) closeEditBtn.addEventListener('click', closeEditModal);
      if (cancelEditBtn) cancelEditBtn.addEventListener('click', closeEditModal);
      if (closeDeleteBtn) closeDeleteBtn.addEventListener('click', closeDeleteModal);
      if (cancelDeleteBtn) cancelDeleteBtn.addEventListener('click', closeDeleteModal);
      if (confirmDeleteBtn && deleteForm) {
        if (!confirmDeleteBtn.dataset.originalHtml) {
          confirmDeleteBtn.dataset.originalHtml = confirmDeleteBtn.innerHTML;
        }
        confirmDeleteBtn.addEventListener('click', function () {
          const button = confirmDeleteBtn;
          button.disabled = true;
          button.classList.add('opacity-75', 'cursor-not-allowed');
          button.innerHTML = `
            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Deleting...
          `;
          deleteForm.submit();
        });
      }

      if (createOverlay) {
        createOverlay.addEventListener('click', function (e) {
          if (e.target === createOverlay) closeCreateModal();
        });
      }
      if (editOverlay) {
        editOverlay.addEventListener('click', function (e) {
          if (e.target === editOverlay) closeEditModal();
        });
      }
      if (deleteOverlay) {
        deleteOverlay.addEventListener('click', function (e) {
          if (e.target === deleteOverlay) closeDeleteModal();
        });
      }

      if (studentsListSection) {
        studentsListSection.addEventListener('click', function (e) {
          const btn = e.target.closest('.open-delete-student-btn');
          if (!btn) return;
          e.preventDefault();
          const id = parseInt(btn.getAttribute('data-delete-id') || '0', 10);
          if (!id) return;
          showDeleteConfirmModal(
            id,
            btn.getAttribute('data-student-name') || '',
            btn.getAttribute('data-student-code') || '',
            btn.getAttribute('data-student-email') || ''
          );
        });
      }

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (deleteOverlay && deleteOverlay.style.display === 'flex') {
          closeDeleteModal();
        } else if (createOverlay && createOverlay.style.display === 'flex') {
          closeCreateModal();
        } else if (editOverlay && editOverlay.style.display === 'flex') {
          closeEditModal();
        }
      });

      // Fallback: force active page color from URL query.
      (function syncPaginationActiveState() {
        const paginationRoot = document.querySelector('.students-pagination');
        if (!paginationRoot) return;
        const params = new URLSearchParams(window.location.search);
        const currentPage = parseInt(params.get('page') || '1', 10);
        const pageButtons = paginationRoot.querySelectorAll('[data-page-number]');
        pageButtons.forEach(function (el) {
          const pageNo = parseInt(el.getAttribute('data-page-number') || '0', 10);
          if (pageNo === currentPage) {
            el.setAttribute('aria-current', 'page');
            el.style.backgroundColor = '#D32F2F';
            el.style.borderColor = '#D32F2F';
            el.style.color = '#FFFFFF';
          } else {
            if (el.tagName === 'A') {
              el.removeAttribute('aria-current');
            }
          }
        });
      })();

      window.openEditStudentModal = function (id, name, studentCode, email) {
        const idInput = document.getElementById('edit_student_id');
        const nameInput = document.getElementById('edit_student_full_name');
        const codeInput = document.getElementById('edit_student_code');
        const emailInput = document.getElementById('edit_student_email');
        const passInput = document.getElementById('edit_student_password');

        if (idInput) idInput.value = String(id || 0);
        if (nameInput) nameInput.value = name || '';
        if (codeInput) codeInput.value = studentCode || '';
        if (emailInput) emailInput.value = email || '';
        if (passInput) passInput.value = '';
        openEditModal();
      };
    })();
  </script>
  <?php require_once __DIR__ . '/../config/site_lang_script.php'; ?>
</body>
</html>
