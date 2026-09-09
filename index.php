<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/search_helper.php';

function extractDriveFileId(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $value, $match) === 1) {
        return (string) $match[1];
    }
    if (preg_match('#/d/([a-zA-Z0-9_-]+)#', $value, $match) === 1) {
        return (string) $match[1];
    }
    if (preg_match('/^[a-zA-Z0-9_-]{15,}$/', $value) === 1) {
        return $value;
    }
    return '';
}

/**
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
        $id = extractDriveFileId($fileData['link']);
    }
    if ($id === '' && isset($fileData['webContentLink']) && is_string($fileData['webContentLink'])) {
        $id = extractDriveFileId($fileData['webContentLink']);
    }
    if ($id === '') {
        return '';
    }
    return './admin/drive_thumbnail.php?id=' . rawurlencode($id);
}

/**
 * Pagination URL — เก็บพารามิเตอร์ GET เดิม (filter + sort) และตั้งหมายเลขหน้า
 */
function getPageUrl(int $pageNum): string
{
    $query = $_GET;
    $query['page'] = $pageNum;

    return '?' . http_build_query($query) . '#all-works';
}

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
if ($initialModal === '' && in_array($error, ['email_exists', 'signup_failed', 'password_mismatch', 'password_too_short', 'missing_fields', 'invalid_email'], true)) {
    $initialModal = 'signup';
}
if ($initialModal === '' && $error === 'registration_closed') {
    $initialModal = 'signup';
}

$buildSignature = 'index-auth ' . date('Y-m-d H:i:s', (int) filemtime(__FILE__));
$projects = [];
$heroGalleryProjects = [];
$years = [];
$categories = [];
$advisors = [];
$approvedProjectCount = 0;
$statsTotalWorks = 0;
$statsCategoryCount = 0;
$statsAdvisorCount = 0;
$statsLatestYearCount = 0;
$statsLatestYearLabel = '';
$featuredProjects = [];
$randomProjectLink = '#';
$page = 1;
$totalPages = 0;
$approvedStatus = 'approved';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchYear = trim((string) ($_GET['year'] ?? ''));
$searchCategory = trim((string) ($_GET['category'] ?? ''));
$searchCategoriesStr = trim((string) ($_GET['categories'] ?? ''));
$searchAdvisor = trim((string) ($_GET['advisor'] ?? ''));

$selectedCategoryIds = [];
if ($searchCategoriesStr !== '') {
    $parts = explode(',', $searchCategoriesStr);
    foreach ($parts as $pVal) {
        $pVal = trim($pVal);
        if ($pVal !== '' && is_numeric($pVal)) {
            $selectedCategoryIds[] = (int) $pVal;
        }
    }
} elseif ($searchCategory !== '' && is_numeric($searchCategory)) {
    $selectedCategoryIds[] = (int) $searchCategory;
}
$selectedCategoryIds = array_values(array_unique($selectedCategoryIds));

$sortOrder = $_GET['sort'] ?? 'newest';
if (!is_string($sortOrder)) {
    $sortOrder = 'newest';
}
$orderSql = ' ORDER BY a.year DESC, p.id DESC '; // ค่าเริ่มต้น
if ($sortOrder === 'oldest') {
    $orderSql = ' ORDER BY a.year ASC, p.id ASC ';
}

try {
    // สร้าง Base SQL สำหรับใช้ทั้งการนับจำนวน (COUNT) และการดึงข้อมูล (SELECT)
    $baseSql = 'FROM projects p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN academic_years a ON a.id = p.academic_year_id
                LEFT JOIN advisors adv ON adv.id = p.advisor_id
                WHERE p.status = :status';
                
    $params = [':status' => $approvedStatus];

    // 1. ค้นหาจากข้อความแบบ Smart Search (Multi-word, Space-insensitive, Synonym)
    if ($searchQuery !== '') {
        $baseSql .= build_smart_search_query($searchQuery, $params);
    }
    // 2. กรองตามปีการศึกษา
    if ($searchYear !== '') {
        $baseSql .= ' AND a.year = :year';
        $params[':year'] = $searchYear;
    }
    // 3. กรองตามหมวดหมู่ (รองรับการเลือกหลายหมวดหมู่ ทั้งหมวดหมู่หลัก และหมวดหมู่ร่วม พร้อมระบบ Smart Match Scoring)
    $matchScoreSqlSelect = '';
    if (!empty($selectedCategoryIds)) {
        $catOrClauses = [];
        foreach ($selectedCategoryIds as $idx => $catIdVal) {
            $paramCat = ':cat_id_' . $idx;
            $paramStr = ':cat_str_' . $idx;
            
            $catOrClauses[] = "(c.id = {$paramCat} OR FIND_IN_SET({$paramStr}, p.secondary_category_ids))";

            $params[$paramCat] = $catIdVal;
            $params[$paramStr] = (string) $catIdVal;
        }
        $baseSql .= ' AND (' . implode(' OR ', $catOrClauses) . ')';

        if (count($selectedCategoryIds) >= 2) {
            $scoreExprs = [];
            foreach ($selectedCategoryIds as $idx => $catIdVal) {
                $paramCatSc = ':scat_id_' . $idx;
                $paramStrSc = ':scat_str_' . $idx;
                $scoreExprs[] = "(CASE WHEN c.id = {$paramCatSc} OR FIND_IN_SET({$paramStrSc}, p.secondary_category_ids) THEN 1 ELSE 0 END)";

                $params[$paramCatSc] = $catIdVal;
                $params[$paramStrSc] = (string) $catIdVal;
            }
            $matchScoreSqlSelect = ', (' . implode(' + ', $scoreExprs) . ') AS match_score';

            if ($sortOrder === 'oldest') {
                $orderSql = ' ORDER BY match_score DESC, a.year ASC, p.id ASC ';
            } else {
                $orderSql = ' ORDER BY match_score DESC, a.year DESC, p.id DESC ';
            }
        }
    }
    // 4. กรองตามอาจารย์ที่ปรึกษา
    if ($searchAdvisor !== '') {
        $baseSql .= ' AND adv.id = :advisor';
        $params[':advisor'] = (int) $searchAdvisor;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $baseSql);
    foreach ($params as $key => $value) {
        if (strpos($baseSql, $key) !== false) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $approvedProjectCount = (int) $countStmt->fetchColumn();

    // --- Pagination ---
    $limit = 9;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    $totalRecords = $approvedProjectCount;
    $totalPages = $limit > 0 ? (int) ceil($totalRecords / $limit) : 0;
    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $limit;

    // ดึงข้อมูลโปรเจกต์พร้อมคำนวณ Smart Match Score
    $sql = 'SELECT p.id, p.category_id, p.title_th, p.title_en, p.creators, p.file_urls, p.created_at, p.secondary_category_ids,
                c.name AS category_name, a.year AS academic_year,
                adv.prefix AS advisor_prefix, adv.full_name AS advisor_name' . $matchScoreSqlSelect . ' ' . $baseSql;

    $sql .= $orderSql;
    $sql .= ' LIMIT :limit OFFSET :offset';

    $projectStmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if (strpos($sql, $key) !== false) {
            $projectStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
    $projectStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $projectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $projectStmt->execute();
    $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);

    // โหลด Dropdown
    $years = $pdo->query('SELECT year FROM academic_years ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $categoriesMap = [];
    foreach ($categories as $cItem) {
        $categoriesMap[(int) $cItem['id']] = trim((string) $cItem['name']);
    }
    $advisors = $pdo->query('SELECT id, prefix, full_name, is_active FROM advisors ORDER BY full_name ASC')->fetchAll(PDO::FETCH_ASSOC);

    $heroGalleryStmt = $pdo->prepare('SELECT file_urls FROM projects WHERE status = :status ORDER BY RAND() LIMIT 12');
    $heroGalleryStmt->execute([':status' => $approvedStatus]);
    $heroGalleryProjects = $heroGalleryStmt->fetchAll(PDO::FETCH_ASSOC);

    $statsTotalWorks = (int) $pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'approved'")->fetchColumn();
    $statsCategoryCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    $statsAdvisorCount = (int) $pdo->query('SELECT COUNT(*) FROM advisors WHERE is_active = 1')->fetchColumn();

    $randomProjectStmt = $pdo->query("SELECT id FROM projects WHERE status = 'approved' ORDER BY RAND() LIMIT 1");
    $randomProjectId = $randomProjectStmt->fetchColumn();
    $randomProjectLink = $randomProjectId ? "./project_detail.php?id=" . (int)$randomProjectId : "#";

    $currentYearRow = $pdo->query('SELECT id, year FROM academic_years ORDER BY year DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($currentYearRow)) {
        $statsLatestYearLabel = trim((string) ($currentYearRow['year'] ?? ''));
        if ($statsLatestYearLabel !== '') {
            $currentYearCountStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM projects p
                 INNER JOIN academic_years a ON a.id = p.academic_year_id
                 WHERE p.status = 'approved' AND a.year = :year"
            );
            $currentYearCountStmt->execute([':year' => $statsLatestYearLabel]);
            $statsLatestYearCount = (int) $currentYearCountStmt->fetchColumn();
        }
    }

    $featuredStmt = $pdo->prepare(
        'SELECT p.id, p.title_th, p.title_en, p.creators, p.file_urls, c.name AS category_name
         FROM projects p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.status = :status
         ORDER BY RAND()
         LIMIT 4'
    );
    $featuredStmt->execute([':status' => $approvedStatus]);
    $featuredProjects = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[HOME] load approved projects failed: ' . $e->getMessage());
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

$formatStatPlus = static function (int $value): string {
    return number_format($value);
};

$heroFeaturedMain = $featuredProjects[0] ?? null;
$heroFeaturedMini = array_slice($featuredProjects, 1, 3);
$heroMiniFallbacks = [
    ['tag_th' => 'โมชั่น', 'tag_en' => 'Motion', 'title' => 'Echoes of Silence', 'gradient' => 'from-[#5B4BDB] via-[#7C3AED] to-[#A855F7]', 'image' => './assets/images/thesis-mini-motion.png'],
    ['tag_th' => 'ยูเอ็กซ์', 'tag_en' => 'UX', 'title' => 'Home Banking', 'gradient' => 'from-[#0EA5E9] via-[#6366F1] to-[#A78BFA]', 'image' => './assets/images/thesis-mini-ux.png'],
    ['tag_th' => '3D', 'tag_en' => '3D', 'title' => 'Beyond The Limit', 'gradient' => 'from-[#312E81] via-[#4338CA] to-[#6D28D9]', 'image' => './assets/images/thesis-mini-3d.png'],
];

$heroMainTitle = 'The Last Shimmer';
$heroMainCreator = 'Atis Wongsiri';
$heroMainCategoryTh = 'ภาพยนตร์สั้น';
$heroMainCategoryEn = 'Short Film';
$heroMainThumb = '';
$heroMainLink = '#all-works';

if (is_array($heroFeaturedMain)) {
    $tTh = trim((string) ($heroFeaturedMain['title_th'] ?? ''));
    $tEn = trim((string) ($heroFeaturedMain['title_en'] ?? ''));
    $heroMainTitleTh = $tTh !== '' ? $tTh : ($tEn !== '' ? $tEn : 'Untitled');
    $heroMainTitleEn = $tEn !== '' ? $tEn : ($tTh !== '' ? $tTh : 'Untitled');
    $heroMainCreator = trim((string) ($heroFeaturedMain['creators'] ?? 'Unknown'));
    $heroMainCategoryTh = trim((string) ($heroFeaturedMain['category_name'] ?? 'ภาพยนตร์สั้น'));
    $heroMainCategoryEn = $heroMainCategoryTh;
    $heroMainFileUrls = json_decode((string) ($heroFeaturedMain['file_urls'] ?? ''), true);
    $heroMainThumbData = is_array($heroMainFileUrls) && isset($heroMainFileUrls['thumbnail']) && is_array($heroMainFileUrls['thumbnail'])
        ? $heroMainFileUrls['thumbnail']
        : null;
    $heroMainThumb = buildThumbnailUrl($heroMainThumbData);
    $heroMainLink = './project_detail.php?id=' . (int) ($heroFeaturedMain['id'] ?? 0);
}

$statsLatestYearTitleTh = $statsLatestYearLabel !== '' ? 'รุ่นปี ' . $statsLatestYearLabel : '';
$statsLatestYearTitleEn = $statsLatestYearLabel !== '' ? 'CLASS OF ' . $statsLatestYearLabel : '';

$heroMiniViewFallbacks = ['3.4k', '2.1k', '1.8k'];
$heroMiniCards = [];
for ($miniIndex = 0; $miniIndex < 3; $miniIndex++) {
    $project = $heroFeaturedMini[$miniIndex] ?? null;
    $fallback = $heroMiniFallbacks[$miniIndex];
    if (is_array($project)) {
        $miniFileUrls = json_decode((string) ($project['file_urls'] ?? ''), true);
        $miniThumbData = is_array($miniFileUrls) && isset($miniFileUrls['thumbnail']) && is_array($miniFileUrls['thumbnail'])
            ? $miniFileUrls['thumbnail']
            : null;
        $mTh = trim((string) ($project['title_th'] ?? ''));
        $mEn = trim((string) ($project['title_en'] ?? ''));
        $miniTitleTh = $mTh !== '' ? $mTh : ($mEn !== '' ? $mEn : $fallback['title']);
        $miniTitleEn = $mEn !== '' ? $mEn : ($mTh !== '' ? $mTh : $fallback['title']);
        $heroMiniCards[] = [
            'link' => './project_detail.php?id=' . (int) ($project['id'] ?? 0),
            'title_th' => $miniTitleTh,
            'title_en' => $miniTitleEn,
            'creator' => trim((string) ($project['creators'] ?? '')),
            'tag_th' => trim((string) ($project['category_name'] ?? $fallback['tag_th'])),
            'tag_en' => trim((string) ($project['category_name'] ?? $fallback['tag_en'])),
            'thumb' => buildThumbnailUrl($miniThumbData),
            'gradient' => $fallback['gradient'],
            'placeholder' => $fallback['image'],
            'views' => $heroMiniViewFallbacks[$miniIndex],
        ];
    } else {
        $heroMiniCards[] = [
            'link' => '#all-works',
            'title_th' => $fallback['title'],
            'title_en' => $fallback['title'],
            'creator' => '',
            'tag_th' => $fallback['tag_th'],
            'tag_en' => $fallback['tag_en'],
            'thumb' => '',
            'gradient' => $fallback['gradient'],
            'placeholder' => $fallback['image'],
            'views' => $heroMiniViewFallbacks[$miniIndex],
        ];
    }
}

$archiveStatCards = [
    [
        'chip_th' => 'คลัง',
        'chip_en' => 'ARCHIVE',
        'title_th' => 'ผลงานทั้งหมด',
        'title_en' => 'TOTAL WORKS',
        'value' => $formatStatPlus($statsTotalWorks),
        'subtitle_th' => 'ผลงานทั้งหมด',
        'subtitle_en' => 'Total works',
        'image' => './assets/images/1.jpg',
        'gradient' => 'from-[#FFF7F7] via-[#FEE2E2] to-[#F3F4F6]',
        'icon' => 'works',
    ],
    [
        'chip_th' => 'ล่าสุด',
        'chip_en' => 'LATEST',
        'title_th' => $statsLatestYearTitleTh,
        'title_en' => $statsLatestYearTitleEn,
        'value' => $formatStatPlus($statsLatestYearCount),
        'subtitle_th' => $statsLatestYearTitleTh,
        'subtitle_en' => 'Latest class year',
        'image' => './assets/images/2.jpg',
        'gradient' => 'from-[#EFF6FF] via-[#FDE7FF] to-[#F3F4F6]',
        'icon' => 'star',
    ],
    [
        'chip_th' => 'หลากหลาย',
        'chip_en' => 'DIVERSE',
        'title_th' => 'ประเภทหมวดหมู่',
        'title_en' => 'CATEGORIES',
        'value' => $formatStatPlus($statsCategoryCount),
        'subtitle_th' => 'ประเภทหมวดหมู่',
        'subtitle_en' => 'Category types',
        'image' => './assets/images/3.jpg',
        'gradient' => 'from-[#ECFDF5] via-[#FEF2F2] to-[#F3F4F6]',
        'icon' => 'categories',
    ],
    [
        'chip_th' => 'คณาจารย์',
        'chip_en' => 'FACULTY',
        'title_th' => 'อาจารย์ที่ปรึกษา',
        'title_en' => 'ADVISORS',
        'value' => $formatStatPlus($statsAdvisorCount),
        'subtitle_th' => 'อาจารย์ที่ปรึกษา',
        'subtitle_en' => 'Faculty advisors',
        'image' => './assets/images/5.png',
        'gradient' => 'from-[#FDE68A] via-[#FBCFE8] to-[#F3F4F6]',
        'icon' => 'advisors',
    ],
];
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>RMUTI MT Thesis Gallery</title>
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
      transition: box-shadow 0.25s ease, background-color 0.25s ease;
    }
    .site-header-inner {
      height: 64px;
      transition: height 0.2s ease;
    }
    .site-header.is-scrolled {
      box-shadow: 0 8px 24px -18px rgba(15, 23, 42, 0.45);
      background-color: rgba(255, 255, 255, 0.98);
    }
    .site-header.is-scrolled .site-header-inner {
      height: 56px;
    }
    html.dark .site-header.is-scrolled {
      background-color: rgba(10, 10, 10, 0.98);
      box-shadow: 0 8px 24px -18px rgba(0, 0, 0, 0.75);
    }
    html.dark .hero-title {
      color: #FAFAFA;
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
    .hero-title {
      margin-top: 24px;
      font-size: 88px;
      line-height: 0.9;
      letter-spacing: 0.02em;
      color: #101216;
      font-weight: 600;
    }

    .hero-student-accent {
      color: #D32F2F;
      font-style: italic;
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    .hero-cta-primary {
      background: #D32F2F;
      box-shadow: 0 0 0 1px rgba(211, 47, 47, 0.12), 0 0 28px rgba(211, 47, 47, 0.28);
    }

    .hero-cta-primary:hover {
      background: #B71C1C;
      box-shadow: 0 0 0 1px rgba(183, 28, 28, 0.16), 0 0 36px rgba(211, 47, 47, 0.34);
    }

    .hero-cta-secondary {
      background: rgba(243, 244, 246, 0.92);
      border: 1px solid #E5E7EB;
      color: #111827;
    }

    .hero-mascot-img {
      display: block;
      width: 100%;
      max-width: 360px;
      height: auto;
      background: transparent;
      border: 0;
      box-shadow: none;
      object-fit: contain;
      object-position: center bottom;
      animation: floating 4s ease-in-out infinite;
    }

    .thesis-feature-card {
      min-height: 220px;
      background: linear-gradient(180deg, rgba(17, 24, 39, 0.15) 0%, rgba(17, 24, 39, 0.92) 68%, rgba(17, 24, 39, 0.98) 100%);
    }

    .thesis-mini-card {
      min-height: 148px;
    }

    .thesis-tag-pill {
      display: inline-flex;
      align-items: center;
      width: fit-content;
      max-width: 88%;
      overflow: hidden;
      white-space: nowrap;
      border-radius: 0.375rem;
      background-color: #D32F2F;
      padding: 0.125rem 0.5rem;
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: 0.05em;
      color: #ffffff;
      text-transform: uppercase;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.3);
      shrink: 0;
    }

    .thesis-tag-pill .tag-marquee {
      display: inline-block;
      white-space: nowrap;
      transition: transform 1.5s cubic-bezier(0.25, 1, 0.5, 1);
      will-change: transform;
    }

    .stat-card {
      min-height: 148px;
    }

    .stat-card-media {
      min-height: 148px;
      border-top-left-radius: 1rem;
      border-bottom-left-radius: 1rem;
    }

    .stat-chip {
      background: #D32F2F;
      color: #fff;
      font-size: 10px;
      letter-spacing: 0.14em;
      font-weight: 700;
      text-transform: uppercase;
    }

    .stat-metric-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .stat-icon-box {
      width: 34px;
      height: 34px;
      border: 1.5px solid rgba(211, 47, 47, 0.35);
      border-radius: 12px;
      background: rgba(211, 47, 47, 0.08);
      display: grid;
      place-items: center;
      color: #D32F2F;
      flex-shrink: 0;
    }

    html.dark .hero-cta-secondary {
      background: rgba(38, 38, 38, 0.92);
      border-color: #404040;
      color: #F5F5F5;
    }

    @keyframes floating {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-15px); }
    }

    @keyframes marquee {
      0% { transform: translateX(0); }
      100% { transform: translateX(-50%); }
    }

    .hero-marquee-container {
      display: flex;
      align-items: center;
      overflow: hidden;
    }

    .hero-marquee-track {
      display: flex;
      flex-shrink: 0;
      width: max-content;
      animation: marquee 80s linear infinite;
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
    .auth-success-box {
      text-align: center;
      padding: 14px 0 8px;
    }
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
    .section-divider {
      border-top: 1px solid #DDDEE1;
      margin: 0;
    }
    html.dark .section-divider {
      border-top-color: #262626;
    }
    .works-heading-wrap {
      position: relative;
      padding-top: 26px;
    }
    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    .works-heading-wrap::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 64px;
      height: 2px;
      background: #D32F2F;
      border-radius: 999px;
      opacity: 0.9;
    }
    .works-subtitle {
      font-size: 11px;
      letter-spacing: 0.28em;
      color: #C03A3A;
      font-weight: 600;
      text-transform: uppercase;
    }
    html.dark .works-subtitle {
      color: #FCA5A5;
    }
    .works-title {
      margin-top: 8px;
      font-size: 48px;
      line-height: 0.95;
      color: #111418;
    }
    html.dark .works-title {
      color: #FAFAFA;
    }
    .work-card {
      overflow: hidden;
      border-radius: 16px;
      border: 1px solid var(--border);
      background: #fff;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
      transition: transform .2s ease, box-shadow .2s ease;
    }
    .work-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.1);
      border-color: var(--mt-red);
    }
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
    .work-card:hover .work-thumb-media {
      transform: scale(1.05);
      filter: none !important;
    }
    .work-category-badge {
      display: inline-flex;
      align-items: center;
      background: rgba(255, 255, 255, 0.9);
      color: #1F2937;
      border-radius: 999px;
      padding: 3px 10px;
      font-size: 10px;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      font-weight: 700;
      backdrop-filter: blur(4px);
      border: 1px solid rgba(255, 255, 255, 0.85);
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
    .work-card:hover .work-overlay-eye {
      opacity: 1;
      transform: translate(-50%, -50%) scale(1);
    }

    @media (max-width: 900px) {
      .hero-title { font-size: 64px; line-height: 0.92; }
      .auth-title { font-size: 40px; }
      .auth-subtitle { font-size: 15px; }
      .auth-submit { font-size: 15px; }
      .auth-success-title { font-size: 27px; }
    }

    /* Premium Animations */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-up {
      animation: fadeUp 1s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0;
    }
    .delay-100 { animation-delay: 100ms; }
    .delay-200 { animation-delay: 200ms; }

    .work-card.reveal {
      opacity: 0;
      transform: translateY(40px);
      transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .work-card.reveal.active {
      opacity: 1;
      transform: translateY(0);
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
<body class="dark:bg-[#121212] dark:text-gray-100" data-error="<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>" data-success="<?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>" data-initial-modal="<?= htmlspecialchars($initialModal, ENT_QUOTES, 'UTF-8') ?>">
  <!-- BUILD: <?= htmlspecialchars($buildSignature, ENT_QUOTES, 'UTF-8') ?> -->

  <?php
  $activePage = 'index';
  require_once __DIR__ . '/config/site_header.php';
  ?>

  <div id="flash-message" class="flash-message"></div>

  <main class="dark:bg-[#121212]">
    <div class="relative w-full bg-[url('./assets/images/bg-grid.svg')] dark:bg-[url('./assets/images/bg-grid-dark.svg')] bg-cover bg-[center_top_-4rem] bg-no-repeat">
    <section class="hero-section-wrapper relative z-20 w-full bg-transparent">
      <div class="hero-tech-overlay absolute inset-0 pointer-events-none z-0" aria-hidden="true"></div>

      <div class="relative z-[1] mx-auto max-w-7xl bg-transparent px-6 pt-16 sm:pt-20 lg:pt-24 pb-4">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 xl:gap-10 items-center">
          <!-- Left Column -->
          <div class="lg:col-span-4 xl:col-span-4">
            <div class="flex items-center gap-3">
              <span class="h-2 w-2 rounded-full bg-[#D32F2F] shadow-[0_0_16px_rgba(211,47,47,0.55)]" aria-hidden="true"></span>
              <p class="text-[10px] sm:text-[11px] font-bold tracking-[0.28em] text-[#D32F2F] uppercase">
                MULTIMEDIA TECHNOLOGY • RMUTI
              </p>
            </div>

            <h1 class="mt-5 font-sans tracking-[-0.03em] text-[#111827] dark:text-gray-100 leading-[0.92]">
              <span class="block text-[2.75rem] sm:text-6xl lg:text-[4.25rem] xl:text-[4.75rem] font-extrabold">Multimedia</span>
              <span class="block hero-student-accent text-[2.75rem] sm:text-6xl lg:text-[4.25rem] xl:text-[4.75rem] -mt-1">Student</span>
              <span class="block text-[2.75rem] sm:text-6xl lg:text-[4.25rem] xl:text-[4.75rem] font-extrabold">Archive</span>
            </h1>

            <p
              class="mt-5 max-w-[22rem] text-[15px] leading-[1.65] text-[#6B7280] dark:text-gray-400"
              data-th="คลังข้อมูลดิจิทัลรวบรวมผลงานสร้างสรรค์โดยนักศึกษาสาขาเทคโนโลยีมัลติมีเดีย มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน"
              data-en="A digital archive of creative works by students from the Department of Multimedia Technology at Rajamangala University of Technology Isan."
            >
              คลังข้อมูลดิจิทัลรวบรวมผลงานสร้างสรรค์โดยนักศึกษาสาขาเทคโนโลยีมัลติมีเดีย มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4 w-full sm:w-auto">
              <a
                href="#all-works"
                class="hero-cta-primary w-full sm:w-auto inline-flex justify-center items-center whitespace-nowrap rounded-xl px-6 py-3.5 text-[11px] font-bold tracking-[0.18em] text-white transition"
                data-th="สำรวจคลังผลงาน >"
                data-en="EXPLORE ARCHIVE >"
              >
                สำรวจคลังผลงาน &gt;
              </a>
              <a
                href="<?= $randomProjectLink ?>"
                class="hero-cta-secondary w-full sm:w-auto inline-flex justify-center items-center gap-2 whitespace-nowrap rounded-xl px-6 py-3.5 text-[11px] font-bold tracking-[0.14em] transition hover:border-[#D32F2F]/40"
                data-th="สุ่มผลงาน"
                data-en="RANDOM DISCOVERY"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6v12a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0016.5 18v-2.25m0 0l3 3m-3-3l3-3M3.75 15.75H9a2.25 2.25 0 002.25-2.25V9a2.25 2.25 0 00-2.25-2.25H3.75" />
                </svg>
                สุ่มผลงาน
              </a>
            </div>
          </div>

          <!-- Center Column -->
          <div class="lg:col-span-4 relative flex justify-center items-end mt-12 sm:mt-16 lg:mt-24">
            <img
              src="./assets/images/hero-mascot.png?v=<?= @filemtime(__DIR__ . '/assets/images/hero-mascot.png') ?>"
              alt="RMUTI Multimedia Mascot"
              class="hero-mascot-img relative z-[2] max-w-[280px] sm:max-w-[320px] lg:max-w-[360px] object-contain"
            />
          </div>

          <!-- Right Column -->
          <aside class="lg:col-span-4 xl:col-span-4">
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="h-9 w-[3px] bg-[#D32F2F] rounded-full shrink-0" aria-hidden="true"></span>
                <h3
                  class="text-[11px] font-bold tracking-[0.28em] text-[#D32F2F] uppercase"
                  data-th="คลังวิทยานิพนธ์"
                  data-en="THESIS COLLECTION"
                >
                  คลังวิทยานิพนธ์
                </h3>
              </div>
              <a
                href="#all-works"
                class="text-[13px] font-semibold text-[#D32F2F] hover:text-[#B71C1C] whitespace-nowrap transition"
                data-th="ดูทั้งหมด >"
                data-en="View all >"
              >
                ดูทั้งหมด &gt;
              </a>
            </div>

            <a href="<?= htmlspecialchars($heroMainLink, ENT_QUOTES, 'UTF-8') ?>" class="group mt-4 block overflow-hidden rounded-2xl border border-black/10 shadow-[0_18px_50px_rgba(0,0,0,0.18)]">
              <article class="thesis-feature-card relative flex min-h-[220px] flex-col justify-end p-5 sm:p-6">
                <?php if ($heroMainThumb !== ''): ?>
                  <img
                    src="<?= htmlspecialchars($heroMainThumb, ENT_QUOTES, 'UTF-8') ?>"
                    alt=""
                    class="absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]"
                  />
                <?php else: ?>
                  <!-- ASSET: Featured thesis thumbnail placeholder -->
                  <img
                    src="./assets/images/thesis-featured.png"
                    alt=""
                    class="absolute inset-0 h-full w-full object-cover object-center transition duration-500 group-hover:scale-[1.03]"
                  />
                <?php endif; ?>
                <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/45 to-black/10"></div>
                <div class="relative z-10">
                  <span
                    class="inline-flex max-w-[90%] items-center truncate whitespace-nowrap rounded-md bg-[#D32F2F] px-2.5 py-1 text-[10px] font-bold tracking-wide text-white uppercase shadow-sm"
                    data-th="<?= htmlspecialchars($heroMainCategoryTh, ENT_QUOTES, 'UTF-8') ?>"
                    data-en="<?= htmlspecialchars($heroMainCategoryEn, ENT_QUOTES, 'UTF-8') ?>"
                  >
                    <?= htmlspecialchars($heroMainCategoryTh, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                  <h4
                    class="mt-2 text-base sm:text-lg lg:text-xl font-bold text-white leading-snug sm:leading-tight line-clamp-2"
                    title="<?= htmlspecialchars($heroMainTitleTh ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?>"
                    data-th="<?= htmlspecialchars($heroMainTitleTh ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?>"
                    data-en="<?= htmlspecialchars($heroMainTitleEn ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?>"
                  >
                    <?= htmlspecialchars($heroMainTitleTh ?? 'Untitled', ENT_QUOTES, 'UTF-8') ?>
                  </h4>
                </div>
              </article>
            </a>

            <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
              <?php foreach ($heroMiniCards as $miniCard): ?>
                <a
                  href="<?= htmlspecialchars($miniCard['link'], ENT_QUOTES, 'UTF-8') ?>"
                  class="group thesis-mini-card relative overflow-hidden rounded-2xl border border-white/10 bg-[#111827] shadow-[0_10px_30px_rgba(0,0,0,0.12)]"
                >
                  <?php if ($miniCard['thumb'] !== ''): ?>
                    <img
                      src="<?= htmlspecialchars($miniCard['thumb'], ENT_QUOTES, 'UTF-8') ?>"
                      alt=""
                      class="absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.05]"
                    />
                  <?php else: ?>
                    <!-- ASSET: Mini thesis card thumbnail placeholder -->
                    <img
                      src="<?= htmlspecialchars($miniCard['placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                      alt=""
                      class="absolute inset-0 h-full w-full object-cover object-center transition duration-500 group-hover:scale-[1.05]"
                    />
                  <?php endif; ?>
                  <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/10"></div>
                  <div class="relative z-10 flex h-full flex-col justify-between p-3">
                    <span class="thesis-tag-pill" title="<?= htmlspecialchars($miniCard['tag_th'], ENT_QUOTES, 'UTF-8') ?>">
                      <span
                        class="tag-marquee"
                        data-th="<?= htmlspecialchars($miniCard['tag_th'], ENT_QUOTES, 'UTF-8') ?>"
                        data-en="<?= htmlspecialchars($miniCard['tag_en'], ENT_QUOTES, 'UTF-8') ?>"
                      >
                        <?= htmlspecialchars($miniCard['tag_th'], ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </span>
                    <div>
                      <h5
                        class="text-xs sm:text-[13px] font-bold leading-snug text-white line-clamp-2"
                        title="<?= htmlspecialchars($miniCard['title_th'], ENT_QUOTES, 'UTF-8') ?>"
                        data-th="<?= htmlspecialchars($miniCard['title_th'], ENT_QUOTES, 'UTF-8') ?>"
                        data-en="<?= htmlspecialchars($miniCard['title_en'], ENT_QUOTES, 'UTF-8') ?>"
                      >
                        <?= htmlspecialchars($miniCard['title_th'], ENT_QUOTES, 'UTF-8') ?>
                      </h5>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </aside>
        </div>
      </div>
    </section>

    <!-- Archive Overview (Stats) -->
    <section id="archive-overview" class="relative z-10 w-full bg-transparent pt-2 sm:pt-4 pb-12 sm:pb-14 lg:pb-16">
      <div class="relative mx-auto max-w-7xl bg-transparent px-6">
        <div class="mb-6 sm:mb-8 bg-transparent">
          <div class="flex items-center gap-3">
            <span class="h-9 w-[3px] rounded-full bg-[#D32F2F] shrink-0" aria-hidden="true"></span>
            <div>
              <div
                class="text-[11px] font-bold tracking-[0.28em] text-[#D32F2F] uppercase"
                data-th="ภาพรวมคลังผลงาน"
                data-en="ARCHIVE OVERVIEW"
              >
                ภาพรวมคลังผลงาน
              </div>
            </div>
          </div>
        </div>

        <div class="mx-auto grid max-w-6xl grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 bg-transparent">
          <?php foreach ($archiveStatCards as $statCard): ?>
            <article class="stat-card flex overflow-hidden rounded-xl bg-white border border-gray-200 shadow-lg dark:bg-[#1A1D21] dark:border-[#333] dark:shadow-[0_8px_30px_rgba(0,0,0,0.6)] transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl hover:border-[var(--mt-red)] dark:hover:border-red-500/50 cursor-pointer">
              <div class="stat-card-media relative w-[42%] shrink-0 overflow-hidden bg-[#f3f3f3] dark:bg-[#262626]">
                <!-- ASSET: Replace with <?= htmlspecialchars($statCard['image'], ENT_QUOTES, 'UTF-8') ?> -->
                <img
                  src="<?= htmlspecialchars($statCard['image'], ENT_QUOTES, 'UTF-8') ?>"
                  alt=""
                  class="w-full h-full object-cover"
                  onerror="this.classList.add('hidden');"
                />
                <div class="absolute inset-x-0 bottom-3 flex justify-center px-2">
                  <span class="stat-chip rounded-full px-3 py-1">
                    <span data-th="<?= htmlspecialchars($statCard['chip_th'], ENT_QUOTES, 'UTF-8') ?>" data-en="<?= htmlspecialchars($statCard['chip_en'], ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($statCard['chip_th'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </span>
                </div>
              </div>

              <div class="flex min-w-0 flex-1 flex-col justify-center px-4 py-4 sm:px-5">
                <div
                  class="text-[10px] font-bold tracking-[0.24em] text-[#D32F2F] uppercase leading-tight"
                  data-th="<?= htmlspecialchars($statCard['title_th'], ENT_QUOTES, 'UTF-8') ?>"
                  data-en="<?= htmlspecialchars($statCard['title_en'], ENT_QUOTES, 'UTF-8') ?>"
                >
                  <?= htmlspecialchars($statCard['title_th'], ENT_QUOTES, 'UTF-8') ?>
                </div>

                <div class="stat-metric-row mt-3">
                  <div class="stat-icon-box" aria-hidden="true">
                    <?php if ($statCard['icon'] === 'works'): ?>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                      </svg>
                    <?php elseif ($statCard['icon'] === 'star'): ?>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14v7" />
                      </svg>
                    <?php elseif ($statCard['icon'] === 'categories'): ?>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                      </svg>
                    <?php else: ?>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z" />
                      </svg>
                    <?php endif; ?>
                  </div>
                  <div class="text-[2rem] leading-none font-extrabold tracking-[-0.03em] text-[#111827] dark:text-white">
                    <?= htmlspecialchars($statCard['value'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </div>

              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    </div>

    <section id="all-works" class="pb-16">
      <hr class="section-divider" />
      <div class="mx-auto max-w-7xl px-6">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pt-9">
          <div class="works-heading-wrap">
            <div class="works-subtitle dark:text-red-300" data-th="คลังวิทยานิพนธ์" data-en="Thesis Collection">คลังวิทยานิพนธ์</div>
            <h2 class="works-title font-display dark:text-white" data-th="ผลงานทั้งหมด" data-en="All Works">ผลงานทั้งหมด</h2>
          </div>
          <div class="flex items-center justify-between gap-3 mt-4 sm:mt-0">
            <span class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap shrink-0" id="works-count-label" data-th="<?= number_format(count($projects)) ?> จาก <?= number_format($approvedProjectCount) ?> ผลงาน" data-en="<?= number_format(count($projects)) ?> of <?= number_format($approvedProjectCount) ?> works">
              <?= number_format(count($projects)) ?> จาก <?= number_format($approvedProjectCount) ?> ผลงาน
            </span>

            <div class="relative">
              <label for="sort-select" class="sr-only">เรียงตาม</label>
              <select name="sort" id="sort-select" class="h-10 w-36 appearance-none rounded-lg border border-gray-300 bg-white pl-3 pr-8 text-sm text-gray-700 outline-none transition-all focus:border-[var(--mt-red)] focus:ring-1 focus:ring-[var(--mt-red)] truncate dark:border-[#262626] dark:bg-[#1e1e1e] dark:text-gray-100" onchange="changeSortOrder(this.value)">
                <option value="newest" <?= (isset($_GET['sort']) && $_GET['sort'] === 'oldest') ? '' : 'selected' ?> data-th="ใหม่ล่าสุด" data-en="Newest">ใหม่ล่าสุด</option>
                <option value="oldest" <?= (isset($_GET['sort']) && $_GET['sort'] === 'oldest') ? 'selected' : '' ?> data-th="เก่าที่สุด" data-en="Oldest">เก่าที่สุด</option>
              </select>
              <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500 dark:text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </div>
          </div>
        </div>

        <div class="relative mt-6">
          <button
            type="button"
            id="category-scroll-left"
            class="category-scroll-btn hidden sm:flex absolute -left-3 top-1/2 z-10 h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-gray-200 bg-white/95 text-gray-700 shadow-md backdrop-blur-sm transition-all duration-300 hover:border-[#D32F2F] hover:text-[#D32F2F] dark:border-[#333] dark:bg-[#1e1e1e]/95 dark:text-gray-200 dark:hover:border-red-500/50 dark:hover:text-red-400 opacity-0 pointer-events-none"
            aria-label="เลื่อนหมวดหมู่ไปทางซ้าย"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
          </button>

          <div id="category-track" class="flex items-center gap-3 overflow-x-auto scroll-smooth scrollbar-hide pb-2 snap-x px-1">
            <?php
            $categoryPillBase = 'whitespace-nowrap px-4 py-2 rounded-full text-[13px] font-medium transition-all snap-start shrink-0 cursor-pointer select-none';
            $categoryPillActive = $categoryPillBase . ' bg-[#D32F2F] text-white shadow-md flex items-center gap-1.5';
            $categoryPillInactive = $categoryPillBase . ' bg-white dark:bg-[#1e1e1e] border border-gray-200 dark:border-[#333] text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-[#262626]';
            $isAllActive = empty($selectedCategoryIds);
            ?>
            <a
              href="javascript:void(0)"
              onclick="toggleCategoryFilter(0)"
              class="<?= $isAllActive ? $categoryPillActive : $categoryPillInactive ?>"
              data-th="ทั้งหมด"
              data-en="All"
            >
              <?php if ($isAllActive): ?>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              <?php endif; ?>
              <span>ทั้งหมด</span>
            </a>
            <?php foreach ($categories as $cat): ?>
              <?php
              $catIdInt = (int) $cat['id'];
              $isCatActive = in_array($catIdInt, $selectedCategoryIds, true);
              ?>
              <a
                href="javascript:void(0)"
                onclick="toggleCategoryFilter(<?= $catIdInt ?>)"
                class="<?= $isCatActive ? $categoryPillActive : $categoryPillInactive ?>"
              >
                <?php if ($isCatActive): ?>
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                <?php endif; ?>
                <span><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></span>
              </a>
            <?php endforeach; ?>

            <?php if (count($selectedCategoryIds) >= 2): ?>
              <a
                href="javascript:void(0)"
                onclick="toggleCategoryFilter(0)"
                class="whitespace-nowrap px-3 py-1.5 rounded-full text-xs font-semibold bg-gray-100 hover:bg-rose-50 text-gray-600 hover:text-[var(--mt-red)] border border-gray-200 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 transition-colors shrink-0 flex items-center gap-1"
                title="ล้างตัวกรองหมวดหมู่ทั้งหมด"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                <span>ล้างตัวกรอง (<?= count($selectedCategoryIds) ?>)</span>
              </a>
            <?php endif; ?>
          </div>

          <button
            type="button"
            id="category-scroll-right"
            class="category-scroll-btn hidden sm:flex absolute -right-3 top-1/2 z-10 h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-gray-200 bg-white/95 text-gray-700 shadow-md backdrop-blur-sm transition-all duration-300 hover:border-[#D32F2F] hover:text-[#D32F2F] dark:border-[#333] dark:bg-[#1e1e1e]/95 dark:text-gray-200 dark:hover:border-red-500/50 dark:hover:text-red-400"
            aria-label="เลื่อนหมวดหมู่ไปทางขวา"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        <?php if (count($projects) === 0): ?>
          <div class="mt-5 rounded-[10px] border border-[var(--border)] bg-[var(--bg-primary)] p-6 text-[var(--text-muted)] dark:bg-[#1e1e1e] dark:border-[#262626]" data-th="ขณะนี้ยังไม่มีผลงานที่เผยแพร่" data-en="No published works are available yet.">
            ขณะนี้ยังไม่มีผลงานที่เผยแพร่
          </div>
        <?php else: ?>
          <!-- Skeleton Loading Grid -->
          <div id="skeleton-grid" class="mt-6 grid auto-rows-fr gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <?php for ($sk = 0; $sk < 8; $sk++): ?>
            <div class="animate-pulse flex flex-col rounded-2xl bg-white p-4 shadow-sm border border-gray-100 dark:bg-[#1e1e1e] dark:border-[#262626]">
              <div class="h-48 w-full rounded-xl bg-gray-200"></div>
              <div class="mt-4 h-4 w-1/3 rounded bg-gray-200"></div>
              <div class="mt-2 h-6 w-3/4 rounded bg-gray-200"></div>
              <div class="mt-4 flex items-center gap-3">
                <div class="h-8 w-8 rounded-full bg-gray-200"></div>
                <div class="h-4 w-1/2 rounded bg-gray-200"></div>
              </div>
            </div>
            <?php endfor; ?>
          </div>

          <!-- Actual Project Grid (hidden until page loads) -->
          <div id="actual-project-grid" class="mt-6 grid auto-rows-fr gap-5 sm:grid-cols-2 lg:grid-cols-3 hidden opacity-0 transition-opacity duration-500">
            <?php foreach ($projects as $project): ?>
              <?php
              $fileUrls = json_decode((string) ($project['file_urls'] ?? ''), true);
              $thumbnailData = is_array($fileUrls) && isset($fileUrls['thumbnail']) && is_array($fileUrls['thumbnail'])
                  ? $fileUrls['thumbnail']
                  : null;
              $thumbnailUrl = buildThumbnailUrl($thumbnailData);
              $projectTitleTh = trim((string) ($project['title_th'] ?? 'Untitled'));
              $projectTitleEn = trim((string) ($project['title_en'] ?? ''));
              $projectCreators = trim((string) ($project['creators'] ?? '-'));
              $categoryName = trim((string) ($project['category_name'] ?? ''));
              $academicYear = trim((string) ($project['academic_year'] ?? ''));
              $advisorFull = trim((string) (($project['advisor_prefix'] ?? '') . ' ' . ($project['advisor_name'] ?? '')));

              $secIds = array_filter(array_map('intval', explode(',', (string) ($project['secondary_category_ids'] ?? ''))));
              $secNames = [];
              foreach ($secIds as $sid) {
                  if (isset($categoriesMap[$sid]) && $categoriesMap[$sid] !== $categoryName) {
                      $secNames[] = $categoriesMap[$sid];
                  }
              }
              $secNames = array_values(array_unique($secNames));

              $isPerfectMatch = false;
              if (count($selectedCategoryIds) >= 2 && isset($project['match_score'])) {
                  $isPerfectMatch = ((int) $project['match_score'] === count($selectedCategoryIds));
              }
              ?>
              <a href="./project_detail.php?id=<?= (int) ($project['id'] ?? 0) ?>" class="work-card reveal group flex h-full flex-col overflow-hidden dark:bg-[#1e1e1e] dark:border-[#262626] <?= $isPerfectMatch ? 'ring-2 ring-[var(--mt-red)]/60' : '' ?>" style="text-decoration:none;color:inherit;" aria-label="Open project detail">
                <div class="work-thumb-wrap relative h-44 w-full shrink-0 overflow-hidden bg-[#F3F4F6]">
                  <div class="absolute left-3 top-3 z-10 flex flex-wrap gap-1 items-center max-w-[88%]">
                    <?php if ($isPerfectMatch): ?>
                      <span class="rounded-full bg-[var(--mt-red)] text-white px-2.5 py-0.5 text-[9px] font-bold uppercase tracking-wide backdrop-blur-sm shadow-md flex items-center gap-1 border border-red-400">
                        <svg class="w-2.5 h-2.5 text-amber-300 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <span>ตรงทุกหมวดหมู่</span>
                      </span>
                    <?php endif; ?>
                    <?php if ($categoryName !== ''): ?>
                      <span class="work-category-badge inline-flex h-5 items-center justify-center rounded-full border border-white/80 bg-white/90 px-2.5 text-[10px] font-bold uppercase tracking-wide text-gray-800 backdrop-blur-sm shadow-sm dark:!border-gray-700 dark:!bg-gray-800/90 dark:!text-gray-100 leading-none"><?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php foreach ($secNames as $sName): ?>
                      <span class="inline-flex h-5 items-center justify-center gap-1 rounded-full border border-white/30 bg-black/60 px-2.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm shadow-sm leading-none">
                        <span class="opacity-60 text-[9px]">•</span>
                        <span><?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8') ?></span>
                      </span>
                    <?php endforeach; ?>
                  </div>
                  <?php if ($thumbnailUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($thumbnailUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($projectTitleTh, ENT_QUOTES, 'UTF-8') ?>" class="work-thumb-media h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" loading="lazy" decoding="async" />
                  <?php else: ?>
                    <div class="work-thumb-media grid h-full w-full place-items-center text-sm text-[#9CA3AF] transition-all duration-300 group-hover:bg-gray-200">No Image</div>
                  <?php endif; ?>
                </div>
                <div class="flex flex-1 flex-col p-5">
                  <h3 class="line-clamp-2 text-xl font-bold text-gray-900 dark:text-gray-100">
                    <span class="project-title-primary-th" title="<?= htmlspecialchars($projectTitleTh, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($projectTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="project-title-primary-en" title="<?= htmlspecialchars($projectTitleEn !== '' ? $projectTitleEn : $projectTitleTh, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($projectTitleEn !== '' ? $projectTitleEn : $projectTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
                  </h3>
                  <?php if ($projectTitleEn !== ''): ?>
                    <p class="mt-1 line-clamp-1 text-sm text-gray-500 dark:text-gray-400">
                      <span class="project-title-secondary-th" title="<?= htmlspecialchars($projectTitleEn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($projectTitleEn, ENT_QUOTES, 'UTF-8') ?></span>
                      <span class="project-title-secondary-en" title="<?= htmlspecialchars($projectTitleTh, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($projectTitleTh, ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                  <?php endif; ?>
                  <div class="mt-auto pt-4 text-sm text-gray-600 dark:text-gray-300">
                    <p class="line-clamp-1" title="<?= htmlspecialchars($projectCreators, ENT_QUOTES, 'UTF-8') ?>">BY <?= htmlspecialchars($projectCreators, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($academicYear !== ''): ?>
                      <p class="mt-1">YEAR: <?= htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if ($advisorFull !== ''): ?>
                      <p class="mt-1 line-clamp-1 text-red-600" title="<?= htmlspecialchars($advisorFull, ENT_QUOTES, 'UTF-8') ?>">Advisor: <?= htmlspecialchars($advisorFull, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($totalPages > 1): ?>
          <div class="mt-12 flex flex-col items-center justify-center">
            <div class="flex items-center gap-2">
              <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(getPageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>" class="flex h-10 w-10 items-center justify-center rounded-full border border-[#E5E7EB] bg-white text-[#6B7280] transition-colors hover:bg-[#F9FAFB] hover:text-[#111827] dark:border-[#262626] dark:bg-[#1e1e1e] dark:text-neutral-300 dark:hover:bg-[#262626] dark:hover:text-white">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                </a>
              <?php else: ?>
                <div class="flex h-10 w-10 cursor-not-allowed items-center justify-center rounded-full border border-[#E5E7EB] bg-gray-50 text-gray-300 dark:border-[#262626] dark:bg-[#1e1e1e]/50 dark:text-neutral-600">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                </div>
              <?php endif; ?>

              <?php
              $startPage = max(1, $page - 2);
              $endPage = min($totalPages, $page + 2);
              for ($i = $startPage; $i <= $endPage; $i++):
                  if ($i === $page):
              ?>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[#D32F2F] text-[15px] font-semibold text-white shadow-sm">
                  <?= (int) $i ?>
                </div>
              <?php else: ?>
                <a href="<?= htmlspecialchars(getPageUrl($i), ENT_QUOTES, 'UTF-8') ?>" class="flex h-10 w-10 items-center justify-center rounded-full border border-[#E5E7EB] bg-white text-[15px] font-medium text-[#4B5563] transition-colors hover:border-[#D32F2F] hover:text-[#D32F2F] dark:border-[#262626] dark:bg-[#1e1e1e] dark:text-neutral-300 dark:hover:border-[#D32F2F] dark:hover:text-[#D32F2F]">
                  <?= (int) $i ?>
                </a>
              <?php
                  endif;
              endfor;
              ?>

              <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(getPageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>" class="flex h-10 w-10 items-center justify-center rounded-full border border-[#E5E7EB] bg-white text-[#6B7280] transition-colors hover:bg-[#F9FAFB] hover:text-[#111827] dark:border-[#262626] dark:bg-[#1e1e1e] dark:text-neutral-300 dark:hover:bg-[#262626] dark:hover:text-white">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </a>
              <?php else: ?>
                <div class="flex h-10 w-10 cursor-not-allowed items-center justify-center rounded-full border border-[#E5E7EB] bg-gray-50 text-gray-300 dark:border-[#262626] dark:bg-[#1e1e1e]/50 dark:text-neutral-600">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </div>
              <?php endif; ?>
            </div>

            <div class="mt-4 text-sm font-medium text-[#9CA3AF]">
              Page <?= (int) $page ?> of <?= (int) $totalPages ?>
            </div>
          </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </section>
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
  <?php require_once __DIR__ . '/config/auth_modals.php'; ?>
  <script>
    // 1. ฟังก์ชันจัดการเมื่อมีการเปลี่ยนตัวเลือก Dropdown
    function changeSortOrder(sortValue) {
      // แอบจดตำแหน่ง Scroll ปัจจุบัน (แกน Y) ไว้ใน sessionStorage ก่อนทำการ Reload
      sessionStorage.setItem('savedScrollPos', String(window.scrollY));

      const url = new URL(window.location.href);
      url.searchParams.set('sort', sortValue);
      url.searchParams.delete('page');
      window.location.href = url.toString();
    }

    // 2. ทำงานอัตโนมัติเมื่อหน้าเว็บโหลดเสร็จ (หลัง Reload)
    document.addEventListener('DOMContentLoaded', function () {
      const savedPos = sessionStorage.getItem('savedScrollPos');
      if (savedPos !== null && savedPos !== '') {
        window.scrollTo({
          top: parseInt(savedPos, 10),
          behavior: 'instant'
        });
        sessionStorage.removeItem('savedScrollPos');
      }
    });
  </script>
<script>
window.addEventListener('load', function() {
  var skeletonGrid = document.getElementById('skeleton-grid');
  var actualGrid = document.getElementById('actual-project-grid');
  if (skeletonGrid && actualGrid) {
    skeletonGrid.classList.add('hidden');
    actualGrid.classList.remove('hidden');
    setTimeout(function() {
      actualGrid.classList.remove('opacity-0');

      var cards = document.querySelectorAll('.work-card.reveal');
      cards.forEach(function(card, index) {
        setTimeout(function() {
          card.classList.add('active');
        }, index * 120);
      });
    }, 50);
  }
});
</script>
<script>
(function () {
  var track = document.getElementById('category-track');
  var btnLeft = document.getElementById('category-scroll-left');
  var btnRight = document.getElementById('category-scroll-right');
  if (!track || !btnLeft || !btnRight) return;

  function updateCategoryScrollButtons() {
    var maxScroll = track.scrollWidth - track.clientWidth - 5;
    if (track.scrollLeft <= 5) {
      btnLeft.classList.add('opacity-0', 'pointer-events-none');
    } else {
      btnLeft.classList.remove('opacity-0', 'pointer-events-none');
    }
    if (track.scrollLeft >= maxScroll) {
      btnRight.classList.add('opacity-0', 'pointer-events-none');
    } else {
      btnRight.classList.remove('opacity-0', 'pointer-events-none');
    }
  }

  btnLeft.addEventListener('click', function () {
    track.scrollBy({ left: -240, behavior: 'smooth' });
  });
  btnRight.addEventListener('click', function () {
    track.scrollBy({ left: 240, behavior: 'smooth' });
  });

  track.addEventListener('scroll', updateCategoryScrollButtons, { passive: true });
  window.addEventListener('resize', updateCategoryScrollButtons, { passive: true });
  setTimeout(updateCategoryScrollButtons, 100);
})();
</script>
<script>
window.toggleCategoryFilter = function(catId) {
  var url = new URL(window.location.href);
  var currentSelected = [];
  var catsParam = url.searchParams.get('categories') || url.searchParams.get('category') || '';
  if (catsParam) {
    currentSelected = catsParam.split(',').map(function(v) { return v.trim(); }).filter(Boolean);
  }

  if (catId === 0 || catId === '0') {
    url.searchParams.delete('categories');
    url.searchParams.delete('category');
  } else {
    var catIdStr = String(catId);
    var idx = currentSelected.indexOf(catIdStr);
    if (idx >= 0) {
      currentSelected.splice(idx, 1);
    } else {
      currentSelected.push(catIdStr);
    }
    url.searchParams.delete('category');
    if (currentSelected.length > 0) {
      url.searchParams.set('categories', currentSelected.join(','));
    } else {
      url.searchParams.delete('categories');
    }
  }

  url.searchParams.delete('page');
  url.hash = 'all-works';
  window.location.href = url.toString();
};
</script>



<script>
  document.addEventListener('DOMContentLoaded', function () {
    const miniCards = document.querySelectorAll('.thesis-mini-card, .thesis-feature-card');
    miniCards.forEach(function (card) {
      const pill = card.querySelector('.thesis-tag-pill');
      const text = card.querySelector('.tag-marquee');
      if (!pill || !text) return;

      card.addEventListener('mouseenter', function () {
        const scrollDist = text.scrollWidth - pill.clientWidth + 10;
        if (scrollDist > 0) {
          text.style.transform = 'translateX(-' + scrollDist + 'px)';
        }
      });

      card.addEventListener('mouseleave', function () {
        text.style.transform = 'translateX(0px)';
      });
    });
  });
</script>
</body>
</html>

