# Финальная проверка и инструкция запуска

## 1) Инструкция запуска (Apache/Nginx + PHP 8 + MySQL)

### 1.1 Импорт базы данных (`schema.sql`)
1. Создайте базу и импортируйте схему:
   ```bash
   mysql -u root -p < /workspace/BANK/schema.sql
   ```
   Скрипт создаёт БД `bank_atm`, таблицы и начальные данные, включая триггер `trg_withdrawals_commission`.【F:schema.sql†L1-L189】

2. При необходимости пересоздайте тестовые пароли/PIN (см. пункт 1.3).

### 1.2 Настройка подключения в `/includes/db.php`
Файл поддерживает значения из переменных окружения, либо дефолтные значения:
- `DB_HOST` (по умолчанию `127.0.0.1`)
- `DB_NAME` (по умолчанию `bank_atm`)
- `DB_USER` (по умолчанию `root`)
- `DB_PASS` (по умолчанию пусто)
- `DB_CHARSET` (по умолчанию `utf8mb4`)【F:includes/db.php†L11-L24】

**Вариант A:** задать переменные окружения в конфиге веб‑сервера.

**Вариант B:** изменить значения прямо в `includes/db.php` (не рекомендовано на проде).

### 1.3 Тестовые логины/пароли (админ и клиент)
В `schema.sql` уже есть пользователи и карты, но хеши пароля/PIN зашиты, поэтому для защиты лучше установить известные пароли вручную:

**Пользователи (сайт)** — таблица `users`:
- `admin` — администратор.
- `client1`, `client2` — клиенты.【F:schema.sql†L182-L189】

**Карты (банкомат)** — таблица `cards`:
- `4111111111111111` — карта клиента 1.
- `5500000000000004` — карта клиента 2.【F:schema.sql†L191-L194】

Пример установки понятных паролей (выполняется в MySQL после импорта):
```sql
UPDATE users SET password_hash = '<hash>' WHERE login = 'admin';
UPDATE users SET password_hash = '<hash>' WHERE login = 'client1';
UPDATE users SET password_hash = '<hash>' WHERE login = 'client2';
UPDATE cards SET pin_hash = '<hash>' WHERE card_number = '4111111111111111';
UPDATE cards SET pin_hash = '<hash>' WHERE card_number = '5500000000000004';
```
Хеши нужно получить через `password_hash()` в PHP (например, локальным скриптом или REPL).【F:schema.sql†L1-L5】

---

## 2) Чек‑лист соответствия требованиям задания

### 2.1 Где CSS + JS
- Общие стили: `assets/style.css` (включается в `render_header`).【F:assets/style.css†L1-L148】【F:includes/helpers.php†L119-L123】
- Общий JS (таймер сессии, fetch‑обёртки): `assets/app.js` (подключается в `render_header`).【F:assets/app.js†L1-L82】【F:includes/helpers.php†L145-L147】
- Дополнительные inline‑скрипты для клиентских страниц (валидация/таблицы):
  - `/atm/login.php` (валидация формы входа).【F:atm/login.php†L174-L215】
  - `/atm/panel.php` (валидация снятия).【F:atm/panel.php†L108-L187】
  - `/site/login.php`, `/site/client.php`, `/site/admin.php`, `/site/analytics.php` (AJAX и UI‑логика).【F:site/login.php†L83-L107】【F:site/client.php†L98-L278】【F:site/admin.php†L93-L230】【F:site/analytics.php†L233-L308】

### 2.2 Где 2 роли (admin/client)
- Роли объявлены в БД (`users.role`).【F:schema.sql†L52-L65】
- Роль проверяется в `require_role()` и в страницах `/site/admin.php`, `/site/client.php`.【F:includes/auth.php†L80-L89】【F:site/admin.php†L7-L12】【F:site/client.php†L7-L15】

### 2.3 Где cookie
- Cookie `last_atm_id` ставится на 10 минут, чтобы помнить выбранный банкомат. 【F:includes/helpers.php†L81-L89】
- Используется в `/atm/login.php` для автоподстановки банкомата.【F:atm/login.php†L25-L57】

### 2.4 Где сессия 2 минуты + предупреждение
- Таймаут 120 секунд, предупреждение за 30 секунд — в `includes/session.php`.【F:includes/session.php†L5-L35】
- Отрисовка предупреждения в `render_header()` + показ/скрытие в `assets/app.js`.【F:includes/helpers.php†L107-L129】【F:assets/app.js†L1-L47】

### 2.5 Где AJAX
- Клиент: `/site/client.php` → `GET /api/withdrawals.php` через `fetch`.【F:site/client.php†L228-L255】【F:api/withdrawals.php†L7-L79】
- Админ: `/site/admin.php` → `GET /api/admin_withdrawals.php` через `fetch`.【F:site/admin.php†L203-L214】【F:api/admin_withdrawals.php†L7-L82】
- Аналитика: `/site/analytics.php` → `GET /api/analytics.php`.【F:site/analytics.php†L283-L297】【F:api/analytics.php†L7-L118】

### 2.6 Где запись в 2 таблицы (в транзакции)
- Снятие наличных обновляет `accounts` и вставляет запись в `withdrawals`.【F:atm/withdraw.php†L85-L135】

### 2.7 Где триггер/процедура
- В БД есть триггер `trg_withdrawals_commission`, рассчитывающий комиссию и итог в `withdrawals`.【F:schema.sql†L154-L163】

### 2.8 Где safe/unsafe демонстрация блокировок
- `/atm/withdraw.php` поддерживает `safe=1/0` (GET). При `safe=1` используется `SELECT ... FOR UPDATE`, при `safe=0` — без блокировки строк.【F:atm/withdraw.php†L23-L63】

### 2.9 Где GET и POST
- Примеры **POST**: регистрация на главной (`index.php`), вход на `/site/login.php`, снятие на `/atm/withdraw.php`.【F:index.php†L38-L140】【F:site/login.php†L25-L52】【F:atm/withdraw.php†L15-L33】
- Примеры **GET**: фильтрация операций через `/api/withdrawals.php`, `/api/admin_withdrawals.php` и параметры `safe=1/0` на `/atm/withdraw.php`.【F:api/withdrawals.php†L23-L79】【F:api/admin_withdrawals.php†L15-L82】【F:atm/withdraw.php†L23-L25】

---

## 3) Короткий сценарий демонстрации (пошагово)

### 3.1 Демонстрация race condition: unsafe vs safe
1. Зайдите на страницу банкомата: `/atm/login.php`.
2. Выберите банкомат и войдите картой клиента (например `4111111111111111`, PIN после установки хеша).
3. Откройте два окна/вкладки и подготовьте одинаковую сумму для снятия.
4. **Unsafe режим**:
   - В одном окне отправьте снятие на `/atm/withdraw.php?safe=0`.
   - Почти одновременно отправьте вторую операцию на `/atm/withdraw.php?safe=0`.
   - Поясните, что отсутствие `FOR UPDATE` может позволить обоим запросам пройти конкурирующе и привести к некорректному балансу (классический race condition).【F:atm/withdraw.php†L23-L63】
5. **Safe режим**:
   - Повторите снятия, но на `/atm/withdraw.php?safe=1` (по умолчанию).
   - Объясните, что `SELECT ... FOR UPDATE` сериализует доступ к строке счёта и предотвращает гонку.【F:atm/withdraw.php†L23-L63】

### 3.2 Демонстрация серверной валидации (сломать JS)
1. Откройте `/atm/login.php` или `/site/login.php`.
2. Отключите JavaScript в браузере.
3. Отправьте форму с неправильными данными (например, короткий PIN или пустой пароль).
4. Серверная валидация вернёт ошибки (PHP проверяет формат и обязательность).【F:atm/login.php†L62-L118】【F:site/login.php†L25-L52】

### 3.3 Базовый поток демонстрации
1. Главная страница `/index.php` → регистрация клиента (POST).【F:index.php†L38-L140】
2. `/site/login.php` → вход как `admin` → `site/admin.php` (AJAX отчёты).【F:site/login.php†L19-L49】【F:site/admin.php†L93-L214】
3. `/site/login.php` → вход как `client1` → `site/client.php` (AJAX история).【F:site/login.php†L19-L49】【F:site/client.php†L98-L255】
4. `/atm/login.php` → снятие средств → отображение комиссии/итога (триггер + транзакция).【F:atm/login.php†L62-L140】【F:atm/withdraw.php†L85-L160】【F:schema.sql†L154-L163】
