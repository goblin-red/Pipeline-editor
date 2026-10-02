<?php
/* Установка Гоблина: проверка сервера и одна запись всего нужного — для страницы install.php и для
   `php bin/server.php install`.
   Отдаёт: installChecks(), installPhpCli(), installRun(). Поставлен ли — installNeeded() (lib/core/config.php).
   Не делает: не показывает форму и не спрашивает — это install.php и bin/server.php.

   Порядок installRun(): проверить базу (MySQL — завести, если просили) → записать настройки
   (config_admin.php, ключи и пароли — secrets.php) → таблицы и каталог схем (schemaInstall) →
   документация (её издатель, отдельным процессом) → отметка data/installed.lock. */

declare(strict_types=1);

require_once __DIR__ . '/../admin/settings.php';   // запись config_admin.php и secrets.php — та же, что у админки

/** Проверки сервера: [['what', 'ok', 'say']…]. Хоть одна не ok — ставить нельзя. */
function installChecks(): array
{
    $root = dirname(__DIR__, 2);
    $out = [];
    $add = static function (string $what, bool $ok, string $say = '') use (&$out) {
        $out[] = ['what' => $what, 'ok' => $ok, 'say' => $say];
    };
    $add('PHP 8.1+', PHP_VERSION_ID >= 80100, PHP_VERSION);
    $add('PDO SQLite / MySQL', extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
        implode(', ', array_filter(['pdo_sqlite', 'pdo_mysql'], 'extension_loaded')));
    foreach (['curl', 'mbstring', 'zip', 'json'] as $ext) $add($ext, extension_loaded($ext));
    foreach (['', 'data', 'storage', 'workspace', 'public/workfiles'] as $dir) {
        $path = $root . ($dir === '' ? '' : '/' . $dir);
        if (!is_dir($path)) @mkdir($path, 0775, true);
        $add(t('install.check.write', ['dir' => $dir === '' ? t('install.check.root') : $dir . '/']), is_writable($path));
    }
    $cli = installPhpCli();
    $add(t('install.check.php_cli'), true, $cli ?: t('install.check.php_cli_none'));   // не мешает установке
    return $out;
}

/** php-cli для фоновых заданий: он сам (установка из Терминала) или рядом с PHP сервера. Пусто — не нашли. */
function installPhpCli(): string
{
    $list = [PHP_SAPI === 'cli' ? PHP_BINARY : '', PHP_BINDIR . '/php', '/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'];
    foreach ($list as $path) {
        if ($path !== '' && @is_file($path) && @is_executable($path)) return $path;
    }
    return '';
}

/**
 * Поставить. $in — ответы человека:
 *   where: local|web; serve: php|apache (только local); driver: sqlite|mysql; sqlite_file;
 *   host, port, name, user, pass, create (MySQL); admin_login, admin_pass; lang: ru|en;
 *   ai_key_deepseek, ai_key_openrouter, voice_api_key, jev_api_key — по желанию.
 * Отдаёт ['notes' => [заметки]] или бросает RuntimeException с понятной причиной.
 */
function installRun(array $in): array
{
    if (!installNeeded()) throw new RuntimeException(t('install.err.done'));
    foreach (installChecks() as $check) {
        if (!$check['ok']) throw new RuntimeException(t('install.err.check', ['what' => $check['what']]));
    }
    $val = static fn(string $key, string $or = ''): string => trim((string) ($in[$key] ?? '')) ?: $or;
    $driver = $val('driver') === 'mysql' ? 'mysql' : 'sqlite';
    $login = $val('admin_login', 'admin');
    $pass = (string) ($in['admin_pass'] ?? '');
    if (mb_strlen($pass) < 8) throw new RuntimeException(t('install.err.pass'));
    $lang = $val('lang') === 'en' ? 'en' : 'ru';

    // База: SQLite — файл в data/; MySQL — пробное соединение, при желании — завести базу.
    $open = ['driver' => $driver, 'admin_login' => $login, 'lang_default' => $lang, 'php_cli' => installPhpCli()];
    $secret = ['admin_pass_hash' => password_hash($pass, PASSWORD_DEFAULT)];
    if ($driver === 'sqlite') {
        $open['sqlite_file'] = $val('sqlite_file', 'data/goblin.sqlite');
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException(t('install.err.ext', ['ext' => 'pdo_sqlite']));
    } else {
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException(t('install.err.ext', ['ext' => 'pdo_mysql']));
        $open += ['host' => $val('host', 'localhost'), 'port' => (int) $val('port', '3306'), 'name' => $val('name', 'goblin'),
                  'user' => $val('user', 'root'), 'charset' => 'utf8mb4'];
        $secret['pass'] = (string) ($in['pass'] ?? '');
        installMysql($open, $secret['pass'], !empty($in['create']));
    }

    // Ключи ИИ — только те, что вписали; рабочее подключение — то, у которого есть ключ.
    foreach (['ai_key_deepseek', 'ai_key_openrouter', 'voice_api_key', 'jev_api_key'] as $key) {
        if ($val($key) !== '') $secret[$key] = $val($key);
    }
    $open['ai_connection'] = ($secret['ai_key_deepseek'] ?? '') === '' && ($secret['ai_key_openrouter'] ?? '') !== ''
        ? 'openrouter' : 'deepseek';

    // Сбой дальше — откат записанного: иначе secrets.php закрыл бы повтор установки.
    $written = array_filter([configPath('config_admin.php'), configPath('secrets.php')], static fn($path) => !is_file($path));
    if ($driver === 'sqlite') {
        $file = str_starts_with($open['sqlite_file'], '/') ? $open['sqlite_file'] : configPath($open['sqlite_file']);
        if (!is_file($file)) array_push($written, $file, $file . '-wal', $file . '-shm');
    }
    try {
        settingsWriteOpen($open);
        settingsWriteSecrets($secret);
        config(true);

        // Таблицы и каталог; база с уже готовыми таблицами (переустановка) — не трогаем.
        $notes = [];
        try {
            dbValue('SELECT COUNT(*) FROM schema_migrations');
            $notes[] = t('install.note.tables_kept');
        } catch (Throwable) {
            schemaInstall();
        }
    } catch (Throwable $error) {
        foreach ($written as $path) @unlink($path);
        config(true);
        throw new RuntimeException(t('install.err.failed', ['error' => $error->getMessage()]));
    }
    if (!installDocs()) $notes[] = t('install.note.docs_later');

    file_put_contents(configPath(INSTALL_LOCK), date('c') . "\n");
    return ['notes' => $notes];
}

/** MySQL: соединиться; create — завести базу, если её нет. Ошибка — понятной фразой. */
function installMysql(array $c, string $pass, bool $create): void
{
    $server = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']);
    try {
        $pdo = new PDO($server, $c['user'], $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        if ($create) $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $c['name'])
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . str_replace('`', '', $c['name']) . '`');
    } catch (Throwable $error) {
        throw new RuntimeException(t('install.err.db', ['error' => $error->getMessage()]));
    }
}

/** Документация разработчика — её издатель (md_backend/документация/publish.php), ru и en. false — не вышло. */
function installDocs(): bool
{
    $cli = (string) (config()['php_cli'] ?? '');
    $script = dirname(__DIR__, 2) . '/md_backend/документация/publish.php';
    if ($cli === '' || !is_file($script) || !function_exists('exec')) return false;
    foreach (['ru', 'en'] as $lang) {
        $code = 1;
        @exec(escapeshellarg($cli) . ' ' . escapeshellarg($script) . ' --lang=' . $lang . ' 2>&1', $lines, $code);
        if ($code !== 0) return false;
    }
    return true;
}
