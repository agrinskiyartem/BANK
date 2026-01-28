<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';

$errors = [];
$form = [
    'atm_id' => '',
    'card_number' => '',
    'pin' => '',
];

if (isset($_GET['msg'])) {
    $message = (string) $_GET['msg'];
    if ($message === 'logout') {
        set_flash('success', 'Сеанс завершён. Войдите снова, чтобы продолжить.');
    } elseif ($message !== '') {
        set_flash('warning', 'Получено сообщение: ' . $message);
    }
}

$atms = db()->query('SELECT atms.id, atms.name, atms.address, atms.bank_owner_id, banks.name AS bank_name FROM atms JOIN banks ON banks.id = atms.bank_owner_id WHERE atms.is_active = 1 ORDER BY banks.name, atms.name')
    ->fetchAll();

$atmMap = [];
foreach ($atms as $atm) {
    $atmMap[(int) $atm['id']] = $atm;
}

$selectedAtmId = get_last_atm_id();
$requestAtmId = null;

if (isset($_GET['atm_id'])) {
    $requestAtmId = (string) $_GET['atm_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['atm_id'] = (string) ($_POST['atm_id'] ?? '');
    $form['card_number'] = preg_replace('/\s+/', '', (string) ($_POST['card_number'] ?? ''));
    $form['pin'] = trim((string) ($_POST['pin'] ?? ''));

    $requestAtmId = $form['atm_id'];

    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

if ($requestAtmId !== null && $requestAtmId !== '') {
    if (filter_var($requestAtmId, FILTER_VALIDATE_INT) !== false) {
        $candidateId = (int) $requestAtmId;
        if (isset($atmMap[$candidateId])) {
            $selectedAtmId = $candidateId;
            set_last_atm_id($candidateId);
        } else {
            $errors[] = 'Выбранный банкомат недоступен.';
        }
    } else {
        $errors[] = 'Некорректный идентификатор банкомата.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_required($form['atm_id'], 'Банкомат', $errors);
    if ($form['atm_id'] !== '') {
        validate_int($form['atm_id'], 'Банкомат', $errors, 1);
    }

    validate_required($form['card_number'], 'Номер карты', $errors);
    if (!preg_match('/^\d{16}$/', $form['card_number'])) {
        $errors[] = 'Номер карты должен содержать 16 цифр.';
    }

    validate_required($form['pin'], 'PIN', $errors);
    if (!preg_match('/^\d{4}$/', $form['pin'])) {
        $errors[] = 'PIN должен содержать 4 цифры.';
    }

    if (empty($errors) && $selectedAtmId === null) {
        $errors[] = 'Выберите банкомат из списка.';
    }

    if (empty($errors)) {
        $cardStmt = db()->prepare(
            'SELECT cards.id, cards.pin_hash, cards.is_blocked, cards.expires_at, cards.client_id, clients.full_name '
            . 'FROM cards '
            . 'JOIN clients ON clients.id = cards.client_id '
            . 'WHERE cards.card_number = :card_number '
            . 'LIMIT 1'
        );
        $cardStmt->execute(['card_number' => $form['card_number']]);
        $card = $cardStmt->fetch();

        if (!$card || !password_verify($form['pin'], $card['pin_hash'])) {
            $errors[] = 'Неверный номер карты или PIN.';
        } elseif ((int) $card['is_blocked'] === 1) {
            $errors[] = 'Карта заблокирована.';
        } elseif ($card['expires_at']) {
            $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d', $card['expires_at']);
            if ($expiresAt && $expiresAt < new DateTimeImmutable('today')) {
                $errors[] = 'Срок действия карты истёк.';
            }
        }

        if (empty($errors)) {
            $atmInfo = $atmMap[$selectedAtmId];
            $_SESSION['atm'] = [
                'id' => (int) $atmInfo['id'],
                'name' => $atmInfo['name'],
                'address' => $atmInfo['address'],
                'bank_owner_id' => (int) $atmInfo['bank_owner_id'],
            ];
            $_SESSION['card'] = [
                'id' => (int) $card['id'],
                'card_number' => $form['card_number'],
                'client_id' => (int) $card['client_id'],
                'client_name' => $card['full_name'],
            ];
            $_SESSION['last_activity'] = time();
            clear_session_warning();

            redirect('/atm/panel.php');
        }
    }

    foreach ($errors as $error) {
        set_flash('error', $error);
    }
}

render_header('Вход в банкомат');
?>
<section class="card">
  <h2>Выберите банкомат</h2>
  <p>Выбранный банкомат будет запомнен на 10 минут.</p>

  <label for="atm-select">
    Банкомат
    <select id="atm-select" name="atm_id" form="atm-login-form" required data-atm-select>
      <option value="">Выберите банкомат</option>
      <?php foreach ($atms as $atm): ?>
        <option value="<?= (int) $atm['id'] ?>" <?= $selectedAtmId === (int) $atm['id'] ? 'selected' : '' ?>>
          <?= sanitize($atm['bank_name'] . ' — ' . $atm['name'] . ' (' . $atm['address'] . ')') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
</section>

<section class="card" style="margin-top: 24px;">
  <h2>Авторизация по карте</h2>
  <div id="atm-login-error" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="atm-login-form" method="post" action="/atm/login.php">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">

    <label>
      Номер карты
      <input type="text" name="card_number" value="<?= sanitize($form['card_number']) ?>" maxlength="16" inputmode="numeric" autocomplete="cc-number" required pattern="\d{16}">
    </label>

    <label>
      PIN
      <input type="password" name="pin" value="" maxlength="4" inputmode="numeric" autocomplete="off" required pattern="\d{4}">
    </label>

    <button type="submit" style="margin-top: 16px;">Войти</button>
  </form>
</section>

<script>
  (function () {
    const form = document.getElementById('atm-login-form');
    const errorBox = document.getElementById('atm-login-error');
    const atmSelect = document.getElementById('atm-select');

    if (atmSelect) {
      atmSelect.addEventListener('change', () => {
        const value = atmSelect.value;
        if (value) {
          window.location.href = `/atm/login.php?atm_id=${encodeURIComponent(value)}`;
        }
      });
    }

    if (!form || !errorBox) {
      return;
    }

    form.addEventListener('submit', (event) => {
      const errors = [];
      const atmValue = atmSelect ? atmSelect.value.trim() : '';
      const cardValue = form.card_number.value.trim();
      const pinValue = form.pin.value.trim();

      if (!atmValue) {
        errors.push('Выберите банкомат.');
      }
      if (!/^\d{16}$/.test(cardValue)) {
        errors.push('Введите номер карты из 16 цифр.');
      }
      if (!/^\d{4}$/.test(pinValue)) {
        errors.push('Введите PIN из 4 цифр.');
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
