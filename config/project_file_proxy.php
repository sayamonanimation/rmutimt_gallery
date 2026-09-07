<?php
declare(strict_types=1);

/**
 * Shared helpers for download_project.php, serve_pdf.php, and view_pdf.php (stream from OpenDrive via PHP headers).
 */

/**
 * @return array<string,mixed>|null
 */
function project_proxy_load_authorized(PDO $pdo, int $projectId): ?array
{
    if ($projectId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, file_urls, status, uploader_id FROM projects WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($project)) {
        return null;
    }

    $role = (string) ($_SESSION['role'] ?? '');
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $isApproved = (string) ($project['status'] ?? '') === 'approved';
    $isAdmin = $role === 'admin';
    $isOwnerStudent = $role === 'student'
        && $sessionUserId > 0
        && $sessionUserId === (int) ($project['uploader_id'] ?? 0);

    if (!$isApproved && !$isAdmin && !$isOwnerStudent) {
        return null;
    }

    return $project;
}

/**
 * @return array<string,mixed>
 */
function project_proxy_parse_file_urls(string $json): array
{
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $entry
 */
function project_proxy_is_external_project_file(array $entry): bool
{
    $source = strtolower(trim((string) ($entry['source'] ?? '')));
    if ($source === 'external' || $source === 'external_link') {
        return true;
    }

    $type = strtolower(trim((string) ($entry['type'] ?? '')));

    return $type === 'external_link';
}

function project_proxy_normalize_external_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/**
 * @param array<string,mixed> $entry
 */
function project_proxy_external_url(array $entry): string
{
    foreach (['external_url', 'link', 'webContentLink'] as $key) {
        $url = trim((string) ($entry[$key] ?? ''));
        if ($url === '' || strtolower($url) === 'n/a') {
            continue;
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
    }

    return '';
}

/**
 * @return array<string,string>
 */
function project_proxy_build_external_project_file_entry(string $url): array
{
    $url = project_proxy_normalize_external_url($url);
    if ($url === '') {
        throw new InvalidArgumentException('Invalid external project link URL');
    }

    $host = parse_url($url, PHP_URL_HOST);
    $name = is_string($host) && $host !== '' ? $host : 'External Project Link';

    return [
        'source' => 'external',
        'external_url' => $url,
        'link' => $url,
        'webContentLink' => $url,
        'name' => $name,
    ];
}

/**
 * @param array<string,mixed> $entry
 */
function project_proxy_entry_has_file(array $entry): bool
{
    if (project_proxy_is_external_project_file($entry)) {
        return project_proxy_external_url($entry) !== '';
    }

    $id = trim((string) ($entry['id'] ?? ''));
    if ($id !== '' && strtolower($id) !== 'n/a') {
        return true;
    }

    foreach (['webContentLink', 'link', 'name'] as $key) {
        $value = trim((string) ($entry[$key] ?? ''));
        if ($value !== '' && strtolower($value) !== 'n/a') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $entry
 */
function project_proxy_extract_file_id(array $entry): string
{
    $id = trim((string) ($entry['id'] ?? ''));
    if ($id !== '' && strtolower($id) !== 'n/a') {
        return $id;
    }

    foreach (['webContentLink', 'link'] as $key) {
        $url = trim((string) ($entry[$key] ?? ''));
        if ($url === '' || strtolower($url) === 'n/a') {
            continue;
        }
        if (preg_match('#/([a-zA-Z0-9_-]{15,})(?:\?|/|$)#', $url, $matches) === 1) {
            return (string) $matches[1];
        }
    }

    return '';
}

function project_proxy_is_thumb_url(string $url): bool
{
    return stripos($url, 'thumb') !== false;
}

/**
 * Resolve a URL that returns raw file bytes (not thumb.json or HTML preview page).
 *
 * @param array<string,mixed> $entry
 */
function project_proxy_resolve_stream_url(array $entry): string
{
    if (project_proxy_is_external_project_file($entry)) {
        return '';
    }

    $fileId = project_proxy_extract_file_id($entry);
    if ($fileId !== '') {
        return 'https://od.lk/d/' . rawurlencode($fileId) . '/';
    }

    foreach (['webContentLink', 'link'] as $key) {
        $url = trim((string) ($entry[$key] ?? ''));
        if ($url === '' || strtolower($url) === 'n/a' || project_proxy_is_thumb_url($url)) {
            continue;
        }
        if (stripos($url, '/f/') !== false) {
            return str_replace('/f/', '/d/', $url);
        }

        return $url;
    }

    return '';
}

/**
 * @param array<string,mixed> $entry
 */
function project_proxy_resolve_filename(array $entry, string $defaultStem): string
{
    $name = trim((string) ($entry['name'] ?? ''));
    if ($name === '') {
        $name = $defaultStem;
    }

    $name = basename(str_replace(["\0", '/', '\\'], '_', $name));
    if ($name === '' || $name === '.' || $name === '_') {
        $name = $defaultStem . '.bin';
    }

    return $name;
}

function project_proxy_content_disposition_filename(string $filename): string
{
    return str_replace(['"', "\r", "\n"], '', $filename);
}

function project_proxy_remote_is_reachable(string $url): bool
{
    if ($url === '') {
        return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 400;
}

function project_proxy_stream_remote(
    string $url,
    string $fallbackFilename = 'document.pdf',
    string $dispositionType = 'inline'
): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }

    $hasSentStatus = false;
    $hasSentDisposition = false;
    $disposition = in_array(strtolower($dispositionType), ['attachment', 'inline'], true) ? strtolower($dispositionType) : 'inline';

    $isPdf = str_ends_with(strtolower($fallbackFilename), '.pdf');
    $curlOptions = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 7200,
        CURLOPT_BUFFERSIZE => 131072,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$hasSentStatus, &$hasSentDisposition, $fallbackFilename, $isPdf, $disposition): int {
            $len = strlen($headerLine);
            $trimmed = trim($headerLine);
            if ($trimmed === '') {
                return $len;
            }

            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/i', $trimmed, $matches)) {
                $code = (int) $matches[1];
                if ($code !== 301 && $code !== 302 && $code !== 307 && $code !== 308 && $code !== 416) {
                    if (!headers_sent()) {
                        http_response_code($code);
                        $hasSentStatus = true;
                    }
                }
                return $len;
            }

            $parts = explode(':', $trimmed, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                if ($name === 'content-type' && $isPdf && $disposition === 'inline') {
                    if (!headers_sent()) {
                        header('Content-Type: application/pdf');
                    }
                } elseif (in_array($name, ['content-range', 'accept-ranges', 'content-length', 'content-type'], true)) {
                    if (!headers_sent()) {
                        header($trimmed);
                    }
                } elseif ($name === 'content-disposition') {
                    if (!headers_sent()) {
                        header('Content-Disposition: ' . $disposition . '; filename="' . project_proxy_content_disposition_filename($fallbackFilename) . '"');
                        $hasSentDisposition = true;
                    }
                }
            }

            return $len;
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$hasSentDisposition, $fallbackFilename, $isPdf, $disposition): int {
            if (!headers_sent()) {
                if ($isPdf && $disposition === 'inline') {
                    header('Content-Type: application/pdf');
                    header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
                }
                header('Accept-Ranges: bytes');
                if (!$hasSentDisposition) {
                    header('Content-Disposition: ' . $disposition . '; filename="' . project_proxy_content_disposition_filename($fallbackFilename) . '"');
                }
            }
            echo $data;
            @flush();

            return strlen($data);
        },
    ];

    $isOpenDrive = (stripos($url, 'opendrive.com') !== false || stripos($url, 'od.lk') !== false);
    if (!$isOpenDrive && isset($_SERVER['HTTP_RANGE']) && is_string($_SERVER['HTTP_RANGE']) && $_SERVER['HTTP_RANGE'] !== '') {
        $rawRange = str_ireplace('bytes=', '', $_SERVER['HTTP_RANGE']);
        $curlOptions[CURLOPT_RANGE] = trim($rawRange);
    }

    curl_setopt_array($ch, $curlOptions);
    curl_exec($ch);
    curl_close($ch);
}

function project_proxy_fail(int $code, string $message): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $message;
    exit;
}

function project_proxy_validate_mutual_exclusive(string $rawProjectLink, bool $hasProjectFileUpload): ?string
{
    $projectLink = project_proxy_normalize_external_url($rawProjectLink);
    if (trim($rawProjectLink) !== '' && $projectLink === '') {
        return 'ลิงก์โปรเจกต์ภายนอกไม่ถูกต้อง';
    }
    if ($projectLink !== '' && $hasProjectFileUpload) {
        return 'กรุณาเลือกอย่างใดอย่างหนึ่ง: อัปโหลดไฟล์โปรเจกต์ หรือใส่ลิงก์ภายนอก';
    }

    return null;
}

/**
 * @param array<string,mixed> $fileUrls
 * @param array<int,string> $oldFileIdsToDelete
 */
function project_proxy_apply_project_link_post(
    array &$fileUrls,
    array &$oldFileIdsToDelete,
    string $rawProjectLink,
    bool $hasNewProjectUpload
): void {
    $projectLink = project_proxy_normalize_external_url($rawProjectLink);
    if (trim($rawProjectLink) !== '' && $projectLink === '') {
        throw new RuntimeException('ลิงก์โปรเจกต์ภายนอกไม่ถูกต้อง');
    }
    if ($projectLink !== '' && $hasNewProjectUpload) {
        throw new RuntimeException('กรุณาเลือกอย่างใดอย่างหนึ่ง: อัปโหลดไฟล์โปรเจกต์ หรือใส่ลิงก์ภายนอก');
    }
    if ($projectLink === '') {
        return;
    }

    foreach (od_collect_drive_ids_from_file_urls_entry($fileUrls['project_file'] ?? null) as $oid) {
        $oldFileIdsToDelete[] = $oid;
    }
    $fileUrls['project_file'] = project_proxy_build_external_project_file_entry($projectLink);
}
