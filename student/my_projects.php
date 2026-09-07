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
require_once __DIR__ . '/../config/opendrive_helper.php';

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
    return '../admin/drive_thumbnail.php?id=' . rawurlencode($candidate);
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
        header('Location: ./my_projects.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $projectId = (int) ($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        $_SESSION['flash_error'] = 'ไม่พบรหัสโครงงานที่ต้องการจัดการ';
        header('Location: ./my_projects.php');
        exit;
    }

    if ($action === 'delete') {
        $ownCheck = $pdo->prepare('SELECT id FROM projects WHERE id = :id AND uploader_id = :uploader_id LIMIT 1');
        $ownCheck->execute([
            ':id' => $projectId,
            ':uploader_id' => (int) $_SESSION['user_id'],
        ]);
        if (!$ownCheck->fetch()) {
            $_SESSION['flash_error'] = 'คุณไม่มีสิทธิ์ลบโครงงานนี้';
            header('Location: ./my_projects.php');
            exit;
        }
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

            $stmt = $pdo->prepare('DELETE FROM projects WHERE id = :id AND uploader_id = :uploader_id');
            $stmt->execute([
                ':id' => $projectId,
                ':uploader_id' => (int) $_SESSION['user_id'],
            ]);

            if ($driveError !== '') {
                $_SESSION['flash_error'] = 'ลบโครงงานแล้ว แต่ข้ามการลบไฟล์ใน OpenDrive: ' . $driveError;
            } else {
                $_SESSION['flash_success'] = 'ลบโครงงานและไฟล์ใน OpenDrive สำเร็จ (' . $driveDeletedCount . ' ไฟล์)';
            }
        } catch (Throwable $e) {
            error_log('[MANAGE_PROJECTS] delete failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'ไม่สามารถลบโครงงานได้: ' . $e->getMessage();
        }
        header('Location: ./my_projects.php');
        exit;
    }
}

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
    error_log('[MANAGE_PROJECTS] load user failed: ' . $e->getMessage());
}

$avatar = $adminName !== '' ? (function_exists('mb_substr') ? mb_substr($adminName, 0, 1, 'UTF-8') : substr($adminName, 0, 1)) : 'A';
$avatar = strtoupper((string) $avatar);

$projects = [];
$totalProjects = 0;
$perPage = 8;
$totalPages = 1;
$currentPage = 1;
$offset = 0;
$showFrom = 0;
$showTo = 0;

try {
    $hasProjectFilesTable = tableExists($pdo, 'project_files');
    $studentUserId = (int) $_SESSION['user_id'];

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE uploader_id = :uploader_id');
    $countStmt->execute([':uploader_id' => $studentUserId]);
    $totalProjects = (int) $countStmt->fetchColumn();

    $perPage = 8;
    $totalPages = $totalProjects > 0 ? (int) ceil($totalProjects / $perPage) : 1;
    $currentPage = (int) ($_GET['page'] ?? 1);
    if ($currentPage < 1) {
        $currentPage = 1;
    }
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $offset = ($currentPage - 1) * $perPage;
    $showFrom = $totalProjects > 0 ? $offset + 1 : 0;
    $showTo = $totalProjects > 0 ? min($offset + $perPage, $totalProjects) : 0;

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
           WHERE p.uploader_id = :uploader_id
           ORDER BY p.id DESC LIMIT :lim OFFSET :off"
        : "SELECT p.id, p.title_th, p.title_en, p.creators, p.status, p.file_urls,
                  '' AS thumb_file_id, '' AS thumb_view_link
           FROM projects p
           WHERE p.uploader_id = :uploader_id
           ORDER BY p.id DESC LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uploader_id', $studentUserId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[MY_PROJECTS] preload data failed: ' . $e->getMessage());
}

$pageTitle = 'โปรเจกต์ของฉัน';
$pageTitleDataTh = 'โปรเจกต์ของฉัน';
$pageTitleDataEn = 'My Projects';
$pageSubtitle = 'Student | Dashboard | My Projects';
$pageSubtitleDataTh = 'นักศึกษา | แดชบอร์ด | โปรเจกต์ของฉัน';
$pageSubtitleDataEn = 'Student | Dashboard | My Projects';

$topbarRightHtml = '
<div class="w-full sm:w-auto">
  <div class="relative w-full sm:w-[320px]">
    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-[#B4BAC0]">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a.75.75 0 101.06-1.06l-2.25-2.249A6 6 0 009 3zm-4.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" clip-rule="evenodd" /></svg>
    </span>
    <input id="student-project-search" type="text" placeholder="ค้นหาจากชื่อเรื่อง นักศึกษา หรือสถานะ..." data-th-placeholder="ค้นหาจากชื่อเรื่อง นักศึกษา หรือสถานะ..." data-en-placeholder="Search by title, student, or status..." class="h-9 w-full rounded-lg border border-[#E2E4E8] bg-white pl-9 pr-3 text-xs text-[#444] outline-none placeholder:text-[#B6BBC1] focus:border-[#CDD2D8] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" />
  </div>
</div>';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Projects - RMUTI MT Gallery</title>
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
                    <a href="../project_detail.php?id=<?= $projectId ?>" class="block h-12 w-20 overflow-hidden rounded-lg bg-[#F1F3F5]">
                      <?php if ($thumbnailUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($thumbnailUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Thumbnail" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                      <?php else: ?>
                        <div class="grid h-full w-full place-items-center text-[10px] text-[#9AA0A6]">No Image</div>
                      <?php endif; ?>
                    </a>
                  </div>
                  <div class="col-span-4 min-w-0">
                    <a href="../project_detail.php?id=<?= $projectId ?>" class="block truncate text-sm font-semibold text-[#1E1E1E] hover:text-[var(--mt-red)] dark:text-gray-100">
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
                    <button type="button" onclick="openEditPage(<?= $projectId ?>)" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-blue-200 bg-blue-50 text-blue-700 transition-all duration-200 hover:-translate-y-[1px] hover:border-blue-300 hover:bg-blue-100 hover:text-blue-800 dark:!bg-transparent dark:!border-transparent dark:!text-blue-400 dark:hover:!bg-blue-400/10 dark:hover:!text-blue-300" title="Edit" aria-label="Edit project">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg>
                    </button>
                    <button
                      type="button"
                      class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-[#F2C7C7] bg-[#FFF8F8] text-[#C62828] transition-all duration-200 hover:-translate-y-[1px] hover:border-[#E8AAAA] hover:bg-[#FFEFEF] hover:text-[#B71C1C] dark:!bg-transparent dark:!border-transparent dark:!text-rose-500 dark:hover:!bg-rose-500/10 dark:hover:!text-rose-400"
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

  <script>
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

    function openEditPage(id) {
      window.location.href = './edit_project.php?id=' + encodeURIComponent(String(id || ''));
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

    window.closeDeleteModal = closeDeleteModal;

    (function () {
      const cleanupBlockingOverlays = function () {
        const allowedModalIds = new Set(['delete-project-modal', 'profile-modal']);
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
        closeDeleteModal();
      });
      window.addEventListener('load', function () {
        cleanupBlockingOverlays();
        closeDeleteModal();
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
          window.history.replaceState({}, document.title, window.location.pathname);
        });
      } else if (urlParams.get('status') === 'error') {
        Swal.fire({
          icon: 'error',
          title: 'เกิดข้อผิดพลาด',
          text: 'ไม่สามารถอัปเดตข้อมูลผลงานได้',
          confirmButtonColor: '#d33',
          confirmButtonText: 'ตกลง'
        }).then(function () {
          window.history.replaceState({}, document.title, window.location.pathname);
        });
      }

      const searchInput = document.getElementById('student-project-search');
      const rows = document.querySelectorAll('.project-row');
      if (searchInput instanceof HTMLInputElement) {
        searchInput.addEventListener('input', function () {
          const keyword = searchInput.value.trim().toLowerCase();
          rows.forEach(function (row) {
            if (!(row instanceof HTMLElement)) {
              return;
            }
            const haystack = String(row.dataset.search || '');
            row.style.display = keyword === '' || haystack.includes(keyword) ? '' : 'none';
          });
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

      window.addEventListener('load', function () {
        console.log('Force unlocking screen...');

        // 1. Remove potentially stale backdrops from previous navigation states.
        const backdrops = document.querySelectorAll('.modal-backdrop, [class*="backdrop"], .fixed.inset-0.bg-black');
        backdrops.forEach(function (el) {
          if (!(el instanceof HTMLElement)) return;
          if (el.id === 'delete-project-modal' || el.id === 'profile-modal') return;
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
      confirmDeleteBtn.addEventListener('click', function () {
        if (this.disabled) return;
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

