<?php
/* Командная строка сервера: миграции, бэкап, карта кода, уборка, задания ИИ.
   Запускается только из терминала.

   Использование:
     php bin/server.php install              — установка вопросами в Терминале (то же, что install.php)
     php bin/server.php serve [--port=8080]  — встроенный сервер PHP: http://localhost:8080
     php bin/server.php release [--to=папка]  — папка релиза для GitHub (по умолчанию ../goblin-release)
     php bin/server.php migrate [--apply]
     php bin/server.php schema-sqlite        — sql/schema.sqlite.sql из sql/schema.sql
     php bin/server.php catalog-export       — sql/catalog.json: встроенный каталог схем для новых установок
     php bin/server.php backup [метка]
     php bin/server.php files-gc [--apply]
     php bin/server.php map
     php bin/server.php ai-job ID

   Разовый перенос из Гоблина v1 (команды v1-*) выполнен и убран: его код лежит
   в archive/lib-transfer/. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/boot.php';

$root = dirname(__DIR__);
$argv = $_SERVER['argv'];
$command = $argv[1] ?? 'help';
$flags = [];
$plain = [];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $flags[$name] = $value;
    } else {
        $plain[] = $arg;
    }
}

switch ($command) {
    case 'install':   cmdInstall(); break;
    case 'serve':     cmdServe($root, (int) ($flags['port'] ?? 8080)); break;
    case 'release':   cmdRelease($root, (string) ($flags['to'] ?? dirname($root) . '/goblin-release')); break;
    case 'migrate':   cmdMigrate($root, isset($flags['apply'])); break;
    case 'schema-sqlite': cmdSchemaSqlite(); break;
    case 'catalog-export': file_put_contents(SCHEMA_DIR . '/catalog.json', schemaCatalog()); echo "записано: sql/catalog.json\n"; break;
    case 'backup':    cmdBackup($root, $plain[0] ?? 'manual'); break;
    case 'files-gc':  cmdFilesGc(isset($flags['apply'])); break;
    case 'map':       cmdMap($root); break;
    case 'ai-job':    cmdAiJob((int) ($plain[0] ?? 0)); break;
    default:
        echo trim(substr(file_get_contents(__FILE__), 0, 600)), PHP_EOL;
}

/* ── Установка и встроенный сервер ─────────────────────────────── */

/** Установка вопросами: ответ Enter — значение в [скобках]. Логика — lib/install/install.php. */
function cmdInstall(): void
{
    require_once dirname(__DIR__) . '/lib/install/install.php';
    if (!installNeeded()) { echo t('install.err.done'), PHP_EOL; exit(1); }
    $ask = static function (string $question, string $or = '', bool $hidden = false): string {
        echo $question, $or !== '' ? " [$or]" : '', ': ';
        if ($hidden) shell_exec('stty -echo 2>/dev/null');
        $answer = trim((string) fgets(STDIN));
        if ($hidden) { shell_exec('stty echo 2>/dev/null'); echo PHP_EOL; }
        return $answer !== '' ? $answer : $or;
    };
    $lang = $ask('Язык / language (ru, en)', 'ru') === 'en' ? 'en' : 'ru';
    $_COOKIE['goblin_lang'] = $lang;
    foreach (installChecks() as $c) echo $c['ok'] ? '  ✓ ' : '  ✗ ', $c['what'], $c['say'] !== '' ? ' — ' . $c['say'] : '', PHP_EOL;

    $in = ['lang' => $lang];
    $in['where'] = $ask(t('install.where') . ' (local, web)', 'local') === 'web' ? 'web' : 'local';
    if ($in['where'] === 'local') $in['serve'] = $ask(t('install.serve_php') . ' / XAMPP (php, apache)', 'php') === 'apache' ? 'apache' : 'php';
    $in['driver'] = $ask(t('install.db') . ' (sqlite, mysql)', $in['where'] === 'web' ? 'mysql' : 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    if ($in['driver'] === 'sqlite') {
        $in['sqlite_file'] = $ask(t('install.sqlite_file'), 'data/goblin.sqlite');
    } else {
        foreach (['host' => 'localhost', 'port' => '3306', 'name' => 'goblin', 'user' => 'root'] as $key => $or) $in[$key] = $ask(t('install.' . $key), $or);
        $in['pass'] = $ask(t('install.pass'), '', true);
        $in['create'] = $ask(t('install.create') . ' (y, n)', 'y') !== 'n';
    }
    $in['admin_login'] = $ask(t('install.admin_login'), 'admin');
    $in['admin_pass'] = $ask(t('install.admin_pass'), '', true);
    echo t('install.keys_hint'), PHP_EOL;
    foreach (['ai_key_deepseek' => 'DeepSeek', 'ai_key_openrouter' => 'OpenRouter', 'voice_api_key' => t('install.key_voice'), 'jev_api_key' => 'Jev'] as $key => $label) {
        $in[$key] = $ask($label, '', true);
    }
    try {
        $done = installRun($in);
    } catch (Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
        exit(1);
    }
    echo PHP_EOL, t('install.done.title'), PHP_EOL;
    foreach ($done['notes'] as $note) echo '  ', $note, PHP_EOL;
    echo $in['where'] === 'local' && ($in['serve'] ?? '') === 'php'
        ? t('install.done.serve') . ' php bin/server.php serve  →  http://localhost:8080' . PHP_EOL
        : t('install.done.editor') . ' …/goblin/  ·  ' . t('install.done.admin') . ' …/goblin/admin.php' . PHP_EOL;
}

/** Встроенный сервер PHP из папки Гоблина: те же правила, что у Apache (bin/router.php). */
function cmdServe(string $root, int $port): void
{
    chdir($root);
    echo "Гоблин: http://localhost:$port  (остановить — Ctrl+C)", PHP_EOL;
    passthru('PHP_CLI_SERVER_WORKERS=4 ' . escapeshellarg(PHP_BINARY) . ' -S localhost:' . $port . ' -t ' . escapeshellarg($root . '/public')
        . ' -d upload_max_filesize=100M -d post_max_size=105M ' . escapeshellarg($root . '/bin/router.php'));
}

/** Папка релиза для GitHub — lib/install/release.php. */
function cmdRelease(string $root, string $dest): void
{
    require_once $root . '/lib/install/release.php';
    if (realpath($dest) === realpath($root)) { echo "релиз не собирают в рабочий Гоблин\n"; exit(1); }
    try {
        $done = releaseBuild($dest);
    } catch (Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
        exit(1);
    }
    printf("релиз: %s — файлов %d, %.1f МБ\n", $dest, $done['files'], $done['bytes'] / 1048576);
}

/* ── Миграции ─────────────────────────────────────────────────── */

function cmdMigrate(string $root, bool $apply): void
{
    $waiting = schemaMigrations()['waiting'];
    if (!$waiting) { echo "все миграции применены\n"; return; }

    foreach ($waiting as $name) {
        if (!$apply) { echo "ждёт: $name\n"; continue; }
        schemaMigrate($name);
        echo "применена: $name\n";
    }
    if (!$apply) echo "это только список. Повторите с --apply\n";
}

/** Схема SQLite из схемы MySQL — после правки sql/schema.sql (lib/core/schema.php). */
function cmdSchemaSqlite(): void
{
    $file = SCHEMA_DIR . '/schema.sqlite.sql';
    file_put_contents($file, schemaSqlite((string) file_get_contents(SCHEMA_DIR . '/schema.sql')));
    echo "записано: sql/schema.sqlite.sql\n";
}

/* ── Бэкап ────────────────────────────────────────────────────── */

function cmdBackup(string $root, string $label): void
{
    $label = preg_replace('/[^\w\-а-яА-Я]/u', '-', $label) ?: 'manual';
    $dest = $root . '/backup/' . date('Y-m-d_H-i') . '_' . $label;
    if (is_dir($dest)) { echo "такая папка уже есть: $dest\n"; exit(1); }
    mkdir($dest . '/db', 0775, true);

    // Код, инструкции, схема и public/workfiles. Ключи и пароли (secrets.php) в снимок не копируются.
    // Исключения — только от корня: без «/» rsync выкинул бы и вложенные service/archive/.
    $exclude = '--exclude /backup/ --exclude /workspace/ --exclude /data/files/ --exclude /secrets.php'
             . ' --exclude .DS_Store --exclude /archive/';
    exec("rsync -a $exclude " . escapeshellarg($root . '/') . ' ' . escapeshellarg($dest . '/project/'));

    $c = config();
    // Пароль уходит окружением: в строке команды его увидел бы любой через `ps`.
    if ($c['pass'] !== '') putenv('MYSQL_PWD=' . $c['pass']);
    $dump = sprintf(
        '%s/mariadb-dump --host=%s --port=%d --user=%s --databases %s --add-drop-database --single-transaction --default-character-set=utf8mb4 > %s',
        dirname($c['php_cli']), escapeshellarg($c['host']), $c['port'], escapeshellarg($c['user']),
        escapeshellarg($c['name']), escapeshellarg($dest . '/db/' . $c['name'] . '.sql')
    );
    exec($dump, $out, $code);
    putenv('MYSQL_PWD');
    if ($code !== 0) { echo "дамп базы не получился\n"; exit(1); }

    $files = (int) trim((string) shell_exec('find ' . escapeshellarg($dest . '/project') . ' -type f | wc -l'));
    $size = trim((string) shell_exec('du -sh ' . escapeshellarg($dest) . ' | cut -f1'));
    file_put_contents($dest . '/ОПИСЬ.txt', implode("\n", [
        'СНИМОК ГОБЛИНА v2',
        '',
        'когда:  ' . date('d.m.Y H:i'),
        'метка:  ' . $label,
        'размер: ' . $size,
        '',
        'project/  код, инструкции, схема базы — ' . $files . ' файлов',
        'db/       полный дамп базы ' . $c['name'],
        '',
        'как вернуть:',
        '  rsync -a project/ ' . $root . '/',
        '  mariadb -u' . $c['user'] . ' < db/' . $c['name'] . '.sql',
        '',
        'внимание: рабочие папки public/workfiles входят; старые workspace/ и байты материалов data/files/ — нет.',
        'внимание: secrets.php (ключи и пароли) в снимок не входит — храните его отдельно.',
    ]) . "\n");

    echo "готово: $dest ($size)\n";
}

/* ── Уборка ───────────────────────────────────────────────────── */

/** Лишние байты материалов — общей уборкой filesGc() (lib/assets/files.php), та же у админки. */
function cmdFilesGc(bool $apply): void
{
    $gone = filesGc($apply);
    printf("%s %d файлов, %.1f МБ%s\n", $apply ? 'удалено' : 'лишние:', $gone['files'], $gone['bytes'] / 1048576,
        $apply ? '' : ' — повторите с --apply');
}

/* ── Карта кода ───────────────────────────────────────────────── */

function cmdMap(string $root): void
{
    $lines = ["# Карта кода Гоблина v2", '', 'Собрано командой `php bin/server.php map` из шапок файлов.', ''];
    foreach (['lib', 'public/src', 'bin'] as $where) {
        $lines[] = '## ' . $where;
        $lines[] = '';
        $dir = $root . '/' . $where;
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($it as $file) {
            if (!$file->isFile() || !preg_match('/\.(php|js)$/', $file->getFilename())) continue;
            $files[] = $file->getPathname();
        }
        sort($files);
        foreach ($files as $path) {
            $head = mapHead($path);
            $lines[] = sprintf('- `%s` — %s', str_replace($root . '/', '', $path), $head);
        }
        $lines[] = '';
    }
    // Диагностика: только в stdout. Файлов не создаёт — ручной md_backend/ru/structure.md не трогает.
    echo implode("\n", $lines) . "\n";
}

/** Первая содержательная строка шапки файла. */
function mapHead(string $path): string
{
    $text = (string) file_get_contents($path, false, null, 0, 600);
    if (preg_match('#/\*+\s*(.+?)[\r\n]#s', $text, $m)) return trim($m[1]);
    if (preg_match('#^\s*//\s*(.+)$#m', $text, $m)) return trim($m[1]);
    return 'без шапки';
}

/* ── Фоновые задания встроенного ИИ ───────────────────────────── */

function cmdAiJob(int $id): void
{
    if (!$id) { echo "нужен номер задания\n"; exit(1); }
    if (!function_exists('aiJobRun')) { echo "модуль ИИ ещё не подключён\n"; exit(1); }
    aiJobRun($id);
}
