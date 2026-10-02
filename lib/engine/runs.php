<?php
/* Прогон: одно прохождение схемы. Заводит его человек, ведёт — агент-leader.
   Отдаёт: RUN_MOVES, runMove(), runAlready(), runRow(), runShape(), runActive(), runLiveGuard(),
           runForFolder(), runStart(), runStartEntry(), runGet(), runPause(), runResume(), runStop(), runStopDo(),
           runFinish(), runFinishEvent(), runAttach(), runAsked(), runOwn(), runSetState(), runBaseUrl(),
           runStartEvent(), runArrival(), runArrivalTake(), runsForget(), runMarkEvent().
   Не делает: не открывает шаги (engine/steps.php), не считает жетоны (engine/marks.php),
              не проверяет схему (engine/precheck.php) и не готовит папку (engine/prepare.php).

   Прогон отделён от схемы: статусы, результаты и время живут в шагах, а не в блоках. */

declare(strict_types=1);

/**
 * Переходы прогона: команда → [из какого состояния => в какое]. Одна таблица на все команды.
 * Живые состояния — running и paused. Закрытые (done, stopped, failed) не оживают:
 * начать заново — новый прогон. finish со словом failed ведёт в failed.
 */
const RUN_MOVES = [
    'pause'  => ['running' => 'paused'],
    'resume' => ['paused' => 'running'],
    'reset'  => ['running' => 'running', 'paused' => 'running'],
    'stop'   => ['running' => 'stopped', 'paused' => 'stopped'],
    'finish' => ['running' => 'done', 'paused' => 'done'],
];

/** Команда не из своего состояния: ключ текста отказа, а `already` — не отказ, прогон уже закрыт. */
const RUN_MOVE_ELSE = [
    'pause'  => 'agents.runs.not_going',
    'resume' => 'agents.runs.not_paused',
    'reset'  => 'agents.runs.reset_closed',
    'stop'   => 'already',
    'finish' => 'already',
];

/**
 * Куда команда ведёт прогон из его состояния (RUN_MOVES). null — прогон уже закрыт,
 * а для stop и finish это не ошибка: ничего не меняем, ответ runAlready(). Иначе — отказ 409.
 */
function runMove(array $run, string $command): ?string
{
    $to = RUN_MOVES[$command][(string) $run['state']] ?? null;
    if ($to !== null) return $to;
    $else = RUN_MOVE_ELSE[$command];
    if ($else === 'already') return null;
    throw new ApiError(ta($else, ['no' => (int) $run['no'], 'state' => (string) $run['state']]), 'conflict');
}

/** Ответ команды над закрытым прогоном: ничего не тронуто, `already` — его настоящий конец. */
function runAlready(array $run): array
{
    return ['run' => runShape($run), 'already' => (string) $run['state']];
}

function runRow(int $id, int $projectId): array
{
    $row = dbRow('SELECT * FROM runs WHERE id = ?', [$id]);
    return sameProject($row, $projectId, 'run');
}

function runShape(array $row): array
{
    return [
        'id'        => (int) $row['id'],
        'no'        => (int) $row['no'],
        'label'     => 'r' . (int) $row['no'],
        'folder'    => (int) $row['folder_id'],
        'title'     => (string) $row['title'],
        'state'     => (string) $row['state'],
        'lead'      => $row['lead_agent_id'] ? (int) $row['lead_agent_id'] : null,
        'workDir'   => (string) $row['work_dir'],
        'agentDir'  => (string) ($row['agent_dir'] ?? ''),   // папка прогона у агента (веб), пусто — агент рядом
        'summary'   => (string) $row['summary'],
        'stopAsked' => $row['stop_at'] !== null,
        'seenAt'    => $row['seen_at'],
        'startedAt' => $row['started_at'],
        'finished'  => $row['finished_at'],
        'rev'       => (int) $row['rev'],
        'engine'    => (int) ($row['engine'] ?? 1),       // 1 — старый учёт, 2 — жетоны строками
        'version'   => (int) ($row['version'] ?? 0),      // растёт при каждом изменении хода
        'driver'    => (string) ($row['driver'] ?? 'manual'),   // кто просит ход: руками или утилита
        'judge'     => (string) ($row['judge'] ?? 'human'),     // кто принимает: человек или проверки
        'jev'       => (string) ($row['jev'] ?? 'off'),
        'showPause' => (float) ($row['show_pause'] ?? 0),
        'waitFor'   => (string) ($row['wait_for'] ?? ''),
    ];
}

/** Живой (running, paused) прогон папки или null. $exceptRunId — этот прогон не считать. */
function runActive(int $folderId, int $exceptRunId = 0): ?array
{
    return dbRow(
        "SELECT * FROM runs WHERE folder_id = ? AND id <> ? AND state IN ('running','paused') ORDER BY id DESC LIMIT 1",
        [$folderId, $exceptRunId]
    );
}

/**
 * Инвариант папки: живой прогон в ней — один. Зовут все, кто делает прогон живым:
 * run.start, run.reset, run.resume и begin простого пути. Только под замком проекта,
 * иначе два старта пройдут оба. $exceptRunId — сам оживляемый прогон.
 * $key — текст отказа для ta(), {no} — номер живого прогона; по умолчанию — как у run.start.
 */
function runLiveGuard(int $folderId, int $exceptRunId = 0, string $key = 'agents.runs.already_going'): void
{
    $live = runActive($folderId, $exceptRunId);
    if ($live) throw new ApiError(ta($key, ['no' => (int) $live['no']]), 'conflict');
}

/** Прогон, который показывает холст: последний, где есть шаги этой папки. */
function runForFolder(int $folderId): ?array
{
    return dbRow(
        'SELECT r.* FROM runs r
          WHERE r.folder_id = ?
             OR EXISTS (SELECT 1 FROM run_steps s JOIN elements e ON e.id = s.element_id
                         WHERE s.run_id = r.id AND e.folder_id = ?)
          ORDER BY r.id DESC LIMIT 1',
        [$folderId, $folderId]
    );
}

/** POST run.start — человек заводит прогон и получает пропуск для leader. */
function runStart(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $runId = 0;
    $repeated = false;

    $out = engineCommand(static function () use ($project, $projectId, &$runId): array {
        $folderId = inputInt('folder');
        $folder = folderRow((int) $folderId, $projectId);

        runLiveGuard((int) $folder['id']);
        // Та же предпроверка, что у begin, и вход схемы — под замком, до записи прогона и пропуска.
        $check = folderPrecheck($folder);
        if (!$check['ready']) {
            throw new ApiError(ta('agents.runs.not_ready', ['list' => implode('; ', array_map('precheckSay', $check['problems']))]),
                'conflict', ['problems' => $check['problems']]);
        }
        $start = runStartEntry($check['entries']);

        $leadId = null;
        if ($lead = inputInt('lead')) {
            $agent = agentRow($lead, $projectId);
            if ($agent['role'] !== 'lead') throw new ApiError(ta('agents.runs.lead_only_role'));
            $leadId = (int) $agent['id'];
        }

        // Как ведём этот прогон: кто просит ход, кто принимает, спрашивать ли Jev,
        // и пауза показа в секундах. Не сказали — руками, принимает человек, без Jev.
        $driver = runChoice('driver', ['manual', 'utility'], 'manual');
        $judge  = runChoice('judge', ['human', 'formal'], 'human');
        $jev    = runChoice('jev', ['off', 'advisor', 'judge'], 'off');
        $pause  = max(0, min(999.9, round((float) (input('pause') ?? 0), 1)));

        $no = nextRunNo($projectId);
        $rev = bumpRev($projectId);
        // engine = 2: жетоны строками в run_marks. Старые прогоны остаются на engine = 1.
        dbRun(
            'INSERT INTO runs (project_id, folder_id, `no`, title, lead_agent_id, started_by, work_dir, work_url, rev,
                               engine, driver, judge, jev, show_pause)
             VALUES (?,?,?,?,?,?,?,?,?,2,?,?,?,?)',
            [$projectId, $folder['id'], $no, (string) (input('title') ?? $folder['name']),
             $leadId, caller()['user_id'], $folder['work_dir'], $folder['work_url'], $rev,
             $driver, $judge, $jev, $pause]
        );
        $runId = dbId();
        runTouch($runId);
        runOutDir(['work_dir' => $folder['work_dir'], 'no' => $no]);   // папка результатов out/rN

        $token = tokenIssue('run', [
            'project_id' => $projectId, 'run_id' => $runId, 'agent_id' => $leadId,
            'created_by' => caller()['user_id'],
        ], 'прогон r' . $no);

        $run = runRow($runId, $projectId);
        runStartEvent($run, $folder, $start, 'human');

        return [
            'run'   => runShape($run),
            'start' => ['id' => (int) $start['id'], 'no' => (int) $start['no'], 'title' => $start['title']],
            'token' => $token['secret'],
            'howto' => sprintf(
                'GOBLIN_URL=%s GOBLIN_PROJECT=%s GOBLIN_RUN=%s goblin next',
                runBaseUrl(), $project['url_key'], $token['secret']
            ),
        ];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/**
 * С какого узла начать: только со входа схемы (graphEntries) — стартера или единственного
 * узла без входа. Несколько входов первым не выбираются; `from` не вход — отказ 422
 * с номером входа. $entries — входы предпроверки. Отдаёт строку элемента.
 */
function runStartEntry(array $entries): array
{
    $nos = array_map(static fn(array $one): int => (int) $one['no'], $entries);
    if (count($entries) !== 1) {
        throw new ApiError(ta('agents.runs.entries_many', ['list' => implode(', ', $nos)]), 'invalid', ['entries' => $nos]);
    }
    $from = inputInt('from');
    if ($from && $from !== $nos[0]) {
        throw new ApiError(ta('agents.runs.from_not_entry', ['from' => $from, 'no' => $nos[0]]), 'invalid', ['entries' => $nos]);
    }
    return dbRow('SELECT * FROM elements WHERE id = ?', [(int) $entries[0]['id']]);
}

/** Настройка прогона из запроса: только из списка, иначе — значение по умолчанию. */
function runChoice(string $name, array $allowed, string $fallback): string
{
    $value = trim((string) (input($name) ?? ''));
    if ($value === '') return $fallback;
    if (!in_array($value, $allowed, true)) {
        throw new ApiError(ta('agents.runs.setting', ['name' => $name]) . '' . implode(', ', $allowed));
    }
    return $value;
}

/**
 * Служебная папка прогона: пакеты заданий и журнал круга.
 *
 * Лежит В рабочей папке — `<work_dir>/service`: worker запускается с рабочей папкой и
 * дальше неё читать обычно не может, а пакет ему нужен. От браузера папка закрыта
 * своим `.htaccess` (его кладёт тот, кто пишет — `serviceDirMake()`), потому что рабочая
 * папка бывает и под `public/`. Имя пакета несёт id шага, поэтому два прогона одной
 * схемы не затирают файлы друг друга.
 *
 * Только путь: папку заводит тот, кто в неё пишет.
 */
function runServiceDir(int $runId): string
{
    $dir = (string) dbValue('SELECT work_dir FROM runs WHERE id = ?', [$runId]);
    return ($dir !== '' ? rtrim($dir, '/') : dirname(__DIR__, 2) . '/storage/runs/' . $runId) . '/service';
}

/**
 * Папка результатов прогона: <рабочая папка>/out/r<N>. Прогоны копятся в out/ рядом
 * друг с другом — ничего не стирается и не уносится в архив. Заводит её сервер
 * (begin, выдача задания); worker только кладёт туда файлы. Нет рабочей папки — ''.
 */
function runOutDir(array $run, bool $make = true): string
{
    $work = rtrim((string) ($run['work_dir'] ?? ''), '/');
    if ($work === '') return '';
    $dir = $work . '/out/r' . (int) $run['no'];
    if ($make && !is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** Завести служебную папку и закрыть её от браузера: внутри лежат пропуска шагов. */
function serviceDirMake(string $dir): string
{
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $deny = dirname(rtrim($dir, '/')) . '/service/.htaccess';
    if (is_dir(dirname($deny)) && !is_file($deny)) {
        @file_put_contents($deny,
            "# Служебное прогона (пакеты, журналы) — только для сервера и CLI, не для браузера.\n"
            . "Require all denied\n");
    }
    return $dir;
}

function runBaseUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    /* Полный адрес api.php: команду из howto человек копирует как есть.
       Берём адрес, по которому пришли, а не путь файла: снаружи это /goblin/api.php,
       а SCRIPT_NAME после переписывания в .htaccess — /goblin/public/api.php. */
    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php');
    $asked = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    if (str_ends_with($asked, '/api.php')) $path = $asked;
    return $scheme . '://' . $host . $path;
}

/**
 * Агенты на других компьютерах — Гоблин в вебе: диск сервера им не виден, задания и материалы — ссылками,
 * рабочая папка — у агента. Настройка agents_where: auto — по адресу запроса (localhost — агент рядом),
 * local / remote — вручную (решение хозяина 30.09.2026).
 */
function agentsRemote(): bool
{
    $where = (string) (config()['agents_where'] ?? 'auto');
    if ($where !== 'auto') return $where === 'remote';
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

/**
 * Путь схемы — та же структура, что в Гоблине: «пользователь-id/проект-id/папка/…/схема-id».
 * Пользователь — владелец проекта, у проекта без учётки — «гости». id — чтобы одинаковые имена не смешались.
 * Один на рабочую папку на сервере (workDirFor) и у агента в вебе (agentRunDir) — решение хозяина 30.09.2026.
 */
function folderTreePath(array $folder): string
{
    $word = static fn(string $name): string => mb_substr(trim((string) preg_replace('/[^\p{L}\p{N}_]+/u', '-', $name), '-'), 0, 60);
    $named = static fn(string $name, int $id): string => ($word($name) !== '' ? $word($name) . '-' : '') . $id;

    $parts = [$named((string) $folder['name'], (int) $folder['id'])];
    // Папки выше схемы — так же «имя-id», до корня проекта: у папки-родителя её рабочая папка и путь
    // её подсхем совпадают. Предел — от кольца в данных.
    for ($up = $folder['parent_id'] ?? null, $depth = 0; $up && $depth < 20; $depth++) {
        $row = dbRow('SELECT id, parent_id, name FROM folders WHERE id = ?', [(int) $up]);
        if (!$row) break;
        array_unshift($parts, $named((string) $row['name'], (int) $row['id']));
        $up = $row['parent_id'];
    }
    $project = dbRow('SELECT p.id, p.title, p.owner_id, u.name, u.email FROM projects p LEFT JOIN users u ON u.id = p.owner_id
                       WHERE p.id = ?', [(int) $folder['project_id']]);
    array_unshift($parts, $named((string) ($project['title'] ?? ''), (int) ($project['id'] ?? 0)));
    array_unshift($parts, !empty($project['owner_id'])
        ? $named((string) ($project['name'] ?: strtok((string) $project['email'], '@')), (int) $project['owner_id'])
        : 'гости');
    return implode('/', $parts);
}

/** Путь схемы у агента в вебе — тот же, что на сервере. */
function agentFolderPath(array $folder): string
{
    return folderTreePath($folder);
}

/**
 * Рабочая папка прогона у агента: $here — текущая папка ведущего (он шлёт её при begin), в ней — путь схемы.
 * Ведущий папку не назвал — относительный путь от того места, где он запущен.
 */
function agentRunDir(array $folder, string $here): string
{
    $here = rtrim(trim($here), '/');
    // Только путь одной строкой: он уходит в задания worker как есть.
    if ($here === '' || mb_strlen($here) > 900 || preg_match('/[\x00-\x1f]/', $here)) $here = '.';
    return $here . '/' . agentFolderPath($folder);
}

/** Рабочая папка в текстах агенту: у агента (веб) или на диске сервера. */
function runWorkShown(array $run): string
{
    $agent = (string) ($run['agent_dir'] ?? '');
    return $agent !== '' ? $agent : (string) ($run['work_dir'] ?? '');
}

/** Папка результатов в текстах агенту: у агента — <его папка>/out/rN (её заводит он сам), иначе runOutDir(). */
function runOutShown(array $run): string
{
    $agent = (string) ($run['agent_dir'] ?? '');
    return $agent !== '' ? $agent . '/out/r' . (int) $run['no'] : runOutDir($run);
}

/** GET run.get — ход прогона, а без номера — список прогонов папки. */
function runGet(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $who = caller();

    $runId = $who['run_id'] ?: runAsked($projectId);
    if (!$runId) {
        $folderId = inputInt('folder');

        // Ни прогона, ни папки — отдаём живые прогоны проекта. Этим живёт
        // сторож: один процесс смотрит за всем проектом, а не за одним прогоном.
        if (!$folderId) {
            $rows = dbAll(
                "SELECT * FROM runs WHERE project_id = ? AND state IN ('running','paused') ORDER BY id",
                [$projectId]
            );
            reply(['runs' => array_map('runShape', $rows), 'now' => dbNow()]);
        }
        $rows = dbAll('SELECT * FROM runs WHERE project_id = ? AND folder_id = ? ORDER BY id DESC LIMIT 50', [$projectId, $folderId]);
        // Сколько шагов в каждом: пустой прогон не должен затирать картину
        // на холсте — экран сам выберет тот, где что-то происходило.
        // Один запрос на все прогоны, а не два на каждый.
        $counts = [];
        if ($rows) {
            $ids = array_map(static fn(array $r) => (int) $r['id'], $rows);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            foreach (dbAll(
                "SELECT run_id, COUNT(*) AS steps, SUM(state = 'accepted') AS done
                   FROM run_steps WHERE run_id IN ($in) GROUP BY run_id", $ids) as $c) {
                $counts[(int) $c['run_id']] = $c;
            }
        }
        $out = array_map(static function (array $row) use ($counts) {
            $shape = runShape($row);
            $shape['steps'] = (int) ($counts[(int) $row['id']]['steps'] ?? 0);
            $shape['done']  = (int) ($counts[(int) $row['id']]['done'] ?? 0);
            return $shape;
        }, $rows);
        reply(['runs' => $out]);
    }

    $run = runRow((int) $runId, $projectId);
    if ($who['role'] === 'lead') dbRun('UPDATE runs SET seen_at = NOW(3) WHERE id = ?', [$run['id']]);

    $steps = dbAll('SELECT * FROM run_steps WHERE run_id = ? ORDER BY id', [$run['id']]);

    // Какие агенты берут работу сами: у них есть действующий долгий пропуск.
    // leader это говорит, что пропуск шага никому передавать не надо.
    $selfServe = dbAll(
        "SELECT agent_id FROM tokens
          WHERE project_id = ? AND scope = 'agent' AND agent_id IS NOT NULL AND revoked_at IS NULL",
        [$projectId]
    );
    $selfServe = array_column($selfServe, 'agent_id');

    $shaped = array_map(static function (array $row) use ($selfServe) {
        $step = stepShape($row);
        $step['selfServe'] = $row['agent_id'] && in_array((int) $row['agent_id'], array_map('intval', $selfServe), true);
        return $step;
    }, $steps);

    reply([
        'run'   => runShape($run),
        'steps' => $shaped,
        'ready' => runReady($run, $steps),
        'stop'  => $run['stop_at'] !== null,
        'now'   => dbNow(),
    ]);
}

/** Событие уровня прогона (пауза, продолжение) — отметка на ленте времени (run.timeline). */
function runMarkEvent(array $run, string $kind, string $title): void
{
    eventAdd(['project_id' => (int) $run['project_id'], 'run_id' => (int) $run['id'], 'kind' => $kind,
              'actor' => eventActor(caller()), 'title' => $title]);
}

/** POST run.pause / run.resume */
function runPause(): void
{
    reply(engineCommand(static function (): array {
        [$project, $run] = runOwn(true);
        runSetState((int) $run['id'], (string) runMove($run, 'pause'), (int) $project['id']);
        runTouch((int) $run['id']);
        runMarkEvent($run, 'pause', ta('agents.runs.ev_paused'));
        return ['run' => runShape(runRow((int) $run['id'], (int) $project['id']))];
    }));
}

function runResume(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $runId = (int) $run['id'];
        $to = (string) runMove($run, 'resume');
        runLiveGuard((int) $run['folder_id'], $runId);
        runSetState((int) $run['id'], $to, (int) $project['id']);
        runTouch((int) $run['id']);
        runMarkEvent($run, 'resume', ta('agents.runs.ev_resumed'));
        return ['run' => runShape(runRow((int) $run['id'], (int) $project['id']))];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/**
 * POST run.stop — человек просит остановиться; с now=1 сервер закрывает всё сам.
 * Закрытый прогон остаётся как есть: ответ `already` (повтор, потерянный ответ).
 */
function runStop(): void
{
    reply(engineCommand(static function (): array {
        [$project, $run] = runOwn(false);
        $projectId = (int) $project['id'];
        if (runMove($run, 'stop') === null) return runAlready($run);
        dbRun('UPDATE runs SET stop_at = NOW(3), rev = ? WHERE id = ?', [bumpRev($projectId), $run['id']]);

        if (!empty(input('now'))) {
            runStopDo($run, eventActor(caller()), ta('agents.advance.ran_out'));
        } else {
            eventAdd(['project_id' => $projectId, 'run_id' => (int) $run['id'], 'kind' => 'stop',
                      'actor' => eventActor(caller()), 'title' => ta('agents.runs.ev_stop_asked'),
                      'meta' => ['asked' => true]]);
            runTouch((int) $run['id']);
        }
        return ['run' => runShape(runRow((int) $run['id'], $projectId))];
    }));
}

/**
 * Остановить прогон: открытые шаги отменены (событие у каждого), прогон закрыт.
 * В ленте — кто остановил и закрытие `finish`, если его там ещё нет. Только под замком.
 * $why — причина для отменённых шагов, $summary — итог прогона.
 */
function runStopDo(array $run, string $actor, string $why, string $summary = ''): void
{
    $runId = (int) $run['id'];
    $projectId = (int) $run['project_id'];
    $cancelled = stepsCancelAll($runId, $projectId, $why, $actor);
    runSetState($runId, 'stopped', $projectId, $summary);
    runTouch($runId);
    tokenRevokeFor('run_id', $runId);   // остановленный прогон не продолжить: его пропуска гаснут (Б3)

    eventAdd(['project_id' => $projectId, 'run_id' => $runId, 'kind' => 'stop', 'actor' => $actor,
              'title' => $why . ($cancelled ? ta('agents.runs.ev_cancelled_steps', ['n' => $cancelled]) : ''),
              'meta' => ['asked' => false]]);
    if (!dbValue("SELECT 1 FROM run_events WHERE run_id = ? AND kind = 'finish' LIMIT 1", [$runId])) {
        runFinishEvent($run, $actor, 'stopped');
    }
}

/**
 * Событие `finish`: чем кончился прогон ('done', 'failed', 'stopped') и сколько шагов принято.
 * $tail — что дописать к заголовку (итог, причина).
 */
function runFinishEvent(array $run, string $actor, string $end, string $tail = ''): void
{
    $taken = (int) dbValue("SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND state = 'accepted'", [(int) $run['id']]);
    $word = ['done' => ta('agents.runs.end_done'), 'failed' => ta('agents.runs.end_failed'), 'stopped' => ta('agents.runs.end_stopped')][$end];
    eventAdd([
        'project_id' => (int) $run['project_id'], 'run_id' => (int) $run['id'], 'kind' => 'finish', 'actor' => $actor,
        'title' => ta('agents.runs.ev_closed', ['word' => $word, 'n' => $taken]) . ($tail !== '' ? " · $tail" : ''),
        'meta' => ['accepted' => $taken, 'failed' => $end === 'failed', 'end' => $end],
    ]);
}

/**
 * POST run.finish — закрыть прогон: дошёл до конца или сорвался.
 * Закрытый прогон остаётся как есть: ответ `already` с его настоящим концом.
 */
function runFinish(): void
{
    reply(engineCommand(static function (): array {
        [$project, $run] = runOwn(true);
        $projectId = (int) $project['id'];
        if (runMove($run, 'finish') === null) return runAlready($run);

        $open = (int) dbValue("SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND state IN ('issued','running','submitted')", [$run['id']]);
        if ($open > 0) throw new ApiError(ta('agents.runs.open_steps', ['n' => $open]), 'conflict');

        $failed = !empty(input('failed'));
        if ($failed && trim((string) (input('why') ?? '')) === '') {
            throw new ApiError(ta('agents.runs.need_why'));
        }

        /* «Дошёл до конца» — только когда прогон действительно пройден: нет лежащих
           жетонов, принят конечный блок, последняя попытка каждого блока принята.
           Иначе закрывать надо как сорвавшийся, с причиной. */
        if (!$failed && (int) $run['engine'] === 2) {
            $ctx = advanceLoad($run);
            $fin = finishCheck($run, $ctx['graph'], $ctx['steps'], $ctx['free'],
                readyList($run, $ctx['graph'], $ctx['steps']));
            if (!$fin['done']) {
                throw new ApiError(ta('agents.runs.not_passed', ['why' => $fin['why'] ?: ta('agents.runs.more_to_do')]), 'conflict', ['code2' => 'not_done']);
            }
        }
        $summary = (string) (input('why') ?? input('summary') ?? '');
        runSetState((int) $run['id'], $failed ? 'failed' : 'done', $projectId, $summary);
        runTouch((int) $run['id']);
        tokenRevokeFor('run_id', (int) $run['id']);
        runFinishEvent($run, leadActor(), $failed ? 'failed' : 'done', $summary);
        return ['run' => runShape(runRow((int) $run['id'], $projectId))];
    }));
}

/** POST run.attach — новая сессия leader; прежний пропуск гаснет. Состояние прогона не меняется. */
function runAttach(): void
{
    reply(engineCommand(static function (): array {
        $project = requireProject(true);
        $projectId = (int) $project['id'];
        // Ярлык с холста годится так же, как номер: `attach r40`.
        $run = runRow(runAsked($projectId), $projectId);
        if (!in_array($run['state'], ['running', 'paused'], true)) {
            throw new ApiError(ta('agents.runs.already_closed'), 'conflict');
        }
        tokenRevokeFor('run_id', (int) $run['id']);

        $token = tokenIssue('run', [
            'project_id' => $projectId, 'run_id' => (int) $run['id'],
            'agent_id' => $run['lead_agent_id'] ? (int) $run['lead_agent_id'] : null,
            'created_by' => caller()['user_id'],
        ], 'прогон r' . $run['no']);

        $steps = dbAll("SELECT * FROM run_steps WHERE run_id = ? AND state IN ('issued','running','submitted') ORDER BY id", [$run['id']]);
        return ['run' => runShape($run), 'token' => $token['secret'], 'open' => array_map('stepShape', $steps)];
    }));
}

/**
 * Какой прогон назвали в запросе.
 *
 * Число — это id из базы, `r28` — ярлык, который человек видит на холсте.
 * Ярлык удобнее: в панели и в журнале прогон зовут именно так.
 * Не назвали — 0. Назвали, но такого нет, — «не найдено», а не 0:
 * иначе run.get молча отдавал список прогонов вместо ошибки.
 */
function runAsked(int $projectId): int
{
    $asked = trim((string) (input('run') ?? ''));
    if ($asked === '') return 0;

    if (preg_match('/^r(\d+)$/ui', $asked, $found)) {
        $id = (int) dbValue('SELECT id FROM runs WHERE project_id = ? AND `no` = ?',
            [$projectId, (int) $found[1]]);
        if (!$id) throw new ApiError(ta('agents.runs.no_such_run', ['run' => $asked]), 'not_found');
        return $id;
    }
    if (!ctype_digit($asked) || (int) $asked === 0) {
        throw new ApiError(ta('agents.runs.no_such_run', ['run' => $asked]), 'not_found');
    }
    return (int) $asked;
}

/** Прогон, которым распоряжается вызывающий. leader — своим пропуском. */
function runOwn(bool $leadOnly): array
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $who = caller();

    $runId = $who['run_id'] ?: runAsked($projectId);
    if (!$runId) throw new ApiError(ta('agents.events.no_run'), 'not_found');
    // leader ведёт прогон пропуском, человек — руками из панели. Больше никто.
    if ($leadOnly && !in_array($who['role'], ['lead', 'human', 'guest', 'admin'], true)) {
        throw new ApiError(ta('agents.runs.leader_only'), 'scope');
    }
    if ($who['run_id'] && (int) $who['run_id'] !== (int) $runId) {
        throw new ApiError(ta('agents.runs.token_other_run'), 'scope');
    }
    return [$project, runRow((int) $runId, $projectId)];
}

function runSetState(int $runId, string $state, int $projectId, string $summary = ''): void
{
    $summary = mb_substr($summary, 0, 500);      // runs.summary — varchar(500), строгий SQL
    $rev = bumpRev($projectId);
    $done = in_array($state, ['done', 'failed', 'stopped'], true);
    /* Закрытый прогон ничего не ждёт: строку «чего ждёт» и время следующего
       хода гасим здесь, иначе остановленный прогон продолжал звать к приёмке
       шага, который сам же и отменил. Итог закрытия хранит summary. */
    dbRun(
        'UPDATE runs SET state = ?, rev = ?, summary = CASE WHEN ? <> \'\' THEN ? ELSE summary END,
                finished_at = CASE WHEN ? THEN NOW(3) ELSE finished_at END,
                wait_for = CASE WHEN ? THEN \'\' ELSE wait_for END,
                next_move_at = CASE WHEN ? THEN NULL ELSE next_move_at END
          WHERE id = ?',
        [$state, $rev, $summary, $summary, $done ? 1 : 0, $done ? 1 : 0, $done ? 1 : 0, $runId]
    );
    // Оборванный прогон цели посылки шлюзов не съедает: их заберёт следующий (runArrival).
    if (in_array($state, ['failed', 'stopped'], true)) {
        dbRun('UPDATE run_steps SET delivered_run_id = NULL WHERE delivered_run_id = ?', [$runId]);
    }
}

/**
 * Событие «прогон начат»: папка, стартовый блок и — если пришли по шлюзу — откуда.
 * $run — строка уже заведённого прогона, $folder и $start — его папка и стартовый элемент.
 */
function runStartEvent(array $run, array $folder, array $start, string $actor): void
{
    $from = runArrival($run);
    eventAdd([
        'project_id' => (int) $run['project_id'], 'run_id' => (int) $run['id'], 'kind' => 'start', 'actor' => $actor,
        'agent_id'   => $run['lead_agent_id'] ? (int) $run['lead_agent_id'] : null,
        'title' => ta('agents.runs.ev_started', ['run' => "r{$run['no']}", 'name' => $folder['name'], 'no' => $start['no']]),
        'meta'  => ['folder' => (int) $folder['id'], 'workDir' => (string) $folder['work_dir']]
            + ($from ? ['from_run' => $from['from_run'], 'from_step' => $from['from_step']] : []),
    ]);
}

/**
 * Прогон начат после шлюза в эту папку: «шлюз из «A» · r12: блок 133 «Сводка» → …».
 * Это ответ стартера — первый блок видит его во «Входе», как ответ любого предшественника.
 * Посылка — сам пройденный шлюз (шаг accepted): берём все, что ещё никто не получил, и те, что уже
 * получил этот прогон (delivered_run_id; отмечает runArrivalTake при старте), по порядку, через «; »,
 * целиком; нет их — null. Пути out/… источника — полные: у цели своя рабочая папка.
 * Отдаёт ['text', 'vars' => переменные шлюзов, 'ids' => шаги шлюзов, 'from_run' и 'from_step' —
 * прогон и шаг последнего перехода].
 */
function runArrival(array $run): ?array
{
    $passes = dbAll(
        "SELECT s.id, s.run_id, s.vars, r.`no`, r.work_dir, f.name FROM run_steps s
           JOIN elements e ON e.id = s.element_id AND e.type = 'gateway' AND e.target_folder_id = ?
           JOIN runs r ON r.id = s.run_id
           JOIN folders f ON f.id = r.folder_id
          WHERE s.state = 'accepted' AND (s.delivered_run_id IS NULL OR s.delivered_run_id = ?)
          ORDER BY s.id LIMIT 50",
        [(int) $run['folder_id'], (int) $run['id']]);
    if (!$passes) return null;

    $parts = array_map(static function (array $pass): string {
        /* «out/report.md» — это out/r19/ рабочей папки источника; у цели папка своя, и там
           out/… значит её out/rN. Путь делаем полным — файл находится однозначно. */
        $work = rtrim((string) $pass['work_dir'], '/');
        $full = static fn(string $text): string => $work === '' ? $text : (string) preg_replace_callback(
            '~(?<![\w/.])out/(r\d+/)?~u',
            static fn(array $m): string => "$work/out/" . (($m[1] ?? '') !== '' ? $m[1] : "r{$pass['no']}/"), $text);
        $said = array_map(static fn(array $one) => ta('agents.runs.gate_answer', ['no' => $one['no'], 'title' => $one['title']]) . $full($one['result']),
            stepInputRows([(int) $pass['id']]));
        return ta('agents.runs.gate_from', ['name' => $pass['name'], 'run' => "r{$pass['no']}"]) . ($said ? ': ' . implode('; ', $said) : '');
    }, $passes);
    $last = end($passes);
    return [
        'text'      => implode('; ', $parts),
        'vars'      => varsFromSteps($passes)['vars'],
        'ids'       => array_map('intval', array_column($passes, 'id')),
        'from_run'  => (int) $last['run_id'],
        'from_step' => (int) $last['id'],
    ];
}

/**
 * Схему поменяли по сути — удалили блок, ромб, шлюз или стрелку: история прошлых прогонов папки к ней
 * больше не подходит, прогоны стираются целиком (решение хозяина 30.09.2026). Живой прогон удалить
 * элемент и так не даст (activeGuardFolders). Файлы out/rN остаются на диске. Прогон, чью посылку шлюза
 * ещё никто не получил, ждёт получения. Номера прогонов не повторяются: счётчик — у проекта.
 */
function runsForget(int $projectId, int $folderId, int $rev): int
{
    $rows = dbAll(
        "SELECT r.id, r.`no`, r.title, r.work_dir FROM runs r
          WHERE r.project_id = ? AND r.folder_id = ? AND r.state IN ('done', 'failed', 'stopped')
            AND NOT EXISTS (SELECT 1 FROM run_steps s
                              JOIN elements e ON e.id = s.element_id AND e.type = 'gateway' AND e.target_folder_id IS NOT NULL
                             WHERE s.run_id = r.id AND s.state = 'accepted' AND s.delivered_run_id IS NULL)",
        [$projectId, $folderId]);
    foreach ($rows as $row) {
        markDeleted($projectId, 'run', (int) $row['id'], $rev,
            ['no' => (int) $row['no'], 'folder_id' => $folderId, 'title' => $row['title']]);
        dbRun('DELETE FROM runs WHERE id = ?', [(int) $row['id']]);
        // Служебное прогона без папки на сервере (агент в вебе) — storage/runs/<id>.
        $stored = dirname(__DIR__, 2) . '/storage/runs/' . (int) $row['id'];
        if ($row['work_dir'] === '') dbAfterCommit(static function () use ($stored): void {
            if (is_dir($stored)) { rmTree($stored); @rmdir($stored); }
        });
    }
    return count($rows);
}

/** Посылки шлюзов получены этим прогоном: больше их никто не заберёт. */
function runArrivalTake(array $run, array $from): void
{
    if (!$from['ids']) return;
    $in = implode(',', array_map('intval', $from['ids']));
    dbRun("UPDATE run_steps SET delivered_run_id = ? WHERE id IN ($in) AND delivered_run_id IS NULL", [(int) $run['id']]);
}
