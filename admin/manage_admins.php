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
require_once __DIR__ . '/image_compress.php';

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_filename VARCHAR(255)");
} catch (Throwable $e) { /* Ignore if exists */ }

$flashError = '';
$flashSuccess = '';

if (isset($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if (isset($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'คำขอไม่ถูกต้องหรือโทเค็นหมดอายุ กรุณาลองใหม่อีกครั้ง';
    header('Location: manage_admins.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_admin') {
    $fullName = trim((string) ($_POST['name'] ?? $_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    // จัดการอัปโหลดไฟล์รูปภาพ
    $avatarFilename = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $avatarFilename = saveLocalUploadedImageAsWebp($_FILES['avatar'], __DIR__ . '/../uploads/admins/', 'admin_', 500);
    }

    if ($fullName === '' || $email === '' || $password === '') {
        $flashError = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'รูปแบบอีเมลไม่ถูกต้อง';
    } else {
        try {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $flashError = 'อีเมลนี้ถูกใช้งานแล้ว';
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO users (name, email, avatar_filename, password, role)
                     VALUES (:name, :email, :avatar_filename, :password, 'admin')"
                );
                $insert->execute([
                    ':name' => $fullName,
                    ':email' => $email,
                    ':avatar_filename' => $avatarFilename,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $_SESSION['flash_success'] = 'สร้างบัญชีแอดมินเรียบร้อยแล้ว';
                header('Location: manage_admins.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[MANAGE_ADMINS] create admin failed: ' . $e->getMessage());
            $flashError = 'ไม่สามารถสร้างบัญชีแอดมินได้ กรุณาลองใหม่อีกครั้ง';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_admin'])) {
    $adminId = (int) ($_POST['admin_id'] ?? 0);
    $fullName = trim((string) ($_POST['edit_full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['edit_email'] ?? '')));
    $password = (string) ($_POST['edit_password'] ?? '');

    if ($adminId <= 0 || $fullName === '' || $email === '') {
        $_SESSION['flash_error'] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        header('Location: manage_admins.php');
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        header('Location: manage_admins.php');
        exit;
    }

    // จัดการอัปโหลดไฟล์รูปภาพ
    $avatarFilename = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $avatarFilename = saveLocalUploadedImageAsWebp($_FILES['avatar'], __DIR__ . '/../uploads/admins/', 'admin_', 500);
    }

    try {
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $dup->execute([':email' => $email, ':id' => $adminId]);
        if ($dup->fetch()) {
            $_SESSION['flash_error'] = 'อีเมลนี้ถูกใช้งานแล้ว';
            header('Location: manage_admins.php');
            exit;
        }

        if ($password !== '') {
            if ($avatarFilename !== null) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, email = :email, avatar_filename = :avatar_filename, password = :password
                     WHERE id = :id AND role = 'admin'"
                );
                $stmt->execute([
                    ':name' => $fullName,
                    ':email' => $email,
                    ':avatar_filename' => $avatarFilename,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => $adminId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, email = :email, password = :password
                     WHERE id = :id AND role = 'admin'"
                );
                $stmt->execute([
                    ':name' => $fullName,
                    ':email' => $email,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => $adminId,
                ]);
            }
        } else {
            if ($avatarFilename !== null) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, email = :email, avatar_filename = :avatar_filename
                     WHERE id = :id AND role = 'admin'"
                );
                $stmt->execute([
                    ':name' => $fullName,
                    ':email' => $email,
                    ':avatar_filename' => $avatarFilename,
                    ':id' => $adminId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, email = :email
                     WHERE id = :id AND role = 'admin'"
                );
                $stmt->execute([
                    ':name' => $fullName,
                    ':email' => $email,
                    ':id' => $adminId,
                ]);
            }
        }

        $_SESSION['flash_success'] = 'อัปเดตข้อมูลแอดมินเรียบร้อยแล้ว';
        header('Location: manage_admins.php');
        exit;
    } catch (Throwable $e) {
        error_log('[MANAGE_ADMINS] edit admin failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'ไม่สามารถอัปเดตข้อมูลแอดมินได้ กรุณาลองใหม่อีกครั้ง';
        header('Location: manage_admins.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) ($_POST['delete_id'] ?? 0);
    if ($deleteId <= 0) {
        $_SESSION['flash_error'] = 'คำขอลบไม่ถูกต้อง';
        header('Location: manage_admins.php');
        exit;
    }
    if ($deleteId === (int) $_SESSION['user_id']) {
        $_SESSION['flash_error'] = 'ไม่สามารถลบบัญชีของตัวเองขณะล็อกอินอยู่ได้';
        header('Location: manage_admins.php');
        exit;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'admin'");
        $stmt->execute([':id' => $deleteId]);
        $_SESSION['flash_success'] = 'ลบบัญชีแอดมินเรียบร้อยแล้ว';
        header('Location: manage_admins.php');
        exit;
    } catch (Throwable $e) {
        error_log('[MANAGE_ADMINS] delete admin failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'ไม่สามารถลบบัญชีแอดมินได้ กรุณาลองใหม่อีกครั้ง';
        header('Location: manage_admins.php');
        exit;
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$searchLower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
$searchCompact = preg_replace('/\s+/u', '', $searchLower);
$searchCompact = is_string($searchCompact) ? $searchCompact : $searchLower;

$admins = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, email, avatar_filename FROM users WHERE role = 'admin' ORDER BY id DESC");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($search !== '') {
        $admins = array_values(array_filter($admins, static function (array $admin) use ($searchLower, $searchCompact): bool {
            $name = (string) ($admin['name'] ?? '');
            $email = (string) ($admin['email'] ?? '');

            $lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            $lowerEmail = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);

            $compactName = preg_replace('/\s+/u', '', $lowerName);
            $compactEmail = preg_replace('/\s+/u', '', $lowerEmail);
            $compactName = is_string($compactName) ? $compactName : $lowerName;
            $compactEmail = is_string($compactEmail) ? $compactEmail : $lowerEmail;

            return mb_strpos($lowerName, $searchLower) !== false
                || mb_strpos($lowerEmail, $searchLower) !== false
                || mb_strpos($compactName, $searchCompact) !== false
                || mb_strpos($compactEmail, $searchCompact) !== false;
        }));
    }
} catch (Throwable $e) {
    error_log('[MANAGE_ADMINS] select admins failed: ' . $e->getMessage());
}

$perPage = 8;
$totalAdmins = count($admins);
$totalPages = $totalAdmins > 0 ? (int) ceil($totalAdmins / $perPage) : 1;
$currentPage = (int) ($_GET['page'] ?? 1);
if ($currentPage < 1) {
    $currentPage = 1;
}
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;
$visibleAdmins = array_slice($admins, $offset, $perPage);
$showFrom = $totalAdmins > 0 ? $offset + 1 : 0;
$showTo = $totalAdmins > 0 ? min($offset + $perPage, $totalAdmins) : 0;

$adminName = trim((string) ($_SESSION['user_name'] ?? 'Administrator'));
$adminEmail = '';
try {
    $s = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $s->execute([':id' => (int) $_SESSION['user_id']]);
    $m = $s->fetch();
    if ($m) {
        $adminEmail = (string) ($m['email'] ?? '');
    }
} catch (Throwable $e) {
    error_log('[MANAGE_ADMINS] load current admin failed: ' . $e->getMessage());
}
$adminAvatar = $adminName !== '' ? (function_exists('mb_substr') ? mb_substr($adminName, 0, 1, 'UTF-8') : substr($adminName, 0, 1)) : 'A';
$adminAvatar = strtoupper((string) $adminAvatar);
$openModalOnLoad = $flashError !== '';

$pageTitle = 'จัดการผู้ดูแลระบบ';
$pageTitleDataTh = 'จัดการผู้ดูแลระบบ';
$pageTitleDataEn = 'Manage Administrators';
$pageSubtitle = 'จัดการข้อมูลผู้ดูแลระบบ';
$pageSubtitleDataTh = 'จัดการข้อมูลผู้ดูแลระบบ';
$pageSubtitleDataEn = 'Manage administrator accounts';
$actionButtonId = 'open-create-admin-modal';
$actionButtonLabel = 'เพิ่มผู้ดูแลใหม่';
$actionButtonLabelDataTh = 'เพิ่มผู้ดูแลใหม่';
$actionButtonLabelDataEn = 'Add New Admin';
$actionButtonIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a1 1 0 011-1h5V4a1 1 0 112 0v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Administrators - RMUTI MT Gallery</title>
  <?php require_once __DIR__ . '/../config/site_theme_head.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/../config/site_tailwind_config.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root { --mt-red:#D32F2F; --mt-red-dark:#B71C1C; --bg-secondary:#F4F5F6; --text-muted:#757575; --border:#E0E0E0; }
    body { font-family:'DM Sans',sans-serif; background:var(--bg-secondary); color:#121212; }
    .font-display { font-family:'Cormorant Garamond',serif; }
    .input { width:100%; height:48px; border:1px solid #DDE1E4; border-radius:14px; background:#FFF; padding:0 14px 0 44px; font-size:15px; color:#222; outline:none; }
    .input:focus { border-color:var(--mt-red); box-shadow:0 0 0 3px rgba(211,47,47,.12); }
    .modal-overlay {
      position:fixed; inset:0; background:rgba(0,0,0,.48);
      display:flex; align-items:center; justify-content:center; z-index:1000; padding:16px;
      opacity:0; visibility:hidden; pointer-events:none;
      transition:opacity 300ms ease-out, visibility 300ms ease-out;
    }
    .modal-overlay.open { visibility:visible; pointer-events:auto; }
    .modal-overlay.is-visible { opacity:1; }
    .modal-panel { transition:all 300ms ease-out; transform:scale(.95); opacity:0; }
    .modal-overlay.is-visible .modal-panel { transform:scale(1); opacity:1; }
    #delete-admin-modal { z-index:1100; }
    .admin-modal-title { font-family:'Cormorant Garamond',serif; font-size:26px; line-height:1; font-weight:600; color:#17191C; }
    .admin-modal-label { margin-bottom:8px; display:block; font-size:12px; font-weight:700; letter-spacing:.08em; color:#9AA0A6; }
    .admin-modal-subtitle { font-size:14px; font-weight:600; color:#9AA0A6; letter-spacing:.05em; }
    .admin-modal-hint { margin-top:8px; font-size:13px; color:#B4B7BA; font-weight:500; }
    .avatar-upload-circle { width:120px; height:120px; }
    .avatar-upload-camera { width:34px; height:34px; bottom:8px; right:8px; }
    .admin-modal-btn { font-size:18px; font-weight:700; line-height:1; font-family:'DM Sans',sans-serif; }
  </style>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
  <?php require_once __DIR__ . '/../config/site_dark_styles.php'; ?>
</head>
<body data-open-modal="<?= $openModalOnLoad ? '1' : '0' ?>" class="dark:bg-[#121212] dark:text-gray-100">
  <div class="flex h-screen overflow-hidden">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-full overflow-hidden">
      <main class="flex-1 overflow-y-auto p-6 md:p-8">
        <div class="mx-auto max-w-7xl">
          <?php include __DIR__ . '/topbar.php'; ?>
          <?php include __DIR__ . '/flash.php'; ?>

        <form method="get" class="mt-4">
          <label for="admins-search" class="sr-only">ค้นหาผู้ดูแลระบบ</label>
          <div class="relative w-full max-w-[460px]">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-[#B4BAC0]">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a.75.75 0 101.06-1.06l-2.25-2.249A6 6 0 009 3zm-4.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" clip-rule="evenodd" /></svg>
            </span>
            <input id="admins-search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" type="text" placeholder="ค้นหาด้วยชื่อผู้ดูแลระบบ หรืออีเมล..." class="h-10 w-full rounded-lg border border-[#E2E4E8] bg-white pl-9 pr-3 text-xs text-[#444] outline-none placeholder:text-[#B6BBC1] focus:border-[#CDD2D8] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" />
          </div>
        </form>

        <section id="admin-list-section" class="mt-6 overflow-hidden rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333]">
          <div class="w-full overflow-x-auto">
          <div class="min-w-[800px]">
          <div class="grid grid-cols-12 gap-4 border-b border-[var(--border)] px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted)]">
            <div class="col-span-1">#</div>
            <div class="col-span-2">Avatar</div>
            <div class="col-span-4">Full Name</div>
            <div class="col-span-3">Email</div>
            <div class="col-span-2 text-right">Actions</div>
          </div>
          <?php if (empty($visibleAdmins)): ?>
            <div class="px-5 py-8 text-center text-sm text-[var(--text-muted)]">ยังไม่มีข้อมูลผู้ดูแลระบบในระบบ</div>
          <?php else: ?>
            <?php foreach ($visibleAdmins as $index => $admin): ?>
              <?php
                $name = (string) ($admin['name'] ?? 'Admin');
                $email = (string) ($admin['email'] ?? '');
                $letter = $name !== '' ? (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1)) : 'A';
                $letter = strtoupper((string) $letter);
                $avatarFile = trim((string) ($admin['avatar_filename'] ?? ''));
                $avatarUrl = $avatarFile !== '' ? '../uploads/admins/' . rawurlencode($avatarFile) : '';
              ?>
              <div class="grid grid-cols-12 items-center gap-4 border-b border-[var(--border)] px-5 py-3">
                <div class="col-span-1 text-sm text-[var(--text-muted)]"><?= (int) $offset + $index + 1 ?></div>
                <div class="col-span-2">
                  <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" class="h-10 w-10 rounded-full object-cover border border-[#DADDE1]" />
                  <?php else: ?>
                    <div class="grid h-10 w-10 place-items-center rounded-full border border-[#DADDE1] bg-gray-400">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/></svg>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="col-span-4 min-w-0 text-sm text-[#1E1E1E] dark:text-gray-100"><div class="truncate"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="col-span-3 min-w-0 text-sm text-[var(--text-muted)]"><div class="truncate"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="col-span-2 flex shrink-0 justify-end gap-2">
                  <button type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-muted)] hover:text-[#1E1E1E] dark:bg-transparent dark:border-transparent dark:text-blue-400 dark:hover:bg-blue-400/10 dark:hover:text-blue-300" aria-label="แก้ไขแอดมิน" onclick='openEditModal(<?= (int) ($admin['id'] ?? 0) ?>, <?= json_encode($name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($email, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($avatarUrl, JSON_UNESCAPED_SLASHES) ?>)'>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg>
                  </button>
                  <button type="button" class="open-delete-admin-btn inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" aria-label="ลบแอดมิน" data-delete-id="<?= (int) ($admin['id'] ?? 0) ?>" data-admin-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" data-admin-email="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          </div>
          </div>
          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-3">
            <?php
              // 1. บังคับดึงค่าหน้าปัจจุบันจาก URL เพื่อแก้ปัญหาหน้าค้าง
              $currentP = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

              // 2. ดึงค่าอื่นๆ ตามตัวแปรเดิมของระบบ (ปรับ fallback limit ให้เป็น 8 ตาม UI)
              $maxP = (int) ($totalPages ?? $total_pages ?? 1);
              $totalItems = (int) ($totalAdmins ?? $totalRecords ?? $total ?? 0);
              $limitPerPage = (int) ($perPage ?? $limit ?? 8);

              $currentP = min($maxP, $currentP);

              // 3. คำนวณตัวเลขแสดงผลใหม่ตามหน้าปัจจุบัน
              $sFrom = $totalItems > 0 ? (($currentP - 1) * $limitPerPage) + 1 : 0;
              $sTo = $totalItems > 0 ? min($sFrom + $limitPerPage - 1, $totalItems) : 0;
              if (!function_exists('getAdminPageUrl')) {
                  function getAdminPageUrl($pageNum) {
                      $q = $_GET;
                      $q['page'] = $pageNum;
                      return '?' . http_build_query($q);
                  }
              }
            ?>
            <div class="text-[11px] text-[var(--text-muted)]">แสดง <?= (int) $sFrom ?>-<?= (int) $sTo ?> จากทั้งหมด <?= (int) $totalItems ?> รายการ</div>

            <div class="admins-pagination flex flex-wrap items-center gap-2">
              <!-- ปุ่ม Previous (จะปลดล็อคเมื่ออยู่หน้า 2 ขึ้นไป) -->
              <a href="<?= htmlspecialchars(getAdminPageUrl(max(1, $currentP - 1)), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] px-3 text-[11px] font-medium <?= $currentP <= 1 ? 'pointer-events-none bg-[#F3F4F6] text-[#B0B6BD] dark:bg-[#262626] dark:text-gray-500' : 'bg-white text-[#6B7280] hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800' ?>">Previous</a>

              <!-- ตัวเลขหน้า (ระบบ Ellipsis) -->
              <?php
              $visiblePages = [];
              for ($i = 1; $i <= $maxP; $i++) {
                  if ($i === 1 || $i === $maxP || ($i >= $currentP - 1 && $i <= $currentP + 1)) {
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
                <?php if ((int) $i === (int) $currentP): ?>
                  <span aria-current="page" data-page-number="<?= (int) $i ?>" style="background:#D32F2F;border-color:#D32F2F;color:#FFFFFF;" class="inline-flex h-8 min-w-[32px] items-center justify-center rounded-md border px-2 text-[11px] font-medium shadow-sm"><?= (int) $i ?></span>
                <?php else: ?>
                  <a href="<?= htmlspecialchars(getAdminPageUrl($i), ENT_QUOTES, 'UTF-8') ?>" data-page-number="<?= (int) $i ?>" class="inline-flex h-8 min-w-[32px] items-center justify-center rounded-md border border-[#DFE2E6] bg-white px-2 text-[11px] font-medium text-[#6B7280] transition-colors hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800"><?= (int) $i ?></a>
                <?php endif; ?>

                <?php $lastPage = $i; ?>
              <?php endforeach; ?>

              <!-- ปุ่ม Next -->
              <a href="<?= htmlspecialchars(getAdminPageUrl(min($maxP, $currentP + 1)), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] px-3 text-[11px] font-medium <?= $currentP >= $maxP ? 'pointer-events-none bg-[#F3F4F6] text-[#B0B6BD] dark:bg-[#262626] dark:text-gray-500' : 'bg-white text-[#6B7280] hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800' ?>">Next</a>
            </div>
          </div>
        </section>
        </div>
      </main>
    </div>
  </div>

  <?php include __DIR__ . '/manage_admins_modals.php'; ?>
  <?php include __DIR__ . '/manage_admins_scripts.php'; ?>
  <script>
    // Fallback: force active page color from URL query.
    (function syncPaginationActiveState() {
      const paginationRoot = document.querySelector('.admins-pagination');
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
            el.style.backgroundColor = '';
            el.style.borderColor = '';
            el.style.color = '';
          }
        }
      });
    })();
  </script>
  <?php require_once __DIR__ . '/../config/site_lang_script.php'; ?>

  <div id="admin-crop-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 backdrop-blur-sm opacity-0 transition-opacity duration-300" aria-hidden="true">
    <div class="modal-inner w-full max-w-md scale-95 rounded-2xl bg-white p-6 shadow-2xl transition-transform duration-300 dark:bg-[#1e1e1e] dark:border dark:border-[#333333]">
      <div class="mb-4 flex items-center justify-between">
        <h3 class="text-xl font-bold text-gray-900 dark:text-white">คอปรูปโปรไฟล์</h3>
        <button type="button" id="close-admin-crop-btn" class="text-2xl leading-none text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
      </div>
      <div class="mb-4 w-full h-[280px] overflow-hidden bg-[#F3F4F6] border border-[#E5E7EB] rounded-xl dark:bg-[#262626] dark:border-[#333333]">
        <img id="admin-cropper-image" src="" alt="Crop" class="max-w-full block">
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" id="cancel-admin-crop-btn" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-[#333333] dark:text-gray-300 dark:hover:bg-gray-800">ยกเลิก</button>
        <button type="button" id="save-admin-crop-btn" class="rounded-lg bg-[var(--mt-red)] px-4 py-2 text-sm font-medium text-white hover:bg-[#B71C1C]">ยืนยันการคอป</button>
      </div>
    </div>
  </div>
</body>
</html>
