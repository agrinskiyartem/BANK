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

$filters = ["w.status = 'success'"];
$params = [];
$errors = [];

if ($dateFrom !== '') {
    $dateFromValue = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom);
    if ($dateFromValue === false) {
        $errors[] = 'Неверный формат даты "с".';
    } else {
        $filters[] = 'w.created_at >= :date_from';
        $params['date_from'] = $dateFromValue->format('Y-m-d 00:00:00');
    }
}

if ($dateTo !== '') {
    $dateToValue = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo);
    if ($dateToValue === false) {
        $errors[] = 'Неверный формат даты "по".';
    } else {
        $filters[] = 'w.created_at <= :date_to';
        $params['date_to'] = $dateToValue->format('Y-m-d 23:59:59');
    }
}

if ($dateFrom !== '' && $dateTo !== '') {
    $fromCheck = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom);
    $toCheck = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo);
    if ($fromCheck !== false && $toCheck !== false && $fromCheck > $toCheck) {
        $errors[] = 'Дата "с" не может быть позже даты "по".';
    }
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$whereSql = '';
if (!empty($filters)) {
    $whereSql = 'WHERE ' . implode(' AND ', $filters);
}

try {
    $commissionSql =
        'SELECT owner.id AS bank_id, owner.name AS bank_name, '
        . 'SUM(w.commission_amount) AS commission_total, COUNT(w.id) AS operations_count '
        . 'FROM withdrawals AS w '
        . 'JOIN atms AS a ON a.id = w.atm_id '
        . 'JOIN banks AS owner ON owner.id = w.bank_owner_id '
        . 'JOIN banks AS issuer ON issuer.id = w.bank_issuer_id '
        . 'JOIN cards AS c ON c.id = w.card_id '
        . 'JOIN users AS u ON u.id = c.client_id '
        . $whereSql . ' '
        . 'GROUP BY owner.id, owner.name '
        . 'ORDER BY commission_total DESC';
    $commissionStmt = db()->prepare($commissionSql);
    $commissionStmt->execute($params);
    $commissionByBank = $commissionStmt->fetchAll();

    $topAtmsSql =
        'SELECT a.id AS atm_id, a.name AS atm_name, a.address AS atm_address, '
        . 'owner.name AS bank_name, COUNT(w.id) AS operations_count '
        . 'FROM withdrawals AS w '
        . 'JOIN atms AS a ON a.id = w.atm_id '
        . 'JOIN banks AS owner ON owner.id = a.bank_owner_id '
        . $whereSql . ' '
        . 'GROUP BY a.id, a.name, a.address, owner.name '
        . 'ORDER BY operations_count DESC '
        . 'LIMIT 5';
    $topAtmsStmt = db()->prepare($topAtmsSql);
    $topAtmsStmt->execute($params);
    $topAtms = $topAtmsStmt->fetchAll();

    $dailySql =
        'SELECT DATE(w.created_at) AS label, COUNT(w.id) AS operations_count, '
        . 'SUM(w.amount) AS amount_total '
        . 'FROM withdrawals AS w '
        . $whereSql . ' '
        . 'GROUP BY DATE(w.created_at) '
        . 'ORDER BY label';
    $dailyStmt = db()->prepare($dailySql);
    $dailyStmt->execute($params);
    $dailyRows = $dailyStmt->fetchAll();

    $monthlySql =
        "SELECT DATE_FORMAT(w.created_at, '%Y-%m') AS label, COUNT(w.id) AS operations_count, "
        . 'SUM(w.amount) AS amount_total '
        . 'FROM withdrawals AS w '
        . $whereSql . ' '
        . "GROUP BY DATE_FORMAT(w.created_at, '%Y-%m') "
        . 'ORDER BY label';
    $monthlyStmt = db()->prepare($monthlySql);
    $monthlyStmt->execute($params);
    $monthlyRows = $monthlyStmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ],
        'commission_by_bank' => $commissionByBank,
        'top_atms' => $topAtms,
        'daily' => $dailyRows,
        'monthly' => $monthlyRows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Ошибка загрузки аналитики.',
    ], JSON_UNESCAPED_UNICODE);
}
