<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_login('/site/login.php');
require_role('admin');

header('Content-Type: application/json; charset=utf-8');

$dateFrom = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
$bankId = isset($_GET['bank_id']) ? trim((string) $_GET['bank_id']) : '';
$atmId = isset($_GET['atm_id']) ? trim((string) $_GET['atm_id']) : '';

$filters = [];
$params = [];

if ($dateFrom !== '') {
    $dateFromValue = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom);
    if ($dateFromValue !== false) {
        $filters[] = 'w.created_at >= :date_from';
        $params['date_from'] = $dateFromValue->format('Y-m-d 00:00:00');
    }
}

if ($dateTo !== '') {
    $dateToValue = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo);
    if ($dateToValue !== false) {
        $filters[] = 'w.created_at <= :date_to';
        $params['date_to'] = $dateToValue->format('Y-m-d 23:59:59');
    }
}

if ($bankId !== '') {
    $bankValue = filter_var($bankId, FILTER_VALIDATE_INT);
    if ($bankValue !== false) {
        $filters[] = '(w.bank_owner_id = :bank_id OR w.bank_issuer_id = :bank_id)';
        $params['bank_id'] = (int) $bankValue;
    }
}

if ($atmId !== '') {
    $atmValue = filter_var($atmId, FILTER_VALIDATE_INT);
    if ($atmValue !== false) {
        $filters[] = 'w.atm_id = :atm_id';
        $params['atm_id'] = (int) $atmValue;
    }
}

$whereSql = '';
if (!empty($filters)) {
    $whereSql = 'WHERE ' . implode(' AND ', $filters);
}

$sql =
    'SELECT w.id, w.created_at, w.amount, w.commission_amount, w.total_amount, w.status, w.mode_safe, w.error_reason, '
    . 'a.name AS atm_name, a.address AS atm_address, '
    . 'owner.name AS bank_owner_name, issuer.name AS bank_issuer_name, '
    . 'c.card_number, cl.full_name AS client_name, u.login AS client_login '
    . 'FROM withdrawals AS w '
    . 'JOIN cards AS c ON c.id = w.card_id '
    . 'JOIN clients AS cl ON cl.id = c.client_id '
    . 'JOIN users AS u ON u.id = cl.user_id '
    . 'JOIN atms AS a ON a.id = w.atm_id '
    . 'JOIN banks AS owner ON owner.id = w.bank_owner_id '
    . 'JOIN banks AS issuer ON issuer.id = w.bank_issuer_id '
    . $whereSql . ' '
    . 'ORDER BY w.created_at DESC';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'items' => $withdrawals,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Ошибка загрузки данных.',
    ], JSON_UNESCAPED_UNICODE);
}
