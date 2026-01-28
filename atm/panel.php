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

$atmSession = $_SESSION['atm'];
$cardSession = $_SESSION['card'];

$errors = [];
$amount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = str_replace(',', '.', trim((string) ($_POST['amount'] ?? '')));

    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }

    validate_required($amount, 'Сумма', $errors);
    if ($amount !== '') {
        validate_decimal($amount, 'Сумма', $errors, 1.00);
    }

    foreach ($errors as $error) {
        set_flash('error', $error);
    }
}

$stmt = db()->prepare(
    'SELECT cards.card_number, cards.bank_issuer_id, clients.full_name, accounts.balance, accounts.currency, '
    . 'issuer.name AS issuer_bank_name, atms.name AS atm_name, atms.address AS atm_address, '
    . 'owner.id AS owner_bank_id, owner.name AS owner_bank_name '
    . 'FROM cards '
    . 'JOIN clients ON clients.id = cards.client_id '
    . 'JOIN accounts ON accounts.card_id = cards.id '
    . 'JOIN banks AS issuer ON issuer.id = cards.bank_issuer_id '
    . 'JOIN atms ON atms.id = :atm_id '
    . 'JOIN banks AS owner ON owner.id = atms.bank_owner_id '
    . 'WHERE cards.id = :card_id '
    . 'LIMIT 1'
);
$stmt->execute([
    'atm_id' => (int) $atmSession['id'],
    'card_id' => (int) $cardSession['id'],
]);
$panel = $stmt->fetch();

if (!$panel) {
    set_flash('error', 'Данные карты или банкомата не найдены.');
    redirect('/atm/login.php');
}

$cardNumber = (string) $panel['card_number'];
$cardMasked = $cardNumber;
if (preg_match('/^\d{8,}$/', $cardNumber)) {
    $cardMasked = substr($cardNumber, 0, 4) . ' **** **** ' . substr($cardNumber, -4);
}

$isSameBank = (int) $panel['bank_issuer_id'] === (int) $panel['owner_bank_id'];
$feeRate = $isSameBank ? 0.0 : 0.012;
$feePercent = $isSameBank ? '0%' : '1.2%';
$issuanceText = $isSameBank ? 'да' : 'нет';

render_header('Панель банкомата');
?>
<section class="card">
  <h2>Данные клиента</h2>
  <dl>
    <dt>ФИО</dt>
    <dd><?= sanitize($panel['full_name']) ?></dd>
    <dt>Номер карты</dt>
    <dd><?= sanitize($cardMasked) ?></dd>
    <dt>Баланс</dt>
    <dd><?= number_format((float) $panel['balance'], 2, '.', ' ') ?> <?= sanitize($panel['currency']) ?></dd>
    <dt>Карта эмитирована банком текущего банкомата?</dt>
    <dd><?= sanitize($issuanceText) ?></dd>
  </dl>
</section>

<section class="card" style="margin-top: 24px;">
  <h2>Выбранный банкомат</h2>
  <dl>
    <dt>Название</dt>
    <dd><?= sanitize($panel['atm_name']) ?> (<?= sanitize($panel['atm_address']) ?>)</dd>
    <dt>Банк-владелец</dt>
    <dd><?= sanitize($panel['owner_bank_name']) ?></dd>
    <dt>Банк-эмитент карты</dt>
    <dd><?= sanitize($panel['issuer_bank_name']) ?></dd>
  </dl>
</section>

<section class="card" style="margin-top: 24px;">
  <h2>Снять деньги</h2>
  <p>Комиссия: <?= sanitize($feePercent) ?><?= $isSameBank ? '' : ' (банк-эмитент отличается от банка-владельца банкомата)' ?>.</p>
  <div id="withdraw-error" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="withdraw-form" method="post" action="/atm/withdraw.php" data-fee-rate="<?= htmlspecialchars((string) $feeRate, ENT_QUOTES) ?>">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
    <label>
      Сумма
      <input type="number" name="amount" value="<?= sanitize($amount) ?>" min="1" step="0.01" inputmode="decimal" required>
    </label>
    <p id="fee-preview" style="margin-top: 8px; color: #64748b;"></p>
    <button type="submit" style="margin-top: 16px;">Снять</button>
  </form>
</section>

<script>
  (function () {
    const form = document.getElementById('withdraw-form');
    const errorBox = document.getElementById('withdraw-error');
    const feePreview = document.getElementById('fee-preview');

    if (!form) {
      return;
    }

    const amountInput = form.querySelector('input[name="amount"]');
    const feeRate = Number(form.dataset.feeRate || 0);

    function formatMoney(value) {
      return value.toFixed(2).replace('.', ',');
    }

    function updateFeePreview() {
      if (!amountInput) {
        return;
      }
      const value = amountInput.value.replace(',', '.');
      const amount = Number(value);

      if (!Number.isFinite(amount) || amount <= 0) {
        feePreview.textContent = '';
        return;
      }

      const feeAmount = amount * feeRate;
      const totalAmount = amount + feeAmount;
      feePreview.textContent = `Комиссия: ${formatMoney(feeAmount)} ₽, итого: ${formatMoney(totalAmount)} ₽.`;
    }

    if (amountInput) {
      amountInput.addEventListener('input', updateFeePreview);
      updateFeePreview();
    }

    if (!errorBox || !amountInput) {
      return;
    }

    form.addEventListener('submit', (event) => {
      const errors = [];
      const rawValue = amountInput.value.trim().replace(',', '.');
      const amount = Number(rawValue);

      if (!rawValue) {
        errors.push('Введите сумму.');
      } else if (!Number.isFinite(amount)) {
        errors.push('Сумма должна быть числом.');
      } else if (amount <= 0) {
        errors.push('Сумма должна быть больше 0.');
      }

      if (errors.length > 0) {
        event.preventDefault();
        errorBox.textContent = errors.join(' ');
        errorBox.style.display = 'block';
      } else {
        errorBox.textContent = '';
        errorBox.style.display = 'none';
      }
    });
  })();
</script>
<?php
render_footer();
