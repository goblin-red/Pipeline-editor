<?php
/* Админка Гоблина: вход, данные разделов JSON-ом (?api=…) и оболочка страницы.
   Данные — lib/admin/data.php, действия — lib/admin/actions.php, экран — public/src/admin/,
   стили — public/css/admin.css.
   Вход — свой: логин и хеш пароля из config/secrets (lib/access/admin.php), своя cookie.
   Хеш пароля: php -r 'echo password_hash("пароль", PASSWORD_DEFAULT);' */

declare(strict_types=1);

require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/web/page.php';
require __DIR__ . '/../lib/admin/data.php';
require __DIR__ . '/../lib/admin/actions.php';
require __DIR__ . '/../lib/admin/settings.php';
require __DIR__ . '/../lib/admin/backup.php';

if (installNeeded()) {
    header('Location: install.php');
    exit;
}

if (isset($_GET['logout'])) { adminLogout(); header('Location: admin.php'); exit; }

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    if (adminLogin((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: admin.php');
        exit;
    }
    $notice = t('admin.login.bad');
}

if (isset($_GET['api'])) { adminApi((string) $_GET['api']); exit; }
// Бэкап кнопкой: ?download=db — дамп базы, ?download=files — материалы, рабочие папки, настройки.
if (isset($_GET['download']) && adminIn()) {
    try {
        $_GET['download'] === 'db' ? backupSendDb() : backupSendFiles();
    } catch (ApiError $error) {
        http_response_code($error->status());
        header('Content-Type: text/plain; charset=utf-8');
        echo $error->getMessage();
    }
    exit;
}
if (!adminIn()) { adminLoginPage($notice); exit; }
adminShell();

/* ── Данные JSON-ом ───────────────────────────────────────────── */

/** GET ?api=раздел — данные; POST ?api=action {do, …} — действие. */
function adminApi(string $name): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!adminIn()) throw new ApiError(t('admin.err.need_login'), 'unauthorized');
        $id = (int) ($_GET['id'] ?? 0);
        if ($name === 'action') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError(t('admin.err.post_only'));
            requireSameOrigin();
            $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $data = adminAction((string) ($in['do'] ?? ''), $in);
        } else {
            $data = match ($name) {
                'overview'  => adminOverview(),
                'projects'  => ['rows' => adminProjects()],
                'project'   => adminProject($id),
                'schemes'   => ['rows' => adminSchemes(null, !empty($_GET['check']))],
                'scheme'    => adminScheme($id),
                'runs'      => ['rows' => adminRuns()],
                'run'       => adminRun($id),
                'people'    => adminPeople(),
                'person'    => adminPerson($id),
                'journal'   => adminJournal($_GET, (int) ($_GET['page'] ?? 1), (int) ($_GET['size'] ?? 50)),
                'entry'     => adminJournalEntry($id),
                'ai'        => adminAi(),
                'tokens'    => ['rows' => adminTokens()],
                'templates' => adminCatalog(),
                'system'    => adminSystem(),
                'settings'  => settingsView(),
                default     => throw new ApiError(t('admin.err.no_section_named', ['name' => $name]), 'not_found'),
            };
        }
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (ApiError $error) {
        http_response_code($error->status());
        echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        http_response_code(500);
        error_log('[goblin] admin: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
        echo json_encode(['ok' => false, 'error' => t('admin.err.server_fail', ['error' => $error->getMessage()])], JSON_UNESCAPED_UNICODE);
    }
}

/* ── Страницы ─────────────────────────────────────────────────── */

function adminHead(string $title): void
{
    $root = dirname(__DIR__);
    $version = editorVersion($root);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="', lang(), '" data-theme="dark"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1"><title>', h($title), '</title>',
         '<link rel="stylesheet" href="css/tokens.css?v=', $version, '">',
         '<link rel="stylesheet" href="css/base.css?v=', $version, '">',
         '<link rel="stylesheet" href="css/admin.css?v=', $version, '">',
         '<script type="importmap">', importMap($root, $version), '</script>', langScript(['common', 'admin']), '</head>';
}

/** Оболочка: боковое меню и место для раздела; всё остальное рисует public/src/admin/main.js. */
function adminShell(): void
{
    $version = editorVersion(dirname(__DIR__));
    adminHead(t('admin.page.title'));
    echo '<body class="adm"><aside class="adm-side">',
         '<a class="adm-brand" href="admin.php"><img src="img/logo-mark.svg" alt="" width="32" height="24"><span>', t('admin.page.brand'), '<small>', t('admin.page.brand_sub'), '</small></span></a>',
         '<nav class="adm-nav" id="adm-nav"></nav>',
         '<div class="adm-side-foot"><a href="index.php">', t('admin.page.editor'), '</a><a href="admin.php?logout=1">', t('admin.page.logout'), '</a></div>',
         '</aside><main class="adm-main" id="adm-main"></main>',
         '<div class="adm-toast" id="adm-toast" hidden></div>',
         '<script type="module" src="src/admin/main.js?v=', $version, '"></script></body></html>';
}

function adminLoginPage(string $notice): void
{
    adminHead(t('admin.login.title'));
    echo '<body class="adm adm-login"><form class="adm-login-card" method="post">',
         '<img src="img/logo-vertical-dark.svg" alt="" width="64" height="80">',
         '<h1>', t('admin.login.h1'), '</h1>';
    if ($notice) echo '<p class="adm-bad">', h($notice), '</p>';
    echo '<input type="hidden" name="do" value="login">',
         '<label class="field"><span>', t('admin.login.login'), '</span><input class="input" name="login" autocomplete="username" required autofocus></label>',
         '<label class="field"><span>', t('admin.login.password'), '</span><input class="input" type="password" name="password" autocomplete="current-password" required></label>',
         '<button class="btn btn-accent btn-wide">', t('admin.login.submit'), '</button></form></body></html>';
}
