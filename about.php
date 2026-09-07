<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';

$assetBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($assetBase === '.' || $assetBase === DIRECTORY_SEPARATOR) {
    $assetBase = '';
}

$error = isset($_GET['error']) ? (string) $_GET['error'] : '';
$success = isset($_GET['success']) ? (string) $_GET['success'] : '';
$modal = isset($_GET['modal']) ? (string) $_GET['modal'] : '';
$initialModal = in_array($modal, ['signin', 'signup'], true) ? $modal : '';

if ($initialModal === '' && in_array($error, ['invalid_credentials', 'login_failed', 'signin_invalid_credentials', 'signin_failed', 'signin_missing_fields', 'signin_invalid_email', 'invalid_csrf'], true)) {
    $initialModal = 'signin';
}
if ($initialModal === '' && in_array($error, ['email_exists', 'signup_failed', 'password_mismatch', 'missing_fields', 'invalid_email'], true)) {
    $initialModal = 'signup';
}
if ($initialModal === '' && $error === 'registration_closed') {
    $initialModal = 'signup';
}

$isLoggedIn = isset($_SESSION['user_id']);
$dashboardLink = './index.php';
$sessionRole = (string) ($_SESSION['role'] ?? '');
if ($sessionRole === 'admin') {
    $dashboardLink = './admin/manage_projects.php';
} elseif ($sessionRole === 'student') {
    $dashboardLink = './student/index.php';
}
$displayName = trim((string) ($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'User'));
$displayAvatarUrl = '';
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmtAvatar = $pdo->prepare('SELECT avatar_filename FROM users WHERE id = :id LIMIT 1');
        $stmtAvatar->execute([':id' => (int) $_SESSION['user_id']]);
        $row = $stmtAvatar->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['avatar_filename'])) {
            $displayAvatarUrl = './uploads/admins/' . rawurlencode($row['avatar_filename']);
        }
    } catch (Throwable $e) {
    }
}
$firstLetter = $displayName !== '' ? (function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1)) : 'U';
$displayAvatarText = strtoupper((string) $firstLetter);

$years = [];
$categories = [];
$advisors = [];
$searchQuery = '';
$searchYear = '';
$searchCategory = '';
$searchAdvisor = '';
$sortOrder = 'newest';

if (isset($pdo)) {
    try {
        $years = $pdo->query('SELECT year FROM academic_years ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);
        $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        $advisors = $pdo->query('SELECT id, prefix, full_name, is_active FROM advisors ORDER BY full_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>About Us - RMUTI MT Thesis Gallery</title>
  <?php require_once __DIR__ . '/config/site_theme_head.php'; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/config/site_tailwind_config.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root {
      --mt-red: #D32F2F;
      --mt-red-dark: #B71C1C;
      --bg-primary: #FFFFFF;
      --bg-secondary: #F8F9FA;
      --text-primary: #121212;
      --text-muted: #757575;
      --border: #E0E0E0;
      --shadow: rgba(0,0,0,0.06);
    }

    html.dark {
      --bg-primary: #1e1e1e;
      --bg-secondary: #262626;
      --text-primary: #F5F5F5;
      --text-muted: #A3A3A3;
      --border: #262626;
      --shadow: rgba(0,0,0,0.45);
    }

    body {
      margin: 0;
      background: #F3F3F4;
      color: var(--text-primary);
      font-family: 'Prompt', sans-serif;
    }
    html.dark body {
      background: #121212;
    }
    .font-display { font-family: 'Prompt', sans-serif; }
    .site-header {
      position: sticky;
      top: 0;
      z-index: 1000;
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
      transition: box-shadow .25s ease, background-color .25s ease;
    }
    .site-header-inner {
      height: 64px;
      transition: height .2s ease;
    }
    .site-header.is-scrolled {
      box-shadow: 0 8px 24px -18px rgba(15, 23, 42, 0.45);
      background: rgba(255, 255, 255, 0.98);
    }
    .site-header.is-scrolled .site-header-inner {
      height: 56px;
    }
    html.dark .site-header {
      background: rgba(10, 10, 10, 0.96);
      border-bottom-color: #262626;
    }
    html.dark .site-header.is-scrolled {
      background: rgba(10, 10, 10, 0.98);
      box-shadow: 0 8px 24px -18px rgba(0, 0, 0, 0.75);
    }


    .btn-search {
      border: 0;
      height: 42px;
      width: 100%;
      padding: 0 18px;
      background: #D32F2F;
      color: #fff;
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 0.03em;
      cursor: pointer;
      border-radius: 9999px;
      transition: all 0.2s ease;
      transform: translateY(0);
      white-space: nowrap;
    }
    .btn-search:hover {
      background: #B71C1C;
      transform: translateY(-1px);
      box-shadow: 0 8px 20px -6px rgba(211, 47, 47, 0.4);
    }

    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 300ms ease-out, visibility 300ms ease-out;
    }
    .modal-overlay.open { visibility: visible; pointer-events: auto; }
    .modal-overlay.is-visible { opacity: 1; }

    .auth-modal {
      width: 100%;
      max-width: 580px;
      max-height: calc(100vh - 32px);
      max-height: calc(100dvh - 32px);
      display: flex;
      flex-direction: column;
      background: #F2F3F4;
      border-radius: 16px;
      border-top: 4px solid var(--mt-red);
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
      transition: all 300ms ease-out;
      transform: scale(.95);
      opacity: 0;
    }
    .modal-overlay.is-visible .auth-modal { transform: scale(1); opacity: 1; }
    .auth-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 20px 24px 14px;
      border-bottom: 1px solid #D7D9DB;
      flex-shrink: 0;
    }
    .auth-title {
      font-family: 'Prompt', sans-serif !important;
      font-weight: 700;
      font-size: clamp(24px, 4vw, 32px);
      line-height: 1.1;
      color: #1A1A1A;
      margin: 0;
    }
    .auth-subtitle {
      margin: 6px 0 0;
      color: #8A8A8A;
      font-size: 14px;
      line-height: 1.25;
    }
    .auth-close {
      border: 0;
      background: transparent;
      color: #9A9A9A;
      font-size: 30px;
      line-height: 1;
      cursor: pointer;
    }
    .auth-body {
      padding: 20px 24px;
      overflow-y: auto;
      overscroll-behavior: contain;
      flex: 1 1 auto;
    }
    .auth-field { margin-bottom: 14px; }
    .auth-field label {
      display: block;
      margin-bottom: 8px;
      font-size: 13px;
      font-weight: 700;
      color: #666;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .auth-field input {
      width: 100%;
      height: 46px;
      border: 1px solid #C8CCCF;
      border-radius: 10px;
      background: #F2F3F4;
      padding: 0 14px;
      font-size: 16px;
      color: #222;
      outline: none;
    }
    .auth-field input:focus {
      border-color: var(--mt-red);
      box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.12);
    }
    .signin-popup-error {
      display: none;
      margin-bottom: 12px;
      border: 1px solid #F3B5B5;
      background: #FFF3F3;
      color: #9C1D1D;
      border-radius: 10px;
      padding: 9px 12px;
      font-size: 13px;
      line-height: 1.35;
    }
    .signin-popup-error.show { display: block; }
    .auth-submit {
      margin-top: 8px;
      width: 100%;
      height: 46px;
      border: 0;
      border-radius: 12px;
      background: var(--mt-red);
      color: #FFF;
      font-size: 16px;
      line-height: 1;
      font-weight: 700;
      font-family: 'DM Sans', sans-serif;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(211, 47, 47, 0.28);
      transition: all 200ms ease;
    }
    .auth-submit:hover {
      background: var(--mt-red-dark);
      box-shadow: 0 6px 20px rgba(211, 47, 47, 0.38);
      transform: translateY(-1px);
    }
    .auth-footer {
      border-top: 1px solid #D7D9DB;
      text-align: center;
      padding: 12px 14px 14px;
      color: #8A8A8A;
      font-size: 14px;
    }
    .auth-switch {
      border: 0;
      background: transparent;
      color: var(--mt-red);
      font-weight: 600;
      cursor: pointer;
      padding: 0;
      margin-left: 6px;
    }
    .auth-hidden { display: none; }
    .auth-success-box { text-align: center; padding: 14px 0 8px; }
    .auth-success-icon {
      width: 64px;
      height: 64px;
      border-radius: 9999px;
      margin: 0 auto 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #EDF8EF;
      color: #1E7A34;
      border: 1px solid #C6E8CE;
    }
    .auth-success-title {
      margin: 0;
      color: #17191C;
      font-size: 24px;
      line-height: 1.2;
      font-family: 'Prompt', sans-serif !important;
      font-weight: 700;
    }
    .auth-success-text {
      margin: 10px 0 0;
      color: #757575;
      font-size: 15px;
      line-height: 1.45;
    }

    .flash-message {
      display: none;
      max-width: 760px;
      margin: 16px auto 0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 14px;
      border: 1px solid transparent;
    }
    .flash-message.show { display: block; }
    .flash-error {
      background: #FFF3F3;
      border-color: #F3B5B5;
      color: #9C1D1D;
    }
    .flash-success {
      background: #EDF8EF;
      border-color: #BFE3C6;
      color: #1E6D2D;
    }

    /* Auth modal — dark mode */
    html.dark .auth-modal {
      background: #1e1e1e !important;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.55);
    }
    html.dark .auth-header {
      border-bottom-color: #333333;
    }
    html.dark .auth-title {
      color: #ffffff;
    }
    html.dark .auth-subtitle {
      color: #a3a3a3;
    }
    html.dark .auth-close {
      color: #9ca3af;
    }
    html.dark .auth-field label {
      color: #d1d5db;
    }
    html.dark .auth-field input {
      background: #121212;
      border-color: #374151;
      color: #ffffff;
    }
    html.dark .auth-field input:focus {
      border-color: var(--mt-red);
      box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.2);
    }
    html.dark .auth-field input::placeholder {
      color: #737373;
    }
    html.dark .signin-popup-error {
      border-color: #7f1d1d;
      background: rgba(127, 29, 29, 0.25);
      color: #fca5a5;
    }
    html.dark .auth-footer {
      border-top-color: #333333;
      color: #a3a3a3;
    }
    html.dark .auth-submit {
      background: var(--mt-red);
      color: #ffffff;
      box-shadow: 0 4px 16px rgba(211, 47, 47, 0.4);
    }
    html.dark .auth-submit:hover {
      background: var(--mt-red-dark);
      box-shadow: 0 6px 22px rgba(211, 47, 47, 0.55);
    }
    html.dark .auth-success-title {
      color: #ffffff;
    }
    html.dark .auth-success-text {
      color: #a3a3a3;
    }
    html.dark .auth-success-icon {
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border-color: rgba(16, 185, 129, 0.35);
    }
    html.dark .flash-error {
      background: rgba(127, 29, 29, 0.25);
      border-color: #7f1d1d;
      color: #fca5a5;
    }
    html.dark .flash-success {
      background: rgba(16, 185, 129, 0.15);
      border-color: rgba(16, 185, 129, 0.35);
      color: #6ee7b7;
    }

    /* SweetAlert2 — dark mode */
    html.dark .swal2-popup {
      background-color: #1e1e1e !important;
      color: #ffffff !important;
      border: 1px solid #333333 !important;
    }
    html.dark .swal2-title,
    html.dark .swal2-html-container {
      color: #e5e7eb !important;
    }
    html.dark .swal2-icon.swal2-warning {
      border-color: #f59e0b !important;
      color: #f59e0b !important;
    }
    html.dark .swal2-icon.swal2-error {
      border-color: #ef4444 !important;
      color: #ef4444 !important;
    }
    html.dark .swal2-icon.swal2-success {
      border-color: #10b981 !important;
      color: #10b981 !important;
    }
  </style>
</head>
<body class="flex min-h-screen flex-col dark:bg-[#121212] dark:text-gray-100" data-error="<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>" data-success="<?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>" data-initial-modal="<?= htmlspecialchars($initialModal, ENT_QUOTES, 'UTF-8') ?>">

  <?php
  $activePage = 'about';
  require_once __DIR__ . '/config/site_header.php';
  ?>

  <div id="flash-message" class="flash-message"></div>

  <main class="flex-1 dark:bg-[#121212]">
    <div class="mx-auto max-w-4xl px-4 py-12">
      <div class="rounded-2xl border border-[var(--border)] bg-white p-8 shadow-sm dark:border-[#333333] dark:bg-[#1e1e1e] sm:p-10">
        <div class="mb-2 flex items-center gap-3">
          <span class="inline-block h-8 w-1 rounded-full bg-[var(--mt-red)]"></span>
          <p class="text-xs font-semibold uppercase tracking-[0.28em] text-[var(--mt-red)]" data-th="เทคโนโลยีมัลติมีเดีย" data-en="Multimedia Technology">เทคโนโลยีมัลติมีเดีย</p>
        </div>

        <h1 class="font-display text-4xl font-semibold tracking-tight text-gray-900 dark:text-white sm:text-5xl" data-th="เกี่ยวกับเรา" data-en="About Us">เกี่ยวกับเรา</h1>

        <div class="mt-8 space-y-5 text-base leading-relaxed">
          <p class="text-gray-600 dark:text-gray-300" data-th="MT Thesis Gallery เป็นแพลตฟอร์มสำหรับเผยแพร่และจัดเก็บผลงานวิทยานิพนธ์ของนักศึกษาสาขาเทคโนโลยีมัลติมีเดีย มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน นครราชสีมา เพื่อให้สังคมและชุมชนทางการศึกษาสามารถเข้าถึงผลงานสร้างสรรค์ได้อย่างสะดวก" data-en="MT Thesis Gallery is a dedicated platform for publishing and archiving thesis projects by students of the Multimedia Technology program at Rajamangala University of Technology Isan, Nakhon Ratchasima — making creative student works accessible to the academic community and the public.">
            MT Thesis Gallery เป็นแพลตฟอร์มสำหรับเผยแพร่และจัดเก็บผลงานวิทยานิพนธ์ของนักศึกษาสาขาเทคโนโลยีมัลติมีเดีย มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน นครราชสีมา เพื่อให้สังคมและชุมชนทางการศึกษาสามารถเข้าถึงผลงานสร้างสรรค์ได้อย่างสะดวก
          </p>
          <p class="text-gray-600 dark:text-gray-300" data-th="เว็บไซต์นี้รองรับการค้นหาและกรองผลงานตามปีการศึกษา หมวดหมู่ และอาจารย์ที่ปรึกษา รวมถึงการแสดงผลในรูปแบบวิดีโอ แกลเลอรีภาพ ไฟล์โปรเจกต์ และเอกสารวิทยานิพนธ์ เพื่อให้ผู้ชมได้สัมผัสผลงานได้ครบถ้วนในที่เดียว" data-en="This website supports searching and filtering projects by academic year, category, and advisor. Works can be presented as videos, image galleries, project files, and thesis documents so visitors can experience each project in one place.">
            เว็บไซต์นี้รองรับการค้นหาและกรองผลงานตามปีการศึกษา หมวดหมู่ และอาจารย์ที่ปรึกษา รวมถึงการแสดงผลในรูปแบบวิดีโอ แกลเลอรีภาพ ไฟล์โปรเจกต์ และเอกสารวิทยานิพนธ์ เพื่อให้ผู้ชมได้สัมผัสผลงานได้ครบถ้วนในที่เดียว
          </p>
          <p class="text-gray-600 dark:text-gray-300" data-th="ผลงานทุกชิ้นที่ปรากฏบนระบบได้รับการตรวจสอบและอนุมัติจากคณาจารย์ที่ปรึกษาก่อนเผยแพร่ เพื่อรักษามาตรฐานคุณภาพและความน่าเชื่อถือของข้อมูลสำหรับผู้ใช้งานทุกท่าน" data-en="Every project published on this platform is reviewed and approved by academic advisors before going live, ensuring quality standards and reliable information for all visitors.">
            ผลงานทุกชิ้นที่ปรากฏบนระบบได้รับการตรวจสอบและอนุมัติจากคณาจารย์ที่ปรึกษาก่อนเผยแพร่ เพื่อรักษามาตรฐานคุณภาพและความน่าเชื่อถือของข้อมูลสำหรับผู้ใช้งานทุกท่าน
          </p>
        </div>
      </div>
    </div>
  </main>

  <footer class="mt-8 w-full border-t border-[#E5E7EB] bg-[#FAFAFB] py-8 dark:border-[#262626] dark:bg-[#0a0a0a]">
    <div class="mx-auto flex max-w-7xl flex-col flex-wrap items-center justify-between gap-4 px-6 sm:flex-row">
      <div class="text-center text-sm font-medium text-[#9CA3AF] sm:text-left">
        &copy; 2026 RMUTI MT Thesis Gallery.
      </div>
      <div class="flex flex-wrap items-center justify-center gap-3 sm:gap-4 text-sm font-medium text-[#9CA3AF]">
        <a href="#" class="open-footer-privacy hover:text-[var(--mt-red)] transition-colors" data-th="นโยบายความเป็นส่วนตัว" data-en="Privacy Policy">นโยบายความเป็นส่วนตัว</a>
        <span class="text-gray-300 dark:text-gray-700">|</span>
        <a href="#" class="open-footer-terms hover:text-[var(--mt-red)] transition-colors" data-th="เงื่อนไขการใช้งาน" data-en="Terms of Service">เงื่อนไขการใช้งาน</a>
        <span class="text-gray-300 dark:text-gray-700">|</span>
        <a href="#" class="open-dev-team hover:text-[var(--mt-red)] transition-colors" data-th="ทีมผู้พัฒนาระบบ" data-en="Developer Team">ทีมผู้พัฒนาระบบ</a>
      </div>
      <div class="text-center text-sm font-medium text-[#9CA3AF] sm:text-right">
        Multimedia Technology
      </div>
    </div>
  </footer>

  <?php require_once __DIR__ . '/config/auth_modals.php'; ?>
</body>
</html>
