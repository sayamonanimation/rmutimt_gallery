<?php
declare(strict_types=1);

/** Card thumbnail frame: .work-thumb-wrap height 176px, ~16:9 — max width for 2x retina. */
const IMAGE_MAX_WIDTH_THUMBNAIL = 900;

/** Gallery main slide up to lg:h-[600px] and video poster — max display width. */
const IMAGE_MAX_WIDTH_GALLERY = 1000;

/** Alias for main-media / display-type images (video poster, gallery slides). */
const IMAGE_MAX_WIDTH_DISPLAY = 1000;

const IMAGE_WEBP_QUALITY = 80;

const IMAGE_PURPOSE_THUMBNAIL = 'thumbnail';
const IMAGE_PURPOSE_GALLERY = 'gallery';
const IMAGE_PURPOSE_DISPLAY = 'display';

/**
 * Resolve max pixel width from UI purpose.
 */
function imageMaxWidthForPurpose(string $purpose): int
{
    return match ($purpose) {
        IMAGE_PURPOSE_GALLERY => IMAGE_MAX_WIDTH_GALLERY,
        IMAGE_PURPOSE_DISPLAY => IMAGE_MAX_WIDTH_DISPLAY,
        default => IMAGE_MAX_WIDTH_THUMBNAIL,
    };
}

/**
 * Load a GD image resource from path + MIME (jpeg/png/webp). GIF returns null (keep animation).
 *
 * @return \GdImage|null
 */
function imageLoadGdResource(string $sourcePath, string $mimeType)
{
    if (!extension_loaded('gd') || !is_readable($sourcePath)) {
        return null;
    }

    $mimeType = strtolower(trim($mimeType));
    if ($mimeType === 'image/jpg') {
        $mimeType = 'image/jpeg';
    }
    if ($mimeType === 'image/x-png') {
        $mimeType = 'image/png';
    }

    if ($mimeType === 'image/jpeg') {
        $image = @imagecreatefromjpeg($sourcePath);
        if ($image !== false) {
            return $image;
        }
    }
    if ($mimeType === 'image/png') {
        $image = @imagecreatefrompng($sourcePath);
        if ($image !== false) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            return $image;
        }
    }
    if ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $image = @imagecreatefromwebp($sourcePath);
        if ($image !== false) {
            return $image;
        }
    }

    // Reliable GD binary string loader fallback
    $data = @file_get_contents($sourcePath);
    if ($data !== false && $data !== '') {
        $image = @imagecreatefromstring($data);
        if ($image !== false) {
            imagealphablending($image, false);
            imagesavealpha($image, true);

            return $image;
        }
    }

    return null;
}

/**
 * Detect MIME type for an on-disk file.
 */
function imageDetectMimeType(string $path, string $fallback = '', string $originalName = ''): string
{
    // 1. Check original filename extension if passed
    if ($originalName !== '') {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $fromOrig = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => '',
        };
        if ($fromOrig !== '') {
            return $fromOrig;
        }
    }

    // 2. Check path extension if it's not a generic .tmp
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $fromExt = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => '',
    };
    if ($fromExt !== '') {
        return $fromExt;
    }

    // 3. Check fallback MIME type if it starts with image/
    $fallback = strtolower(trim($fallback));
    if ($fallback === 'image/jpg') {
        $fallback = 'image/jpeg';
    }
    if (str_starts_with($fallback, 'image/')) {
        return $fallback;
    }

    // 4. Try mime_content_type (ignore generic application/octet-stream or text/plain)
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            $detected = strtolower($detected);
            if ($detected !== 'application/octet-stream' && $detected !== 'text/plain') {
                return $detected;
            }
        }
    }

    return $fallback;
}

/**
 * Compute scaled dimensions (preserve aspect ratio, never upscale).
 *
 * @return array{width:int,height:int}
 */
function imageComputeScaledDimensions(int $width, int $height, int $maxWidth): array
{
    if ($width <= 0 || $height <= 0) {
        return ['width' => $width, 'height' => $height];
    }
    if ($width <= $maxWidth) {
        return ['width' => $width, 'height' => $height];
    }

    $ratio = $maxWidth / $width;

    return [
        'width' => $maxWidth,
        'height' => max(1, (int) round($height * $ratio)),
    ];
}

/**
 * Resize GD image to target dimensions (returns new resource).
 *
 * @param \GdImage $source
 * @return \GdImage|null
 */
function imageResizeGd($source, int $targetWidth, int $targetHeight)
{
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    if ($srcW <= 0 || $srcH <= 0) {
        return null;
    }

    $dest = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($dest === false) {
        return null;
    }

    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    if ($transparent !== false) {
        imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $transparent);
    }
    imagealphablending($dest, true);

    if (!imagecopyresampled($dest, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH)) {
        imagedestroy($dest);

        return null;
    }

    return $dest;
}

/**
 * Ensure project temp directory exists for optimized uploads.
 */
function imageOptimizedTempDir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'temp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return sys_get_temp_dir();
    }

    return $dir;
}

/**
 * Build .webp filename from original upload name.
 */
function imageWebpFileName(string $originalName): string
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/[^\p{L}\p{N}\-_.]+/u', '_', (string) $base) ?? 'image';
    $base = trim((string) $base, '._-');
    if ($base === '') {
        $base = 'image';
    }

    return $base . '.webp';
}

/**
 * Resize and convert an image file to WebP in a temp directory.
 *
 * @return array{path:string,name:string,mimeType:string,size:int}|null Null when skipped or failed (caller uses original).
 */
function optimizeImageToWebpTemp(
    string $sourcePath,
    string $mimeType,
    string $originalName,
    string $purpose = IMAGE_PURPOSE_THUMBNAIL,
    int $quality = IMAGE_WEBP_QUALITY
): ?array {
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        return null;
    }
    if (!is_readable($sourcePath)) {
        return null;
    }

    $mimeType = imageDetectMimeType($sourcePath, $mimeType, $originalName);
    if ($mimeType === 'image/gif') {
        return null;
    }
    if (!str_starts_with($mimeType, 'image/')) {
        return null;
    }

    $image = imageLoadGdResource($sourcePath, $mimeType);
    if ($image === null) {
        return null;
    }

    $srcW = imagesx($image);
    $srcH = imagesy($image);
    $maxWidth = imageMaxWidthForPurpose($purpose);
    $scaled = imageComputeScaledDimensions($srcW, $srcH, $maxWidth);

    $working = $image;
    if ($scaled['width'] !== $srcW || $scaled['height'] !== $srcH) {
        $resized = imageResizeGd($image, $scaled['width'], $scaled['height']);
        if ($resized !== null) {
            imagedestroy($image);
            $working = $resized;
        }
    }

    $tempDir = imageOptimizedTempDir();
    $tempPath = $tempDir . DIRECTORY_SEPARATOR . 'od_' . uniqid('', true) . '.webp';
    $saved = imagewebp($working, $tempPath, max(0, min(100, $quality)));
    imagedestroy($working);

    if (!$saved || !is_file($tempPath)) {
        @unlink($tempPath);

        return null;
    }

    $size = filesize($tempPath);
    if ($size === false || $size <= 0) {
        @unlink($tempPath);

        return null;
    }

    return [
        'path' => $tempPath,
        'name' => imageWebpFileName($originalName),
        'mimeType' => 'image/webp',
        'size' => (int) $size,
    ];
}

/**
 * Prepare upload path: optimize raster images to WebP temp file when possible.
 *
 * @param array<string,mixed> $file One $_FILES element
 * @return array{path:string,name:string,mimeType:string,cleanup:bool} Paths/names for OpenDrive upload
 */
function prepareImageUploadForOpenDrive(array $file, string $imagePurpose = ''): array
{
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? 'upload.bin');
    $mimeType = (string) ($file['type'] ?? 'application/octet-stream');

    $result = [
        'path' => $tmpPath,
        'name' => $originalName,
        'mimeType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        'cleanup' => false,
    ];

    if ($imagePurpose === '' || $tmpPath === '' || !is_readable($tmpPath)) {
        return $result;
    }

    $mimeLower = strtolower(imageDetectMimeType($tmpPath, $mimeType, $originalName));
    if (!str_starts_with($mimeLower, 'image/') || $mimeLower === 'image/gif') {
        return $result;
    }

    $optimized = optimizeImageToWebpTemp($tmpPath, $mimeType, $originalName, $imagePurpose, IMAGE_WEBP_QUALITY);
    if ($optimized === null) {
        return $result;
    }

    return [
        'path' => $optimized['path'],
        'name' => $optimized['name'],
        'mimeType' => $optimized['mimeType'],
        'cleanup' => true,
    ];
}

/**
 * Delete temp file created by prepareImageUploadForOpenDrive when cleanup flag is set.
 */
function cleanupOptimizedUploadTemp(array $prepared): void
{
    if (($prepared['cleanup'] ?? false) !== true) {
        return;
    }
    $path = (string) ($prepared['path'] ?? '');
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Infer image optimization purpose from upload field / file type key.
 */
function imagePurposeFromUploadContext(string $fieldOrType, bool $isGalleryBatch = false): string
{
    if ($fieldOrType === 'thumbnail' || $fieldOrType === 'thumbnail_file') {
        return IMAGE_PURPOSE_THUMBNAIL;
    }
    if ($fieldOrType === 'main_media' || $fieldOrType === 'main_media_file') {
        return $isGalleryBatch ? IMAGE_PURPOSE_GALLERY : IMAGE_PURPOSE_DISPLAY;
    }
    if ($fieldOrType === 'project_file') {
        return IMAGE_PURPOSE_THUMBNAIL;
    }

    return '';
}

/**
 * Legacy in-place recompress (author avatars on local disk). GIF skipped.
 */
function compressImageBeforeUpload(string $sourcePath, string $mimeType, int $quality = 75): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
        return false;
    }

    $mimeType = strtolower(trim($mimeType));
    if ($mimeType === 'image/jpg') {
        $mimeType = 'image/jpeg';
    }

    if ($mimeType === 'image/jpeg') {
        $image = @imagecreatefromjpeg($sourcePath);
        if ($image !== false) {
            imagejpeg($image, $sourcePath, max(0, min(100, $quality)));
            imagedestroy($image);

            return true;
        }
    } elseif ($mimeType === 'image/png') {
        $image = @imagecreatefrompng($sourcePath);
        if ($image !== false) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $pngQuality = (int) round(9 - ($quality / 10));
            $pngQuality = max(0, min(9, $pngQuality));
            imagepng($image, $sourcePath, $pngQuality);
            imagedestroy($image);

            return true;
        }
    } elseif ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $image = @imagecreatefromwebp($sourcePath);
        if ($image !== false) {
            imagewebp($image, $sourcePath, max(0, min(100, $quality)));
            imagedestroy($image);

            return true;
        }
    }

    return false;
}

/**
 * Validate and store an author/member avatar from a single $_FILES entry (local disk only).
 *
 * @param array<string,mixed> $file One $_FILES element
 * @return string|null Stored filename basename, or null if rejected / failed
 */
function save_author_profile_upload(array $file, string $uploadAuthorDir, int $compressQuality = 75): ?string
{
    return saveLocalUploadedImageAsWebp($file, $uploadAuthorDir, 'author_', 600, $compressQuality);
}

/**
 * Save an uploaded image file ($_FILES element) as an optimized WebP file on local disk.
 *
 * @param array<string,mixed> $file One element of $_FILES
 * @param string $destDir Destination directory
 * @param string $prefix Filename prefix
 * @param int $maxWidth Max width in pixels (default 600)
 * @param int $quality WebP quality (1-100, default 80)
 * @return string|null Generated filename basename on success, or null on failure
 */
function saveLocalUploadedImageAsWebp(
    array $file,
    string $destDir,
    string $prefix = 'img_',
    int $maxWidth = 600,
    int $quality = IMAGE_WEBP_QUALITY
): ?string {
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return null;
    }

    $destDir = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return null;
    }

    $origName = (string) ($file['name'] ?? 'upload.bin');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        return null;
    }

    $mimeType = imageDetectMimeType($tmpPath, (string) ($file['type'] ?? ''));
    if ($ext === 'gif' || $mimeType === 'image/gif') {
        $newFn = $prefix . uniqid('', true) . '_' . time() . '.gif';
        $target = $destDir . $newFn;

        return move_uploaded_file($tmpPath, $target) ? $newFn : null;
    }

    // Try converting to WebP using GD
    if (extension_loaded('gd') && function_exists('imagewebp')) {
        $gdImage = imageLoadGdResource($tmpPath, $mimeType);
        if ($gdImage !== null) {
            $srcW = imagesx($gdImage);
            $srcH = imagesy($gdImage);
            $scaled = imageComputeScaledDimensions($srcW, $srcH, $maxWidth);

            $working = $gdImage;
            if ($scaled['width'] !== $srcW || $scaled['height'] !== $srcH) {
                $resized = imageResizeGd($gdImage, $scaled['width'], $scaled['height']);
                if ($resized !== null) {
                    imagedestroy($gdImage);
                    $working = $resized;
                }
            }

            $newFn = $prefix . uniqid('', true) . '_' . time() . '.webp';
            $target = $destDir . $newFn;
            $saved = imagewebp($working, $target, max(0, min(100, $quality)));
            imagedestroy($working);
            @unlink($tmpPath);

            if ($saved && is_file($target)) {
                return $newFn;
            }
        }
    }

    // Fallback if GD / WebP conversion fails
    $fallbackExt = $ext === 'jpeg' ? 'jpg' : $ext;
    $newFn = $prefix . uniqid('', true) . '_' . time() . '.' . $fallbackExt;
    $target = $destDir . $newFn;

    return move_uploaded_file($tmpPath, $target) ? $newFn : null;
}

/**
 * Process a remote/local image file path to WebP temp (for migration script).
 *
 * @return array{path:string,name:string,mimeType:string,size:int}|null
 */
function optimizeLocalOrDownloadedImageToWebp(
    string $sourcePath,
    string $originalName,
    string $purpose = IMAGE_PURPOSE_THUMBNAIL
): ?array {
    $mime = imageDetectMimeType($sourcePath, '');

    return optimizeImageToWebpTemp($sourcePath, $mime, $originalName, $purpose, IMAGE_WEBP_QUALITY);
}

/**
 * Check whether a file entry URL/name still uses a legacy raster extension.
 *
 * @param array<string,mixed>|null $entry
 */
function imageEntryNeedsWebpConversion(?array $entry): bool
{
    if (!is_array($entry)) {
        return false;
    }
    foreach (['name', 'link', 'webContentLink'] as $key) {
        if (!isset($entry[$key]) || !is_string($entry[$key])) {
            continue;
        }
        if (preg_match('/\.(jpe?g|png)(\?|#|$)/i', $entry[$key]) === 1) {
            return true;
        }
    }

    return false;
}
