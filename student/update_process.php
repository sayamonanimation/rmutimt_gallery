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
require_once __DIR__ . '/../config/video_optimizer.php';
require_once __DIR__ . '/../admin/image_compress.php';

function backToEdit(int $projectId, string $type, string $message): void
{
    if ($type === 'error' && od_request_is_xhr()) {
        od_respond_ajax_error($message);
    }
    $_SESSION[$type === 'error' ? 'flash_error' : 'flash_success'] = $message;
    $status = $type === 'error' ? 'error' : 'success';
    header('Location: ./my_projects.php?status=' . $status);
    exit;
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return $stmt->fetchColumn() !== false;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':table_name' => $tableName]);
    return $stmt->fetchColumn() !== false;
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
 * Normalize uploaded files for single/multiple file input names (same as upload_process.php).
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

/**
 * @return array<int,string> Drive file IDs from saved main_media or single-file entry
 */
function collectDriveIdsFromFileUrlsEntry(mixed $entry): array
{
    if (!is_array($entry)) {
        return [];
    }
    $ids = [];
    $list = isset($entry[0]) && is_array($entry[0]) ? $entry : [$entry];
    foreach ($list as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if (od_is_deletable_storage_file_id($id)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * Pick first successfully uploaded file from candidate input names (single-file inputs).
 *
 * @param array<int,string> $candidateNames
 * @return array<string,mixed>|null
 */
function pickFirstOkUploadFromCandidates(array $candidateNames): ?array
{
    foreach ($candidateNames as $name) {
        if (!isset($_FILES[$name]) || !is_array($_FILES[$name])) {
            continue;
        }
        $f = $_FILES[$name];
        if (isset($f['error']) && is_array($f['error'])) {
            foreach (normalizeUploadedFilesByField($name) as $part) {
                if ((int) ($part['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    return $part;
                }
            }
            continue;
        }
        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return $f;
        }
    }

    return null;
}

function upsertProjectFileRow(PDO $pdo, int $projectId, string $fileType, array $newFileData): void
{
    $fileId = trim((string) ($newFileData['id'] ?? ''));
    if ($fileId === '') {
        return;
    }
    $fileName = (string) ($newFileData['name'] ?? '');
    $webView = (string) ($newFileData['link'] ?? '');

    try {
        $find = $pdo->prepare('SELECT id FROM project_files WHERE project_id = :pid AND file_type = :ftype LIMIT 1');
        $find->execute([':pid' => $projectId, ':ftype' => $fileType]);
        $exists = $find->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            $upd = $pdo->prepare(
                'UPDATE project_files SET file_id = :fid, file_name = :fname, web_view_link = :wvl WHERE project_id = :pid AND file_type = :ftype'
            );
            $upd->execute([
                ':fid' => $fileId,
                ':fname' => $fileName,
                ':wvl' => $webView,
                ':pid' => $projectId,
                ':ftype' => $fileType,
            ]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO project_files (project_id, file_type, file_id, file_name, web_view_link) VALUES (:pid, :ftype, :fid, :fname, :wvl)'
            );
            $ins->execute([
                ':pid' => $projectId,
                ':ftype' => $fileType,
                ':fid' => $fileId,
                ':fname' => $fileName,
                ':wvl' => $webView,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[UPDATE_PROCESS] project_files upsert skipped: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./my_projects.php');
    exit;
}
if (empty($_POST) && isset($_SERVER['CONTENT_LENGTH'])) {
    $pid = (int) ($_GET['id'] ?? 0);
    backToEdit($pid, 'error', 'ขนาดไฟล์ที่อัปโหลดใหญ่เกินขีดจำกัดของเซิร์ฟเวอร์ กรุณาลดขนาดไฟล์แล้วลองใหม่อีกครั้ง');
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $pid = (int) ($_POST['project_id'] ?? 0);
    backToEdit($pid > 0 ? $pid : 0, 'error', 'Token ไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$titleTh = trim((string) ($_POST['title_th'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));
$advisorId = (int) ($_POST['advisor_id'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
$introduction = trim((string) ($_POST['introduction'] ?? ''));
$displayType = (string) ($_POST['display_type'] ?? 'video');
$youtube_link = trim((string) ($_POST['youtube_link'] ?? ''));

if ($projectId <= 0 || $titleTh === '' || $categoryId <= 0 || $academicYearId <= 0) {
    backToEdit($projectId, 'error', 'ข้อมูลที่ต้องกรอกยังไม่ครบ');
}
if (!in_array($displayType, ['video', 'gallery'], true)) {
    $displayType = 'video';
}

try {
    $stmt = $pdo->prepare(
        'SELECT file_urls, title_th, title_en, uploader_id'
        . (tableHasColumn($pdo, 'projects', 'drive_folder_id') ? ', drive_folder_id' : '')
        . ' FROM projects WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $projectId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        backToEdit($projectId, 'error', 'ไม่พบโปรเจกต์ที่ต้องการแก้ไข');
    }
    if ((int) ($existing['uploader_id'] ?? 0) !== (int) $_SESSION['user_id']) {
        backToEdit($projectId, 'error', 'คุณไม่มีสิทธิ์แก้ไขโครงงานนี้');
    }

    $fileUrls = json_decode((string) ($existing['file_urls'] ?? ''), true);
    $fileUrls = is_array($fileUrls) ? $fileUrls : [];
    $prevDisplayType = (string) ($fileUrls['display_type'] ?? 'video');
    $prevTitleTh = trim((string) ($existing['title_th'] ?? ''));

    // Release session lock so user can browse other tabs/pages during heavy upload/update
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

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

    $fileUrls['display_type'] = $displayType;
    if ($youtube_link !== '') {
        $fileUrls['youtube'] = $youtube_link;
    } else {
        unset($fileUrls['youtube']);
    }
    $fileUrls['drive_folder_id'] = $driveFolderId;

    // --- เริ่มระบบจัดการรูปผู้จัดทำ (ครอบจักรวาล) ---
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_members (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, project_id BIGINT NOT NULL, student_id VARCHAR(50) DEFAULT '', full_name VARCHAR(255) NOT NULL, avatar_filename VARCHAR(255))");
        $pdo->exec("ALTER TABLE project_members ADD COLUMN IF NOT EXISTS avatar_filename VARCHAR(255)");
    } catch (Throwable $e) {}

    $uploadAuthorDir = __DIR__ . '/../uploads/authors/';
    if (!is_dir($uploadAuthorDir)) { mkdir($uploadAuthorDir, 0755, true); }

    $oldMembers = [];
    try {
        $oldMembersStmt = $pdo->prepare('SELECT full_name, avatar_filename FROM project_members WHERE project_id = :id ORDER BY id ASC');
        $oldMembersStmt->execute([':id' => $projectId]);
        $oldMembers = $oldMembersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $membersData = [];
    $memberIndex = 0;

    // รองรับโครงสร้างฟอร์มแบบ name="member_name[]"
    if (isset($_POST['member_name']) && is_array($_POST['member_name'])) {
        $memberPhotos = $_FILES['member_photo_new'] ?? null;
        foreach ($_POST['member_name'] as $idx => $name) {
            $n = trim((string) $name);
            if ($n !== '') {
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
    } 
    // รองรับโครงสร้างฟอร์มแบบ name="author_name_1"
    else {
        foreach ($_POST as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'author_name_')) continue;
            $idxStr = str_replace('author_name_', '', $key);
            $n = trim((string) $value);
            if ($n !== '') {
                $prevRaw = $oldMembers[$memberIndex]['avatar_filename'] ?? null;
                $avatarFilename = (is_string($prevRaw) && trim($prevRaw) !== '') ? trim($prevRaw) : null;
                $fileKey = 'author_photo_' . $idxStr;

                if (isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey]) && (int) ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $saved = save_author_profile_upload($_FILES[$fileKey], $uploadAuthorDir, 70);
                    if ($saved !== null) {
                        $avatarFilename = $saved;
                    }
                }
                $membersData[] = ['name' => $n, 'avatar' => $avatarFilename];
                $memberIndex++;
            }
        }
    }

    $creators = implode(', ', array_column($membersData, 'name'));

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

    // ดำเนินการลบของเก่า และ Insert ของใหม่เข้า DB
    $pdo->beginTransaction();
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
    // --- จบระบบจัดการรูปผู้จัดทำ ---

    // อัปโหลดไฟล์ผลงานใหม่ขึ้น OpenDrive + อัปเดต $fileUrls (+ sync project_files ถ้ามีตาราง)
    $projectFilesExists = tableExists($pdo, 'project_files');
    $project_link_raw = trim((string) ($_POST['project_link'] ?? ''));
    $hasNewProjectUpload = od_pick_first_ok_upload(['project_file_new', 'project_file']) !== null;
    $projectLinkNormalized = project_proxy_normalize_external_url($project_link_raw);
    $existingProjectEntry = is_array($fileUrls['project_file'] ?? null) ? $fileUrls['project_file'] : null;
    $existingWasExternal = is_array($existingProjectEntry)
        && project_proxy_is_external_project_file($existingProjectEntry);
    $removeProjectFile = isset($_POST['remove_project_file']) && (string) $_POST['remove_project_file'] === '1';

    if (trim($project_link_raw) !== '' && $projectLinkNormalized === '') {
        throw new RuntimeException('ลิงก์โปรเจกต์ภายนอกไม่ถูกต้อง');
    }
    if ($projectLinkNormalized !== '' && $hasNewProjectUpload) {
        throw new RuntimeException('กรุณาเลือกอย่างใดอย่างหนึ่ง: อัปโหลดไฟล์โปรเจกต์ หรือใส่ลิงก์ภายนอก');
    }

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
                error_log('[UPDATE_PROCESS] project_files delete skipped: ' . $e->getMessage());
            }
        }
    };

    if ($removeProjectFile) {
        $clearProjectFileEntry();
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

    foreach ($map as $fileType => $candidateNames) {
        if ($fileType === 'project_file' && $projectLinkNormalized !== '') {
            continue;
        }

        if ($fileType === 'main_media') {
            $normalized = normalizeUploadedFilesByField('main_media_file_new');
            $validMain = array_values(array_filter(
                $normalized,
                static fn(array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ));

            $displayTypeChanged = ($displayType !== $prevDisplayType);
            if ($validMain === [] && !$displayTypeChanged) {
                continue;
            }

            // Always collect previous main media file IDs so they are deleted from OpenDrive
            $prevMain = $fileUrls['main_media'] ?? null;
            foreach (od_collect_drive_ids_from_file_urls_entry($prevMain) as $oid) {
                $oldFileIdsToDelete[] = $oid;
            }

            if ($validMain === [] && $displayTypeChanged) {
                // Changed display type without uploading new files -> clear previous main media
                unset($fileUrls['main_media']);
                if ($projectFilesExists) {
                    try {
                        $del = $pdo->prepare('DELETE FROM project_files WHERE project_id = :pid AND file_type = :ftype');
                        $del->execute([':pid' => $projectId, ':ftype' => 'main_media']);
                    } catch (Throwable $e) {
                        error_log('[UPDATE_PROCESS] project_files main_media clear skipped: ' . $e->getMessage());
                    }
                }
                continue;
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
                $newData = uploadFileToOpenDrive($od, $vf, $driveFolderId, IMAGE_PURPOSE_DISPLAY);
                $fileUrls['main_media'] = $newData;
                if ($projectFilesExists) {
                    upsertProjectFileRow($pdo, $projectId, 'main_media', $newData);
                }
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
                if ($projectFilesExists && $batch !== []) {
                    upsertProjectFileRow($pdo, $projectId, 'main_media', $batch[0]);
                }
            }

            continue;
        }

        $file = od_pick_first_ok_upload($candidateNames);
        if ($file === null) {
            if ($fileType === 'project_file' && isset($_FILES['project_file_new']) && is_array($_FILES['project_file_new'])) {
                $pfErr = (int) ($_FILES['project_file_new']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($pfErr !== UPLOAD_ERR_NO_FILE && $pfErr !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('อัปโหลดไฟล์โปรเจกต์ไม่สำเร็จ (รหัสข้อผิดพลาด: ' . $pfErr . ')');
                }
            }
            continue;
        }

        if ($fileType === 'project_file') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['zip', 'rar', '7z', 'mp4', 'mov', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new RuntimeException('ไฟล์โปรเจกต์: ประเภทไฟล์ไม่ถูกต้อง');
            }
        }
        if ($fileType === 'thumbnail') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                throw new RuntimeException('รูปปก: ประเภทไฟล์ไม่ถูกต้อง');
            }
        }
        if ($fileType === 'thesis_pdf') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                throw new RuntimeException('ไฟล์ Thesis ต้องเป็น PDF');
            }
        }

        $prevEntry = $fileUrls[$fileType] ?? null;
        foreach (od_collect_drive_ids_from_file_urls_entry($prevEntry) as $oid) {
            $oldFileIdsToDelete[] = $oid;
        }

        $newFileData = uploadFileToOpenDrive($od, $file, $driveFolderId, imagePurposeFromUploadContext($fileType));
        $fileUrls[$fileType] = $newFileData;

        if ($projectFilesExists) {
            upsertProjectFileRow($pdo, $projectId, $fileType, $newFileData);
        }
    }

    $rawSecCatIds = $_POST['secondary_category_ids'] ?? [];
    $secCatIdsArr = is_array($rawSecCatIds) ? array_filter(array_map('intval', $rawSecCatIds)) : [];
    $secondaryCategoryIdsStr = count($secCatIdsArr) > 0 ? implode(',', array_unique($secCatIdsArr)) : null;

    // Update project row after file uploads so JSON stores latest file IDs/links.
    $sql = tableHasColumn($pdo, 'projects', 'drive_folder_id')
        ? "UPDATE projects SET title_th=:title_th, title_en=:title_en, creators=:creators, advisor_id=:advisor_id, category_id=:category_id, secondary_category_ids=:secondary_category_ids, academic_year_id=:academic_year_id, introduction=:introduction, file_urls=:file_urls, drive_folder_id=:drive_folder_id WHERE id=:id"
        : "UPDATE projects SET title_th=:title_th, title_en=:title_en, creators=:creators, advisor_id=:advisor_id, category_id=:category_id, secondary_category_ids=:secondary_category_ids, academic_year_id=:academic_year_id, introduction=:introduction, file_urls=:file_urls WHERE id=:id";

    $params = [
        ':id' => $projectId,
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

    $pdo->commit();

    // Delete old OpenDrive files only after DB commit succeeds.
    $oldFileIdsToDelete = array_values(array_unique(array_filter($oldFileIdsToDelete)));
    foreach ($oldFileIdsToDelete as $oldFileId) {
        try {
            $od->deleteFile((string) $oldFileId);
        } catch (Throwable $deleteError) {
            error_log('[UPDATE_PROCESS] delayed delete failed for file ' . $oldFileId . ': ' . $deleteError->getMessage());
        }
    }

    backToEdit($projectId, 'success', 'อัปเดตข้อมูลโปรเจกต์เรียบร้อยแล้ว');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[UPDATE_PROCESS] failed: ' . $e->getMessage());
    backToEdit($projectId, 'error', 'อัปเดตข้อมูลไม่สำเร็จ: ' . $e->getMessage());
}
