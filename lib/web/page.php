<?php
/* Сборка страницы редактора: разметка из views/editor, карта модулей и словарь языка.
   Отдаёт: renderEditor(), agentNote().
   Не делает: не отдаёт данные — их клиент берёт сам через api.php. */

declare(strict_types=1);

function renderEditor(bool $viewOnly = false): void
{
    $root = dirname(__DIR__, 2);
    $version = editorVersion($root);
    $note = agentNote();

    header('Content-Type: text/html; charset=utf-8');
    // Записка агенту — самой первой строкой: комментарий до <!doctype> браузер пропускает без последствий.
    echo "<!--\n" . $note . "\n-->\n";
    echo view($root . '/views/editor/layout.html', [
        'agentnote' => '<pre hidden class="agent-note">' . h($note) . '</pre>',
        'version'  => $version,
        'importmap' => importMap($root, $version),
        'topbar'   => view($root . '/views/editor/topbar.html'),
        // Выпуск системы — файл VERSION в корне (подпись под логотипом в настройках).
        'left'     => view($root . '/views/editor/left.html', ['release' => h(trim((string) @file_get_contents($root . '/VERSION')))]),
        'canvas'   => view($root . '/views/editor/canvas.html'),
        'panel'    => view($root . '/views/editor/panel.html'),
        'runbar'   => view($root . '/views/editor/runbar.html'),
        'dialogs'  => view($root . '/views/editor/dialogs.html'),
        'bodyclass' => $viewOnly ? 'view-only' : '',
        'lang'     => lang(),
        'i18n'     => langScript(['common', 'editor']),
        // Первый заход: скин и вкладка слева — настройки установки (config.php → look_default, rail_default).
        // Движок голоса (voice_engine): browser или openai — panel/voice.js.
        'defaults' => '<script>window.GOBLIN_DEFAULTS = ' . json_encode([
            'look' => (string) (config()['look_default'] ?? 'work'),
            'pane' => (string) (config()['rail_default'] ?? 'folders'),
        ], JSON_HEX_TAG) . '; window.GOBLIN_VOICE = '
            . json_encode((string) (config()['voice_engine'] ?? 'browser'), JSON_HEX_TAG) . ';</script>',
    ]);
}

/**
 * Записка агенту ИИ, которому по ошибке дали адрес холста вместо ссылки-задания: страница — для человека,
 * задание — по docs.run (role=lead — прогон, role=draw — рисование). Ключ проекта и папка — в #хвосте адреса,
 * сервер их не видит: агент берёт их из адреса сам. По-английски — так её поймёт любой агент.
 * Первой строкой HTML и скрытой копией в начале <body>: часть агентов читает страницу без комментариев.
 */
function agentNote(): string
{
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    // Только буквы адреса: «-->» из чужого заголовка Host не закроет комментарий.
    $host = (string) preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $path = str_replace(['<', '>'], '', str_ends_with($path, '/') ? $path : rtrim(dirname($path), '/') . '/');
    $site = $scheme . '://' . $host . $path;
    $api = $site . 'api.php';

    return <<<END_OF_NOTE
    NOTE FOR AI AGENTS (Claude, Codex, ChatGPT, Gemini, Cursor and others)

    This page is the Goblin canvas: a visual flowchart editor made for a HUMAN to look at in a browser.
    The scheme is drawn by JavaScript after the page loads, so this HTML holds neither the scheme nor a task for you.

    If you were given this page to study or to work on, the human most likely copied the address from
    the browser bar by mistake. What you need is an agent link. It returns a plain-text task with
    step-by-step instructions:

      1. RUN the scheme (you act as the lead of the run):
         {$api}?op=docs.run&role=lead&project=PROJECT&folder=FOLDER

      2. CREATE OR EDIT the scheme of this folder:
         {$api}?op=docs.run&role=draw&project=PROJECT&folder=FOLDER

    Take PROJECT and FOLDER from the address you were given: {$site}#p=PROJECT&f=FOLDER
    Example: {$site}#p=abc123xyz0&f=4 gives project=abc123xyz0 and folder=4.

    Pick the link that matches what the human asked for, open it and follow it.
    If the address has no #p= part, or it is unclear whether the human wants a run or an edit, do not guess:
    ask the human to copy the agent link in the canvas (the link button in the top bar, then
    "Start a run" or "Create or edit a scheme") and send it to you.
    END_OF_NOTE;
}

/** Версия ресурсов: по самому свежему файлу клиента. Кэш не мешает разработке. */
function editorVersion(string $root): string
{
    $newest = 0;
    foreach (['public/src', 'public/css', 'views/editor'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) $newest = max($newest, $file->getMTime());
        }
    }
    return (string) $newest;
}

/** Карта модулей: клиент пишет `import … from 'goblin/…'`, сборщика нет. */
function importMap(string $root, string $version): string
{
    $map = [];
    $dir = $root . '/public/src';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.js')) continue;
        $name = str_replace($dir . '/', '', $file->getPathname());
        $map['goblin/' . $name] = './src/' . $name . '?v=' . $version;
    }
    ksort($map);
    return json_encode(['imports' => $map], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** Простая подстановка {{ключ}} и перевод {{t:ключ.словаря}} — движка шаблонов тут не нужно. */
function view(string $file, array $vars = []): string
{
    $html = is_file($file) ? (string) file_get_contents($file) : '';
    $html = preg_replace_callback('/\{\{t:([\w.\-]+)\}\}/', static fn(array $m) => h(t($m[1])), $html);
    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . $key . '}}', (string) $value, $html);
    }
    return preg_replace('/\{\{\w+\}\}/', '', $html);
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
