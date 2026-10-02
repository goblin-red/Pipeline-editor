<?php
/* Пять конфигов и единственное место, где их читают.
   Отдаёт: config() — настройки из config.php, правки админки (config_admin.php) и секреты из secrets.php;
           lists() — списки из config.txt;
           listHas(), listWrite() — работа со списками.
   Не делает: не ходит в базу и ничего не проверяет по правам. */

declare(strict_types=1);

/** Секрет → переменная окружения, которая сильнее файла. */
const SECRET_ENV = [
    'pass'            => 'GOBLIN_DB_PASS',
    'ai_key_deepseek' => 'GOBLIN_DEEPSEEK_KEY',
    'ai_key_openrouter' => 'GOBLIN_OPENROUTER_KEY',
    'voice_api_key'   => 'GOBLIN_OPENAI_KEY',
    'jev_api_key'     => 'GOBLIN_JEV_KEY',
    'ftp_pass'        => 'GOBLIN_FTP_PASSWORD',
    'admin_pass_hash' => 'GOBLIN_ADMIN_PASS_HASH',
];

/** Открытая настройка → переменная окружения (та же, что в config.php). Задана — правка админки не действует. */
const CONFIG_ENV = [
    'driver' => 'GOBLIN_DB_DRIVER', 'sqlite_file' => 'GOBLIN_SQLITE_FILE',
    'host' => 'GOBLIN_DB_HOST', 'port' => 'GOBLIN_DB_PORT', 'name' => 'GOBLIN_DB_NAME', 'user' => 'GOBLIN_DB_USER',
    'v1_name' => 'GOBLIN_V1_DB_NAME', 'php_cli' => 'GOBLIN_PHP_CLI',
    'ai_api_url' => 'GOBLIN_AI_API_URL', 'ai_model' => 'GOBLIN_AI_MODEL',
    'voice_engine' => 'GOBLIN_VOICE_ENGINE', 'voice_api_url' => 'GOBLIN_VOICE_API_URL', 'voice_model' => 'GOBLIN_VOICE_MODEL',
    'voice_tts_model' => 'GOBLIN_VOICE_TTS_MODEL', 'voice_tts_voice' => 'GOBLIN_VOICE_TTS_VOICE',
    'jev_api_url' => 'GOBLIN_JEV_API_URL', 'jev_model' => 'GOBLIN_JEV_MODEL',
    'ftp_host' => 'GOBLIN_FTP_HOST', 'ftp_user' => 'GOBLIN_FTP_USER', 'ftp_root' => 'GOBLIN_FTP_ROOT',
    'ftp_port' => 'GOBLIN_FTP_PORT', 'ftp_protocol' => 'GOBLIN_FTP_PROTOCOL', 'admin_login' => 'GOBLIN_ADMIN_LOGIN',
];

/** Задана ли переменная окружения настройки: тогда она сильнее любого файла. */
function configFromEnv(string $key): ?string
{
    $name = CONFIG_ENV[$key] ?? SECRET_ENV[$key] ?? null;
    $value = $name ? getenv($name) : false;
    return ($value === false || $value === '') ? null : $name;
}

/**
 * Настройки установки. Порядок, от слабого к сильному:
 * config.php → config_admin.php (правки админки) → secrets.php → окружение → config_web.php на хостинге.
 * $fresh — перечитать файлы: админка только что их переписала.
 */
function config(bool $fresh = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$fresh) return $cfg;

    $root = dirname(__DIR__, 2);
    $cfg = require $root . '/config.php';

    // Правки из админки — поверх config.php, но переменная окружения сильнее.
    if (is_file($root . '/config_admin.php')) {
        foreach (require $root . '/config_admin.php' as $key => $value) {
            if (!configFromEnv($key)) $cfg[$key] = $value;
        }
    }

    // Секреты — поверх config.php, но переменная окружения сильнее файла.
    if (is_file($root . '/secrets.php')) {
        foreach (require $root . '/secrets.php' as $key => $value) {
            $name = SECRET_ENV[$key] ?? null;
            $env = $name ? getenv($name) : false;
            $cfg[$key] = ($env === false || $env === '') ? $value : $env;
        }
    }

    if (is_file($root . '/config_web.php')) {
        $cfg = array_merge($cfg, require $root . '/config_web.php');
    }
    return $cfg = configAi($cfg);
}

/**
 * Рабочее подключение ИИ (ai_connection из ai_connections) → ai_api_url, ai_model, ai_timeout, ai_api_key:
 * их и читает весь Гоблин. Ключ подключения — ai_key_<имя> из secrets.php. Окружение сильнее (проверки).
 */
function configAi(array $cfg): array
{
    // До подключений ключ DeepSeek лежал в secrets.php как ai_api_key.
    if ((string) ($cfg['ai_key_deepseek'] ?? '') === '') $cfg['ai_key_deepseek'] = (string) ($cfg['ai_api_key'] ?? '');

    $all = (array) ($cfg['ai_connections'] ?? []);
    $id = isset($all[$cfg['ai_connection'] ?? '']) ? (string) $cfg['ai_connection'] : (string) array_key_first($all);
    $one = $all[$id] ?? [];
    $cfg['ai_connection'] = $id;
    $cfg['ai_api_url'] = (string) ($one['url'] ?? '');
    $cfg['ai_model'] = (string) ($one['model'] ?? '');
    $cfg['ai_timeout'] = (int) ($one['timeout'] ?? 40);
    $cfg['ai_api_key'] = (string) ($cfg['ai_key_' . $id] ?? '');
    foreach (['ai_api_url', 'ai_model'] as $key) {
        if ($name = configFromEnv($key)) $cfg[$key] = (string) getenv($name);
    }
    return $cfg;
}

function configPath(string $name): string
{
    return dirname(__DIR__, 2) . '/' . $name;
}

/** Отметка «Гоблин поставлен» — пишет установщик (lib/install/install.php). */
const INSTALL_LOCK = 'data/installed.lock';

/** Ещё не поставлен: нет отметки и нет secrets.php (у установок до установщика отметки нет, а секреты есть). */
function installNeeded(): bool
{
    return !is_file(configPath(INSTALL_LOCK)) && !is_file(configPath('secrets.php'));
}

/* ── Границы файлов ──────────────────────────────────────────────
   Пути приходят от человека и от агентов: рабочая папка, материал по локальному
   пути, файл результата. Разрешённые корни задаёт `file_roots` в config.php —
   всё, что вне них, сервер не читает, не отдаёт и не чистит. */

/** Разрешённые корни, уже развёрнутые до настоящих путей. */
function fileRoots(): array
{
    static $roots = null;
    if ($roots !== null) return $roots;

    $roots = [];
    foreach ((array) (config()['file_roots'] ?? []) as $one) {
        $real = realpath((string) $one);
        if ($real) $roots[] = rtrim($real, '/');
    }
    return $roots;
}

/** Убрать «.», «..» и лишние косые, не трогая диск. */
function pathNormalize(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($parts); continue; }
        $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}

/**
 * Путь внутри разрешённых корней? Отдаёт настоящий путь или null.
 *
 * Проверяется дважды: сам путь после уборки «..» и его настоящее место на диске —
 * иначе символическая ссылка внутри рабочей папки вывела бы наружу.
 * Пути ещё не созданного файла достаточно первой проверки.
 */
function pathInsideRoots(string $path): ?string
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '/')) return null;

    $clean = pathNormalize($path);
    $real = realpath($path);
    foreach (fileRoots() as $root) {
        $inside = static fn(string $p): bool => $p === $root || str_starts_with($p, $root . '/');
        if (!$inside($clean)) continue;
        if ($real !== false && !$inside($real)) return null;
        return $real !== false ? $real : $clean;
    }
    return null;
}

/** То же, но отказом наружу. $what — что именно проверяли. */
function pathMustBeInsideRoots(string $path, string $what): string
{
    $real = pathInsideRoots($path);
    if ($real === null) {
        throw new ApiError(t('server.path.outside', ['what' => entityLabel($what), 'path' => $path]), 'forbidden',
            ['code2' => 'outside_roots']);
    }
    return $real;
}

/**
 * Списки из config.txt.
 *
 * Возвращает: ['cli' => ['claude' => ['label'=>…, 'color'=>…, 'extra'=>[…]], …], …]
 * Раздел [interface] возвращается отдельно как числа.
 */
function lists(bool $fresh = false): array
{
    static $data = null;
    if ($data !== null && !$fresh) return $data;

    $file = configPath('config.txt');
    $data = listsParse(is_file($file) ? (string) file_get_contents($file) : '');
    return $data;
}

/**
 * Разбор текста config.txt — один на сайт и на редактор в админке.
 * Строки, которые разбор пропускает или понимает не так, как задумано, попадают в $problems:
 * ['line' => номер с 1, 'why' => no_section|no_equals|bad_key|duplicate, 'key' => …].
 */
function listsParse(string $text, array &$problems = []): array
{
    $data = ['sections' => [], 'interface' => []];
    $section = '';
    foreach (preg_split('/\r\n|\r|\n/', $text) as $index => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = strtolower(trim($line, '[]'));
            if ($section !== 'interface') $data['sections'][$section] ??= [];
            continue;
        }
        $at = ['line' => $index + 1];
        if ($section === '' || !str_contains($line, '=')) {
            $problems[] = $at + ['why' => $section === '' ? 'no_section' : 'no_equals', 'key' => ''];
            continue;
        }

        [$key, $rest] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) $problems[] = $at + ['why' => 'bad_key', 'key' => $key];
        if ($key === '') continue;
        if ($section === 'interface' ? isset($data['interface'][$key]) : isset($data['sections'][$section][$key])) {
            $problems[] = $at + ['why' => 'duplicate', 'key' => $key];
        }

        if ($section === 'interface') {
            $data['interface'][$key] = is_numeric($rest) ? $rest + 0 : $rest;
            continue;
        }

        $parts = array_map('trim', explode('|', $rest));
        $data['sections'][$section][$key] = [
            'label' => $parts[0] ?? $key,
            'color' => $parts[1] ?? '',
            'extra' => isset($parts[2]) && $parts[2] !== '' ? splitOutside($parts[2]) : [],
            // Четвёртое поле есть только у [cli]: чем запускать worker.
            'run'   => $parts[3] ?? '',
        ];
    }
    return $data;
}

/** Есть ли значение в списке. Пустая строка разрешена всегда. */
function listHas(string $section, string $value): bool
{
    if ($value === '') return true;
    return isset(lists()['sections'][strtolower($section)][$value]);
}

/** Число из раздела [interface]. */
function interfaceValue(string $key, float $fallback): float
{
    $v = lists()['interface'][$key] ?? null;
    return is_numeric($v) ? (float) $v : $fallback;
}

/**
 * Правка одной строки списка. Значение null удаляет строку.
 * Пишет под блокировкой файла: две одновременные правки затирали друг друга.
 */
function listWrite(string $section, string $key, ?array $value): void
{
    $file = configPath('config.txt');
    $fh = fopen($file, 'c+');
    if (!$fh) throw new RuntimeException(t('server.config.not_writable'));

    try {
        flock($fh, LOCK_EX);
        $text = stream_get_contents($fh);
        $lines = explode("\n", $text);
        $section = strtolower($section);

        $out = [];
        $in = false;
        $done = false;
        $lastFilled = -1;

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim !== '' && $trim[0] === '[' && substr($trim, -1) === ']') {
                if ($in && !$done && $value !== null) {
                    array_splice($out, $lastFilled + 1, 0, [listLine($key, $value)]);
                    $done = true;
                }
                $in = strtolower(trim($trim, '[]')) === $section;
            } elseif ($in && $trim !== '' && $trim[0] !== '#' && str_contains($trim, '=')) {
                $name = trim(explode('=', $trim, 2)[0]);
                if ($name === $key) {
                    if ($value === null) continue;      // удаление
                    $line = listLine($key, $value);
                    $done = true;
                }
            }
            $out[] = $line;
            if (trim($line) !== '') $lastFilled = count($out) - 1;
        }

        if ($in && !$done && $value !== null) {
            array_splice($out, $lastFilled + 1, 0, [listLine($key, $value)]);
            $done = true;
        }
        if (!$done && $value !== null) {
            $out[] = '';
            $out[] = '[' . $section . ']';
            $out[] = listLine($key, $value);
        }

        $text = implode("\n", $out);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $text);
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    lists(true);
}

/**
 * Разбить по запятым, не трогая то, что в скобках.
 * `opus-5[low, high], haiku-4.5[normal]` → две записи, а не четыре.
 */
function splitOutside(string $text): array
{
    $out = [];
    $word = '';
    $deep = 0;

    foreach (str_split($text) as $letter) {
        if ($letter === '[') $deep++;
        if ($letter === ']') $deep = max(0, $deep - 1);
        if ($letter === ',' && $deep === 0) {
            $out[] = trim($word);
            $word = '';
            continue;
        }
        $word .= $letter;
    }
    $out[] = trim($word);
    return array_values(array_filter($out, static fn($one) => $one !== ''));
}

/** Одно поле строки списка: без переносов и без разделителя — иначе файл распадётся. */
function listField($value): string
{
    $text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $value);
    return trim(str_replace(['|', '[', ']'], ['/', '(', ')'], $text));
}

function listLine(string $key, array $value): string
{
    $parts = [listField($value['label'] ?? $key)];
    $color = listField($value['color'] ?? '');
    $extra = array_map('listField', (array) ($value['extra'] ?? []));
    $run   = listField($value['run'] ?? '');
    if ($color !== '' || $extra || $run !== '') $parts[] = $color;
    if ($extra || $run !== '') $parts[] = implode(', ', $extra);
    if ($run !== '') $parts[] = $run;
    return $key . ' = ' . implode(' | ', $parts);
}
