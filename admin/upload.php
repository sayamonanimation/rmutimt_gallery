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

$adminName = trim((string) ($_SESSION['user_name'] ?? 'Administrator'));
$adminEmail = '';

try {
    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => (int) $_SESSION['user_id']]);
    $currentAdmin = $userStmt->fetch();
    if ($currentAdmin) {
        $adminName = trim((string) ($currentAdmin['name'] ?? $adminName));
        $adminEmail = trim((string) ($currentAdmin['email'] ?? ''));
    }
} catch (Throwable $e) {
    error_log('[ADMIN_UPLOAD] load user failed: ' . $e->getMessage());
}

$avatar = $adminName !== '' ? (function_exists('mb_substr') ? mb_substr($adminName, 0, 1, 'UTF-8') : substr($adminName, 0, 1)) : 'A';
$avatar = strtoupper((string) $avatar);

$pageTitle = 'อัปโหลดโปรเจกต์ใหม่';
$pageTitleDataTh = 'อัปโหลดโปรเจกต์ใหม่';
$pageTitleDataEn = 'Upload New Project';
$pageSubtitle = 'Admin | Dashboard | Upload New Project';
$pageSubtitleDataTh = 'ผู้ดูแล | แดชบอร์ด | อัปโหลดโปรเจกต์ใหม่';
$pageSubtitleDataEn = 'Admin | Dashboard | Upload New Project';
$advisors = [];
$categories = [];
$academicYears = [];

try {
    $advisorStmt = $pdo->query('SELECT id, prefix, full_name FROM advisors WHERE is_active = 1 ORDER BY full_name ASC');
    $advisors = $advisorStmt->fetchAll();

    $categoryStmt = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC');
    $categories = $categoryStmt->fetchAll();

    $yearStmt = $pdo->query('SELECT id, year FROM academic_years ORDER BY year DESC');
    $academicYears = $yearStmt->fetchAll();
} catch (Throwable $e) {
    error_log('[ADMIN_UPLOAD] load dropdown data failed: ' . $e->getMessage());
}

$uploadErrors = $_SESSION['upload_errors'] ?? [];
$uploadSuccess = $_SESSION['upload_success'] ?? null;
$uploadedFiles = $_SESSION['uploaded_files'] ?? [];
$oldInput = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);

$authorCount = 2;
if ($oldInput !== []) {
    $count = 0;
    foreach ($oldInput as $k => $v) {
        if (is_string($k) && str_starts_with($k, 'author_name_')) {
            $count++;
        }
    }
    if ($count > 2) {
        $authorCount = $count;
    }
}

$csrfToken = csrf_token();
unset($_SESSION['upload_errors'], $_SESSION['upload_success'], $_SESSION['uploaded_files']);

$selectedDisplayType = isset($oldInput['display_type']) && (string) $oldInput['display_type'] === 'gallery' ? 'gallery' : 'video';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Upload New Project - RMUTI MT Gallery</title>
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
    .form-input {
      height: 44px;
      width: 100%;
      border: 1px solid #E3E5E8;
      border-radius: 12px;
      background: #fff;
      padding: 0 12px;
      font-size: 13px;
      color: #374151;
      outline: none;
      transition: color .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .form-input:focus, .form-textarea:focus, .form-select:focus {
      border-color: var(--mt-red);
      box-shadow: 0 0 0 1px var(--mt-red);
    }
    .form-select {
      height: 44px;
      width: 100%;
      border: 1px solid #E3E5E8;
      border-radius: 12px;
      background: #fff;
      padding: 0 12px;
      font-size: 13px;
      color: #374151;
      outline: none;
      transition: color .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .form-textarea {
      width: 100%;
      min-height: 96px;
      border: 1px solid #E3E5E8;
      border-radius: 12px;
      background: #fff;
      padding: 11px 12px;
      font-size: 13px;
      color: #374151;
      outline: none;
      resize: vertical;
      transition: color .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .upload-zone {
      border: 1px dashed #D7DBE0;
      border-radius: 14px;
      background: #fff;
      min-height: 112px;
      cursor: pointer;
      transition: .16s ease;
    }
    .upload-zone:hover { border-color: var(--mt-red); background:#FCFCFD; }
    .upload-zone.drag-over { border-color: #D32F2F; background:#FFF5F5; }
    .file-list-card {
      border: 1px solid #E5E7EB;
      border-radius: 12px;
      background: #FFFFFF;
      overflow: hidden;
    }
    .file-list-row {
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: center;
      gap: 10px;
      padding: 10px 40px 10px 12px;
      border-top: 1px solid #F1F3F5;
    }
    .file-list-row:first-child { border-top: 0; }
    .file-list-icon {
      width: 22px;
      height: 22px;
      border-radius: 999px;
      display: grid;
      place-items: center;
      font-size: 12px;
      background: #FFF1F1;
      color: #B91C1C;
    }
    .file-list-name {
      min-width: 0;
      font-size: 13px;
      color: #1F2937;
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    html.dark .file-list-card {
      background: #1e1e1e;
      border-color: #333333;
    }
    html.dark .file-list-row {
      border-top-color: #333333;
    }
    html.dark .file-list-name {
      color: #E5E7EB;
    }
    html.dark .file-list-icon {
      background: rgba(211, 47, 47, 0.15);
      color: #EF4444;
    }
    .file-list-size {
      font-size: 12px;
      color: #9CA3AF;
      white-space: nowrap;
    }
    .file-list-remove {
      border: 0;
      background: transparent;
      color: #9CA3AF;
      width: 20px;
      height: 20px;
      border-radius: 999px;
      cursor: pointer;
      line-height: 1;
      font-size: 16px;
      display: grid;
      place-items: center;
    }
    .file-list-remove:hover { background: #FEE2E2; color: #B91C1C; }

    .tooltip-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background-color: #E5E7EB;
      color: #6B7280;
      font-size: 10px;
      font-weight: bold;
      cursor: help;
      margin-left: 6px;
      position: relative;
    }
    html.dark .tooltip-icon {
      background-color: #374151;
      color: #9CA3AF;
    }
    .tooltip-box {
      position: absolute;
      bottom: 100%;
      left: 50%;
      transform: translateX(-50%) translateY(-8px);
      width: 220px;
      padding: 8px 12px;
      background: #111827;
      color: #fff;
      font-size: 11px;
      line-height: 1.5;
      border-radius: 8px;
      text-transform: none;
      letter-spacing: normal;
      font-weight: 400;
      text-align: left;
      opacity: 0;
      visibility: hidden;
      transition: all 0.2s ease;
      z-index: 50;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
      pointer-events: none;
    }
    html.dark .tooltip-box {
      background: #374151;
    }
    .tooltip-icon:hover .tooltip-box {
      opacity: 1;
      visibility: visible;
      transform: translateX(-50%) translateY(-4px);
    }
    .tooltip-box::after {
      content: '';
      position: absolute;
      top: 100%;
      left: 50%;
      transform: translateX(-50%);
      border-width: 4px;
      border-style: solid;
      border-color: #111827 transparent transparent transparent;
    }
    html.dark .tooltip-box::after {
      border-color: #374151 transparent transparent transparent;
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
  <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php require_once __DIR__ . '/../config/site_dark_styles.php'; ?>
</head>
<body class="h-screen overflow-hidden dark:bg-[#121212] dark:text-gray-100">
  <div class="flex h-screen overflow-hidden">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-full min-h-0 overflow-hidden">
      <main class="flex-1 overflow-y-auto p-6 md:p-8">
        <div class="mx-auto max-w-5xl">
          <?php include __DIR__ . '/topbar.php'; ?>

          <?php if (is_array($uploadErrors) && $uploadErrors !== []): ?>
            <div class="mt-6 rounded-xl border border-[#F8C9C9] bg-[#FFF5F5] p-4 text-sm text-[#9F1D1D]">
              <p class="font-semibold">อัปโหลดไม่สำเร็จ กรุณาตรวจสอบข้อมูล:</p>
              <ul class="mt-2 list-disc space-y-1 pl-5">
                <?php foreach ($uploadErrors as $error): ?>
                  <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if (is_string($uploadSuccess) && $uploadSuccess !== ''): ?>
            <div class="mt-6 rounded-xl border border-[#BEE5C8] bg-[#F4FFF7] p-4 text-sm text-[#1F6A34]">
              <p class="font-semibold"><?= htmlspecialchars($uploadSuccess, ENT_QUOTES, 'UTF-8') ?></p>
              <?php if (is_array($uploadedFiles) && $uploadedFiles !== []): ?>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                  <?php foreach ($uploadedFiles as $label => $data): ?>
                    <li>
                      <?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?>:
                      <?php if (is_array($data) && isset($data['id'])): ?>
                        ID <?= htmlspecialchars((string) $data['id'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (isset($data['link']) && is_string($data['link']) && $data['link'] !== ''): ?>
                          - <a class="underline" href="<?= htmlspecialchars($data['link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">เปิดไฟล์</a>
                        <?php endif; ?>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <form id="upload-project-form" method="POST" action="./upload_process_admin.php" enctype="multipart/form-data" class="mt-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <section class="rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
              <div class="mb-5 flex items-center gap-3">
                <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-[var(--mt-red)] text-[11px] font-semibold text-white">1</span>
                <h2 class="text-[20px] font-semibold text-[#202328] dark:text-white">Project Metadata</h2>
              </div>

              <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Project Title (ชื่อผลงาน) <span class="text-[var(--mt-red)]">*</span></label>
                  <input type="text" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="title_th" placeholder="ชื่อผลงาน (ภาษาไทย)" value="<?= htmlspecialchars((string) ($oldInput['title_th'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required />
                </div>
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Project Title (English) <span class="text-[var(--mt-red)]">*</span></label>
                  <input type="text" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="title_en" placeholder="Project Title (English)" value="<?= htmlspecialchars((string) ($oldInput['title_en'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required />
                </div>
              </div>

              <div class="mt-4">
                <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Advisor (อาจารย์ที่ปรึกษา) <span class="text-[var(--mt-red)]">*</span></label>
                <div class="flex items-center gap-2">
                  <div class="grid h-9 w-9 place-items-center rounded-full bg-[var(--mt-red)] text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/></svg>
                  </div>
                  <div class="select-wrap relative w-full">
                    <select class="form-select dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="advisor_id" required>
                      <option value="" disabled <?= empty($oldInput['advisor_id'] ?? '') ? 'selected' : '' ?>>Select advisor...</option>
                      <?php foreach ($advisors as $advisor): ?>
                        <option value="<?= htmlspecialchars((string) ($advisor['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (isset($oldInput['advisor_id']) && $oldInput['advisor_id'] == $advisor['id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars(trim((string) (($advisor['prefix'] ?? '') . ' ' . ($advisor['full_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
                  </div>
                </div>
              </div>

              <div class="mt-5">
                <div class="mb-2 flex items-center justify-between">
                  <label class="text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Authors (ผู้จัดทำ)</label>
                  <button id="add-author-btn" type="button" class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-[var(--mt-red)] px-4 text-[12px] font-semibold text-white hover:bg-[var(--mt-red-dark)]">
                    <span class="text-base leading-none">+</span>
                    เพิ่มผู้จัดทำ
                  </button>
                </div>
                <div id="authors-container" class="grid grid-cols-1 gap-3 md:grid-cols-2">
<?php
for ($i = 1; $i <= $authorCount; $i++):
    $oldName = $oldInput['author_name_' . $i] ?? '';
?>
                  <div class="author-card rounded-2xl border border-[#E7E9EC] bg-[#FBFBFC] p-4 relative dark:bg-[#1e1e1e] dark:border-gray-700">
                    <button type="button" class="remove-author-btn absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-md border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition hover:bg-[#FFEFEF] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" aria-label="ลบผู้จัดทำ">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
                    </button>
                    <label class="group mx-auto grid h-16 w-16 cursor-pointer place-items-center overflow-hidden rounded-full border-2 border-dashed border-[#E4B4B8] bg-[#FFF8F8] text-[#D56A74] dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-400">
                      <svg xmlns="http://www.w3.org/2000/svg" class="author-photo-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4 5a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-2l-1-1H7L6 5H4z"/><path fill-rule="evenodd" d="M10 8a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd"/></svg>
                      <img class="author-photo-preview hidden h-full w-full object-cover" alt="Author photo preview" />
                      <input type="file" class="sr-only author-photo-input" name="author_photo_<?= $i ?>" accept="image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF" />
                    </label>
                    <p class="mt-2 text-center text-[11px] text-[#A6ADB6] dark:text-gray-400">Upload Photo</p>
                    <label class="mt-3 mb-1 block text-[11px] font-medium text-[#8B919A] dark:text-gray-200">ชื่อ-นามสกุล <span class="text-[var(--mt-red)]">*</span></label>
                    <input type="text" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="author_name_<?= $i ?>" placeholder="ชื่อผู้จัดทำคนที่ <?= $i ?>" value="<?= htmlspecialchars((string) $oldName, ENT_QUOTES, 'UTF-8') ?>" required />
                  </div>
<?php endfor; ?>
                </div>
              </div>

              <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Main Category (หมวดหมู่หลัก) <span class="text-[var(--mt-red)]">*</span></label>
                  <div class="select-wrap relative w-full">
                    <select class="form-select dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="category_id" required>
                      <option value="" disabled <?= empty($oldInput['category_id'] ?? '') ? 'selected' : '' ?>>Select a category...</option>
                      <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars((string) ($category['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (isset($oldInput['category_id']) && $oldInput['category_id'] == $category['id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
                  </div>
                </div>
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Academic Year (ปีการศึกษา) <span class="text-[var(--mt-red)]">*</span></label>
                  <div class="select-wrap relative w-full">
                    <select class="form-select dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="academic_year_id" required>
                      <option value="" disabled <?= empty($oldInput['academic_year_id'] ?? '') ? 'selected' : '' ?>>Select year...</option>
                      <?php foreach ($academicYears as $academicYear): ?>
                        <option value="<?= htmlspecialchars((string) ($academicYear['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (isset($oldInput['academic_year_id']) && $oldInput['academic_year_id'] == $academicYear['id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) ($academicYear['year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
                  </div>
                </div>
              </div>

              <div class="mt-4 col-span-1 md:col-span-2">
                <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">
                  Mixed Media / Secondary Categories (หมวดหมู่ร่วม & สื่อผสมเพิ่มเติม - เลือกติ๊กได้หลายข้อความ, ไม่บังคับ) <span class="text-[var(--mt-red)]">*</span>
                </label>
                <div class="flex flex-wrap gap-2 pt-1">
                  <?php foreach ($categories as $category): ?>
                    <?php
                    $catIdStr = (string) $category['id'];
                    $isSecChecked = isset($oldInput['secondary_category_ids']) && is_array($oldInput['secondary_category_ids']) && in_array($catIdStr, $oldInput['secondary_category_ids'], true);
                    ?>
                    <label class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#1e1e1e] text-xs font-medium text-gray-700 dark:text-gray-200 cursor-pointer hover:border-[var(--mt-red)] transition-colors shadow-sm">
                      <input type="checkbox" name="secondary_category_ids[]" value="<?= htmlspecialchars($catIdStr, ENT_QUOTES, 'UTF-8') ?>" <?= $isSecChecked ? 'checked' : '' ?> class="w-4 h-4 rounded border-gray-300 text-[var(--mt-red)] focus:ring-[var(--mt-red)]/20" />
                      <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="mt-4">
                <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Introduction (บทนำ) <span class="text-[var(--mt-red)]">*</span></label>
                <textarea class="form-textarea dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="introduction" maxlength="3500" placeholder="Provide a brief summary of the project..." required><?= htmlspecialchars((string) ($oldInput['introduction'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="mt-1 text-right text-[10px] text-gray-400"><span id="intro-char-count">0</span>/3500</div>
              </div>
            </section>

            <section class="rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
              <div class="mb-5 flex items-center gap-3">
                <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-[var(--mt-red)] text-[11px] font-semibold text-white">2</span>
                <h2 class="text-[20px] font-semibold text-[#202328] dark:text-white">อัปโหลดไฟล์ผลงาน <span class="text-sm text-[#A2A8AF]">(Upload Media)</span></h2>
              </div>

              <div class="space-y-4">
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">รูปปก (THUMBNAIL) <span class="font-normal text-[#B1B7BF]">- แนะนำสัดส่วน 16:9</span></label>
                  <input id="thumbnail_file" type="file" class="hidden" name="thumbnail_file" accept="image/*" />
                  <label for="thumbnail_file" data-input-id="thumbnail_file" class="upload-zone flex flex-col items-center justify-center px-4 text-center transition-colors duration-200 hover:border-[var(--mt-red)] focus-within:border-[var(--mt-red)] focus-within:ring-1 focus-within:ring-[var(--mt-red)]">
                    <span class="mb-2 grid h-10 w-10 place-items-center rounded-full bg-[#F5F0EE] text-[#B9A093] dark:bg-rose-950/40 dark:text-rose-400">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 15a4 4 0 014-4h1.1a5 5 0 019.78 1.5A3.5 3.5 0 0116.5 19h-9A4.5 4.5 0 013 15z"/><path d="M10.75 9.5V13a.75.75 0 01-1.5 0V9.5L7.72 11.03a.75.75 0 01-1.06-1.06l2.81-2.81a.75.75 0 011.06 0l2.81 2.81a.75.75 0 11-1.06 1.06L10.75 9.5z"/></svg>
                    </span>
                    <span class="upload-message text-sm font-semibold text-[#31343A] dark:text-gray-300" data-default-message="คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</span>
                    <span class="mt-1 text-xs text-[#A0A6AE]">รองรับ: image/*</span>
                  </label>
                  <div id="thumbnail-file-list" class="file-list-card mt-2 hidden"></div>
                </div>

                <div>
                  <label class="mb-1.5 flex items-center text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">
                    รูปแบบการแสดงผล (DISPLAY TYPE)
                    <div class="tooltip-icon">
                      ?
                      <div class="tooltip-box">
                        <strong>วิดีโอ (Video):</strong> รองรับไฟล์ MP4, MOV 1 ไฟล์<br/>
                        <strong>แกลลอรีภาพนิ่ง (Gallery):</strong> รองรับไฟล์รูปภาพ (JPG, PNG) อัปโหลดได้สูงสุด 10 ไฟล์
                      </div>
                    </div>
                  </label>
                  <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <label id="display-type-video-btn" class="display-type-btn relative flex-1 rounded-xl border-2 border-[var(--mt-red)] bg-[#FFF1F1] px-4 py-3 text-center transition-all ring-4 ring-[var(--mt-red)]/20 dark:border-[var(--mt-red)] dark:bg-red-900/20 dark:text-white">
                      <input type="radio" name="display_type" value="video" class="h-4 w-4 accent-[var(--mt-red)]" <?= $selectedDisplayType === 'video' ? 'checked' : '' ?> />
                      <span>🎬 วิดีโอ</span>
                    </label>
                    <label id="display-type-gallery-btn" class="display-type-btn relative flex-1 rounded-xl border border-gray-200 bg-white px-4 py-3 text-center transition-all hover:border-gray-300 hover:bg-gray-50 dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                      <input type="radio" name="display_type" value="gallery" class="h-4 w-4 accent-[var(--mt-red)]" <?= $selectedDisplayType === 'gallery' ? 'checked' : '' ?> />
                      <span>🖼️ แกลลอรีภาพนิ่ง</span>
                    </label>
                  </div>
                </div>

                <div id="main-media-container">
                  <label class="mb-1.5 flex items-center text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">
                    สื่อตัวอย่าง (SAMPLE MEDIA)
                    <div class="tooltip-icon">
                      ?
                      <div class="tooltip-box" style="width: 250px;">
                        <strong>โหมดวิดีโอ:</strong> อัปโหลดทีเซอร์ภาพยนตร์ คลิปตัวอย่าง หรือวิดีโอพรีเซนต์ผลงาน<br/>
                        <strong class="mt-1 block">โหมดแกลลอรี:</strong> อัปโหลดภาพตัวอย่างผลงานที่ต้องการแสดงโชว์
                      </div>
                    </div>
                  </label>

                  <div id="youtube-link-container" class="mb-3 transition-all duration-300">
                    <div class="relative">
                      <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-4 w-4 text-[var(--mt-red)]" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                      </div>
                      <input type="url" id="youtube_link" name="youtube_link" class="form-input pl-9 placeholder:text-[#A0A6AD] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="วางลิงก์ YouTube ที่นี่ (หากต้องการใช้แทนไฟล์วิดีโอ)" value="<?= htmlspecialchars((string) ($oldInput['youtube_link'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                    </div>
                    <div class="my-3 flex items-center">
                      <div class="flex-grow border-t border-[#E3E5E8]"></div>
                      <span class="mx-3 text-[10px] font-medium text-[#A0A6AD] uppercase tracking-wider">หรืออัปโหลดไฟล์ (OR)</span>
                      <div class="flex-grow border-t border-[#E3E5E8]"></div>
                    </div>
                  </div>

                  <input id="main_media_file" type="file" class="hidden" name="main_media_file[]" accept=".mp4,.mov,.jpg,.jpeg,.png" />
                  <label for="main_media_file" data-input-id="main_media_file" class="upload-zone flex flex-col items-center justify-center px-4 text-center transition-colors duration-200 hover:border-[var(--mt-red)] focus-within:border-[var(--mt-red)] focus-within:ring-1 focus-within:ring-[var(--mt-red)]">
                    <span class="mb-2 grid h-10 w-10 place-items-center rounded-full bg-[#F5F0EE] text-[#B9A093] dark:bg-rose-950/40 dark:text-rose-400">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 15a4 4 0 014-4h1.1a5 5 0 019.78 1.5A3.5 3.5 0 0116.5 19h-9A4.5 4.5 0 013 15z"/><path d="M10.75 9.5V13a.75.75 0 01-1.5 0V9.5L7.72 11.03a.75.75 0 01-1.06-1.06l2.81-2.81a.75.75 0 011.06 0l2.81 2.81a.75.75 0 11-1.06 1.06L10.75 9.5z"/></svg>
                    </span>
                    <span class="upload-message text-sm font-semibold text-[#31343A] dark:text-gray-300" data-default-message="คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</span>
                    <span id="main-media-accept-hint" class="mt-1 text-xs text-[#A0A6AE]">โหมดวิดีโอ: 1 ไฟล์ (MP4, MOV, JPG, PNG)</span>
                  </label>
                  <div id="main-media-file-list" class="file-list-card mt-2 hidden"></div>
                </div>

                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">ไฟล์รูปเล่ม (THESIS PDF) <span class="font-normal text-[#B1B7BF]">- รองรับเฉพาะ .pdf</span></label>
                  <input id="thesis_pdf_file" type="file" class="hidden" name="thesis_pdf_file" accept=".pdf" />
                  <label for="thesis_pdf_file" data-input-id="thesis_pdf_file" class="upload-zone flex flex-col items-center justify-center border-[#E7B6B1] bg-[#FFF6F5] px-4 text-center transition-colors duration-200 hover:border-[var(--mt-red)] focus-within:border-[var(--mt-red)] focus-within:ring-1 focus-within:ring-[var(--mt-red)] dark:bg-[#1e1e1e] dark:border-gray-700">
                    <span class="mb-2 grid h-10 w-10 place-items-center rounded-full bg-[#FAECEA] text-[#BC8F85] dark:bg-rose-950/40 dark:text-rose-400">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 15a4 4 0 014-4h1.1a5 5 0 019.78 1.5A3.5 3.5 0 0116.5 19h-9A4.5 4.5 0 013 15z"/><path d="M10.75 9.5V13a.75.75 0 01-1.5 0V9.5L7.72 11.03a.75.75 0 01-1.06-1.06l2.81-2.81a.75.75 0 011.06 0l2.81 2.81a.75.75 0 11-1.06 1.06L10.75 9.5z"/></svg>
                    </span>
                    <span class="upload-message text-sm font-semibold text-[#31343A] dark:text-gray-300" data-default-message="คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</span>
                    <span class="mt-1 text-xs text-[#A0A6AE]">รองรับ: .pdf</span>
                  </label>
                  <div id="thesis-pdf-file-list" class="file-list-card mt-2 hidden"></div>
                </div>

                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">ไฟล์โปรเจกต์ (PROJECT FILE) <span class="font-normal text-[#B1B7BF]">(ไม่บังคับ) - รองรับ Archive, Video, Image หรือลิงก์ภายนอก</span></label>
                  <div id="project-link-container" class="mb-3 transition-all duration-300">
                    <div class="relative">
                      <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-4 w-4 text-[var(--mt-red)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                      </div>
                      <input type="url" id="project_link" name="project_link" class="form-input pl-9 placeholder:text-[#A0A6AD] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="วางลิงก์เว็บไซต์หรือเกมภายนอก (ทางเลือกแทนการอัปโหลดไฟล์)" value="<?= htmlspecialchars((string) ($oldInput['project_link'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                    </div>
                    <div class="my-3 flex items-center">
                      <div class="flex-grow border-t border-[#E3E5E8]"></div>
                      <span class="mx-3 text-[10px] font-medium text-[#A0A6AD] uppercase tracking-wider">หรืออัปโหลดไฟล์ (OR)</span>
                      <div class="flex-grow border-t border-[#E3E5E8]"></div>
                    </div>
                  </div>
                  <input id="project_file" type="file" class="hidden" name="project_file" accept=".zip,.rar,.7z,.mp4,.mov,.jpg,.jpeg,.png,.webp" />
                  <label for="project_file" data-input-id="project_file" class="upload-zone flex flex-col items-center justify-center border-[#E8D2C9] bg-[#FFFDFB] px-4 text-center transition-colors duration-200 hover:border-[var(--mt-red)] focus-within:border-[var(--mt-red)] focus-within:ring-1 focus-within:ring-[var(--mt-red)] dark:bg-[#1e1e1e] dark:border-gray-700">
                    <span class="mb-2 grid h-10 w-10 place-items-center rounded-full bg-[#F8F2EE] text-[#C09E8E] dark:bg-rose-950/40 dark:text-rose-400">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 15a4 4 0 014-4h1.1a5 5 0 019.78 1.5A3.5 3.5 0 0116.5 19h-9A4.5 4.5 0 013 15z"/><path d="M10.75 9.5V13a.75.75 0 01-1.5 0V9.5L7.72 11.03a.75.75 0 01-1.06-1.06l2.81-2.81a.75.75 0 011.06 0l2.81 2.81a.75.75 0 11-1.06 1.06L10.75 9.5z"/></svg>
                    </span>
                    <span class="upload-message text-sm font-semibold text-[#31343A] dark:text-gray-300" data-default-message="คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</span>
                    <span class="mt-1 text-xs text-[#A0A6AE]">รองรับ: .zip, .rar, .7z, .mp4, .mov, .jpg, .png</span>
                  </label>
                  <div id="project-file-list" class="file-list-card mt-2 hidden"></div>
                </div>
              </div>
            </section>

            <!-- Upload Progress Bar -->
            <div id="upload-progress-container" class="hidden w-full mb-4">
              <div class="flex justify-between mb-1">
                <span class="text-xs font-medium text-[var(--mt-red)]" id="progress-label">Uploading...</span>
                <span class="text-xs font-medium text-[var(--mt-red)]" id="progress-percent">0%</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                <div id="progress-bar-fill" class="bg-[var(--mt-red)] h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
              </div>
              <p class="text-[10px] text-gray-500 mt-1 italic">กรุณาอย่าปิดหน้าต่างนี้จนกว่าการอัปโหลดจะเสร็จสมบูรณ์</p>
            </div>

            <div class="mb-4">
              <label class="flex items-start gap-2 text-sm leading-relaxed text-[#555F6D] dark:text-gray-300 cursor-pointer">
                <input type="checkbox" name="pdpa_consent" required class="mt-0.5 shrink-0" />
                <span data-th='ข้าพเจ้ายินยอมให้จัดเก็บและเผยแพร่ข้อมูลตาม <a href="#" class="open-privacy-notice font-semibold text-[var(--mt-red)] hover:underline">นโยบายความเป็นส่วนตัว</a> และยอมรับ <a href="#" class="open-terms-notice font-semibold text-[var(--mt-red)] hover:underline">ข้อตกลงและเงื่อนไขการใช้งาน</a>'
                      data-en='I agree to the storage and dissemination of information according to the <a href="#" class="open-privacy-notice font-semibold text-[var(--mt-red)] hover:underline">Privacy Policy</a> and accept the <a href="#" class="open-terms-notice font-semibold text-[var(--mt-red)] hover:underline">Terms and Conditions of Use</a>'>ข้าพเจ้ายินยอมให้จัดเก็บและเผยแพร่ข้อมูลตาม <a href="#" class="open-privacy-notice font-semibold text-[var(--mt-red)] hover:underline">นโยบายความเป็นส่วนตัว</a> และยอมรับ <a href="#" class="open-terms-notice font-semibold text-[var(--mt-red)] hover:underline">ข้อตกลงและเงื่อนไขการใช้งาน</a></span>
              </label>
            </div>

            <div class="relative z-[50] flex items-center gap-3 pb-4 pointer-events-auto" style="position: relative !important; z-index: 50 !important; pointer-events: auto !important;">
              <button id="publish-project-btn" type="submit" class="relative z-[50] pointer-events-auto inline-flex h-11 items-center gap-2 rounded-xl bg-[var(--mt-red)] px-6 text-sm font-semibold text-white transition-all duration-200 hover:-translate-y-[1px] hover:shadow-lg hover:bg-[var(--mt-red-dark)] focus:outline-none focus:ring-2 focus:ring-[var(--mt-red)] focus:ring-offset-2" style="position: relative !important; z-index: 50 !important; pointer-events: auto !important;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3 15a4 4 0 014-4h1.1a5 5 0 019.78 1.5A3.5 3.5 0 0116.5 19h-9A4.5 4.5 0 013 15z"/><path d="M10.75 9.5V13a.75.75 0 01-1.5 0V9.5L7.72 11.03a.75.75 0 01-1.06-1.06l2.81-2.81a.75.75 0 011.06 0l2.81 2.81a.75.75 0 11-1.06 1.06L10.75 9.5z"/></svg>
                Publish Project
              </button>
              <a href="./manage_projects.php" class="relative z-[50] pointer-events-auto inline-flex h-11 items-center gap-2 rounded-xl border border-[#DCE0E5] bg-white px-6 text-sm font-semibold text-[#555F6D] transition-all duration-200 hover:-translate-y-[1px] hover:shadow-sm hover:bg-[#F8FAFC] focus:outline-none focus:ring-2 focus:ring-gray-200 focus:ring-offset-2 dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800" style="position: relative !important; z-index: 50 !important; pointer-events: auto !important;">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L10.414 9H16a1 1 0 110 2h-5.586l2.293 2.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                Back to Dashboard
              </a>
            </div>
          </form>

        </div>
      </main>
    </div>
  </div>

  <template id="author-template">
    <div class="author-card rounded-2xl border border-[#E7E9EC] bg-[#FBFBFC] p-4 relative dark:bg-[#1e1e1e] dark:border-gray-700">
      <button type="button" class="remove-author-btn absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-md border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition hover:bg-[#FFEFEF] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" aria-label="ลบผู้จัดทำ">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
      </button>
      <label class="group mx-auto grid h-16 w-16 cursor-pointer place-items-center overflow-hidden rounded-full border-2 border-dashed border-[#E4B4B8] bg-[#FFF8F8] text-[#D56A74] dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-400">
        <svg xmlns="http://www.w3.org/2000/svg" class="author-photo-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4 5a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-2l-1-1H7L6 5H4z"/><path fill-rule="evenodd" d="M10 8a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd"/></svg>
        <img class="author-photo-preview hidden h-full w-full object-cover" alt="Author photo preview" />
        <input type="file" class="sr-only author-photo-input" accept="image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF" />
      </label>
      <p class="mt-2 text-center text-[11px] text-[#A6ADB6] dark:text-gray-400">Upload Photo</p>
      <label class="mt-3 mb-1 block text-[11px] font-medium text-[#8B919A] dark:text-gray-200">ชื่อ-นามสกุล <span class="text-[var(--mt-red)]">*</span></label>
      <input type="text" class="form-input author-name-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="ชื่อผู้จัดทำคนถัดไป" required />
    </div>
  </template>

  <script>
    (function () {
      const uploadForm = document.getElementById('upload-project-form');
      const submitBtn = document.getElementById('publish-project-btn');
      const authorsContainer = document.getElementById('authors-container');
      const addAuthorBtn = document.getElementById('add-author-btn');
      const authorTemplate = document.getElementById('author-template');
      const submitBtnOriginalHtml = submitBtn instanceof HTMLButtonElement ? submitBtn.innerHTML : '';

      if (uploadForm instanceof HTMLFormElement && submitBtn instanceof HTMLButtonElement) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
        uploadForm.addEventListener('submit', function (e) {
          console.log('Form Submit Triggered!');
          console.log('Submit button clicked');

          // คำนวณขนาดไฟล์ทั้งหมดที่เตรียมจะอัปโหลด
          let totalSize = 0;

          // 1. ไฟล์ Thumbnail
          const thumbInput = document.getElementById('thumbnail_file');
          if (thumbInput && thumbInput.files.length > 0) totalSize += thumbInput.files[0].size;

          // 2. ไฟล์ Main Media (รองรับหลายไฟล์)
          const mainInput = document.getElementById('main_media_file');
          if (mainInput && mainInput.files.length > 0) {
            for (let i = 0; i < mainInput.files.length; i += 1) {
              totalSize += mainInput.files[i].size;
            }
          }

          // 3. ไฟล์ PDF และ Project File
          const pdfInput = document.getElementById('thesis_pdf_file');
          if (pdfInput && pdfInput.files.length > 0) totalSize += pdfInput.files[0].size;
          const projInput = document.getElementById('project_file');
          if (projInput && projInput.files.length > 0) totalSize += projInput.files[0].size;

          // ขีดจำกัดขนาดรวมของไฟล์ที่เลือกในฟอร์ม (สอดคล้องกับฝั่งเซิร์ฟเวอร์ / upload_process.php)
          const MAX_TOTAL_SIZE = 10 * 1024 * 1024 * 1024;
          if (totalSize > MAX_TOTAL_SIZE) {
            alert('ขนาดไฟล์รวมทั้งหมดใหญ่เกินไป (' + (totalSize / (1024 * 1024)).toFixed(2) + ' MB)\nระบบรองรับการอัปโหลดสูงสุดครั้งละ 10 GB กรุณาตรวจสอบขนาดไฟล์อีกครั้ง');
            e.preventDefault(); // หยุดการส่งฟอร์มทันที
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            if (typeof submitBtnOriginalHtml !== 'undefined') submitBtn.innerHTML = submitBtnOriginalHtml;
            return;
          }

          const validationErrors = [];
          const titleThInput = uploadForm.querySelector('input[name="title_th"]');
          const advisorSelect = uploadForm.querySelector('select[name="advisor_id"]');
          const categorySelect = uploadForm.querySelector('select[name="category_id"]');
          const yearSelect = uploadForm.querySelector('select[name="academic_year_id"]');
          const thumbnailInput = document.getElementById('thumbnail_file');
          const thesisInput = document.getElementById('thesis_pdf_file');
          const ytInputCheck = document.getElementById('youtube_link');
          const hasYoutube = ytInputCheck && ytInputCheck.value.trim() !== '';
          const hasMainMedia = (Array.isArray(selectedMainMediaFiles) && selectedMainMediaFiles.length > 0) || hasYoutube;

          if (!(titleThInput instanceof HTMLInputElement) || titleThInput.value.trim() === '') {
            validationErrors.push('กรุณากรอกชื่อผลงาน (ภาษาไทย)');
          }
          if (!(advisorSelect instanceof HTMLSelectElement) || advisorSelect.value.trim() === '') {
            validationErrors.push('กรุณาเลือกอาจารย์ที่ปรึกษา');
          }
          if (!(categorySelect instanceof HTMLSelectElement) || categorySelect.value.trim() === '') {
            validationErrors.push('กรุณาเลือกหมวดหมู่');
          }
          if (!(yearSelect instanceof HTMLSelectElement) || yearSelect.value.trim() === '') {
            validationErrors.push('กรุณาเลือกปีการศึกษา');
          }
          if (!(thumbnailInput instanceof HTMLInputElement) || !thumbnailInput.files || thumbnailInput.files.length === 0) {
            validationErrors.push('กรุณาอัปโหลดรูปปก (Thumbnail)');
          }
          if (!hasMainMedia) {
            validationErrors.push('กรุณาอัปโหลดสื่อหลัก (Main Media)');
          }
          if (!(thesisInput instanceof HTMLInputElement) || !thesisInput.files || thesisInput.files.length === 0) {
            validationErrors.push('กรุณาอัปโหลดไฟล์ Thesis PDF');
          }

          if (validationErrors.length > 0 || !uploadForm.checkValidity()) {
            e.preventDefault(); // หยุดการ Reload หน้าเว็บ
            const messageHtml = validationErrors.map(function (msg) {
              return '<div style="text-align:left;">• ' + msg + '</div>';
            }).join('');
            if (typeof Swal !== 'undefined') {
              Swal.fire({
                icon: 'warning',
                title: 'กรอกข้อมูลไม่ครบ',
                html: messageHtml !== '' ? messageHtml : 'กรุณาตรวจสอบข้อมูลในฟอร์มอีกครั้ง',
                confirmButtonText: 'ตกลง'
              });
            } else {
              alert((validationErrors.length > 0 ? validationErrors.join('\n') : 'กรุณาตรวจสอบข้อมูลในฟอร์มอีกครั้ง'));
            }
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            return;
          }

          if (!uploadForm.checkValidity()) {
            e.preventDefault(); // หยุดการ Reload หน้าเว็บ
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            return;
          }
          // Validation passed — use XHR with progress tracking
          e.preventDefault();
          submitBtn.disabled = true;
          submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
          submitBtn.innerHTML = '<span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span><span>Uploading...</span>';

          var progressContainer = document.getElementById('upload-progress-container');
          var progressBarFill = document.getElementById('progress-bar-fill');
          var progressPercent = document.getElementById('progress-percent');
          var progressLabel = document.getElementById('progress-label');
          if (progressContainer) progressContainer.classList.remove('hidden');

          var formSections = uploadForm.querySelectorAll('section');
          formSections.forEach(function(sec) {
            sec.classList.add('relative');
            if (!sec.querySelector('.section-blocker')) {
              var blocker = document.createElement('div');
              blocker.className = 'section-blocker absolute inset-0 z-[50] bg-white/60 dark:bg-black/60 backdrop-blur-[2px] rounded-2xl cursor-wait';
              sec.appendChild(blocker);
            }
          });

          var formData = new FormData(uploadForm);
          var xhr = new XMLHttpRequest();

          xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) {
              var pct = Math.round((ev.loaded / ev.total) * 100);
              if (progressBarFill) progressBarFill.style.width = pct + '%';
              if (progressPercent) progressPercent.textContent = pct + '%';
              if (pct >= 100 && progressLabel) {
                progressLabel.textContent = 'Processing files, please wait...';
              }
            }
          });

          xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 400) {
              if (xhr.responseURL && xhr.responseURL.indexOf('upload.php') !== -1) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(xhr.responseText, 'text/html');
                var errorBox = doc.querySelector('.bg-\\[\\#FFF5F5\\]');
                
                var errorHtml = 'เกิดข้อผิดพลาดในการประมวลผล (ไฟล์อาจมีขนาดใหญ่เกินกว่าที่เซิร์ฟเวอร์ตั้งค่าไว้ใน php.ini)';
                if (errorBox) {
                  var errors = [];
                  var listItems = errorBox.querySelectorAll('li');
                  for (var i = 0; i < listItems.length; i++) {
                    errors.push('• ' + listItems[i].textContent);
                  }
                  if (errors.length > 0) {
                    errorHtml = '<div style="text-align:left; font-size:14px;">' + errors.join('<br>') + '</div>';
                  }
                }
                
                if (typeof Swal !== 'undefined') {
                  Swal.fire({ icon: 'error', title: 'อัปโหลดไม่สำเร็จ', html: errorHtml, confirmButtonText: 'ตกลง' });
                } else {
                  alert('อัปโหลดไม่สำเร็จ\n' + errorHtml.replace(/<[^>]*>?/gm, ''));
                }
                
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                submitBtn.innerHTML = submitBtnOriginalHtml;
                if (progressContainer) progressContainer.classList.add('hidden');
                if (progressBarFill) progressBarFill.style.width = '0%';
                if (progressPercent) progressPercent.textContent = '0%';
                if (progressLabel) progressLabel.textContent = 'Uploading...';
                document.querySelectorAll('.section-blocker').forEach(function(blocker) {
                  blocker.remove();
                });
                return;
              }
              if (progressLabel) progressLabel.textContent = 'Complete!';
              if (progressBarFill) progressBarFill.style.width = '100%';
              if (progressPercent) progressPercent.textContent = '100%';
              window.location.href = xhr.responseURL || './manage_projects.php?status=success';
            } else {
              var errMsg = 'เกิดข้อผิดพลาดในการอัปโหลด (HTTP ' + xhr.status + ')';
              try { var r = JSON.parse(xhr.responseText); if (r.message) errMsg = r.message; } catch(ex) {}
              if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'อัปโหลดไม่สำเร็จ', text: errMsg, confirmButtonText: 'ตกลง' });
              } else { alert(errMsg); }
              submitBtn.disabled = false;
              submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
              submitBtn.innerHTML = submitBtnOriginalHtml;
              if (progressContainer) progressContainer.classList.add('hidden');
              if (progressBarFill) progressBarFill.style.width = '0%';
              if (progressPercent) progressPercent.textContent = '0%';
              if (progressLabel) progressLabel.textContent = 'Uploading...';
              document.querySelectorAll('.section-blocker').forEach(function(blocker) {
                blocker.remove();
              });
            }
          };

          xhr.onerror = function () {
            var errMsg = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'error', title: 'Connection Error', text: errMsg, confirmButtonText: 'ตกลง' });
            } else { alert(errMsg); }
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
            submitBtn.innerHTML = submitBtnOriginalHtml;
            if (progressContainer) progressContainer.classList.add('hidden');
            if (progressBarFill) progressBarFill.style.width = '0%';
            if (progressPercent) progressPercent.textContent = '0%';
            if (progressLabel) progressLabel.textContent = 'Uploading...';
            document.querySelectorAll('.section-blocker').forEach(function(blocker) {
              blocker.remove();
            });
          };

          xhr.open('POST', uploadForm.action, true);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.send(formData);
        });

        window.addEventListener('pageshow', function () {
          submitBtn.disabled = false;
          submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
          if (submitBtnOriginalHtml !== '') {
            submitBtn.innerHTML = submitBtnOriginalHtml;
          }
        });

        uploadForm.addEventListener('input', function () {
          submitBtn.disabled = false;
          submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
        });
      }

      const AUTHOR_PHOTO_ACCEPT = 'image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF';

      function refreshAuthorPlaceholders() {
        const cards = authorsContainer.querySelectorAll('.author-card');
        cards.forEach(function (card, index) {
          const num = index + 1;
          const nameInput = card.querySelector('input[name^="author_name_"]');
          const photoInput = card.querySelector('input[name^="author_photo_"]');
          if (nameInput instanceof HTMLInputElement) {
            nameInput.name = 'author_name_' + String(num);
            nameInput.placeholder = 'ชื่อผู้จัดทำคนที่ ' + String(num);
          }
          if (photoInput instanceof HTMLInputElement) {
            photoInput.name = 'author_photo_' + String(num);
          }
        });
      }

      if (addAuthorBtn && authorsContainer && authorTemplate) {
        addAuthorBtn.addEventListener('click', function () {
          const nextIndex = authorsContainer.querySelectorAll('.author-card').length + 1;
          const node = authorTemplate.content.firstElementChild.cloneNode(true);
          const photoInput = node.querySelector('.author-photo-input');
          const nameInput = node.querySelector('.author-name-input');

          if (photoInput) {
            photoInput.name = 'author_photo_' + String(nextIndex);
            photoInput.setAttribute('accept', AUTHOR_PHOTO_ACCEPT);
          }
          if (nameInput) {
            nameInput.name = 'author_name_' + String(nextIndex);
            nameInput.placeholder = 'ชื่อผู้จัดทำคนที่ ' + String(nextIndex);
          }

          authorsContainer.appendChild(node);
          if (typeof window.bindRequiredFieldValidation === 'function' && nameInput) {
            window.bindRequiredFieldValidation(nameInput);
          }
        });

        authorsContainer.addEventListener('click', function (event) {
          const target = event.target;
          if (!(target instanceof Element)) {
            return;
          }
          const removeBtn = target.closest('.remove-author-btn');
          if (!removeBtn) {
            return;
          }
          const card = removeBtn.closest('.author-card');
          if (!card) {
            return;
          }
          card.remove();
          refreshAuthorPlaceholders();
        });

        authorsContainer.addEventListener('change', function (event) {
          const target = event.target;
          if (!(target instanceof HTMLInputElement) || target.type !== 'file') {
            return;
          }
          if (!target.classList.contains('author-photo-input') && !String(target.name).startsWith('author_photo_')) {
            return;
          }
          const card = target.closest('.author-card');
          if (!card) {
            return;
          }
          const previewImg = card.querySelector('.author-photo-preview');
          const previewIcon = card.querySelector('.author-photo-icon');
          if (!(previewImg instanceof HTMLImageElement) || !(previewIcon instanceof SVGElement)) {
            return;
          }

          const file = target.files && target.files.length > 0 ? target.files[0] : null;
          if (!file || !file.type.startsWith('image/')) {
            previewImg.classList.add('hidden');
            previewImg.removeAttribute('src');
            previewIcon.classList.remove('hidden');
            return;
          }

          if (target.dataset.previewUrl) {
            URL.revokeObjectURL(target.dataset.previewUrl);
          }

          const previewUrl = URL.createObjectURL(file);
          target.dataset.previewUrl = previewUrl;
          previewImg.src = previewUrl;
          previewImg.classList.remove('hidden');
          previewIcon.classList.add('hidden');
        });
      }

      const uploadZones = document.querySelectorAll('.upload-zone');
      const mainMediaInput = document.getElementById('main_media_file');
      const displayTypeRadios = document.querySelectorAll('input[name="display_type"]');
      const mainMediaHint = document.getElementById('main-media-accept-hint');
      const mainMediaFileList = document.getElementById('main-media-file-list');
      const mainMediaZone = document.querySelector('[data-input-id="main_media_file"]');
      const singleFileConfigs = {
        thumbnail_file: {
          input: document.getElementById('thumbnail_file'),
          zone: document.querySelector('[data-input-id="thumbnail_file"]'),
          list: document.getElementById('thumbnail-file-list')
        },
        thesis_pdf_file: {
          input: document.getElementById('thesis_pdf_file'),
          zone: document.querySelector('[data-input-id="thesis_pdf_file"]'),
          list: document.getElementById('thesis-pdf-file-list')
        },
        project_file: {
          input: document.getElementById('project_file'),
          zone: document.querySelector('[data-input-id="project_file"]'),
          list: document.getElementById('project-file-list')
        }
      };
      let selectedMainMediaFiles = [];

      const setUploadZoneSelectedState = function (zone, fileName) {
        const messageEl = zone.querySelector('.upload-message');
        if (!(messageEl instanceof HTMLElement)) {
          return;
        }
        const defaultMessage = messageEl.dataset.defaultMessage || 'คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่';
        if (fileName) {
          messageEl.textContent = '📄 ' + fileName;
          zone.classList.remove('border-dashed');
          zone.classList.add('border-2', 'border-solid', 'border-emerald-400', 'bg-emerald-50');
          return;
        }
        messageEl.textContent = defaultMessage;
        zone.classList.remove('border-solid', 'border-emerald-400', 'bg-emerald-50');
        if (!zone.classList.contains('border-dashed')) {
          zone.classList.add('border-dashed');
        }
      };

      const getSelectedDisplayType = function () {
        const selected = document.querySelector('input[name="display_type"]:checked');
        return selected instanceof HTMLInputElement ? selected.value : 'video';
      };

      const updateDisplayTypeUI = function (selectedType) {
        const btnVideo = document.getElementById('display-type-video-btn');
        const btnGallery = document.getElementById('display-type-gallery-btn');
        if (!(btnVideo instanceof HTMLElement) || !(btnGallery instanceof HTMLElement)) return;

        if (selectedType === 'video') {
          btnVideo.className = 'display-type-btn relative flex-1 rounded-xl border-2 border-[var(--mt-red)] bg-[#FFF1F1] px-4 py-3 text-center transition-all ring-4 ring-[var(--mt-red)]/20 dark:bg-red-900/20 dark:text-white';
          btnGallery.className = 'display-type-btn relative flex-1 rounded-xl border border-gray-200 bg-white px-4 py-3 text-center transition-all hover:border-gray-300 hover:bg-gray-50 dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800';
        } else {
          btnGallery.className = 'display-type-btn relative flex-1 rounded-xl border-2 border-[var(--mt-red)] bg-[#FFF1F1] px-4 py-3 text-center transition-all ring-4 ring-[var(--mt-red)]/20 dark:bg-red-900/20 dark:text-white';
          btnVideo.className = 'display-type-btn relative flex-1 rounded-xl border border-gray-200 bg-white px-4 py-3 text-center transition-all hover:border-gray-300 hover:bg-gray-50 dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800';
        }
      };

      const formatFileSize = function (bytes) {
        const size = Number(bytes || 0);
        if (size <= 0) return '0 KB';
        if (size >= 1024 * 1024) return (size / (1024 * 1024)).toFixed(1) + ' MB';
        return Math.max(1, Math.round(size / 1024)) + ' KB';
      };

      const isAllowedMainMediaFile = function (file, mode) {
        const name = String(file && file.name ? file.name : '').toLowerCase();
        const ext = name.includes('.') ? name.split('.').pop() : '';
        if (mode === 'gallery') {
          return ['jpg', 'jpeg', 'png', 'webp'].includes(String(ext || ''));
        }
        return ['mp4', 'mov', 'jpg', 'jpeg', 'png'].includes(String(ext || ''));
      };

      const getFileIcon = function (file) {
        const fileType = String(file && file.type ? file.type : '');
        const lowerName = String(file && file.name ? file.name : '').toLowerCase();
        if (fileType.startsWith('image/')) return '🖼️';
        if (fileType.startsWith('video/')) return '🎬';
        if (lowerName.endsWith('.pdf')) return '📄';
        if (lowerName.endsWith('.zip') || lowerName.endsWith('.rar') || lowerName.endsWith('.7z')) return '🗜️';
        return '📁';
      };

      const escapeHtml = function (value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };

      const syncMainMediaInputFiles = function () {
        if (!(mainMediaInput instanceof HTMLInputElement)) {
          return;
        }
        const dt = new DataTransfer();
        selectedMainMediaFiles.forEach(function (file) {
          dt.items.add(file);
        });
        mainMediaInput.files = dt.files;
      };

      const renderMainMediaFileList = function () {
        const ytInputEl = document.getElementById('youtube_link');
        const hasFilesNow = selectedMainMediaFiles.length > 0;
        if (ytInputEl) {
          ytInputEl.disabled = hasFilesNow;
          if (hasFilesNow) ytInputEl.classList.add('opacity-50', 'bg-gray-100', 'cursor-not-allowed');
          else ytInputEl.classList.remove('opacity-50', 'bg-gray-100', 'cursor-not-allowed');
        }
        if (!(mainMediaFileList instanceof HTMLElement) || !(mainMediaZone instanceof HTMLElement)) {
          return;
        }
        mainMediaFileList.innerHTML = '';
        const mode = getSelectedDisplayType();
        if (selectedMainMediaFiles.length === 0) {
          mainMediaFileList.classList.add('hidden');
          mainMediaZone.classList.remove('hidden');
          setUploadZoneSelectedState(mainMediaZone, '');
          return;
        }

        selectedMainMediaFiles.forEach(function (file, index) {
          const isImage = String(file.type || '').startsWith('image/');
          const safeName = escapeHtml(String(file.name || ''));
          const row = document.createElement('div');
          row.className = 'file-list-row relative';
          row.innerHTML =
            '<span class="file-list-icon">' + (isImage ? '🖼️' : '🎬') + '</span>' +
            '<span class="file-list-name" title="' + safeName + '">' + safeName + '</span>' +
            '<span class="file-list-size">' + formatFileSize(file.size) + '</span>' +
            '<button type="button" class="upload-remove-btn absolute right-2 top-2 rounded-md bg-white/90 p-1.5 text-gray-500 transition-all duration-200 hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] focus:outline-none focus:ring-2 focus:ring-[var(--mt-red)] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" aria-label="ลบไฟล์" data-index="' + String(index) + '">&times;</button>';
          mainMediaFileList.appendChild(row);
        });

        if (mode === 'gallery' && selectedMainMediaFiles.length < 10) {
          mainMediaZone.classList.remove('hidden');
          setUploadZoneSelectedState(mainMediaZone, 'เพิ่มไฟล์ได้อีก ' + String(10 - selectedMainMediaFiles.length) + ' รูป');
        } else {
          mainMediaZone.classList.add('hidden');
        }
        mainMediaFileList.classList.remove('hidden');
      };

      const validateCandidateMainMediaFiles = function (candidateFiles) {
        const mode = getSelectedDisplayType();
        if (mode === 'video' && candidateFiles.length > 1) {
          alert('โหมดวิดีโอสามารถเลือกได้เพียง 1 ไฟล์เท่านั้น');
          return false;
        }
        if (mode === 'gallery' && candidateFiles.length > 10) {
          alert('โหมดแกลลอรีสามารถอัปโหลดได้สูงสุด 10 รูป');
          return false;
        }
        for (let i = 0; i < candidateFiles.length; i += 1) {
          if (!isAllowedMainMediaFile(candidateFiles[i], mode)) {
            alert(mode === 'gallery'
              ? 'โหมดแกลลอรีรองรับเฉพาะไฟล์รูปภาพ JPG, JPEG, PNG เท่านั้น'
              : 'โหมดวิดีโอรองรับเฉพาะ MP4, MOV หรือรูปภาพ JPG, JPEG, PNG');
            return false;
          }
        }
        return true;
      };

      const applyMainMediaSelection = function (newFiles, replaceAll) {
        if (!(mainMediaInput instanceof HTMLInputElement)) {
          return;
        }
        const mode = getSelectedDisplayType();
        const incomingFiles = Array.isArray(newFiles) ? newFiles : [];
        const candidateFiles = mode === 'video' || replaceAll
          ? incomingFiles.slice(0, 1)
          : selectedMainMediaFiles.concat(incomingFiles);

        if (!validateCandidateMainMediaFiles(candidateFiles)) {
          mainMediaInput.value = '';
          return;
        }

        selectedMainMediaFiles = candidateFiles;
        syncMainMediaInputFiles();
        renderMainMediaFileList();
      };

      const renderSingleFileList = function (inputId) {
        const config = singleFileConfigs[inputId];
        if (!config) return;
        const input = config.input;
        const zone = config.zone;
        const list = config.list;
        if (!(input instanceof HTMLInputElement) || !(zone instanceof HTMLElement) || !(list instanceof HTMLElement)) {
          return;
        }
        const file = input.files && input.files.length > 0 ? input.files[0] : null;
        list.innerHTML = '';
        if (!file) {
          list.classList.add('hidden');
          zone.classList.remove('hidden');
          setUploadZoneSelectedState(zone, '');
          return;
        }
        const row = document.createElement('div');
        row.className = 'file-list-row relative';
        row.innerHTML =
          '<span class="file-list-icon">' + getFileIcon(file) + '</span>' +
          '<span class="file-list-name" title="' + escapeHtml(file.name) + '">' + escapeHtml(file.name) + '</span>' +
          '<span class="file-list-size">' + formatFileSize(file.size) + '</span>' +
          '<button type="button" class="upload-remove-btn absolute right-2 top-2 rounded-md bg-white/90 p-1.5 text-gray-500 transition-all duration-200 hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] focus:outline-none focus:ring-2 focus:ring-[var(--mt-red)] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" aria-label="ลบไฟล์" data-input-id="' + inputId + '">&times;</button>';
        list.appendChild(row);
        zone.classList.add('hidden');
        list.classList.remove('hidden');
      };

      const applyMainMediaInputMode = function () {
        if (!(mainMediaInput instanceof HTMLInputElement)) {
          return;
        }
        const mode = getSelectedDisplayType();
        if (mode === 'gallery') {
          mainMediaInput.multiple = true;
          mainMediaInput.accept = '.jpg,.jpeg,.png';
          if (mainMediaHint instanceof HTMLElement) {
            mainMediaHint.textContent = 'โหมดแกลลอรี: รูปภาพเท่านั้น (JPG, JPEG, PNG) สูงสุด 10 รูป';
          }
        } else {
          mainMediaInput.multiple = false;
          mainMediaInput.accept = '.mp4,.mov,.jpg,.jpeg,.png';
          if (mainMediaHint instanceof HTMLElement) {
            mainMediaHint.textContent = 'โหมดวิดีโอ: 1 ไฟล์ (MP4, MOV, JPG, PNG)';
          }
        }
        const ytContainer = document.getElementById('youtube-link-container');
        const ytInput = document.getElementById('youtube_link');
        if (mode === 'gallery') {
          if (ytContainer) ytContainer.classList.add('hidden');
          if (ytInput) {
            ytInput.value = '';
            ytInput.dispatchEvent(new Event('input'));
          }
        } else {
          if (ytContainer) ytContainer.classList.remove('hidden');
        }
        selectedMainMediaFiles = [];
        mainMediaInput.value = '';
        syncMainMediaInputFiles();
        if (mainMediaZone instanceof HTMLElement) {
          mainMediaZone.classList.remove('hidden');
        }
        renderMainMediaFileList();
      };

      if (mainMediaFileList instanceof HTMLElement) {
        mainMediaFileList.addEventListener('click', function (event) {
          const target = event.target;
          if (!(target instanceof Element)) return;
          const removeBtn = target.closest('.upload-remove-btn');
          if (!(removeBtn instanceof HTMLButtonElement)) return;
          const index = Number(removeBtn.dataset.index || '-1');
          if (!Number.isInteger(index) || index < 0) return;
          selectedMainMediaFiles.splice(index, 1);
          syncMainMediaInputFiles();
          renderMainMediaFileList();
        });
      }

      Object.keys(singleFileConfigs).forEach(function (inputId) {
        const config = singleFileConfigs[inputId];
        if (!config || !(config.list instanceof HTMLElement) || !(config.input instanceof HTMLInputElement)) return;
        config.list.addEventListener('click', function (event) {
          const target = event.target;
          if (!(target instanceof Element)) return;
          const removeBtn = target.closest('.upload-remove-btn');
          if (!(removeBtn instanceof HTMLButtonElement)) return;
          config.input.value = '';
          renderSingleFileList(inputId);
        });
      });

      displayTypeRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
          updateDisplayTypeUI(getSelectedDisplayType());
          applyMainMediaInputMode();
        });
      });
      updateDisplayTypeUI(getSelectedDisplayType());
      applyMainMediaInputMode();

      uploadZones.forEach(function (zone) {
        const inputId = zone.getAttribute('data-input-id');
        const fileInput = inputId ? document.getElementById(inputId) : zone.querySelector('input[type="file"]');
        if (!(fileInput instanceof HTMLInputElement)) {
          return;
        }

        ['dragenter', 'dragover'].forEach(function (evtName) {
          zone.addEventListener(evtName, function (e) {
            e.preventDefault();
            zone.classList.add('drag-over');
          });
        });

        ['dragleave', 'drop'].forEach(function (evtName) {
          zone.addEventListener(evtName, function (e) {
            e.preventDefault();
            zone.classList.remove('drag-over');
          });
        });

        zone.addEventListener('drop', function (e) {
          const dt = e.dataTransfer;
          if (!dt || !dt.files || dt.files.length === 0) {
            return;
          }
          if (fileInput.id === 'main_media_file') {
            const droppedFiles = Array.from(dt.files);
            applyMainMediaSelection(droppedFiles, false);
            return;
          }
          fileInput.files = dt.files;
          renderSingleFileList(fileInput.id);
        });

        fileInput.addEventListener('change', function (e) {
          const target = e.target;
          if (!(target instanceof HTMLInputElement) || !target.files || target.files.length === 0) {
            setUploadZoneSelectedState(zone, '');
            return;
          }
          if (target.id === 'main_media_file') {
            const pickedFiles = Array.from(target.files);
            const shouldReplace = getSelectedDisplayType() !== 'gallery';
            applyMainMediaSelection(pickedFiles, shouldReplace);
            return;
          }
          renderSingleFileList(target.id);
        });
      });

      const ytLinkInput = document.getElementById('youtube_link');
      const mainZone = document.querySelector('[data-input-id="main_media_file"]');
      if (ytLinkInput && mainZone) {
        ytLinkInput.addEventListener('input', function() {
          const isFilled = this.value.trim() !== '';
          if (mainMediaInput) mainMediaInput.disabled = isFilled;

          if (isFilled) {
            mainZone.classList.add('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
            mainZone.classList.remove('hover:border-[var(--mt-red)]');
          } else {
            mainZone.classList.remove('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
            mainZone.classList.add('hover:border-[var(--mt-red)]');
          }
        });
        ytLinkInput.dispatchEvent(new Event('input'));
      }

      const projectLinkInput = document.getElementById('project_link');
      const projectFileInput = document.getElementById('project_file');
      const projectZone = document.querySelector('[data-input-id="project_file"]');

      function syncProjectFileMutualExclusive() {
        if (!projectLinkInput || !projectZone) return;

        const linkFilled = projectLinkInput.value.trim() !== '';
        const fileSelected = projectFileInput && projectFileInput.files.length > 0;

        if (linkFilled) {
          if (projectFileInput) {
            projectFileInput.disabled = true;
            projectFileInput.value = '';
          }
          projectZone.classList.add('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
          projectZone.classList.remove('hover:border-[var(--mt-red)]');
          const projectList = document.getElementById('project-file-list');
          if (projectList) {
            projectList.classList.add('hidden');
            projectList.innerHTML = '';
          }
        } else if (projectFileInput) {
          projectFileInput.disabled = false;
          projectZone.classList.remove('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
          projectZone.classList.add('hover:border-[var(--mt-red)]');
        }

        if (fileSelected) {
          projectLinkInput.disabled = true;
          projectLinkInput.classList.add('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
        } else if (!linkFilled) {
          projectLinkInput.disabled = false;
          projectLinkInput.classList.remove('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
        }
      }

      if (projectLinkInput) {
        projectLinkInput.addEventListener('input', syncProjectFileMutualExclusive);
      }
      if (projectFileInput) {
        projectFileInput.addEventListener('change', syncProjectFileMutualExclusive);
      }
      syncProjectFileMutualExclusive();
    })();
  </script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const selectWraps = document.querySelectorAll('.select-wrap');
    
    selectWraps.forEach(wrap => {
      const select = wrap.querySelector('select');
      if (!select) return;
      
        // ซ่อน Select แต่ยังคงให้เบราว์เซอร์โฟกัสเพื่อทำ Validation ได้
        select.style.position = 'absolute';
        select.style.opacity = '0';
        select.style.pointerEvents = 'none';
        select.style.height = '100%';
        select.style.width = '100%';
        select.style.top = '0';
        select.style.left = '0';
        select.style.zIndex = '-1';
      
      const originalClasses = select.getAttribute('class') || '';
      
      const customSelect = document.createElement('div');
      customSelect.className = 'custom-select relative w-full h-full cursor-pointer';
      
      const selectedBox = document.createElement('div');
      selectedBox.className = `selected-box flex items-center justify-between w-full bg-white text-[13px] text-[#374151] transition-all hover:border-[var(--mt-red)] pl-3 pr-8 dark:bg-[#1e1e1e] dark:text-gray-200 dark:border-gray-700 ${originalClasses}`;
      selectedBox.style.cursor = 'pointer';
      selectedBox.style.appearance = 'none';
      
      const selectedText = document.createElement('span');
      selectedText.className = 'truncate';
      selectedText.textContent = select.options[select.selectedIndex]?.text || 'Select...';
      selectedBox.appendChild(selectedText);
      
      const originalArrow = wrap.querySelector('svg');
      if (originalArrow) {
        originalArrow.style.top = '50%';
        originalArrow.style.transform = 'translateY(-50%)';
      }
      
      const optionsMenu = document.createElement('div');
      optionsMenu.className = 'options-menu absolute left-0 top-[calc(100%+8px)] w-full min-w-[200px] max-h-[280px] overflow-y-auto bg-white border border-[#E5E7EB] rounded-2xl shadow-[0_12px_30px_rgba(0,0,0,0.12)] z-[9999] opacity-0 invisible translate-y-[-10px] transition-all duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] py-1.5';
      
      Array.from(select.options).forEach((option, index) => {
        if (option.disabled && option.value === '') return;
        
        const optionItem = document.createElement('div');
        optionItem.className = 'option-item flex items-center px-4 py-2.5 mx-1.5 my-0.5 rounded-xl text-[13px] text-[#4B5563] transition-all duration-200 hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] hover:translate-x-1 cursor-pointer dark:text-gray-300 dark:hover:bg-red-900/30 dark:hover:text-red-400';
        
        if (option.value === '') optionItem.classList.add('text-[#9CA3AF]', 'italic');
        optionItem.textContent = option.text;
        
        if (index === select.selectedIndex && option.value !== '') {
          optionItem.classList.add('bg-[#FFF1F1]', 'text-[var(--mt-red)]', 'font-semibold', 'dark:bg-red-900/30', 'dark:text-red-400');
        }
        
        optionItem.addEventListener('click', (e) => {
          e.stopPropagation();
          select.selectedIndex = index;
          selectedText.textContent = option.text;
          select.dispatchEvent(new Event('change'));
          
          optionsMenu.querySelectorAll('.option-item').forEach(item => {
            item.classList.remove('bg-[#FFF1F1]', 'text-[var(--mt-red)]', 'font-semibold', 'dark:bg-red-900/30', 'dark:text-red-400');
            item.classList.add('text-[#4B5563]');
          });
          optionItem.classList.remove('text-[#4B5563]');
          optionItem.classList.add('bg-[#FFF1F1]', 'text-[var(--mt-red)]', 'font-semibold', 'dark:bg-red-900/30', 'dark:text-red-400');
          closeAllSelect();
        });
        
        optionsMenu.appendChild(optionItem);
      });
      
      customSelect.appendChild(selectedBox);
      customSelect.appendChild(optionsMenu);
      wrap.appendChild(customSelect);
      select.addEventListener('change', function () {
        selectedText.textContent = select.options[select.selectedIndex]?.text || 'Select...';
      });
      
      selectedBox.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !optionsMenu.classList.contains('invisible');
        closeAllSelect();
        if (!isOpen) {
          optionsMenu.classList.remove('invisible', 'opacity-0', 'translate-y-[-10px]');
          optionsMenu.classList.add('opacity-100', 'translate-y-0');
          if (originalArrow) {
            originalArrow.style.transform = 'translateY(-50%) rotate(180deg)';
            originalArrow.style.color = 'var(--mt-red)';
          }
          selectedBox.classList.add('border-[var(--mt-red)]', 'ring-2', 'ring-[var(--mt-red)]/20');
        }
      });

        // ดักจับเมื่อฟอร์มถูก Submit แต่ลืมเลือกช่องนี้ (required)
        select.addEventListener('invalid', function(e) {
            e.preventDefault();

            selectedBox.classList.add('border-[var(--mt-red)]', 'ring-2', 'ring-[var(--mt-red)]/20', 'bg-[#FFF1F1]', 'dark:bg-red-900/20');

            wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const labelElement = wrap.parentElement.querySelector('label') || wrap.closest('div').parentElement.querySelector('label');
            const fieldName = labelElement ? labelElement.innerText : 'ข้อมูล';

            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'ข้อมูลไม่ครบถ้วน', text: 'กรุณาเลือก ' + fieldName });
            } else {
                alert('กรุณาเลือก: ' + fieldName);
            }
        });

        select.addEventListener('change', function() {
            selectedBox.classList.remove('border-[var(--mt-red)]', 'ring-2', 'ring-[var(--mt-red)]/20', 'bg-[#FFF1F1]', 'dark:bg-red-900/20');
        });
    });

        // ระบบแจ้งเตือนสำหรับ Input และ Textarea ทั่วไป (ที่ถูกตั้งค่าเป็น required)
        function bindRequiredFieldValidation(field) {
            if (!(field instanceof HTMLElement) || field.dataset.requiredValidationBound === '1') return;
            field.dataset.requiredValidationBound = '1';
            field.addEventListener('invalid', function(e) {
                e.preventDefault();

                this.classList.add('border-[var(--mt-red)]', 'ring-2', 'ring-[var(--mt-red)]/20', 'bg-[#FFF1F1]');

                this.scrollIntoView({ behavior: 'smooth', block: 'center' });

                const labelElement = this.closest('div').querySelector('label') || document.querySelector(`label[for="${this.id}"]`);
                let fieldName = labelElement ? labelElement.innerText.replace(/[\*:]/g, '').trim() : 'ข้อมูล';

                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'ข้อมูลไม่ครบถ้วน', text: 'กรุณากรอก: ' + fieldName });
                } else {
                    alert('กรุณากรอก: ' + fieldName);
                }
            });

            field.addEventListener('input', function() {
                this.classList.remove('border-[var(--mt-red)]', 'ring-2', 'ring-[var(--mt-red)]/20', 'bg-[#FFF1F1]');
            });
        }
        document.querySelectorAll('input[required]:not([type="file"]), textarea[required]').forEach(bindRequiredFieldValidation);
        window.bindRequiredFieldValidation = bindRequiredFieldValidation;
    
    function closeAllSelect() {
      document.querySelectorAll('.options-menu').forEach(menu => {
        menu.classList.remove('opacity-100', 'translate-y-0');
        menu.classList.add('invisible', 'opacity-0', 'translate-y-[-10px]');
      });
      document.querySelectorAll('.select-wrap svg').forEach(icon => {
        icon.style.transform = 'translateY(-50%) rotate(0deg)';
        icon.style.color = '';
      });
      document.querySelectorAll('.selected-box').forEach(box => {
        box.classList.remove('border-[var(--mt-red)]', 'ring-2', 'ring-[var(--mt-red)]/20');
      });
    }
    
    document.addEventListener('click', closeAllSelect);

    const introTextarea = document.querySelector('textarea[name="introduction"]');
    const introCharCount = document.getElementById('intro-char-count');
    if (introTextarea && introCharCount) {
      const updateIntroCharCount = () => {
        introCharCount.textContent = introTextarea.value.length;
      };
      introTextarea.addEventListener('input', updateIntroCharCount);
      updateIntroCharCount();
    }
  });
  </script>

  <div id="author-crop-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/60 backdrop-blur-sm opacity-0 transition-opacity duration-300" aria-hidden="true">
      <div class="modal-inner w-full max-w-md scale-95 rounded-2xl bg-white p-6 shadow-2xl transition-transform duration-300 dark:bg-[#1e1e1e] dark:border dark:border-[#333333]">
          <div class="mb-4 flex items-center justify-between">
              <h3 class="text-xl font-bold text-gray-900 dark:text-white">คอปรูปผู้จัดทำ</h3>
              <button type="button" id="close-author-crop-btn" class="text-2xl leading-none text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
          </div>
          <div class="mb-4 w-full h-[280px] overflow-hidden bg-[#F3F4F6] border border-[#E5E7EB] rounded-xl dark:bg-[#262626] dark:border-[#333333]">
              <img id="author-cropper-image" src="" alt="Crop" class="max-w-full block">
          </div>
          <div class="flex justify-end gap-2">
              <button type="button" id="cancel-author-crop-btn" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-[#333333] dark:text-gray-300 dark:hover:bg-gray-800">ยกเลิก</button>
              <button type="button" id="save-author-crop-btn" class="rounded-lg bg-[var(--mt-red)] px-4 py-2 text-sm font-medium text-white hover:bg-[#B71C1C]">ยืนยันการคอป</button>
          </div>
      </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
        let authorCropper = null;
        let currentAuthorInput = null;
        const authorCropModal = document.getElementById('author-crop-modal');
        const authorCropImage = document.getElementById('author-cropper-image');

        function closeAuthorCropper() {
            if (!authorCropModal) return;
            authorCropModal.classList.add('opacity-0');
            const inner = authorCropModal.querySelector('.modal-inner');
            if (inner) inner.classList.add('scale-95');

            setTimeout(() => {
                authorCropModal.classList.remove('flex');
                authorCropModal.classList.add('hidden');
                authorCropModal.setAttribute('aria-hidden', 'true');
                if (authorCropper) {
                    authorCropper.destroy();
                    authorCropper = null;
                }
            }, 300);
        }

        function openAuthorCropper(file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                authorCropImage.src = event.target.result;
                authorCropModal.classList.remove('hidden');
                authorCropModal.classList.add('flex');
                authorCropModal.setAttribute('aria-hidden', 'false');

                requestAnimationFrame(() => {
                    authorCropModal.classList.remove('opacity-0');
                    const inner = authorCropModal.querySelector('.modal-inner');
                    if (inner) inner.classList.remove('scale-95');
                });

                if (authorCropper) authorCropper.destroy();
                authorCropper = new Cropper(authorCropImage, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 1,
                });
            };
            reader.readAsDataURL(file);
        }

        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('author-photo-input')) {
                const file = e.target.files[0];
                if (!file) return;
                currentAuthorInput = e.target;
                openAuthorCropper(file);
            }
        });

        document.getElementById('close-author-crop-btn')?.addEventListener('click', function() {
            if (currentAuthorInput) currentAuthorInput.value = '';
            closeAuthorCropper();
        });

        document.getElementById('cancel-author-crop-btn')?.addEventListener('click', function() {
            if (currentAuthorInput) currentAuthorInput.value = '';
            closeAuthorCropper();
        });

        authorCropModal?.addEventListener('click', function(e) {
            if (e.target === authorCropModal) {
                if (currentAuthorInput) currentAuthorInput.value = '';
                closeAuthorCropper();
            }
        });

        document.getElementById('save-author-crop-btn')?.addEventListener('click', function() {
            if (!authorCropper || !currentAuthorInput) return;
            const btn = this;
            btn.innerHTML = 'กำลังประมวลผล...';
            btn.disabled = true;

            authorCropper.getCroppedCanvas({ width: 400, height: 400 }).toBlob(function(blob) {
                const originalName = currentAuthorInput.files[0]?.name || 'author_cropped.jpg';
                const croppedFile = new File([blob], originalName, { type: 'image/jpeg', lastModified: Date.now() });

                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(croppedFile);
                currentAuthorInput.files = dataTransfer.files;

                // Update the preview image with the cropped blob
                const authorCard = currentAuthorInput.closest('.author-card');
                if (authorCard) {
                    const previewImg = authorCard.querySelector('.author-photo-preview');
                    if (previewImg) {
                        // Release the old object URL if it exists to avoid memory leaks
                        if (currentAuthorInput.dataset.previewUrl) {
                            URL.revokeObjectURL(currentAuthorInput.dataset.previewUrl);
                        }
                        const newPreviewUrl = URL.createObjectURL(blob);
                        currentAuthorInput.dataset.previewUrl = newPreviewUrl;
                        previewImg.src = newPreviewUrl;
                    }
                }

                btn.innerHTML = 'ยืนยันการคอป';
                btn.disabled = false;
                closeAuthorCropper();
            }, 'image/jpeg', 0.9);
        });
    });
  </script>
  <?php require_once __DIR__ . '/../config/site_lang_script.php'; ?>
  <script>
  (function () {
    var privacyHtmlTh = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
      + '<p style="margin:0 0 12px;"><strong>1.</strong> วัตถุประสงค์การเก็บรวบรวมข้อมูล: ระบบจัดเก็บข้อมูลส่วนบุคคลเพื่อใช้ในการยืนยันตัวตนผู้จัดทำผลงาน จัดทำคลังข้อมูลวิทยานิพนธ์ประจำสาขาวิชาเทคโนโลยีมัลติมีเดีย และใช้เป็นข้อมูลอ้างอิงทางวิชาการ</p>'
      + '<p style="margin:0 0 12px;"><strong>2.</strong> ข้อมูลที่มีการจัดเก็บ: ข้อมูลประจำตัว: ชื่อ-นามสกุล, รหัสนักศึกษา, อีเมลมหาวิทยาลัย, และรายชื่ออาจารย์ที่ปรึกษา | ข้อมูลผลงาน: ชื่อโครงงาน, บทคัดย่อ, ไฟล์รูปภาพปก, ไฟล์วิดีโอ, และไฟล์สื่อมัลติมีเดียที่เกี่ยวข้อง | ข้อมูลการใช้งานระบบ: ประวัติการเข้าสู่ระบบ (Session Log) สำหรับผู้ดูแลระบบเพื่อความปลอดภัย</p>'
      + '<p style="margin:0 0 12px;"><strong>3.</strong> ระยะเวลาการจัดเก็บ: ข้อมูลจะถูกจัดเก็บไว้ตลอดระยะเวลาที่ระบบคลังผลงานเปิดให้บริการเพื่อประโยชน์ทางการศึกษาและการสืบค้นย้อนหลัง</p>'
      + '<p style="margin:0 0 12px;"><strong>4.</strong> การเปิดเผยข้อมูล: ข้อมูลชื่อผู้จัดทำ บทคัดย่อ และไฟล์สื่อมัลติมีเดียจะถูกแสดงผลบนหน้าเว็บไซต์สาธารณะ เพื่อเผยแพร่ผลงานสู่บุคคลภายนอกตามวัตถุประสงค์ของคลังข้อมูล</p>'
      + '<p style="margin:0;"><strong>5.</strong> สิทธิของเจ้าของข้อมูล: ผู้ใช้งานมีสิทธิ์ขอเข้าถึง ขอปรับปรุงข้อมูลให้ถูกต้อง หรือแจ้งลบข้อมูลส่วนบุคคล/ผลงานของตนเองได้ โดยติดต่อผ่านผู้ดูแลระบบหลังบ้านหรือสาขาวิชา</p>'
      + '</div>';

    var privacyHtmlEn = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
      + '<p style="margin:0 0 12px;"><strong>1. Purpose of Data Collection:</strong> The system collects personal data to verify the identity of project creators, curate the thesis repository for the Department of Multimedia Technology, and serve as academic references.</p>'
      + '<p style="margin:0 0 12px;"><strong>2. Collected Data:</strong> Identity Information: Full name, Student ID, University email, and Advisor name(s) | Project Information: Project title, Abstract, Cover image, Video, and related multimedia files | System Usage: Admin access and session logs for security purposes.</p>'
      + '<p style="margin:0 0 12px;"><strong>3. Retention Period:</strong> Data will be retained throughout the operational period of the repository system for educational purposes and retrospective search.</p>'
      + '<p style="margin:0 0 12px;"><strong>4. Data Disclosure:</strong> Author names, abstracts, and multimedia files will be published publicly on the website according to the objectives of the repository.</p>'
      + '<p style="margin:0;"><strong>5. Data Subject Rights:</strong> Users have the right to access, update, or request deletion of their personal data or projects by contacting system administrators or the department.</p>'
      + '</div>';

    var termsHtmlTh = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
      + '<p style="margin:0 0 12px;"><strong>1.</strong> การรับรองสิทธิ์ในผลงาน: ผู้ส่งผลงานต้องรับรองว่าผลงานมัลติมีเดีย (วิดีโอ, แอนิเมชัน, เกม, หรือสื่อปฏิสัมพันธ์) ที่นำเข้าสู่ระบบ เป็นผลงานที่สร้างสรรค์ขึ้นจริง และไม่ละเมิดลิขสิทธิ์ เครื่องหมายการค้า หรือสิทธิในทรัพย์สินทางปัญญาของบุคคลอื่น</p>'
      + '<p style="margin:0 0 12px;"><strong>2.</strong> สิทธิ์ในการเผยแพร่: ผู้ส่งผลงานตกลงยินยอมให้สาขาวิชาเทคโนโลยีมัลติมีเดีย นำผลงาน ภาพประกอบ และข้อมูลจำเพาะ (Metadata) ไปจัดแสดง เผยแพร่ หรือใช้เป็นกรณีศึกษาเพื่อประโยชน์ทางการเรียนการสอนได้</p>'
      + '<p style="margin:0;"><strong>3.</strong> ข้อห้ามในการนำเข้าข้อมูล: ห้ามอัปโหลดไฟล์ที่มีเนื้อหาขัดต่อกฎหมาย ละเมิดความเป็นส่วนตัว มีไวรัสแฝง หรือมีเนื้อหาที่ไม่เหมาะสมเข้าสู่ระบบฐานข้อมูล</p>'
      + '</div>';

    var termsHtmlEn = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
      + '<p style="margin:0 0 12px;"><strong>1. Verification of Rights:</strong> Submitters must certify that multimedia works (videos, animations, games, or interactive media) uploaded to the system are genuinely created and do not infringe on copyrights, trademarks, or intellectual property rights of others.</p>'
      + '<p style="margin:0 0 12px;"><strong>2. Publication Rights:</strong> Submitters agree to allow the Department of Multimedia Technology to showcase, publish, or use the works, illustrations, and metadata as case studies for educational purposes.</p>'
      + '<p style="margin:0;"><strong>3. Prohibitions:</strong> It is strictly prohibited to upload files with illegal content, privacy violations, viruses, or inappropriate materials to the system database.</p>'
      + '</div>';

    function openPolicySwal(titleTh, titleEn, htmlTh, htmlEn) {
      var isDark = document.documentElement.classList.contains('dark');
      var lang = (document.documentElement.lang === 'en' || localStorage.getItem('lang') === 'en') ? 'en' : 'th';
      var title = lang === 'en' ? titleEn : titleTh;
      var html = lang === 'en' ? htmlEn : htmlTh;
      var confirmBtn = lang === 'en' ? 'OK' : 'ตกลง';
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          title: title,
          html: html,
          confirmButtonText: confirmBtn,
          confirmButtonColor: '#D32F2F',
          width: '640px',
          background: isDark ? '#1E1E1E' : '#FFFFFF',
          color: isDark ? '#F3F4F6' : '#111827',
          customClass: {
            confirmButton: 'rounded-xl font-bold px-6 py-2.5 shadow-md'
          }
        });
      }
    }

    document.addEventListener('click', function (e) {
      var privacyLink = e.target.closest('.open-privacy-notice');
      if (privacyLink) {
        e.preventDefault();
        e.stopPropagation();
        openPolicySwal('นโยบายการคุ้มครองข้อมูลส่วนบุคคล (PDPA Privacy Notice)', 'PDPA Privacy Notice', privacyHtmlTh, privacyHtmlEn);
        return;
      }

      var termsLink = e.target.closest('.open-terms-notice');
      if (termsLink) {
        e.preventDefault();
        e.stopPropagation();
        openPolicySwal('ข้อตกลงและเงื่อนไขการนำส่งผลงาน (Submission Terms & Conditions)', 'Submission Terms & Conditions', termsHtmlTh, termsHtmlEn);
        return;
      }
    });
  })();
  </script>
</body>
</html>
