<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
start_secure_session();

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/project_file_proxy.php';

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $tableName]);
        return $stmt->fetch() !== false;
    } catch (Throwable) {
        return false;
    }
}

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE :column_name");
        $stmt->execute([':column_name' => $columnName]);
        return $stmt->fetch() !== false;
    } catch (Throwable) {
        return false;
    }
}

$projectId = (int) ($_GET['id'] ?? 0);
if ($projectId <= 0) {
    $_SESSION['flash_error'] = 'ไม่พบรหัสโปรเจกต์ที่ต้องการแก้ไข';
    header('Location: ./my_projects.php');
    exit;
}

$flashError = (string) ($_SESSION['flash_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$adminName = trim((string) ($_SESSION['user_name'] ?? 'Student'));
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
    error_log('[EDIT_PROJECT] load user failed: ' . $e->getMessage());
}

$project = null;
$members = [];
$advisors = [];
$categories = [];
$academicYears = [];
$fileUrls = [];

try {
    $stmt = $pdo->prepare(
        "SELECT p.*
         FROM projects p
         WHERE p.id = :id
           AND p.uploader_id = :user_id
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $projectId,
        ':user_id' => (int) $_SESSION['user_id'],
    ]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['flash_error'] = 'ไม่พบโปรเจกต์นี้ในระบบ';
        header('Location: ./my_projects.php');
        exit;
    }

    $currAdvisorId = (int) ($project['advisor_id'] ?? 0);
    $advStmt = $pdo->prepare('SELECT id, prefix, full_name, is_active FROM advisors WHERE is_active = 1 OR id = :curr_adv_id ORDER BY full_name ASC');
    $advStmt->execute([':curr_adv_id' => $currAdvisorId]);
    $advisors = $advStmt->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $academicYears = $pdo->query('SELECT id, year FROM academic_years ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);

    if (tableExists($pdo, 'project_members')) {
        $photoExpr = "'' AS photo";
        if (tableHasColumn($pdo, 'project_members', 'avatar_filename')) {
            $photoExpr = 'avatar_filename AS photo';
        }
        $memberStmt = $pdo->prepare("SELECT full_name AS name, $photoExpr FROM project_members WHERE project_id = :id ORDER BY id ASC");
        $memberStmt->execute([':id' => $projectId]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($members === []) {
        $creatorText = trim((string) ($project['creators'] ?? ''));
        if ($creatorText !== '') {
            $parts = preg_split('/\s*,\s*/u', $creatorText);
            if (is_array($parts)) {
                foreach ($parts as $name) {
                    $name = trim((string) $name);
                    if ($name !== '') {
                        $members[] = ['name' => $name, 'photo' => ''];
                    }
                }
            }
        }
    }
    if ($members === []) {
        $members = [['name' => '', 'photo' => '']];
    }

    $fileUrls = json_decode((string) ($project['file_urls'] ?? ''), true);
    $fileUrls = is_array($fileUrls) ? $fileUrls : [];
} catch (Throwable $e) {
    error_log('[EDIT_PROJECT] preload failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'ไม่สามารถโหลดข้อมูลโปรเจกต์ได้';
    header('Location: ./my_projects.php');
    exit;
}

$displayType = (string) ($fileUrls['display_type'] ?? 'video');
$titleTh = (string) ($project['title_th'] ?? '');
$titleEn = (string) ($project['title_en'] ?? '');
$advisorId = (int) ($project['advisor_id'] ?? 0);
$categoryId = (int) ($project['category_id'] ?? 0);
$academicYearId = (int) ($project['academic_year_id'] ?? 0);
$introduction = (string) ($project['introduction'] ?? '');
$existingSecIds = array_filter(array_map('intval', explode(',', (string) ($project['secondary_category_ids'] ?? ''))));

$pageTitle = 'แก้ไขโปรเจกต์';
$pageTitleDataTh = 'แก้ไขโปรเจกต์';
$pageTitleDataEn = 'Edit Project';
$pageSubtitle = 'Student | Dashboard | Edit Project';
$pageSubtitleDataTh = 'นักศึกษา | แดชบอร์ด | แก้ไขโปรเจกต์';
$pageSubtitleDataEn = 'Student | Dashboard | Edit Project';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Project - RMUTI MT Gallery</title>
  <?php require_once __DIR__ . '/../config/site_theme_head.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/../config/site_tailwind_config.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root { --mt-red:#D32F2F; --mt-red-dark:#B71C1C; --bg-secondary:#F4F5F6; --text-muted:#757575; --border:#E0E0E0; }
    body { font-family:'DM Sans',sans-serif; background:var(--bg-secondary); color:#121212; }
    .font-display { font-family:'Cormorant Garamond',serif; }
    .form-input,.form-select { height:44px; width:100%; border:1px solid #E3E5E8; border-radius:12px; background:#fff; padding:0 12px; font-size:13px; color:#374151; outline:none; transition: color .2s ease, border-color .2s ease, box-shadow .2s ease; }
    .form-input:focus,.form-textarea:focus,.form-select:focus { border-color:var(--mt-red); box-shadow:0 0 0 1px var(--mt-red); }
    .form-textarea { width:100%; min-height:96px; border:1px solid #E3E5E8; border-radius:12px; background:#fff; padding:11px 12px; font-size:13px; color:#374151; outline:none; resize:vertical; transition: color .2s ease, border-color .2s ease, box-shadow .2s ease; }
    .upload-zone { border:1px dashed #D7DBE0; border-radius:14px; background:#fff; min-height:112px; cursor:pointer; transition: all .2s ease; }
    .upload-zone:hover { border-color:var(--mt-red); background:#FCFCFD; }
    .upload-zone.drag-over { border-color:#D32F2F; background:#FFF5F5; }
  </style>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
  <?php require_once __DIR__ . '/../config/site_dark_styles.php'; ?>
</head>
<body class="h-screen overflow-hidden dark:bg-[#121212] dark:text-gray-100">
  <div class="flex h-screen overflow-hidden">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="flex-1 flex flex-col h-full min-h-0 overflow-hidden">
      <main class="flex-1 overflow-y-auto p-6 md:p-8">
        <div class="mx-auto max-w-5xl">
          <?php include __DIR__ . '/topbar.php'; ?>

          <?php if ($flashError !== ''): ?>
            <div class="mt-6 rounded-xl border border-[#F8C9C9] bg-[#FFF5F5] p-4 text-sm text-[#9F1D1D]"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <?php if ($flashSuccess !== ''): ?>
            <div class="mt-6 rounded-xl border border-[#BEE5C8] bg-[#F4FFF7] p-4 text-sm text-[#1F6A34]"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <form method="POST" action="./update_project_page.php" enctype="multipart/form-data" class="mt-6 space-y-6">
            <?= csrf_input() ?>
            <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">

            <section class="rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
              <div class="mb-5 flex items-center gap-3">
                <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-[var(--mt-red)] text-[11px] font-semibold text-white">1</span>
                <h2 class="text-[20px] font-semibold text-[#202328] dark:text-white">Project Metadata</h2>
              </div>

              <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Project Title (TH) <span class="text-[var(--mt-red)]">*</span></label>
                  <input type="text" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="title_th" value="<?= htmlspecialchars($titleTh, ENT_QUOTES, 'UTF-8') ?>" required />
                </div>
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Project Title (EN) <span class="text-[var(--mt-red)]">*</span></label>
                  <input type="text" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="title_en" value="<?= htmlspecialchars($titleEn, ENT_QUOTES, 'UTF-8') ?>" required />
                </div>
              </div>

              <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Advisor <span class="text-[var(--mt-red)]">*</span></label>
                  <div class="select-wrap relative w-full">
                    <select name="advisor_id" class="form-select dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required>
                      <option value="" disabled <?= $advisorId === 0 ? 'selected' : '' ?>>Select advisor...</option>
                      <?php foreach ($advisors as $advisor): ?>
                        <option value="<?= (int) $advisor['id'] ?>" <?= (int) $advisor['id'] === $advisorId ? 'selected' : '' ?>>
                          <?= htmlspecialchars(trim((string) (($advisor['prefix'] ?? '') . ' ' . ($advisor['full_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
                  </div>
                </div>
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Main Category (หมวดหมู่หลัก) <span class="text-[var(--mt-red)]">*</span></label>
                  <div class="select-wrap relative w-full">
                    <select name="category_id" class="form-select dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required>
                      <option value="">Select category...</option>
                      <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === $categoryId ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
                  </div>
                </div>
                <div>
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Academic Year <span class="text-[var(--mt-red)]">*</span></label>
                  <div class="select-wrap relative w-full">
                    <select name="academic_year_id" class="form-select dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required>
                      <option value="">Select year...</option>
                      <?php foreach ($academicYears as $year): ?>
                        <option value="<?= (int) $year['id'] ?>" <?= (int) $year['id'] === $academicYearId ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) ($year['year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
                  </div>
                </div>

                <div class="mt-4 col-span-1 md:col-span-2">
                  <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">
                    Mixed Media / Secondary Categories (หมวดหมู่ร่วม & สื่อผสมเพิ่มเติม - เลือกติ๊กได้หลายข้อความ, ไม่บังคับ) <span class="text-[var(--mt-red)]">*</span>
                  </label>
                  <div class="flex flex-wrap gap-2 pt-1">
                    <?php foreach ($categories as $category): ?>
                      <?php
                      $catIdInt = (int) $category['id'];
                      $isSecChecked = in_array($catIdInt, $existingSecIds, true);
                      ?>
                      <label class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#1e1e1e] text-xs font-medium text-gray-700 dark:text-gray-200 cursor-pointer hover:border-[var(--mt-red)] transition-colors shadow-sm">
                        <input type="checkbox" name="secondary_category_ids[]" value="<?= $catIdInt ?>" <?= $isSecChecked ? 'checked' : '' ?> class="w-4 h-4 rounded border-gray-300 text-[var(--mt-red)] focus:ring-[var(--mt-red)]/20" />
                        <span><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <div class="mt-5">
                <div class="mb-2 flex items-center justify-between">
                  <label class="text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Authors (ผู้จัดทำ)</label>
                  <button id="add-author-btn" type="button" class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-[var(--mt-red)] px-4 text-[12px] font-semibold text-white hover:bg-[var(--mt-red-dark)]">
                    <span class="text-base leading-none">+</span> เพิ่มผู้จัดทำ
                  </button>
                </div>
                <div id="authors-container" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <?php foreach ($members as $idx => $member): ?>
                    <?php
                    $existingPhoto = trim((string) ($member['photo'] ?? ''));
                    $existingPhotoBase = $existingPhoto !== '' ? basename(str_replace(["\0", '/', '\\'], '', $existingPhoto)) : '';
                    $existingPhotoUrl = $existingPhotoBase !== '' ? '../uploads/authors/' . rawurlencode($existingPhotoBase) : '';
                    ?>
                    <div class="author-card rounded-2xl border border-[#E7E9EC] bg-[#FBFBFC] p-4 relative dark:bg-[#1e1e1e] dark:border-gray-700">
                      <button type="button" class="remove-author-btn absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-md border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-colors duration-200 hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] dark:!bg-transparent dark:!border-transparent dark:!text-rose-500 dark:hover:!bg-rose-500/10 dark:hover:!text-rose-400" aria-label="ลบผู้จัดทำ">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
                      </button>
                      <label class="group mx-auto grid h-16 w-16 cursor-pointer place-items-center overflow-hidden rounded-full border-2 border-dashed border-[#E4B4B8] bg-[#FFF8F8] text-[#D56A74] dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="author-photo-icon <?= $existingPhotoUrl !== '' ? 'hidden' : '' ?> h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4 5a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-2l-1-1H7L6 5H4z"/><path fill-rule="evenodd" d="M10 8a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd"/></svg>
                        <img class="author-photo-preview <?= $existingPhotoUrl !== '' ? '' : 'hidden' ?> h-full w-full object-cover" alt="Author photo preview" src="<?= htmlspecialchars($existingPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" />
                        <input type="file" class="sr-only author-photo-input" name="member_photo_new[]" accept="image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF" />
                      </label>
                      <div class="mt-2 text-center text-[11px]">
                        <button type="button" class="change-author-photo-btn text-[#2563EB] underline dark:text-blue-400">เปลี่ยนรูป</button>
                      </div>
                      <label class="mt-3 mb-1 block text-[11px] font-medium text-[#8B919A] dark:text-gray-200">ชื่อ-นามสกุล <span class="text-[var(--mt-red)]">*</span></label>
                      <input type="text" name="member_name[]" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" value="<?= htmlspecialchars((string) ($member['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ชื่อผู้จัดทำคนที่ <?= $idx + 1 ?>" required />
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="mt-4">
                <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Introduction <span class="text-[var(--mt-red)]">*</span></label>
                <textarea class="form-textarea dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" name="introduction" required><?= htmlspecialchars($introduction, ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>
            </section>

            <section class="rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
              <div class="mb-2 flex items-center gap-3">
                <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-[var(--mt-red)] text-[11px] font-semibold text-white">2</span>
                <h2 class="text-[20px] font-semibold text-[#202328] dark:text-white">อัปโหลดไฟล์ผลงาน <span class="text-sm text-[#A2A8AF] dark:text-gray-400">(Upload Media)</span></h2>
              </div>

              <?php
              $fileBlocks = [
                  'thumbnail' => ['label' => 'รูปปก (THUMBNAIL)', 'input' => 'thumbnail_file_new', 'accept' => 'image/*'],
                  'main_media' => ['label' => 'สื่อหลัก (MAIN MEDIA)', 'input' => 'main_media_file_new', 'accept' => '.mp4,.mov,.jpg,.jpeg,.png'],
                  'thesis_pdf' => ['label' => 'ไฟล์รูปเล่ม (THESIS PDF)', 'input' => 'thesis_pdf_file_new', 'accept' => '.pdf'],
                  'project_file' => ['label' => 'ไฟล์โปรเจกต์ (PROJECT FILE) (ไม่บังคับ)', 'input' => 'project_file_new', 'accept' => '.zip,.rar,.7z,.mp4,.mov,.jpg,.jpeg,.png,.webp'],
              ];
              ?>
              <div class="space-y-4">
                <?php foreach ($fileBlocks as $key => $meta): ?>
                  <?php
                  $currentFile = $fileUrls[$key] ?? null;
                  // ตรวจสอบว่าเป็น Array แบบกลุ่มไฟล์หรือไม่ (เช่น main_media)
                  if (is_array($currentFile) && isset($currentFile[0])) {
                      if (count($currentFile) > 1) {
                          $currentName = 'อัปโหลดไว้แล้ว ' . count($currentFile) . ' ไฟล์ (Gallery)';
                          $currentLink = (string) ($currentFile[0]['link'] ?? '');
                          $thumbId = (string) ($currentFile[0]['id'] ?? '');
                      } else {
                          $currentName = (string) ($currentFile[0]['name'] ?? '');
                          $currentLink = (string) ($currentFile[0]['link'] ?? '');
                          $thumbId = (string) ($currentFile[0]['id'] ?? '');
                      }
                  } else {
                      $currentName = is_array($currentFile) ? (string) ($currentFile['name'] ?? '') : '';
                      $currentLink = is_array($currentFile) ? (string) ($currentFile['link'] ?? '') : '';
                      $thumbId = is_array($currentFile) ? (string) ($currentFile['id'] ?? '') : '';
                  }
                  $thumbUrl = $key === 'thumbnail' && $thumbId !== '' ? 'https://drive.google.com/thumbnail?id=' . rawurlencode($thumbId) . '&sz=w200' : '';
                  $inputId = $key === 'main_media' ? 'main_media_file' : (string) $meta['input'];
                  ?>
                  <div class="edit-file-block">
                    <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></label>

                    <?php if ($key === 'project_file'): ?>
                      <?php
                      $savedProjectLink = '';
                      if (is_array($currentFile) && project_proxy_is_external_project_file($currentFile)) {
                          $savedProjectLink = project_proxy_external_url($currentFile);
                          if ($currentName === '') {
                              $currentName = 'ลิงก์ภายนอก';
                          }
                          $currentLink = $savedProjectLink;
                      }
                      ?>
                      <div id="project-link-container" class="mb-3 transition-all duration-300">
                        <div class="relative">
                          <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-[var(--mt-red)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                          </div>
                          <input type="url" id="project_link" name="project_link" class="form-input pl-9 placeholder:text-[#A0A6AD] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="วางลิงก์เว็บไซต์หรือเกมภายนอก (ทางเลือกแทนการอัปโหลดไฟล์)" value="<?= htmlspecialchars($savedProjectLink, ENT_QUOTES, 'UTF-8') ?>" />
                        </div>
                        <div class="my-3 flex items-center">
                          <div class="flex-grow border-t border-[#E3E5E8] dark:border-gray-700"></div>
                          <span class="mx-3 text-[10px] font-medium text-[#A0A6AD] uppercase tracking-wider dark:text-gray-400">หรืออัปโหลดไฟล์ (OR)</span>
                          <div class="flex-grow border-t border-[#E3E5E8] dark:border-gray-700"></div>
                        </div>
                      </div>
                    <?php endif; ?>

                    <?php if ($currentName !== ''): ?>
                      <?php
                      $canRemoveProjectFile = $key === 'project_file'
                          && is_array($currentFile)
                          && project_proxy_entry_has_file($currentFile)
                          && !project_proxy_is_external_project_file($currentFile);
                      ?>
                      <div class="current-file-wrap mb-2 rounded-xl border border-[#DCE4ED] bg-[#F8FAFC] p-3 dark:bg-[#1a1a1a] dark:border-gray-700 dark:text-gray-200">
                        <div class="flex items-center justify-between gap-3">
                          <div class="flex min-w-0 items-center gap-2">
                            <span class="text-lg"><?= $key === 'thumbnail' ? '🖼️' : '📄' ?></span>
                            <div class="truncate text-sm dark:text-gray-200"><?= htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8') ?></div>
                          </div>
                          <div class="flex items-center gap-2">
                            <?php if ($currentLink !== ''): ?>
                              <a href="<?= htmlspecialchars($currentLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="text-xs text-[#2563EB] underline dark:text-blue-400">เปิดไฟล์</a>
                            <?php endif; ?>
                            <button type="button" class="change-file-btn rounded-lg border border-[#D2D8E0] px-3 py-1 text-xs font-semibold text-[#475569] dark:!bg-transparent dark:!border-transparent dark:!text-blue-400 dark:hover:!text-blue-300 dark:hover:!bg-blue-400/10 transition-colors" data-target-input-id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>">เปลี่ยนไฟล์</button>
                          </div>
                        </div>
                        <?php if ($canRemoveProjectFile): ?>
                          <label class="mt-3 flex cursor-pointer items-center gap-2 text-xs text-[#B91C1C] dark:text-rose-400">
                            <input type="checkbox" id="remove_project_file" name="remove_project_file" value="1" class="h-3.5 w-3.5 accent-[var(--mt-red)]" />
                            <span>ลบไฟล์โปรเจกต์ที่มีอยู่</span>
                          </label>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <?php if ($key === 'main_media'): ?>
                      <div id="youtube-link-container" class="mb-3 transition-all duration-300">
                        <div class="relative">
                          <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-[var(--mt-red)]" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                          </div>
                          <input type="url" id="youtube_link" name="youtube_link" class="form-input pl-9 placeholder:text-[#A0A6AD] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="วางลิงก์ YouTube ที่นี่ (หากต้องการใช้แทนไฟล์วิดีโอ)" value="<?= htmlspecialchars((string) ($fileUrls['youtube'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                        </div>
                        <div class="my-3 flex items-center">
                          <div class="flex-grow border-t border-[#E3E5E8] dark:border-gray-700"></div>
                          <span class="mx-3 text-[10px] font-medium text-[#A0A6AD] uppercase tracking-wider dark:text-gray-400">หรืออัปโหลดไฟล์ (OR)</span>
                          <div class="flex-grow border-t border-[#E3E5E8] dark:border-gray-700"></div>
                        </div>
                      </div>
                    <?php endif; ?>

                    <input id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>" type="file" name="<?= htmlspecialchars($meta['input'], ENT_QUOTES, 'UTF-8') ?><?= $key === 'main_media' ? '[]' : '' ?>" accept="<?= htmlspecialchars($meta['accept'], ENT_QUOTES, 'UTF-8') ?>" class="hidden update-file-input" data-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                    <label for="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>" data-input-id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>" class="upload-zone <?= $currentName !== '' ? 'hidden' : 'flex' ?> flex-col items-center justify-center px-4 text-center cursor-pointer pointer-events-auto transition-all duration-200 hover:border-[var(--mt-red)] focus-within:border-[var(--mt-red)] focus-within:ring-1 focus-within:ring-[var(--mt-red)]">
                      <span class="mb-2 grid h-10 w-10 place-items-center rounded-full bg-[#F5F0EE] text-[#B9A093]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 15a4 4 0 014-4h1.1a5 5 0 019.78 1.5A3.5 3.5 0 0116.5 19h-9A4.5 4.5 0 013 15z"/><path d="M10.75 9.5V13a.75.75 0 01-1.5 0V9.5L7.72 11.03a.75.75 0 01-1.06-1.06l2.81-2.81a.75.75 0 011.06 0l2.81 2.81a.75.75 0 11-1.06 1.06L10.75 9.5z"/></svg>
                      </span>
                      <span class="upload-message text-sm font-semibold text-[#31343A] dark:text-gray-300" data-default-message="คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</span>
                    </label>
                  </div>
                  <?php if ($key === 'thumbnail'): ?>
                  <div class="mb-4">
                    <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">รูปแบบการแสดงผล (DISPLAY TYPE)</label>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                      <label id="display-type-video-btn" class="display-type-btn relative flex-1 rounded-xl px-4 py-3 text-center transition-all <?= $displayType === 'video' ? 'border-2 border-[var(--mt-red)] bg-[#FFF1F1] ring-4 ring-[var(--mt-red)]/20 dark:bg-red-900/20 dark:text-white' : 'border border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                        <input type="radio" name="display_type" value="video" class="h-4 w-4 accent-[var(--mt-red)]" <?= $displayType === 'video' ? 'checked' : '' ?> />
                        <span>🎬 วิดีโอ</span>
                      </label>
                      <label id="display-type-gallery-btn" class="display-type-btn relative flex-1 rounded-xl px-4 py-3 text-center transition-all <?= $displayType === 'gallery' ? 'border-2 border-[var(--mt-red)] bg-[#FFF1F1] ring-4 ring-[var(--mt-red)]/20 dark:bg-red-900/20 dark:text-white' : 'border border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800' ?>">
                        <input type="radio" name="display_type" value="gallery" class="h-4 w-4 accent-[var(--mt-red)]" <?= $displayType === 'gallery' ? 'checked' : '' ?> />
                        <span>🖼️ แกลลอรีภาพนิ่ง</span>
                      </label>
                    </div>
                  </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </section>

            <div class="w-full pb-4">
              <div id="upload-progress-container" class="hidden w-full mb-4">
                <div class="flex justify-between mb-1">
                  <span class="text-xs font-medium text-[var(--mt-red)]" id="progress-label">Saving Changes...</span>
                  <span class="text-xs font-medium text-[var(--mt-red)]" id="progress-percent">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                  <div id="progress-bar-fill" class="bg-[var(--mt-red)] h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p class="text-[10px] text-gray-500 mt-1 italic">กรุณาอย่าปิดหน้าต่างนี้จนกว่าการบันทึกข้อมูลจะเสร็จสมบูรณ์</p>
              </div>
              <div class="flex items-center gap-3">
              <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-xl bg-[var(--mt-red)] px-6 text-sm font-semibold text-white transition-all duration-200 hover:-translate-y-[1px] hover:shadow-lg hover:bg-[var(--mt-red-dark)] focus:outline-none focus:ring-2 focus:ring-[var(--mt-red)] focus:ring-offset-2">Save Changes</button>
              <a href="./my_projects.php" class="inline-flex h-11 items-center gap-2 rounded-xl border border-[#DCE0E5] bg-white px-6 text-sm font-semibold text-[#555F6D] transition-all duration-200 hover:-translate-y-[1px] hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">Back to My Projects</a>
              </div>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>

  <script>
    (function () {
      const uploadForm = document.querySelector('form[action="./update_project_page.php"]');
      const submitBtn = uploadForm instanceof HTMLFormElement ? uploadForm.querySelector('button[type="submit"]') : null;
      const submitBtnOriginalHtml = submitBtn instanceof HTMLButtonElement ? submitBtn.innerHTML : '';
      const authorsContainer = document.getElementById('authors-container');
      const addAuthorBtn = document.getElementById('add-author-btn');
      const bindAuthorCardEvents = function (card) {
        if (!(card instanceof HTMLElement)) return;
        const removeBtn = card.querySelector('.remove-author-btn');
        const changePhotoBtn = card.querySelector('.change-author-photo-btn');
        const photoInput = card.querySelector('.author-photo-input');
        const previewImg = card.querySelector('.author-photo-preview');
        const previewIcon = card.querySelector('.author-photo-icon');

        if (removeBtn instanceof HTMLButtonElement) {
          removeBtn.addEventListener('click', function () {
            if (!(authorsContainer instanceof HTMLElement) || authorsContainer.children.length <= 1) return;
            card.remove();
          });
        }
        if (changePhotoBtn instanceof HTMLButtonElement && photoInput instanceof HTMLInputElement) {
          changePhotoBtn.addEventListener('click', function () {
            photoInput.click();
          });
        }
        if (photoInput instanceof HTMLInputElement && previewImg instanceof HTMLImageElement && previewIcon instanceof SVGElement) {
          photoInput.addEventListener('change', function () {
            const file = photoInput.files && photoInput.files.length > 0 ? photoInput.files[0] : null;
            if (!file) return;
            const url = URL.createObjectURL(file);
            previewImg.src = url;
            previewImg.classList.remove('hidden');
            previewIcon.classList.add('hidden');
          });
        }
      };

      if (authorsContainer instanceof HTMLElement) {
        authorsContainer.querySelectorAll('.author-card').forEach(bindAuthorCardEvents);
      }
      if (authorsContainer && addAuthorBtn) {
        addAuthorBtn.addEventListener('click', function () {
          const index = authorsContainer.children.length + 1;
          const wrapper = document.createElement('div');
          wrapper.className = 'author-card rounded-2xl border border-[#E7E9EC] bg-[#FBFBFC] p-4 relative dark:bg-[#1e1e1e] dark:border-gray-700';
          wrapper.innerHTML = '<button type="button" class="remove-author-btn absolute right-3 top-3 inline-flex h-7 w-7 items-center justify-center rounded-md border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-colors duration-200 hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] dark:!bg-transparent dark:!border-transparent dark:!text-rose-500 dark:hover:!bg-rose-500/10 dark:hover:!text-rose-400" aria-label="ลบผู้จัดทำ"><svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg></button>' +
            '<label class="group mx-auto grid h-16 w-16 cursor-pointer place-items-center overflow-hidden rounded-full border-2 border-dashed border-[#E4B4B8] bg-[#FFF8F8] text-[#D56A74] dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-400"><svg xmlns="http://www.w3.org/2000/svg" class="author-photo-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4 5a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-2l-1-1H7L6 5H4z"/><path fill-rule="evenodd" d="M10 8a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd"/></svg><img class="author-photo-preview hidden h-full w-full object-cover" alt="Author photo preview"><input type="file" class="sr-only author-photo-input" name="member_photo_new[]" accept="image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF"></label>' +
            '<div class="mt-2 text-center text-[11px]"><button type="button" class="change-author-photo-btn text-[#2563EB] underline dark:text-blue-400">เปลี่ยนรูป</button></div>' +
            '<label class="mt-3 mb-1 block text-[11px] font-medium text-[#8B919A] dark:text-gray-200">ชื่อ-นามสกุล <span class="text-[var(--mt-red)]">*</span></label>' +
            '<input type="text" name="member_name[]" class="form-input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="ชื่อผู้จัดทำคนที่ ' + index + '" required>';
          authorsContainer.appendChild(wrapper);
          bindAuthorCardEvents(wrapper);
          wrapper.querySelectorAll('input[required]:not([type="file"]), textarea[required]').forEach(function (field) {
            if (typeof window.bindRequiredFieldValidation === 'function') {
              window.bindRequiredFieldValidation(field);
            }
          });
        });
      }

      document.querySelectorAll('.change-file-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const targetInputId = btn.getAttribute('data-target-input-id');
          if (!targetInputId) return;
          const input = document.getElementById(targetInputId);
          if (input instanceof HTMLInputElement) {
            const zone = document.querySelector('[data-input-id="' + targetInputId + '"]');
            const currentWrap = btn.closest('.current-file-wrap');
            if (currentWrap instanceof HTMLElement) currentWrap.classList.add('hidden');
            if (zone instanceof HTMLElement) zone.classList.remove('hidden');
            if (zone instanceof HTMLElement) zone.classList.add('flex');
            input.classList.remove('hidden');
            const msg = zone instanceof HTMLElement ? zone.querySelector('.upload-message') : null;
            if (msg) msg.textContent = 'คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่';
            input.click();
          }
        });
      });

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

      document.querySelectorAll('input[name="display_type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
          updateDisplayTypeUI(getSelectedDisplayType());
          applyMainMediaInputMode();
        });
      });
      updateDisplayTypeUI(getSelectedDisplayType());

      const applyMainMediaInputMode = function () {
        const mainMediaInput = document.getElementById('main_media_file');
        if (!mainMediaInput) return;
        const mode = getSelectedDisplayType();
        const ytContainer = document.getElementById('youtube-link-container');
        const ytInput = document.getElementById('youtube_link');

        if (mode === 'gallery') {
          mainMediaInput.multiple = true;
          mainMediaInput.accept = '.jpg,.jpeg,.png';
          if (ytContainer) ytContainer.classList.add('hidden');
          if (ytInput) {
            ytInput.value = '';
            ytInput.dispatchEvent(new Event('input'));
          }
        } else {
          mainMediaInput.multiple = false;
          mainMediaInput.accept = '.mp4,.mov,.jpg,.jpeg,.png';
          if (ytContainer) ytContainer.classList.remove('hidden');
        }
      };
      applyMainMediaInputMode();

      if (uploadForm instanceof HTMLFormElement && submitBtn instanceof HTMLButtonElement) {
        const successRedirect = './my_projects.php?status=success';

        const resetUploadUI = function () {
          submitBtn.disabled = false;
          submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
          submitBtn.innerHTML = submitBtnOriginalHtml;
          const progressContainer = document.getElementById('upload-progress-container');
          const progressBarFill = document.getElementById('progress-bar-fill');
          const progressPercent = document.getElementById('progress-percent');
          const progressLabel = document.getElementById('progress-label');
          if (progressContainer) progressContainer.classList.add('hidden');
          if (progressBarFill) progressBarFill.style.width = '0%';
          if (progressPercent) progressPercent.textContent = '0%';
          if (progressLabel) progressLabel.textContent = 'Saving Changes...';
        };

        uploadForm.addEventListener('submit', function (e) {
          let totalSize = 0;
          const thumbInput = document.getElementById('thumbnail_file') || document.getElementById('thumbnail_file_new');
          if (thumbInput && thumbInput.files.length > 0) totalSize += thumbInput.files[0].size;
          const mainInput = document.getElementById('main_media_file');
          if (mainInput && mainInput.files.length > 0) {
            for (let i = 0; i < mainInput.files.length; i += 1) {
              totalSize += mainInput.files[i].size;
            }
          }
          const pdfInput = document.getElementById('thesis_pdf_file') || document.getElementById('thesis_pdf_file_new');
          if (pdfInput && pdfInput.files.length > 0) totalSize += pdfInput.files[0].size;
          const projInput = document.getElementById('project_file') || document.getElementById('project_file_new');
          if (projInput && projInput.files.length > 0) totalSize += projInput.files[0].size;

          const MAX_TOTAL_SIZE = 10 * 1024 * 1024 * 1024;
          if (totalSize > MAX_TOTAL_SIZE) {
            alert('ขนาดไฟล์รวมทั้งหมดใหญ่เกินไป (' + (totalSize / (1024 * 1024)).toFixed(2) + ' MB)\nระบบรองรับการอัปโหลดสูงสุดครั้งละ 10 GB กรุณาตรวจสอบขนาดไฟล์อีกครั้ง');
            e.preventDefault();
            resetUploadUI();
            return;
          }

          e.preventDefault();
          submitBtn.disabled = true;
          submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
          submitBtn.innerHTML = '<span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white mr-2"></span>Saving...';

          const progressContainer = document.getElementById('upload-progress-container');
          const progressBarFill = document.getElementById('progress-bar-fill');
          const progressPercent = document.getElementById('progress-percent');
          const progressLabel = document.getElementById('progress-label');
          if (progressContainer) progressContainer.classList.remove('hidden');

          const formData = new FormData(uploadForm);
          const xhr = new XMLHttpRequest();

          xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) {
              const pct = Math.round((ev.loaded / ev.total) * 100);
              if (progressBarFill) progressBarFill.style.width = pct + '%';
              if (progressPercent) progressPercent.textContent = pct + '%';
              if (pct >= 100 && progressLabel) {
                progressLabel.textContent = 'Processing files, please wait...';
              }
            }
          });

          xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 400) {
              if (progressLabel) progressLabel.textContent = 'Complete!';
              if (progressBarFill) progressBarFill.style.width = '100%';
              if (progressPercent) progressPercent.textContent = '100%';
              window.location.href = xhr.responseURL || successRedirect;
            } else {
              let errMsg = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล (HTTP ' + xhr.status + ')';
              try {
                const r = JSON.parse(xhr.responseText);
                if (r.message) errMsg = r.message;
              } catch (ex) {}
              if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: errMsg, confirmButtonColor: '#d33', confirmButtonText: 'ตกลง' });
              } else {
                alert(errMsg);
              }
              resetUploadUI();
            }
          };

          xhr.onerror = function () {
            const errMsg = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'error', title: 'Connection Error', text: errMsg, confirmButtonText: 'ตกลง' });
            } else {
              alert(errMsg);
            }
            resetUploadUI();
          };

          xhr.open('POST', uploadForm.action || window.location.href, true);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.send(formData);
        });
      }

      const uploadZones = document.querySelectorAll('.upload-zone');
      uploadZones.forEach(function (zone) {
        const inputId = zone.getAttribute('data-input-id');
        const fileInput = inputId ? document.getElementById(inputId) : null;
        if (!(fileInput instanceof HTMLInputElement)) return;

        zone.addEventListener('click', function () {
          fileInput.click();
        });

        const handleFiles = function (files) {
          if (!files || files.length === 0) return;

          if (inputId === 'main_media_file') {
            const mode = getSelectedDisplayType();
            if (mode === 'gallery' && files.length > 10) {
              alert('โหมดแกลลอรีสามารถอัปโหลดได้สูงสุด 10 รูป');
              fileInput.value = '';
              return;
            }
            if (mode === 'video' && files.length > 1) {
              alert('โหมดวิดีโอสามารถเลือกได้เพียง 1 ไฟล์เท่านั้น');
              fileInput.value = '';
              return;
            }
          }

          fileInput.files = files;
          const msg = zone.querySelector('.upload-message');
          if (msg) {
            if (files.length > 1) {
              msg.textContent = 'เลือกแล้ว ' + files.length + ' ไฟล์';
            } else {
              msg.textContent = '📄 ' + files[0].name;
            }
          }
        };

        zone.addEventListener('drop', function (e) {
          e.preventDefault();
          zone.classList.remove('drag-over');
          const dt = e.dataTransfer;
          handleFiles(dt.files);
        });
        ['dragenter', 'dragover'].forEach(function (evt) {
          zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.add('drag-over'); });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
          zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.remove('drag-over'); });
        });
        fileInput.addEventListener('change', function () {
          handleFiles(fileInput.files);
        });
      });

      // Mutual Exclusivity: YouTube vs File Upload
      const ytLinkInput = document.getElementById('youtube_link');
      const mainZone = document.querySelector('[data-input-id="main_media_file"]');
      const mainMediaInput = document.getElementById('main_media_file');

      if (ytLinkInput && mainZone) {
        ytLinkInput.addEventListener('input', function() {
          const isFilled = this.value.trim() !== '';
          if (mainMediaInput) mainMediaInput.disabled = isFilled;

          const currentWrap = mainZone.closest('.edit-file-block').querySelector('.current-file-wrap');

          if (isFilled) {
            mainZone.classList.add('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
            mainZone.classList.remove('hover:border-[var(--mt-red)]');
            if (currentWrap) currentWrap.classList.add('opacity-40', 'pointer-events-none');
          } else {
            mainZone.classList.remove('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
            mainZone.classList.add('hover:border-[var(--mt-red)]');
            if (currentWrap) currentWrap.classList.remove('opacity-40', 'pointer-events-none');
          }
        });
        ytLinkInput.dispatchEvent(new Event('input'));
      }

      const projectLinkInput = document.getElementById('project_link');
      const projectFileInput = document.getElementById('project_file_new');
      const projectZone = document.querySelector('[data-input-id="project_file_new"]');

      function syncProjectFileMutualExclusive() {
        if (!projectLinkInput || !projectZone) return;

        const linkFilled = projectLinkInput.value.trim() !== '';
        const fileSelected = projectFileInput && projectFileInput.files.length > 0;
        const currentWrap = projectZone.closest('.edit-file-block')?.querySelector('.current-file-wrap');

        if (linkFilled) {
          if (projectFileInput) {
            projectFileInput.disabled = true;
            projectFileInput.value = '';
          }
          projectZone.classList.add('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
          projectZone.classList.remove('hover:border-[var(--mt-red)]');
          if (currentWrap) currentWrap.classList.add('opacity-40', 'pointer-events-none');
        } else if (projectFileInput) {
          projectFileInput.disabled = false;
          projectZone.classList.remove('opacity-40', 'pointer-events-none', 'bg-[#F9FAFB]');
          projectZone.classList.add('hover:border-[var(--mt-red)]');
          if (currentWrap) currentWrap.classList.remove('opacity-40', 'pointer-events-none');
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

      const removeProjectFileCheckbox = document.getElementById('remove_project_file');
      if (removeProjectFileCheckbox && projectZone) {
        removeProjectFileCheckbox.addEventListener('change', function () {
          const currentWrap = projectZone.closest('.edit-file-block')?.querySelector('.current-file-wrap');
          if (removeProjectFileCheckbox.checked) {
            if (currentWrap instanceof HTMLElement) currentWrap.classList.add('opacity-60');
            projectZone.classList.remove('hidden');
            projectZone.classList.add('flex');
            if (projectLinkInput) {
              projectLinkInput.value = '';
              projectLinkInput.dispatchEvent(new Event('input'));
            }
          } else if (currentWrap instanceof HTMLElement) {
            currentWrap.classList.remove('opacity-60');
          }
        });
      }
    })();
  </script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const selectWraps = document.querySelectorAll('.select-wrap');
    
    selectWraps.forEach(wrap => {
      const select = wrap.querySelector('select');
      if (!select) return;
      
      select.style.display = 'none';
      
      const originalClasses = select.getAttribute('class') || '';
      
      const customSelect = document.createElement('div');
      customSelect.className = 'custom-select relative w-full h-full cursor-pointer';
      
      const selectedBox = document.createElement('div');
      selectedBox.className = `selected-box flex items-center justify-between w-full bg-white text-[13px] text-[#374151] transition-all hover:border-[var(--mt-red)] pl-3 pr-8 ${originalClasses}`;
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
        optionItem.className = 'option-item flex items-center px-4 py-2.5 mx-1.5 my-0.5 rounded-xl text-[13px] text-[#4B5563] transition-all duration-200 hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] hover:translate-x-1 cursor-pointer';
        
        if (option.value === '') optionItem.classList.add('text-[#9CA3AF]', 'italic');
        optionItem.textContent = option.text;
        
        if (index === select.selectedIndex && option.value !== '') {
          optionItem.classList.add('bg-[#FFF1F1]', 'text-[var(--mt-red)]', 'font-semibold');
        }
        
        optionItem.addEventListener('click', (e) => {
          e.stopPropagation();
          select.selectedIndex = index;
          selectedText.textContent = option.text;
          select.dispatchEvent(new Event('change'));
          
          optionsMenu.querySelectorAll('.option-item').forEach(item => {
            item.classList.remove('bg-[#FFF1F1]', 'text-[var(--mt-red)]', 'font-semibold');
            item.classList.add('text-[#4B5563]');
          });
          optionItem.classList.remove('text-[#4B5563]');
          optionItem.classList.add('bg-[#FFF1F1]', 'text-[var(--mt-red)]', 'font-semibold');
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

                btn.innerHTML = 'ยืนยันการคอป';
                btn.disabled = false;
                closeAuthorCropper();
            }, 'image/jpeg', 0.9);
        });
    });
  </script>
  <?php require_once __DIR__ . '/../config/site_lang_script.php'; ?>
</body>
</html>

