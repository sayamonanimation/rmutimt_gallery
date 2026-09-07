<?php
declare(strict_types=1);

const CSRF_TOKEN_SESSION_KEY = '_csrf_token';

function csrf_token(): string
{
    if (!isset($_SESSION[CSRF_TOKEN_SESSION_KEY]) || !is_string($_SESSION[CSRF_TOKEN_SESSION_KEY]) || $_SESSION[CSRF_TOKEN_SESSION_KEY] === '') {
        $_SESSION[CSRF_TOKEN_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_TOKEN_SESSION_KEY];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(?string $submittedToken): bool
{
    $sessionToken = $_SESSION[CSRF_TOKEN_SESSION_KEY] ?? null;
    if (!is_string($submittedToken) || $submittedToken === '' || !is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function csrf_rotate_token(): string
{
    $_SESSION[CSRF_TOKEN_SESSION_KEY] = bin2hex(random_bytes(32));
    return $_SESSION[CSRF_TOKEN_SESSION_KEY];
}
