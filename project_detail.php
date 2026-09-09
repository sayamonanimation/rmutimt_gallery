<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/project_file_proxy.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/manage_list_state.php';

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
 * @param array<string,mixed>|null $fileData
 * @return array<string,string>
 */
function buildDriveFileMeta(?array $fileData): array
{
    if (!is_array($fileData)) {
        return ['id' => '', 'preview' => '', 'download' => '', 'name' => '', 'img_src' => ''];
    }
    $link = isset($fileData['link']) && is_string($fileData['link']) ? trim($fileData['link']) : '';
    $web = isset($fileData['webContentLink']) && is_string($fileData['webContentLink']) ? trim($fileData['webContentLink']) : '';
    $isGoogleUrl = static function (string $u): bool {
        return $u !== '' && (stripos($u, 'drive.google.com') !== false || stripos($u, 'docs.google.com') !== false);
    };
    if (!$isGoogleUrl($link) && !$isGoogleUrl($web) && ($link !== '' || $web !== '')) {
        $idStored = isset($fileData['id']) && is_string($fileData['id']) ? trim($fileData['id']) : '';
        $direct = $link !== '' ? $link : $web;
        $stream = $web !== '' ? $web : $link;
        $name = isset($fileData['name']) && is_string($fileData['name']) ? trim($fileData['name']) : '';
        if ($name === '') {
            $name = 'Media file';
        }
        return [
            'id' => $idStored,
            'preview' => $stream,
            'download' => $stream,
            'name' => $name,
            'img_src' => $direct,
        ];
    }

    $id = '';
    if (isset($fileData['id']) && is_string($fileData['id'])) {
        $id = trim($fileData['id']);
    }
    if ($id === '' && isset($fileData['link']) && is_string($fileData['link'])) {
        $id = extractDriveFileIdFromText($fileData['link']);
    }
    if ($id === '' && isset($fileData['webContentLink']) && is_string($fileData['webContentLink'])) {
        $id = extractDriveFileIdFromText($fileData['webContentLink']);
    }
    $name = isset($fileData['name']) && is_string($fileData['name']) ? trim($fileData['name']) : '';
    if ($name === '') {
        $name = 'Google Drive File';
    }
    if ($id === '') {
        return ['id' => '', 'preview' => '', 'download' => '', 'name' => $name, 'img_src' => ''];
    }
    $thumb = './admin/drive_thumbnail.php?id=' . rawurlencode($id);
    return [
        'id' => $id,
        'preview' => 'https://drive.google.com/file/d/' . rawurlencode($id) . '/preview',
        'download' => 'https://drive.google.com/uc?export=download&id=' . rawurlencode($id),
        'name' => $name,
        'img_src' => $thumb,
    ];
}

/**
 * @param mixed $mainMediaRaw
 * @return array<int,array<string,string>>
 */
function normalizeMainMediaItems($mainMediaRaw): array
{
    if (!is_array($mainMediaRaw)) {
        return [];
    }
    $isAssoc = array_keys($mainMediaRaw) !== range(0, count($mainMediaRaw) - 1);
    $items = $isAssoc ? [$mainMediaRaw] : $mainMediaRaw;
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $meta = buildDriveFileMeta($item);
        if ($meta['id'] === '' && ($meta['img_src'] ?? '') === '') {
            continue;
        }
        $normalized[] = $meta;
    }
    return $normalized;
}

/**
 * Direct video file URL (not Google Drive) from main_media JSON, if any.
 */
function extractDirectVideoUrlFromMainMedia($mainMediaRaw): string
{
    if (!is_array($mainMediaRaw)) {
        return '';
    }
    $isAssoc = array_keys($mainMediaRaw) !== range(0, count($mainMediaRaw) - 1);
    $items = $isAssoc ? [$mainMediaRaw] : $mainMediaRaw;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $link = '';
        if (isset($item['link']) && is_string($item['link'])) {
            $link = trim($item['link']);
        }
        if ($link === '' && isset($item['webContentLink']) && is_string($item['webContentLink'])) {
            $link = trim($item['webContentLink']);
        }
        if ($link === '') {
            continue;
        }
        if (stripos($link, 'drive.google.com') !== false || stripos($link, 'docs.google.com') !== false) {
            continue;
        }
        if (preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?|#|$)/i', $link) === 1
            || stripos($link, 'opendrive.com') !== false
            || stripos($link, 'od.lk') !== false) {

            if (stripos($link, 'opendrive.com') !== false || stripos($link, 'od.lk') !== false) {
                if (stripos($link, '/d/') !== false) {
                    $link = str_replace('/d/', '/s/', $link);
                } elseif (stripos($link, '/f/') !== false) {
                    $link = str_replace('/f/', '/s/', $link);
                }
            }
            return $link;
        }
    }
    return '';
}

/**
 * Normalize Google Drive file URLs to /preview embed form for faster streaming UI.
 */
function toGoogleDrivePreviewUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (stripos($url, 'drive.google.com/file/d/') !== false && stripos($url, '/preview') !== false) {
        return $url;
    }
    $id = extractDriveFileIdFromText($url);
    if ($id === '') {
        return '';
    }
    return 'https://drive.google.com/file/d/' . rawurlencode($id) . '/preview';
}

/**
 * Thumbnail URL for gallery cards (matches index.php logic).
 *
 * @param array<string,mixed>|null $fileData
 */
function buildThumbnailUrl(?array $fileData): string
{
    if (!is_array($fileData)) {
        return '';
    }
    foreach (['link', 'webContentLink'] as $k) {
        if (!isset($fileData[$k]) || !is_string($fileData[$k])) {
            continue;
        }
        $u = trim($fileData[$k]);
        if ($u === '' || !preg_match('#^https?://#i', $u)) {
            continue;
        }
        if (stripos($u, 'drive.google.com') !== false || stripos($u, 'docs.google.com') !== false) {
            continue;
        }
        return $u;
    }
    $id = '';
    if (isset($fileData['id']) && is_string($fileData['id'])) {
        $id = trim($fileData['id']);
    }
    if ($id === '' && isset($fileData['link']) && is_string($fileData['link'])) {
        $id = extractDriveFileIdFromText($fileData['link']);
    }
    if ($id === '' && isset($fileData['webContentLink']) && is_string($fileData['webContentLink'])) {
        $id = extractDriveFileIdFromText($fileData['webContentLink']);
    }
    if ($id === '') {
        return '';
    }
    return './admin/drive_thumbnail.php?id=' . rawurlencode($id);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name');
    $stmt->execute([':name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$projectId = (int) ($_GET['id'] ?? 0);
if ($projectId <= 0) {
    http_response_code(404);
    echo 'Project not found.';
    exit;
}

$project = null;
$members = [];
$relatedProjects = [];

try {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.category_id, p.advisor_id, p.title_th, p.title_en, p.creators, p.introduction, p.file_urls, p.status, p.uploader_id, p.created_at, p.secondary_category_ids,
                c.name AS category_name, a.year AS academic_year,
                adv.prefix AS advisor_prefix, adv.full_name AS advisor_name, adv.avatar_filename AS advisor_avatar
         FROM projects p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN academic_years a ON a.id = p.academic_year_id
         LEFT JOIN advisors adv ON adv.id = p.advisor_id
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($project === null) {
        http_response_code(404);
        echo 'Project not found.';
        exit;
    }

    $secondaryCategoryNames = [];
    if (!empty($project['secondary_category_ids'])) {
        $secIds = array_filter(array_map('intval', explode(',', (string) $project['secondary_category_ids'])));
        if (count($secIds) > 0) {
            $inClause = implode(',', array_fill(0, count($secIds), '?'));
            $secStmt = $pdo->prepare("SELECT name FROM categories WHERE id IN ($inClause)");
            $secStmt->execute(array_values($secIds));
            $secRows = $secStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($secRows as $sn) {
                $snTrim = trim((string) $sn);
                if ($snTrim !== '' && $snTrim !== trim((string) ($project['category_name'] ?? ''))) {
                    $secondaryCategoryNames[] = $snTrim;
                }
            }
        }
    }
    $secondaryCategoryNames = array_values(array_unique($secondaryCategoryNames));

    $role = (string) ($_SESSION['role'] ?? '');
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $isApproved = (string) ($project['status'] ?? '') === 'approved';
    $isAdmin = $role === 'admin';
    $isOwnerStudent = $role === 'student' && $sessionUserId > 0 && $sessionUserId === (int) ($project['uploader_id'] ?? 0);
    if (!$isApproved && !$isAdmin && !$isOwnerStudent) {
        http_response_code(404);
        echo 'Project not found.';
        exit;
    }

    if (tableExists($pdo, 'project_members')) {
        $photoExpr = tableHasColumn($pdo, 'project_members', 'avatar_filename')
            ? "COALESCE(NULLIF(TRIM(avatar_filename), ''), '')"
            : "''";
        $memberStmt = $pdo->prepare("SELECT full_name AS name, {$photoExpr} AS photo FROM project_members WHERE project_id = :id ORDER BY id ASC");
        $memberStmt->execute([':id' => $projectId]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $currentCategoryId = (int) ($project['category_id'] ?? 0);
    $currentAdvisorId = (int) ($project['advisor_id'] ?? 0);

    $categoriesMap = [];
    try {
        $catMapRows = $pdo->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (is_array($catMapRows)) {
            $categoriesMap = $catMapRows;
        }
    } catch (Throwable $e) {
        // Fallback silently
    }

    $relatedStmt = $pdo->prepare(
        'SELECT p.id, p.title_th, p.title_en, p.creators, p.file_urls, p.created_at, p.secondary_category_ids,
                c.name AS category_name, a.year AS academic_year,
                adv.prefix AS advisor_prefix, adv.full_name AS advisor_name
         FROM projects p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN academic_years a ON a.id = p.academic_year_id
         LEFT JOIN advisors adv ON adv.id = p.advisor_id
         WHERE p.status = :status AND p.id <> :id
         ORDER BY 
            (CASE WHEN p.category_id = :cat_id1 AND :cat_id2 > 0 THEN 2 ELSE 0 END +
             CASE WHEN p.advisor_id = :adv_id1 AND :adv_id2 > 0 THEN 1 ELSE 0 END) DESC,
            RAND()
         LIMIT 4'
    );
    $relatedStmt->execute([
        ':status' => 'approved',
        ':id' => $projectId,
        ':cat_id1' => $currentCategoryId,
        ':cat_id2' => $currentCategoryId,
        ':adv_id1' => $currentAdvisorId,
        ':adv_id2' => $currentAdvisorId,
    ]);
    $relatedProjects = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[PROJECT_DETAIL] Load failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Unable to load project.';
    exit;
}

$fileUrls = json_decode((string) ($project['file_urls'] ?? ''), true);
if (!is_array($fileUrls)) {
    $fileUrls = [];
}
$displayType = (string) ($fileUrls['display_type'] ?? 'video');
$thumbnailMeta = buildDriveFileMeta(is_array($fileUrls['thumbnail'] ?? null) ? $fileUrls['thumbnail'] : null);
$mainMediaItems = normalizeMainMediaItems($fileUrls['main_media'] ?? null);
$thumbnailPosterUrl = ($thumbnailMeta['img_src'] ?? '') !== ''
    ? $thumbnailMeta['img_src']
    : ($thumbnailMeta['id'] !== ''
        ? './admin/drive_thumbnail.php?id=' . rawurlencode($thumbnailMeta['id'])
        : '');
$youtubeUrl = trim((string) ($fileUrls['youtube'] ?? ''));
$youtubeEmbedUrl = '';
if ($youtubeUrl !== '') {
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $youtubeUrl, $match)) {
        $youtubeEmbedUrl = 'https://www.youtube.com/embed/' . $match[1] . '?rel=0';
    }
}
$directMainVideoUrl = extractDirectVideoUrlFromMainMedia($fileUrls['main_media'] ?? null);
if ($directMainVideoUrl === '' && $mainMediaItems !== []) {
    $pv = trim((string) ($mainMediaItems[0]['preview'] ?? ''));
    if ($pv !== '' && stripos($pv, 'drive.google.com') === false
        && (preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?|#|$)/i', $pv) === 1 || stripos($pv, 'opendrive.com') !== false || stripos($pv, 'od.lk') !== false)) {
        if (stripos($pv, 'opendrive.com') !== false || stripos($pv, 'od.lk') !== false) {
            if (stripos($pv, '/d/') !== false) {
                $pv = str_replace('/d/', '/s/', $pv);
            } elseif (stripos($pv, '/f/') !== false) {
                $pv = str_replace('/f/', '/s/', $pv);
            }
        }
        $directMainVideoUrl = $pv;
    }
}
$projectFileMeta = buildDriveFileMeta(is_array($fileUrls['project_file'] ?? null) ? $fileUrls['project_file'] : null);
$thesisMeta = buildDriveFileMeta(is_array($fileUrls['thesis_pdf'] ?? null) ? $fileUrls['thesis_pdf'] : null);

if ($members === []) {
    $creatorText = trim((string) ($project['creators'] ?? ''));
    if ($creatorText !== '') {
        $parts = preg_split('/\s*,\s*/', $creatorText);
        foreach ($parts as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed !== '') {
                $members[] = ['name' => $trimmed, 'photo' => ''];
            }
        }
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$dashboardLink = './index.php';
$sessionRole = (string) ($_SESSION['role'] ?? '');
if ($sessionRole === 'admin') {
    $dashboardLink = manage_list_state_url('./admin/manage_projects.php', $_GET);
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
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars((string) ($project['title_th'] ?? 'Project Detail'), ENT_QUOTES, 'UTF-8') ?> - RMUTI MT Thesis Gallery</title>
  <?php require_once __DIR__ . '/config/site_theme_head.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/config/site_tailwind_config.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    /* ตั้งค่าฟอนต์ภาษาไทยให้แสดงผลสวยงามทั่วทั้งหน้า */
    body { font-family: 'Prompt', sans-serif !important; }
    .thai-title { font-family: 'Noto Sans Thai', sans-serif !important; font-weight: 600; }
    .thai-intro { font-family: 'Sarabun', sans-serif !important; text-align: left; }
    .project-title-primary-en,
    .project-title-secondary-en {
      display: none;
    }
    html[lang="en"] .project-title-primary-th,
    html[lang="en"] .project-title-secondary-th {
      display: none;
    }
    html[lang="en"] .project-title-primary-en,
    html[lang="en"] .project-title-secondary-en {
      display: block;
    }
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
    .work-card {
      border: 1px solid #E5E7EB;
      border-radius: 1rem;
      background: #FFFFFF;
      transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .work-card:hover {
      transform: translateY(-6px);
      border-color: #D32F2F !important;
      box-shadow: 0 16px 36px -8px rgba(211, 47, 47, 0.18);
    }
    html.dark .work-card {
      background: #1e1e1e;
      border-color: #262626;
    }
    html.dark .work-card:hover {
      border-color: #F87171 !important;
      box-shadow: 0 16px 36px -8px rgba(211, 47, 47, 0.28), 0 0 1px 1px rgba(248, 113, 113, 0.2);
    }
    .work-card:hover h3 {
      color: #D32F2F !important;
    }
    html.dark .work-card:hover h3 {
      color: #F87171 !important;
    }
    .work-card:hover .work-overlay-eye {
      opacity: 1;
      transform: translate(-50%, -50%) scale(1);
    }
    .work-category-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.9);
      color: #1F2937;
      border-radius: 999px;
      padding: 0 10px;
      height: 20px;
      font-size: 10px;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      font-weight: 700;
      backdrop-filter: blur(4px);
      border: 1px solid rgba(255, 255, 255, 0.85);
      line-height: 1;
      white-space: nowrap;
      flex-shrink: 0;
    }
    html.dark .work-category-badge {
      background: rgba(31, 41, 55, 0.9) !important;
      color: #F3F4F6 !important;
      border-color: rgba(55, 65, 81, 0.8) !important;
    }
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

    body { background:#F3F3F4; color:#111827; }
    .font-display { font-family: 'Prompt', sans-serif; }
    .gallery-track { scroll-snap-type: x mandatory; }
    .gallery-slide { scroll-snap-align: start; }
    .gallery-thumb-img {
      height: 100%;
      width: 100%;
      object-fit: cover;
      object-position: center;
    }
    /* Related Works cards — thumbnail shell + badge (hover บนรูป/ไอคอนใช้ Tailwind group-hover) */
    .work-thumb-wrap {
      position: relative;
      height: 176px;
      width: 100%;
      overflow: hidden;
      background: #F3F4F6;
    }
    .work-thumb-media {
      height: 100%;
      width: 100%;
      object-fit: cover;
      transition: transform .35s ease, filter .35s ease;
    }
    .work-overlay-eye {
      position: absolute;
      left: 50%;
      top: 50%;
      z-index: 2;
      transform: translate(-50%, -50%) scale(0.92);
      width: 48px;
      height: 48px;
      border-radius: 999px;
      display: grid;
      place-items: center;
      background: rgba(211, 47, 47, 0.95);
      color: #fff;
      opacity: 0;
      transition: opacity .25s ease, transform .25s ease;
      box-shadow: 0 8px 20px rgba(0,0,0,.25);
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
      font-family: 'Prompt', sans-serif;
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
  <?php require_once __DIR__ . '/config/site_dark_styles.php'; ?>
</head>
<body class="dark:bg-[#121212] dark:text-gray-100" data-error="<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>" data-success="<?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>" data-initial-modal="<?= htmlspecialchars($initialModal, ENT_QUOTES, 'UTF-8') ?>">
  <?php
  $activePage = 'project_detail';
  require_once __DIR__ . '/config/site_header.php';
  ?>

  <div id="flash-message" class="flash-message"></div>

  <main class="mx-auto max-w-7xl px-6 pb-14 pt-8 dark:bg-[#121212]">
    <a href="index.php" onclick="if(window.history.length > 1) { window.history.back(); return false; }" class="mb-5 inline-flex items-center gap-2 text-sm text-[#6B7280] hover:text-[#111827] dark:text-gray-400 dark:hover:text-gray-100" data-th="← กลับไปหน้าก่อนหน้า" data-en="← Go back to previous">← กลับไปหน้าก่อนหน้า</a>

    <?php if ($displayType === 'gallery' && $mainMediaItems !== []): ?>
      <section class="w-full">
        <div class="relative group">
          <div id="gallery-track" class="gallery-track flex gap-3 overflow-x-auto rounded-2xl scroll-smooth">
            <?php foreach ($mainMediaItems as $idx => $media): ?>
              <?php
                $imgUrl = ($media['img_src'] ?? '') !== '' ? $media['img_src'] : './admin/drive_thumbnail.php?id=' . rawurlencode($media['id']);
                $downloadUrl = ($media['download'] ?? '') !== '' ? $media['download'] : $imgUrl;
              ?>
              <div class="gallery-slide group/slide relative flex h-[300px] min-h-[300px] min-w-full cursor-pointer items-center justify-center overflow-hidden rounded-2xl bg-[#F1F3F5] dark:bg-[#1e1e1e] sm:h-[480px] sm:min-h-[480px] lg:h-[600px] lg:min-h-[600px]" data-index="<?= (int) $idx ?>" data-src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" data-download="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" class="h-full w-full max-h-full max-w-full object-contain transition-transform duration-300 group-hover/slide:scale-[1.01]" loading="lazy" />
                <!-- Hover Zoom Indicator Overlay -->
                <div class="absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 transition-opacity duration-300 group-hover/slide:opacity-100">
                  <span class="inline-flex items-center gap-2 rounded-full bg-white/95 px-4 py-2.5 text-xs font-semibold text-gray-900 shadow-xl backdrop-blur-md transition-transform duration-200 hover:scale-105 dark:bg-gray-900/95 dark:text-white sm:text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                    </svg>
                    <span data-th="คลิกเพื่อดูภาพใหญ่" data-en="Click to expand">คลิกเพื่อดูภาพใหญ่</span>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <button id="gallery-prev" type="button" class="absolute left-4 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white opacity-0 backdrop-blur-sm transition-all duration-300 hover:bg-black/60 group-hover:opacity-100">‹</button>
          <button id="gallery-next" type="button" class="absolute right-4 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white opacity-0 backdrop-blur-sm transition-all duration-300 hover:bg-black/60 group-hover:opacity-100">›</button>
        </div>
        <div class="mt-4 flex gap-3 overflow-x-auto pb-2 scroll-smooth scrollbar-hide">
          <?php foreach ($mainMediaItems as $idx => $media): ?>
            <?php $imgUrl = ($media['img_src'] ?? '') !== '' ? $media['img_src'] : './admin/drive_thumbnail.php?id=' . rawurlencode($media['id']); ?>
            <button type="button" class="gallery-thumb shrink-0 overflow-hidden rounded-xl bg-transparent p-0 transition-all duration-200 <?= $idx === 0 ? 'opacity-100 ring-2 ring-[var(--mt-red)] ring-offset-2' : 'opacity-60 hover:opacity-80' ?>" data-index="<?= (int) $idx ?>">
              <span class="flex h-20 w-28 items-center justify-center overflow-hidden rounded-xl bg-[#F1F3F5] dark:bg-[#1e1e1e]">
                <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="thumb" class="gallery-thumb-img h-full w-full object-cover" loading="lazy" />
              </span>
            </button>
          <?php endforeach; ?>
        </div>
      </section>
    <?php else: ?>
      <section class="overflow-hidden rounded-2xl border border-[#E5E7EB] bg-black shadow-sm">
        <?php if ($youtubeEmbedUrl !== ''): ?>
          <div class="relative w-full overflow-hidden bg-black h-[260px] sm:h-[420px] lg:h-[520px]">
            <iframe
              src="<?= htmlspecialchars($youtubeEmbedUrl, ENT_QUOTES, 'UTF-8') ?>"
              class="absolute inset-0 h-full w-full border-0"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen>
            </iframe>
          </div>
        <?php elseif ($directMainVideoUrl !== ''): ?>
          <div class="relative w-full overflow-hidden rounded-2xl bg-black group h-[260px] sm:h-[420px] lg:h-[520px]" id="custom-video-wrapper">
            <video
              id="project-main-video"
              class="w-full h-full object-contain"
              controls
              preload="metadata"
              playsinline
              <?= $thumbnailPosterUrl !== '' ? 'poster="' . htmlspecialchars($thumbnailPosterUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
            >
              <source src="<?= htmlspecialchars($directMainVideoUrl, ENT_QUOTES, 'UTF-8') ?>" type="video/mp4" />
              วิดีโอไม่รองรับในเบราว์เซอร์นี้
            </video>
            <button type="button" id="custom-play-btn" class="absolute inset-0 z-10 flex items-center justify-center bg-black/20 transition-all duration-300 hover:bg-black/40" aria-label="เล่นวิดีโอ">
              <div class="flex h-16 w-16 items-center justify-center rounded-full bg-white text-gray-900 shadow-xl transition-transform duration-300 hover:scale-110">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="ml-1 h-8 w-8">
                  <path fill-rule="evenodd" d="M4.5 5.653c0-1.426 1.529-2.33 2.779-1.643l11.54 6.348c1.295.712 1.295 2.573 0 3.285L7.28 19.991c-1.25.687-2.779-.217-2.779-1.643V5.653z" clip-rule="evenodd" />
                </svg>
              </div>
            </button>
          </div>
        <?php elseif ($mainMediaItems !== []): ?>
          <?php
          $drivePreviewSrc = $mainMediaItems[0]['preview'] ?? '';
          $driveEmbedSrc = toGoogleDrivePreviewUrl($drivePreviewSrc);
          if ($driveEmbedSrc === '') {
              $driveEmbedSrc = $drivePreviewSrc;
          }
          ?>
          <div class="relative h-[260px] w-full sm:h-[420px] lg:h-[520px]">
            <?php if ($thumbnailPosterUrl !== ''): ?>
              <div id="main-media-drive-poster" class="absolute inset-0 z-[1] flex items-center justify-center bg-black transition-opacity duration-300">
                <img src="<?= htmlspecialchars($thumbnailPosterUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="h-full w-full object-cover opacity-95" loading="lazy" decoding="async" width="1280" height="720" />
              </div>
            <?php endif; ?>
            <iframe
              src="<?= htmlspecialchars($driveEmbedSrc, ENT_QUOTES, 'UTF-8') ?>"
              class="relative z-[2] h-full w-full min-h-[260px] border-0 sm:min-h-[420px] lg:min-h-[520px]"
              allow="autoplay; fullscreen"
              allowfullscreen
              loading="lazy"
              referrerpolicy="strict-origin-when-cross-origin"
              title="<?= htmlspecialchars((string) ($mainMediaItems[0]['name'] ?? 'Main media'), ENT_QUOTES, 'UTF-8') ?>"
              onload="(function(el){var p=document.getElementById('main-media-drive-poster');if(!p)return;p.style.opacity='0';p.style.pointerEvents='none';setTimeout(function(){if(p&&p.parentNode)p.parentNode.removeChild(p);},320);})(this)"
            ></iframe>
          </div>
        <?php else: ?>
          <div class="grid h-[260px] place-items-center text-sm text-white/70 sm:h-[420px] lg:h-[520px]">No media available</div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($displayType === 'gallery' && $mainMediaItems !== []): ?>
      <!-- Fullscreen Gallery Lightbox Modal -->
      <div id="gallery-lightbox" class="fixed inset-0 z-[9999] hidden flex-col justify-between bg-black/95 backdrop-blur-md transition-all duration-300 opacity-0 select-none" role="dialog" aria-modal="true">
        <!-- Top Bar -->
        <div class="flex items-center justify-between px-6 py-4 bg-gradient-to-b from-black/90 to-transparent z-10">
          <div class="flex items-center gap-3 text-white">
            <span class="text-xs sm:text-sm font-semibold tracking-wider rounded-full bg-white/10 px-3.5 py-1 backdrop-blur-md border border-white/20">
              <span id="lightbox-counter">1 / 1</span>
            </span>
            <span id="lightbox-title" class="text-xs sm:text-sm font-medium text-gray-300 truncate max-w-md hidden sm:inline-block"></span>
          </div>
          <div class="flex items-center gap-3">
            <a id="lightbox-download" href="#" download target="_blank" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition-all duration-200 hover:bg-white/20 hover:scale-105 backdrop-blur-md border border-white/20" title="ดาวน์โหลดรูปภาพ / Download">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
            </a>
            <button id="lightbox-close" type="button" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition-all duration-200 hover:bg-red-500/80 hover:scale-105 backdrop-blur-md border border-white/20" title="ปิด (Esc)">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <!-- Center Main Image Container -->
        <div id="lightbox-img-wrapper" class="relative flex-1 flex items-center justify-center p-4 overflow-hidden">
          <?php if (count($mainMediaItems) > 1): ?>
            <button id="lightbox-prev" type="button" class="absolute left-4 z-20 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white text-2xl backdrop-blur-md border border-white/20 transition-all duration-200 hover:bg-white/25 hover:scale-110 active:scale-95 shadow-2xl">‹</button>
          <?php endif; ?>

          <div class="relative flex items-center justify-center max-h-full max-w-full">
            <img id="lightbox-img" src="" alt="" class="max-h-[78vh] max-w-[92vw] object-contain rounded-lg shadow-2xl transition-all duration-300 scale-95" />
          </div>

          <?php if (count($mainMediaItems) > 1): ?>
            <button id="lightbox-next" type="button" class="absolute right-4 z-20 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white text-2xl backdrop-blur-md border border-white/20 transition-all duration-200 hover:bg-white/25 hover:scale-110 active:scale-95 shadow-2xl">›</button>
          <?php endif; ?>
        </div>

        <!-- Bottom Thumbnails Strip -->
        <div class="px-6 py-3 bg-gradient-to-t from-black/90 to-transparent flex justify-center items-center z-10">
          <div class="flex gap-2.5 overflow-x-auto scrollbar-hide max-w-4xl py-1">
            <?php foreach ($mainMediaItems as $idx => $media): ?>
              <?php $imgUrl = ($media['img_src'] ?? '') !== '' ? $media['img_src'] : './admin/drive_thumbnail.php?id=' . rawurlencode($media['id']); ?>
              <button type="button" class="lightbox-thumb shrink-0 overflow-hidden rounded-lg bg-transparent p-0 transition-all duration-200 opacity-60 hover:opacity-100" data-index="<?= (int) $idx ?>">
                <span class="flex h-14 w-20 items-center justify-center overflow-hidden rounded-lg bg-black/50 border border-white/20">
                  <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="thumb" class="h-full w-full object-cover" loading="lazy" />
                </span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <section class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?php
          $detailTitleTh = trim((string) ($project['title_th'] ?? '-'));
          $detailTitleEn = trim((string) ($project['title_en'] ?? ''));
        ?>
        <h1 class="text-2xl sm:text-3xl lg:text-4xl text-[#111827] dark:text-white leading-snug tracking-normal mb-3">
          <span class="project-title-primary-th thai-title"><?= htmlspecialchars($detailTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="project-title-primary-en font-normal tracking-wide" style="font-family: 'DM Sans', sans-serif;"><?= htmlspecialchars($detailTitleEn !== '' ? $detailTitleEn : $detailTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
        </h1>
        <?php if ($detailTitleEn !== ''): ?>
          <h2 class="text-base sm:text-lg text-[#6B7280] dark:text-gray-400 font-normal tracking-wide mb-6">
            <span class="project-title-secondary-th" style="font-family: 'DM Sans', sans-serif;"><?= htmlspecialchars($detailTitleEn, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="project-title-secondary-en thai-title"><?= htmlspecialchars($detailTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
          </h2>
        <?php endif; ?>
        <hr class="my-6 border-[#E5E7EB] dark:border-[#262626]" />
        <h2 class="font-display text-3xl text-[#111827] dark:text-white" data-th="บทนำ" data-en="Introduction">บทนำ</h2>
        <div class="thai-intro mt-3 text-[#374151] dark:text-gray-300 text-[15px] sm:text-[16px] leading-relaxed font-normal tracking-wide space-y-5" style="word-break: break-word;">
          <?php
          $introText = (string) ($project['introduction'] ?? '-');
          if (mb_strlen($introText, 'UTF-8') > 3500) {
              $introText = mb_substr($introText, 0, 3500, 'UTF-8') . '...';
          }
          ?>
          <?= nl2br(htmlspecialchars($introText, ENT_QUOTES, 'UTF-8')) ?>
        </div>

      </div>

      <aside class="space-y-4">
        <div class="rounded-2xl border border-[#E5E7EB] bg-[#F9FAFB] p-6 shadow-[0_2px_10px_rgba(0,0,0,0.02)] dark:bg-[#1e1e1e] dark:border-[#262626]">
          <!-- AUTHOR SECTION -->
          <div class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#9CA3AF] mb-4 dark:text-gray-400" data-th="ผู้จัดทำ" data-en="Author">ผู้จัดทำ</div>
          <div class="space-y-4">
            <?php foreach ($members as $mem): ?>
              <?php
                $memName = trim($mem['name']);
                $memPhoto = trim((string) ($mem['photo'] ?? ''));
                $memPhotoBase = basename(str_replace(["\0", '/', '\\'], '', $memPhoto));
                $authorDiskPath = $memPhotoBase !== '' ? __DIR__ . '/uploads/authors/' . $memPhotoBase : '';
                $hasAuthorPhoto = $memPhotoBase !== '' && $authorDiskPath !== '' && is_file($authorDiskPath);
                $memPhotoUrl = $hasAuthorPhoto ? './uploads/authors/' . rawurlencode($memPhotoBase) : '';
                $memInitials = mb_substr($memName, 0, 1, 'UTF-8');
              ?>
              <div class="flex items-center gap-3">
                <?php if ($hasAuthorPhoto): ?>
                  <div class="h-11 w-11 shrink-0 overflow-hidden rounded-full border border-[#E5E7EB] bg-white shadow-sm">
                    <img src="<?= htmlspecialchars($memPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Author" class="h-full w-full object-cover" />
                  </div>
                <?php else: ?>
                  <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-[#E5E7EB] bg-white shadow-sm dark:border-[#333] dark:bg-[#262626]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500 dark:text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/></svg>
                  </div>
                <?php endif; ?>
                <div class="text-[14px] font-semibold text-[#111827]"><?= htmlspecialchars($memName, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="my-6 h-px w-full bg-[#E5E7EB]"></div>

          <!-- ADVISOR SECTION -->
          <div class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#9CA3AF] mb-4 dark:text-gray-400" data-th="อาจารย์ที่ปรึกษา" data-en="Advisor">อาจารย์ที่ปรึกษา</div>
          <div class="flex items-center gap-3">
            <?php
              $advAvatarFile = trim((string)($project['advisor_avatar'] ?? ''));
              $advAvatarUrl = $advAvatarFile !== '' ? './uploads/advisors/' . rawurlencode($advAvatarFile) : '';
              $advName = trim((string) (($project['advisor_prefix'] ?? '') . ' ' . ($project['advisor_name'] ?? '-')));
              $advInitials = mb_substr(str_replace(['ผศ.', 'รศ.', 'ศ.', 'ดร.', 'อาจารย์'], '', $advName), 0, 1, 'UTF-8');
            ?>
            <?php if ($advAvatarUrl !== ''): ?>
              <img src="<?= htmlspecialchars($advAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Advisor" class="h-11 w-11 rounded-full object-cover border border-[#E5E7EB] bg-white shadow-sm" />
            <?php else: ?>
              <div class="grid h-11 w-11 place-items-center rounded-full bg-white text-sm font-bold text-[#6B7280] border border-[#E5E7EB] shadow-sm">
                <?= htmlspecialchars($advInitials, ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endif; ?>
            <div class="text-[14px] font-semibold text-[#111827]"><?= htmlspecialchars($advName, ENT_QUOTES, 'UTF-8') ?></div>
          </div>

          <div class="mt-6 grid grid-cols-2 gap-4">
            <div>
              <div class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#9CA3AF] dark:text-gray-400" data-th="ปีการศึกษา" data-en="Year">ปีการศึกษา</div>
              <div class="mt-1.5 text-[14px] font-semibold text-[#111827]"><?= htmlspecialchars((string) ($project['academic_year'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div>
              <div class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#9CA3AF] dark:text-gray-400" data-th="หมวดหมู่" data-en="Category">หมวดหมู่</div>
              <div class="mt-1.5 text-[14px] font-semibold text-[#111827] dark:text-white"><?= htmlspecialchars((string) ($project['category_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (!empty($secondaryCategoryNames)): ?>
                <?php $secListStr = implode(', ', $secondaryCategoryNames); ?>
                <div 
                  class="group/sec relative mt-0.5 text-[11px] font-medium text-[#6B7280] dark:text-gray-400 cursor-pointer select-none transition-colors hover:text-[var(--mt-red)] dark:hover:text-rose-400"
                  onclick="const inner=this.querySelector('.sec-text-inner'); if(inner){ inner.classList.toggle('truncate'); inner.classList.toggle('whitespace-normal'); }"
                  title="คลิกเพื่อสลับแสดงข้อมูลเต็ม / Hover or click to expand"
                >
                  <div class="sec-text-inner truncate">+ <?= htmlspecialchars($secListStr, ENT_QUOTES, 'UTF-8') ?></div>
                  <!-- Floating Tooltip Popover (Adapted for Light & Dark Mode) -->
                  <div class="invisible group-hover/sec:visible opacity-0 group-hover/sec:opacity-100 transition-all duration-200 absolute right-0 bottom-full mb-2 z-30 w-max max-w-[230px] rounded-xl bg-white dark:bg-[#1e1e1e] px-3.5 py-2.5 text-[11px] text-[#111827] dark:text-white shadow-[0_12px_30px_rgba(0,0,0,0.15)] dark:shadow-[0_12px_30px_rgba(0,0,0,0.7)] backdrop-blur-md border border-gray-200 dark:border-gray-700 pointer-events-none">
                    <div class="font-bold text-[var(--mt-red)] dark:text-rose-400 text-[10px] uppercase mb-0.5 tracking-wide">สื่อผสมเพิ่มเติม:</div>
                    <div class="whitespace-normal font-normal leading-snug text-gray-700 dark:text-gray-200"><?= htmlspecialchars($secListStr, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="absolute right-4 top-full -mt-[1px] border-4 border-transparent border-t-white dark:border-t-[#1e1e1e]"></div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php
          $rawProjectFileEntry = $fileUrls['project_file'] ?? null;
          $showProjectFileBtn = is_array($rawProjectFileEntry) && project_proxy_entry_has_file($rawProjectFileEntry);
          $isExternalProjectFile = $showProjectFileBtn
              && is_array($rawProjectFileEntry)
              && project_proxy_is_external_project_file($rawProjectFileEntry);
          $externalProjectUrl = $isExternalProjectFile
              ? project_proxy_external_url($rawProjectFileEntry)
              : '';
          $rawThesisEntry = $fileUrls['thesis_pdf'] ?? null;
          $showThesisPdfBtn = is_array($rawThesisEntry) && project_proxy_entry_has_file($rawThesisEntry);
        ?>

        <?php if ($showProjectFileBtn || $showThesisPdfBtn): ?>
          <div class="mt-4 flex flex-col gap-3">
            <?php if ($showProjectFileBtn): ?>
              <?php if ($isExternalProjectFile && $externalProjectUrl !== ''): ?>
                <a
                  href="<?= htmlspecialchars($externalProjectUrl, ENT_QUOTES, 'UTF-8') ?>"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex h-12 w-full items-center justify-center gap-2.5 rounded-xl bg-[#D32F2F] px-5 text-sm font-bold text-white shadow-md transition-all duration-200 hover:bg-[#B71C1C] hover:shadow-[0_4px_14px_rgba(211,47,47,0.3)] active:scale-[0.99]"
                >
                  <svg class="h-5 w-5 shrink-0" style="width:20px; height:20px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                  </svg>
                  <span data-th="เปิดชมผลงาน" data-en="Open Project">เปิดชมผลงาน</span>
                </a>
              <?php else: ?>
                <a
                  href="./download_project.php?id=<?= (int) $projectId ?>"
                  class="inline-flex h-12 w-full items-center justify-center gap-2.5 rounded-xl bg-[#D32F2F] px-5 text-sm font-bold text-white shadow-md transition-all duration-200 hover:bg-[#B71C1C] hover:shadow-[0_4px_14px_rgba(211,47,47,0.3)] active:scale-[0.99]"
                >
                  <svg class="h-5 w-5 shrink-0" style="width:20px; height:20px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  <span data-th="ดาวน์โหลดผลงาน" data-en="Download Project">ดาวน์โหลดผลงาน</span>
                </a>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($showThesisPdfBtn): ?>
              <?php
                $thesisBtnStyle = $showProjectFileBtn
                  ? 'inline-flex h-12 w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-5 text-sm font-bold text-[#111827] shadow-sm transition-all duration-200 hover:bg-gray-50 hover:border-[#D32F2F] hover:text-[#D32F2F] dark:border-[#333] dark:bg-[#1e1e1e] dark:text-white dark:hover:bg-[#262626] dark:hover:border-[#D32F2F] dark:hover:text-[#D32F2F] active:scale-[0.99]'
                  : 'inline-flex h-12 w-full items-center justify-center gap-2.5 rounded-xl bg-[#D32F2F] px-5 text-sm font-bold text-white shadow-md transition-all duration-200 hover:bg-[#B71C1C] hover:shadow-[0_4px_14px_rgba(211,47,47,0.3)] active:scale-[0.99]';
              ?>
              <a
                href="./view_pdf.php?id=<?= (int) $projectId ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="<?= htmlspecialchars($thesisBtnStyle, ENT_QUOTES, 'UTF-8') ?>"
              >
                <svg class="h-5 w-5 shrink-0" style="width:20px; height:20px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span data-th="อ่านเล่มวิทยานิพนธ์ (PDF)" data-en="Thesis PDF">อ่านเล่มวิทยานิพนธ์ (PDF)</span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </aside>
    </section>

    <?php if ($relatedProjects !== []): ?>
      <hr class="my-10 border-[#E5E7EB] dark:border-[#262626]" />
      <section>
        <div class="text-[11px] uppercase tracking-[0.2em] text-[#D32F2F]">Discover More</div>
        <h2 class="mt-1 font-display text-4xl text-[#111827] dark:text-white" data-th="ผลงานที่เกี่ยวข้อง" data-en="Related Works">ผลงานที่เกี่ยวข้อง</h2>
        <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <?php foreach ($relatedProjects as $item): ?>
            <?php
            $relatedId = (int) ($item['id'] ?? 0);
            $itemUrls = json_decode((string) ($item['file_urls'] ?? ''), true);
            $thumbnailData = is_array($itemUrls) && isset($itemUrls['thumbnail']) && is_array($itemUrls['thumbnail'])
                ? $itemUrls['thumbnail']
                : null;
            $relatedThumb = buildThumbnailUrl($thumbnailData);
            $relatedTitleTh = trim((string) ($item['title_th'] ?? 'Untitled'));
            $relatedTitleEn = trim((string) ($item['title_en'] ?? ''));
            $relatedAuthor = trim((string) ($item['creators'] ?? '-'));
            $relatedCategory = trim((string) ($item['category_name'] ?? ''));
            $relatedYear = trim((string) ($item['academic_year'] ?? ''));
            $relatedAdvisor = trim((string) (($item['advisor_prefix'] ?? '') . ' ' . ($item['advisor_name'] ?? '')));

            $relSecIds = array_filter(array_map('intval', explode(',', (string) ($item['secondary_category_ids'] ?? ''))));
            $relSecNames = [];
            foreach ($relSecIds as $sid) {
                if (isset($categoriesMap[$sid]) && $categoriesMap[$sid] !== $relatedCategory) {
                    $relSecNames[] = $categoriesMap[$sid];
                }
            }
            $relSecNames = array_values(array_unique($relSecNames));
            ?>
            <a href="./project_detail.php?id=<?= $relatedId ?>" class="work-card group flex h-full min-w-0 w-full flex-col overflow-hidden dark:bg-[#1e1e1e] dark:border-[#262626]" style="text-decoration:none;color:inherit;" aria-label="Open project detail">
              <div class="work-thumb-wrap relative h-44 w-full shrink-0 overflow-hidden bg-[#F3F4F6] dark:bg-[#262626]">
                <div class="absolute left-3 top-3 z-20 group/catrel inline-flex items-center gap-1.5 max-w-[90%]">
                  <?php if ($relatedCategory !== ''): ?>
                    <span class="work-category-badge inline-flex h-5 items-center justify-center rounded-full border border-white/80 bg-white/90 px-2.5 text-[10px] font-bold uppercase tracking-wide text-gray-800 backdrop-blur-sm shadow-sm dark:!border-gray-700 dark:!bg-gray-800/90 dark:!text-gray-100 leading-none shrink-0"><?= htmlspecialchars($relatedCategory, ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if (count($relSecNames) > 0): ?>
                    <span class="inline-flex h-5 items-center justify-center gap-1 rounded-full border border-gray-200 bg-white/95 px-2.5 text-[10px] font-bold text-gray-800 backdrop-blur-md shadow-sm dark:border-gray-700 dark:bg-gray-800/95 dark:text-gray-100 leading-none shrink-0 cursor-pointer transition-colors duration-150 hover:bg-white hover:text-[var(--mt-red,#D32F2F)]">
                      <span>+<?= count($relSecNames) ?></span>
                    </span>
                    <!-- Floating Secondary Categories Popover Tooltip -->
                    <div class="invisible group-hover/catrel:visible opacity-0 group-hover/catrel:opacity-100 transition-all duration-200 absolute left-0 top-full mt-2 z-30 w-max max-w-[230px] rounded-xl bg-white dark:bg-[#1e1e1e] px-3.5 py-2.5 text-[11px] text-[#111827] dark:text-white shadow-[0_12px_30px_rgba(0,0,0,0.15)] dark:shadow-[0_12px_30px_rgba(0,0,0,0.7)] backdrop-blur-md border border-gray-200 dark:border-gray-700 pointer-events-none">
                      <div class="font-bold text-[var(--mt-red)] dark:text-rose-400 text-[10px] uppercase mb-0.5 tracking-wide"><?= htmlspecialchars($relatedCategory !== '' ? $relatedCategory : 'สื่อผสม', ENT_QUOTES, 'UTF-8') ?>เพิ่มเติม:</div>
                      <div class="whitespace-normal font-normal leading-snug text-gray-700 dark:text-gray-200"><?= htmlspecialchars(implode(', ', $relSecNames), ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="absolute left-4 bottom-full -mb-[1px] border-4 border-transparent border-b-white dark:border-b-[#1e1e1e]"></div>
                    </div>
                  <?php endif; ?>
                </div>
                <?php if ($relatedThumb !== ''): ?>
                  <img src="<?= htmlspecialchars($relatedThumb, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($relatedTitleTh, ENT_QUOTES, 'UTF-8') ?>" class="work-thumb-media h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" loading="lazy" decoding="async" />
                <?php else: ?>
                  <div class="work-thumb-media grid h-full w-full place-items-center text-sm text-[#9CA3AF] transition-all duration-300 group-hover:bg-gray-200 dark:text-gray-400 dark:group-hover:bg-[#333333]">No Image</div>
                <?php endif; ?>
              </div>
              <div class="flex flex-1 flex-col p-5">
                <h3 class="line-clamp-2 text-lg font-bold text-gray-900 dark:text-white transition-colors duration-200" title="<?= htmlspecialchars($relatedTitleTh, ENT_QUOTES, 'UTF-8') ?>">
                  <span class="project-title-primary-th"><?= htmlspecialchars($relatedTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="project-title-primary-en"><?= htmlspecialchars($relatedTitleEn !== '' ? $relatedTitleEn : $relatedTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
                </h3>
                <?php if ($relatedTitleEn !== ''): ?>
                  <p class="mt-1 line-clamp-1 text-sm text-gray-500 dark:text-gray-400" title="<?= htmlspecialchars($relatedTitleEn, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="project-title-secondary-th"><?= htmlspecialchars($relatedTitleEn, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="project-title-secondary-en"><?= htmlspecialchars($relatedTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
                  </p>
                <?php endif; ?>
                <div class="mt-auto pt-4 text-xs text-gray-600 dark:text-gray-300">
                  <p class="line-clamp-1 uppercase tracking-wide text-gray-500 font-medium dark:text-gray-300" title="<?= htmlspecialchars($relatedAuthor, ENT_QUOTES, 'UTF-8') ?>">BY <?= htmlspecialchars($relatedAuthor, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php if ($relatedYear !== ''): ?>
                    <p class="mt-1 text-gray-400 dark:text-gray-400">YEAR: <?= htmlspecialchars($relatedYear, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php endif; ?>
                  <?php if ($relatedAdvisor !== ''): ?>
                    <p class="mt-1 line-clamp-1 font-medium text-[var(--mt-red,#D32F2F)]" title="<?= htmlspecialchars($relatedAdvisor, ENT_QUOTES, 'UTF-8') ?>">Advisor: <?= htmlspecialchars($relatedAdvisor, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <!-- Footer Section -->
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

  <script>
    (function () {
      var track = document.getElementById('gallery-track');
      if (!(track instanceof HTMLElement)) return;
      var prevBtn = document.getElementById('gallery-prev');
      var nextBtn = document.getElementById('gallery-next');
      var thumbs = document.querySelectorAll('.gallery-thumb');
      var slides = track.querySelectorAll('.gallery-slide');
      var currentIndex = 0;
      var scrollSyncPaused = false;

      function updateThumbs() {
        thumbs.forEach(function (el, idx) {
          if (!(el instanceof HTMLElement)) return;
          if (idx === currentIndex) {
            el.classList.add('opacity-100', 'ring-2', 'ring-[var(--mt-red)]', 'ring-offset-2');
            el.classList.remove('opacity-60');
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
          } else {
            el.classList.remove('opacity-100', 'ring-2', 'ring-[var(--mt-red)]', 'ring-offset-2');
            el.classList.add('opacity-60');
          }
        });
      }

      function goTo(index) {
        if (slides.length === 0) return;
        scrollSyncPaused = true;
        currentIndex = Math.max(0, Math.min(index, slides.length - 1));
        var target = slides[currentIndex];
        if (target instanceof HTMLElement) {
          track.scrollTo({ left: target.offsetLeft, behavior: 'smooth' });
        }
        updateThumbs();
        setTimeout(function () { scrollSyncPaused = false; }, 600);
      }

      if (prevBtn instanceof HTMLButtonElement) {
        prevBtn.addEventListener('click', function () { goTo(currentIndex - 1); });
      }
      if (nextBtn instanceof HTMLButtonElement) {
        nextBtn.addEventListener('click', function () { goTo(currentIndex + 1); });
      }
      thumbs.forEach(function (el) {
        el.addEventListener('click', function () {
          var idx = Number(el.getAttribute('data-index') || '0');
          goTo(idx);
        });
      });

      // Scroll-sync: update active thumbnail when user swipes/scrolls
      if ('IntersectionObserver' in window && slides.length > 1) {
        var observer = new IntersectionObserver(function (entries) {
          if (scrollSyncPaused) return;
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              var idx = Array.prototype.indexOf.call(slides, entry.target);
              if (idx >= 0 && idx !== currentIndex) {
                currentIndex = idx;
                updateThumbs();
              }
            }
          });
        }, { root: track, threshold: 0.55 });

        slides.forEach(function (slide) { observer.observe(slide); });
      }

      // --- Interactive Lightbox Modal Implementation ---
      var lightbox = document.getElementById('gallery-lightbox');
      if (lightbox instanceof HTMLElement) {
        var lightboxImg = document.getElementById('lightbox-img');
        var lightboxCounter = document.getElementById('lightbox-counter');
        var lightboxTitle = document.getElementById('lightbox-title');
        var lightboxDownload = document.getElementById('lightbox-download');
        var lightboxClose = document.getElementById('lightbox-close');
        var lightboxPrev = document.getElementById('lightbox-prev');
        var lightboxNext = document.getElementById('lightbox-next');
        var lightboxThumbs = document.querySelectorAll('.lightbox-thumb');
        var lbCurrentIndex = 0;
        var touchStartX = 0;
        var touchEndX = 0;

        function updateLightboxView(idx) {
          if (slides.length === 0) return;
          lbCurrentIndex = (idx + slides.length) % slides.length;
          var activeSlide = slides[lbCurrentIndex];
          if (!(activeSlide instanceof HTMLElement)) return;

          var src = activeSlide.getAttribute('data-src') || '';
          var download = activeSlide.getAttribute('data-download') || src;
          var name = activeSlide.getAttribute('data-name') || '';

          if (lightboxImg instanceof HTMLImageElement) {
            lightboxImg.style.transform = 'scale(0.96)';
            lightboxImg.style.opacity = '0.5';
            setTimeout(function () {
              lightboxImg.src = src;
              lightboxImg.alt = name;
              lightboxImg.style.transform = 'scale(1)';
              lightboxImg.style.opacity = '1';
            }, 100);
          }
          if (lightboxCounter) {
            lightboxCounter.textContent = (lbCurrentIndex + 1) + ' / ' + slides.length;
          }
          if (lightboxTitle) {
            lightboxTitle.textContent = name;
          }
          if (lightboxDownload instanceof HTMLAnchorElement) {
            lightboxDownload.href = download;
            lightboxDownload.setAttribute('download', name);
          }

          lightboxThumbs.forEach(function (tEl, tIdx) {
            if (!(tEl instanceof HTMLElement)) return;
            if (tIdx === lbCurrentIndex) {
              tEl.classList.add('opacity-100', 'ring-2', 'ring-white', 'ring-offset-2', 'ring-offset-black');
              tEl.classList.remove('opacity-60');
              tEl.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
            } else {
              tEl.classList.remove('opacity-100', 'ring-2', 'ring-white', 'ring-offset-2', 'ring-offset-black');
              tEl.classList.add('opacity-60');
            }
          });
        }

        function openLightbox(idx) {
          lightbox.classList.remove('hidden');
          lightbox.classList.add('flex');
          document.body.style.overflow = 'hidden';
          setTimeout(function () {
            lightbox.classList.remove('opacity-0');
            lightbox.classList.add('opacity-100');
          }, 20);
          updateLightboxView(idx);
        }

        function closeLightbox() {
          lightbox.classList.remove('opacity-100');
          lightbox.classList.add('opacity-0');
          setTimeout(function () {
            lightbox.classList.remove('flex');
            lightbox.classList.add('hidden');
            document.body.style.overflow = '';
          }, 300);
        }

        slides.forEach(function (slideEl) {
          slideEl.addEventListener('click', function () {
            var sIdx = Number(slideEl.getAttribute('data-index') || '0');
            openLightbox(sIdx);
          });
        });

        if (lightboxClose instanceof HTMLButtonElement) {
          lightboxClose.addEventListener('click', closeLightbox);
        }
        if (lightboxPrev instanceof HTMLButtonElement) {
          lightboxPrev.addEventListener('click', function () {
            updateLightboxView(lbCurrentIndex - 1);
          });
        }
        if (lightboxNext instanceof HTMLButtonElement) {
          lightboxNext.addEventListener('click', function () {
            updateLightboxView(lbCurrentIndex + 1);
          });
        }
        lightboxThumbs.forEach(function (tEl) {
          tEl.addEventListener('click', function () {
            var tIdx = Number(tEl.getAttribute('data-index') || '0');
            updateLightboxView(tIdx);
          });
        });

        lightbox.addEventListener('click', function (e) {
          if (e.target === lightbox || (e.target instanceof HTMLElement && e.target.id === 'lightbox-img-wrapper')) {
            closeLightbox();
          }
        });

        document.addEventListener('keydown', function (e) {
          if (lightbox.classList.contains('hidden')) return;
          if (e.key === 'Escape') {
            closeLightbox();
          } else if (e.key === 'ArrowLeft') {
            updateLightboxView(lbCurrentIndex - 1);
          } else if (e.key === 'ArrowRight') {
            updateLightboxView(lbCurrentIndex + 1);
          }
        });

        lightbox.addEventListener('touchstart', function (e) {
          if (e.touches.length > 0) touchStartX = e.touches[0].clientX;
        }, { passive: true });

        lightbox.addEventListener('touchend', function (e) {
          if (e.changedTouches.length > 0) {
            touchEndX = e.changedTouches[0].clientX;
            var diff = touchStartX - touchEndX;
            if (Math.abs(diff) > 40) {
              if (diff > 0) updateLightboxView(lbCurrentIndex + 1);
              else updateLightboxView(lbCurrentIndex - 1);
            }
          }
        }, { passive: true });
      }
    })();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const video = document.getElementById('project-main-video');
      const playBtn = document.getElementById('custom-play-btn');

      if (!(video instanceof HTMLVideoElement) || !(playBtn instanceof HTMLButtonElement)) {
        return;
      }

      function hideOverlay() {
        playBtn.classList.add('opacity-0', 'pointer-events-none');
      }

      function showOverlay() {
        playBtn.classList.remove('opacity-0', 'pointer-events-none');
      }

      playBtn.addEventListener('click', function () {
        if (video.paused) {
          void video.play();
        }
      });

      video.addEventListener('play', hideOverlay);
      video.addEventListener('pause', showOverlay);
      video.addEventListener('ended', showOverlay);

      if (!video.paused) {
        hideOverlay();
      }
    });
  </script>
  <?php if ($showThesisPdfBtn): ?>
    <?php
      $thesisEntry = $fileUrls['thesis_pdf'] ?? null;
      $thesisPreloadUrl = is_array($thesisEntry) ? project_proxy_resolve_stream_url($thesisEntry) : '';
      if ($thesisPreloadUrl === '') {
          $thesisPreloadUrl = './serve_pdf.php?id=' . (int) $projectId;
      }
    ?>
    <script>
      (function() {
        var pdfUrl = <?= json_encode($thesisPreloadUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        if (pdfUrl && 'fetch' in window) {
          setTimeout(function() {
            fetch(pdfUrl, { mode: 'cors', cache: 'force-cache' }).catch(function() {});
          }, 400);
        }
      })();
    </script>
  <?php endif; ?>
</body>
</html>

