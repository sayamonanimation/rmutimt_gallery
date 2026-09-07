<?php
declare(strict_types=1);

require_once __DIR__ . '/opendrive_config.php';

/**
 * OpenDrive REST client (cURL) with shared cookie jar and browser-like headers
 * for session login, chunked upload, and file delete.
 *
 * @see https://dev.opendrive.com/api
 */
final class OpenDriveHelper
{
    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const CURL_TIMEOUT = 7200;

    private const CURL_CONNECT_TIMEOUT = 120;

    /** Secondary cap inside sanitizeFolderName after getOrCreateFolder 50-char trim (bytes-safe for API). */
    private const FOLDER_NAME_MAX_CHARS = 64;

    private string $apiBase;

    private string $username;

    private string $password;

    private string $sessionId = '';

    /** Persistent cookie jar path (shared across requests in this helper instance). */
    private string $cookieFile;

    public function __construct()
    {
        global $od_username, $od_password, $od_api_base;
        $this->username = isset($od_username) ? trim((string) $od_username) : '';
        $this->password = isset($od_password) ? (string) $od_password : '';
        $base = isset($od_api_base) ? trim((string) $od_api_base) : 'https://dev.opendrive.com/api/v1';
        $this->apiBase = rtrim($this->normalizeApiHostToDev($base), '/');
        $this->cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'od_cookie.txt';
        $this->ensureCookieFile();
    }

    /**
     * Force API host to dev.opendrive.com (replace legacy api.opendrive.com).
     */
    private function normalizeApiHostToDev(string $base): string
    {
        if ($base === '') {
            return 'https://dev.opendrive.com/api/v1';
        }
        return str_ireplace('api.opendrive.com', 'dev.opendrive.com', $base);
    }

    private function ensureCookieFile(): void
    {
        if (is_file($this->cookieFile)) {
            return;
        }
        $dir = dirname($this->cookieFile);
        if (is_dir($dir) && is_writable($dir)) {
            @touch($this->cookieFile);
        }
    }

    /**
     * Browser-like headers for Cloudflare; $mode: json | form | multipart | bare
     * (bare = no Content-Type; for DELETE / no body).
     *
     * @return array<int,string>
     */
    private function buildHttpHeaders(string $mode): array
    {
        $lines = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: th-TH,th;q=0.9,en-US;q=0.8,en;q=0.7',
            'Origin: https://www.opendrive.com',
            'Referer: https://www.opendrive.com/',
            'Sec-Ch-Ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
        ];
        if ($mode === 'json') {
            array_unshift($lines, 'Content-Type: application/json');
        } elseif ($mode === 'form') {
            array_unshift($lines, 'Content-Type: application/x-www-form-urlencoded');
        }
        // multipart / bare: do not set Content-Type (cURL sets multipart boundary when needed).

        return $lines;
    }

    public function login(): string
    {
        if ($this->sessionId !== '') {
            return $this->sessionId;
        }
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('OpenDrive: กรุณาตั้งค่า od_username และ od_password ใน config/opendrive_config.php');
        }
        $url = $this->apiBase . '/session/login.json';
        // API documents "passwd"; include "password" as requested for compatibility.
        $payload = json_encode([
            'username' => $this->username,
            'password' => $this->password,
            'passwd' => $this->password,
        ], JSON_UNESCAPED_SLASHES);
        $body = $this->curlRequest('POST', $url, [
            CURLOPT_POSTFIELDS => $payload,
        ], 'json');
        $data = $this->decodeJsonObject($body);
        $sid = $this->firstString($data, ['SessionID', 'session_id', 'SessionId']);
        if ($sid === '') {
            throw new RuntimeException('OpenDrive login failed: unexpected response');
        }
        $this->sessionId = $sid;
        return $this->sessionId;
    }

    /**
     * Find or create a subfolder under $parentFolderId. Uses session + same cURL transport as uploads.
     *
     * @see https://dev.opendrive.com/api — folder list.json, POST folder.json
     */
    public function getOrCreateFolder(string $folderName, string $parentFolderId): string
    {
        $this->login();

        // 1–2: จำกัดชื่อโฟลเดอร์ก่อน list/create (ภาษาไทยหลาย byte — โฟลเดอร์สั้นไม่กระทบชื่อเต็มใน DB)
        $folderName = trim($folderName);
        $folderName = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $folderName);
        $folderName = is_string($folderName) ? trim($folderName) : '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($folderName, 'UTF-8') > 50) {
                $folderName = mb_substr($folderName, 0, 50, 'UTF-8') . '..';
            }
        } elseif (strlen($folderName) > 50) {
            $folderName = substr($folderName, 0, 50) . '..';
        }
        if ($folderName === '') {
            $folderName = 'Untitled';
        }

        $safe = $this->sanitizeFolderName($folderName);
        $parentFolderId = trim($parentFolderId);
        if ($parentFolderId === '') {
            $parentFolderId = '0';
        }

        $listUrl = $this->apiBase . '/folder/list.json/' . rawurlencode($this->sessionId) . '/' . rawurlencode($parentFolderId);
        $listBody = $this->curlRequest('GET', $listUrl, [
            CURLOPT_HTTPGET => true,
        ], 'bare');
        $data = $this->decodeJsonObject($listBody);
        $folders = $data['Folders'] ?? [];
        if (!is_array($folders)) {
            $folders = [];
        }
        foreach ($folders as $f) {
            if (!is_array($f)) {
                continue;
            }
            $n = trim((string) ($f['Name'] ?? $f['name'] ?? ''));
            if ($n !== '' && strcasecmp($n, $safe) === 0) {
                $id = trim((string) ($f['FolderID'] ?? $f['FolderId'] ?? $f['folder_id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }

        $createUrl = $this->apiBase . '/folder.json';
        $createBody = $this->curlRequest('POST', $createUrl, [
            CURLOPT_POSTFIELDS => http_build_query([
                'session_id' => $this->sessionId,
                'folder_name' => $safe,
                'folder_sub_parent' => $parentFolderId,
                'folder_is_public' => '1',
            ]),
        ], 'form');
        $created = $this->decodeJsonObject($createBody);
        $newId = trim((string) ($created['FolderID'] ?? $created['FolderId'] ?? $created['folder_id'] ?? ''));
        if ($newId === '') {
            throw new RuntimeException('OpenDrive: สร้างโฟลเดอร์ไม่สำเร็จ: ' . $createBody);
        }
        return $newId;
    }

    private function sanitizeFolderName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\"\'\\\\]/u', '', $name);
        $name = is_string($name) ? trim($name) : '';

        $clean = preg_replace('/[\\\\\\/:*?"<>|#\\x00-\\x1F]/u', '_', $name);
        $clean = is_string($clean) ? $clean : $name;
        $clean = trim(preg_replace('/\\s+/u', ' ', $clean) ?? '');
        if ($clean === '') {
            $clean = 'Untitled';
        }
        $max = self::FOLDER_NAME_MAX_CHARS;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($clean, 'UTF-8') > $max) {
                $clean = mb_substr($clean, 0, $max, 'UTF-8');
            }
        } elseif (strlen($clean) > $max) {
            $clean = substr($clean, 0, $max);
        }
        return $clean;
    }

    /**
     * @return array{id:string,link:string,webContentLink:string,name:string,mimeType:string}
     */
    public function uploadFile(string $filePath, string $fileName, string $folderId): array
    {
        set_time_limit(self::CURL_TIMEOUT);
        $this->login();
        $filePath = realpath($filePath) ?: $filePath;
        if (!is_readable($filePath)) {
            throw new RuntimeException('OpenDrive: ไม่สามารถอ่านไฟล์สำหรับอัปโหลดได้');
        }
        $size = filesize($filePath);
        if ($size === false || $size < 0) {
            throw new RuntimeException('OpenDrive: ไม่สามารถอ่านขนาดไฟล์ได้');
        }
        $fileHash = md5_file($filePath);
        if ($fileHash === false) {
            throw new RuntimeException('OpenDrive: คำนวณ MD5 ไม่สำเร็จ');
        }
        $folderId = trim($folderId);
        if ($folderId === '') {
            $folderId = '0';
        }
        $fileName = trim(str_replace(["\0"], '', $fileName));
        if ($fileName === '') {
            $fileName = 'upload.bin';
        }
        $fileName = self::makeUniqueOpenDriveFileName($fileName);
        $mimeType = $this->guessMime($fileName, $filePath);

        $createUrl = $this->apiBase . '/upload/create_file.json';
        $createBody = $this->curlRequest('POST', $createUrl, [
            CURLOPT_POSTFIELDS => http_build_query([
                'session_id' => $this->sessionId,
                'folder_id' => $folderId,
                'file_name' => $fileName,
                'file_size' => (string) $size,
                'file_hash' => $fileHash,
            ]),
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
        ], 'form');
        $created = $this->decodeJsonObject($createBody);
        $fileId = $this->firstString($created, ['FileId', 'file_id', 'ID']);
        if ($fileId === '') {
            throw new RuntimeException('OpenDrive create_file failed: ' . $createBody);
        }
        $requireHashOnly = (int) ($created['RequireHashOnly'] ?? $created['require_hash_only'] ?? 0);
        if ($requireHashOnly === 1) {
            return $this->finishFromCloseUpload($fileId, $size, '', $fileHash, $fileName, $mimeType);
        }

        $openUrl = $this->apiBase . '/upload/open_file_upload.json';
        $openBody = $this->curlRequest('POST', $openUrl, [
            CURLOPT_POSTFIELDS => http_build_query([
                'session_id' => $this->sessionId,
                'file_id' => $fileId,
                'file_size' => (string) $size,
                'file_hash' => $fileHash,
            ]),
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
        ], 'form');
        $opened = $this->decodeJsonObject($openBody);
        $tempLocation = $this->firstString($opened, ['TempLocation', 'temp_location']);
        if ($tempLocation === '') {
            throw new RuntimeException('OpenDrive open_file_upload failed: ' . $openBody);
        }

        $chunkSize = 65536;
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('OpenDrive: เปิดไฟล์ต้นฉบับไม่สำเร็จ');
        }
        $offset = 0;
        try {
            while (!feof($handle)) {
                $piece = fread($handle, $chunkSize);
                if ($piece === false) {
                    throw new RuntimeException('OpenDrive: อ่านไฟล์ระหว่างอัปโหลดไม่สำเร็จ');
                }
                $len = strlen($piece);
                if ($len === 0) {
                    break;
                }
                $chunkPath = sys_get_temp_dir() . '/od_chunk_' . bin2hex(random_bytes(8)) . '.bin';
                if (file_put_contents($chunkPath, $piece) === false) {
                    throw new RuntimeException('OpenDrive: เขียน chunk ชั่วคราวไม่สำเร็จ');
                }
                try {
                    $chunkUrl = $this->apiBase . '/upload/upload_file_chunk.json';
                    $cf = new CURLFile($chunkPath, 'application/octet-stream', 'chunk.bin');
                    $this->curlRequest('POST', $chunkUrl, [
                        CURLOPT_POSTFIELDS => [
                            'session_id' => $this->sessionId,
                            'file_id' => $fileId,
                            'temp_location' => $tempLocation,
                            'chunk_offset' => (string) $offset,
                            'chunk_size' => (string) $len,
                            'file_data' => $cf,
                        ],
                        CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
                        CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
                    ], 'multipart');
                } finally {
                    if (is_file($chunkPath)) {
                        unlink($chunkPath);
                    }
                }
                $offset += $len;
            }
        } finally {
            fclose($handle);
        }

        return $this->finishFromCloseUpload($fileId, $size, $tempLocation, $fileHash, $fileName, $mimeType);
    }

    public function deleteFile(string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return;
        }
        $this->login();
        $trashUrl = $this->apiBase . '/file/trash.json';
        $this->curlRequest('POST', $trashUrl, [
            CURLOPT_POSTFIELDS => http_build_query([
                'session_id' => $this->sessionId,
                'file_id' => $fileId,
            ]),
        ], 'form');
        $delUrl = $this->apiBase . '/file.json/' . rawurlencode($this->sessionId) . '/' . rawurlencode($fileId);
        $this->curlRequest('DELETE', $delUrl, [], 'bare');
    }

    /**
     * @return array{id:string,link:string,webContentLink:string,name:string,mimeType:string}
     */
    private function finishFromCloseUpload(
        string $fileId,
        int $fileSize,
        string $tempLocation,
        string $fileHash,
        string $fileName,
        string $mimeType
    ): array {
        $closeUrl = $this->apiBase . '/upload/close_file_upload.json';
        $fields = [
            'session_id' => $this->sessionId,
            'file_id' => $fileId,
            'file_size' => (string) $fileSize,
            'temp_location' => $tempLocation,
            'file_hash' => $fileHash,
        ];
        $closeBody = $this->curlRequest('POST', $closeUrl, [
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
        ], 'form');
        $closed = $this->decodeJsonObject($closeBody);
        $finalId = $this->firstString($closed, ['FileId', 'file_id', 'ID']) ?: $fileId;
        $direct = $this->firstString($closed, ['DirectLinkPublick', 'DirectLinkPublic', 'direct_link_publick']);
        $stream = $this->firstString($closed, ['StreamingLink', 'streaming_link']);
        $download = $this->firstString($closed, ['DownloadLink', 'download_link']);
        $link = $this->firstString($closed, ['Link', 'link']);
        $thumb = $this->firstString($closed, ['ThumbLink', 'thumb_link']);
        $displayLink = $direct !== '' ? $direct : ($download !== '' ? $download : ($link !== '' ? $link : ($stream !== '' ? $stream : $thumb)));
        $mimeLower = strtolower($mimeType);
        if (str_starts_with($mimeLower, 'video/')) {
            $contentLink = $stream !== '' ? $stream : ($download !== '' ? $download : $displayLink);
        } else {
            $contentLink = $download !== '' ? $download : ($displayLink !== '' ? $displayLink : $stream);
        }

        $this->assertUploadResultLinks($finalId, $displayLink, $contentLink);

        $this->setFilePublicAccess($finalId);

        return [
            'id' => $finalId,
            'link' => $displayLink,
            'webContentLink' => $contentLink,
            'name' => $fileName,
            'mimeType' => $mimeType,
        ];
    }

    public function setFilePublicAccess(string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return;
        }
        try {
            $this->login();
            $accessUrl = $this->apiBase . '/permissions/set_file_access.json';
            $this->curlRequest('POST', $accessUrl, [
                CURLOPT_POSTFIELDS => http_build_query([
                    'session_id' => $this->sessionId,
                    'file_id' => $fileId,
                    'access_file' => '1',
                    'public_access' => '1',
                ]),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
            ], 'form');
        } catch (Throwable $e) {
            // Silently ignore if API method differs
        }
    }

    private function assertUploadResultLinks(string $fileId, string $link, string $webContentLink): void
    {
        $fileId = trim($fileId);
        if ($fileId === '' || strtolower($fileId) === 'n/a') {
            throw new RuntimeException('OpenDrive upload failed: invalid file id in response');
        }

        $isPlaceholder = static function (string $value): bool {
            $t = trim($value);

            return $t === '' || strtolower($t) === 'n/a';
        };
        $isHttpUrl = static function (string $value): bool {
            return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
        };

        if ($isPlaceholder($link) && $isPlaceholder($webContentLink)) {
            throw new RuntimeException('OpenDrive upload failed: no download link returned from API');
        }

        $linkOk = !$isPlaceholder($link) && $isHttpUrl($link);
        $webOk = !$isPlaceholder($webContentLink) && $isHttpUrl($webContentLink);
        if (!$linkOk && !$webOk) {
            throw new RuntimeException('OpenDrive upload failed: download links are not valid HTTP URLs');
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $v = $data[$k];
            if (is_string($v)) {
                $t = trim($v);
                if ($t !== '' && strtolower($t) !== 'n/a') {
                    return $t;
                }
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        $trim = trim($json);
        if ($trim === '') {
            return [];
        }
        $decoded = json_decode($trim, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,CURLOPT_*|int|string> $extra
     */
    private function curlRequest(string $method, string $url, array $extra = [], string $headerMode = 'form'): string
    {
        $url = str_ireplace('api.opendrive.com', 'dev.opendrive.com', $url);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_USERAGENT => self::DEFAULT_USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->buildHttpHeaders($headerMode),
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
        } elseif ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        foreach ($extra as $k => $v) {
            if ($k === CURLOPT_HTTPHEADER && is_array($v)) {
                $opts[CURLOPT_HTTPHEADER] = array_merge($opts[CURLOPT_HTTPHEADER], $v);
                continue;
            }
            $opts[$k] = $v;
        }
        curl_setopt_array($ch, $opts);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_CONNECT_TIMEOUT);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException('OpenDrive cURL Error: ' . $err);
        }
        if ($out === false) {
            throw new RuntimeException('OpenDrive cURL Error: empty response body');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('OpenDrive API Error (HTTP ' . $httpCode . '): ' . $out);
        }
        return (string) $out;
    }

    /**
     * OpenDrive returns HTTP 409 if a file with the same name already exists in the folder.
     */
    public static function makeUniqueOpenDriveFileName(string $originalName): string
    {
        $base = basename(str_replace(["\0"], '', $originalName));
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $base);
        if ($safe === '' || $safe === '_') {
            $safe = 'upload.bin';
        }
        if (strlen($safe) > 160) {
            $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));
            $stem = (string) pathinfo($safe, PATHINFO_FILENAME);
            $stem = substr($stem, 0, 120);
            $safe = $ext !== '' ? $stem . '.' . $ext : $stem;
        }
        $uniq = str_replace(['.', '/', '\\'], '_', uniqid('', true));

        return (string) time() . '_' . $uniq . '_' . $safe;
    }

    /**
     * True if this $_FILES field has at least one successful upload (single or multi slot).
     */
    public static function uploadedFileFieldHasOk(?array $fileField): bool
    {
        if (!is_array($fileField) || !array_key_exists('error', $fileField)) {
            return false;
        }
        $e = $fileField['error'];
        if (is_array($e)) {
            foreach ($e as $code) {
                if ((int) $code === UPLOAD_ERR_OK) {
                    return true;
                }
            }

            return false;
        }

        return (int) $e === UPLOAD_ERR_OK;
    }

    private function guessMime(string $fileName, string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            $m = mime_content_type($filePath);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}

/**
 * Collect stored file IDs from file_urls (same shape as legacy Drive JSON).
 *
 * @param array<string,mixed> $fileUrls
 * @return array<int,string>
 */
function collect_storage_file_ids_from_file_urls(array $fileUrls): array
{
    $ids = [];
    foreach (['thumbnail', 'thesis_pdf', 'project_file'] as $k) {
        if (!isset($fileUrls[$k]) || !is_array($fileUrls[$k])) {
            continue;
        }
        $fid = trim((string) ($fileUrls[$k]['id'] ?? ''));
        if ($fid !== '') {
            $ids[] = $fid;
        }
    }
    $mm = $fileUrls['main_media'] ?? null;
    if (is_array($mm)) {
        if (isset($mm['id']) && is_string($mm['id'])) {
            $t = trim($mm['id']);
            if ($t !== '') {
                $ids[] = $t;
            }
        } else {
            foreach ($mm as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $fid = trim((string) ($item['id'] ?? ''));
                if ($fid !== '') {
                    $ids[] = $fid;
                }
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Normalize $_FILES[field] to a list of single-file arrays (same shape as admin/update_process.php).
 *
 * @return array<int,array<string,mixed>>
 */
function od_normalize_uploaded_files_field(string $fieldName): array
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
 * OpenDrive/storage file IDs that are safe to pass to delete APIs (skip placeholders like n/a).
 */
function od_is_deletable_storage_file_id(string $id): bool
{
    $id = trim($id);
    if ($id === '' || strtolower($id) === 'n/a') {
        return false;
    }

    return true;
}

/**
 * Pick first successfully uploaded file from candidate $_FILES input names (single-file fields).
 *
 * @param array<int,string> $candidateNames
 * @return array<string,mixed>|null
 */
function od_pick_first_ok_upload(array $candidateNames): ?array
{
    foreach ($candidateNames as $name) {
        if (!isset($_FILES[$name]) || !is_array($_FILES[$name])) {
            continue;
        }
        $f = $_FILES[$name];
        if (isset($f['error']) && is_array($f['error'])) {
            foreach (od_normalize_uploaded_files_field($name) as $part) {
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

/**
 * @return array<int,string>
 */
function od_collect_drive_ids_from_file_urls_entry(mixed $entry): array
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

function od_request_is_xhr(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/**
 * Return JSON error for AJAX upload/edit forms (SweetAlert on the client).
 */
function od_respond_ajax_error(string $message, int $httpCode = 500): never
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(
        ['success' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
