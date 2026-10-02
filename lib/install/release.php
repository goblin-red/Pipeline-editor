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
        '.gitignore' => "# Settings and keys of each installation (written by the installer)\nsecrets.php\nconfig_admin.php\nconfig_web.php\n\n"
            . "# Data: SQLite database, uploaded files, run folders, install mark\ndata/*\n!data/files/\ndata/files/*\n!data/files/.htaccess\n"
            . "storage/*\n!storage/.htaccess\nworkspace/*\n!workspace/.gitkeep\npublic/workfiles/*\n!public/workfiles/.htaccess\n\n"
            . "# Service\nbackup/\narchive/\n_up/\n.DS_Store\n*.log\n",
        'secrets.example.php' => "<?php\n/* Sample of secrets.php. Normally the installer writes it (install.php or php bin/server.php install) —\n"
            . "   no need to edit by hand. AI keys are your own; empty means the service is off. Change later in admin → Settings. */\n\n"
            . "declare(strict_types=1);\n\nreturn [\n    'pass' => '',              // MySQL database password; not needed for SQLite\n"
            . "    'admin_pass_hash' => '',   // php -r 'echo password_hash(\"password\", PASSWORD_DEFAULT);'\n"
            . "    'ai_key_deepseek' => '',   // DeepSeek — https://platform.deepseek.com\n"
            . "    'ai_key_openrouter' => '', // OpenRouter — https://openrouter.ai\n"
            . "    'voice_api_key' => '',     // OpenAI — voice (otherwise the browser voice)\n"
            . "    'jev_api_key' => '',       // Jev (TypeSafe) — signal model for decisions\n"
            . "    'ftp_pass' => '',          // publishing to your hosting — optional\n];\n",
    ];
}

function releaseReadme(string $version): string
{
    return <<<MD
# Goblin — flowchart workflows run by AI agents

**$version** · MIT license

Goblin is a visual workflow editor where you **draw a process as a flowchart** and **AI coding agents run it
step by step**. You describe what each block should do; the Goblin server turns the scheme into tasks, hands
them to an agent (Claude Code, Codex, Gemini CLI, Cursor and others), checks every result and moves the run
along the arrows — branches, loops and parallel paths included. You watch the run live on the canvas.

No framework, no build step: plain PHP on the server and ES modules in the browser. Runs on your own computer
or on ordinary shared hosting.

## Features

**Canvas editor**
- Blocks, decisions (yes/no), gateways, groups, areas (swimlanes), notes, tables and links — numbered hotkeys 1–8
- Arrows with labels, colors, nested folders (each folder is its own canvas), drag-and-drop between folders
- Several looks (Working, Classic, Studio, Design, Minimal and more) and a mobile layout
- English and Russian interface; instructions for agents in the project language

**Runs with AI agents**
- One link starts a run: the agent reads the server instructions and follows the scheme
- Two roles: a *lead* agent that drives the run and *workers* that do the tasks; or one agent doing everything
- Every block gets a clear task; results are checked (files present, formats, conditions) before the next step
- Decisions are answered by the agent, by a person or by the optional Jev signal model (TypeSafe)
- Live highlighting, a run log and a timeline; safe stop, retry and restart

**Built-in AI assistant** (bring your own key)
- Chat that edits the scheme for you: add steps, rearrange, explain
- Scheme builder: describe a goal, answer a few questions, get a ready flowchart
- Any OpenAI-compatible API: DeepSeek, OpenRouter (Qwen, DeepSeek and many more) or your own endpoint
- Voice input and reading answers aloud — with the browser speech engine (free) or OpenAI

**Ready-made catalog** — 26 working schemes in 10 sections: sites and landing pages, photo, video, texts,
social media, documents, data and APIs, audio, code, Goblin basics.

**Admin panel** — settings, AI connections and balances, people, projects, runs, tokens, backups and migrations.

## How a run works

1. Draw the process: a start node, blocks with descriptions, decisions and arrows.
2. Press **Start a run** and copy the run link.
3. Give the link to your agent in the terminal (Claude Code, Codex…). It reads the instructions and asks the
   server what to do next.
4. The server issues one task at a time, checks the answer and the files, follows the arrows and highlights
   progress on the canvas until the run is complete.

## Requirements

- PHP **8.1+** with `pdo_sqlite` or `pdo_mysql`, `curl`, `mbstring`, `zip`
- Database: **SQLite** (a single file — nothing to set up) or **MySQL 5.7+ / MariaDB 10.3+**
- Any web server: PHP built-in server, Apache (XAMPP, MAMP, shared hosting)

## Installation

### On your computer (macOS, PHP built-in server)

```bash
brew install php                       # macOS does not ship PHP
git clone https://github.com/goblin-red/workflow.git goblin
cd goblin
php bin/server.php install             # database, admin password, optional AI keys
php bin/server.php serve               # → http://localhost:8080
```

SQLite is suggested by default — the database is one file in `data/`.

### With XAMPP or MAMP

Put the `goblin` folder into `htdocs` and open `http://localhost/goblin/` — the installer page opens by itself.

### On a website (shared hosting)

1. Upload the files into a `goblin` folder of your site (or into the site root).
2. Open `https://your-site/goblin/` — the installer page opens.
3. Choose the database (MySQL is recommended for a website), set the admin password, optionally add AI keys.

The installer checks the server, creates the tables, loads the catalog of ready-made schemes and then
closes itself.

## Configuration

Everything is set in the admin panel — `admin.php`:

| What | Where |
|---|---|
| AI assistant (DeepSeek, OpenRouter, your own OpenAI-compatible API) | Settings → Built-in AI — model connections |
| Voice: browser (free) or OpenAI | Settings → Voice |
| Jev signal model for decisions | Settings → Jev |
| Database, paths, guests, file limits | Settings |

**API keys are not included.** Bring your own:
[DeepSeek](https://platform.deepseek.com) · [OpenRouter](https://openrouter.ai) ·
[OpenAI](https://platform.openai.com) (voice, optional) · [TypeSafe Jev](https://typesafe.ai) (optional).
Without keys the editor and runs with your own agents work; only the built-in assistant is off.
Each person can also use their own keys in their account.

Keys and passwords are stored in `secrets.php`, settings in `config_admin.php` — both are created by the
installer and never committed (see `.gitignore`, sample: `secrets.example.php`).

## Updating

```bash
git pull
php bin/server.php migrate --apply     # or: admin → System → Apply migrations
```

## Project layout

```
public/        web root: editor, admin, installer, API entry (api.php), browser code in public/src/
lib/           server: API, run engine, AI, admin, database layer (MySQL and SQLite)
views/         page templates
instructions/  instructions for lead and worker agents and the built-in AI (en, ru)
lang/          interface texts (en, ru)
sql/           schema for MySQL and SQLite, migrations, catalog of ready-made schemes
bin/server.php command line: install, serve, migrate, backup, release
md_backend/    developer documentation
```

## License

MIT — see [LICENSE](LICENSE).

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
