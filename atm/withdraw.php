<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';

if (empty($_SESSION['atm']) || empty($_SESSION['card'])) {
    set_flash('error', 'Авторизуйтесь через банкомат, чтобы продолжить.');
    redirect('/atm/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Некорректный способ обращения.');
    redirect('/atm/panel.php');
}

$atmSession = $_SESSION['atm'];
$cardSession = $_SESSION['card'];

$safeParam = (string) ($_GET['safe'] ?? '1');
$isSafeMode = $safeParam !== '0';

$errors = [];
$amountRaw = str_replace(',', '.', trim((string) ($_POST['amount'] ?? '')));

try {
    require_csrf_token($_POST['csrf_token'] ?? null);
} catch (RuntimeException $exception) {
    $errors[] = $exception->getMessage();
}

validate_required($amountRaw, 'Сумма', $errors);
if ($amountRaw !== '') {
    validate_decimal($amountRaw, 'Сумма', $errors, 1.00);
}

$operation = null;

if (empty($errors)) {
    $amount = round((float) $amountRaw, 2);

    try {
        $pdo = db();
        if ($isSafeMode) {
            $pdo->beginTransaction();
        }

        $query = 'SELECT accounts.id AS account_id, accounts.balance, accounts.currency, '
            . 'cards.bank_issuer_id, atms.id AS atm_id, atms.name AS atm_name, atms.address AS atm_address, '
            . 'atms.bank_owner_id, issuer.name AS issuer_bank_name, owner.name AS owner_bank_name '
            . 'FROM cards '
            . 'JOIN accounts ON accounts.card_id = cards.id '
            . 'JOIN atms ON atms.id = :atm_id '
            . 'JOIN banks AS issuer ON issuer.id = cards.bank_issuer_id '
            . 'JOIN banks AS owner ON owner.id = atms.bank_owner_id '
            . 'WHERE cards.id = :card_id '
            . 'LIMIT 1';

        if ($isSafeMode) {
            $query .= ' FOR UPDATE';
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'atm_id' => (int) $atmSession['id'],
            'card_id' => (int) $cardSession['id'],
        ]);
        $panel = $stmt->fetch();

        if (!$panel) {
            throw new RuntimeException('Данные карты или банкомата не найдены.');
        }

        $balance = (float) $panel['balance'];
        $feeRate = (int) $panel['bank_issuer_id'] === (int) $panel['bank_owner_id'] ? 0.0 : 0.012;
        $commission = round($amount * $feeRate, 2);
        $total = round($amount + $commission, 2);

        if ($total <= 0.0) {
            throw new RuntimeException('Сумма должна быть больше 0.');
        }

        if ($isSafeMode) {
            if ($balance < $total) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Недостаточно средств для снятия.';
            } else {
                $newBalance = round($balance - $total, 2);
                $createdAt = db_now();

                $updateStmt = $pdo->prepare('UPDATE accounts SET balance = :balance WHERE id = :account_id');
                $updateStmt->execute([
                    'balance' => $newBalance,
                    'account_id' => (int) $panel['account_id'],
                ]);

                $insertStmt = $pdo->prepare(
                    'INSERT INTO withdrawals '
                    . '(card_id, atm_id, bank_issuer_id, bank_owner_id, amount, commission_amount, total_amount, mode_safe, status, created_at) '
                    . 'VALUES (:card_id, :atm_id, :bank_issuer_id, :bank_owner_id, :amount, :commission_amount, :total_amount, :mode_safe, :status, :created_at)'
                );
                $insertStmt->execute([
                    'card_id' => (int) $cardSession['id'],
                    'atm_id' => (int) $atmSession['id'],
                    'bank_issuer_id' => (int) $panel['bank_issuer_id'],
                    'bank_owner_id' => (int) $panel['bank_owner_id'],
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'total_amount' => $total,
                    'mode_safe' => 1,
                    'status' => 'success',
                    'created_at' => $createdAt,
                ]);

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                $operation = [
                    'amount' => $amount,
                    'commission' => $commission,
                    'total' => $total,
                    'new_balance' => $newBalance,
                    'currency' => $panel['currency'],
                    'created_at' => $createdAt,
                    'atm_name' => $panel['atm_name'],
                    'atm_address' => $panel['atm_address'],
                    'issuer_bank_name' => $panel['issuer_bank_name'],
                    'owner_bank_name' => $panel['owner_bank_name'],
                ];
            }
        } else {
            $createdAt = db_now();

            $updateStmt = $pdo->prepare(
                'UPDATE accounts SET balance = balance - :total '
                . 'WHERE id = :account_id AND balance >= :total'
            );
            $updateStmt->execute([
                'total' => $total,
                'account_id' => (int) $panel['account_id'],
            ]);

            if ($updateStmt->rowCount() === 0) {
                $errors[] = 'Недостаточно средств для снятия.';
            } else {
                $balanceStmt = $pdo->prepare('SELECT balance FROM accounts WHERE id = :account_id');
                $balanceStmt->execute([
                    'account_id' => (int) $panel['account_id'],
                ]);
                $balanceRow = $balanceStmt->fetch();
                if (!$balanceRow) {
                    throw new RuntimeException('Не удалось получить баланс после операции.');
                }
                $newBalance = (float) $balanceRow['balance'];

                $insertStmt = $pdo->prepare(
                    'INSERT INTO withdrawals '
                    . '(card_id, atm_id, bank_issuer_id, bank_owner_id, amount, commission_amount, total_amount, mode_safe, status, created_at) '
                    . 'VALUES (:card_id, :atm_id, :bank_issuer_id, :bank_owner_id, :amount, :commission_amount, :total_amount, :mode_safe, :status, :created_at)'
                );
                $insertStmt->execute([
                    'card_id' => (int) $cardSession['id'],
                    'atm_id' => (int) $atmSession['id'],
                    'bank_issuer_id' => (int) $panel['bank_issuer_id'],
                    'bank_owner_id' => (int) $panel['bank_owner_id'],
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'total_amount' => $total,
                    'mode_safe' => 0,
                    'status' => 'success',
                    'created_at' => $createdAt,
                ]);

                $operation = [
                    'amount' => $amount,
                    'commission' => $commission,
                    'total' => $total,
                    'new_balance' => $newBalance,
                    'currency' => $panel['currency'],
                    'created_at' => $createdAt,
                    'atm_name' => $panel['atm_name'],
                    'atm_address' => $panel['atm_address'],
                    'issuer_bank_name' => $panel['issuer_bank_name'],
                    'owner_bank_name' => $panel['owner_bank_name'],
                ];
            }
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Не удалось выполнить операцию. Попробуйте позже.';
    }
}





// render_header('Снятие наличных');
render_header('Снятие наличных', 'atm-body');




?>
<!-- <section class="card"> -->
<div class="atm-terminal">
  <section class="card">



  <h2>Результат операции</h2>

  <?php if (!empty($errors)): ?>
    <div class="flash__message flash__message--error" style="margin-bottom: 16px;">
      <?php foreach ($errors as $error): ?>
        <div><?= sanitize($error) ?></div>
      <?php endforeach; ?>
    </div>
    <a href="/atm/panel.php">Вернуться в панель</a>
  <?php elseif ($operation): ?>
    <dl>
      <dt>Сумма</dt>
      <dd><?= number_format($operation['amount'], 2, '.', ' ') ?> <?= sanitize($operation['currency']) ?></dd>
      <dt>Комиссия</dt>
      <dd><?= number_format($operation['commission'], 2, '.', ' ') ?> <?= sanitize($operation['currency']) ?></dd>
      <dt>Итого списано</dt>
      <dd><?= number_format($operation['total'], 2, '.', ' ') ?> <?= sanitize($operation['currency']) ?></dd>
      <dt>Новый баланс</dt>
      <dd><?= number_format($operation['new_balance'], 2, '.', ' ') ?> <?= sanitize($operation['currency']) ?></dd>
      <dt>Дата и время</dt>
      <dd><?= sanitize($operation['created_at']) ?></dd>
      <dt>Банкомат</dt>
      <dd><?= sanitize($operation['atm_name']) ?> (<?= sanitize($operation['atm_address']) ?>)</dd>
      <dt>Банк-владелец</dt>
      <dd><?= sanitize($operation['owner_bank_name']) ?></dd>
      <dt>Банк-эмитент</dt>
      <dd><?= sanitize($operation['issuer_bank_name']) ?></dd>
    </dl>
    <a href="/atm/panel.php" style="display: inline-block; margin-top: 16px;">Вернуться в панель</a>
  <?php endif; ?>
</section>

<!--  -->
</div>

<?php
render_footer();
