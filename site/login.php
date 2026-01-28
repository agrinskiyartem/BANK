<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$errors = [];
$form = [
    'login' => '',
    'password' => '',
];

if (is_logged_in()) {
    $user = current_user();
    if ($user && $user['role'] === 'admin') {
        redirect('/site/admin.php');
    }
    redirect('/site/client.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['login'] = trim((string) ($_POST['login'] ?? ''));
    $form['password'] = (string) ($_POST['password'] ?? '');

    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }

    validate_required($form['login'], 'Логин', $errors);
    validate_length($form['login'], 'Логин', $errors, 3, 50);

    validate_required($form['password'], 'Пароль', $errors);
    validate_length($form['password'], 'Пароль', $errors, 6, 255);

    if (empty($errors)) {
        if (authenticate_user($form['login'], $form['password'])) {
            $user = current_user();
            if ($user && $user['role'] === 'admin') {
                redirect('/site/admin.php');
            }
            redirect('/site/client.php');
        }
    }

    foreach ($errors as $error) {
        set_flash('error', $error);
    }
}

render_header('Вход на сайт');
?>
<section class="card">
  <h2>Авторизация</h2>
  <p>Введите логин и пароль для входа в личный кабинет.</p>

  <div id="site-login-error" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="site-login-form" method="post" action="/site/login.php">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">

    <label>
      Логин
      <input type="text" name="login" value="<?= sanitize($form['login']) ?>" autocomplete="username" required minlength="3" maxlength="50">
    </label>

    <label>
      Пароль
      <input type="password" name="password" value="" autocomplete="current-password" required minlength="6" maxlength="255">
    </label>

    <button type="submit" style="margin-top: 16px;">Войти</button>
  </form>
</section>

<script>
  (function () {
    const form = document.getElementById('site-login-form');
    const errorBox = document.getElementById('site-login-error');

    if (!form || !errorBox) {
      return;
    }

    form.addEventListener('submit', (event) => {
      const errors = [];
      const loginValue = form.login.value.trim();
      const passwordValue = form.password.value;

      if (loginValue.length < 3) {
        errors.push('Введите логин длиной от 3 символов.');
      }
      if (passwordValue.length < 6) {
        errors.push('Введите пароль длиной от 6 символов.');
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
