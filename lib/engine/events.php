<?php
/* Лента прогона: одно место, где видно всё.
   Отдаёт: eventAdd(), eventStep(), runEvent(), runLog(), eventClean().
   Не делает: ничего не решает — только записывает и отдаёт записанное.

   Правило одно: событие пишется одинаково, кто бы его ни клал — сервер,
   круг leader, сторож или worker. У события есть заголовок в одну строку
   (его видно свёрнутым) и тело — сырьё: пакет задания дословно, строка
   запуска, запрос к сервису, вывод терминала. */

declare(strict_types=1);

/** Виды событий: что бывает в ленте. Подпись — для человека. */
const EVENT_KINDS = [
    'start' => true,
    'open' => true,
    'package' => true,
    'launch' => true,
    'task' => true,
    'note' => true,
    'job' => true,
    'job-done' => true,
    'message' => true,
    'submit' => true,
    'judge' => true,
    'jev-ask' => true,
    'jev-say' => true,
    'accept' => true,
    'return' => true,
    'fail' => true,
    'decide' => true,
    'pass' => true,
    'console' => true,
    'drive' => true,
    'watch' => true,
    'finish' => true,
    'reset' => true,
    'pause' => true,
    'resume' => true,
    'who' => true,
    'stop' => true,
    'cancel' => true,
    'warn' => true,
    'file' => true,
];

/** Подпись вида события: человеку (в редакторе) — на его языке, агенту и в ленту — на языке проекта. */
function eventKindWord(string $kind, bool $forAgents = false): string
{
    $key = 'agents.events.kind.' . $kind;
    $word = $forAgents ? ta($key) : t($key);
    return $word === $key ? $kind : $word;
}

/** События, которые являются действиями прогона, а не диагностикой/снимком. */
const EVENT_DID_KINDS = [
    'start', 'open', 'task', 'submit', 'accept', 'return', 'fail', 'decide', 'pass', 'finish', 'reset', 'stop',
    // Слово человека или leader — тоже действие прогона: `goblin say` писал
    // в журнал, но в живой ленте не показывался, и было не понять, ушло ли.
    'message',
];

/**
 * Записать событие.
 *
 * Пропуска и ключи вырезаются здесь, а не у того, кто пишет: забыть об этом
 * легко, а строка запуска worker несёт пропуск шага целиком.
 */
function eventAdd(array $e): int
{
    $runId = (int) ($e['run_id'] ?? 0);
    if (!$runId) return 0;

    dbRun(
        'INSERT INTO run_events
            (project_id, run_id, step_id, element_no, attempt, at, kind, actor, agent_id, title, body, meta, took_ms)
         VALUES (?,?,?,?,?,NOW(3),?,?,?,?,?,?,?)',
        [
            (int) $e['project_id'],
            $runId,
            $e['step_id']    ?? null,
            $e['element_no'] ?? null,
            $e['attempt']    ?? null,
            (string) ($e['kind'] ?? 'drive'),
            (string) ($e['actor'] ?? 'script'),
            $e['agent_id']   ?? null,
            mb_substr(eventClean((string) ($e['title'] ?? '')), 0, 250),
            isset($e['body']) && $e['body'] !== null ? eventClean(eventText($e['body'])) : null,
            isset($e['meta'])
                ? json_encode(journalCleanSecrets((array) $e['meta']), JSON_UNESCAPED_UNICODE)
                : null,
            $e['took_ms'] ?? null,
        ]
    );
    return dbId();
}

/** Событие, привязанное к шагу: номер и попытка подставляются сами. */
function eventStep(array $step, string $kind, string $title, $body = null, array $more = []): int
{
    return eventAdd($more + [
        'project_id' => (int) $step['project_id'],
        'run_id'     => (int) $step['run_id'],
        'step_id'    => (int) $step['id'],
        'element_no' => (int) $step['element_no'],
        'attempt'    => (int) $step['attempt'],
        'agent_id'   => $step['agent_id'] ? (int) $step['agent_id'] : null,
        'kind'       => $kind,
        'title'      => $title,
        'body'       => $body,
    ]);
}

/** Тело события: строку как есть, остальное — читаемым JSON. */
function eventText($body): string
{
    return is_string($body)
        ? $body
        : (string) json_encode(
            is_array($body) ? journalCleanSecrets($body) : $body,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
}

/** Верхняя граница событий прогона; диагностические события тоже двигают cursor. */
function runLastEvent(int $runId): int
{
    return (int) dbValue('SELECT COALESCE(MAX(id), 0) FROM run_events WHERE run_id = ?', [$runId]);
}

/**
 * Одна страница событий до согласованной верхней границы snapshot.
 * Cursor идёт по всем строкам, включая диагностику; в did попадают только действия.
 */
function runDidPage(int $runId, int $sinceEvent, int $eventHead, int $limit = 200): array
{
    if ($eventHead <= $sinceEvent) {
        return ['did' => [], 'lastEvent' => $eventHead, 'hasMoreEvents' => false];
    }
    $rows = dbAll(
        "SELECT * FROM run_events
          WHERE run_id = ? AND id > ? AND id <= ?
          ORDER BY id LIMIT " . ($limit + 1),
        [$runId, $sinceEvent, $eventHead]
    );
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    $cursor = $rows ? (int) $rows[count($rows) - 1]['id'] : $eventHead;
    $didRows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => in_array((string) $row['kind'], EVENT_DID_KINDS, true)
    ));
    $did = array_map(static function (array $row): array {
        $step = $row['element_no'] !== null
            ? (int) $row['element_no'] . ($row['attempt'] !== null ? '.' . (int) $row['attempt'] : '')
            : null;
        $kind = (string) $row['kind'];
        $meta = $row['meta'] !== null && $row['meta'] !== ''
            ? (json_decode((string) $row['meta'], true) ?: [])
            : [];
        $why = $row['body'] !== null && $row['body'] !== ''
            ? (string) $row['body']
            : (string) $row['title'];
        $branch = null;
        if ($kind === 'decide') {
            $branch = in_array(($meta['branch'] ?? null), ['yes', 'no'], true)
                ? (string) $meta['branch']
                : null;
            // Старые события не содержат meta.branch: восстанавливаем ветку из
            // сохранённого заголовка, чтобы история прежних прогонов не врала.
            if (preg_match('/→\s*(да|нет|yes|no)(?:\s*·\s*(.*))?$/u', (string) $row['title'], $match)) {
                if ($branch === null) $branch = in_array($match[1], ['да', 'yes'], true) ? 'yes' : 'no';
                $why = trim((string) ($match[2] ?? ''));
            } elseif ($row['body'] === null || $row['body'] === '') {
                $why = '';
            }
        }
        return [
            'event' => (int) $row['id'],
            'kind' => $kind,
            'step' => $step,
            'by' => (string) $row['actor'],
            'why' => $why,
            'branch' => $branch,
        ];
    }, $didRows);
    return ['did' => $did, 'lastEvent' => $more ? $cursor : $eventHead, 'hasMoreEvents' => $more];
}

/**
 * Вырезать секреты.
 *
 * В строке запуска worker стоит пропуск шага, в заявках — ключи сервисов.
 * Журнал читают люди и агенты, поэтому храним их скрытыми.
 */
function eventClean(string $text): string
{
    return (string) preg_replace(
        ['/\b(gbs|gbr|gba|gbl)_[A-Za-z0-9]+/', '/\bapikey_[A-Za-z0-9_]+/', '/\bsk-[A-Za-z0-9\-]{10,}/'],
        ['$1_…', 'apikey_…', 'sk-…'],
        $text
    );
}

/** Размер тела человеческими словами: «4,2 КБ». */
function kbOf($body): string
{
    $size = strlen(eventText($body));
    $ru = langAgents() === 'ru';
    return $size < 1024 ? ta('agents.events.bytes', ['n' => $size]) : ta('agents.events.kb', ['n' => number_format($size / 1024, 1, $ru ? ',' : '.', $ru ? ' ' : ',')]);
}

/* ── Операции ─────────────────────────────────────────────────── */

/** POST run.event — событие от круга, сторожа, worker или человека. */
function runEvent(): void
{
    // Под замком, как любая команда прогона; ход прогона событие не меняет — версия не растёт.
    reply(engineCommand(static function (): array {
        [$project, $run] = runOwn(false);
        $who = caller();

        $kind = (string) (input('kind') ?? 'drive');
        if (!isset(EVENT_KINDS[$kind])) throw new ApiError(ta('agents.events.no_kind', ['kind' => $kind]));

        // Шаг называют номером элемента: так его знает и человек, и командная строка.
        $stepId = null;
        $no = inputInt('no');
        if ($no) {
            $row = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? ORDER BY id DESC LIMIT 1',
                [(int) $run['id'], $no]);
            $stepId = $row ? (int) $row['id'] : null;
        }
        if ($who['step_id']) $stepId = (int) $who['step_id'];

        $step = $stepId ? dbRow('SELECT * FROM run_steps WHERE id = ?', [$stepId]) : null;

        $id = eventAdd([
            'project_id' => (int) $project['id'],
            'run_id'     => (int) $run['id'],
            'step_id'    => $step ? (int) $step['id'] : null,
            'element_no' => $step ? (int) $step['element_no'] : ($no ?: null),
            'attempt'    => $step ? (int) $step['attempt'] : null,
            'kind'       => $kind,
            'actor'      => eventActor($who),
            'agent_id'   => $who['agent_id'] ?? ($step['agent_id'] ?? null),
            'title'      => (string) (input('title') ?? eventKindWord($kind, true)),
            'body'       => input('body'),
            'meta'       => input('meta'),
            'took_ms'    => inputInt('tookMs') ?: null,
        ]);
        return ['event' => $id];
    }));
}

/**
 * leader бывает живой, а бывает круг `goblin drive`.
 * Круг помечает свои обращения `by=script` — по ней и различаем.
 */
function leadActor(): string
{
    return ((string) (input('by') ?? '')) === 'script' ? 'script' : 'lead';
}

/** Кто пишет: по пропуску понятно, leader это, worker или человек. */
function eventActor(array $who): string
{
    return match ($who['role']) {
        'lead'   => leadActor(),
        'worker' => 'worker',
        'human', 'guest', 'admin' => 'human',
        default  => 'script',
    };
}

/**
 * GET run.log — лента прогона.
 *
 * Без `event` тела не отдаются: в ленте они не нужны, а весят много.
 * С `event=<id>` приходит одно событие целиком — это и есть «раскрыть».
 */
function runLog(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $who = caller();

    $runId = $who['run_id'] ?: runAsked($projectId);
    if (!$runId) throw new ApiError(ta('agents.events.no_run'), 'not_found');
    $run = runRow((int) $runId, $projectId);

    if ($eventId = inputInt('event')) {
        $row = dbRow('SELECT * FROM run_events WHERE id = ? AND run_id = ?', [$eventId, (int) $run['id']]);
        if (!$row) throw new ApiError(ta('agents.events.no_event'), 'not_found');
        reply(['event' => eventShape($row, true)]);
    }

    $where = ['run_id = ?'];
    $args  = [(int) $run['id']];
    if ($no = inputInt('step')) { $where[] = 'element_no = ?'; $args[] = $no; }
    // Через запятую можно спросить сразу несколько видов: «jev-ask,jev-say». Пусто («,») — без фильтра.
    $kinds = array_values(array_filter(array_map('trim', explode(',', (string) (input('kind') ?? '')))));
    if ($kinds) {
        $where[] = 'kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        foreach ($kinds as $one) $args[] = $one;
    }
    if ($after = inputInt('after')) { $where[] = 'id > ?'; $args[] = $after; }

    $limit = min(500, max(1, inputInt('limit') ?: 200));
    $rows = dbAll('SELECT * FROM run_events WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . ($limit + 1), $args);
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    $lastEvent = $rows ? (int) $rows[count($rows) - 1]['id'] : (int) (inputInt('after') ?? 0);

    reply([
        'run'    => runShape($run),
        'events' => array_map(static fn(array $row) => eventShape($row, false), $rows),
        'lastEvent' => $lastEvent,
        'more' => $more,
        'kinds'  => array_combine(array_keys(EVENT_KINDS), array_map('eventKindWord', array_keys(EVENT_KINDS))),
    ]);
}

function eventShape(array $row, bool $full): array
{
    $out = [
        'id'      => (int) $row['id'],
        'at'      => $row['at'],
        'kind'    => (string) $row['kind'],
        'what'    => eventKindWord((string) $row['kind']),
        'actor'   => (string) $row['actor'],
        'agent'   => $row['agent_id'] ? (int) $row['agent_id'] : null,
        'no'      => $row['element_no'] !== null ? (int) $row['element_no'] : null,
        'attempt' => $row['attempt'] !== null ? (int) $row['attempt'] : null,
        'title'   => (string) $row['title'],
        'tookMs'  => $row['took_ms'] !== null ? (int) $row['took_ms'] : null,
        'size'    => $row['body'] !== null ? strlen((string) $row['body']) : 0,
    ];
    if ($row['meta']) $out['meta'] = json_decode((string) $row['meta'], true);
    if ($full) $out['body'] = (string) $row['body'];
    return $out;
}
