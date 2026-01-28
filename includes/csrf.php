<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validate_csrf_token(?string $token): bool
{
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf_token(?string $token): void
{
    if (!validate_csrf_token($token)) {
        throw new RuntimeException('Неверный CSRF токен.');
    }
}
