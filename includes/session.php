<?php

declare(strict_types=1);

const SESSION_TIMEOUT_SECONDS = 120;
const SESSION_WARNING_SECONDS = 30;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function enforce_session_timeout(): void
{
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return;
    }

    $elapsed = time() - (int) $_SESSION['last_activity'];

    if ($elapsed >= SESSION_TIMEOUT_SECONDS) {
        $_SESSION['session_expired'] = true;
        $_SESSION['session_warning'] = false;
        logout_user_if_defined();
        return;
    }

    if ($elapsed >= (SESSION_TIMEOUT_SECONDS - SESSION_WARNING_SECONDS)) {
        $_SESSION['session_warning'] = true;
    } else {
        $_SESSION['session_warning'] = false;
    }

    $_SESSION['last_activity'] = time();
}

function session_warning_active(): bool
{
    return (bool) ($_SESSION['session_warning'] ?? false);
}

function session_expired(): bool
{
    return (bool) ($_SESSION['session_expired'] ?? false);
}

function clear_session_warning(): void
{
    $_SESSION['session_warning'] = false;
    $_SESSION['session_expired'] = false;
}

function session_config(): array
{
    return [
        'timeoutSeconds' => SESSION_TIMEOUT_SECONDS,
        'warningSeconds' => SESSION_WARNING_SECONDS,
        'lastActivity' => (int) ($_SESSION['last_activity'] ?? time()),
        'warningActive' => session_warning_active(),
    ];
}

function logout_user_if_defined(): void
{
    if (function_exists('logout_user')) {
        logout_user();
    } else {
        $_SESSION = [];
        session_destroy();
    }
}

enforce_session_timeout();
