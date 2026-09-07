<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table_name LIMIT 1");
        $stmt->execute([':table_name' => $tableName]);
        return $stmt->fetch() !== false;
    } catch (Throwable) {
        return false;
    }
}

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table_name AND column_name = :column_name LIMIT 1");
        $stmt->execute([':table_name' => $tableName, ':column_name' => $columnName]);
        return $stmt->fetch() !== false;
    } catch (Throwable) {
        return false;
    }
}

$projectId = (int) ($_GET['id'] ?? 0);
if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid project id'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $projectStmt = $pdo->prepare(
        "SELECT
            p.id,
            p.title_th,
            p.title_en,
            p.creators,
            p.advisor_id,
            p.category_id,
            p.academic_year_id,
            p.introduction,
            p.file_urls,
            p.status,
            a.prefix AS advisor_prefix,
            a.full_name AS advisor_full_name,
            c.name AS category_name,
            ay.year AS academic_year
         FROM projects p
         LEFT JOIN advisors a ON a.id = p.advisor_id
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN academic_years ay ON ay.id = p.academic_year_id
         WHERE p.id = :id
         LIMIT 1"
    );
    $projectStmt->execute([':id' => $projectId]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Project not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $members = [];
    if (tableExists($pdo, 'project_members')) {
        $photoCol = tableHasColumn($pdo, 'project_members', 'avatar_filename') ? ', avatar_filename AS photo' : '';
        $memberStmt = $pdo->prepare(
            "SELECT full_name AS name{$photoCol} FROM project_members WHERE project_id = :id ORDER BY id ASC"
        );
        $memberStmt->execute([':id' => $projectId]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($members === []) {
        $creators = trim((string) ($project['creators'] ?? ''));
        if ($creators !== '') {
            $parts = preg_split('/\s*,\s*/u', $creators);
            if (is_array($parts)) {
                foreach ($parts as $name) {
                    $name = trim((string) $name);
                    if ($name !== '') {
                        $members[] = ['name' => $name];
                    }
                }
            }
        }
    }

    $files = [
        'thumbnail' => null,
        'main_media' => null,
        'thesis_pdf' => null,
        'project_file' => null,
    ];

    if (tableExists($pdo, 'project_files')) {
        $fileStmt = $pdo->prepare(
            'SELECT file_type, file_id, file_name, web_view_link FROM project_files WHERE project_id = :id'
        );
        $fileStmt->execute([':id' => $projectId]);
        while ($row = $fileStmt->fetch(PDO::FETCH_ASSOC)) {
            $type = (string) ($row['file_type'] ?? '');
            if (!array_key_exists($type, $files)) {
                continue;
            }
            $files[$type] = [
                'id' => (string) ($row['file_id'] ?? ''),
                'name' => (string) ($row['file_name'] ?? ''),
                'link' => (string) ($row['web_view_link'] ?? ''),
            ];
        }
    } else {
        $fileUrls = json_decode((string) ($project['file_urls'] ?? ''), true);
        if (is_array($fileUrls)) {
            foreach (array_keys($files) as $key) {
                if (isset($fileUrls[$key]) && is_array($fileUrls[$key])) {
                    $files[$key] = [
                        'id' => (string) ($fileUrls[$key]['id'] ?? ''),
                        'name' => (string) ($fileUrls[$key]['name'] ?? ''),
                        'link' => (string) ($fileUrls[$key]['link'] ?? ''),
                    ];
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'project' => [
            'id' => (int) $project['id'],
            'title_th' => (string) ($project['title_th'] ?? ''),
            'title_en' => (string) ($project['title_en'] ?? ''),
            'advisor_id' => (int) ($project['advisor_id'] ?? 0),
            'category_id' => (int) ($project['category_id'] ?? 0),
            'academic_year_id' => (int) ($project['academic_year_id'] ?? 0),
            'introduction' => (string) ($project['introduction'] ?? ''),
            'status' => (string) ($project['status'] ?? 'pending'),
            'members' => $members,
            'files' => $files,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[GET_PROJECT_DATA] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
}
