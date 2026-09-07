<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();
session_write_close();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/project_file_proxy.php';

$projectId = (int) ($_GET['id'] ?? 0);
$project = project_proxy_load_authorized($pdo, $projectId);
if ($project === null) {
    project_proxy_fail(404, 'Project not found.');
}

$fileUrls = project_proxy_parse_file_urls((string) ($project['file_urls'] ?? ''));
$entry = $fileUrls['thesis_pdf'] ?? null;
if (!is_array($entry) || !project_proxy_entry_has_file($entry)) {
    project_proxy_fail(404, 'Thesis PDF not found.');
}

$streamUrl = project_proxy_resolve_stream_url($entry);
if ($streamUrl === '') {
    project_proxy_fail(404, 'Unable to fetch thesis PDF from storage.');
}

$filename = project_proxy_resolve_filename($entry, 'document.pdf');
if (!str_ends_with(strtolower($filename), '.pdf')) {
    $filename .= '.pdf';
}

project_proxy_stream_remote($streamUrl, $filename);
exit;
