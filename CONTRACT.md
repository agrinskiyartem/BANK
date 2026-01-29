# Контракт проекта “Банкоматы” (Вариант 36)

## A) Роли и типы авторизации
1) **Роли пользователей**:
   - `admin`
   - `client`
2) **Авторизация “Сайт”** (логин/пароль) через таблицу `users`.
3) **Авторизация “Банкомат”** (номер карты/PIN) через таблицу `cards` (PIN хранится как `hash`).
4) **Сессия хранит**:
   - `user_id`, `role` (для сайта)
   - `atm_card_id` (или `card_id`) и `selected_atm_id` (для банкомата)
   - `last_activity` (для таймаута 2 минуты)
5) **Таймаут**: 120 секунд. После истечения — серверный logout + клиентское предупреждение через JS.

## B) Таблицы (строгие названия) и назначение
1) `banks`
2) `atms`
3) `users`
4) `cards`
5) `accounts` — баланс карты
6) `withdrawals` — операции снятия
7) `audit_log` — демонстрация записи в 2 таблицы и/или логирование

Профиль клиента хранится в `users` (поле `full_name`) для `role=client`.

## C) Ключевые поля (обязательные имена)
> Ниже перечислены обязательные поля. Дополнительные поля можно добавлять при необходимости, но **нельзя переименовывать** перечисленные.

- **`banks`**: `id`, `name`, `bic` (nullable), `created_at`
- **`atms`**: `id`, `bank_owner_id` (FK → `banks.id`), `name`, `address`, `is_active`, `created_at`
- **`users`**: `id`, `login`, `password_hash`, `role`, `full_name`, `created_at`
- **`cards`**: `id`, `card_number` (UNIQUE), `pin_hash`, `bank_issuer_id` (FK → `banks.id`), `client_id` (FK → `users.id`), `created_at`, `is_blocked`
- **`accounts`**: `id`, `card_id` (FK → `cards.id` UNIQUE), `balance`, `updated_at`
- **`withdrawals`**: `id`, `card_id`, `atm_id`, `bank_issuer_id`, `bank_owner_id`, `amount`, `commission_amount`, `total_amount`, `created_at`, `mode_safe` (0/1)
- **`audit_log`**: `id`, `actor_user_id` (nullable FK → `users.id`), `action`, `entity`, `entity_id`, `details`, `created_at`

### Рекомендуемые дополнительные поля (необязательные)
- `banks`: `updated_at`
- `atms`: `updated_at`
- `users`: `updated_at`, `is_active`
- `cards`: `updated_at`, `expires_at`
- `accounts`: `currency` (например `RUB`)
- `withdrawals`: `status` (например `success|failed`), `error_reason` (nullable)
- `audit_log`: `ip_address`, `user_agent`

## D) Бизнес-правила (строго)
1) Комиссия = 0%, если `bank_issuer_id == bank_owner_id`.
2) Иначе комиссия = 1.2% от `amount`, округление до 2 знаков.
3) `total_amount = amount + commission_amount`.
4) Нельзя уйти в минус: `balance >= total_amount`.
5) Снятие (ATM) должно выполнять транзакцию и запись минимум в 2 таблицы: `accounts` (UPDATE) + `withdrawals` (INSERT).
6) Демонстрация конкурентного доступа:
   - **safe режим**: `SELECT balance FROM accounts WHERE card_id=? FOR UPDATE` + транзакция
   - **unsafe режим**: без `FOR UPDATE`
   - переключение GET параметром `safe=1/0` на `/atm/withdraw.php` (default `safe=1`)
7) Для демонстрации триггера/процедуры в `schema.sql` допускается логирование в `audit_log` при CRUD в админке.

## E) Cookie и сессии
- cookie name: `last_atm_id` (TTL 10 минут)
- (опционально) cookie name: `last_login` (TTL 10 минут) — только если нужно
- сессии: `last_activity`; при истечении — JS предупреждение и серверный logout

## F) Маршруты/файлы (строго такие пути)
- `/index.php`
- `/atm/login.php`
- `/atm/panel.php`
- `/atm/withdraw.php`
- `/site/login.php`
- `/site/client.php`
- `/site/admin.php`
- `/site/banks.php`
- `/site/atms.php`
- `/site/analytics.php`
- `/api/withdrawals.php`
- `/api/admin_withdrawals.php`
- (опционально) `/api/analytics.php`
- `/includes/db.php`
- `/includes/auth.php`
- `/includes/session.php`
- `/includes/helpers.php`
- `/assets/style.css`
- `/assets/app.js`

## G) Контракты API (параметры и ответы)
1) **GET `/api/withdrawals.php`**:
   - params: `date_from` (YYYY-MM-DD optional), `date_to`, `bank_id`, `atm_id`
   - returns: JSON `{ ok: true, items: [...] }` где `item` содержит:
     - `created_at`, `amount`, `commission_amount`, `total_amount`, `atm_name`, `bank_owner_name`
2) **GET `/api/admin_withdrawals.php`**:
   - params: `date_from`, `date_to`, `bank_owner_id`, `bank_issuer_id`, `atm_id`
   - returns: JSON `{ ok: true, items: [...] }` где `item` содержит:
     - `created_at`, `client_full_name`, `card_number_masked`, `amount`, `commission_amount`, `total_amount`, `atm_name`, `bank_owner_name`, `bank_issuer_name`, `mode_safe`
3) **(опционально) GET `/api/analytics.php`**:
   - params: `date_from`, `date_to`
   - returns: JSON с агрегатами (`commissions_by_bank`, `top_atms`, `ops_timeseries`)

---

## НЕЛЬЗЯ МЕНЯТЬ В ДАЛЬНЕЙШИХ ПРОМТАХ
### Роли и авторизация
- Роли: `admin`, `client`
- Авторизация сайта через таблицу `users`
- Авторизация банкомата через таблицу `cards` (PIN хранится как hash)
- Сессия: `user_id`, `role`, `atm_card_id`/`card_id`, `selected_atm_id`, `last_activity`
- Таймаут: 120 секунд, с серверным logout + JS предупреждением

### Таблицы (строгие названия)
- `banks`, `atms`, `users`, `cards`, `accounts`, `withdrawals`, `audit_log`

### Ключевые поля (строго перечисленные)
- `banks`: `id`, `name`, `bic`, `created_at`
- `atms`: `id`, `bank_owner_id`, `name`, `address`, `is_active`, `created_at`
- `users`: `id`, `login`, `password_hash`, `role`, `full_name`, `created_at`
- `cards`: `id`, `card_number`, `pin_hash`, `bank_issuer_id`, `client_id`, `created_at`, `is_blocked`
- `accounts`: `id`, `card_id`, `balance`, `updated_at`
- `withdrawals`: `id`, `card_id`, `atm_id`, `bank_issuer_id`, `bank_owner_id`, `amount`, `commission_amount`, `total_amount`, `created_at`, `mode_safe`
- `audit_log`: `id`, `actor_user_id`, `action`, `entity`, `entity_id`, `details`, `created_at`

### Бизнес-правила (строго)
- Комиссия 0% при `bank_issuer_id == bank_owner_id`
- Иначе комиссия 1.2% от `amount` с округлением до 2 знаков
- `total_amount = amount + commission_amount`
- Баланс не может стать отрицательным: `balance >= total_amount`
- Снятие пишет минимум в 2 таблицы: `accounts` (UPDATE) + `withdrawals` (INSERT)
- Safe/unsafe режимы на `/atm/withdraw.php` через GET `safe=1/0` (default `safe=1`)
- Safe режим использует `SELECT ... FOR UPDATE`

### Cookies и сессии
- cookie: `last_atm_id` (TTL 10 минут)
- (опционально) cookie: `last_login` (TTL 10 минут)
- сессия: `last_activity` + JS предупреждение при истечении

### Маршруты/файлы (строго)
- `/index.php`
- `/atm/login.php`
- `/atm/panel.php`
- `/atm/withdraw.php`
- `/site/login.php`
- `/site/client.php`
- `/site/admin.php`
- `/site/banks.php`
- `/site/atms.php`
- `/site/analytics.php`
- `/api/withdrawals.php`
- `/api/admin_withdrawals.php`
- (опционально) `/api/analytics.php`
- `/includes/db.php`
- `/includes/auth.php`
- `/includes/session.php`
- `/includes/helpers.php`
- `/assets/style.css`
- `/assets/app.js`

### Контракты API (строго)
- `GET /api/withdrawals.php` params: `date_from`, `date_to`, `bank_id`, `atm_id` → JSON `{ ok: true, items: [...] }` с полями `created_at`, `amount`, `commission_amount`, `total_amount`, `atm_name`, `bank_owner_name`
- `GET /api/admin_withdrawals.php` params: `date_from`, `date_to`, `bank_owner_id`, `bank_issuer_id`, `atm_id` → JSON `{ ok: true, items: [...] }` с полями `created_at`, `client_full_name`, `card_number_masked`, `amount`, `commission_amount`, `total_amount`, `atm_name`, `bank_owner_name`, `bank_issuer_name`, `mode_safe`
- (опционально) `GET /api/analytics.php` params: `date_from`, `date_to` → JSON агрегатов `commissions_by_bank`, `top_atms`, `ops_timeseries`
