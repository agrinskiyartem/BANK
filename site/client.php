<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_login('/site/login.php');
require_role('client');

$user = current_user();
$clientId = $user['client_id'] ?? null;
if ($clientId === null) {
    set_flash('error', 'Профиль клиента не найден.');
    redirect('/site/login.php');
}

$clientStmt = db()->prepare('SELECT full_name FROM clients WHERE id = :id LIMIT 1');
$clientStmt->execute(['id' => $clientId]);
$client = $clientStmt->fetch();

$balancesStmt = db()->prepare(
    'SELECT accounts.currency, SUM(accounts.balance) AS total_balance '
    . 'FROM accounts '
    . 'JOIN cards ON cards.id = accounts.card_id '
    . 'WHERE cards.client_id = :client_id '
    . 'GROUP BY accounts.currency '
    . 'ORDER BY accounts.currency'
);
$balancesStmt->execute(['client_id' => $clientId]);
$balances = $balancesStmt->fetchAll();

$banksStmt = db()->query('SELECT id, name FROM banks ORDER BY name');
$banks = $banksStmt->fetchAll();

render_header('Кабинет клиента');
?>
<section class="card">
  <h2>Добро пожаловать<?= $client ? ', ' . sanitize($client['full_name']) : '' ?></h2>
  <p>Ниже отображены ваши балансы и история списаний в банкоматах.</p>
  <dl>
    <dt>Баланс</dt>
    <dd>
      <?php if (empty($balances)) : ?>
        Нет данных по счетам.
      <?php else : ?>
        <?php foreach ($balances as $balance) : ?>
          <div><?= number_format((float) $balance['total_balance'], 2, '.', ' ') ?> <?= sanitize($balance['currency']) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </dd>
  </dl>
</section>

<section class="card" style="margin-top: 24px;">
  <h2>Мои списания</h2>
  <p>Используйте фильтры, чтобы быстро найти нужную операцию.</p>

  <div id="filter-errors" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="withdrawal-filters" style="display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <label>
      Дата с
      <input type="text" name="date_from" placeholder="дд/мм/гггг" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}">
    </label>
    <label>
      Дата по
      <input type="text" name="date_to" placeholder="дд/мм/гггг" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}">
    </label>
    <label>
      Банк-владелец
      <select name="bank_owner_id">
        <option value="">Все банки</option>
        <?php foreach ($banks as $bank) : ?>
          <option value="<?= (int) $bank['id'] ?>"><?= sanitize($bank['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div style="display: flex; gap: 8px; align-items: flex-end;">
      <button type="submit">Применить</button>
      <button type="button" id="reset-filters">Сбросить</button>
    </div>
  </form>

  <div id="withdrawals-state" style="margin-top: 16px; color: #64748b;"></div>

  <div style="overflow-x: auto; margin-top: 16px;">
    <table id="withdrawals-table" style="width: 100%; border-collapse: collapse;">
      <thead>
        <tr>
          <th style="text-align: left; padding: 8px 0;">Дата/время <button type="button" data-sort="created_at" style="margin-left: 4px;">↕</button></th>
          <th style="text-align: left; padding: 8px 0;">Сумма <button type="button" data-sort="amount" style="margin-left: 4px;">↕</button></th>
          <th style="text-align: left; padding: 8px 0;">Комиссия</th>
          <th style="text-align: left; padding: 8px 0;">Итог</th>
          <th style="text-align: left; padding: 8px 0;">Банкомат</th>
          <th style="text-align: left; padding: 8px 0;">Банк-владелец</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</section>

<script>
  (function () {
    const form = document.getElementById('withdrawal-filters');
    const resetButton = document.getElementById('reset-filters');
    const errorBox = document.getElementById('filter-errors');
    const stateBox = document.getElementById('withdrawals-state');
    const tableBody = document.querySelector('#withdrawals-table tbody');
    const sortButtons = document.querySelectorAll('[data-sort]');

    if (!form || !tableBody || !stateBox) {
      return;
    }

    let withdrawals = [];
    let currentSort = { key: 'created_at', direction: 'desc' };

    function formatMoney(value) {
      return Number(value).toFixed(2).replace('.', ',');
    }

    function formatDate(value) {
      const date = new Date(value.replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) {
        return value;
      }
      return date.toLocaleString('ru-RU');
    }

    function parseDateInput(value, label) {
      if (!value) {
        return { value: '', timestamp: null };
      }

      const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
      if (!match) {
        return { error: `${label} должна быть в формате дд/мм/гггг.` };
      }

      const day = Number(match[1]);
      const month = Number(match[2]);
      const year = Number(match[3]);
      const date = new Date(Date.UTC(year, month - 1, day));

      if (
        Number.isNaN(date.getTime())
        || date.getUTCFullYear() !== year
        || date.getUTCMonth() !== month - 1
        || date.getUTCDate() !== day
      ) {
        return { error: `${label} указана некорректно.` };
      }

      return {
        value: `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
        timestamp: date.getTime(),
      };
    }

    function renderTable() {
      tableBody.innerHTML = '';

      if (withdrawals.length === 0) {
        stateBox.textContent = 'Списания не найдены.';
        return;
      }

      stateBox.textContent = `Найдено операций: ${withdrawals.length}`;

      withdrawals.forEach((item) => {
        const row = document.createElement('tr');
        const cells = [
          formatDate(item.created_at),
          `${formatMoney(item.amount)} ₽`,
          `${formatMoney(item.commission_amount)} ₽`,
          `${formatMoney(item.total_amount)} ₽`,
          item.atm_name,
          item.bank_owner_name,
        ];

        cells.forEach((text) => {
          const cell = document.createElement('td');
          cell.style.padding = '8px 0';
          cell.textContent = text;
          row.appendChild(cell);
        });

        tableBody.appendChild(row);
      });
    }

    function sortData(key) {
      if (currentSort.key === key) {
        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
      } else {
        currentSort = { key, direction: 'desc' };
      }

      const direction = currentSort.direction === 'asc' ? 1 : -1;

      withdrawals.sort((a, b) => {
        if (key === 'created_at') {
          const aDate = new Date(a.created_at.replace(' ', 'T')).getTime();
          const bDate = new Date(b.created_at.replace(' ', 'T')).getTime();
          return (aDate - bDate) * direction;
        }
        return (Number(a[key]) - Number(b[key])) * direction;
      });

      renderTable();
    }

    function validateFilters() {
      const dateFromRaw = form.date_from.value.trim();
      const dateToRaw = form.date_to.value.trim();
      const parsedFrom = parseDateInput(dateFromRaw, 'Дата "с"');
      const parsedTo = parseDateInput(dateToRaw, 'Дата "по"');
      const errors = [];

      if (parsedFrom.error) {
        errors.push(parsedFrom.error);
      }
      if (parsedTo.error) {
        errors.push(parsedTo.error);
      }
      if (parsedFrom.timestamp !== null && parsedTo.timestamp !== null && parsedFrom.timestamp > parsedTo.timestamp) {
        errors.push('Дата "с" не может быть позже даты "по".');
      }

      if (errors.length > 0) {
        errorBox.textContent = errors.join(' ');
        errorBox.style.display = 'block';
        return false;
      }

      errorBox.textContent = '';
      errorBox.style.display = 'none';
      return true;
    }

    function buildParams() {
      const params = new URLSearchParams();
      const dateFrom = parseDateInput(form.date_from.value.trim(), 'Дата "с"');
      const dateTo = parseDateInput(form.date_to.value.trim(), 'Дата "по"');
      const bankOwnerId = form.bank_owner_id.value;

      if (dateFrom.value) {
        params.set('date_from', dateFrom.value);
      }
      if (dateTo.value) {
        params.set('date_to', dateTo.value);
      }
      if (bankOwnerId) {
        params.set('bank_owner_id', bankOwnerId);
      }

      return params;
    }

    async function loadWithdrawals() {
      if (!validateFilters()) {
        return;
      }

      stateBox.textContent = 'Загружаем данные...';
      tableBody.innerHTML = '';

      try {
        const params = buildParams();
        const response = await fetch(`/api/withdrawals.php?${params.toString()}`);

        if (!response.ok) {
          throw new Error('Не удалось загрузить список списаний.');
        }

        const data = await response.json();
        withdrawals = Array.isArray(data.items) ? data.items : [];
        sortData(currentSort.key);
      } catch (error) {
        stateBox.textContent = error.message || 'Произошла ошибка загрузки.';
      }
    }

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      loadWithdrawals();
    });

    form.addEventListener('change', () => {
      validateFilters();
    });

    if (resetButton) {
      resetButton.addEventListener('click', () => {
        form.reset();
        validateFilters();
        loadWithdrawals();
      });
    }

    sortButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const key = button.dataset.sort;
        sortData(key);
      });
    });

    loadWithdrawals();
  })();
</script>
<?php
render_footer();
