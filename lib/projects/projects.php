<?php
/* Проект: создать, прочитать, настроить, удалить.
   Отдаёт: projectGet(), projectUpdate(), projectDelete(), projectOpen(), projectShape().
   Не делает: не отдаёт схему папки — это folders/scheme.php.

   Ключ из ссылки #p=… — это и адрес, и пропуск на чтение: кто знает ссылку, тот видит. */

declare(strict_types=1);

/** GET project.get — проект и дерево папок. Без ключа — список проектов человека. */
function projectGet(): void
{
    $key = trim((string) request()['project']);
    if ($key === '' && !caller()['project_id']) {
        // Кто вошёл и режим — и здесь: у нового посетителя проекта ещё нет, а шапке они нужны.
        reply(['projects' => projectList(), 'me' => projectMe(), 'agentsRemote' => agentsRemote()]);
    }

    $project = requireProject(false);
    guestSeen((int) $project['id']);   // открыли — отметка для уборки проектов гостей
    guestSweep();                      // раз в сутки, если включена
    $view = askedView();

    $folders = array_map(
        static fn(array $row) => folderShape($row, $view),
        dbAll('SELECT * FROM folders WHERE project_id = ? ORDER BY sort, id', [$project['id']])
    );

    // agentsRemote — агенты на своих компьютерах (веб): их файлы браузер берёт из подключённой папки.
    $out = ['project' => projectShape($project) + ['agentsRemote' => agentsRemote(), 'filesLimit' => filesLimit()], 'folders' => $folders,
            // Кто вошёл: шапке — «Мой кабинет» или «Войти».
            'me' => projectMe()];

    if ($view === VIEW_FULL) {
        $out['agents'] = agentList((int) $project['id']);
    }
    if (!empty(input('full'))) {
        $out['scheme'] = [];
        foreach ($folders as $folder) {
            $out['scheme'][$folder['id']] = folderScheme((int) $folder['id'], $view);
        }
    }
    reply($out);
}

function projectShape(array $row): array
{
    return [
        'id'           => (int) $row['id'],
        'key'          => (string) $row['url_key'],
        'title'        => (string) $row['title'],
        'lang'         => (string) ($row['lang'] ?? 'ru'),
        'filesServer'  => (int) ($row['files_server'] ?? 0) === 1,
        'rev'          => (int) $row['rev'],
        'owner'        => $row['owner_id'] ? (int) $row['owner_id'] : null,
        'guestWrite'   => (int) $row['guest_write'] === 1,
        'aiConfirm'    => (int) $row['ai_confirm'] === 1,
        'strictChecks' => (int) $row['strict_checks'] === 1,
        'nextNo'       => (int) $row['next_no'],
        'nextRunNo'    => (int) $row['next_run_no'],
    ];
}

/**
 * Проекты человека: свои и те, куда он заходил.
 * Первым идёт тот, который правили последним, — его и открывает пустой адрес.
 * «Последний» считается по большему из двух: правка проекта и заход в него.
 */
function projectList(): array
{
    $userId = caller()['user_id'];
    // Гость: проекты по ключам, которые помнит его браузер. Вошёл — они становятся его.
    if (!$userId) return guestList(guestKeys());
    guestAdopt((int) $userId, guestKeys());
    $rows = dbAll(
        'SELECT p.*, seen.last_seen,
                GREATEST(COALESCE(seen.last_seen, p.updated_at), p.updated_at) AS touched,
                (SELECT COUNT(*) FROM folders f WHERE f.project_id = p.id) AS folders
           FROM projects p
           LEFT JOIN (SELECT project_id, MAX(last_seen) AS last_seen
                        FROM visits
                       WHERE user_id = ? AND project_id IS NOT NULL
                       GROUP BY project_id) seen ON seen.project_id = p.id
          WHERE p.owner_id = ? OR seen.project_id IS NOT NULL
          ORDER BY GREATEST(COALESCE(seen.last_seen, p.updated_at), p.updated_at) DESC
          LIMIT 200',
        [$userId, $userId]
    );
    /* `seen` — когда человек открывал проект, `touched` — последнее касание:
       открывал или правил. Сортируем по второму, поэтому его же и отдаём:
       иначе в списке стояла дата, которая порядку не соответствует. */
    return array_map(static fn(array $row) => projectShape($row) + [
        'folders' => (int) $row['folders'],
        'seen'    => (string) ($row['last_seen'] ?: $row['updated_at']),
        'touched' => (string) ($row['touched'] ?: $row['updated_at']),
    ], $rows);
}

/** Открыть проект по ключу, создав его, если такого ещё нет. */
function projectOpen(string $key): array
{
    if (!validProjectKey($key)) throw new ApiError(t('server.project.bad_key'), 'not_found');
    $row = dbRow('SELECT * FROM projects WHERE url_key = ?', [$key]);
    if ($row) return $row;

    // Язык агентов нового проекта — язык интерфейса того, кто его завёл; поменять — project.update lang.
    dbRun('INSERT INTO projects (url_key, owner_id, lang) VALUES (?,?,?)', [$key, caller()['user_id'], lang()]);
    return dbRow('SELECT * FROM projects WHERE id = ?', [dbId()]);
}

/** Кто вошёл — для шапки: имя и почта, гостю — null. */
function projectMe(): ?array
{
    $user = currentUser();
    return $user ? ['name' => (string) $user['name'], 'email' => (string) $user['email']] : null;
}

/** Операция project.update — настройки проекта, в том числе строгие проверки. */
function projectUpdate(array $op, array &$ctx): array
{
    if (array_key_exists('strictChecks', $op)
        && (int) $ctx['project']['strict_checks'] !== (!empty($op['strictChecks']) ? 1 : 0)) {
        activeGuardProject((int) $ctx['projectId'], 'strict_checks');
    }
    // Язык агентов и ИИ: инструкции, задания и лента прогона. Посреди прогона не меняется.
    if (array_key_exists('lang', $op)) {
        if (!in_array($op['lang'], LANGS, true)) throw new ApiError(t('server.project.bad_lang', ['langs' => implode(', ', LANGS)]), 'invalid');
        if ($op['lang'] !== (string) $ctx['project']['lang']) activeGuardProject((int) $ctx['projectId'], 'agent_lang');
    }
    $set = [];
    $args = [];
    $prev = [];
    $map = [
        'title'        => 'title',
        'guestWrite'   => 'guest_write',
        'aiConfirm'    => 'ai_confirm',
        'strictChecks' => 'strict_checks',
        'lang'         => 'lang',
        'filesServer'  => 'files_server',
    ];
    foreach ($map as $from => $column) {
        if (!array_key_exists($from, $op)) continue;
        $prev[$from] = $ctx['project'][$column];
        $set[] = "$column = ?";
        $args[] = match ($column) {
            'title' => mb_substr((string) $op[$from], 0, 255),
            'lang'  => (string) $op[$from],
            default => !empty($op[$from]) ? 1 : 0,
        };
    }
    if ($set) {
        $args[] = $ctx['projectId'];
        dbRun('UPDATE projects SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }
    return ['id' => $ctx['projectId'], 'prev' => $prev ?: null];
}

/** Операция project.delete — целиком, вместе с папками, элементами и прогонами. */
function projectDelete(array $op, array &$ctx): array
{
    $id = $ctx['projectId'];
    activeGuardProject((int) $id, 'delete_project');
    $dirs = dbAll('SELECT work_dir FROM folders WHERE project_id = ?', [$id]);
    // Служебное прогонов без папки на сервере (агент в вебе) — storage/runs/<id>: удаляется с проектом.
    $stored = array_column(dbAll("SELECT id FROM runs WHERE project_id = ? AND work_dir = ''", [$id]), 'id');
    dbRun('DELETE FROM projects WHERE id = ?', [$id]);
    // Пустые рабочие папки, заведённые сами, — долой (после фиксации); с файлами остаются.
    foreach ($dirs as $one) dbAfterCommit(static fn() => workDirDropIfEmpty((string) $one['work_dir']));
    foreach ($stored as $runId) {
        $dir = dirname(__DIR__, 2) . '/storage/runs/' . (int) $runId;
        dbAfterCommit(static function () use ($dir): void {
            if (is_dir($dir)) { rmTree($dir); @rmdir($dir); }
        });
    }
    // Проекта больше нет — писать в его журнал некуда: он уехал каскадом.
    return ['id' => $id, 'deleted' => true, 'no_journal' => true];
}
