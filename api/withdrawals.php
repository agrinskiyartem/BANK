<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_login('/site/login.php');
require_role('client');

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$clientId = $user['client_id'] ?? null;
if ($clientId === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Client not found.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dateFrom = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
$bankOwnerId = isset($_GET['bank_owner_id']) ? trim((string) $_GET['bank_owner_id']) : '';
$bankId = isset($_GET['bank_id']) ? trim((string) $_GET['bank_id']) : '';

$filters = [];
$params = ['client_id' => $clientId];

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

if ($bankOwnerId !== '' || $bankId !== '') {
    $rawBankValue = $bankOwnerId !== '' ? $bankOwnerId : $bankId;
    $bankOwnerValue = filter_var($rawBankValue, FILTER_VALIDATE_INT);
    if ($bankOwnerValue !== false) {
        $filters[] = 'w.bank_owner_id = :bank_owner_id';
        $params['bank_owner_id'] = (int) $bankOwnerValue;
    }
}

$whereSql = '';
if (!empty($filters)) {
    $whereSql = ' AND ' . implode(' AND ', $filters);
}

$sql =
    'SELECT w.created_at, w.amount, w.commission_amount, w.total_amount, '
    . 'a.name AS atm_name, a.address AS atm_address, '
    . 'owner.name AS bank_owner_name, issuer.name AS bank_issuer_name '
    . 'FROM withdrawals AS w '
    . 'JOIN cards AS c ON c.id = w.card_id '
    . 'JOIN atms AS a ON a.id = w.atm_id '
    . 'JOIN banks AS owner ON owner.id = w.bank_owner_id '
    . 'JOIN banks AS issuer ON issuer.id = w.bank_issuer_id '
    . 'WHERE c.client_id = :client_id' . $whereSql . ' '
    . 'ORDER BY w.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$withdrawals = $stmt->fetchAll();

$response = [
    'ok' => true,
    'items' => $withdrawals,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
