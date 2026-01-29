<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_login('/site/login.php');
require_role('admin');

$banksStmt = db()->query('SELECT id, name FROM banks ORDER BY name');
$banks = $banksStmt->fetchAll();

$atmsStmt = db()->query(
    'SELECT atms.id, atms.name, atms.address, banks.name AS bank_name '
    . 'FROM atms '
    . 'JOIN banks ON banks.id = atms.bank_owner_id '
    . 'ORDER BY atms.name'
);
$atms = $atmsStmt->fetchAll();

render_header('Панель администратора');
?>
<section class="card">
  <h2>Списания по всем клиентам</h2>
  <p>Используйте фильтры для поиска операций по банкам, банкоматам и датам.</p>

  <div id="filter-errors" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="withdrawal-filters" style="display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <label>
      Дата с
      <input type="date" name="date_from">
    </label>
    <label>
      Дата по
      <input type="date" name="date_to">
    </label>
    <label>
      Банк
      <select name="bank_id">
        <option value="">Все банки</option>
        <?php foreach ($banks as $bank) : ?>
          <option value="<?= (int) $bank['id'] ?>"><?= sanitize($bank['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      Банкомат
      <select name="atm_id">
        <option value="">Все банкоматы</option>
        <?php foreach ($atms as $atm) : ?>
          <option value="<?= (int) $atm['id'] ?>">
            <?= sanitize($atm['name'] . ' — ' . $atm['address'] . ' (' . $atm['bank_name'] . ')') ?>
          </option>
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
          <th style="text-align: left; padding: 8px 0;">Дата/время</th>
          <th style="text-align: left; padding: 8px 0;">Клиент</th>
          <th style="text-align: left; padding: 8px 0;">Карта</th>
          <th style="text-align: left; padding: 8px 0;">Сумма</th>
          <th style="text-align: left; padding: 8px 0;">Комиссия</th>
          <th style="text-align: left; padding: 8px 0;">Итого</th>
          <th style="text-align: left; padding: 8px 0;">Банкомат</th>
          <th style="text-align: left; padding: 8px 0;">Банк-владелец</th>
          <th style="text-align: left; padding: 8px 0;">Банк-эмитент</th>
          <th style="text-align: left; padding: 8px 0;">Статус</th>
          <th style="text-align: left; padding: 8px 0;">Ошибка</th>
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

    if (!form || !tableBody || !stateBox) {
      return;
    }

    let withdrawals = [];

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
          item.client_name,
          item.card_number,
          `${formatMoney(item.amount)} ₽`,
          `${formatMoney(item.commission_amount)} ₽`,
          `${formatMoney(item.total_amount)} ₽`,
          item.atm_name,
          item.bank_owner_name,
          item.bank_issuer_name,
          item.status === 'success' ? 'Успешно' : 'Ошибка',
          item.error_reason || '—',
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

    function validateFilters() {
      const dateFrom = form.date_from.value;
      const dateTo = form.date_to.value;
      const errors = [];

      if (dateFrom && dateTo && dateFrom > dateTo) {
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
      const dateFrom = form.date_from.value;
      const dateTo = form.date_to.value;
      const bankId = form.bank_id.value;
      const atmId = form.atm_id.value;

      if (dateFrom) {
        params.set('date_from', dateFrom);
      }
      if (dateTo) {
        params.set('date_to', dateTo);
      }
      if (bankId) {
        params.set('bank_id', bankId);
      }
      if (atmId) {
        params.set('atm_id', atmId);
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
        const response = await fetch(`/api/admin_withdrawals.php?${params.toString()}`);

        if (!response.ok) {
          throw new Error('Не удалось загрузить список списаний.');
        }

        const data = await response.json();
        withdrawals = Array.isArray(data.items) ? data.items : [];
        renderTable();
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

    loadWithdrawals();
  })();
</script>
<?php
render_footer();
