<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_login('/site/login.php');
require_role('admin');

render_header('Аналитика по банкоматам');
?>
<section class="card">
  <h2>Аналитика операций</h2>
  <p>
    Комиссии считаются по банку-владельцу банкомата и учитывают только успешные операции.
  </p>

  <div id="analytics-errors" class="flash__message flash__message--error" style="display: none; margin-bottom: 16px;"></div>

  <form id="analytics-filters" style="display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <label>
      Дата с
      <input type="date" name="date_from" placeholder="дд/мм/гггг" lang="ru">
    </label>
    <label>
      Дата по
      <input type="date" name="date_to" placeholder="дд/мм/гггг" lang="ru">
    </label>
    <div style="display: flex; gap: 8px; align-items: flex-end;">
      <button type="submit">Показать</button>
      <button type="button" id="reset-analytics">Сбросить</button>
    </div>
  </form>

  <div id="analytics-state" style="margin-top: 16px; color: #64748b;"></div>

  <div style="display: grid; gap: 24px; margin-top: 16px;">
    <div>
      <h3>Сумма комиссий по банкам-владельцам банкоматов</h3>
      <table id="commission-table" style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px 0;">Банк</th>
            <th style="text-align: right; padding: 8px 0;">Комиссия</th>
            <th style="text-align: right; padding: 8px 0;">Операции</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div>
      <h3>Топ банкоматов по количеству операций</h3>
      <table id="top-atms-table" style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="text-align: left; padding: 8px 0;">Банкомат</th>
            <th style="text-align: left; padding: 8px 0;">Банк</th>
            <th style="text-align: right; padding: 8px 0;">Операции</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div>
      <h3>Динамика операций по дням</h3>
      <canvas id="daily-chart" height="140"></canvas>
    </div>

    <div>
      <h3>Динамика операций по месяцам</h3>
      <canvas id="monthly-chart" height="140"></canvas>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  (function () {
    const form = document.getElementById('analytics-filters');
    const resetButton = document.getElementById('reset-analytics');
    const errorBox = document.getElementById('analytics-errors');
    const stateBox = document.getElementById('analytics-state');
    const commissionBody = document.querySelector('#commission-table tbody');
    const topAtmsBody = document.querySelector('#top-atms-table tbody');
    const dailyCanvas = document.getElementById('daily-chart');
    const monthlyCanvas = document.getElementById('monthly-chart');

    if (!form || !commissionBody || !topAtmsBody || !dailyCanvas || !monthlyCanvas) {
      return;
    }

    let dailyChart = null;
    let monthlyChart = null;

    function formatMoney(value) {
      return Number(value || 0).toFixed(2).replace('.', ',');
    }

    function clearTables() {
      commissionBody.innerHTML = '';
      topAtmsBody.innerHTML = '';
    }

    function renderCommissionTable(items) {
      commissionBody.innerHTML = '';

      if (!items || items.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 3;
        cell.style.padding = '8px 0';
        cell.textContent = 'Данных нет.';
        row.appendChild(cell);
        commissionBody.appendChild(row);
        return;
      }

      items.forEach((item) => {
        const row = document.createElement('tr');
        const bankCell = document.createElement('td');
        bankCell.style.padding = '8px 0';
        bankCell.textContent = item.bank_name;

        const commissionCell = document.createElement('td');
        commissionCell.style.padding = '8px 0';
        commissionCell.style.textAlign = 'right';
        commissionCell.textContent = `${formatMoney(item.commission_total)} ₽`;

        const countCell = document.createElement('td');
        countCell.style.padding = '8px 0';
        countCell.style.textAlign = 'right';
        countCell.textContent = item.operations_count;

        row.appendChild(bankCell);
        row.appendChild(commissionCell);
        row.appendChild(countCell);
        commissionBody.appendChild(row);
      });
    }

    function renderTopAtmsTable(items) {
      topAtmsBody.innerHTML = '';

      if (!items || items.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 3;
        cell.style.padding = '8px 0';
        cell.textContent = 'Данных нет.';
        row.appendChild(cell);
        topAtmsBody.appendChild(row);
        return;
      }

      items.forEach((item) => {
        const row = document.createElement('tr');
        const atmCell = document.createElement('td');
        atmCell.style.padding = '8px 0';
        atmCell.textContent = `${item.atm_name} — ${item.atm_address}`;

        const bankCell = document.createElement('td');
        bankCell.style.padding = '8px 0';
        bankCell.textContent = item.bank_name;

        const countCell = document.createElement('td');
        countCell.style.padding = '8px 0';
        countCell.style.textAlign = 'right';
        countCell.textContent = item.operations_count;

        row.appendChild(atmCell);
        row.appendChild(bankCell);
        row.appendChild(countCell);
        topAtmsBody.appendChild(row);
      });
    }

    function renderCharts(daily, monthly) {
      const dailyLabels = daily.map((row) => row.label);
      const dailyValues = daily.map((row) => Number(row.operations_count || 0));

      const monthlyLabels = monthly.map((row) => row.label);
      const monthlyValues = monthly.map((row) => Number(row.operations_count || 0));

      if (dailyChart) {
        dailyChart.destroy();
      }
      if (monthlyChart) {
        monthlyChart.destroy();
      }

      dailyChart = new Chart(dailyCanvas, {
        type: 'line',
        data: {
          labels: dailyLabels,
          datasets: [
            {
              label: 'Операции',
              data: dailyValues,
              borderColor: '#2563eb',
              backgroundColor: 'rgba(37, 99, 235, 0.15)',
              fill: true,
              tension: 0.25,
            },
          ],
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
              display: true,
            },
          },
        },
      });

      monthlyChart = new Chart(monthlyCanvas, {
        type: 'bar',
        data: {
          labels: monthlyLabels,
          datasets: [
            {
              label: 'Операции',
              data: monthlyValues,
              backgroundColor: '#0ea5e9',
            },
          ],
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
              display: true,
            },
          },
        },
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

      if (dateFrom) {
        params.set('date_from', dateFrom);
      }
      if (dateTo) {
        params.set('date_to', dateTo);
      }

      return params;
    }

    async function loadAnalytics() {
      if (!validateFilters()) {
        return;
      }

      stateBox.textContent = 'Загружаем аналитику...';
      clearTables();

      try {
        const params = buildParams();
        const response = await fetch(`/api/analytics.php?${params.toString()}`);

        if (!response.ok) {
          const data = await response.json().catch(() => ({}));
          const message = data.errors ? data.errors.join(' ') : 'Не удалось загрузить аналитику.';
          throw new Error(message);
        }

        const data = await response.json();
        renderCommissionTable(Array.isArray(data.commission_by_bank) ? data.commission_by_bank : []);
        renderTopAtmsTable(Array.isArray(data.top_atms) ? data.top_atms : []);
        renderCharts(Array.isArray(data.daily) ? data.daily : [], Array.isArray(data.monthly) ? data.monthly : []);

        stateBox.textContent = 'Данные обновлены.';
      } catch (error) {
        stateBox.textContent = error.message || 'Произошла ошибка загрузки.';
      }
    }

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      loadAnalytics();
    });

    form.addEventListener('change', () => {
      validateFilters();
    });

    if (resetButton) {
      resetButton.addEventListener('click', () => {
        form.reset();
        validateFilters();
        loadAnalytics();
      });
    }

    loadAnalytics();
  })();
</script>
<?php
render_footer();
