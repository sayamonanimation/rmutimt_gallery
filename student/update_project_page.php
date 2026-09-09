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
require_once __DIR__ . '/../config/project_file_proxy.php';
require_once __DIR__ . '/../admin/image_compress.php';

function backWithMessage(string $type, string $message): void
{
    if ($type === 'error' && od_request_is_xhr()) {
        od_respond_ajax_error($message);
    }
    $_SESSION[$type === 'error' ? 'flash_error' : 'flash_success'] = $message;
    header('Location: ./my_projects.php');
    exit;
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name LIMIT 1");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column
        ]);
        return $stmt->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
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

/**
 * @param array<string,mixed> $file
 * @return array{id:string,link:string,webContentLink:string,name:string,mimeType:string}
 */
function uploadFileToOpenDrive(OpenDriveHelper $od, array $file, string $folderId, string $imagePurpose = ''): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($err)) {
        throw new RuntimeException('OpenDrive: โครงสร้างอัปโหลดหลายไฟล์ในฟิลด์เดียวไม่รองรับในฟังก์ชันนี้');
    }
    if ((int) $err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('OpenDrive: อนุญาตเฉพาะไฟล์ที่อัปโหลดสำเร็จ (UPLOAD_ERR_OK) เท่านั้น');
    }

    $path = (string) ($file['tmp_name'] ?? '');
    if ($path === '' || !is_readable($path)) {
        throw new RuntimeException('อ่านไฟล์ใหม่ไม่สำเร็จ');
    }

    $prepared = prepareImageUploadForOpenDrive($file, $imagePurpose);
    try {
        return $od->uploadFile(
            (string) $prepared['path'],
            (string) $prepared['name'],
            $folderId
        );
    } finally {
        cleanupOptimizedUploadTemp($prepared);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    backWithMessage('error', 'Invalid request');
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    backWithMessage('error', 'Token ไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$titleTh = trim((string) ($_POST['title_th'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));
$advisorId = (int) ($_POST['advisor_id'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
$introduction = trim((string) ($_POST['introduction'] ?? ''));
$youtube_link = trim((string) ($_POST['youtube_link'] ?? ''));
$project_link_raw = trim((string) ($_POST['project_link'] ?? ''));

if ($projectId <= 0 || $titleTh === '' || $categoryId <= 0 || $academicYearId <= 0) {
    backWithMessage('error', 'ข้อมูลที่ต้องกรอกยังไม่ครบ');
}

try {
    $userId = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare(
        'SELECT file_urls, title_th, title_en'
        . (tableHasColumn($pdo, 'projects', 'drive_folder_id') ? ', drive_folder_id' : '')
        . ' FROM projects WHERE id = :id AND uploader_id = :uploader_id LIMIT 1'
    );
    $stmt->execute([
        ':id' => $projectId,
        ':uploader_id' => $userId,
    ]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        backWithMessage('error', 'ไม่พบโปรเจกต์ที่ต้องการแก้ไข');
    }

    $fileUrls = json_decode((string) ($existing['file_urls'] ?? ''), true);
    $fileUrls = is_array($fileUrls) ? $fileUrls : [];
    $prevTitleTh = trim((string) ($existing['title_th'] ?? ''));

    $od = new OpenDriveHelper();
    $od->login();
    $existingFolderId = trim((string) ($existing['drive_folder_id'] ?? ($fileUrls['drive_folder_id'] ?? '')));
    if ($existingFolderId !== '') {
        $driveFolderId = $existingFolderId;
    } else {
        $driveFolderId = isset($od_folder_id) ? trim((string) $od_folder_id) : '';
        if ($driveFolderId === '') {
            $driveFolderId = '0';
        }
    }

    $map = [
        'thumbnail' => ['thumbnail_file_new', 'thumbnail_file'],
        'main_media' => ['main_media_file_new', 'main_media_file'],
        'thesis_pdf' => ['thesis_pdf_file_new', 'thesis_pdf_file'],
        'project_file' => ['project_file_new', 'project_file'],
    ];
    $oldFileIdsToDelete = [];

    $displayTypePost = (string) ($_POST['display_type'] ?? '');
    $displayType = in_array($displayTypePost, ['video', 'gallery'], true)
        ? $displayTypePost
        : (string) ($fileUrls['display_type'] ?? 'video');
    if (!in_array($displayType, ['video', 'gallery'], true)) {
        $displayType = 'video';
    }
    $fileUrls['display_type'] = $displayType;
    if ($youtube_link !== '') {
        $fileUrls['youtube'] = $youtube_link;
    } else {
        unset($fileUrls['youtube']);
    }

    $hasNewProjectUpload = od_pick_first_ok_upload(['project_file_new', 'project_file']) !== null;
    $projectLinkNormalized = project_proxy_normalize_external_url($project_link_raw);
    $existingProjectEntry = is_array($fileUrls['project_file'] ?? null) ? $fileUrls['project_file'] : null;
    $existingWasExternal = is_array($existingProjectEntry)
        && project_proxy_is_external_project_file($existingProjectEntry);
    $removeProjectFile = isset($_POST['remove_project_file']) && (string) $_POST['remove_project_file'] === '1';
    $projectFilesExists = tableExists($pdo, 'project_files');

    $clearProjectFileEntry = static function () use (&$fileUrls, &$oldFileIdsToDelete, $pdo, $projectId, $projectFilesExists): void {
        foreach (od_collect_drive_ids_from_file_urls_entry($fileUrls['project_file'] ?? null) as $oid) {
            $oldFileIdsToDelete[] = $oid;
        }
        unset($fileUrls['project_file']);
        if ($projectFilesExists) {
            try {
                $del = $pdo->prepare('DELETE FROM project_files WHERE project_id = :pid AND file_type = :ftype');
                $del->execute([':pid' => $projectId, ':ftype' => 'project_file']);
            } catch (Throwable $e) {
                error_log('[STUDENT_UPDATE_PAGE] project_files delete skipped: ' . $e->getMessage());
            }
        }
    };

    if ($removeProjectFile) {
        $clearProjectFileEntry();
    }

    if (trim($project_link_raw) !== '' && $projectLinkNormalized === '') {
        throw new RuntimeException('ลิงก์โปรเจกต์ภายนอกไม่ถูกต้อง');
    }
    if ($projectLinkNormalized !== '' && $hasNewProjectUpload) {
        throw new RuntimeException('กรุณาเลือกอย่างใดอย่างหนึ่ง: อัปโหลดไฟล์โปรเจกต์ หรือใส่ลิงก์ภายนอก');
    }
    if ($projectLinkNormalized !== '') {
        foreach (od_collect_drive_ids_from_file_urls_entry($fileUrls['project_file'] ?? null) as $oid) {
            $oldFileIdsToDelete[] = $oid;
        }
        $fileUrls['project_file'] = project_proxy_build_external_project_file_entry($projectLinkNormalized);
    } elseif (
        $existingWasExternal
        && $project_link_raw === ''
        && !$hasNewProjectUpload
        && !$removeProjectFile
    ) {
        $clearProjectFileEntry();
    }

    foreach ($map as $key => $candidateNames) {
        if ($key === 'project_file' && $projectLinkNormalized !== '') {
            continue;
        }

        $inputName = $candidateNames[0];
        $old = isset($fileUrls[$key]) && is_array($fileUrls[$key]) ? $fileUrls[$key] : null;

        if ($key === 'main_media') {
            $normalized = od_normalize_uploaded_files_field($inputName);
            $validMain = array_values(array_filter(
                $normalized,
                static fn(array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ));
            if ($validMain === []) {
                continue;
            }

            foreach (od_collect_drive_ids_from_file_urls_entry($fileUrls['main_media'] ?? null) as $oid) {
                $oldFileIdsToDelete[] = $oid;
            }

            if ($displayType === 'video') {
                if (count($validMain) !== 1) {
                    throw new RuntimeException('โหมดวิดีโอต้องอัปโหลดไฟล์สื่อหลัก 1 ไฟล์เท่านั้น');
                }
                $vf = $validMain[0];
                $ext = strtolower(pathinfo((string) ($vf['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($ext, ['mp4', 'mov', 'jpg', 'jpeg', 'png'], true)) {
                    throw new RuntimeException('สื่อหลัก (วิดีโอ): ประเภทไฟล์ไม่ถูกต้อง');
                }
                $fileUrls['main_media'] = uploadFileToOpenDrive($od, $vf, $driveFolderId, IMAGE_PURPOSE_DISPLAY);
            } else {
                if (count($validMain) > 10) {
                    throw new RuntimeException('โหมดแกลลอรีอัปโหลดได้สูงสุด 10 รูป');
                }
                $batch = [];
                foreach ($validMain as $vf) {
                    $ext = strtolower(pathinfo((string) ($vf['name'] ?? ''), PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                        throw new RuntimeException('แกลลอรี: ใช้ได้เฉพาะ JPG / PNG / WebP');
                    }
                    $batch[] = uploadFileToOpenDrive($od, $vf, $driveFolderId, IMAGE_PURPOSE_GALLERY);
                }
                $fileUrls['main_media'] = $batch;
            }
            continue;
        }

        $file = od_pick_first_ok_upload($candidateNames);
        if ($file === null) {
            if ($key === 'project_file' && isset($_FILES['project_file_new']) && is_array($_FILES['project_file_new'])) {
                $pfErr = (int) ($_FILES['project_file_new']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($pfErr !== UPLOAD_ERR_NO_FILE && $pfErr !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('อัปโหลดไฟล์โปรเจกต์ไม่สำเร็จ (รหัสข้อผิดพลาด: ' . $pfErr . ')');
                }
            }
            continue;
        }

        if ($key === 'project_file') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['zip', 'rar', '7z', 'mp4', 'mov', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new RuntimeException('ไฟล์โปรเจกต์: ประเภทไฟล์ไม่ถูกต้อง');
            }
        }
        if ($key === 'thumbnail') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                throw new RuntimeException('รูปปก: ประเภทไฟล์ไม่ถูกต้อง');
            }
        }
        if ($key === 'thesis_pdf') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                throw new RuntimeException('ไฟล์ Thesis ต้องเป็น PDF');
            }
        }

        foreach (od_collect_drive_ids_from_file_urls_entry($fileUrls[$key] ?? null) as $oid) {
            $oldFileIdsToDelete[] = $oid;
        }

        $fileUrls[$key] = uploadFileToOpenDrive($od, $file, $driveFolderId, imagePurposeFromUploadContext($key));
    }

    $fileUrls['drive_folder_id'] = $driveFolderId;

    try {
        // สร้างตารางถ้ายังไม่มี
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_members (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            student_id VARCHAR(50) NULL DEFAULT '',
            full_name VARCHAR(255) NOT NULL,
            avatar_filename VARCHAR(255) NULL DEFAULT NULL,
            KEY idx_project_members_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // เพิ่มคอลัมน์เผื่อกรณีที่มีตารางอยู่แล้วแต่ไม่มีคอลัมน์รูป
        $pdo->exec("ALTER TABLE project_members ADD COLUMN avatar_filename VARCHAR(255) NULL DEFAULT NULL AFTER full_name");
    } catch (Throwable $e) {}

    $uploadAuthorDir = __DIR__ . '/../uploads/authors/';
    if (!is_dir($uploadAuthorDir)) { mkdir($uploadAuthorDir, 0755, true); }

    $memberNames = $_POST['member_name'] ?? [];
    $memberPhotos = $_FILES['member_photo_new'] ?? null;
    $membersData = [];

    // ดึงรูปเก่ามาพักไว้ก่อน เผื่อไม่ได้อัปโหลดรูปใหม่
    $oldMembersStmt = $pdo->prepare('SELECT full_name, avatar_filename FROM project_members WHERE project_id = :id ORDER BY id ASC');
    $oldMembersStmt->execute([':id' => $projectId]);
    $oldMembers = $oldMembersStmt->fetchAll(PDO::FETCH_ASSOC);

    $memberIndex = 0;
    if (is_array($memberNames)) {
        foreach ($memberNames as $idx => $name) {
            $n = trim((string) $name);
            if ($n === '') {
                continue;
            }

            $prevRaw = $oldMembers[$memberIndex]['avatar_filename'] ?? null;
            $avatarFilename = (is_string($prevRaw) && trim($prevRaw) !== '') ? trim($prevRaw) : null;

            $uploadErr = UPLOAD_ERR_NO_FILE;
            if (is_array($memberPhotos) && isset($memberPhotos['error']) && is_array($memberPhotos['error']) && array_key_exists($idx, $memberPhotos['error'])) {
                $uploadErr = (int) $memberPhotos['error'][$idx];
            }

            if ($uploadErr === UPLOAD_ERR_OK && is_array($memberPhotos)) {
                $single = [
                    'name' => (string) ($memberPhotos['name'][$idx] ?? ''),
                    'type' => (string) ($memberPhotos['type'][$idx] ?? ''),
                    'tmp_name' => (string) ($memberPhotos['tmp_name'][$idx] ?? ''),
                    'error' => $uploadErr,
                    'size' => (int) ($memberPhotos['size'][$idx] ?? 0),
                ];
                $saved = save_author_profile_upload($single, $uploadAuthorDir, 70);
                if ($saved !== null) {
                    $avatarFilename = $saved;
                }
            }

            $membersData[] = ['name' => $n, 'avatar' => $avatarFilename];
            $memberIndex++;
        }
    }
    $creators = implode(', ', array_column($membersData, 'name'));

    $rawSecCatIds = $_POST['secondary_category_ids'] ?? [];
    $secCatIdsArr = is_array($rawSecCatIds) ? array_filter(array_map('intval', $rawSecCatIds)) : [];
    $secondaryCategoryIdsStr = count($secCatIdsArr) > 0 ? implode(',', array_unique($secCatIdsArr)) : null;

    $pdo->beginTransaction();

    $sql = tableHasColumn($pdo, 'projects', 'drive_folder_id')
        ? "UPDATE projects SET title_th=:title_th, title_en=:title_en, creators=:creators, advisor_id=:advisor_id, category_id=:category_id, secondary_category_ids=:secondary_category_ids, academic_year_id=:academic_year_id, introduction=:introduction, file_urls=:file_urls, drive_folder_id=:drive_folder_id WHERE id=:id AND uploader_id=:uploader_id"
        : "UPDATE projects SET title_th=:title_th, title_en=:title_en, creators=:creators, advisor_id=:advisor_id, category_id=:category_id, secondary_category_ids=:secondary_category_ids, academic_year_id=:academic_year_id, introduction=:introduction, file_urls=:file_urls WHERE id=:id AND uploader_id=:uploader_id";
    $params = [
        ':id' => $projectId,
        ':uploader_id' => $userId,
        ':title_th' => $titleTh,
        ':title_en' => $titleEn !== '' ? $titleEn : null,
        ':creators' => $creators,
        ':advisor_id' => $advisorId > 0 ? $advisorId : null,
        ':category_id' => $categoryId,
        ':secondary_category_ids' => $secondaryCategoryIdsStr,
        ':academic_year_id' => $academicYearId,
        ':introduction' => $introduction !== '' ? $introduction : null,
        ':file_urls' => json_encode($fileUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    if (str_contains($sql, 'drive_folder_id')) {
        $params[':drive_folder_id'] = $driveFolderId;
    }
    $updateStmt = $pdo->prepare($sql);
    $updateStmt->execute($params);

    $newAvatars = array_filter(array_column($membersData, 'avatar'));
    foreach ($oldMembers as $oldMem) {
        $oldAvatar = $oldMem['avatar_filename'] ?? null;
        if ($oldAvatar && !in_array($oldAvatar, $newAvatars, true)) {
            $filePath = __DIR__ . '/../uploads/authors/' . basename((string) $oldAvatar);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    $pdo->prepare('DELETE FROM project_members WHERE project_id = :id')->execute([':id' => $projectId]);
    if ($membersData !== []) {
        $insertMember = $pdo->prepare('INSERT INTO project_members (project_id, student_id, full_name, avatar_filename) VALUES (:project_id, :student_id, :full_name, :avatar_filename)');
        foreach ($membersData as $member) {
            $av = $member['avatar'] ?? null;
            $avNorm = (is_string($av) && trim($av) !== '') ? trim($av) : null;
            $insertMember->execute([
                ':project_id' => $projectId,
                ':student_id' => '',
                ':full_name' => $member['name'],
                ':avatar_filename' => $avNorm,
            ]);
        }
    }

    $pdo->commit();

    $oldFileIdsToDelete = array_values(array_unique($oldFileIdsToDelete));
    foreach ($oldFileIdsToDelete as $oldFileId) {
        try {
            $od->deleteFile((string) $oldFileId);
        } catch (Throwable $deleteError) {
            error_log('[UPDATE_PROJECT_PAGE] delayed delete failed for file ' . $oldFileId . ': ' . $deleteError->getMessage());
        }
    }

    backWithMessage('success', 'อัปเดตข้อมูลโปรเจกต์เรียบร้อยแล้ว');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[UPDATE_PROJECT_PAGE] failed: ' . $e->getMessage());
    backWithMessage('error', 'อัปเดตข้อมูลไม่สำเร็จ: ' . $e->getMessage());
}


