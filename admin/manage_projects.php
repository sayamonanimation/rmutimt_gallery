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
require_once __DIR__ . '/../config/opendrive_helper.php';
require_once __DIR__ . '/../config/manage_list_state.php';

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
             LIMIT 1"
        );
        $stmt->execute([':table_name' => $tableName]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function extractDriveFileIdFromText(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $value, $m) === 1) {
        return (string) $m[1];
    }
    if (preg_match('#/d/([a-zA-Z0-9_-]+)#', $value, $m) === 1) {
        return (string) $m[1];
    }
    if (preg_match('/^[a-zA-Z0-9_-]{15,}$/', $value) === 1) {
        return $value;
    }
    return '';
}

/**
 * @param array<string,mixed>|null $thumbnailData
 */
function buildDriveThumbnailUrl(?array $thumbnailData): string
{
    if (!is_array($thumbnailData)) {
        return '';
    }
    foreach (['link', 'webContentLink'] as $k) {
        if (!isset($thumbnailData[$k]) || !is_string($thumbnailData[$k])) {
            continue;
        }
        $u = trim($thumbnailData[$k]);
        if ($u === '' || !preg_match('#^https?://#i', $u)) {
            continue;
        }
        if (stripos($u, 'drive.google.com') !== false || stripos($u, 'docs.google.com') !== false) {
            continue;
        }
        return $u;
    }
    $candidate = '';
    if (isset($thumbnailData['id']) && is_string($thumbnailData['id'])) {
        $candidate = trim($thumbnailData['id']);
    }
    if ($candidate === '' && isset($thumbnailData['link']) && is_string($thumbnailData['link'])) {
        $candidate = extractDriveFileIdFromText($thumbnailData['link']);
    }
    if ($candidate === '' && isset($thumbnailData['webContentLink']) && is_string($thumbnailData['webContentLink'])) {
        $candidate = extractDriveFileIdFromText($thumbnailData['webContentLink']);
    }
    if ($candidate === '' && isset($thumbnailData['name']) && is_string($thumbnailData['name'])) {
        $candidate = extractDriveFileIdFromText($thumbnailData['name']);
    }
    if ($candidate === '') {
        return '';
    }
    return './drive_thumbnail.php?id=' . rawurlencode($candidate);
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = 'คำขอไม่ถูกต้องหรือโทเค็นหมดอายุ กรุณาลองใหม่อีกครั้ง';
        header('Location: ' . manage_list_state_url('./manage_projects.php', $_POST));
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $projectId = (int) ($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        $_SESSION['flash_error'] = 'ไม่พบรหัสโครงงานที่ต้องการจัดการ';
        header('Location: ' . manage_list_state_url('./manage_projects.php', $_POST));
        exit;
    }

    if ($action === 'approve' || $action === 'revoke') {
        $newStatus = $action === 'approve' ? 'approved' : 'pending';
        try {
            $stmt = $pdo->prepare('UPDATE projects SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $newStatus, ':id' => $projectId]);
            $_SESSION['flash_success'] = $action === 'approve'
                ? 'อนุมัติโครงงานเรียบร้อยแล้ว'
                : 'ยกเลิกการอนุมัติโครงงานเรียบร้อยแล้ว';
        } catch (Throwable $e) {
            error_log('[MANAGE_PROJECTS] status update failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'ไม่สามารถเปลี่ยนสถานะโครงงานได้';
        }
        header('Location: ' . manage_list_state_url('./manage_projects.php', $_POST));
        exit;
    }

    if ($action === 'delete') {
        try {
            // 1. ดึงข้อมูล file_urls
            $stmtFile = $pdo->prepare('SELECT file_urls FROM projects WHERE id = :id LIMIT 1');
            $stmtFile->execute([':id' => $projectId]);
            $projectData = $stmtFile->fetch(PDO::FETCH_ASSOC);

            $driveDeletedCount = 0;
            $driveError = '';

            if ($projectData && !empty($projectData['file_urls'])) {
                $fileUrls = json_decode((string) $projectData['file_urls'], true);
                $fileUrls = is_array($fileUrls) ? $fileUrls : [];

                try {
                    $od = new OpenDriveHelper();
                    $od->login();
                    $idsToDelete = collect_storage_file_ids_from_file_urls($fileUrls);
                    foreach ($idsToDelete as $fid) {
                        $fid = trim((string) $fid);
                        if ($fid === '') {
                            continue;
                        }
                        try {
                            $od->deleteFile($fid);
                            $driveDeletedCount++;
                        } catch (Throwable $e) {
                            $driveError = 'OpenDrive: ' . $e->getMessage();
                            error_log('[MANAGE_PROJECTS] OpenDrive delete error (ID: ' . $fid . '): ' . $e->getMessage());
                        }
                    }
                } catch (Throwable $e) {
                    $driveError = 'OpenDrive: ' . $e->getMessage();
                    error_log('[MANAGE_PROJECTS] OpenDrive init/delete failed: ' . $e->getMessage());
                }
            }

            // 2. ลบออกจาก DB
            try {
                if (tableExists($pdo, 'project_members')) {
                    try {
                        $avatarStmt = $pdo->prepare('SELECT avatar_filename FROM project_members WHERE project_id = :id');
                        $avatarStmt->execute([':id' => $projectId]);
                        while ($row = $avatarStmt->fetch(PDO::FETCH_ASSOC)) {
                            $fn = trim((string) ($row['avatar_filename'] ?? ''));
                            if ($fn === '') {
                                continue;
                            }
                            $safe = basename($fn);
                            if ($safe === '' || $safe === '.' || $safe === '..') {
                                continue;
                            }
                            $filePath = __DIR__ . '/../uploads/authors/' . $safe;
                            if (file_exists($filePath)) {
                                unlink($filePath);
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('[MANAGE_PROJECTS] author avatar cleanup: ' . $e->getMessage());
                    }
                    $pdo->prepare('DELETE FROM project_members WHERE project_id = :id')->execute([':id' => $projectId]);
                }
            } catch (Throwable $e) {
            }

            if (tableExists($pdo, 'project_files')) {
                try {
                    $pdo->prepare('DELETE FROM project_files WHERE project_id = :id')->execute([':id' => $projectId]);
                } catch (Throwable $e) {
                }
            }

            $stmt = $pdo->prepare('DELETE FROM projects WHERE id = :id');
            $stmt->execute([':id' => $projectId]);

            if ($driveError !== '') {
                $_SESSION['flash_error'] = 'ลบโครงงานแล้ว แต่ข้ามการลบไฟล์ใน OpenDrive: ' . $driveError;
            } else {
                $_SESSION['flash_success'] = 'ลบโครงงานและไฟล์ใน OpenDrive สำเร็จ (' . $driveDeletedCount . ' ไฟล์)';
            }
        } catch (Throwable $e) {
            error_log('[MANAGE_PROJECTS] delete failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'ไม่สามารถลบโครงงานได้: ' . $e->getMessage();
        }
        header('Location: ' . manage_list_state_url('./manage_projects.php', $_POST));
        exit;
    }
}

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
    error_log('[MANAGE_PROJECTS] load user failed: ' . $e->getMessage());
}

$avatar = $adminName !== '' ? (function_exists('mb_substr') ? mb_substr($adminName, 0, 1, 'UTF-8') : substr($adminName, 0, 1)) : 'A';
$avatar = strtoupper((string) $avatar);

$projects = [];
$advisors = [];
$categories = [];
$academicYears = [];
$totalProjects = 0;
$perPage = 8;
$totalPages = 1;
$currentPage = 1;
$offset = 0;
$showFrom = 0;
$showTo = 0;
$listSearchQuery = trim((string) ($_GET['search'] ?? ''));
$listStateSuffix = manage_list_state_append($_GET);

try {
    $hasProjectFilesTable = tableExists($pdo, 'project_files');
    
    // นับจำนวนโปรเจกต์ทั้งหมด
    $countStmt = $pdo->query('SELECT COUNT(*) FROM projects');
    $totalProjects = (int) $countStmt->fetchColumn();
    
    // คำนวณหน้า
    $perPage = 8;
    $totalPages = $totalProjects > 0 ? (int) ceil($totalProjects / $perPage) : 1;
    $currentPage = (int) ($_GET['page'] ?? 1);
    if ($currentPage < 1) $currentPage = 1;
    if ($currentPage > $totalPages) $currentPage = $totalPages;
    
    $offset = ($currentPage - 1) * $perPage;
    $showFrom = $totalProjects > 0 ? $offset + 1 : 0;
    $showTo = $totalProjects > 0 ? min($offset + $perPage, $totalProjects) : 0;

    // เพิ่ม LIMIT และ OFFSET ใน Query
    $sql = $hasProjectFilesTable
        ? "SELECT p.id, p.title_th, p.title_en, p.creators, p.status, p.file_urls,
                  pf.file_id AS thumb_file_id, pf.web_view_link AS thumb_view_link
           FROM projects p
           LEFT JOIN project_files pf
             ON pf.id = (
               SELECT pf2.id
               FROM project_files pf2
               WHERE pf2.project_id = p.id AND pf2.file_type = 'thumbnail'
               ORDER BY pf2.id DESC
               LIMIT 1
             )
           ORDER BY p.id DESC LIMIT $perPage OFFSET $offset"
        : "SELECT p.id, p.title_th, p.title_en, p.creators, p.status, p.file_urls,
                  '' AS thumb_file_id, '' AS thumb_view_link
           FROM projects p
           ORDER BY p.id DESC LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $advisors = $pdo->query('SELECT id, prefix, full_name, is_active FROM advisors ORDER BY full_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $academicYears = $pdo->query('SELECT id, year FROM academic_years ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[MANAGE_PROJECTS] preload data failed: ' . $e->getMessage());
}

$pageTitle = 'จัดการโปรเจกต์';
$pageTitleDataTh = 'จัดการโปรเจกต์';
$pageTitleDataEn = 'Manage Projects';
$pageSubtitle = 'Admin | Dashboard | Manage Projects';
$pageSubtitleDataTh = 'ผู้ดูแล | แดชบอร์ด | จัดการโปรเจกต์';
$pageSubtitleDataEn = 'Admin | Dashboard | Manage Projects';

$topbarRightHtml = '
<div class="w-full sm:w-auto">
  <div class="relative w-full sm:w-[320px]">
    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-[#B4BAC0]">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a.75.75 0 101.06-1.06l-2.25-2.249A6 6 0 009 3zm-4.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" clip-rule="evenodd" /></svg>
    </span>
    <input id="admin-project-search" type="text" value="' . htmlspecialchars($listSearchQuery, ENT_QUOTES, 'UTF-8') . '" placeholder="ค้นหาจากชื่อเรื่อง นักศึกษา หรือสถานะ..." data-th-placeholder="ค้นหาจากชื่อเรื่อง นักศึกษา หรือสถานะ..." data-en-placeholder="Search by title, student, or status..." class="h-9 w-full rounded-lg border border-[#E2E4E8] bg-white pl-9 pr-3 text-xs text-[#444] outline-none placeholder:text-[#B6BBC1] focus:border-[#CDD2D8] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" />
  </div>
</div>';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Projects - RMUTI MT Gallery</title>
  <?php require_once __DIR__ . '/../config/site_theme_head.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/../config/site_tailwind_config.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root { --mt-red:#D32F2F; --mt-red-dark:#B71C1C; --bg-secondary:#F4F5F6; --text-muted:#757575; --border:#E0E0E0; --sidebar-width:260px; }
    body { font-family:'DM Sans',sans-serif; background:var(--bg-secondary); color:#121212; }
    .font-display { font-family:'Cormorant Garamond',serif; }
    body.manage-projects-page .admin-shell {
      height: 100vh;
    }
    body.manage-projects-page main {
      position: relative;
      z-index: 10;
    }
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
    .modal-overlay.open { visibility: visible; pointer-events: auto; }
    .modal-overlay.is-visible { opacity: 1; }
    .modal-panel {
      transition: all 300ms ease-out;
      transform: scale(0.95);
      opacity: 0;
      position: relative !important;
    }
    .modal-overlay.is-visible .modal-panel {
      transform: scale(1);
      opacity: 1;
    }
  </style>
  <?php require_once __DIR__ . '/../config/site_dark_styles.php'; ?>
</head>
<body class="manage-projects-page dark:bg-[#121212] dark:text-gray-100">
  <div class="admin-shell flex h-screen overflow-hidden">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-shell flex-1 flex flex-col h-full overflow-hidden">
      <main class="flex-1 overflow-y-auto p-6 md:p-8">
        <div class="mx-auto max-w-7xl">
          <?php include __DIR__ . '/topbar.php'; ?>
          <?php include __DIR__ . '/flash.php'; ?>

          <section class="mt-6 overflow-hidden rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333]">
            <div class="w-full overflow-x-auto">
            <div class="min-w-[800px]">
            <div class="grid grid-cols-12 gap-4 border-b border-[var(--border)] px-5 py-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-[var(--text-muted)]">
              <div class="col-span-2">Thumbnail</div>
              <div class="col-span-4">Project Title</div>
              <div class="col-span-3">Uploader</div>
              <div class="col-span-1">Status</div>
              <div class="col-span-2 text-right">Actions</div>
            </div>
            <!-- Skeleton Table -->
            <div id="skeleton-table" class="w-full">
              <?php for ($sk = 0; $sk < 5; $sk++): ?>
              <div class="animate-pulse grid grid-cols-12 items-center gap-4 border-b border-[var(--border)] px-5 py-3">
                <div class="col-span-2"><div class="h-12 w-20 rounded-lg bg-gray-200 dark:bg-gray-700"></div></div>
                <div class="col-span-4 space-y-2"><div class="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700"></div><div class="h-3 w-1/2 rounded bg-gray-200 dark:bg-gray-700"></div></div>
                <div class="col-span-3"><div class="h-4 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div></div>
                <div class="col-span-1"><div class="h-6 w-16 rounded-full bg-gray-200 dark:bg-gray-700"></div></div>
                <div class="col-span-2 flex justify-end gap-1.5"><div class="h-8 w-8 rounded-lg bg-gray-200 dark:bg-gray-700"></div><div class="h-8 w-8 rounded-lg bg-gray-200 dark:bg-gray-700"></div></div>
              </div>
              <?php endfor; ?>
            </div>
            <div id="actual-table-data" class="hidden opacity-0 transition-opacity duration-500 w-full">
            <?php if ($projects === []): ?>
              <div class="px-5 py-8 text-center text-sm text-[var(--text-muted)]">ยังไม่มีข้อมูลโครงงานในระบบ</div>
            <?php else: ?>
              <?php foreach ($projects as $project): ?>
                <?php
                $projectId = (int) ($project['id'] ?? 0);
                $titleTh = trim((string) ($project['title_th'] ?? '-'));
                $titleEn = trim((string) ($project['title_en'] ?? ''));
                $creatorText = trim((string) ($project['creators'] ?? '-'));
                $status = (string) ($project['status'] ?? 'pending');
                $isApproved = $status === 'approved';
                $fileUrls = json_decode((string) ($project['file_urls'] ?? ''), true);
                $thumbnailData = is_array($fileUrls) && isset($fileUrls['thumbnail']) && is_array($fileUrls['thumbnail'])
                    ? $fileUrls['thumbnail']
                    : null;
                if (!is_array($thumbnailData)) {
                    $thumbnailData = [];
                }
                if (isset($project['thumb_file_id']) && is_string($project['thumb_file_id']) && trim($project['thumb_file_id']) !== '') {
                    $thumbnailData['id'] = trim($project['thumb_file_id']);
                }
                if (isset($project['thumb_view_link']) && is_string($project['thumb_view_link']) && trim($project['thumb_view_link']) !== '') {
                    $thumbnailData['link'] = trim($project['thumb_view_link']);
                }
                $thumbnailUrl = buildDriveThumbnailUrl($thumbnailData);
                ?>
                <div
                  class="project-row grid grid-cols-12 items-center gap-4 border-b border-[var(--border)] px-5 py-3"
                  data-project-id="<?= $projectId ?>"
                  data-project-title="<?= htmlspecialchars($titleTh, ENT_QUOTES, 'UTF-8') ?>"
                  data-search="<?= htmlspecialchars(strtolower($titleTh . ' ' . $titleEn . ' ' . $creatorText . ' ' . $status), ENT_QUOTES, 'UTF-8') ?>">
                  <div class="col-span-2">
                    <a href="../project_detail.php?id=<?= $projectId ?><?= htmlspecialchars($listStateSuffix, ENT_QUOTES, 'UTF-8') ?>" class="block h-12 w-20 overflow-hidden rounded-lg bg-[#F1F3F5]">
                      <?php if ($thumbnailUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($thumbnailUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Thumbnail" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                      <?php else: ?>
                        <div class="grid h-full w-full place-items-center text-[10px] text-[#9AA0A6]">No Image</div>
                      <?php endif; ?>
                    </a>
                  </div>
                  <div class="col-span-4 min-w-0">
                    <a href="../project_detail.php?id=<?= $projectId ?><?= htmlspecialchars($listStateSuffix, ENT_QUOTES, 'UTF-8') ?>" class="block truncate text-sm font-semibold text-[#1E1E1E] hover:text-[var(--mt-red)] dark:text-gray-100">
                      <?= htmlspecialchars($titleTh, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <div class="truncate text-[11px] text-[#9AA0A6]"><?= htmlspecialchars($titleEn, ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                  <div class="col-span-3 truncate text-sm text-[#5F6368] dark:text-gray-300" title="<?= htmlspecialchars($creatorText, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($creatorText, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="col-span-1">
                    <span
                      id="status-badge-<?= $projectId ?>"
                      data-status="<?= $isApproved ? 'approved' : 'pending' ?>"
                      class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold <?= $isApproved ? 'border border-[#BFE3C6] bg-[#ECF8EE] text-[#2E7D32] dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-400' : 'border border-[#F0D9A4] bg-[#FFF8E8] text-[#B87A00] dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300' ?>">
                      <?= $isApproved ? 'Approved' : 'Pending' ?>
                    </span>
                  </div>
                  <div id="action-buttons-<?= $projectId ?>" class="actions-cell col-span-2 flex justify-end gap-1.5">
                    <?php if ($isApproved): ?>
                      <button
                        id="cancel-btn-<?= $projectId ?>"
                        type="button"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:bg-transparent dark:border-transparent dark:text-orange-400 dark:hover:bg-orange-400/10 dark:hover:text-orange-300"
                        title="Cancel Approval"
                        aria-label="Cancel approval"
                        onclick="openCancelModal(<?= $projectId ?>)"
                        >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                      </button>
                    <?php else: ?>
                      <button
                        id="approve-btn-<?= $projectId ?>"
                        type="button"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#CDE9D2] bg-[#F3FBF4] text-[#2E7D32] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#AFDDB8] hover:bg-[#EAF7EC] hover:text-[#25692B] dark:bg-transparent dark:border-transparent dark:text-emerald-400 dark:hover:bg-emerald-400/10 dark:hover:text-emerald-300"
                        title="Approve"
                        aria-label="Approve project"
                        onclick="openApproveModal(<?= $projectId ?>)"
                        >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.3 7.3a1 1 0 01-1.415 0l-3.3-3.3a1 1 0 111.414-1.414l2.593 2.592 6.593-6.592a1 1 0 011.415 0z" clip-rule="evenodd"/></svg>
                      </button>
                    <?php endif; ?>
                    <button type="button" onclick="openEditPage(<?= $projectId ?>)" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-blue-200 bg-blue-50 text-blue-700 transition-all duration-200 hover:-translate-y-[1px] hover:border-blue-300 hover:bg-blue-100 hover:text-blue-800 dark:!bg-transparent dark:!border-transparent dark:!text-blue-400 dark:hover:!bg-blue-400/10 dark:hover:!text-blue-300" title="Edit" aria-label="Edit project">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg>
                    </button>
                    <button
                      type="button"
                      class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400"
                      onclick="confirmDelete(<?= $projectId ?>, '<?= htmlspecialchars(addslashes($titleTh), ENT_QUOTES, 'UTF-8') ?>')"
                      title="Delete"
                      aria-label="Delete project"
                      >
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.5 2a1 1 0 00-.894.553L7.382 3H5a1 1 0 100 2h.293l.854 10.243A2 2 0 008.14 17h3.72a2 2 0 001.993-1.757L14.707 5H15a1 1 0 100-2h-2.382l-.224-.447A1 1 0 0011.5 2h-3zM9 8a1 1 0 012 0v5a1 1 0 11-2 0V8z" clip-rule="evenodd"/></svg>
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
            </div>
            </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-3">
              <div class="text-[11px] text-[var(--text-muted)]">แสดง <?= (int) $showFrom ?>-<?= (int) $showTo ?> จากทั้งหมด <?= (int) $totalProjects ?> รายการ</div>
              <div class="projects-pagination flex flex-wrap items-center gap-2">
                <?php
                // ดึงค่าหน้าปัจจุบันแบบชัวร์ๆ
                $p_current = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
                $p_total = $totalPages ?? 1; // อ้างอิงตัวแปรหน้าทั้งหมดของคุณ
                $p_current = min((int) $p_total, $p_current);

                if (!function_exists('getProjPageUrl')) {
                    function getProjPageUrl($pageNum) {
                        $q = $_GET;
                        unset($q['status']); // ลบพารามิเตอร์ status ออกจากลิงก์เปลี่ยนหน้า เพื่อป้องกัน Pop-up เด้งซ้ำ
                        $q['page'] = $pageNum;
                        return '?' . http_build_query($q);
                    }
                }
                ?>

                <!-- ปุ่ม Previous -->
                <a href="<?= getProjPageUrl(max(1, $p_current - 1)) ?>" class="inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] px-3 text-[11px] font-medium <?= $p_current <= 1 ? 'pointer-events-none bg-[#F3F4F6] text-[#B0B6BD] dark:bg-[#262626] dark:text-gray-500' : 'bg-white text-[#6B7280] hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800' ?>">Previous</a>

                <!-- ตัวเลขหน้า (ระบบ Ellipsis) -->
                <?php
                $visiblePages = [];
                for ($i = 1; $i <= $p_total; $i++) {
                    if ($i === 1 || $i === $p_total || ($i >= $p_current - 1 && $i <= $p_current + 1)) {
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
                  <?php if ($i === $p_current): ?>
                    <span aria-current="page" data-page-number="<?= (int) $i ?>" style="background:#D32F2F;border-color:#D32F2F;color:#FFFFFF;" class="inline-flex h-8 min-w-[32px] items-center justify-center rounded-md border px-2 text-[11px] font-medium shadow-sm"><?= (int) $i ?></span>
                  <?php else: ?>
                    <a href="<?= getProjPageUrl($i) ?>" data-page-number="<?= (int) $i ?>" class="inline-flex h-8 min-w-[32px] items-center justify-center rounded-md border border-[#DFE2E6] bg-white px-2 text-[11px] font-medium text-[#6B7280] transition-colors hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800"><?= (int) $i ?></a>
                  <?php endif; ?>

                  <?php $lastPage = $i; ?>
                <?php endforeach; ?>

                <!-- ปุ่ม Next -->
                <a href="<?= getProjPageUrl(min($p_total, $p_current + 1)) ?>" class="inline-flex h-8 min-w-[76px] items-center justify-center rounded-md border border-[#DFE2E6] px-3 text-[11px] font-medium <?= $p_current >= $p_total ? 'pointer-events-none bg-[#F3F4F6] text-[#B0B6BD] dark:bg-[#262626] dark:text-gray-500' : 'bg-white text-[#6B7280] hover:bg-[#F8F9FA] dark:bg-[#1e1e1e] dark:text-gray-300 dark:border-[#333333] dark:hover:bg-gray-800' ?>">Next</a>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
      </div>

  <div id="edit-project-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel relative h-[90vh] w-full max-w-6xl overflow-y-auto rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
      <button id="edit-modal-close-x" type="button" class="absolute top-4 right-4 z-[50] text-2xl font-medium text-gray-400 transition-colors hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200" aria-label="Close modal">&times;</button>
      <div class="mb-5 flex items-center justify-between">
        <h3 class="text-xl font-semibold text-[#1F2937] dark:text-white">Edit Project</h3>
        <span id="edit-project-id-badge" class="rounded-full bg-[#F3F4F6] px-3 py-1 text-xs font-semibold text-[#6B7280] dark:bg-[#262626] dark:text-gray-300">ID: -</span>
      </div>

      <form id="edit-project-form" method="POST" action="./update_project_page.php" enctype="multipart/form-data" class="space-y-6">
        <?= csrf_input() ?>
        <input type="hidden" id="edit-project-id-input" name="project_id" value="">
        <input type="hidden" name="return_page" value="<?= (int) $currentPage ?>">
        <input type="hidden" name="return_search" value="<?= htmlspecialchars($listSearchQuery, ENT_QUOTES, 'UTF-8') ?>">
        <section class="rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
          <div class="mb-5 flex items-center gap-3">
            <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-[var(--mt-red)] text-[11px] font-semibold text-white">1</span>
            <h2 class="text-[20px] font-semibold text-[#202328] dark:text-white">Project Metadata</h2>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Project Title (TH)</label>
              <input id="edit-title-th" name="title_th" type="text" class="h-11 w-full rounded-xl border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required />
            </div>
            <div>
              <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Project Title (EN)</label>
              <input id="edit-title-en" name="title_en" type="text" class="h-11 w-full rounded-xl border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" />
            </div>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Advisor</label>
              <div class="select-wrap relative w-full">
                <select id="edit-advisor-id" name="advisor_id" class="h-11 w-full rounded-xl border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20">
                  <option value="">Select advisor...</option>
                  <?php foreach ($advisors as $advisor): ?>
                    <option value="<?= (int) $advisor['id'] ?>"><?= htmlspecialchars(trim((string) (($advisor['prefix'] ?? '') . ' ' . ($advisor['full_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
                <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
              </div>
            </div>
            <div>
              <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Category</label>
              <div class="select-wrap relative w-full">
                <select id="edit-category-id" name="category_id" class="h-11 w-full rounded-xl border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required>
                  <option value="">Select category...</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
                <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
              </div>
            </div>
            <div>
              <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Academic Year</label>
              <div class="select-wrap relative w-full">
                <select id="edit-academic-year-id" name="academic_year_id" class="h-11 w-full rounded-xl border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required>
                  <option value="">Select year...</option>
                  <?php foreach ($academicYears as $year): ?>
                    <option value="<?= (int) $year['id'] ?>"><?= htmlspecialchars((string) ($year['year'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
                <svg xmlns="http://www.w3.org/2000/svg" class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 pointer-events-none text-gray-500 transition-transform duration-300 ease-in-out" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" /></svg>
              </div>
            </div>
          </div>

          <div class="mt-4">
            <div class="mb-2 flex items-center justify-between">
              <span class="text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Students</span>
              <button id="edit-add-member-btn" type="button" class="rounded-lg bg-[var(--mt-red)] px-3 py-1 text-xs font-semibold text-white hover:bg-[var(--mt-red-dark)]">+ Add more</button>
            </div>
            <div id="edit-members-container" class="space-y-2"></div>
          </div>

          <div class="mt-4">
            <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.08em] text-[#8B919A] dark:text-gray-400">Introduction</label>
            <textarea id="edit-introduction" name="introduction" class="min-h-[100px] w-full rounded-xl border border-[#E3E5E8] p-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20"></textarea>
          </div>
        </section>

        <section class="rounded-2xl border border-[var(--border)] bg-white dark:bg-[#1e1e1e] dark:border-[#333333] p-5 md:p-6">
          <div class="mb-5 flex items-center gap-3">
            <span class="inline-grid h-6 w-6 place-items-center rounded-full bg-[var(--mt-red)] text-[11px] font-semibold text-white">2</span>
            <h2 class="text-[20px] font-semibold text-[#202328] dark:text-white">Upload Media</h2>
          </div>
          <div id="edit-files-container" class="space-y-3"></div>
        </section>

        <div class="flex justify-end gap-2">
          <button id="edit-modal-close-btn" type="button" class="rounded-xl border border-[#D7DCE2] bg-white px-5 py-2 text-sm font-semibold text-[#6B7280] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">Close</button>
          <button type="submit" class="rounded-xl bg-[var(--mt-red)] px-5 py-2 text-sm font-semibold text-white">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    const LIST_STATE_SUFFIX = <?= json_encode($listStateSuffix) ?>;

    let statusProjectId = null;
    let currentProjectId = null;

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

    function openApproveModal(projectId) {
      console.log('openApproveModal()', { projectId: projectId });
      statusProjectId = Number(projectId) || 0;
      currentProjectId = statusProjectId;
      const modal = document.getElementById('approve-confirm-modal');
      if (modal instanceof HTMLElement) {
        animateOpen(modal);
        modal.dataset.projectId = String(statusProjectId);
        const confirmBtn = document.getElementById('confirm-approve-btn');
        if (confirmBtn instanceof HTMLButtonElement) {
          confirmBtn.dataset.projectId = String(statusProjectId);
          if (!confirmBtn.dataset.originalHtml) {
            confirmBtn.dataset.originalHtml = confirmBtn.innerHTML;
          }
          confirmBtn.disabled = false;
          confirmBtn.style.pointerEvents = '';
          confirmBtn.classList.remove('opacity-75', 'cursor-not-allowed');
          confirmBtn.innerHTML = confirmBtn.dataset.originalHtml;
        }
      }
    }

    function closeApproveModal() {
      const modalElement = document.getElementById('approve-confirm-modal');
      animateClose(modalElement);
    }

    function openCancelModal(projectId) {
      console.log('openCancelModal()', { projectId: projectId });
      statusProjectId = Number(projectId) || 0;
      currentProjectId = statusProjectId;
      const modal = document.getElementById('cancel-confirm-modal');
      if (modal instanceof HTMLElement) {
        animateOpen(modal);
        modal.dataset.projectId = String(statusProjectId);
        const confirmBtn = document.getElementById('confirm-cancel-btn');
        if (confirmBtn instanceof HTMLButtonElement) {
          confirmBtn.dataset.projectId = String(statusProjectId);
          if (!confirmBtn.dataset.originalHtml) {
            confirmBtn.dataset.originalHtml = confirmBtn.innerHTML;
          }
          confirmBtn.disabled = false;
          confirmBtn.style.pointerEvents = '';
          confirmBtn.classList.remove('opacity-75', 'cursor-not-allowed');
          confirmBtn.innerHTML = confirmBtn.dataset.originalHtml;
        }
      }
    }

    function closeCancelModal() {
      const modalElement = document.getElementById('cancel-confirm-modal');
      animateClose(modalElement);
    }

    function updateStatusUI(projectId, finalStatus) {
      const isApproved = finalStatus === 'approved';
      const badge = document.getElementById('status-badge-' + String(projectId));
      if (badge instanceof HTMLElement) {
        badge.dataset.status = isApproved ? 'approved' : 'pending';
        badge.textContent = isApproved ? 'Approved' : 'Pending';
        badge.className = 'inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold ' + (isApproved
          ? 'border border-[#BFE3C6] bg-[#ECF8EE] text-[#2E7D32] dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-400'
          : 'border border-[#F0D9A4] bg-[#FFF8E8] text-[#B87A00] dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300'
        );
        const row = badge.closest('.project-row');
        if (row && row instanceof HTMLElement) {
          row.dataset.search = String(row.dataset.search || '').replace(/approved|pending/gi, isApproved ? 'approved' : 'pending');
        }
      }

      const actionWrap = document.getElementById('action-buttons-' + String(projectId));
      if (actionWrap instanceof HTMLElement) {
        const row = actionWrap.closest('.project-row');
        const projectTitle = row instanceof HTMLElement ? String(row.dataset.projectTitle || '-') : '-';
        const safeTitle = projectTitle.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        const toggleBtn = isApproved
          ? '<button id="cancel-btn-' + String(projectId) + '" type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:bg-transparent dark:border-transparent dark:text-orange-400 dark:hover:bg-orange-400/10 dark:hover:text-orange-300" title="Cancel Approval" aria-label="Cancel approval" onclick="openCancelModal(' + String(projectId) + ')"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>'
          : '<button id="approve-btn-' + String(projectId) + '" type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#CDE9D2] bg-[#F3FBF4] text-[#2E7D32] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#AFDDB8] hover:bg-[#EAF7EC] hover:text-[#25692B] dark:bg-transparent dark:border-transparent dark:text-emerald-400 dark:hover:bg-emerald-400/10 dark:hover:text-emerald-300" title="Approve" aria-label="Approve project" onclick="openApproveModal(' + String(projectId) + ')"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.3 7.3a1 1 0 01-1.415 0l-3.3-3.3a1 1 0 111.414-1.414l2.593 2.592 6.593-6.592a1 1 0 011.415 0z" clip-rule="evenodd"/></svg></button>';

        const editBtn = '<button type="button" onclick="openEditPage(' + String(projectId) + ')" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-blue-200 bg-blue-50 text-blue-700 transition-all duration-200 hover:-translate-y-[1px] hover:border-blue-300 hover:bg-blue-100 hover:text-blue-800 dark:!bg-transparent dark:!border-transparent dark:!text-blue-400 dark:hover:!bg-blue-400/10 dark:hover:!text-blue-300" title="Edit" aria-label="Edit project"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg></button>';
        const deleteBtn = '<button type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:bg-transparent dark:border-transparent dark:text-rose-500 dark:hover:bg-rose-500/10 dark:hover:text-rose-400" onclick="confirmDelete(' + String(projectId) + ', \'' + safeTitle + '\')" title="Delete" aria-label="Delete project"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.5 2a1 1 0 00-.894.553L7.382 3H5a1 1 0 100 2h.293l.854 10.243A2 2 0 008.14 17h3.72a2 2 0 001.993-1.757L14.707 5H15a1 1 0 100-2h-2.382l-.224-.447A1 1 0 0011.5 2h-3zM9 8a1 1 0 012 0v5a1 1 0 11-2 0V8z" clip-rule="evenodd"/></svg></button>';

        actionWrap.innerHTML = toggleBtn + editBtn + deleteBtn;
      }
    }

    function confirmApprove() {
      console.log('Button Clicked!', { action: 'approved', projectId: statusProjectId });
      console.log('Sending update for ID:', statusProjectId);
      submitStatusChange('approved');
    }

    function confirmCancel() {
      console.log('Button Clicked!', { action: 'pending', projectId: statusProjectId });
      console.log('Sending update for ID:', statusProjectId);
      submitStatusChange('pending');
    }

    async function submitStatusChange(newStatus) {
      console.log('submitStatusChange() called', { newStatus: newStatus, projectId: statusProjectId });
      const projectId = statusProjectId;
      const normalizedStatus = String(newStatus || '').toLowerCase() === 'approved' ? 'approved' : 'pending';
      console.log('currentProjectId=', currentProjectId, 'statusProjectId=', statusProjectId);
      if (!projectId || projectId <= 0) return;

      try {
        const endpoint = './update_status.php';
        console.log('AJAX endpoint:', endpoint);
        const body = new URLSearchParams();
        body.set('csrf_token', CSRF_TOKEN);
        body.set('project_id', String(projectId));
        body.set('new_status', normalizedStatus);

        const resp = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString()
        });

        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || data.success !== true) {
          const msg = (data && data.message) ? data.message : 'ไม่สามารถอัปเดตสถานะได้';
          if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: msg, confirmButtonText: 'ตกลง' });
          } else {
            alert(msg);
          }
          if (typeof window.resetConfirmApproveBtn === 'function') window.resetConfirmApproveBtn();
          if (typeof window.resetConfirmCancelBtn === 'function') window.resetConfirmCancelBtn();
          return;
        }

        console.log('Status updated successfully', data);
        updateStatusUI(projectId, String(data.status || normalizedStatus));
        closeModal();
      } catch (e) {
        console.error('submitStatusChange() error:', e);
        if (typeof Swal !== 'undefined') {
          await Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถอัปเดตสถานะได้', confirmButtonText: 'ตกลง' });
        } else {
          alert('ไม่สามารถอัปเดตสถานะได้');
        }
        if (typeof window.resetConfirmApproveBtn === 'function') window.resetConfirmApproveBtn();
        if (typeof window.resetConfirmCancelBtn === 'function') window.resetConfirmCancelBtn();
      }
    }

    function closeModal() {
      closeApproveModal();
      closeCancelModal();
    }

    // expose handlers explicitly for inline onclick fallback
    window.openApproveModal = openApproveModal;
    window.openCancelModal = openCancelModal;
    window.closeApproveModal = closeApproveModal;
    window.closeCancelModal = closeCancelModal;
    window.closeDeleteModal = closeDeleteModal;
    window.confirmApprove = confirmApprove;
    window.confirmCancel = confirmCancel;

    function openEditPage(id) {
      window.location.href = './edit_project.php?id=' + encodeURIComponent(String(id || '')) + LIST_STATE_SUFFIX;
    }

    function confirmDelete(id, title) {
      const modal = document.getElementById('delete-project-modal');
      const projectNameEl = document.getElementById('delete-project-name');
      const projectIdInput = document.getElementById('delete-project-id-input');
      if (!(modal instanceof HTMLDivElement) || !(projectNameEl instanceof HTMLElement) || !(projectIdInput instanceof HTMLInputElement)) {
        return;
      }
      projectNameEl.textContent = String(title || '-');
      projectIdInput.value = String(id || '');
      animateOpen(modal);
    }

    function resetDeleteSubmitButton() {
      const button = document.getElementById('confirm-delete-btn');
      if (!(button instanceof HTMLButtonElement)) return;
      button.disabled = false;
      button.style.pointerEvents = '';
      button.classList.remove('opacity-75', 'cursor-not-allowed');
      button.innerHTML = button.dataset.originalHtml || 'Delete';
    }

    function closeDeleteModal() {
      const modalElement = document.getElementById('delete-project-modal');
      animateClose(modalElement);
      resetDeleteSubmitButton();
    }

    (function () {
      const cleanupBlockingOverlays = function () {
        const allowedModalIds = new Set(['delete-project-modal', 'edit-project-modal', 'approve-confirm-modal', 'cancel-confirm-modal', 'profile-modal']);
        const overlays = document.querySelectorAll('.modal-backdrop, .fixed.inset-0');
        overlays.forEach(function (el) {
          if (!(el instanceof HTMLElement)) return;
          if (allowedModalIds.has(el.id)) return;
          if (el.classList.contains('swal2-container')) return;
          el.remove();
        });
      };

      document.addEventListener('DOMContentLoaded', function () {
        cleanupBlockingOverlays();
        closeModal();
      });
      window.addEventListener('load', function () {
        cleanupBlockingOverlays();
        closeModal();
      });

      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('status') === 'success') {
        Swal.fire({
          icon: 'success',
          title: 'สำเร็จ!',
          text: 'บันทึกโครงการเรียบร้อยแล้ว',
          confirmButtonColor: '#d33',
          confirmButtonText: 'ตกลง'
        }).then(function () {
          urlParams.delete('status');
          const qs = urlParams.toString();
          window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
        });
      } else if (urlParams.get('status') === 'error') {
        Swal.fire({
          icon: 'error',
          title: 'เกิดข้อผิดพลาด',
          text: 'ไม่สามารถอัปเดตข้อมูลผลงานได้',
          confirmButtonColor: '#d33',
          confirmButtonText: 'ตกลง'
        }).then(function () {
          urlParams.delete('status');
          const qs = urlParams.toString();
          window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
        });
      }

      const searchInput = document.getElementById('admin-project-search');
      const rows = document.querySelectorAll('.project-row');
      const applySearchFilter = function (keyword) {
        const normalized = String(keyword || '').trim().toLowerCase();
        rows.forEach(function (row) {
          if (!(row instanceof HTMLElement)) {
            return;
          }
          const haystack = String(row.dataset.search || '');
          row.style.display = normalized === '' || haystack.includes(normalized) ? '' : 'none';
        });
      };
      const syncSearchToUrl = function (keyword) {
        const params = new URLSearchParams(window.location.search);
        const trimmed = String(keyword || '').trim();
        if (trimmed === '') {
          params.delete('search');
        } else {
          params.set('search', trimmed);
        }
        const qs = params.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
      };
      if (searchInput instanceof HTMLInputElement) {
        applySearchFilter(searchInput.value);
        let searchDebounceTimer = null;
        searchInput.addEventListener('input', function () {
          const keyword = searchInput.value;
          applySearchFilter(keyword);
          if (searchDebounceTimer !== null) {
            window.clearTimeout(searchDebounceTimer);
          }
          searchDebounceTimer = window.setTimeout(function () {
            syncSearchToUrl(keyword);
          }, 300);
        });
      }

      const closeBtn = document.getElementById('delete-modal-close-btn');
      const closeXBtn = document.getElementById('delete-modal-close-x');
      closeDeleteModal();

      if (closeBtn instanceof HTMLButtonElement) {
        closeBtn.addEventListener('click', closeDeleteModal);
      }
      if (closeXBtn instanceof HTMLButtonElement) {
        closeXBtn.addEventListener('click', closeDeleteModal);
      }
      const modal = document.getElementById('delete-project-modal');
      if (modal instanceof HTMLDivElement) {
        modal.addEventListener('click', function (event) {
          if (event.target === modal) {
            closeDeleteModal();
          }
        });
      }

      const editModal = document.getElementById('edit-project-modal');
      const editCloseBtn = document.getElementById('edit-modal-close-btn');
      const editCloseXBtn = document.getElementById('edit-modal-close-x');
      const editProjectIdBadge = document.getElementById('edit-project-id-badge');
      const editProjectIdInput = document.getElementById('edit-project-id-input');
      const editMembersContainer = document.getElementById('edit-members-container');
      const editAddMemberBtn = document.getElementById('edit-add-member-btn');
      const editFilesContainer = document.getElementById('edit-files-container');
      const editTitleTh = document.getElementById('edit-title-th');
      const editTitleEn = document.getElementById('edit-title-en');
      const editAdvisorId = document.getElementById('edit-advisor-id');
      const editCategoryId = document.getElementById('edit-category-id');
      const editAcademicYearId = document.getElementById('edit-academic-year-id');
      const editIntroduction = document.getElementById('edit-introduction');

      const fileLabels = {
        thumbnail: 'Thumbnail',
        main_media: 'Main Media',
        thesis_pdf: 'Thesis PDF',
        project_file: 'Project File'
      };

      const closeEditModal = function () {
        if (!(editModal instanceof HTMLDivElement)) return;
        animateClose(editModal);
      };
      const openEditModal = function () {
        if (!(editModal instanceof HTMLDivElement)) return;
        animateOpen(editModal);
      };
      closeEditModal();

      const renderMembers = function (members) {
        if (!(editMembersContainer instanceof HTMLElement)) return;
        editMembersContainer.innerHTML = '';
        const list = Array.isArray(members) && members.length > 0 ? members : [{ name: '' }];
        list.forEach(function (member, index) {
          const wrap = document.createElement('div');
          wrap.className = 'grid grid-cols-[1fr_auto] gap-2';
          wrap.innerHTML = '<div class="space-y-2">' +
            '<input type="text" name="member_name[]" class="h-10 w-full rounded-lg border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" value="' + String(member.name || '') + '" placeholder="ชื่อ-นามสกุล ผู้จัดทำ #' + (index + 1) + '">' +
            '<input type="file" class="h-10 w-full rounded-lg border border-[#E3E5E8] px-2 py-1 text-xs author-photo-input" name="member_photo_new[]" accept="image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF">' +
            '</div>' +
            '<button type="button" class="member-remove-btn h-10 rounded-lg border border-[#F1C8C8] px-3 text-xs font-semibold text-[#B91C1C] hover:bg-[#FFF5F5]">ลบ</button>';
          editMembersContainer.appendChild(wrap);
        });
        editMembersContainer.querySelectorAll('.member-remove-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const row = btn.closest('div');
            if (!(row instanceof HTMLElement) || !(editMembersContainer instanceof HTMLElement)) return;
            if (editMembersContainer.children.length <= 1) return;
            row.remove();
          });
        });
      };

      const appendEmptyMember = function () {
        if (!(editMembersContainer instanceof HTMLElement)) return;
        const index = editMembersContainer.children.length + 1;
        const wrap = document.createElement('div');
        wrap.className = 'grid grid-cols-[1fr_auto] gap-2';
        wrap.innerHTML = '<div class="space-y-2">' +
          '<input type="text" name="member_name[]" class="h-10 w-full rounded-lg border border-[#E3E5E8] px-3 text-sm dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" value="" placeholder="ชื่อ-นามสกุล ผู้จัดทำ #' + index + '">' +
          '<input type="file" class="h-10 w-full rounded-lg border border-[#E3E5E8] px-2 py-1 text-xs author-photo-input" name="member_photo_new[]" accept="image/*,image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,.JPG,.JPEG,.PNG,.WEBP,.GIF">' +
          '</div>' +
          '<button type="button" class="member-remove-btn h-10 rounded-lg border border-[#F1C8C8] px-3 text-xs font-semibold text-[#B91C1C] hover:bg-[#FFF5F5]">ลบ</button>';
        editMembersContainer.appendChild(wrap);
        const removeBtn = wrap.querySelector('.member-remove-btn');
        if (removeBtn instanceof HTMLButtonElement) {
          removeBtn.addEventListener('click', function () {
            if (editMembersContainer.children.length <= 1) return;
            wrap.remove();
          });
        }
      };

      const renderFiles = function (files) {
        if (!(editFilesContainer instanceof HTMLElement)) return;
        editFilesContainer.innerHTML = '';
        const inputMap = {
          thumbnail: 'thumbnail_file_new',
          main_media: 'main_media_file_new',
          thesis_pdf: 'thesis_pdf_file_new',
          project_file: 'project_file_new'
        };
        const removeMap = {
          thumbnail: 'remove_thumbnail',
          main_media: 'remove_main_media',
          thesis_pdf: 'remove_thesis_pdf',
          project_file: 'remove_project_file'
        };

        ['thumbnail', 'main_media', 'thesis_pdf', 'project_file'].forEach(function (key) {
          const file = files && files[key] ? files[key] : null;
          const name = file && file.name ? file.name : 'No file';
          const link = file && file.link ? file.link : '';
          const row = document.createElement('div');
          row.className = 'rounded-xl border border-[#E5E7EB] p-3';
          row.innerHTML = '<div class="mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-[#8B919A]">' + fileLabels[key] + '</div>' +
            '<div class="flex items-center justify-between gap-2">' +
            '<div class="truncate text-sm"><span class="mr-1">📄</span>' + String(name) + '</div>' +
            '<div class="flex items-center gap-2">' +
            (link ? '<a href="' + String(link) + '" target="_blank" rel="noopener" class="text-xs text-[#2563EB] underline">Open</a>' : '') +
            '<button type="button" class="text-xs text-[#DC2626]">ลบ</button>' +
            '<button type="button" class="text-xs text-[#2563EB]">เปลี่ยนไฟล์</button>' +
            '</div></div>' +
            '<input type="hidden" name="' + removeMap[key] + '" value="0">' +
            '<input type="file" name="' + inputMap[key] + '" class="mt-2 hidden w-full text-xs">';
          const replaceBtn = row.querySelectorAll('button')[1];
          const removeBtn = row.querySelectorAll('button')[0];
          const removeInput = row.querySelector('input[type="hidden"]');
          const fileInput = row.querySelector('input[type="file"]');
          if (removeBtn && removeInput instanceof HTMLInputElement) {
            removeBtn.addEventListener('click', function () {
              removeInput.value = '1';
              row.classList.add('border-[#FCA5A5]', 'bg-[#FFF1F2]');
            });
          }
          if (replaceBtn && fileInput instanceof HTMLInputElement && removeInput instanceof HTMLInputElement) {
            replaceBtn.addEventListener('click', function () {
              fileInput.classList.toggle('hidden');
            });
            fileInput.addEventListener('change', function () {
              if (fileInput.files && fileInput.files.length > 0) {
                removeInput.value = '0';
                row.classList.add('border-[#86EFAC]', 'bg-[#F0FDF4]');
              }
            });
          }
          editFilesContainer.appendChild(row);
        });
      };

      // Delegated modal edit trigger (if edit buttons exist in future dynamic content).
      document.addEventListener('click', async function (event) {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const editTrigger = target.closest('.edit-project-btn-modal');
        if (!(editTrigger instanceof HTMLElement)) return;
        event.preventDefault();
        const projectId = editTrigger.getAttribute('data-project-id');
        if (!projectId) return;
        if (editProjectIdBadge instanceof HTMLElement) editProjectIdBadge.textContent = 'ID: ' + projectId;
        if (editProjectIdInput instanceof HTMLInputElement) editProjectIdInput.value = projectId;
        openEditModal();
        try {
          const response = await fetch('./get_project_data.php?id=' + encodeURIComponent(projectId), { headers: { 'Accept': 'application/json' } });
          const data = await response.json();
          if (!response.ok || !data || data.success !== true) {
            throw new Error((data && data.message) ? data.message : 'Load project data failed');
          }
          const p = data.project || {};
          if (editTitleTh instanceof HTMLInputElement) editTitleTh.value = String(p.title_th || '');
          if (editTitleEn instanceof HTMLInputElement) editTitleEn.value = String(p.title_en || '');
          if (editIntroduction instanceof HTMLTextAreaElement) editIntroduction.value = String(p.introduction || '');
          if (editAdvisorId instanceof HTMLSelectElement) {
            editAdvisorId.value = String(p.advisor_id || '');
            editAdvisorId.dispatchEvent(new Event('change'));
          }
          if (editCategoryId instanceof HTMLSelectElement) {
            editCategoryId.value = String(p.category_id || '');
            editCategoryId.dispatchEvent(new Event('change'));
          }
          if (editAcademicYearId instanceof HTMLSelectElement) {
            editAcademicYearId.value = String(p.academic_year_id || '');
            editAcademicYearId.dispatchEvent(new Event('change'));
          }
          renderMembers(p.members || []);
          renderFiles(p.files || {});
        } catch (err) {
          alert('โหลดข้อมูลโปรเจกต์ไม่สำเร็จ: ' + (err && err.message ? err.message : 'unknown error'));
        }
      });

      if (editCloseBtn instanceof HTMLButtonElement) editCloseBtn.addEventListener('click', closeEditModal);
      if (editAddMemberBtn instanceof HTMLButtonElement) editAddMemberBtn.addEventListener('click', appendEmptyMember);
      if (editCloseXBtn instanceof HTMLButtonElement) editCloseXBtn.addEventListener('click', closeEditModal);
      if (editModal instanceof HTMLDivElement) {
        editModal.addEventListener('click', function (event) {
          if (event.target === editModal) closeEditModal();
        });
      }

      window.addEventListener('load', function () {
        console.log('Force unlocking screen...');

        // 1. Remove potentially stale backdrops from previous navigation states.
        const backdrops = document.querySelectorAll('.modal-backdrop, [class*="backdrop"], .fixed.inset-0.bg-black');
        backdrops.forEach(function (el) {
          if (!(el instanceof HTMLElement)) return;
          if (
            el.id === 'delete-project-modal' ||
            el.id === 'edit-project-modal' ||
            el.id === 'approve-confirm-modal' ||
            el.id === 'cancel-confirm-modal' ||
            el.id === 'profile-modal'
          ) return;
          if (el.classList.contains('swal2-container')) return;
          console.log('Removed annoying backdrop:', el);
          el.remove();
        });

        // 2. Force unlock body + html interaction.
        document.body.style.overflow = 'auto';
        document.body.style.pointerEvents = 'auto';
        document.body.classList.remove('modal-open', 'overflow-hidden');

        document.documentElement.style.overflow = 'auto';
        document.documentElement.style.pointerEvents = 'auto';

        // 3. Hide any generic dialog/modal that may be visible by mistake.
        const modals = document.querySelectorAll('.modal, [role="dialog"]');
        modals.forEach(function (modal) {
          if (!(modal instanceof HTMLElement)) return;
          if (modal.closest('.swal2-container')) return;
          modal.classList.remove('visible', 'opacity-100', 'pointer-events-auto');
          modal.classList.add('invisible', 'opacity-0', 'pointer-events-none');
        });
      });
    })();
  </script>

  <!-- Approve Confirm Modal -->
  <div id="approve-confirm-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel relative z-[100] w-full max-w-md mx-4 rounded-2xl bg-white p-6 text-center shadow-xl border-t-4 border-[#2E7D32] pointer-events-auto dark:bg-[#1e1e1e] dark:border-[#333333]">
      <button type="button" class="absolute top-4 right-4 z-[50] text-2xl font-medium text-gray-400 transition-colors hover:text-gray-600 pointer-events-auto cursor-pointer" aria-label="Close modal" onclick="closeApproveModal()">&times;</button>
      <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-[#EAF8EA] text-[#1E7A2E] border border-[#CDE9D2]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.3 7.3a1 1 0 01-1.415 0l-3.3-3.3a1 1 0 111.414-1.414l2.593 2.592 6.593-6.592a1 1 0 011.415 0z" clip-rule="evenodd"/>
        </svg>
      </div>
      <h3 class="font-display text-[28px] leading-tight font-semibold text-[#1F2937] dark:text-white">Confirm Approve?</h3>
      <p class="mt-3 text-sm text-[#6B7280] dark:text-gray-300">Are you sure you want to approve this project?</p>
      <div class="mt-6 flex items-center justify-center gap-4">
        <button type="button" class="inline-flex h-10 items-center rounded-xl border border-[#D7DCE2] bg-white px-6 text-sm font-semibold text-[#6B7280] hover:bg-[#F8FAFC] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800 pointer-events-auto cursor-pointer" style="position:relative;" onclick="closeApproveModal()">Close</button>
        <button id="confirm-approve-btn" type="button" class="inline-flex h-10 items-center justify-center rounded-xl bg-[#2E7D32] px-6 text-sm font-semibold text-white hover:bg-[#25692B] pointer-events-auto cursor-pointer" style="position:relative;">Confirm Approve</button>
      </div>
    </div>
  </div>

  <!-- Cancel Confirm Modal -->
  <div id="cancel-confirm-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel relative z-[100] w-full max-w-md mx-4 rounded-2xl bg-white p-6 text-center shadow-xl border-t-4 border-[#D9534F] pointer-events-auto dark:bg-[#1e1e1e] dark:border-[#333333]">
      <button type="button" class="absolute top-4 right-4 z-[50] text-2xl font-medium text-gray-400 transition-colors hover:text-gray-600 pointer-events-auto cursor-pointer" aria-label="Close modal" onclick="closeCancelModal()">&times;</button>
      <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-[#FFECEC] text-[#D9534F] border border-[#F4D7D7]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
        </svg>
      </div>
      <h3 class="font-display text-[28px] leading-tight font-semibold text-[#1F2937] dark:text-white">Cancel Approval?</h3>
      <p class="mt-3 text-sm text-[#6B7280] dark:text-gray-300">Are you sure you want to cancel approval for this project?</p>
      <div class="mt-6 flex items-center justify-center gap-4">
        <button type="button" class="inline-flex h-10 items-center rounded-xl border border-[#D7DCE2] bg-white px-6 text-sm font-semibold text-[#6B7280] hover:bg-[#F8FAFC] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800 pointer-events-auto cursor-pointer" style="position:relative;" onclick="closeCancelModal()">Close</button>
        <button id="confirm-cancel-btn" type="button" class="inline-flex h-10 items-center justify-center rounded-xl bg-[#D9534F] px-6 text-sm font-semibold text-white hover:bg-[#C64A44] pointer-events-auto cursor-pointer" style="position:relative;">Confirm Cancel</button>
      </div>
    </div>
  </div>
  <div id="delete-project-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-panel relative z-[100] w-full max-w-md rounded-2xl border border-[#F1C9C9] bg-white p-6 text-center shadow-xl pointer-events-auto dark:bg-[#1e1e1e] dark:border-[#333333]">
      <button id="delete-modal-close-x" type="button" class="absolute top-4 right-4 z-[50] text-2xl font-medium text-gray-400 transition-colors hover:text-gray-600 pointer-events-auto cursor-pointer" aria-label="Close modal" onclick="closeDeleteModal()">&times;</button>
      <div class="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-full bg-[#FFF1F1] text-[#D9534F] dark:bg-rose-950/40 dark:text-rose-400">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.518 11.591c.75 1.334-.213 2.985-1.742 2.985H3.48c-1.53 0-2.492-1.65-1.743-2.985L8.257 3.1zM11 14a1 1 0 10-2 0 1 1 0 002 0zm-1-8a1 1 0 00-.993.883L9 7v4a1 1 0 001.993.117L11 11V7a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
      </div>
      <h3 class="text-xl font-semibold text-[#1F2937] dark:text-white">Delete Project?</h3>
      <p class="mt-2 text-sm text-[#5F6368] dark:text-gray-300">โปรเจกต์ "<span id="delete-project-name" class="font-semibold text-[#1F2937] dark:text-white">-</span>" จะถูกลบออกอย่างถาวร ดำเนินการต่อหรือไม่?</p>
      <form id="delete-project-form" method="POST" class="mt-6 flex items-center justify-center gap-3">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="return_page" value="<?= (int) $currentPage ?>" />
        <input type="hidden" name="return_search" value="<?= htmlspecialchars($listSearchQuery, ENT_QUOTES, 'UTF-8') ?>" />
        <input type="hidden" id="delete-project-id-input" name="project_id" value="" />
        <button type="button" id="delete-modal-close-btn" class="inline-flex h-10 items-center rounded-xl border border-[#D7DCE2] bg-white px-6 text-sm font-semibold text-[#6B7280] hover:bg-[#F8FAFC] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800" onclick="closeDeleteModal()" style="position:relative; cursor:pointer; pointer-events:auto;">Close</button>
        <button type="submit" id="confirm-delete-btn" class="inline-flex h-10 items-center justify-center rounded-xl bg-[var(--mt-red)] px-6 text-sm font-semibold text-white hover:bg-[var(--mt-red-dark)]">Delete</button>
      </form>
    </div>
  </div>
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

    const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
    const deleteProjectForm = document.getElementById('delete-project-form');
    if (confirmDeleteBtn && deleteProjectForm) {
      if (!confirmDeleteBtn.dataset.originalHtml) {
        confirmDeleteBtn.dataset.originalHtml = confirmDeleteBtn.innerHTML;
      }
      confirmDeleteBtn.addEventListener('click', function (e) {
        if (this.disabled) return;
        e.preventDefault();
        this.disabled = true;
        this.style.pointerEvents = 'none';
        this.classList.add('opacity-75', 'cursor-not-allowed');
        this.innerHTML = `
          <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Deleting...
        `;
        deleteProjectForm.submit();
      });
    }

    const confirmApproveBtn = document.getElementById('confirm-approve-btn');
    const confirmCancelBtn = document.getElementById('confirm-cancel-btn');

    window.resetConfirmApproveBtn = function () {
      if (!(confirmApproveBtn instanceof HTMLButtonElement)) return;
      confirmApproveBtn.disabled = false;
      confirmApproveBtn.style.pointerEvents = '';
      confirmApproveBtn.classList.remove('opacity-75', 'cursor-not-allowed');
      confirmApproveBtn.innerHTML = confirmApproveBtn.dataset.originalHtml || 'Confirm Approve';
    };

    window.resetConfirmCancelBtn = function () {
      if (!(confirmCancelBtn instanceof HTMLButtonElement)) return;
      confirmCancelBtn.disabled = false;
      confirmCancelBtn.style.pointerEvents = '';
      confirmCancelBtn.classList.remove('opacity-75', 'cursor-not-allowed');
      confirmCancelBtn.innerHTML = confirmCancelBtn.dataset.originalHtml || 'Confirm Cancel';
    };

    if (confirmApproveBtn) {
      if (!confirmApproveBtn.dataset.originalHtml) {
        confirmApproveBtn.dataset.originalHtml = confirmApproveBtn.innerHTML;
      }
      confirmApproveBtn.addEventListener('click', function () {
        if (this.disabled) return;
        this.disabled = true;
        this.style.pointerEvents = 'none';
        this.classList.add('opacity-75', 'cursor-not-allowed');
        this.innerHTML = `
          <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Approving...
        `;
        if (typeof window.confirmApprove === 'function') {
          window.confirmApprove();
        }
      });
    }

    if (confirmCancelBtn) {
      if (!confirmCancelBtn.dataset.originalHtml) {
        confirmCancelBtn.dataset.originalHtml = confirmCancelBtn.innerHTML;
      }
      confirmCancelBtn.addEventListener('click', function () {
        if (this.disabled) return;
        this.disabled = true;
        this.style.pointerEvents = 'none';
        this.classList.add('opacity-75', 'cursor-not-allowed');
        this.innerHTML = `
          <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Processing...
        `;
        if (typeof window.confirmCancel === 'function') {
          window.confirmCancel();
        }
      });
    }
  });
  </script>
  <script>
    // Fallback: force active page color from URL query.
    (function syncPaginationActiveState() {
      const paginationRoot = document.querySelector('.projects-pagination');
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
  <script>
  window.addEventListener('load', function() {
    var skeletonTable = document.getElementById('skeleton-table');
    var actualTable = document.getElementById('actual-table-data');
    if (skeletonTable && actualTable) {
      skeletonTable.classList.add('hidden');
      actualTable.classList.remove('hidden');
      setTimeout(function() { actualTable.classList.remove('opacity-0'); }, 50);
    }
  });
  </script>
  <?php require_once __DIR__ . '/../config/site_lang_script.php'; ?>
</body>
</html>
