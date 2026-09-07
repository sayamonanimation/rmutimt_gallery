<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/project_file_proxy.php';

$projectId = (int) ($_GET['id'] ?? 0);
$project = project_proxy_load_authorized($pdo, $projectId);
if ($project === null) {
    project_proxy_fail(404, 'Project not found.');
}

$fileUrls = project_proxy_parse_file_urls((string) ($project['file_urls'] ?? ''));
$entry = $fileUrls['project_file'] ?? null;

// Release session lock before streaming file download so other tabs are not blocked
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!is_array($entry) || !project_proxy_entry_has_file($entry)) {
    project_proxy_fail(404, 'Project file not found.');
}

// Handle external links (Google Drive, Figma, Canva, etc.) by redirecting directly
if (project_proxy_is_external_project_file($entry)) {
    $externalUrl = project_proxy_external_url($entry);
    if ($externalUrl !== '') {
        header('Location: ' . $externalUrl);
        exit;
    }
}

$streamUrl = project_proxy_resolve_stream_url($entry);
if ($streamUrl === '') {
    project_proxy_fail(404, 'Unable to fetch project file from storage.');
}

$filename = project_proxy_resolve_filename($entry, 'project_file');

if (!headers_sent()) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . project_proxy_content_disposition_filename($filename) . '"');
    header('Cache-Control: private, no-cache');
    header('X-Content-Type-Options: nosniff');
}

project_proxy_stream_remote($streamUrl, $filename, 'attachment');
exit;
