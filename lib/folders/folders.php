<?php
/* Папки: создать, переименовать, переместить, удалить.
   Отдаёт: folderRow(), folderCreate(), folderUpdate(), folderDelete(), folderOpen(), folderRunEnv(),
           folderRoleScheme(), folderUsesAgentCards(), workDirFor(), workDirIn(), workDirDropIfEmpty().
   Не делает: не собирает схему для холста — это folders/scheme.php.

   Папка — это холст. Папки вкладываются друг в друга; удаление уносит ветку целиком. */

declare(strict_types=1);

/**
 * Среды прогона: как leader связан с worker. Выбирается в папке (вкладка
 * «Агенты») и действует только в команде; первая — по умолчанию. По ней
 * сервер собирает инструкции агентов (lib/api/instructions.php).
 */
const RUN_ENVS = [
    'subagents'   => ['title' => 'Субагенты — внутри leader'],
    'orca'        => ['title' => 'Орка — отдельные терминалы'],
    'sendmessage' => ['title' => 'SendMessage — сессии Claude'],
];

/**
 * Карточки агентов на блоках действуют только в команде, в Орке и SendMessage:
 * worker на карточке — шаг ему, ведущий или пусто — делает ведущий. В solo всё
 * делает ведущий, в субагентах исполнителя выбирает он сам.
 */
function folderUsesAgentCards(int $folderId): bool
{
    $folder = dbRow('SELECT run_env, role_scheme FROM folders WHERE id = ?', [$folderId]);
    return $folder !== null && in_array(folderActiveRunEnv($folder), ['orca', 'sendmessage'], true);
}

/** Действующая среда: в solo — никакой (сохранённая ждёт команды), в команде пусто — субагенты. */
function folderActiveRunEnv(array $folder): string
{
    if ((string) ($folder['role_scheme'] ?? 'solo') === 'solo') return '';
    $env = (string) ($folder['run_env'] ?? '');
    return isset(RUN_ENVS[$env]) ? $env : 'subagents';
}

/** Среда прогона из запроса: одна из RUN_ENVS; '' оставлен старым вызовам — это субагенты. */
function folderRunEnv($value): string
{
    $value = trim((string) $value);
    if ($value !== '' && !isset(RUN_ENVS[$value])) {
        throw new ApiError(t('server.folder.bad_env', ['value' => $value, 'list' => implode(', ', array_keys(RUN_ENVS))]));
    }
    return $value;
}

/**
 * Иерархия ролей: как распределены роли в прогоне. Выбирается в папке (вкладка
 * «Агенты», над средой). Solo отдаёт работу ведущему без worker; остальные
 * составы допускают worker, но отдельные роли консультанта и аудитора пока
 * только показываются в ссылке 🔗. 'soon' — схема задумана, инструкций для неё
 * ещё нет: выбрать можно, в списке помечена «(позже)».
 */
const ROLE_SCHEMES = [
    'solo'                 => ['title' => 'Ведущий делает всё сам'],
    'leader-worker'        => ['title' => 'Ведущий — воркер'],
    'consult-worker'       => ['title' => 'Ведущий — консультант — воркер', 'soon' => true],
    'consult-audit-worker' => ['title' => 'Ведущий — консультант — аудитор — воркер', 'soon' => true],
];

/** Иерархия ролей из запроса: одна из ROLE_SCHEMES, пусто — solo. */
function folderRoleScheme($value): string
{
    $value = trim((string) $value);
    if ($value === '') return 'solo';
    if (!isset(ROLE_SCHEMES[$value])) {
        throw new ApiError(t('server.folder.bad_roles', ['value' => $value, 'list' => implode(', ', array_keys(ROLE_SCHEMES))]));
    }
    return $value;
}

function folderRow(int $id, int $projectId): array
{
    $row = dbRow('SELECT * FROM folders WHERE id = ?', [$id]);
    return sameProject($row, $projectId, 'folder');
}

/**
 * Рабочая папка схемы: пусто или путь внутри разрешённых корней (`file_roots`) и только своего проекта.
 * Отсюда worker читают входы и кладут результаты, сюда же смотрит уборка прогона,
 * поэтому чужой путь означал бы чтение и стирание файлов мимо Гоблина.
 */
function folderWorkDir(string $dir, int $projectId): string
{
    $dir = trim($dir);
    if ($dir === '') return '';
    // Примерка заготовки (templateCheck) ставит корень рабочих файлов и откатывается целиком.
    if (batchDryRun()) return pathMustBeInsideRoots($dir, 'work_dir');
    return pathMustBeInProject($dir, $projectId, 'work_dir');
}

/**
 * Путь на диске — внутри корней установки и в пределах своего проекта (Б2): корни общие для всех проектов.
 * В папке рабочих файлов (workfiles_dir) — только в ветке «пользователь/проект-<id>» этого проекта или внутри
 * уже заданной рабочей папки его схем; вне её — не задевая рабочих папок других проектов.
 */
function pathMustBeInProject(string $path, int $projectId, string $what): string
{
    $real = pathMustBeInsideRoots($path, $what);
    $inside = static fn(string $p, string $dir): bool => $p === $dir || str_starts_with($p, $dir . '/');
    $refuse = new ApiError(t('server.path.other_project', ['what' => entityLabel($what), 'path' => $path]), 'forbidden',
        ['code2' => 'other_project']);

    foreach (dbAll("SELECT project_id, work_dir FROM folders WHERE work_dir <> ''") as $row) {
        $dir = realpath((string) $row['work_dir']) ?: rtrim((string) $row['work_dir'], '/');
        if ((int) $row['project_id'] === $projectId) {
            if ($inside($real, $dir)) return $real;
        } elseif ($inside($real, $dir) || $inside($dir, $real)) {
            throw $refuse;
        }
    }
    $base = realpath((string) config()['workfiles_dir']) ?: '';
    if ($base === '' || !$inside($real, $base) && !$inside($base, $real)) return $real;
    // Ветка проекта: «пользователь-id/проект-id/…» (folderTreePath) — второй шаг оканчивается его id.
    $steps = explode('/', substr($real, strlen($base) + 1));
    if ($real !== $base && count($steps) >= 2 && preg_match('/(^|-)' . $projectId . '$/', $steps[1])) return $real;
    throw $refuse;
}

/** Новая рабочая папка: public/workfiles/<пользователь>/<проект>/<папки>/<схема> (workfiles_dir в config.php) —
    та же структура, что у агента в вебе (folderTreePath). Нужны id, name, project_id и parent_id папки. */
function workDirFor(array $folder): string
{
    $base = rtrim((string) (config()['workfiles_dir'] ?? config()['workspace_dir']), '/');
    $path = $base . '/' . folderTreePath($folder);
    if (!is_dir($path)) {
        if (!mkdir($path, 0775, true)) throw new ApiError(t('server.folder.mkdir_failed', ['path' => $path]));
        dbOnRollback(static fn() => workDirDropIfEmpty($path));   // пачку откатили — папку тоже
    }
    return $path;
}

/**
 * В рабочей папке — подпапка in/: там лежат материалы. Нет самой папки — заводим.
 * Только после фиксации и не в пробной пачке: откатят правку — на диске следа не останется.
 */
function workDirIn(string $dir): void
{
    if ($dir === '' || batchDryRun()) return;
    dbAfterCommit(static fn() => is_dir($dir . '/in') || @mkdir($dir . '/in', 0775, true));
}

/**
 * Папку схемы удалили: её рабочую папку убираем, только если она там, где их
 * заводят сами (workfiles_dir, workspace_dir), и пуста (кроме пустой in/).
 */
function workDirDropIfEmpty(string $dir): void
{
    $real = $dir !== '' ? realpath($dir) : false;
    if (!$real) return;
    $ours = '';
    foreach (['workfiles_dir', 'workspace_dir'] as $key) {
        $base = realpath((string) (config()[$key] ?? ''));
        if ($base && str_starts_with($real, $base . '/')) $ours = $base;
    }
    if ($ours === '') return;
    // Пустые вложенные папки (in/, out/rN/ прогона, который ничего не сдал) — долой снизу вверх;
    // есть хоть один файл — его папки и сама рабочая остаются.
    $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($dirs as $one) {
        if ($one->isDir() && !$one->isLink() && count(scandir($one->getPathname())) === 2) @rmdir($one->getPathname());
    }
    if (count(scandir($real)) === 2) @rmdir($real);
    // Папки пользователя и проекта над схемой (folderTreePath) опустели — тоже долой, до корня рабочих папок.
    for ($up = dirname($real); $up !== $ours && str_starts_with($up, $ours . '/') && is_dir($up)
            && count(scandir($up)) === 2; $up = dirname($up)) {
        @rmdir($up);
    }
}

function folderCreate(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $parent = null;
    if (!empty($op['parent'])) {
        $parent = (int) folderRow((int) $op['parent'], $projectId)['id'];
    }
    $sort = isset($op['sort']) ? (int) $op['sort']
        : (int) dbValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM folders WHERE project_id = ? AND parent_id <=> ?', [$projectId, $parent]);

    $name = mb_substr((string) ($op['name'] ?? ''), 0, 255);
    $workDir = folderWorkDir((string) ($op['workDir'] ?? ''), $projectId);
    dbRun(
        'INSERT INTO folders (project_id, parent_id, name, sort, work_dir, work_url, share_scheme, run_env, role_scheme, style, rev, content_rev)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $projectId, $parent,
            $name,
            $sort,
            $workDir,
            (string) ($op['workUrl'] ?? ''),
            !empty($op['shareScheme']) ? 1 : 0,
            folderRunEnv($op['runEnv'] ?? ''),
            folderRoleScheme($op['roleScheme'] ?? ''),
            json_encode((array) ($op['style'] ?? []), JSON_UNESCAPED_UNICODE),
            $ctx['rev'], $ctx['rev'],
        ]
    );
    $id = dbId();
    // Рабочая папка заводится сразу — public/workfiles/<имя папки>, в ней in/ для материалов.
    // Пробная пачка (проверка правок ИИ) диск не трогает: её откатят.
    if (batchDryRun()) return ['id' => $id, 'folder_id' => $id];
    if ($workDir === '') {
        $workDir = workDirFor(['id' => $id, 'name' => $name, 'project_id' => $projectId, 'parent_id' => $parent]);
        dbRun('UPDATE folders SET work_dir = ? WHERE id = ?', [$workDir, $id]);
    }
    workDirIn($workDir);
    return ['id' => $id, 'folder_id' => $id];
}

function folderUpdate(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $id = (int) ($op['id'] ?? 0);
    $row = folderRow($id, $projectId);

    // Состав команды и среда тоже смысл: по ним собираются инструкции и задания живого прогона.
    $semanticChange = (array_key_exists('name', $op) && (string) $op['name'] !== (string) $row['name'])
        || (array_key_exists('workDir', $op) && (string) $op['workDir'] !== (string) $row['work_dir'])
        || (array_key_exists('workUrl', $op) && (string) $op['workUrl'] !== (string) $row['work_url'])
        || (array_key_exists('shareScheme', $op)
            && (!empty($op['shareScheme']) ? 1 : 0) !== (int) $row['share_scheme'])
        || (array_key_exists('roleScheme', $op) && folderRoleScheme($op['roleScheme']) !== (string) $row['role_scheme'])
        || (array_key_exists('runEnv', $op) && folderRunEnv($op['runEnv']) !== (string) $row['run_env']);
    if ($semanticChange) {
        activeGuardFolders($projectId, [$id], 'folder_settings');
    }
    if (array_key_exists('parent', $op)) {
        $parent = $op['parent'] === null ? null : (int) $op['parent'];
        $oldParent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        if ($parent !== $oldParent) {
            $affected = folderBranch($id);
            if ($parent !== null) $affected[] = $parent;
            activeGuardFolders($projectId, $affected, 'move_folder');
        }
    }

    $set = [];
    $args = [];
    $prev = [];

    $simple = ['name' => 'name', 'workDir' => 'work_dir', 'workUrl' => 'work_url'];
    foreach ($simple as $from => $column) {
        if (!array_key_exists($from, $op)) continue;
        $prev[$from] = $row[$column];
        $set[] = "$column = ?";
        $args[] = $from === 'workDir'
            ? (trim((string) $op[$from]) === (string) $row['work_dir'] ? (string) $row['work_dir']   // прежняя — как есть
                : folderWorkDir((string) $op[$from], (int) $row['project_id']))
            : (string) $op[$from];
        if ($from === 'workDir') workDirIn(end($args));
    }
    if (array_key_exists('shareScheme', $op)) {
        $prev['shareScheme'] = (int) $row['share_scheme'];
        $set[] = 'share_scheme = ?';
        $args[] = !empty($op['shareScheme']) ? 1 : 0;
    }
    // Среда прогона и состав команды — под защитой живого прогона (выше, semanticChange).
    if (array_key_exists('runEnv', $op)) {
        $prev['runEnv'] = (string) $row['run_env'];
        $set[] = 'run_env = ?';
        $args[] = folderRunEnv($op['runEnv']);
    }
    if (array_key_exists('roleScheme', $op)) {
        $prev['roleScheme'] = (string) $row['role_scheme'];
        $set[] = 'role_scheme = ?';
        $args[] = folderRoleScheme($op['roleScheme']);
    }
    if (array_key_exists('sort', $op)) {
        $prev['sort'] = (int) $row['sort'];
        $set[] = 'sort = ?';
        $args[] = (int) $op['sort'];
    }
    if (array_key_exists('parent', $op)) {
        $parent = $op['parent'] === null ? null : (int) folderRow((int) $op['parent'], $projectId)['id'];
        if ($parent !== null) folderNoCycle($parent, $id);
        $prev['parent'] = $row['parent_id'] ? (int) $row['parent_id'] : null;
        $set[] = 'parent_id = ?';
        $args[] = $parent;
    }
    if (array_key_exists('style', $op)) {
        $old = json_decode((string) $row['style'], true) ?: [];
        $prev['style'] = $old;
        $set[] = 'style = ?';
        $args[] = json_encode(array_merge($old, (array) $op['style']), JSON_UNESCAPED_UNICODE);
    }
    // Камера не двигает ревизию: это положение глаза, а не содержимое.
    if (array_key_exists('camera', $op)) {
        dbRun('UPDATE folders SET camera = ? WHERE id = ?', [json_encode((array) $op['camera']), $id]);
    }

    if ($set) {
        $set[] = 'rev = ?';
        $args[] = $ctx['rev'];
        $args[] = $id;
        dbRun('UPDATE folders SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }
    return ['id' => $id, 'prev' => $prev ?: null];
}

function folderDelete(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $id = (int) ($op['id'] ?? 0);
    folderRow($id, $projectId);

    $branch = folderBranch($id);
    activeGuardFolders($projectId, $branch, 'delete_folder');
    // Ярлыки и цели шлюзов через границу ветки внешний ключ убрал бы молча — делаем это
    // сами, с надгробиями и ревизией: иначе открытые редакторы ничего не узнают.
    linksCutAcross($branch, $projectId, $ctx['rev']);
    gatesCutAcross($branch, $projectId, $ctx['rev']);
    $gone = dbAll('SELECT id, name, work_dir FROM folders WHERE id IN (' . implode(',', array_map('intval', $branch)) . ')');
    dbRun('DELETE FROM folders WHERE id = ?', [$id]);
    foreach ($gone as $one) {
        markDeleted($projectId, 'folder', (int) $one['id'], $ctx['rev'], ['title' => $one['name']]);
        // Рабочую папку убираем только после фиксации: откатят пачку — папка нужна.
        dbAfterCommit(static fn() => workDirDropIfEmpty((string) $one['work_dir']));
    }
    return ['id' => $id, 'deleted' => count($branch)];
}

/** Папка и все её потомки. */
function folderBranch(int $id): array
{
    $out = [$id];
    $queue = [$id];
    while ($queue) {
        $current = array_pop($queue);
        foreach (dbAll('SELECT id FROM folders WHERE parent_id = ?', [$current]) as $row) {
            $out[] = (int) $row['id'];
            $queue[] = (int) $row['id'];
        }
    }
    return $out;
}

function folderNoCycle(int $parentId, int $folderId): void
{
    $id = $parentId;
    $seen = [];
    while ($id) {
        if ($id === $folderId) throw new ApiError(t('server.folder.into_itself'));
        if (isset($seen[$id])) break;
        $seen[$id] = true;
        $id = (int) (dbValue('SELECT parent_id FROM folders WHERE id = ?', [$id]) ?? 0);
    }
}

/**
 * POST folder.open {folder} — открыть рабочую папку схемы в Finder.
 * Только когда Гоблин стоит на том же Mac, за которым человек (запрос с localhost): в вебе сервер
 * чужой, и окно открылось бы не у человека — там кнопка копирует путь (panel/panel.js).
 */
function folderOpen(): void
{
    $project = requireProject(true);
    $folder = folderRow((int) inputInt('folder'), (int) $project['id']);
    if (agentsRemote() || PHP_OS_FAMILY !== 'Darwin') throw new ApiError(t('server.folder.open_local_only'), 'conflict');

    $dir = (string) $folder['work_dir'];
    if ($dir === '' || !is_dir($dir)) throw new ApiError(t('server.folder.open_missing', ['dir' => $dir]), 'not_found');
    exec('open ' . escapeshellarg($dir) . ' 2>&1', $said, $code);
    if ($code !== 0) throw new ApiError(t('server.folder.open_failed', ['why' => implode(' ', $said)]), 'conflict');
    reply(['opened' => $dir]);
}
