# Учебный проект «Банкоматы» (вариант 36): проектирование

## 1) Сущности и связи (с кардинальностями)

- **Банк (Bank)**
  - 1 банк **выпускает** много карт (1:N) — эмитент.
  - 1 банк **владеет** многими банкоматами (1:N) — банк-владелец.
  - 1 банк **имеет** много клиентов (1:N), если клиентов связываем с «домашним» банком.

- **Клиент/Пользователь (User/Client)**
  - 1 пользователь может иметь несколько счетов (1:N).
  - 1 пользователь может иметь несколько карт (1:N).

- **Счет (Account)**
  - 1 счет принадлежит одному пользователю (N:1).
  - 1 счет может быть связан с несколькими картами (1:N) (например, виртуальная/физическая).
  - 1 счет участвует во многих операциях (1:N).

- **Карта (Card)**
  - 1 карта **принадлежит** одному счету (N:1).
  - 1 карта **эмитирована** одним банком (N:1).
  - 1 карта **используется** во многих операциях (1:N).

- **Банкомат (ATM)**
  - 1 банкомат **принадлежит** одному банку (N:1).
  - 1 банкомат участвует во многих операциях (1:N).

- **Операция (Transaction / Withdrawal)**
  - 1 операция **инициируется** картой (N:1).
  - 1 операция **списывает** со счета (N:1).
  - 1 операция **проходит** через банкомат (N:1).
  - 1 операция **имеет** комиссию, зависящую от эмитента карты и банка-владельца банкомата (0% либо 1.2%).

- **Справочники/служебные сущности**
  - **Роли** (admin/client) — 1 роль на пользователя (N:1).
  - **Состояние сессии** (активна/истекла, время последней активности).
  - **Логи/события** (для аналитики и демонстрации блокировок).

## 2) Предложение структуры таблиц MySQL (поля, типы, связи, индексы)

> Ниже — минимально достаточная структура, которую можно расширять. Типы ориентированы на MySQL 8.

### banks
- `id` BIGINT UNSIGNED PK AI
- `name` VARCHAR(255) NOT NULL
- `bic` VARCHAR(20) UNIQUE
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**Индексы:** `UNIQUE(bic)`, `INDEX(name)`

### users
- `id` BIGINT UNSIGNED PK AI
- `email` VARCHAR(255) UNIQUE NOT NULL
- `password_hash` VARCHAR(255) NOT NULL
- `role` ENUM('admin','client') NOT NULL
- `bank_id` BIGINT UNSIGNED NULL  — «домашний» банк клиента (опционально)
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**FK:** `bank_id → banks.id`
**Индексы:** `UNIQUE(email)`, `INDEX(role)`, `INDEX(bank_id)`

### accounts
- `id` BIGINT UNSIGNED PK AI
- `user_id` BIGINT UNSIGNED NOT NULL
- `iban` VARCHAR(34) UNIQUE NOT NULL
- `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00
- `currency` CHAR(3) NOT NULL DEFAULT 'RUB'
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**FK:** `user_id → users.id`
**Индексы:** `UNIQUE(iban)`, `INDEX(user_id)`

### cards
- `id` BIGINT UNSIGNED PK AI
- `account_id` BIGINT UNSIGNED NOT NULL
- `issuer_bank_id` BIGINT UNSIGNED NOT NULL
- `pan_hash` CHAR(64) NOT NULL UNIQUE  — хранить хеш PAN
- `last4` CHAR(4) NOT NULL
- `pin_hash` CHAR(60) NOT NULL
- `status` ENUM('active','blocked') NOT NULL DEFAULT 'active'
- `expires_at` DATE NOT NULL
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**FK:** `account_id → accounts.id`, `issuer_bank_id → banks.id`
**Индексы:** `UNIQUE(pan_hash)`, `INDEX(account_id)`, `INDEX(issuer_bank_id)`

### atms
- `id` BIGINT UNSIGNED PK AI
- `bank_id` BIGINT UNSIGNED NOT NULL  — владелец банкомата
- `code` VARCHAR(50) NOT NULL UNIQUE
- `location` VARCHAR(255) NOT NULL
- `status` ENUM('active','maintenance','blocked') NOT NULL DEFAULT 'active'
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**FK:** `bank_id → banks.id`
**Индексы:** `UNIQUE(code)`, `INDEX(bank_id)`

### transactions
- `id` BIGINT UNSIGNED PK AI
- `account_id` BIGINT UNSIGNED NOT NULL
- `card_id` BIGINT UNSIGNED NOT NULL
- `atm_id` BIGINT UNSIGNED NOT NULL
- `type` ENUM('withdraw') NOT NULL
- `amount` DECIMAL(12,2) NOT NULL
- `fee_rate` DECIMAL(5,4) NOT NULL  — 0.0000 или 0.0120
- `fee_amount` DECIMAL(12,2) NOT NULL
- `total_amount` DECIMAL(12,2) NOT NULL  — amount + fee
- `status` ENUM('pending','success','failed') NOT NULL
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**FK:** `account_id → accounts.id`, `card_id → cards.id`, `atm_id → atms.id`
**Индексы:** `INDEX(account_id)`, `INDEX(card_id)`, `INDEX(atm_id)`, `INDEX(created_at)`

### transaction_logs (для демонстрации блокировок/трассировки)
- `id` BIGINT UNSIGNED PK AI
- `transaction_id` BIGINT UNSIGNED NULL
- `message` VARCHAR(500) NOT NULL
- `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
**FK:** `transaction_id → transactions.id`
**Индексы:** `INDEX(transaction_id)`, `INDEX(created_at)`

### Аналитика
Для аналитики по банкам/банкоматам/времени достаточно представлений или агрегирующих запросов по `transactions` с JOIN на `banks/atms`. Индекс `transactions.created_at` помогает временным фильтрам, а индексы `atm_id`/`card_id` ускоряют группировки.

### Комиссия 0% / 1.2%
- Определяется сравнением `cards.issuer_bank_id` и `atms.bank_id`.
- Если равны → `fee_rate = 0.0000`, иначе `fee_rate = 0.0120`.
- Можно вычислять в приложении и записывать в `transactions` (для прозрачной аналитики).

### Процедура/триггер (требование задания)
- **Процедура** `sp_withdraw(account_id, card_id, atm_id, amount)`:
  - внутри транзакции делает `SELECT ... FOR UPDATE` по `accounts`;
  - рассчитывает комиссию по банку-эмитенту/владельцу банкомата;
  - вставляет запись в `transactions` и обновляет баланс.
- **Триггер** на `transactions` для записи в `transaction_logs` (минимальная демонстрация).

## 3) Конкурентный доступ (unsafe/safe режим)

### Unsafe режим (демонстрация гонки)
- В `withdraw.php` показать сценарий без блокировки:
  1. `SELECT balance FROM accounts WHERE id = ?`
  2. Проверка и `UPDATE accounts SET balance = balance - ?` без транзакции/блокировки.
- В параллельных запросах возможно списание сверх остатка.

### Safe режим (правильный)
- Используем транзакцию и блокировки строк:
  1. `BEGIN`.
  2. `SELECT balance FROM accounts WHERE id = ? FOR UPDATE`.
  3. Если баланс достаточен — `UPDATE accounts` и `INSERT INTO transactions`.
  4. `COMMIT`.
- При одновременных запросах второй дождется завершения первого.
- При ошибке — `ROLLBACK` и статус операции `failed`.
- Для демонстрации блокировок — писать в `transaction_logs` время входа/выхода из критической секции.

## 4) Дерево проекта (строго с путями)

```
/index.php
/atm/login.php
/atm/panel.php
/atm/withdraw.php
/site/login.php
/site/client.php
/site/admin.php
/site/banks.php
/site/atms.php
/site/analytics.php
/api/*.php
/includes/*.php
/assets/style.css
/assets/app.js
```

## 5) Ответственность каждого файла

### Корень
- `/index.php` — главная: кнопки «Банкомат» и «Сайт», форма регистрации/логина, установка cookie.

### ATM-сценарий
- `/atm/login.php` — вход по карте+PIN, старт сессии (2 минуты), подготовка ATM-сценария.
- `/atm/panel.php` — панель банкомата: текущий баланс, кнопки операций.
- `/atm/withdraw.php` — логика снятия, демонстрация unsafe/safe режимов, запись в 2 таблицы (`transactions`, `transaction_logs`).

### Web-сайт
- `/site/login.php` — вход пользователя, выбор роли (client/admin), установка сессии.
- `/site/client.php` — профиль клиента: счета/карты/история операций, AJAX для обновления баланса.
- `/site/admin.php` — админ-панель: управление банками/банкоматами/пользователями.
- `/site/banks.php` — CRUD банков.
- `/site/atms.php` — CRUD банкоматов, привязка к банку.
- `/site/analytics.php` — аналитика по банкам/банкоматам/времени.

### API
- `/api/*.php` — AJAX-эндпоинты для операций (withdraw, balance, analytics), все GET/POST проверки, JSON-ответы, обработка ошибок.

### Includes
- `/includes/db.php` — PDO-подключение, настройки транзакций, error mode.
- `/includes/auth.php` — логика аутентификации, роли, проверки доступа.
- `/includes/session.php` — сессии 2 минуты, предупреждение об истечении.
- `/includes/helpers.php` — общие утилиты (форматирование, валидация).

### Assets
- `/assets/style.css` — базовые стили.
- `/assets/app.js` — JS для AJAX, предупреждения тайм-аута, обновление UI.

## Соответствие требованиям задания (чек-лист)

- **CSS + JS**: `/assets/style.css`, `/assets/app.js`.
- **Home + auth**: `/index.php`, `/site/login.php`, `/atm/login.php`.
- **2 роли**: `users.role`, `/site/admin.php`, `/site/client.php`.
- **Запись в 2 таблицы**: `transactions` + `transaction_logs`.
- **AJAX**: `/api/*.php`.
- **Триггер/процедура**: `sp_withdraw` + trigger на `transactions`.
- **GET + POST**: формы + AJAX-запросы.
- **Обработка ошибок**: проверка средств, статусы операций, JSON error.
- **Сессия 2 минуты + предупреждение**: `/includes/session.php` + JS таймер.
- **Cookie**: для запоминания пользователя/банка (например, last bank/role).
- **Демонстрация блокировок**: unsafe/safe режимы + `SELECT ... FOR UPDATE`.
