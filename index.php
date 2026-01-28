<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

$errors = [];
$form = [
    'full_name' => '',
    'login' => '',
    'password' => '',
    'card_number' => '',
    'bank_issuer_id' => '',
    'pin' => '',
];

if (isset($_GET['msg'])) {
    $message = (string) $_GET['msg'];
    if ($message === 'registered') {
        set_flash('success', 'Регистрация завершена. Теперь войдите в систему.');
    } elseif ($message !== '') {
        set_flash('warning', 'Получено сообщение: ' . $message);
    }
}

if (isset($_GET['error'])) {
    $error = (string) $_GET['error'];
    if ($error !== '') {
        set_flash('error', 'Ошибка: ' . $error);
    }
}

$banks = db()->query('SELECT id, name FROM banks ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $form['login'] = trim((string) ($_POST['login'] ?? ''));
    $form['password'] = (string) ($_POST['password'] ?? '');
    $form['card_number'] = preg_replace('/\s+/', '', (string) ($_POST['card_number'] ?? ''));
    $form['bank_issuer_id'] = (string) ($_POST['bank_issuer_id'] ?? '');
    $form['pin'] = trim((string) ($_POST['pin'] ?? ''));

    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }

    validate_required($form['full_name'], 'ФИО', $errors);
    validate_length($form['full_name'], 'ФИО', $errors, 3, 255);

    validate_required($form['login'], 'Логин', $errors);
    validate_length($form['login'], 'Логин', $errors, 3, 50);

    validate_required($form['password'], 'Пароль', $errors);
    validate_length($form['password'], 'Пароль', $errors, 6, 255);

    validate_required($form['card_number'], 'Номер карты', $errors);
    if (!preg_match('/^\d{16}$/', $form['card_number'])) {
        $errors[] = 'Номер карты должен содержать 16 цифр.';
    }

    validate_required($form['bank_issuer_id'], 'Банк-эмитент', $errors);
    if ($form['bank_issuer_id'] !== '') {
        validate_int($form['bank_issuer_id'], 'Банк-эмитент', $errors, 1);
    }

    validate_required($form['pin'], 'PIN', $errors);
    if (!preg_match('/^\d{4}$/', $form['pin'])) {
        $errors[] = 'PIN должен содержать 4 цифры.';
    }

    if (empty($errors)) {
        $existingLogin = db()->prepare('SELECT COUNT(*) FROM users WHERE login = :login');
        $existingLogin->execute(['login' => $form['login']]);
        if ((int) $existingLogin->fetchColumn() > 0) {
            $errors[] = 'Логин уже используется.';
        }

        $existingCard = db()->prepare('SELECT COUNT(*) FROM cards WHERE card_number = :card');
        $existingCard->execute(['card' => $form['card_number']]);
        if ((int) $existingCard->fetchColumn() > 0) {
            $errors[] = 'Номер карты уже зарегистрирован.';
        }

        $bankExists = db()->prepare('SELECT COUNT(*) FROM banks WHERE id = :id');
        $bankExists->execute(['id' => (int) $form['bank_issuer_id']]);
        if ((int) $bankExists->fetchColumn() === 0) {
            $errors[] = 'Выберите банк-эмитент из списка.';
        }
    }

    if (empty($errors)) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare('INSERT INTO users (login, password_hash, role, created_at, updated_at, is_active) VALUES (:login, :password_hash, :role, :created_at, :updated_at, 1)');
            $now = db_now();
            $userStmt->execute([
                'login' => $form['login'],
                'password_hash' => password_hash($form['password'], PASSWORD_DEFAULT),
                'role' => 'client',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $userId = (int) $pdo->lastInsertId();

            $clientStmt = $pdo->prepare('INSERT INTO clients (user_id, full_name, created_at, updated_at) VALUES (:user_id, :full_name, :created_at, :updated_at)');
            $clientStmt->execute([
                'user_id' => $userId,
                'full_name' => $form['full_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $clientId = (int) $pdo->lastInsertId();
            $expiresAt = (new DateTimeImmutable('+3 years'))->format('Y-m-d');

            $cardStmt = $pdo->prepare('INSERT INTO cards (card_number, pin_hash, bank_issuer_id, client_id, created_at, updated_at, expires_at, is_blocked) VALUES (:card_number, :pin_hash, :bank_issuer_id, :client_id, :created_at, :updated_at, :expires_at, 0)');
            $cardStmt->execute([
                'card_number' => $form['card_number'],
                'pin_hash' => password_hash($form['pin'], PASSWORD_DEFAULT),
                'bank_issuer_id' => (int) $form['bank_issuer_id'],
                'client_id' => $clientId,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            $cardId = (int) $pdo->lastInsertId();

            $accountStmt = $pdo->prepare('INSERT INTO accounts (card_id, balance, currency, updated_at) VALUES (:card_id, :balance, :currency, :updated_at)');
            $accountStmt->execute([
                'card_id' => $cardId,
                'balance' => 0.00,
                'currency' => 'RUB',
                'updated_at' => $now,
            ]);

            $pdo->commit();
            redirect('/index.php?msg=registered');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Не удалось завершить регистрацию. Попробуйте позже.';
        }
    }

    foreach ($errors as $error) {
        set_flash('error', $error);
    }
}

render_header('Bank ATM');
?>
<section class="card" style="margin-bottom: 24px;">
  <h2>Выберите вход</h2>
  <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 16px;">
    <a class="btn" href="/atm/login.php" style="padding: 18px 28px; font-size: 1.1rem;">Банкомат</a>
    <a class="btn" href="/site/login.php" style="padding: 18px 28px; font-size: 1.1rem;">Сайт</a>
  </div>
</section>

<section class="card">
  <h2>Регистрация клиента</h2>
  <p>Если вы ещё не зарегистрированы, заполните форму ниже.</p>

  <div id="client-error" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="registration-form" method="post" action="/index.php">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">

    <label>
      ФИО
      <input type="text" name="full_name" value="<?= sanitize($form['full_name']) ?>" required>
    </label>

    <label>
      Логин
      <input type="text" name="login" value="<?= sanitize($form['login']) ?>" required>
    </label>

    <label>
      Пароль
      <input type="password" name="password" required>
    </label>

    <label>
      Номер карты
      <input type="text" name="card_number" value="<?= sanitize($form['card_number']) ?>" maxlength="16" required>
    </label>

    <label>
      Банк-эмитент
      <select name="bank_issuer_id" required>
        <option value="">Выберите банк</option>
        <?php foreach ($banks as $bank) : ?>
          <option value="<?= (int) $bank['id'] ?>" <?= $form['bank_issuer_id'] == $bank['id'] ? 'selected' : '' ?>>
            <?= sanitize($bank['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      PIN
      <input type="password" name="pin" maxlength="4" required>
    </label>

    <div style="margin-top: 16px;">
      <button type="submit">Зарегистрироваться</button>
    </div>
  </form>
</section>

<script>
  (function () {
    const form = document.getElementById('registration-form');
    const errorBox = document.getElementById('client-error');

    if (!form) {
      return;
    }

    form.addEventListener('submit', function (event) {
      const fullName = form.elements.full_name.value.trim();
      const login = form.elements.login.value.trim();
      const password = form.elements.password.value;
      const cardNumber = form.elements.card_number.value.replace(/\s+/g, '');
      const bankIssuer = form.elements.bank_issuer_id.value;
      const pin = form.elements.pin.value.trim();

      const errors = [];

      if (fullName.length < 3) {
        errors.push('Введите ФИО (минимум 3 символа).');
      }
      if (login.length < 3) {
        errors.push('Логин должен быть не короче 3 символов.');
      }
      if (password.length < 6) {
        errors.push('Пароль должен быть не короче 6 символов.');
      }
      if (!/^\d{16}$/.test(cardNumber)) {
        errors.push('Номер карты должен содержать 16 цифр.');
      }
      if (!bankIssuer) {
        errors.push('Выберите банк-эмитент.');
      }
      if (!/^\d{4}$/.test(pin)) {
        errors.push('PIN должен содержать 4 цифры.');
      }

      if (errors.length > 0) {
        event.preventDefault();
        errorBox.textContent = errors.join(' ');
        errorBox.style.display = 'block';
      } else {
        errorBox.style.display = 'none';
      }
    });
  })();
</script>
<?php
render_footer();
