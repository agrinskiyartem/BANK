<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

function get_flash_messages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function validate_required(string $value, string $fieldLabel, array &$errors): void
{
    if (trim($value) === '') {
        $errors[] = sprintf('Поле "%s" обязательно.', $fieldLabel);
    }
}

function validate_int($value, string $fieldLabel, array &$errors, int $min = null, int $max = null): void
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        $errors[] = sprintf('Поле "%s" должно быть целым числом.', $fieldLabel);
        return;
    }

    $intValue = (int) $value;
    if ($min !== null && $intValue < $min) {
        $errors[] = sprintf('Поле "%s" должно быть не меньше %d.', $fieldLabel, $min);
    }
    if ($max !== null && $intValue > $max) {
        $errors[] = sprintf('Поле "%s" должно быть не больше %d.', $fieldLabel, $max);
    }
}

function validate_decimal($value, string $fieldLabel, array &$errors, float $min = null, float $max = null): void
{
    if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
        $errors[] = sprintf('Поле "%s" должно быть числом.', $fieldLabel);
        return;
    }

    $floatValue = (float) $value;
    if ($min !== null && $floatValue < $min) {
        $errors[] = sprintf('Поле "%s" должно быть не меньше %.2f.', $fieldLabel, $min);
    }
    if ($max !== null && $floatValue > $max) {
        $errors[] = sprintf('Поле "%s" должно быть не больше %.2f.', $fieldLabel, $max);
    }
}

function validate_length(string $value, string $fieldLabel, array &$errors, int $min = 0, int $max = 255): void
{
    $length = mb_strlen($value);
    if ($length < $min) {
        $errors[] = sprintf('Поле "%s" должно быть не короче %d символов.', $fieldLabel, $min);
    }
    if ($length > $max) {
        $errors[] = sprintf('Поле "%s" должно быть не длиннее %d символов.', $fieldLabel, $max);
    }
}

function set_last_atm_id(int $atmId): void
{
    setcookie('last_atm_id', (string) $atmId, [
        'expires' => time() + 600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function get_last_atm_id(): ?int
{
    if (!isset($_COOKIE['last_atm_id'])) {
        return null;
    }

    $value = filter_var($_COOKIE['last_atm_id'], FILTER_VALIDATE_INT);
    return $value === false ? null : (int) $value;
}

function render_header(string $title = 'Bank ATM'): void
{
    $config = session_config();
    $flashMessages = get_flash_messages();
    $csrfToken = csrf_token();
    $warningClass = $config['warningActive'] ? 'session-warning--active' : '';

    echo '<!DOCTYPE html>'; 
    echo '<html lang="ru">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<meta name="csrf-token" content="' . sanitize($csrfToken) . '">';
    echo '<title>' . sanitize($title) . '</title>';
    echo '<link rel="stylesheet" href="/assets/style.css">';
    echo '</head>';
    echo '<body>';
    echo '<div class="app">';
    echo '<header class="app__header">';
    echo '<h1 class="app__title">' . sanitize($title) . '</h1>';
    echo '</header>';

    echo '<div id="session-warning" class="session-warning ' . $warningClass . '">';
    echo '<p>Сессия скоро завершится из-за неактивности.</p>';
    echo '</div>';

    if (!empty($flashMessages)) {
        echo '<div class="flash">';
        foreach ($flashMessages as $type => $messages) {
            foreach ($messages as $message) {
                echo '<div class="flash__message flash__message--' . sanitize($type) . '">';
                echo sanitize($message);
                echo '</div>';
            }
        }
        echo '</div>';
    }

    echo '<main class="app__content">';
    echo '<script>'; 
    echo 'window.APP_CONFIG = ' . json_encode($config, JSON_UNESCAPED_UNICODE) . ';';
    echo '</script>';
    echo '<script src="/assets/app.js" defer></script>';
}

function render_footer(): void
{
    echo '</main>';
    echo '<footer class="app__footer">';
    echo '<span>Bank ATM &copy; ' . date('Y') . '</span>';
    echo '</footer>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
}
