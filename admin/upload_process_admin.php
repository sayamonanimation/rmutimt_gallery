<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
start_secure_session();

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/opendrive_helper.php';
require_once __DIR__ . '/../config/project_file_proxy.php';
require_once __DIR__ . '/../config/video_optimizer.php';
require_once __DIR__ . '/image_compress.php';

/** Per-file limit for main media video and optional project file (aligned with form client MAX_TOTAL_SIZE ~10 GB). */
const UPLOAD_MAX_LARGE_MEDIA_BYTES = 10 * 1024 * 1024 * 1024;

/**
 * @return array{id:string,link:string,webContentLink:string,name:string,mimeType:string}
 */
function uploadToOpenDrive(OpenDriveHelper $od, array $file, string $targetFolderId, string $imagePurpose = ''): array
{
    $folderId = trim($targetFolderId);
    if ($folderId === '') {
        $folderId = '0';
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_readable($tmpPath)) {
        throw new RuntimeException('ไม่สามารถอ่านไฟล์ชั่วคราวได้');
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('OpenDrive: อนุญาตเฉพาะไฟล์ที่อัปโหลดสำเร็จเท่านั้น');
    }

    $preparedImg = prepareImageUploadForOpenDrive($file, $imagePurpose);
    $preparedVid = prepareVideoUploadForOpenDrive([
        'tmp_name' => $preparedImg['path'],
        'name' => $preparedImg['name'],
        'error' => UPLOAD_ERR_OK,
    ]);

    try {
        return $od->uploadFile(
            (string) $preparedVid['path'],
            (string) $preparedVid['name'],
            $folderId
        );
    } finally {
        cleanupOptimizedVideoTemp($preparedVid);
        cleanupOptimizedUploadTemp($preparedImg);
    }
}

/**
 * @param array<string,mixed> $file
 */
function validateUploadedFile(array $file, array $allowedExtensions, int $maxBytes, string $label): ?string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return $label . ' ยังไม่ได้เลือกไฟล์';
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        $errorMap = [
            UPLOAD_ERR_INI_SIZE => 'ไฟล์มีขนาดใหญ่เกินค่า upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'ไฟล์มีขนาดใหญ่เกินค่าที่ฟอร์มอนุญาต',
            UPLOAD_ERR_PARTIAL => 'ไฟล์อัปโหลดไม่สมบูรณ์',
            UPLOAD_ERR_NO_TMP_DIR => 'ไม่พบโฟลเดอร์ temporary สำหรับอัปโหลด',
            UPLOAD_ERR_CANT_WRITE => 'ไม่สามารถเขียนไฟล์ลงดิสก์ได้',
            UPLOAD_ERR_EXTENSION => 'ไฟล์ถูกหยุดโดยส่วนขยายของ PHP',
        ];
        return $label . ': ' . ($errorMap[$errorCode] ?? 'เกิดข้อผิดพลาดระหว่างอัปโหลด');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return $label . ': ไฟล์ไม่ถูกต้องหรือว่างเปล่า';
    }
    if ($size > $maxBytes) {
        return sprintf('%s: ไฟล์ใหญ่เกินกำหนด (สูงสุด %d MB)', $label, (int) floor($maxBytes / 1024 / 1024));
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        return sprintf(
            '%s: ประเภทไฟล์ไม่ถูกต้อง (อนุญาต: %s)',
            $label,
            implode(', ', $allowedExtensions)
        );
    }

    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return $label . ': ไฟล์อัปโหลดไม่ผ่านการตรวจสอบความปลอดภัย';
    }

    return null;
}

/**
 * Normalize uploaded files for single/multiple file input names.
 *
 * @return array<int,array<string,mixed>>
 */
function normalizeUploadedFilesByField(string $fieldName): array
{
    $raw = $_FILES[$fieldName] ?? null;
    if (!is_array($raw)) {
        return [];
    }

    if (!isset($raw['name']) || !is_array($raw['name'])) {
        return [$raw];
    }

    $names = $raw['name'];
    $tmpNames = is_array($raw['tmp_name'] ?? null) ? $raw['tmp_name'] : [];
    $types = is_array($raw['type'] ?? null) ? $raw['type'] : [];
    $sizes = is_array($raw['size'] ?? null) ? $raw['size'] : [];
    $errors = is_array($raw['error'] ?? null) ? $raw['error'] : [];

    $result = [];
    foreach ($names as $idx => $name) {
        $result[] = [
            'name' => (string) $name,
            'type' => (string) ($types[$idx] ?? ''),
            'tmp_name' => (string) ($tmpNames[$idx] ?? ''),
            'error' => (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$idx] ?? 0),
        ];
    }
    return $result;
}

function redirectBack(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_array($_POST)) {
        $_SESSION['old_input'] = $_POST;
    }
    if (!headers_sent()) {
        header('Location: ./upload.php');
    } else {
        echo '<script>window.location.href="./upload.php";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=./upload.php"></noscript>';
    }
    exit;
}

function redirectToManageProjectsSuccess(): void
{
    if (!headers_sent()) {
        header('Location: ./manage_projects.php?status=success');
    } else {
        echo '<script>window.location.href="./manage_projects.php?status=success";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=./manage_projects.php?status=success"></noscript>';
    }
    exit;
}

function iniSizeToBytes(string $value): int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 0;
    }
    $unit = strtolower(substr($trimmed, -1));
    $number = (float) $trimmed;
    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function failUpload(string $message, ?string $detail = null): void
{
    $errors = [$message];
    if ($detail !== null && $detail !== '') {
        $errors[] = 'รายละเอียด: ' . $detail;
    }
    respondUploadErrors($errors);
}

function respondUploadErrors(array $errors): void
{
    if (od_request_is_xhr()) {
        od_respond_ajax_error(implode(' ', $errors));
    }
    $_SESSION['upload_errors'] = $errors;
    redirectBack();
}

function projectsTableHasDriveFolderId(PDO $pdo): bool
{
    static $hasColumn = null;
    if (is_bool($hasColumn)) {
        return $hasColumn;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'drive_folder_id'");
        $hasColumn = $stmt !== false && $stmt->fetch() !== false;
    } catch (Throwable $e) {
        error_log('[UPLOAD_PROCESS] check drive_folder_id column failed: ' . $e->getMessage());
        $hasColumn = false;
    }

    return $hasColumn;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name');
        $stmt->execute([':name' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectBack();
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$maxPostSize = iniSizeToBytes((string) ini_get('post_max_size'));
if ($contentLength > 0 && $maxPostSize > 0 && $contentLength > $maxPostSize) {
    respondUploadErrors([
        'ขนาดข้อมูลที่อัปโหลดเกินค่าที่เซิร์ฟเวอร์อนุญาต (post_max_size)',
        'กรุณาลดขนาดไฟล์ หรือปรับค่า post_max_size / upload_max_filesize ใน php.ini',
    ]);
}

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!is_array($lastError)) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['upload_errors'] = [
        'ระบบอัปโหลดหยุดทำงานระหว่างประมวลผล กรุณาลองใหม่อีกครั้ง',
        'รายละเอียด: ' . (string) ($lastError['message'] ?? 'unknown fatal error'),
    ];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_array($_POST)) {
        $_SESSION['old_input'] = $_POST;
    }
    if (!headers_sent()) {
        header('Location: ./upload.php');
    }
    exit;
});

set_exception_handler(static function (Throwable $e): void {
    error_log('[UPLOAD_PROCESS] uncaught exception: ' . $e->getMessage());
    $errors = [
        'เกิดข้อผิดพลาดระหว่างอัปโหลด กรุณาลองใหม่อีกครั้ง',
        'รายละเอียด: ' . $e->getMessage(),
    ];
    if (od_request_is_xhr()) {
        od_respond_ajax_error(implode(' ', $errors));
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['upload_errors'] = $errors;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_array($_POST)) {
            $_SESSION['old_input'] = $_POST;
        }
    }
    if (!headers_sent()) {
        header('Location: ./upload.php');
    }
    exit;
});

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    respondUploadErrors(['Token ไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง']);
}

$fileRules = [
    'thumbnail_file' => [
        'label' => 'ไฟล์รูปปก (Thumbnail)',
        'required' => true,
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'max_bytes' => 10 * 1024 * 1024,
    ],
    'thesis_pdf_file' => [
        'label' => 'ไฟล์ Thesis PDF',
        'required' => true,
        'extensions' => ['pdf'],
        'max_bytes' => 30 * 1024 * 1024,
    ],
    'project_file' => [
        'label' => 'ไฟล์โปรเจกต์ (ทางเลือก)',
        'required' => false,
        'extensions' => ['zip', 'rar', '7z', 'mp4', 'mov', 'jpg', 'jpeg', 'png', 'webp'],
        'max_bytes' => UPLOAD_MAX_LARGE_MEDIA_BYTES,
    ],
];

$errors = [];
$filesToUpload = [];
$project_link_raw = trim((string) ($_POST['project_link'] ?? ''));

foreach ($fileRules as $fieldName => $rule) {
    if ($fieldName === 'project_file' && $project_link_raw !== '') {
        continue;
    }

    $uploadedFile = $_FILES[$fieldName] ?? null;
    if (!is_array($uploadedFile)) {
        if (($rule['required'] ?? false) === true) {
            $errors[] = (string) $rule['label'] . ': ไม่พบข้อมูลไฟล์';
        }
        continue;
    }

    $isOptionalAndMissing = ($rule['required'] ?? false) === false
        && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
    if ($isOptionalAndMissing) {
        continue;
    }

    $validationError = validateUploadedFile(
        $uploadedFile,
        (array) ($rule['extensions'] ?? []),
        (int) ($rule['max_bytes'] ?? 0),
        (string) ($rule['label'] ?? $fieldName)
    );

    if ($validationError !== null) {
        $errors[] = $validationError;
        continue;
    }

    $filesToUpload[$fieldName] = [
        'label' => (string) ($rule['label'] ?? $fieldName),
        'file' => $uploadedFile,
    ];
}

if (!isset($od_username) || trim((string) $od_username) === '' || !isset($od_password) || (string) $od_password === '') {
    $errors[] = 'กรุณาตั้งค่า OpenDrive (od_username / od_password) ใน config/opendrive_config.php';
}

$mutualError = project_proxy_validate_mutual_exclusive(
    $project_link_raw,
    isset($filesToUpload['project_file'])
);
if ($mutualError !== null) {
    $errors[] = $mutualError;
}

if ($errors !== []) {
    respondUploadErrors($errors);
}

$project_link = project_proxy_normalize_external_url($project_link_raw);

$titleTh = trim((string) ($_POST['project_title_th'] ?? $_POST['title_th'] ?? ''));
$titleEn = trim((string) ($_POST['project_title_en'] ?? $_POST['title_en'] ?? ''));
$introduction = trim((string) ($_POST['introduction'] ?? ''));
$advisorId = (int) ($_POST['advisor_id'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
$displayType = (string) ($_POST['display_type'] ?? 'video');
$youtube_link = trim((string) ($_POST['youtube_link'] ?? ''));

$creatorNames = [];
foreach ($_POST as $key => $value) {
    if (!is_string($key) || !str_starts_with($key, 'author_name_')) {
        continue;
    }
    $name = trim((string) $value);
    if ($name !== '') {
        $creatorNames[] = $name;
    }
}

if ($titleTh === '') {
    $errors[] = 'กรุณากรอกชื่อผลงานภาษาไทย';
}
if ($categoryId <= 0) {
    $errors[] = 'กรุณาเลือกหมวดหมู่';
}
if ($academicYearId <= 0) {
    $errors[] = 'กรุณาเลือกปีการศึกษา';
}
if ($creatorNames === []) {
    $errors[] = 'กรุณากรอกชื่อผู้จัดทำอย่างน้อย 1 คน';
}
if (!in_array($displayType, ['video', 'gallery'], true)) {
    $displayType = 'video';
}

$mainMediaFiles = normalizeUploadedFilesByField('main_media_file');
$mainMediaLabel = 'ไฟล์สื่อหลัก (Main Media)';
$mainMediaMaxBytes = UPLOAD_MAX_LARGE_MEDIA_BYTES;
$videoModeAllowedExt = ['mp4', 'mov', 'jpg', 'jpeg', 'png'];
$galleryModeAllowedExt = ['jpg', 'jpeg', 'png', 'webp'];
$validMainMediaFiles = array_values(array_filter(
    $mainMediaFiles,
    static fn(array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
));

if ($displayType === 'video') {
    if (count($validMainMediaFiles) > 1) {
        $errors[] = 'โหมดวิดีโอต้องอัปโหลดไฟล์สื่อหลัก 1 ไฟล์เท่านั้น';
    } elseif ($youtube_link === '' && count($validMainMediaFiles) !== 1) {
        $errors[] = 'โหมดวิดีโอต้องอัปโหลดไฟล์สื่อหลัก 1 ไฟล์เท่านั้น';
    } elseif (count($validMainMediaFiles) === 1) {
        $validationError = validateUploadedFile(
            $validMainMediaFiles[0],
            $videoModeAllowedExt,
            $mainMediaMaxBytes,
            $mainMediaLabel
        );
        if ($validationError !== null) {
            $errors[] = $validationError;
        } else {
            $filesToUpload['main_media_file'] = [
                'label' => $mainMediaLabel,
                'file' => $validMainMediaFiles[0],
            ];
        }
    }
} else {
    if ($validMainMediaFiles === []) {
        $errors[] = 'โหมดแกลลอรีต้องเลือกไฟล์รูปภาพอย่างน้อย 1 รูป';
    } elseif (count($validMainMediaFiles) > 10) {
        $errors[] = 'โหมดแกลลอรีอัปโหลดได้สูงสุด 10 รูป';
    } else {
        $validatedMediaFiles = [];
        foreach ($validMainMediaFiles as $index => $mediaFile) {
            $validationError = validateUploadedFile(
                $mediaFile,
                $galleryModeAllowedExt,
                $mainMediaMaxBytes,
                $mainMediaLabel . ' #' . ($index + 1)
            );
            if ($validationError !== null) {
                $errors[] = $validationError;
                continue;
            }
            $validatedMediaFiles[] = $mediaFile;
        }
        if ($validatedMediaFiles !== []) {
            $filesToUpload['main_media_file'] = [
                'label' => $mainMediaLabel,
                'files' => $validatedMediaFiles,
            ];
        }
    }
}

if ($errors !== []) {
    respondUploadErrors($errors);
}

// Release session lock so admin can browse other tabs/pages during heavy file upload
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $od = new OpenDriveHelper();
    $od->login();

    global $od_folder_id;
    $rootFolderId = isset($od_folder_id) ? trim((string) $od_folder_id) : '';
    if ($rootFolderId === '') {
        $rootFolderId = '0';
    }

    $yearName = 'ปีไม่ระบุ';
    $stmtYear = $pdo->prepare('SELECT year FROM academic_years WHERE id = :id LIMIT 1');
    $stmtYear->execute([':id' => $academicYearId]);
    $rowYear = $stmtYear->fetch(PDO::FETCH_ASSOC);
    if (is_array($rowYear) && trim((string) ($rowYear['year'] ?? '')) !== '') {
        $yearName = trim((string) $rowYear['year']);
    }

    $advisorName = 'อาจารย์ไม่ระบุ';
    if ($advisorId > 0) {
        $stmtAdv = $pdo->prepare('SELECT prefix, full_name FROM advisors WHERE id = :id LIMIT 1');
        $stmtAdv->execute([':id' => $advisorId]);
        $rowAdv = $stmtAdv->fetch(PDO::FETCH_ASSOC);
        if (is_array($rowAdv)) {
            $prefix = trim((string) ($rowAdv['prefix'] ?? ''));
            $fn = trim((string) ($rowAdv['full_name'] ?? ''));
            $combo = trim($prefix !== '' ? $prefix . ' ' . $fn : $fn);
            if ($combo !== '') {
                $advisorName = $combo;
            }
        }
    }

    $categoryName = 'หมวดหมู่ไม่ระบุ';
    $stmtCat = $pdo->prepare('SELECT name FROM categories WHERE id = :id LIMIT 1');
    $stmtCat->execute([':id' => $categoryId]);
    $rowCat = $stmtCat->fetch(PDO::FETCH_ASSOC);
    if (is_array($rowCat) && trim((string) ($rowCat['name'] ?? '')) !== '') {
        $categoryName = trim((string) $rowCat['name']);
    }

    // ชื่อโฟลเดอร์บน OpenDrive (ปี / อาจารย์ / หมวด / โปรเจกต์) ถูก sanitize + จำกัดความยาวใน OpenDriveHelper::sanitizeFolderName — ไม่กระทบ title_th ใน DB
    $projectName = $titleTh !== '' ? $titleTh : 'Untitled_Project';

    $yearFolderId = $od->getOrCreateFolder($yearName, $rootFolderId);
    $advisorFolderId = $od->getOrCreateFolder($advisorName, $yearFolderId);
    $categoryFolderId = $od->getOrCreateFolder($categoryName, $advisorFolderId);
    $projectFolderId = $od->getOrCreateFolder($projectName, $categoryFolderId);

    error_log('[UPLOAD_PROCESS] OpenDrive nested leaf folder id=' . $projectFolderId);

    $uploadedResults = [];
    foreach ($filesToUpload as $fieldKey => $fileData) {
        $label = (string) ($fileData['label'] ?? '');
        if (isset($fileData['files']) && is_array($fileData['files'])) {
            $uploadedBatch = [];
            $batchPurpose = imagePurposeFromUploadContext((string) $fieldKey, true);
            foreach ($fileData['files'] as $singleFile) {
                if (!is_array($singleFile)) {
                    continue;
                }
                $uploadedBatch[] = uploadToOpenDrive($od, $singleFile, $projectFolderId, $batchPurpose);
            }
            $uploadedResults[$label] = $uploadedBatch;
            continue;
        }
        if (isset($fileData['file']) && is_array($fileData['file'])) {
            $singlePurpose = imagePurposeFromUploadContext((string) $fieldKey, $displayType === 'gallery');
            $result = uploadToOpenDrive($od, $fileData['file'], $projectFolderId, $singlePurpose);
            $uploadedResults[$label] = $result;
        }
    }

    $fileUrls = [
        'display_type' => $displayType,
        'thumbnail' => $uploadedResults['ไฟล์รูปปก (Thumbnail)'] ?? null,
        'main_media' => $uploadedResults['ไฟล์สื่อหลัก (Main Media)'] ?? null,
        'thesis_pdf' => $uploadedResults['ไฟล์ Thesis PDF'] ?? null,
        'project_file' => $project_link !== ''
            ? project_proxy_build_external_project_file_entry($project_link)
            : ($uploadedResults['ไฟล์โปรเจกต์ (ทางเลือก)'] ?? null),
        'drive_folder_id' => $projectFolderId,
    ];
    if ($youtube_link !== '') {
        $fileUrls['youtube'] = $youtube_link;
    }

    $rawSecCatIds = $_POST['secondary_category_ids'] ?? [];
    $secCatIdsArr = is_array($rawSecCatIds) ? array_filter(array_map('intval', $rawSecCatIds)) : [];
    $secondaryCategoryIdsStr = count($secCatIdsArr) > 0 ? implode(',', array_unique($secCatIdsArr)) : null;

    $params = [
        ':title_th' => $titleTh,
        ':title_en' => $titleEn !== '' ? $titleEn : null,
        ':creators' => implode(', ', $creatorNames),
        ':advisor_id' => $advisorId > 0 ? $advisorId : null,
        ':category_id' => $categoryId,
        ':secondary_category_ids' => $secondaryCategoryIdsStr,
        ':academic_year_id' => $academicYearId,
        ':introduction' => $introduction !== '' ? $introduction : null,
        ':file_urls' => json_encode($fileUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':uploader_id' => (int) $_SESSION['user_id'],
        ':drive_folder_id' => $projectFolderId,
    ];

    if (projectsTableHasDriveFolderId($pdo)) {
        $insertSql = "INSERT INTO projects (
            title_th, title_en, creators, advisor_id, category_id, secondary_category_ids, academic_year_id, introduction, file_urls, status, uploader_id, drive_folder_id
        ) VALUES (
            :title_th, :title_en, :creators, :advisor_id, :category_id, :secondary_category_ids, :academic_year_id, :introduction, :file_urls, 'Pending', :uploader_id, :drive_folder_id
        )";
    } else {
        $insertSql = "INSERT INTO projects (
            title_th, title_en, creators, advisor_id, category_id, secondary_category_ids, academic_year_id, introduction, file_urls, status, uploader_id
        ) VALUES (
            :title_th, :title_en, :creators, :advisor_id, :category_id, :secondary_category_ids, :academic_year_id, :introduction, :file_urls, 'Pending', :uploader_id
        )";
        unset($params[':drive_folder_id']);
    }

    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->execute($params);

    $projectId = (int) $pdo->lastInsertId();

    // ==========================================
    // ระบบบันทึกผู้จัดทำ (Authors) พร้อมรูปโปรไฟล์ สำหรับ Upload ใหม่
    // ==========================================
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_members (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id BIGINT UNSIGNED NOT NULL, student_id VARCHAR(50) NULL DEFAULT '', full_name VARCHAR(255) NOT NULL, avatar_filename VARCHAR(255) NULL DEFAULT NULL, KEY idx_project_members_project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("ALTER TABLE project_members ADD COLUMN avatar_filename VARCHAR(255) NULL DEFAULT NULL AFTER full_name");
    } catch (Throwable $e) {}

    $uploadAuthorDir = __DIR__ . '/../uploads/authors/';
    if (!is_dir($uploadAuthorDir)) { mkdir($uploadAuthorDir, 0755, true); }

    $membersData = [];
    foreach ($_POST as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'author_name_')) continue;
        $idxStr = str_replace('author_name_', '', $key);
        $n = trim((string) $value);

        if ($n !== '') {
            $avatarFilename = null;
            $fileKey = 'author_photo_' . $idxStr;

            if (isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey])) {
                $saved = save_author_profile_upload($_FILES[$fileKey], $uploadAuthorDir, 75);
                if ($saved !== null) {
                    $avatarFilename = $saved;
                }
            }
            $membersData[] = ['name' => $n, 'avatar' => $avatarFilename];
        }
    }

    if ($membersData !== []) {
        $creatorsText = implode(', ', array_column($membersData, 'name'));
        $updateCreators = $pdo->prepare('UPDATE projects SET creators = :creators WHERE id = :id');
        $updateCreators->execute([':creators' => $creatorsText, ':id' => $projectId]);
    }

    $pdo->prepare('DELETE FROM project_members WHERE project_id = :id')->execute([':id' => $projectId]);
    if ($membersData !== []) {
        $insertMember = $pdo->prepare('INSERT INTO project_members (project_id, student_id, full_name, avatar_filename) VALUES (:project_id, :student_id, :full_name, :avatar_filename)');
        foreach ($membersData as $member) {
            $insertMember->execute([
                ':project_id' => $projectId,
                ':student_id' => '',
                ':full_name' => $member['name'],
                ':avatar_filename' => $member['avatar'],
            ]);
        }
    }
    // ==========================================

    $_SESSION['uploaded_files'] = $uploadedResults;
    $_SESSION['upload_success'] = 'อัปโหลดไฟล์และบันทึกผลงานเรียบร้อยแล้ว (สถานะ Pending)';
    $_SESSION['flash_success'] = 'เพิ่มโครงการเรียบร้อยแล้ว';
    redirectToManageProjectsSuccess();
} catch (Throwable $e) {
    error_log('[UPLOAD_PROCESS] OpenDrive upload failed: ' . $e->getMessage());
    respondUploadErrors([
        'ไม่สามารถอัปโหลดไป OpenDrive ได้ในขณะนี้',
        'รายละเอียด: ' . $e->getMessage(),
    ]);
}
