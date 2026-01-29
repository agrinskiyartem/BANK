<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';

function authenticate_user(string $login, string $password): bool
{
    $login = trim($login);

    if ($login === '' || $password === '') {
        set_flash('error', 'Введите логин и пароль.');
        return false;
    }

    $stmt = db()->prepare('SELECT id, login, password_hash, role, is_active FROM users WHERE login = :login LIMIT 1');
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        set_flash('error', 'Пользователь не найден или заблокирован.');
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        set_flash('error', 'Неверный логин или пароль.');
        return false;
    }

    $clientId = null;
    if ($user['role'] === 'client') {
        $clientStmt = db()->prepare('SELECT id FROM clients WHERE user_id = :user_id LIMIT 1');
        $clientStmt->execute(['user_id' => $user['id']]);
        $clientId = $clientStmt->fetchColumn() ?: null;
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'login' => $user['login'],
        'role' => $user['role'],
        'client_id' => $clientId ? (int) $clientId : null,
    ];

    $_SESSION['last_activity'] = time();
    clear_session_warning();

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(string $redirectTo = '/index.php'): void
{
    if (!is_logged_in()) {
        set_flash('error', 'Пожалуйста, войдите в систему.');
        redirect($redirectTo);
    }
}

function require_role(string ...$roles): void
{
    $user = current_user();

    if (!$user || !in_array($user['role'], $roles, true)) {
        set_flash('error', 'Недостаточно прав.');
        redirect('/index.php');
    }
}
