<?php
/* Папка релиза — дистрибутив для GitHub: только код и содержимое продукта, без данных, ключей и снимков.
   Отдаёт: releaseBuild().
   Не делает: не заливает на GitHub (git — руками или отдельной командой) и не трогает рабочий Гоблин.

   Берём по белому списку (RELEASE_TAKE), выкидываем по чёрному (RELEASE_SKIP). Пустые рабочие папки —
   с их .htaccess. Из документов вырезаются разделы с пропусками. В конце — поиск утечек: нашлось —
   отказ, папка остаётся для разбора. Папку .git в релизе не трогаем — это история репозитория. */

declare(strict_types=1);

/** Что берём: папки и файлы от корня Гоблина. */
const RELEASE_TAKE = [
    'bin', 'lang', 'lib', 'public', 'sql', 'views', 'instructions/ru', 'instructions/en',
    'md_backend/ru', 'md_backend/en', 'md_backend/документация', 'md_backend/arch', 'md_backend/homebrew.md',
    'config.php', 'config.txt', 'VERSION', '.htaccess',
];

/** Что выкидываем — начала путей и имена файлов. */
const RELEASE_SKIP = ['public/workfiles/', 'public/vendor/.cache', '.DS_Store', '.log', '.tmp',
    'md_backend/ru/хостинг.md'];   // как выложен canvas.goblin.red — только для хозяина

/** Пустые папки, что нужны работающему Гоблину: берём только их .htaccess. */
const RELEASE_EMPTY = ['data/files/.htaccess', 'storage/.htaccess', 'public/workfiles/.htaccess'];

/** Признаки утечки — вид ключа, а не значения (сам шаблон под себя не попадает: после префикса идёт «[»). */
const RELEASE_LEAKS = '~sk-[A-Za-z0-9_-]{20,}|gbt_[0-9a-f]{24,}|gh[po]_[A-Za-z0-9]{20,}|Cl-[0-9a-f]{12}~';

/** Собрать релиз в $dest. Отдаёт ['files' => n, 'bytes' => n]; утечка — RuntimeException со списком файлов. */
function releaseBuild(string $dest): array
{
    $root = dirname(__DIR__, 2);
    if (!is_dir($dest)) mkdir($dest, 0775, true);
    releaseClean($dest);

    // Свежие схема SQLite и каталог — из рабочей базы.
    file_put_contents(SCHEMA_DIR . '/schema.sqlite.sql', schemaSqlite((string) file_get_contents(SCHEMA_DIR . '/schema.sql')));
    file_put_contents(SCHEMA_DIR . '/catalog.json', schemaCatalog());

    $files = 0;
    $bytes = 0;
    foreach (RELEASE_TAKE as $item) {
        $from = $root . '/' . $item;
        $list = is_dir($from)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo($from)];
        foreach ($list as $file) {
            if (!$file->isFile()) continue;
            $rel = substr($file->getPathname(), strlen($root) + 1);
            if (releaseSkipped($rel)) continue;
            releaseCopy($file->getPathname(), $dest . '/' . $rel);
            $files++;
            $bytes += $file->getSize();
        }
    }
    foreach (RELEASE_EMPTY as $rel) releaseCopy($root . '/' . $rel, $dest . '/' . $rel);
    @mkdir($dest . '/workspace', 0775, true);
    file_put_contents($dest . '/workspace/.gitkeep', '');

    // Места документов между метками local-only (пропуска для проверок) — только для рабочего Гоблина.
    foreach (['md_backend/ru/backend.md', 'md_backend/en/backend.md'] as $rel) {
        $path = $dest . '/' . $rel;
        if (!is_file($path)) continue;
        $text = (string) file_get_contents($path);
        $text = (string) preg_replace('~<!-- local-only.*?<!-- /local-only -->\n?~su', '', $text);
        file_put_contents($path, $text);
    }

    foreach (releaseFiles() as $name => $text) file_put_contents($dest . '/' . $name, $text);

    $leaks = releaseLeaks($dest);
    if ($leaks) throw new RuntimeException("в релизе нашлись ключи или пароли:\n  " . implode("\n  ", $leaks));
    return ['files' => $files, 'bytes' => $bytes];
}

function releaseSkipped(string $rel): bool
{
    foreach (RELEASE_SKIP as $skip) {
        if (str_starts_with($rel, $skip) || str_ends_with($rel, $skip) || basename($rel) === $skip) return true;
    }
    return false;
}

function releaseCopy(string $from, string $to): void
{
    if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true);
    copy($from, $to);
}

/** Очистить папку релиза, кроме .git. */
function releaseClean(string $dest): void
{
    foreach (scandir($dest) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.git') continue;
        $path = $dest . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            $all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($all as $one) $one->isDir() ? rmdir($one->getPathname()) : unlink($one->getPathname());
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}

/** Файлы, которых нет в рабочем Гоблине: README, лицензия, .gitignore, образец secrets.php. */
function releaseFiles(): array
{
    $version = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
    return [
        'README.md' => releaseReadme($version),
        'LICENSE' => "MIT License\n\nCopyright (c) 2026 goblin.red\n\n"
            . "Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated\n"
            . "documentation files (the \"Software\"), to deal in the Software without restriction, including without limitation\n"
            . "the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to\n"
            . "permit persons to whom the Software is furnished to do so, subject to the following conditions:\n\n"
            . "The above copyright notice and this permission notice shall be included in all copies or substantial portions of\n"
            . "the Software.\n\n"
            . "THE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO\n"
            . "THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\n"
            . "AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,\n"
            . "TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\n"
            . "SOFTWARE.\n",
        '.gitignore' => "# Настройки и ключи установки — у каждого свои (их пишет установщик)\nsecrets.php\nconfig_admin.php\nconfig_web.php\n\n"
            . "# Данные: база SQLite, материалы, рабочие папки, отметка установки\ndata/*\n!data/files/\ndata/files/*\n!data/files/.htaccess\n"
            . "storage/*\n!storage/.htaccess\nworkspace/*\n!workspace/.gitkeep\npublic/workfiles/*\n!public/workfiles/.htaccess\n\n"
            . "# Служебное\nbackup/\narchive/\n_up/\n.DS_Store\n*.log\n",
        'secrets.example.php' => "<?php\n/* Образец secrets.php. Обычно его пишет установщик (install.php или php bin/server.php install) —\n"
            . "   руками не нужно. Ключи ИИ — свои; пусто — без этого сервиса. Потом всё меняется в админке → «Настройки». */\n\n"
            . "declare(strict_types=1);\n\nreturn [\n    'pass' => '',              // пароль базы MySQL; для SQLite не нужен\n"
            . "    'admin_pass_hash' => '',   // php -r 'echo password_hash(\"пароль\", PASSWORD_DEFAULT);'\n"
            . "    'ai_key_deepseek' => '',   // DeepSeek — https://platform.deepseek.com\n"
            . "    'ai_key_openrouter' => '', // OpenRouter — https://openrouter.ai\n"
            . "    'voice_api_key' => '',     // OpenAI — голос (иначе голос браузера)\n"
            . "    'jev_api_key' => '',       // Jev (TypeSafe) — сигнальщик прогона\n"
            . "    'ftp_pass' => '',          // публикация на свой хостинг — по желанию\n];\n",
    ];
}

function releaseReadme(string $version): string
{
    return <<<MD
# Goblin — flowchart canvas for AI agents

**$version** · [Русский ниже](#гоблин--холст-блок-схем-для-ии-агентов)

Draw a process as a flowchart — AI agents (Claude Code, Codex and others) walk through it step by step:
the server hands out tasks, checks results and keeps the run on track. Built-in AI assistant, a catalog of
ready-made schemes, voice input, English and Russian interface.

## Requirements

- PHP 8.1+ with `pdo_sqlite` or `pdo_mysql`, `curl`, `mbstring`, `zip`
- Database: **SQLite** (one file, nothing to set up) or **MySQL / MariaDB**

## Install on your computer (macOS)

```bash
brew install php                 # macOS has no PHP of its own
git clone https://github.com/goblin-red/workflow.git goblin
cd goblin
php bin/server.php install       # a few questions: database, admin password, AI keys (optional)
php bin/server.php serve         # → http://localhost:8080
```

With XAMPP or MAMP: put the `goblin` folder into `htdocs` and open `http://localhost/goblin/` — the installer starts by itself.

## Install on a website (hosting)

1. Upload the files into the `goblin` folder of your site (or the site root).
2. Open `https://your-site/goblin/` — the installer page opens.
3. Choose the database (MySQL is recommended for a website), set the admin password, optionally your AI keys.

After installation the installer is closed. Settings, AI keys and people — in `admin.php`.
AI keys are yours: DeepSeek, OpenRouter, OpenAI (voice), Jev — none are included.

---

# Гоблин — холст блок-схем для ИИ-агентов

Рисуете процесс блок-схемой — ИИ-агенты (Claude Code, Codex и другие) проходят его шаг за шагом:
сервер выдаёт задания, проверяет результаты и ведёт прогон. Встроенный ИИ-помощник, каталог готовых схем,
голосовой ввод, русский и английский интерфейс.

## Что нужно

- PHP 8.1+ с `pdo_sqlite` или `pdo_mysql`, `curl`, `mbstring`, `zip`
- База: **SQLite** (один файл, настраивать нечего) или **MySQL / MariaDB**

## Установка на свой компьютер (macOS)

```bash
brew install php                 # своего PHP в macOS нет
git clone https://github.com/goblin-red/workflow.git goblin
cd goblin
php bin/server.php install       # несколько вопросов: база, пароль админки, ключи ИИ (по желанию)
php bin/server.php serve         # → http://localhost:8080
```

С XAMPP или MAMP: папку `goblin` — в `htdocs`, открыть `http://localhost/goblin/` — установщик откроется сам.

## Установка на сайт (хостинг)

1. Залить файлы в папку `goblin` сайта (или в корень).
2. Открыть `https://ваш-сайт/goblin/` — откроется страница установки.
3. Выбрать базу (для сайта лучше MySQL), задать пароль админки, по желанию — свои ключи ИИ.

После установки установщик закрыт. Настройки, ключи ИИ и люди — в `admin.php`.
Ключи ИИ — свои: DeepSeek, OpenRouter, OpenAI (голос), Jev — в комплекте их нет.

MD;
}

/** Файлы релиза с признаками ключей и паролей или с настоящими секретами этой установки (папку .git не смотрим). */
function releaseLeaks(string $dest): array
{
    $c = config();
    $secrets = array_filter([$c['pass'] ?? '', $c['ftp_pass'] ?? '', $c['voice_api_key'] ?? '', $c['jev_api_key'] ?? '',
        ...array_map(static fn($id) => $c['ai_key_' . $id] ?? '', array_keys((array) ($c['ai_connections'] ?? [])))],
        static fn($one) => is_string($one) && strlen($one) >= 6);
    $found = [];
    $all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
    foreach ($all as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/.git/') || $file->getSize() > 5 * 1048576) continue;
        $text = (string) file_get_contents($path);
        $leak = preg_match(RELEASE_LEAKS, $text);
        foreach ($secrets as $one) $leak = $leak || str_contains($text, $one);
        if ($leak) $found[] = substr($path, strlen($dest) + 1);
    }
    return $found;
}
