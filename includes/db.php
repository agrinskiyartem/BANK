<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'bank_atm';
    $user = getenv('DB_USER') ?: 'bank_user';
    $pass = getenv('DB_PASS') ?: 'bank_pass';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $exception) {
        error_log('Database connection error: ' . $exception->getMessage());
        throw new RuntimeException('Database connection failed.');
    }

    return $pdo;
}

function db_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function users_has_full_name(): bool
{
    static $hasFullName = null;

    if ($hasFullName !== null) {
        return $hasFullName;
    }

    $stmt = db()->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() '
        . 'AND table_name = :table AND column_name = :column LIMIT 1'
    );
    $stmt->execute(['table' => 'users', 'column' => 'full_name']);
    $hasFullName = (bool) $stmt->fetchColumn();

    return $hasFullName;
}

function users_full_name_sql(string $tableAlias = 'users', string $as = 'full_name'): string
{
    if (users_has_full_name()) {
        return sprintf(
            "COALESCE(NULLIF(%s.full_name, ''), %s.login) AS %s",
            $tableAlias,
            $tableAlias,
            $as
        );
    }

    return sprintf('%s.login AS %s', $tableAlias, $as);
}



