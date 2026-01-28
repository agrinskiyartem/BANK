<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login('/site/login.php');
require_role('admin');

$user = current_user();

$errors = [];
$bank = [
    'id' => null,
    'name' => '',
    'bic' => '',
];

$editId = null;
if (isset($_GET['edit']) || isset($_GET['id'])) {
    $editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($editId !== false && $editId !== null) {
        $stmt = db()->prepare('SELECT id, name, bic FROM banks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $editId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $bank = [
                'id' => (int) $existing['id'],
                'name' => $existing['name'],
                'bic' => $existing['bic'] ?? '',
            ];
        } else {
            set_flash('error', 'Банк не найден.');
            redirect('/site/banks.php');
        }
    } else {
        set_flash('error', 'Некорректный идентификатор банка.');
        redirect('/site/banks.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }

    $bankId = filter_var($_POST['bank_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $bic = strtoupper(trim((string) ($_POST['bic'] ?? '')));
    if ($bic === '') {
        $bic = null;
    }

    validate_required($name, 'Название банка', $errors);
    validate_length($name, 'Название банка', $errors, 2, 255);

    if ($bic !== null) {
        validate_length($bic, 'БИК', $errors, 3, 20);
        if (!preg_match('/^[A-Z0-9]+$/', $bic)) {
            $errors[] = 'Поле "БИК" должно содержать только латинские буквы и цифры.';
        }
    }

    if ($bic !== null) {
        $stmt = db()->prepare('SELECT id FROM banks WHERE bic = :bic AND (:id IS NULL OR id != :id) LIMIT 1');
        $stmt->execute([
            'bic' => $bic,
            'id' => $bankId,
        ]);
        if ($stmt->fetch()) {
            $errors[] = 'Банк с указанным БИК уже существует.';
        }
    }

    if (empty($errors)) {
        $now = db_now();
        $db = db();
        $action = $bankId ? 'update' : 'create';

        try {
            $db->beginTransaction();

            if ($bankId) {
                $updateStmt = $db->prepare(
                    'UPDATE banks SET name = :name, bic = :bic, updated_at = :updated_at WHERE id = :id'
                );
                $updateStmt->execute([
                    'name' => $name,
                    'bic' => $bic,
                    'updated_at' => $now,
                    'id' => $bankId,
                ]);
                $entityId = $bankId;
            } else {
                $insertStmt = $db->prepare(
                    'INSERT INTO banks (name, bic, created_at, updated_at) VALUES (:name, :bic, :created_at, :updated_at)'
                );
                $insertStmt->execute([
                    'name' => $name,
                    'bic' => $bic,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $entityId = (int) $db->lastInsertId();
            }

            $details = json_encode([
                'name' => $name,
                'bic' => $bic,
            ], JSON_UNESCAPED_UNICODE);

            $logStmt = $db->prepare(
                'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, details, created_at, ip_address, user_agent) '
                . 'VALUES (:actor_user_id, :action, :entity, :entity_id, :details, :created_at, :ip_address, :user_agent)'
            );
            $logStmt->execute([
                'actor_user_id' => $user['id'] ?? null,
                'action' => $action,
                'entity' => 'banks',
                'entity_id' => $entityId,
                'details' => $details,
                'created_at' => $now,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            $db->commit();

            set_flash('success', $bankId ? 'Банк обновлён.' : 'Банк создан.');
            redirect('/site/banks.php?edit=1&id=' . $entityId);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Не удалось сохранить банк. Попробуйте позже.';
        }
    }

    $bank = [
        'id' => $bankId,
        'name' => $name,
        'bic' => $bic ?? '',
    ];
}

$banksStmt = db()->query('SELECT id, name, bic FROM banks ORDER BY name');
$banks = $banksStmt->fetchAll();

render_header('Администрирование банков');
?>
<section class="card">
  <h2><?= $bank['id'] ? 'Редактирование банка' : 'Создание банка' ?></h2>
  <p>Заполните данные банка и сохраните изменения.</p>

  <?php if (!empty($errors)) : ?>
    <div class="flash__message flash__message--error" style="margin-bottom: 16px;">
      <?= sanitize(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <form id="bank-form" method="post" style="display: grid; gap: 12px; max-width: 520px;">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
    <?php if ($bank['id']) : ?>
      <input type="hidden" name="bank_id" value="<?= (int) $bank['id'] ?>">
    <?php endif; ?>
    <label>
      Название банка
      <input type="text" name="name" value="<?= sanitize($bank['name']) ?>" maxlength="255" required>
    </label>
    <label>
      БИК
      <input type="text" name="bic" value="<?= sanitize($bank['bic']) ?>" maxlength="20" placeholder="Например, CFINRU00">
    </label>
    <div style="display: flex; gap: 8px;">
      <button type="submit">Сохранить</button>
      <a href="/site/banks.php" class="button" style="text-decoration: none; display: inline-flex; align-items: center;">Новый банк</a>
    </div>
  </form>
</section>

<section class="card" style="margin-top: 24px;">
  <h2>Список банков</h2>
  <p>Выберите банк для редактирования.</p>
  <?php if (empty($banks)) : ?>
    <p>Банки не найдены.</p>
  <?php else : ?>
    <div style="overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px 0;">Название</th>
            <th style="text-align: left; padding: 8px 0;">БИК</th>
            <th style="text-align: left; padding: 8px 0;">Действия</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($banks as $row) : ?>
            <tr>
              <td style="padding: 6px 0;"><?= sanitize($row['name']) ?></td>
              <td style="padding: 6px 0;"><?= sanitize($row['bic'] ?? '—') ?></td>
              <td style="padding: 6px 0;">
                <a href="/site/banks.php?edit=1&id=<?= (int) $row['id'] ?>">Редактировать</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
  (function () {
    const form = document.getElementById('bank-form');
    if (!form) {
      return;
    }

    form.addEventListener('submit', function (event) {
      const errors = [];
      const name = form.querySelector('[name="name"]');
      const bic = form.querySelector('[name="bic"]');

      if (!name.value.trim()) {
        errors.push('Введите название банка.');
      } else if (name.value.trim().length < 2) {
        errors.push('Название банка слишком короткое.');
      }

      if (bic.value.trim()) {
        const bicValue = bic.value.trim().toUpperCase();
        if (!/^[A-Z0-9]+$/.test(bicValue)) {
          errors.push('БИК должен содержать только латинские буквы и цифры.');
        }
        if (bicValue.length < 3 || bicValue.length > 20) {
          errors.push('БИК должен быть от 3 до 20 символов.');
        }
        bic.value = bicValue;
      }

      if (errors.length > 0) {
        event.preventDefault();
        alert(errors.join('\n'));
      }
    });
  })();
</script>
<?php
render_footer();
