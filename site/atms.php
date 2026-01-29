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
$statuses = [
    'online' => 'В работе',
    'maintenance' => 'Техобслуживание',
    'offline' => 'Не работает',
];

$atm = [
    'id' => null,
    'bank_owner_id' => '',
    'name' => '',
    'address' => '',
    'is_active' => 1,
    'status' => 'online',
    'status_note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteErrors = [];

    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $deleteErrors[] = $exception->getMessage();
    }

    $atmId = filter_var($_POST['atm_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$atmId) {
        $deleteErrors[] = 'Некорректный идентификатор банкомата.';
    }

    if (empty($deleteErrors)) {
        $db = db();
        $stmt = $db->prepare('SELECT id, name, address FROM atms WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $atmId]);
        $atmToDelete = $stmt->fetch();

        if (!$atmToDelete) {
            $deleteErrors[] = 'Банкомат не найден.';
        } else {
            $hasWithdrawals = $db->prepare('SELECT COUNT(*) FROM withdrawals WHERE atm_id = :id');
            $hasWithdrawals->execute(['id' => $atmId]);
            if ((int) $hasWithdrawals->fetchColumn() > 0) {
                $deleteErrors[] = 'Нельзя удалить банкомат, пока есть операции.';
            }
        }
    }

    if (empty($deleteErrors)) {
        $db = db();
        $now = db_now();
        try {
            $db->beginTransaction();

            $deleteStmt = $db->prepare('DELETE FROM atms WHERE id = :id');
            $deleteStmt->execute(['id' => $atmId]);

            $details = json_encode([
                'name' => $atmToDelete['name'],
                'address' => $atmToDelete['address'],
            ], JSON_UNESCAPED_UNICODE);

            $logStmt = $db->prepare(
                'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, details, created_at, ip_address, user_agent) '
                . 'VALUES (:actor_user_id, :action, :entity, :entity_id, :details, :created_at, :ip_address, :user_agent)'
            );
            $logStmt->execute([
                'actor_user_id' => $user['id'] ?? null,
                'action' => 'delete',
                'entity' => 'atms',
                'entity_id' => $atmId,
                'details' => $details,
                'created_at' => $now,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            $db->commit();
            set_flash('success', 'Банкомат удалён.');
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $deleteErrors[] = 'Не удалось удалить банкомат. Попробуйте позже.';
        }
    }

    if (!empty($deleteErrors)) {
        foreach ($deleteErrors as $message) {
            set_flash('error', $message);
        }
    }

    redirect('/site/atms.php');
}

$editId = null;
if (isset($_GET['edit']) || isset($_GET['id'])) {
    $editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($editId !== false && $editId !== null) {
        $stmt = db()->prepare(
            'SELECT atms.id, atms.bank_owner_id, atms.name, atms.address, atms.is_active, '
            . 'atm_status.status, atm_status.note '
            . 'FROM atms '
            . 'LEFT JOIN atm_status ON atm_status.atm_id = atms.id '
            . 'WHERE atms.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $editId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $atm = [
                'id' => (int) $existing['id'],
                'bank_owner_id' => (int) $existing['bank_owner_id'],
                'name' => $existing['name'],
                'address' => $existing['address'],
                'is_active' => (int) $existing['is_active'],
                'status' => $existing['status'] ?? 'online',
                'status_note' => $existing['note'] ?? '',
            ];
        } else {
            set_flash('error', 'Банкомат не найден.');
            redirect('/site/atms.php');
        }
    } else {
        set_flash('error', 'Некорректный идентификатор банкомата.');
        redirect('/site/atms.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }

    $atmId = filter_var($_POST['atm_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $bankOwnerId = filter_var($_POST['bank_owner_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $status = (string) ($_POST['status'] ?? 'online');
    $statusNote = trim((string) ($_POST['status_note'] ?? ''));

    validate_required($name, 'Название банкомата', $errors);
    validate_length($name, 'Название банкомата', $errors, 2, 255);
    validate_required($address, 'Адрес', $errors);
    validate_length($address, 'Адрес', $errors, 3, 255);

    if ($bankOwnerId === null) {
        $errors[] = 'Выберите банк-владелец.';
    }

    if (!array_key_exists($status, $statuses)) {
        $errors[] = 'Укажите корректный статус банкомата.';
    }

    if ($statusNote !== '') {
        validate_length($statusNote, 'Примечание', $errors, 0, 255);
    }

    if (empty($errors)) {
        $now = db_now();
        $db = db();

        try {
            $db->beginTransaction();

            if ($atmId) {
                $updateStmt = $db->prepare(
                    'UPDATE atms SET bank_owner_id = :bank_owner_id, name = :name, address = :address, '
                    . 'is_active = :is_active, updated_at = :updated_at WHERE id = :id'
                );
                $updateStmt->execute([
                    'bank_owner_id' => $bankOwnerId,
                    'name' => $name,
                    'address' => $address,
                    'is_active' => $isActive,
                    'updated_at' => $now,
                    'id' => $atmId,
                ]);

                $statusIdStmt = $db->prepare('SELECT id FROM atm_status WHERE atm_id = :atm_id LIMIT 1');
                $statusIdStmt->execute(['atm_id' => $atmId]);
                $statusId = $statusIdStmt->fetchColumn();

                if ($statusId) {
                    $updateStatusStmt = $db->prepare(
                        'UPDATE atm_status SET status = :status, note = :note, updated_at = :updated_at WHERE atm_id = :atm_id'
                    );
                    $updateStatusStmt->execute([
                        'status' => $status,
                        'note' => $statusNote === '' ? null : $statusNote,
                        'updated_at' => $now,
                        'atm_id' => $atmId,
                    ]);
                } else {
                    $insertStatusStmt = $db->prepare(
                        'INSERT INTO atm_status (atm_id, status, note, updated_at) VALUES (:atm_id, :status, :note, :updated_at)'
                    );
                    $insertStatusStmt->execute([
                        'atm_id' => $atmId,
                        'status' => $status,
                        'note' => $statusNote === '' ? null : $statusNote,
                        'updated_at' => $now,
                    ]);
                }

                $entityId = $atmId;
            } else {
                $insertStmt = $db->prepare(
                    'INSERT INTO atms (bank_owner_id, name, address, is_active, created_at, updated_at) '
                    . 'VALUES (:bank_owner_id, :name, :address, :is_active, :created_at, :updated_at)'
                );
                $insertStmt->execute([
                    'bank_owner_id' => $bankOwnerId,
                    'name' => $name,
                    'address' => $address,
                    'is_active' => $isActive,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $entityId = (int) $db->lastInsertId();

                $insertStatusStmt = $db->prepare(
                    'INSERT INTO atm_status (atm_id, status, note, updated_at) VALUES (:atm_id, :status, :note, :updated_at)'
                );
                $insertStatusStmt->execute([
                    'atm_id' => $entityId,
                    'status' => $status,
                    'note' => $statusNote === '' ? null : $statusNote,
                    'updated_at' => $now,
                ]);
            }

            $db->commit();

            set_flash('success', $atmId ? 'Банкомат обновлён.' : 'Банкомат создан.');
            redirect('/site/atms.php?edit=1&id=' . $entityId);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Не удалось сохранить банкомат. Попробуйте позже.';
        }
    }

    $atm = [
        'id' => $atmId,
        'bank_owner_id' => $bankOwnerId ?? '',
        'name' => $name,
        'address' => $address,
        'is_active' => $isActive,
        'status' => $status,
        'status_note' => $statusNote,
    ];
}

$banksStmt = db()->query('SELECT id, name FROM banks ORDER BY name');
$banks = $banksStmt->fetchAll();

$atmsStmt = db()->query(
    'SELECT atms.id, atms.name, atms.address, atms.is_active, banks.name AS bank_name '
    . 'FROM atms JOIN banks ON banks.id = atms.bank_owner_id ORDER BY atms.name'
);
$atms = $atmsStmt->fetchAll();

render_header('Администрирование банкоматов');
?>
<section class="card">
  <h2><?= $atm['id'] ? 'Редактирование банкомата' : 'Создание банкомата' ?></h2>
  <p>Укажите банк-владельца, адрес и статус банкомата.</p>

  <?php if (!empty($errors)) : ?>
    <div class="flash__message flash__message--error" style="margin-bottom: 16px;">
      <?= sanitize(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <form id="atm-form" method="post" style="display: grid; gap: 12px; max-width: 640px;">
    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
    <?php if ($atm['id']) : ?>
      <input type="hidden" name="atm_id" value="<?= (int) $atm['id'] ?>">
    <?php endif; ?>
    <label>
      Банк-владелец
      <select name="bank_owner_id" required>
        <option value="">Выберите банк</option>
        <?php foreach ($banks as $bank) : ?>
          <option value="<?= (int) $bank['id'] ?>" <?= (int) $atm['bank_owner_id'] === (int) $bank['id'] ? 'selected' : '' ?>>
            <?= sanitize($bank['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      Название банкомата
      <input type="text" name="name" value="<?= sanitize($atm['name']) ?>" maxlength="255" required>
    </label>
    <label>
      Адрес
      <input type="text" name="address" value="<?= sanitize($atm['address']) ?>" maxlength="255" required>
    </label>
    <label style="display: flex; gap: 8px; align-items: center;">
      <input type="checkbox" name="is_active" value="1" <?= $atm['is_active'] ? 'checked' : '' ?>>
      Активен
    </label>
    <label>
      Статус
      <select name="status" required>
        <?php foreach ($statuses as $value => $label) : ?>
          <option value="<?= sanitize($value) ?>" <?= $atm['status'] === $value ? 'selected' : '' ?>>
            <?= sanitize($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      Примечание
      <input type="text" name="status_note" value="<?= sanitize($atm['status_note']) ?>" maxlength="255" placeholder="Например, запланирован сервис">
    </label>
    <div style="display: flex; gap: 8px;">
      <button type="submit">Сохранить</button>
      <a href="/site/atms.php" class="button" style="text-decoration: none; display: inline-flex; align-items: center;">Новый банкомат</a>
    </div>
  </form>
</section>

<section class="card" style="margin-top: 24px;">
  <h2>Список банкоматов</h2>
  <p>Выберите банкомат для редактирования.</p>
  <?php if (empty($atms)) : ?>
    <p>Банкоматы не найдены.</p>
  <?php else : ?>
    <div style="overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px 0;">Название</th>
            <th style="text-align: left; padding: 8px 0;">Адрес</th>
            <th style="text-align: left; padding: 8px 0;">Банк</th>
            <th style="text-align: left; padding: 8px 0;">Активен</th>
            <th style="text-align: left; padding: 8px 0;">Действия</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($atms as $row) : ?>
            <tr>
              <td style="padding: 6px 0;"><?= sanitize($row['name']) ?></td>
              <td style="padding: 6px 0;"><?= sanitize($row['address']) ?></td>
              <td style="padding: 6px 0;"><?= sanitize($row['bank_name']) ?></td>
              <td style="padding: 6px 0;"><?= (int) $row['is_active'] === 1 ? 'Да' : 'Нет' ?></td>
              <td style="padding: 6px 0;">
                <a href="/site/atms.php?edit=1&id=<?= (int) $row['id'] ?>">Редактировать</a>
                <form method="post" action="/site/atms.php" style="display: inline; margin-left: 8px;" onsubmit="return confirm('Удалить банкомат?');">
                  <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="atm_id" value="<?= (int) $row['id'] ?>">
                  <button type="submit" class="link-button">Удалить</button>
                </form>
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
    const form = document.getElementById('atm-form');
    if (!form) {
      return;
    }

    form.addEventListener('submit', function (event) {
      const errors = [];
      const bank = form.querySelector('[name="bank_owner_id"]');
      const name = form.querySelector('[name="name"]');
      const address = form.querySelector('[name="address"]');
      const status = form.querySelector('[name="status"]');

      if (!bank.value) {
        errors.push('Выберите банк-владелец.');
      }
      if (!name.value.trim()) {
        errors.push('Введите название банкомата.');
      }
      if (!address.value.trim()) {
        errors.push('Введите адрес.');
      }
      if (!status.value) {
        errors.push('Выберите статус банкомата.');
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
