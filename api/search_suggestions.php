<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/session.php';
start_secure_session();
session_write_close();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/search_helper.php';

if (!function_exists('extractDriveFileId')) {
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
}

if (!function_exists('buildThumbnailUrl')) {
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
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchYear = trim((string) ($_GET['year'] ?? ''));
$searchCategory = trim((string) ($_GET['category'] ?? ''));
$searchAdvisor = trim((string) ($_GET['advisor'] ?? ''));

if (mb_strlen($searchQuery, 'UTF-8') < 2 && $searchYear === '' && $searchCategory === '' && $searchAdvisor === '') {
    echo json_encode([
        'success' => true,
        'query' => $searchQuery,
        'total_matches' => 0,
        'suggestions' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $params = [':status' => 'approved'];
    $baseSql = "FROM projects p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN academic_years a ON a.id = p.academic_year_id
                LEFT JOIN advisors adv ON adv.id = p.advisor_id
                WHERE p.status = :status";

    if ($searchYear !== '') {
        $baseSql .= " AND a.year = :year";
        $params[':year'] = $searchYear;
    }

    $searchCategory = trim((string) ($_GET['category'] ?? ''));
    $searchCategoriesStr = trim((string) ($_GET['categories'] ?? ''));
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

    if (!empty($selectedCategoryIds)) {
        $catOrClauses = [];
        foreach ($selectedCategoryIds as $idx => $catIdVal) {
            $paramCat = ':category_id_' . $idx;
            $paramStr = ':cat_id_str_' . $idx;
            $catOrClauses[] = "(p.category_id = {$paramCat} OR FIND_IN_SET({$paramStr}, p.secondary_category_ids))";
            $params[$paramCat] = $catIdVal;
            $params[$paramStr] = (string) $catIdVal;
        }
        $baseSql .= " AND (" . implode(" OR ", $catOrClauses) . ")";
    }
    if ($searchAdvisor !== '') {
        $baseSql .= " AND p.advisor_id = :advisor_id";
        $params[':advisor_id'] = (int) $searchAdvisor;
    }

    if ($searchQuery !== '') {
        $baseSql .= build_smart_search_query($searchQuery, $params);
    }

    // Category lookup map
    $catRows = $pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $catMap = [];
    foreach ($catRows as $cr) {
        $catMap[(int) $cr['id']] = trim((string) $cr['name']);
    }

    // Count total matches
    $countStmt = $pdo->prepare("SELECT COUNT(*) " . $baseSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalMatches = (int) $countStmt->fetchColumn();

    // Fetch top 5 suggestions
    $sql = "SELECT p.id, p.title_th, p.title_en, p.file_urls, p.secondary_category_ids, c.name AS category_name " . $baseSql . " ORDER BY p.id DESC LIMIT 5";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suggestions = [];
    foreach ($rows as $row) {
        $fileUrls = json_decode((string) ($row['file_urls'] ?? ''), true);
        $thumbData = is_array($fileUrls) && isset($fileUrls['thumbnail']) && is_array($fileUrls['thumbnail'])
            ? $fileUrls['thumbnail']
            : null;
        $thumbUrl = buildThumbnailUrl($thumbData);

        $tTh = trim((string) ($row['title_th'] ?? ''));
        $tEn = trim((string) ($row['title_en'] ?? ''));

        $secIds = array_filter(array_map('intval', explode(',', (string) ($row['secondary_category_ids'] ?? ''))));
        $secNames = [];
        $mainName = trim((string) ($row['category_name'] ?? 'General'));
        foreach ($secIds as $sid) {
            if (isset($catMap[$sid]) && $catMap[$sid] !== $mainName) {
                $secNames[] = $catMap[$sid];
            }
        }

        $suggestions[] = [
            'id' => (int) $row['id'],
            'title_th' => $tTh !== '' ? $tTh : $tEn,
            'title_en' => $tEn !== '' ? $tEn : $tTh,
            'category_name' => $mainName,
            'secondary_category_names' => array_values(array_unique($secNames)),
            'thumbnail' => $thumbUrl,
            'link' => './project_detail.php?id=' . (int) $row['id'],
        ];
    }

    echo json_encode([
        'success' => true,
        'query' => $searchQuery,
        'total_matches' => $totalMatches,
        'suggestions' => $suggestions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to fetch suggestions.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
