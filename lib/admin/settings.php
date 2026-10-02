<?php
/* Настройки установки в админке: схема полей, показ, проверка и запись.
   Отдаёт: SETTINGS, settingsView(), settingsSave(), settingsTestDb(), settingsTestDbAction(), settingsListsSave().
   Не делает: не проверяет вход — это admin.php; личные настройки людей не трогает.

   Открытые значения — config_admin.php (только отличия от config.php), ключи и пароли — secrets.php:
   он в снимок не входит. Поле, заданное переменной окружения, заперто: файл его не пересилит. */

declare(strict_types=1);

/** Группы и поля. Тип: text, int, bool, secret (ключ, пароль), password (хранится хеш), lines (список строк),
    connections (подключения ИИ: открытая часть — config_admin.php, ключи — ai_key_<имя> в secrets.php). */
const SETTINGS = [
    'db' => [
        'driver' => 'text', 'sqlite_file' => 'text',
        'host' => 'text', 'port' => 'int', 'name' => 'text', 'user' => 'text', 'pass' => 'secret',
        'charset' => 'text', 'v1_name' => 'text',
    ],
    'paths' => [
        'php_cli' => 'text', 'instructions_dir' => 'text', 'workspace_dir' => 'text', 'workfiles_dir' => 'text',
        'files_dir' => 'text', 'file_roots' => 'lines', 'tasks_autoclean' => 'bool', 'agents_where' => 'text',
    ],
    'ai' => ['ai_connection' => 'text', 'ai_connections' => 'connections'],
    'voice' => [
        'voice_engine' => 'text', 'voice_api_url' => 'text', 'voice_model' => 'text', 'voice_languages' => 'text',
        'voice_tts_model' => 'text', 'voice_tts_voice' => 'text', 'voice_api_key' => 'secret',
    ],
    'jev' => ['jev_api_url' => 'text', 'jev_model' => 'text', 'jev_api_key' => 'secret'],
    'ftp' => [
        'ftp_host' => 'text', 'ftp_user' => 'text', 'ftp_pass' => 'secret', 'ftp_root' => 'text',
        'ftp_port' => 'int', 'ftp_protocol' => 'text', 'ftp_passive' => 'bool',
    ],
    'admin' => ['admin_login' => 'text', 'admin_pass_hash' => 'password'],
    'guests' => ['guest_days' => 'int', 'guest_ai' => 'bool'],
    'files' => ['files_limit_count' => 'int', 'files_limit_kb' => 'int'],
    'backup' => ['backup_extra_databases' => 'lines'],
];

/** Поля с выбором из списка: админка рисует выпадашку, сервер другого не примет. */
const SETTINGS_CHOICES = ['voice_engine' => ['browser', 'openai'], 'driver' => ['mysql', 'sqlite']];

/** Поля, без которых установка не работает: пустыми не сохраняются. */
const SETTINGS_REQUIRED = ['host', 'port', 'name', 'user', 'charset', 'php_cli', 'instructions_dir',
    'workspace_dir', 'workfiles_dir', 'files_dir', 'admin_login'];

/* ── Показ ───────────────────────────────────────────────────── */

/** Группы с полями, значениями и замками окружения; текст config.txt. */
function settingsView(): array
{
    $c = config(true);
    $groups = [];
    foreach (SETTINGS as $group => $fields) {
        $list = [];
        foreach ($fields as $key => $type) {
            $value = $c[$key] ?? match ($type) { 'lines' => [], 'bool' => false, default => '' };
            $field = ['key' => $key, 'type' => $type, 'value' => $value, 'env' => configFromEnv($key)];
            // Какой режим вышел для этого адреса админки: веб или локально.
            if ($key === 'agents_where') $field['now'] = agentsRemote() ? 'remote' : 'local';
            if (isset(SETTINGS_CHOICES[$key])) $field['options'] = SETTINGS_CHOICES[$key];
            if ($type === 'connections') $field['value'] = settingsConnectionsView($c);
            // Хеш пароля не показываем: только задан он или нет.
            if ($type === 'password') $field = ['value' => '', 'set' => (string) $value !== ''] + $field;
            $list[] = $field;
        }
        $groups[] = ['key' => $group, 'fields' => $list];
    }
    return ['groups' => $groups, 'lists' => (string) @file_get_contents(configPath('config.txt'))];
}

/* ── Запись группы ───────────────────────────────────────────── */

/** Сохранить поля одной группы. Сначала проверка новой картины целиком, потом запись. */
function settingsSave(string $group, array $values): array
{
    $fields = SETTINGS[$group] ?? throw new ApiError(t('admin.settings.err_group'), 'not_found');
    $open = [];
    $secret = [];
    foreach ($fields as $key => $type) {
        if (!array_key_exists($key, $values) || configFromEnv($key)) continue;
        if ($type === 'connections') {
            [$open[$key], $keys] = settingsConnections($values[$key]);
            $secret += $keys;
            continue;
        }
        $value = settingsClean($key, $type, $values[$key]);
        if ($type === 'password') {
            // Пустое поле — пароль прежний; новый хранится только хешем.
            if ($value !== '') $secret[$key] = password_hash($value, PASSWORD_DEFAULT);
        } elseif ($type === 'secret') {
            $secret[$key] = $value;
        } else {
            $open[$key] = $value;
        }
    }
    $problem = settingsCheck($group, $open + $secret + config());
    if ($problem !== '') throw new ApiError($problem);

    settingsWriteOpen($open);
    settingsWriteSecrets($secret);
    config(true);
    return ['say' => t('admin.say.settings_saved')];
}

/** Привести значение к типу поля. */
function settingsClean(string $key, string $type, mixed $value): mixed
{
    if ($type === 'bool') return filter_var($value, FILTER_VALIDATE_BOOL);
    if ($type === 'lines') {
        $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);
        return array_values(array_filter(array_map(static fn($one) => mb_substr(trim((string) $one), 0, 1000), $lines),
            static fn($one) => $one !== ''));
    }
    $value = mb_substr(trim((string) $value), 0, 1000);
    if ($type === 'int') {
        if (!ctype_digit($value)) throw new ApiError(t('admin.settings.err_int', ['key' => $key]));
        return (int) $value;
    }
    if (in_array($key, SETTINGS_REQUIRED, true) && $value === '') {
        throw new ApiError(t('admin.settings.err_empty', ['key' => $key]));
    }
    return $value;
}

/** Проверка группы по новой картине настроек. Пустая строка — всё в порядке. */
function settingsCheck(string $group, array $c): string
{
    if ($group === 'db') return settingsTestDb($c);
    if ($group === 'paths') {
        if (!is_file($c['php_cli']) || !is_executable($c['php_cli'])) {
            return t('admin.settings.err_php', ['path' => $c['php_cli']]);
        }
        $dirs = [$c['instructions_dir'], $c['workspace_dir'], $c['workfiles_dir'], $c['files_dir'], ...$c['file_roots']];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) return t('admin.settings.err_dir', ['path' => $dir]);
        }
        if (!in_array($c['agents_where'] ?? 'auto', ['auto', 'local', 'remote'], true)) return t('admin.settings.err_where');
    }
    foreach (SETTINGS_CHOICES as $key => $options) {
        if (array_key_exists($key, $c) && !in_array($c[$key], $options, true)) {
            return t('admin.settings.err_choice', ['key' => $key, 'options' => implode(', ', $options)]);
        }
    }
    $problem = settingsCheckAi($c);
    if ($problem !== '') return $problem;
    foreach (['voice_api_url', 'jev_api_url'] as $key) {
        $url = (string) ($c[$key] ?? '');
        if ($url !== '' && !preg_match('~^https?://~i', $url)) return t('admin.settings.err_url', ['key' => $key]);
    }
    foreach (['port', 'ftp_port'] as $key) {
        $port = (int) ($c[$key] ?? 0);
        if ($port < 1 || $port > 65535) return t('admin.settings.err_port', ['key' => $key]);
    }
    return '';
}

/** Подключения ИИ: есть рабочее, у каждого название, адрес http(s), модель и ожидание 5–600 с. */
function settingsCheckAi(array $c): string
{
    $all = (array) ($c['ai_connections'] ?? []);
    if (!$all) return t('admin.settings.err_ai_none');
    if (!isset($all[$c['ai_connection'] ?? ''])) return t('admin.settings.err_ai_active');
    foreach ($all as $id => $one) {
        $name = (string) ($one['name'] ?? '');
        if ($name === '') return t('admin.settings.err_ai_name', ['id' => $id]);
        if (!preg_match('~^https?://~i', (string) ($one['url'] ?? ''))) return t('admin.settings.err_url', ['key' => $name]);
        if ((string) ($one['model'] ?? '') === '') return t('admin.settings.err_ai_model', ['key' => $name]);
        $wait = (int) ($one['timeout'] ?? 0);
        if ($wait < 5 || $wait > 600) return t('admin.settings.err_timeout', ['key' => $name]);
    }
    return '';
}

/** Подключения ИИ для админки: открытая часть и ключ каждого. */
function settingsConnectionsView(array $c): array
{
    $out = [];
    foreach ((array) ($c['ai_connections'] ?? []) as $id => $one) {
        $out[] = ['id' => (string) $id] + $one + ['key' => (string) ($c['ai_key_' . $id] ?? '')];
    }
    return $out;
}

/**
 * Подключения ИИ из формы → [открытая часть по именам, ключи ai_key_<имя>].
 * Удалённое подключение уносит свой ключ (null — строку из secrets.php убрать);
 * прежний ключ DeepSeek (ai_api_key) к этому времени уже переехал в ai_key_deepseek.
 */
function settingsConnections(mixed $rows): array
{
    $list = [];
    $keys = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $id = strtolower(trim((string) ($row['id'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $id) || isset($list[$id])) {
            throw new ApiError(t('admin.settings.err_ai_id', ['id' => $id]));
        }
        $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 60);
        $timeout = trim((string) ($row['timeout'] ?? ''));
        if (!ctype_digit($timeout)) throw new ApiError(t('admin.settings.err_int', ['key' => $name !== '' ? $name : $id]));
        $list[$id] = [
            'name'    => $name,
            'url'     => mb_substr(trim((string) ($row['url'] ?? '')), 0, 500),
            'model'   => mb_substr(trim((string) ($row['model'] ?? '')), 0, 200),
            'timeout' => (int) $timeout,
        ];
        $keys['ai_key_' . $id] = mb_substr(trim((string) ($row['key'] ?? '')), 0, 500);
    }
    foreach (array_keys((array) (config()['ai_connections'] ?? [])) as $id) {
        if (!isset($list[$id])) $keys['ai_key_' . $id] = null;
    }
    $keys['ai_api_key'] = null;
    return [$list, $keys];
}

/**
 * Пробное соединение с базой по этим настройкам: это должна быть база Гоблина.
 * Пустая строка — соединение есть; иначе текст ошибки.
 */
function settingsTestDb(array $c): string
{
    try {
        if (($c['driver'] ?? 'mysql') === 'sqlite') {
            // SQLite: файл должен уже быть базой Гоблина — пустой файл тут не заводим.
            $file = (string) ($c['sqlite_file'] ?? '') ?: 'data/goblin.sqlite';
            $file = str_starts_with($file, '/') ? $file : configPath($file);
            if (!is_file($file)) throw new RuntimeException($file);
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            return '';
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        return '';
    } catch (Throwable $error) {
        return t('admin.settings.err_db', ['error' => $error->getMessage()]);
    }
}

/** Кнопка «Проверить соединение»: введённые поля базы поверх нынешних. */
function settingsTestDbAction(array $values): array
{
    $c = config();
    foreach (SETTINGS['db'] as $key => $type) {
        if (array_key_exists($key, $values) && !configFromEnv($key)) $c[$key] = settingsClean($key, $type, $values[$key]);
    }
    $problem = settingsTestDb($c);
    if ($problem !== '') throw new ApiError($problem);
    return ['say' => t('admin.say.db_ok')];
}

/* ── config.txt ──────────────────────────────────────────────── */

/**
 * Сохранить config.txt целиком из редактора.
 * Сначала разбор тем же listsParse(), что и у сайта: есть ошибки — файл не трогаем, отдаём строки с ошибками.
 * Пропали ключи — на них могут ссылаться схемы: без confirm не пишем, отдаём список на подтверждение.
 */
function settingsListsSave(string $text, bool $confirm): array
{
    $text = rtrim(preg_replace('/\r\n|\r/', "\n", $text)) . "\n";
    $problems = [];
    $new = listsParse($text, $problems);
    if ($problems) {
        return ['saved' => false, 'problems' => array_map(static fn($one) => $one + [
            'text' => t('admin.settings.lp.' . $one['why'], $one),
        ], $problems)];
    }

    $removed = [];
    $old = lists(true);
    foreach ($old['sections'] as $section => $rows) {
        foreach (array_keys($rows) as $key) if (!isset($new['sections'][$section][$key])) $removed[] = "$section.$key";
    }
    foreach (array_keys($old['interface']) as $key) if (!isset($new['interface'][$key])) $removed[] = "interface.$key";
    if ($removed && !$confirm) return ['saved' => false, 'removed' => $removed];

    // Под той же блокировкой, что правка одной строки (listWrite): две записи не затрут друг друга.
    $fh = fopen(configPath('config.txt'), 'c+');
    if (!$fh) throw new ApiError(t('admin.settings.err_write', ['file' => 'config.txt']));
    try {
        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $text);
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    lists(true);
    return ['saved' => true, 'say' => t('admin.say.lists_saved')];
}

/* ── Файлы ───────────────────────────────────────────────────── */

/** Открытые значения — в config_admin.php. Храним только отличия от config.php: вернули как было — строка уходит. */
function settingsWriteOpen(array $open): void
{
    if (!$open) return;
    $root = dirname(__DIR__, 2);
    $base = require $root . '/config.php';
    $file = $root . '/config_admin.php';
    $saved = is_file($file) ? require $file : [];

    foreach ($open as $key => $value) {
        if (($base[$key] ?? null) === $value) unset($saved[$key]);
        else $saved[$key] = $value;
    }
    if (!$saved && !is_file($file)) return;       // отличий нет — файл не заводим
    $text = "<?php\n"
        . "/* Настройки установки, заданные в админке («Настройки»). Файл пишет админка — руками не править:\n"
        . "   руками правят config.php. Лежит поверх него; ключи и пароли — в secrets.php, окружение сильнее. */\n\n"
        . "declare(strict_types=1);\n\n"
        . 'return ' . var_export($saved, true) . ";\n";
    settingsWriteFile($file, $text);
}

/** Ключи и пароли — в secrets.php: меняется только значение строки, пояснения в файле остаются. null — строку убрать. */
function settingsWriteSecrets(array $secret): void
{
    if (!$secret) return;
    $file = configPath('secrets.php');
    $text = is_file($file) ? (string) file_get_contents($file) : "<?php\ndeclare(strict_types=1);\n\nreturn [\n];\n";
    $quoted = "'(?:[^'\\\\]|\\\\.)*'";   // строка PHP в одинарных кавычках

    foreach ($secret as $key => $value) {
        if ($value === null) {
            $text = preg_replace('~^[ \t]*' . preg_quote("'" . $key . "'", '~') . '\s*=>\s*' . $quoted . ',?[ \t]*\n?~m', '', $text, 1);
            continue;
        }
        $line = "'" . $key . "' => " . var_export((string) $value, true);
        $pattern = '~' . preg_quote("'" . $key . "'", '~') . '\s*=>\s*' . $quoted . '~';
        $count = 0;
        $text = preg_replace_callback($pattern, static fn() => $line, $text, 1, $count);
        if (!$count) $text = preg_replace_callback('~\];\s*$~', static fn() => "    $line,\n];\n", $text, 1);
    }
    settingsWriteFile($file, $text);
}

/** Запись целиком или никак: временный файл и переименование. Читать файл может только владелец — сервер (0600, Б14). */
function settingsWriteFile(string $file, string $text): void
{
    $tmp = $file . '.tmp' . getmypid();
    $ok = file_put_contents($tmp, $text, LOCK_EX) !== false;
    if ($ok) @chmod($tmp, 0600);
    if (!$ok || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new ApiError(t('admin.settings.err_write', ['file' => basename($file)]));
    }
    // Иначе PHP ещё какое-то время исполнял бы прежнюю копию файла.
    if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);
}
