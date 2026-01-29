<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_login('/site/login.php');
require_role('admin');

function mask_card_number(string $number): string
{
    $clean = preg_replace('/\D+/', '', $number);
    if ($clean === '' || strlen($clean) <= 4) {
        return $number;
    }

    return substr($clean, 0, 4) . ' **** **** ' . substr($clean, -4);
}

$usersStmt = db()->query(
    'SELECT users.id, users.login, users.role, users.is_active, users.created_at, '
    . 'clients.id AS client_id, clients.full_name '
    . 'FROM users '
    . 'LEFT JOIN clients ON clients.user_id = users.id '
    . 'ORDER BY users.created_at DESC'
);
$users = $usersStmt->fetchAll();

$clientIds = [];
foreach ($users as $user) {
    if (!empty($user['client_id'])) {
        $clientIds[] = (int) $user['client_id'];
    }
}

$cardsByClient = [];
if (!empty($clientIds)) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $cardsStmt = db()->prepare(
        'SELECT cards.client_id, cards.card_number, cards.expires_at, cards.is_blocked, '
        . 'banks.name AS bank_name, accounts.balance, accounts.currency '
        . 'FROM cards '
        . 'JOIN banks ON banks.id = cards.bank_issuer_id '
        . 'LEFT JOIN accounts ON accounts.card_id = cards.id '
        . 'WHERE cards.client_id IN (' . $placeholders . ') '
        . 'ORDER BY cards.client_id, cards.card_number'
    );
    $cardsStmt->execute($clientIds);
    $cards = $cardsStmt->fetchAll();

    foreach ($cards as $card) {
        $clientId = (int) $card['client_id'];
        if (!isset($cardsByClient[$clientId])) {
            $cardsByClient[$clientId] = [];
        }
        $cardsByClient[$clientId][] = $card;
    }
}

render_header('Пользователи');
?>
<section class="card">
  <h2>Пользователи системы</h2>
  <p>Просматривайте учетные записи, привязанные карты и балансы клиентов.</p>

  <?php if (empty($users)) : ?>
    <p>Пользователи не найдены.</p>
  <?php else : ?>
    <div style="overflow-x: auto;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px 0;">Логин</th>
            <th style="text-align: left; padding: 8px 0;">Роль</th>
            <th style="text-align: left; padding: 8px 0;">Активен</th>
            <th style="text-align: left; padding: 8px 0;">Клиент</th>
            <th style="text-align: left; padding: 8px 0;">Карты и балансы</th>
            <th style="text-align: left; padding: 8px 0;">Создан</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user) : ?>
            <tr>
              <td style="padding: 6px 0;"><?= sanitize($user['login']) ?></td>
              <td style="padding: 6px 0;"><?= $user['role'] === 'admin' ? 'Администратор' : 'Клиент' ?></td>
              <td style="padding: 6px 0;"><?= (int) $user['is_active'] === 1 ? 'Да' : 'Нет' ?></td>
              <td style="padding: 6px 0;">
                <?= $user['full_name'] ? sanitize($user['full_name']) : '—' ?>
              </td>
              <td style="padding: 6px 0;">
                <?php if (empty($user['client_id']) || empty($cardsByClient[(int) $user['client_id']])) : ?>
                  —
                <?php else : ?>
                  <ul style="margin: 0; padding-left: 18px;">
                    <?php foreach ($cardsByClient[(int) $user['client_id']] as $card) : ?>
                      <li>
                        <?= sanitize($card['bank_name']) ?>,
                        <?= sanitize(mask_card_number($card['card_number'])) ?>,
                        <?= $card['balance'] !== null ? number_format((float) $card['balance'], 2, '.', ' ') . ' ' . sanitize($card['currency']) : 'Баланс не задан' ?>
                        <?php if (!empty($card['expires_at'])) : ?>
                          , до <?= sanitize($card['expires_at']) ?>
                        <?php endif; ?>
                        <?= (int) $card['is_blocked'] === 1 ? ' (карта заблокирована)' : '' ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </td>
              <td style="padding: 6px 0;">
                <?= sanitize((string) $user['created_at']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
render_footer();
