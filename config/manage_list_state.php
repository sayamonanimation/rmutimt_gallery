<?php
declare(strict_types=1);

/**
 * @return array<string, int|string>
 */
function manage_list_state_params(array $source, ?string $flashStatus = null): array
{
    $q = [];
    $page = (int) ($source['return_page'] ?? $source['page'] ?? 0);
    $search = trim((string) ($source['return_search'] ?? $source['search'] ?? ''));
    if ($page > 1) {
        $q['page'] = $page;
    }
    if ($search !== '') {
        $q['search'] = $search;
    }
    if ($flashStatus !== null && in_array($flashStatus, ['success', 'error'], true)) {
        $q['status'] = $flashStatus;
    }
    return $q;
}

function manage_list_state_url(string $path, array $source, ?string $flashStatus = null): string
{
    $q = manage_list_state_params($source, $flashStatus);
    return $path . ($q === [] ? '' : '?' . http_build_query($q));
}

function manage_list_state_append(array $source): string
{
    $q = manage_list_state_params($source);
    if ($q === []) {
        return '';
    }
    return '&' . http_build_query($q);
}
