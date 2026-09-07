<?php
declare(strict_types=1);

/**
 * Optimize uploaded MP4 video files for fast web streaming (faststart / moov atom at start).
 * Uses ffmpeg if available; falls back gracefully to the original file if ffmpeg is missing.
 *
 * @param array<string, mixed> $file Standard $_FILES array element
 * @return array{path: string, name: string, is_temp: bool} Path to upload file and flag if temporary
 */
function prepareVideoUploadForOpenDrive(array $file): array
{
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? 'video.mp4');

    if ($tmpPath === '' || !is_readable($tmpPath)) {
        return ['path' => $tmpPath, 'name' => $originalName, 'is_temp' => false];
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'm4v'], true)) {
        return ['path' => $tmpPath, 'name' => $originalName, 'is_temp' => false];
    }

    $ffmpegBin = findFFmpegBinary();
    if ($ffmpegBin === '') {
        return ['path' => $tmpPath, 'name' => $originalName, 'is_temp' => false];
    }

    $destDir = sys_get_temp_dir();
    $outPath = $destDir . DIRECTORY_SEPARATOR . 'faststart_' . uniqid('', true) . '.' . $ext;

    $cmd = sprintf(
        '%s -y -i %s -c copy -movflags +faststart %s 2>&1',
        escapeshellarg($ffmpegBin),
        escapeshellarg($tmpPath),
        escapeshellarg($outPath)
    );

    $output = [];
    $returnVar = -1;
    exec($cmd, $output, $returnVar);

    if ($returnVar === 0 && file_exists($outPath) && filesize($outPath) > 0) {
        return [
            'path' => $outPath,
            'name' => $originalName,
            'is_temp' => true,
        ];
    }

    if (file_exists($outPath)) {
        @unlink($outPath);
    }

    return ['path' => $tmpPath, 'name' => $originalName, 'is_temp' => false];
}

/**
 * Find FFmpeg binary path on Windows system or returning empty string if not found.
 */
function findFFmpegBinary(): string
{
    static $cachedPath = null;
    if ($cachedPath !== null) {
        return $cachedPath;
    }

    $candidates = [
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\xampp\\ffmpeg\\bin\\ffmpeg.exe',
        'ffmpeg.exe',
        'ffmpeg',
    ];

    foreach ($candidates as $cand) {
        $cmd = escapeshellarg($cand) . ' -version 2>&1';
        $output = [];
        $returnVar = -1;
        @exec($cmd, $output, $returnVar);
        if ($returnVar === 0) {
            $cachedPath = $cand;
            return $cachedPath;
        }
    }

    $cachedPath = '';
    return $cachedPath;
}

/**
 * Clean up temporary optimized video file if created.
 *
 * @param array{path: string, name: string, is_temp: bool} $prepared
 */
function cleanupOptimizedVideoTemp(array $prepared): void
{
    if (($prepared['is_temp'] ?? false) && file_exists($prepared['path'])) {
        @unlink($prepared['path']);
    }
}
