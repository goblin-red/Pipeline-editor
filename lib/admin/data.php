<?php
/* Админка: данные разделов — только чтение.
   Отдаёт: adminOverview(), adminProjects(), adminProject(), adminSchemes(), adminScheme(),
           adminRuns(), adminRun(), adminPeople(), adminPerson(), adminJournal(), adminAi(),
           adminTokens(), adminTemplates(), adminSystem().
   Не делает: ничего не меняет (это lib/admin/actions.php) и не проверяет вход (public/admin.php).

   Каждая функция отдаёт готовый к показу массив; даты — строками сервера, числа — числами.
   Подключается только из public/admin.php, в lib/boot.php не вписана: API она не нужна. */

declare(strict_types=1);

/* ── Обзор ────────────────────────────────────────────────────── */

function adminOverview(): array
{
    $days = 30;
    $count = static fn(string $sql, array $args = []) => (int) dbValue($sql, $args);

    $kpi = [
        'projects'      => $count('SELECT COUNT(*) FROM projects'),
        'projectsWeek'  => $count('SELECT COUNT(*) FROM projects WHERE updated_at > NOW() - INTERVAL 7 DAY'),
        'schemes'       => $count('SELECT COUNT(*) FROM folders'),
        'elements'      => $count("SELECT COUNT(*) FROM elements WHERE type <> 'arrow'"),
        'users'         => $count('SELECT COUNT(*) FROM users'),
        'visitors'      => $count('SELECT COUNT(DISTINCT visitor) FROM visits WHERE last_seen > NOW() - INTERVAL 30 DAY'),
        'runs'          => $count('SELECT COUNT(*) FROM runs'),
        'runsLive'      => $count("SELECT COUNT(*) FROM runs WHERE state IN ('running','paused')"),
        'aiCalls'       => $count('SELECT COUNT(*) FROM ai_jobs WHERE created_at > NOW() - INTERVAL 30 DAY'),
        'aiTokens'      => $count('SELECT COALESCE(SUM(tokens_total),0) FROM ai_jobs WHERE created_at > NOW() - INTERVAL 30 DAY'),
        'edits'         => $count('SELECT COUNT(*) FROM journal WHERE created_at > NOW() - INTERVAL 30 DAY'),
        'dbMb'          => (int) round(array_sum(array_column(adminTables(), 'mb'))),
        // Платные модели за всё время (журнал api_calls, lib/ai/calls.php).
        'dsCalls'       => $count("SELECT COUNT(*) FROM api_calls WHERE service = 'deepseek'"),
        'dsTokens'      => $count("SELECT COALESCE(SUM(tokens_in + tokens_out),0) FROM api_calls WHERE service = 'deepseek'"),
        'jevCalls'      => $count("SELECT COUNT(*) FROM api_calls WHERE service = 'jev'"),
        'jevTokens'     => $count("SELECT COALESCE(SUM(tokens_in + tokens_out),0) FROM api_calls WHERE service = 'jev'"),
    ];

    // Активность по дням: правки, прогоны, обращения к ИИ.
    $series = static function (string $sql) use ($days): array {
        $rows = [];
        foreach (dbAll($sql, [$days]) as $row) $rows[$row['day']] = (int) $row['n'];
        return array_map(static fn(string $day) => ['day' => $day, 'n' => $rows[$day] ?? 0], adminDays($days));
    };
    $activity = [
        'edits' => $series('SELECT DATE(created_at) day, COUNT(*) n FROM journal WHERE created_at > CURDATE() - INTERVAL ? DAY GROUP BY day'),
        'runs'  => $series('SELECT DATE(started_at) day, COUNT(*) n FROM runs WHERE started_at > CURDATE() - INTERVAL ? DAY GROUP BY day'),
        'ai'    => $series('SELECT DATE(created_at) day, COUNT(*) n FROM ai_jobs WHERE created_at > CURDATE() - INTERVAL ? DAY GROUP BY day'),
    ];

    $live = dbAll("SELECT r.id, r.no, r.state, r.started_at, r.wait_for, p.url_key, p.title AS project, f.name AS folder, r.folder_id
                     FROM runs r JOIN projects p ON p.id = r.project_id LEFT JOIN folders f ON f.id = r.folder_id
                    WHERE r.state IN ('running','paused') ORDER BY r.id DESC LIMIT 20");
    $recent = dbAll('SELECT p.id, p.url_key, p.title, p.updated_at, u.email AS owner
                       FROM projects p LEFT JOIN users u ON u.id = p.owner_id ORDER BY p.updated_at DESC LIMIT 8');

    return ['kpi' => $kpi, 'activity' => $activity, 'live' => $live, 'recent' => $recent, 'health' => adminHealth(),
            'balance' => apiBalance()];
}

/**
 * Последние $n дней, старые первыми, — по календарю базы: строки считаются по DATE(created_at)
 * в её поясе, и день PHP (другой пояс) у полуночи сдвигал бы столбики.
 */
function adminDays(int $n): array
{
    $today = (string) dbValue('SELECT CURDATE()');
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) $out[] = date('Y-m-d', strtotime("$today -$i day"));
    return $out;
}

/** Что требует внимания: миграции, зависшее, сбои за сутки. */
function adminHealth(): array
{
    $out = [];
    $waiting = schemaMigrations()['waiting'];
    if ($waiting) $out[] = ['level' => 'bad', 'say' => t('admin.health.migrations', ['list' => implode(', ', $waiting)])];
    $stuck = (int) dbValue("SELECT COUNT(*) FROM ai_jobs WHERE state IN ('queued','running') AND created_at < NOW() - INTERVAL 10 MINUTE");
    if ($stuck) $out[] = ['level' => 'warn', 'say' => t('admin.health.stuck', ['n' => $stuck])];
    $aiFailed = (int) dbValue("SELECT COUNT(*) FROM ai_jobs WHERE state = 'failed' AND created_at > NOW() - INTERVAL 1 DAY");
    if ($aiFailed) $out[] = ['level' => 'warn', 'say' => t('admin.health.ai_failed', ['n' => $aiFailed])];
    $runFailed = (int) dbValue("SELECT COUNT(*) FROM runs WHERE state = 'failed' AND started_at > NOW() - INTERVAL 1 DAY");
    if ($runFailed) $out[] = ['level' => 'warn', 'say' => t('admin.health.runs_failed', ['n' => $runFailed])];
    $errors = count(adminErrorLines(500, 1));
    if ($errors) $out[] = ['level' => 'warn', 'say' => t('admin.health.errors', ['n' => $errors])];
    if ((string) (config()['ai_api_key'] ?? '') === '') $out[] = ['level' => 'warn', 'say' => t('admin.health.no_key')];
    if (!$out) $out[] = ['level' => 'ok', 'say' => t('admin.health.ok')];
    return $out;
}

/* ── Проекты ──────────────────────────────────────────────────── */

function adminProjects(): array
{
    return array_map('adminProjectRow', dbAll(
        "SELECT p.*, u.email AS owner,
                (SELECT COUNT(*) FROM folders f WHERE f.project_id = p.id) AS folders,
                (SELECT COUNT(*) FROM elements e WHERE e.project_id = p.id AND e.type <> 'arrow') AS elements,
                (SELECT COUNT(*) FROM runs r WHERE r.project_id = p.id) AS runs,
                (SELECT COUNT(*) FROM runs r WHERE r.project_id = p.id AND r.state IN ('running','paused')) AS live,
                (SELECT COUNT(*) FROM assets a WHERE a.project_id = p.id) AS assets,
                (SELECT COUNT(*) FROM agents g WHERE g.project_id = p.id) AS agents,
                (SELECT COUNT(*) FROM ai_chats c WHERE c.project_id = p.id) AS chats,
                (SELECT MAX(j.created_at) FROM journal j WHERE j.project_id = p.id) AS last_edit,
                (SELECT COUNT(DISTINCT v.visitor) FROM visits v WHERE v.project_id = p.id) AS visitors
           FROM projects p LEFT JOIN users u ON u.id = p.owner_id
          ORDER BY p.updated_at DESC"
    ));
}

function adminProjectRow(array $r): array
{
    return [
        'id' => (int) $r['id'], 'key' => $r['url_key'], 'title' => (string) $r['title'],
        'owner' => $r['owner'], 'ownerId' => $r['owner_id'] ? (int) $r['owner_id'] : null,
        'folders' => (int) $r['folders'], 'elements' => (int) $r['elements'], 'runs' => (int) $r['runs'],
        'live' => (int) $r['live'], 'assets' => (int) $r['assets'], 'agents' => (int) $r['agents'],
        'chats' => (int) $r['chats'], 'visitors' => (int) $r['visitors'],
        'guestWrite' => (int) $r['guest_write'] === 1, 'aiConfirm' => (int) $r['ai_confirm'] === 1,
        'strictChecks' => (int) $r['strict_checks'] === 1,
        'created' => $r['created_at'], 'updated' => $r['updated_at'], 'lastEdit' => $r['last_edit'],
    ];
}

function adminProject(int $id): array
{
    $row = dbRow('SELECT p.*, u.email AS owner FROM projects p LEFT JOIN users u ON u.id = p.owner_id WHERE p.id = ?', [$id]);
    if (!$row) throw new ApiError(t('admin.err.no_project'), 'not_found');
    $row = array_merge($row, adminProjectsRaw($id)[0] ?? []);
    return [
        'project' => adminProjectRow($row),
        'schemes' => adminSchemes($id),
        'runs'    => adminRuns($id, 20),
        'agents'  => dbAll('SELECT id, name, role, cli, model, created_at FROM agents WHERE project_id = ? ORDER BY name', [$id]),
        'journal' => adminJournal(['project' => $id], 1, 15)['rows'],
        'users'   => dbAll('SELECT id, email FROM users ORDER BY email'),
    ];
}

/** Счётчики одного проекта — той же выборкой, что список. */
function adminProjectsRaw(int $id): array
{
    return dbAll(
        "SELECT (SELECT COUNT(*) FROM folders f WHERE f.project_id = p.id) AS folders,
                (SELECT COUNT(*) FROM elements e WHERE e.project_id = p.id AND e.type <> 'arrow') AS elements,
                (SELECT COUNT(*) FROM runs r WHERE r.project_id = p.id) AS runs,
                (SELECT COUNT(*) FROM runs r WHERE r.project_id = p.id AND r.state IN ('running','paused')) AS live,
                (SELECT COUNT(*) FROM assets a WHERE a.project_id = p.id) AS assets,
                (SELECT COUNT(*) FROM agents g WHERE g.project_id = p.id) AS agents,
                (SELECT COUNT(*) FROM ai_chats c WHERE c.project_id = p.id) AS chats,
                (SELECT MAX(j.created_at) FROM journal j WHERE j.project_id = p.id) AS last_edit,
                (SELECT COUNT(DISTINCT v.visitor) FROM visits v WHERE v.project_id = p.id) AS visitors
           FROM projects p WHERE p.id = ?", [$id]);
}

/* ── Схемы (папки) ────────────────────────────────────────────── */

/** Схемы установки или проекта; $folderId — одна схема той же выборкой (карточка схемы). */
function adminSchemes(?int $projectId = null, bool $check = false, ?int $folderId = null): array
{
    $rows = dbAll(
        "SELECT f.id, f.name, f.work_dir, f.parent_id, f.created_at, f.updated_at, f.project_id, p.url_key, p.title AS project,
                SUM(e.type = 'block') AS blocks, SUM(e.type = 'decision') AS decisions, SUM(e.type = 'gateway') AS gateways,
                SUM(e.type = 'arrow') AS arrows, SUM(e.type IN ('group','area')) AS frames, SUM(e.type = 'link') AS links,
                SUM(e.type = 'block' AND EXISTS (SELECT 1 FROM props pr WHERE pr.element_id = e.id AND pr.name = 'start' AND pr.value = '1')) AS starters,
                (SELECT COUNT(*) FROM runs r WHERE r.folder_id = f.id) AS runs,
                (SELECT r.state FROM runs r WHERE r.folder_id = f.id ORDER BY r.id DESC LIMIT 1) AS last_run,
                (SELECT MAX(r.started_at) FROM runs r WHERE r.folder_id = f.id) AS last_run_at
           FROM folders f JOIN projects p ON p.id = f.project_id
           LEFT JOIN elements e ON e.folder_id = f.id
          WHERE (? IS NULL OR f.project_id = ?) AND (? IS NULL OR f.id = ?)
          GROUP BY f.id ORDER BY f.updated_at DESC",
        [$projectId, $projectId, $folderId, $folderId]
    );
    return array_map(static function (array $r) use ($check): array {
        $out = [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'projectId' => (int) $r['project_id'],
            'key' => $r['url_key'], 'project' => (string) $r['project'], 'workDir' => (string) $r['work_dir'],
            'blocks' => (int) $r['blocks'], 'decisions' => (int) $r['decisions'], 'gateways' => (int) $r['gateways'],
            'arrows' => (int) $r['arrows'], 'frames' => (int) $r['frames'], 'links' => (int) $r['links'],
            'starter' => (int) $r['starters'] > 0, 'runs' => (int) $r['runs'], 'lastRun' => $r['last_run'],
            'lastRunAt' => $r['last_run_at'], 'created' => $r['created_at'], 'updated' => $r['updated_at'],
        ];
        if ($check) $out['problems'] = adminPrecheck((int) $r['id']);
        return $out;
    }, $rows);
}

/** Предпроверка прогона папки — список помех (пустой — готова). Пустая папка — одна помеха. */
function adminPrecheck(int $folderId): array
{
    $folder = dbRow('SELECT * FROM folders WHERE id = ?', [$folderId]);
    if (!$folder) return [t('admin.data.no_folder')];
    try {
        return array_column(folderPrecheck($folder)['problems'], 'say');
    } catch (Throwable $e) {
        return [$e->getMessage()];
    }
}

function adminScheme(int $id): array
{
    $one = adminSchemes(null, false, $id)[0] ?? null;
    if (!$one) throw new ApiError(t('admin.err.no_scheme'), 'not_found');
    $one['problems'] = adminPrecheck($id);
    return [
        'scheme' => $one,
        'elements' => dbAll("SELECT e.`no`, e.type, e.title, a.name AS agent,
                                    EXISTS (SELECT 1 FROM props pr WHERE pr.element_id = e.id AND pr.name = 'start' AND pr.value = '1') AS starter,
                                    EXISTS (SELECT 1 FROM asset_links l WHERE l.element_id = e.id AND l.role = 'spec') AS spec,
                                    TRIM(COALESCE(e.description, '')) <> '' AS about
                               FROM elements e LEFT JOIN agents a ON a.id = e.agent_id
                              WHERE e.folder_id = ? AND e.type IN ('block','decision','gateway') ORDER BY e.`no`", [$id]),
        'runs' => adminRuns(null, 20, $id),
    ];
}

/* ── Прогоны ──────────────────────────────────────────────────── */

/** Прогоны установки, проекта или папки; $runId — один прогон той же выборкой (карточка прогона). */
function adminRuns(?int $projectId = null, int $limit = 500, ?int $folderId = null, ?int $runId = null): array
{
    $rows = dbAll(
        "SELECT r.id, r.no, r.state, r.driver, r.judge, r.jev, r.engine, r.started_at, r.finished_at, r.wait_for,
                r.project_id, r.folder_id, p.url_key, p.title AS project, f.name AS folder, a.name AS lead,
                COUNT(s.id) AS steps, SUM(s.state = 'accepted') AS accepted,
                SUM(s.state IN ('failed','cancelled')) AS failed, SUM(s.state = 'returned') AS returned,
                MAX(s.attempt) AS rounds,
                TIMESTAMPDIFF(SECOND, r.started_at, COALESCE(r.finished_at, NOW(3))) AS seconds
           FROM runs r JOIN projects p ON p.id = r.project_id
           LEFT JOIN folders f ON f.id = r.folder_id
           LEFT JOIN agents a ON a.id = r.lead_agent_id
           LEFT JOIN run_steps s ON s.run_id = r.id
          WHERE (? IS NULL OR r.project_id = ?) AND (? IS NULL OR r.folder_id = ?) AND (? IS NULL OR r.id = ?)
          GROUP BY r.id ORDER BY r.id DESC LIMIT " . max(1, $limit),
        [$projectId, $projectId, $folderId, $folderId, $runId, $runId]
    );
    return array_map(static fn(array $r) => [
        'id' => (int) $r['id'], 'no' => 'r' . $r['no'], 'state' => $r['state'], 'driver' => $r['driver'],
        'judge' => $r['judge'], 'jev' => $r['jev'], 'engine' => (int) $r['engine'],
        'projectId' => (int) $r['project_id'], 'key' => $r['url_key'], 'project' => (string) $r['project'],
        'folderId' => $r['folder_id'] ? (int) $r['folder_id'] : null, 'folder' => (string) $r['folder'],
        'lead' => $r['lead'], 'steps' => (int) $r['steps'], 'accepted' => (int) $r['accepted'],
        'failed' => (int) $r['failed'], 'returned' => (int) $r['returned'], 'rounds' => (int) $r['rounds'],
        'seconds' => (int) $r['seconds'], 'started' => $r['started_at'], 'finished' => $r['finished_at'],
        'waitFor' => (string) $r['wait_for'],
    ], $rows);
}

function adminRun(int $id): array
{
    $run = adminRuns(null, 1, null, $id)[0] ?? null;
    if (!$run) throw new ApiError(t('admin.err.no_run'), 'not_found');
    $steps = dbAll('SELECT s.element_no, s.element_title, s.attempt, s.state, s.result, s.error, a.name AS agent,
                           s.opened_at, s.finished_at,
                           TIMESTAMPDIFF(SECOND, s.opened_at, COALESCE(s.finished_at, s.submitted_at, NOW(3))) AS seconds
                      FROM run_steps s LEFT JOIN agents a ON a.id = s.agent_id
                     WHERE s.run_id = ? ORDER BY s.id', [$id]);
    $kinds = dbAll('SELECT kind, COUNT(*) n FROM run_events WHERE run_id = ? GROUP BY kind ORDER BY n DESC', [$id]);
    $events = dbAll('SELECT at, kind, actor, element_no, attempt, title FROM run_events WHERE run_id = ? ORDER BY id DESC LIMIT 200', [$id]);
    return ['run' => $run, 'steps' => $steps, 'kinds' => $kinds, 'events' => $events];
}

/* ── Люди ─────────────────────────────────────────────────────── */

function adminPeople(): array
{
    $visitors = dbAll(
        'SELECT v.visitor, v.url_key, v.project_id, v.hits, v.first_seen, v.last_seen, v.user_agent, v.ip, u.email
           FROM visits v LEFT JOIN users u ON u.id = v.user_id ORDER BY v.last_seen DESC LIMIT 500'
    );
    return [
        'users' => adminUsers(),
        'visitors' => array_map(static fn(array $v) => [
            'visitor' => substr((string) $v['visitor'], 0, 10), 'key' => $v['url_key'], 'email' => $v['email'],
            'hits' => (int) $v['hits'], 'first' => $v['first_seen'], 'last' => $v['last_seen'],
            'browser' => adminBrowser((string) $v['user_agent']), 'ip' => (string) $v['ip'],
        ], $visitors),
    ];
}

/** Люди со счётчиками; $id — один человек той же выборкой (карточка человека). */
function adminUsers(?int $id = null): array
{
    $users = dbAll(
        "SELECT u.id, u.email, u.name, u.created_at, u.last_login_at,
                (SELECT COUNT(*) FROM projects p WHERE p.owner_id = u.id) AS projects,
                (SELECT COUNT(*) FROM tokens t WHERE t.user_id = u.id AND t.scope = 'session'
                    AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > NOW())) AS sessions,
                (SELECT COALESCE(SUM(v.hits),0) FROM visits v WHERE v.user_id = u.id) AS hits,
                (SELECT MAX(v.last_seen) FROM visits v WHERE v.user_id = u.id) AS last_seen,
                (SELECT COUNT(*) FROM journal j WHERE j.user_id = u.id) AS edits,
                (SELECT COUNT(*) FROM ai_jobs a WHERE a.user_id = u.id) AS ai
           FROM users u WHERE (? IS NULL OR u.id = ?) ORDER BY u.id",
        [$id, $id]
    );
    return array_map(static fn(array $u) => [
        'id' => (int) $u['id'], 'email' => $u['email'], 'name' => (string) $u['name'],
        'created' => $u['created_at'], 'lastLogin' => $u['last_login_at'], 'lastSeen' => $u['last_seen'],
        'projects' => (int) $u['projects'], 'sessions' => (int) $u['sessions'], 'hits' => (int) $u['hits'],
        'edits' => (int) $u['edits'], 'ai' => (int) $u['ai'],
    ], $users);
}

function adminPerson(int $id): array
{
    $user = adminUsers($id)[0] ?? null;
    if (!$user) throw new ApiError(t('admin.err.no_user'), 'not_found');
    return [
        'user' => $user,
        'projects' => dbAll('SELECT id, url_key AS `key`, title, updated_at FROM projects WHERE owner_id = ? ORDER BY updated_at DESC', [$id]),
        'sessions' => dbAll("SELECT id, tail, created_at, last_used_at, expires_at FROM tokens
                              WHERE user_id = ? AND scope = 'session' AND revoked_at IS NULL ORDER BY id DESC LIMIT 50", [$id]),
    ];
}

function adminBrowser(string $agent): string
{
    foreach (['Edg' => 'Edge', 'YaBrowser' => t('admin.data.yandex'), 'Chrome' => 'Chrome', 'Safari' => 'Safari',
              'Firefox' => 'Firefox', 'curl' => 'curl', 'node' => 'node'] as $needle => $name) {
        if (str_contains($agent, $needle)) return $name;
    }
    return $agent === '' ? '—' : mb_substr($agent, 0, 24);
}

/* ── Журнал правок ────────────────────────────────────────────── */

/**
 * Журнал постранично. Фильтры: project (id), via, op, days, q (поиск по подписи и ключу проекта).
 * Отдаёт ['rows' => …, 'total' => N, 'page' => …, 'pages' => …, 'vias' => […], 'ops' => […]].
 */
function adminJournal(array $filter, int $page = 1, int $size = 50): array
{
    $where = ['1 = 1'];
    $args = [];
    if (!empty($filter['project'])) { $where[] = 'j.project_id = ?'; $args[] = (int) $filter['project']; }
    if (!empty($filter['via']))     { $where[] = 'j.via = ?'; $args[] = (string) $filter['via']; }
    if (!empty($filter['days']))    { $where[] = 'j.created_at > NOW() - INTERVAL ? DAY'; $args[] = (int) $filter['days']; }
    if (!empty($filter['op']))      { $where[] = 'EXISTS (SELECT 1 FROM journal_ops o WHERE o.journal_id = j.id AND o.op = ?)'; $args[] = (string) $filter['op']; }
    if (!empty($filter['q'])) {
        $where[] = '(j.label LIKE ? OR p.url_key LIKE ? OR p.title LIKE ?)';
        $like = '%' . $filter['q'] . '%';
        array_push($args, $like, $like, $like);
    }
    $sql = ' FROM journal j LEFT JOIN projects p ON p.id = j.project_id LEFT JOIN users u ON u.id = j.user_id WHERE ' . implode(' AND ', $where);
    $total = (int) dbValue('SELECT COUNT(*)' . $sql, $args);
    $size = max(10, min(200, $size));
    $pages = max(1, (int) ceil($total / $size));
    $page = max(1, min($pages, $page));

    $rows = dbAll('SELECT j.id, j.created_at, j.via, j.label, j.op_scope, j.rev, j.run_id, p.url_key, p.title AS project,
                          j.project_id, u.email,
                          (SELECT GROUP_CONCAT(DISTINCT o.op ORDER BY o.op SEPARATOR \', \') FROM journal_ops o WHERE o.journal_id = j.id) AS ops,
                          (SELECT COUNT(*) FROM journal_ops o WHERE o.journal_id = j.id) AS n'
        . $sql . ' ORDER BY j.id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), $args);

    return [
        'rows' => array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'at' => $r['created_at'], 'via' => (string) $r['via'], 'label' => (string) $r['label'],
            'key' => $r['url_key'], 'project' => (string) $r['project'], 'projectId' => $r['project_id'] ? (int) $r['project_id'] : null,
            'who' => $r['email'], 'ops' => (string) $r['ops'], 'n' => (int) $r['n'], 'rev' => (int) $r['rev'],
            'run' => $r['run_id'] ? (int) $r['run_id'] : null,
        ], $rows),
        'total' => $total, 'page' => $page, 'pages' => $pages,
        'vias' => array_column(dbAll('SELECT DISTINCT via FROM journal WHERE via IS NOT NULL ORDER BY via'), 'via'),
        'ops' => array_column(dbAll('SELECT DISTINCT op FROM journal_ops ORDER BY op'), 'op'),
    ];
}

/** Одна запись журнала: операции с аргументами, «было» и «стало». */
function adminJournalEntry(int $id): array
{
    $entry = dbRow('SELECT j.*, p.url_key FROM journal j LEFT JOIN projects p ON p.id = j.project_id WHERE j.id = ?', [$id]);
    if (!$entry) throw new ApiError(t('admin.err.no_entry'), 'not_found');
    $ops = dbAll('SELECT n, op, target_no, target_ref, folder_id, args, prev, result FROM journal_ops WHERE journal_id = ? ORDER BY n', [$id]);
    foreach ($ops as &$op) {
        foreach (['args', 'prev', 'result'] as $key) $op[$key] = $op[$key] !== null ? json_decode((string) $op[$key], true) : null;
    }
    unset($op);
    return ['entry' => $entry, 'ops' => $ops];
}

/* ── Встроенный ИИ ────────────────────────────────────────────── */

/** Счёт вызовов по службам — одинаковый для дней и людей: вызовы, токены, сбои. */
const ADMIN_CALL_SUMS = "SUM(service = 'deepseek') AS ds_calls, SUM(IF(service = 'deepseek', tokens_in + tokens_out, 0)) AS ds_tokens,
                         SUM(service = 'jev') AS jev_calls, SUM(IF(service = 'jev', tokens_in + tokens_out, 0)) AS jev_tokens,
                         SUM(ok = 0) AS failed";

function adminAi(): array
{
    $days = [];
    foreach (dbAll('SELECT DATE(created_at) day, COUNT(*) calls, COALESCE(SUM(tokens_total),0) tokens,
                           SUM(state = \'failed\') failed
                      FROM ai_jobs WHERE created_at > CURDATE() - INTERVAL 30 DAY GROUP BY day') as $r) $days[$r['day']] = $r;
    $series = array_map(static fn(string $day) => ['day' => $day, 'n' => (int) ($days[$day]['calls'] ?? 0),
        'tokens' => (int) ($days[$day]['tokens'] ?? 0)], adminDays(30));
    return [
        'series' => $series,
        'model' => (string) config()['ai_model'],
        'keySet' => (string) (config()['ai_api_key'] ?? '') !== '',
        'byProject' => dbAll('SELECT p.id, p.url_key AS `key`, p.title, COUNT(j.id) AS calls, COALESCE(SUM(j.tokens_total),0) AS tokens,
                                     SUM(j.state = \'failed\') AS failed, MAX(j.created_at) AS last
                                FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id JOIN projects p ON p.id = c.project_id
                               GROUP BY p.id ORDER BY last DESC'),
        'jobs' => dbAll('SELECT j.id, j.created_at, j.kind, j.state, j.model, j.tokens_prompt, j.tokens_completion, j.tokens_total,
                                j.error, TIMESTAMPDIFF(SECOND, j.started_at, j.finished_at) AS seconds, p.url_key AS `key`, c.title
                           FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id LEFT JOIN projects p ON p.id = c.project_id
                          ORDER BY j.id DESC LIMIT 300'),
        // Журнал вызовов DeepSeek и Jev (lib/ai/calls.php): по дням, по людям и все подряд.
        'callDays' => dbAll("SELECT DATE(created_at) AS day, " . ADMIN_CALL_SUMS . "
                               FROM api_calls GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 120"),
        'callUsers' => dbAll("SELECT a.user_id AS id, u.email, u.name, " . ADMIN_CALL_SUMS . ", MAX(a.created_at) AS last
                                FROM api_calls a LEFT JOIN users u ON u.id = a.user_id
                               GROUP BY a.user_id, u.email, u.name ORDER BY last DESC"),
        'calls' => dbAll('SELECT a.id, a.created_at, a.service, a.what, a.model, a.tokens_in, a.tokens_out, a.ms, a.ok, a.error,
                                 a.user_id, u.email, u.name, p.url_key AS `key`, p.title
                            FROM api_calls a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id
                           ORDER BY a.id DESC LIMIT 1000'),
        // Правила модели по файлам: общие, режимы, рисование, метод конструктора и опыт каталога.
        'rules' => array_merge(array_map(static fn(array $one) => [
            'file' => $one[0], 'name' => $one[1],
            'chars' => mb_strlen(aiSection(langFile(config()['instructions_dir'], $one[0], 'ru'), $one[1])),
        ], [['deepseek.md', 'common'], ['deepseek.md', 'assistant'], ['deepseek.md', 'simulation'],
            ['рисование.md', 'drawing'], ['конструктор.md', 'constructor']]),
            [['file' => t('admin.data.rules_catalog'), 'name' => t('admin.data.rules_catalog_name'), 'chars' => mb_strlen(catalogDigest())]]),
    ];
}

/* ── Пропуска ─────────────────────────────────────────────────── */

function adminTokens(): array
{
    return array_map(static fn(array $t) => [
        'id' => (int) $t['id'], 'scope' => $t['scope'], 'tail' => (string) $t['tail'], 'label' => (string) $t['label'],
        'key' => $t['url_key'], 'email' => $t['email'], 'run' => $t['run_id'] ? (int) $t['run_id'] : null,
        'created' => $t['created_at'], 'used' => $t['last_used_at'], 'expires' => $t['expires_at'], 'revoked' => $t['revoked_at'],
        // Код состояния: подпись на языке человека ставит экран (admin/core.js, tokenState и badge).
        'state' => $t['revoked_at'] ? 'revoked' : ((int) $t['expired'] ? 'expired' : 'live'),
    ], dbAll('SELECT t.*, p.url_key, u.email, (t.expires_at IS NOT NULL AND t.expires_at <= NOW(3)) AS expired
                FROM tokens t LEFT JOIN projects p ON p.id = t.project_id
                LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 2000'));
}

/* ── Каталог схем ─────────────────────────────────────────────── */

/** Разделы с паспортами и все заготовки: в каталоге и нет (lib/templates/catalog.php). */
function adminCatalog(): array
{
    $rows = array_map(static function (array $t): array {
        $body = json_decode((string) $t['body'], true) ?: ['elements' => []];
        return [
            'id' => (int) $t['id'], 'key' => $t['key'], 'title' => (string) $t['title'], 'about' => (string) $t['about'],
            'category' => (string) $t['category'], 'sort' => (int) $t['sort'], 'inCatalog' => (int) $t['in_catalog'] === 1,
            'checked' => $t['checked_at'], 'builtin' => (int) $t['builtin'] === 1, 'owner' => $t['email'],
            'steps' => count(array_filter($body['elements'] ?? [], static fn(array $e) => ($e['type'] ?? '') === 'block' && !propOn($e['props']['start'] ?? false))),
            'passport' => catalogPassport((string) $t['passport'], PASSPORT_TEMPLATE),
            'en' => catalogOver($t, 'en', PASSPORT_TEMPLATE),
            'size' => strlen((string) $t['body']), 'updated' => $t['updated_at'],
        ];
    }, dbAll('SELECT t.*, u.email FROM templates t LEFT JOIN users u ON u.id = t.owner_id ORDER BY t.in_catalog DESC, t.category, t.sort, t.title'));
    $count = array_count_values(array_column(array_filter($rows, static fn(array $t) => $t['inCatalog']), 'category'));
    $categories = array_map(static fn(array $c) => [
        'key' => $c['key'], 'title' => $c['title'], 'about' => $c['about'], 'sort' => (int) $c['sort'],
        'count' => (int) ($count[$c['key']] ?? 0), 'passport' => catalogPassport((string) $c['passport'], PASSPORT_CATEGORY),
        'en' => catalogOver($c, 'en', PASSPORT_CATEGORY),
    ], dbAll('SELECT * FROM template_categories ORDER BY sort, title'));
    return ['rows' => $rows, 'categories' => $categories];
}

/* ── Система ──────────────────────────────────────────────────── */

function adminSystem(): array
{
    $c = config();
    $root = dirname(__DIR__, 2);
    return [
        'versions' => [
            'PHP' => PHP_VERSION,
            ...adminDbVersion(),
            t('admin.system.server') => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            t('admin.system.ai_model') => (string) $c['ai_model'],
            t('admin.system.jev_model') => (string) ($c['jev_model'] ?? ''),
        ],
        'keys' => [
            (string) ($c['ai_connections'][$c['ai_connection']]['name'] ?? 'AI') => (string) ($c['ai_api_key'] ?? '') !== '',
            'Jev (TypeSafe)' => (string) ($c['jev_api_key'] ?? '') !== '',
            t('admin.system.admin_password') => (string) ($c['admin_pass_hash'] ?? '') !== '',
        ],
        'paths' => [
            t('admin.system.workfiles_new') => (string) ($c['workfiles_dir'] ?? ''),
            'workspace' => (string) $c['workspace_dir'],
            t('admin.system.roots') => implode("\n", (array) ($c['file_roots'] ?? [])),
            t('admin.system.instructions') => (string) $c['instructions_dir'],
        ],
        'disk' => array_map(static fn(string $name, string $path) => ['name' => $name, 'path' => $path, 'mb' => adminDirMb($path)],
            [t('admin.system.disk_workfiles'), 'workspace', t('admin.system.disk_files'), t('admin.system.disk_backup')],
            [$root . '/public/workfiles', $root . '/workspace', $root . '/data/files', $root . '/backup']),
        'migrations' => schemaMigrations(),
        'tables' => adminTables(),
        'errors' => adminErrorLines(200, 30),
        'orphans' => adminOrphanDirs(),
    ];
}

/** База и её версия одной парой: SQLite, MariaDB или MySQL. */
function adminDbVersion(): array
{
    if (dbDriver() === 'sqlite') return ['SQLite' => (string) dbValue('SELECT sqlite_version()')];
    $version = (string) dbValue('SELECT VERSION()');
    return [(str_contains($version, 'MariaDB') ? 'MariaDB' : 'MySQL') => $version];
}

/**
 * Таблицы базы: имя, строк, МБ — крупные сверху. MySQL знает размеры сам (information_schema, строки — примерно);
 * у SQLite размер — у файла целиком: он записан первой строкой «(файл базы)», строки таблиц считаются точно.
 */
function adminTables(): array
{
    if (dbDriver() !== 'sqlite') {
        return dbAll('SELECT table_name AS name, table_rows AS `rows`, ROUND((data_length + index_length) / 1048576, 1) AS mb
                        FROM information_schema.tables WHERE table_schema = ? ORDER BY (data_length + index_length) DESC',
            [config()['name']]);
    }
    $file = dbSqliteFile();
    $bytes = (int) @filesize($file) + (int) @filesize($file . '-wal');
    $out = [['name' => '(' . basename($file) . ')', 'rows' => null, 'mb' => round($bytes / 1048576, 1)]];
    foreach (dbAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
        $out[] = ['name' => $row['name'], 'rows' => (int) dbValue('SELECT COUNT(*) FROM "' . $row['name'] . '"'), 'mb' => 0];
    }
    return $out;
}

/** Рабочие папки в workfiles_dir, к которым не привязана ни одна схема: схему удалили, а файлы остались. */
function adminOrphanDirs(): array
{
    $base = realpath((string) (config()['workfiles_dir'] ?? ''));
    if (!$base) return [];
    $used = [];
    foreach (dbAll('SELECT work_dir FROM folders WHERE work_dir IS NOT NULL') as $row) {
        $real = $row['work_dir'] !== '' ? realpath((string) $row['work_dir']) : false;
        if ($real) $used[$real] = true;
    }
    $out = [];
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (isset($used[realpath($dir)])) continue;
        $files = 0;
        $bytes = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getFilename() === '.DS_Store') continue;
            $files++;
            $bytes += $file->getSize();
        }
        $out[] = ['name' => basename($dir), 'files' => $files, 'mb' => round($bytes / 1048576, 2),
            'changed' => (string) dbValue('SELECT FROM_UNIXTIME(?)', [(int) filemtime($dir)])];   // время — в поясе базы, как у остальных дат
    }
    return $out;
}

/** Размер папки в МБ — обходом на PHP: на хостинге запуск программ (du) бывает запрещён. */
function adminDirMb(string $path): ?float
{
    if (!is_dir($path)) return null;
    $bytes = 0;
    try {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) if ($file->isFile() && !$file->isLink()) $bytes += $file->getSize();
    } catch (Throwable) {
        return null;                                  // папка без прав на чтение — размер неизвестен
    }
    return round($bytes / 1048576, 1);
}

/**
 * Строки журнала ошибок Apache с пометкой [goblin] — свежие сверху.
 * $days — не старше стольких суток (по дате в строке, если она есть).
 */
function adminErrorLines(int $limit, int $days): array
{
    // Журнал PHP (error_log из php.ini: хостинг, Homebrew), иначе — журнал Apache в XAMPP.
    $file = (string) ini_get('error_log');
    if ($file === '' || !is_readable($file)) $file = '/Applications/XAMPP/xamppfiles/logs/error_log';
    if (!is_readable($file)) return [];
    $size = filesize($file);
    $fh = fopen($file, 'rb');
    fseek($fh, max(0, $size - 2 * 1048576));          // хвост в 2 МБ — хватает с запасом
    $tail = stream_get_contents($fh);
    fclose($fh);
    $since = time() - $days * 86400;
    $out = [];
    foreach (array_reverse(explode("\n", (string) $tail)) as $line) {
        if (!str_contains($line, '[goblin]')) continue;
        if (preg_match('/^\[([^\]]+)\]/', $line, $m) && ($t = strtotime(preg_replace('/\.\d+/', '', $m[1]))) && $t < $since) continue;
        $out[] = mb_substr($line, 0, 600);
        if (count($out) >= $limit) break;
    }
    return $out;
}
