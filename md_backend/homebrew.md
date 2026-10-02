# Гоблин на Mac через Homebrew — установка

Без XAMPP и Apache: PHP и MariaDB из Homebrew, сайт — встроенным сервером PHP.
В macOS нет своего PHP (с 12-й версии) и нет MySQL, поэтому их ставит Homebrew.

## 1. Homebrew, PHP и MariaDB

Homebrew — с сайта [brew.sh](https://brew.sh), одна команда в Терминале. Потом:

```bash
brew install php mariadb
brew services start mariadb        # база будет запускаться сама после перезагрузки
```

## 2. База

```bash
mariadb -u root -e "CREATE DATABASE goblin_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb -u root goblin_v2 < sql/schema.sql
```

- Команды — из папки Гоблина.
- `schema.sql` — пустая база нужного вида, со всеми миграциями.
- Перенести данные со старой установки (XAMPP) — дамп вместо `schema.sql`:
  `mysqldump -u root goblin_v2 > goblin.sql` там, `mariadb -u root goblin_v2 < goblin.sql` здесь.

## 3. Настройки

В `config.php` поправить путь к PHP (остальное подходит как есть: база `goblin_v2` на `127.0.0.1`, пользователь `root` без пароля):

```php
'php_cli' => '/opt/homebrew/bin/php',     // на Mac с Intel — /usr/local/bin/php
```

- Пароль базы, если задан, — в `secrets.php` (`pass`).
- Пароль админки: `php -r 'echo password_hash("пароль", PASSWORD_DEFAULT);'` → `admin_pass_hash` в `secrets.php`.
- Дальше всё правится в админке → «Настройки».

## 4. Запуск

```bash
php -S localhost:8080 -t public -d upload_max_filesize=100M -d post_max_size=105M bin/router.php
```

- Команда — из папки Гоблина; окно Терминала не закрывать, пока работаете.
- Открыть [http://localhost:8080/](http://localhost:8080/), админка — [http://localhost:8080/admin.php](http://localhost:8080/admin.php).
- `bin/router.php` делает то же, что `.htaccess` под Apache: служебные папки прогонов и скрипты
  в рабочих папках закрыты.
- Адрес `localhost` — значит агенты на этом же компьютере: всё на диске, как в XAMPP.

## Проверить

- Страница открывается, в админке «Система» база зелёная.
- `php -v` — версия 8.2 или новее (на ней Гоблин проверен).
