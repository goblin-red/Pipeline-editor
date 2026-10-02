<?php
/* Лента времени прогона: кто какой блок брал и когда — одним снимком базы.
   Отдаёт: runTimeline(), timelineShape(), runReport().
   Не делает: ничего не пишет и не решает — только читает шаги, жетоны и ленту событий.
   Договор с экраном — scratchpad/impl/timeline-contract.md.

   Строки (lanes) — исполнители: ведущий, worker'ы, Jev, движок. Отрезки (bars) — попытки
   блоков, решения ромбов, стартер и шлюз. Ожидания (waits) — где прогон стоял без работы.
   Отметки (marks) — события уровня прогона: старт, пауза, остановка, живой сброс, конец.
   Показывается текущая жизнь прогона: попытки до живого сброса удалены вместе с шагами. */

declare(strict_types=1);

/** Короче этого ожидание не показываем: это миг между приёмкой и выдачей. */
const TIMELINE_WAIT_MIN_MS = 1000;

/** Короткий ответ и причина на ленте — до этой длины; полный — по шагу. */
const TIMELINE_TEXT = 160;

/** События ленты, которые нужны шкале: отметки прогона и кто решил, прошёл, начал. */
const TIMELINE_EVENTS = ['start', 'pause', 'resume', 'stop', 'reset', 'finish', 'open', 'decide', 'pass', 'accept', 'jev-say'];

/** GET run.timeline — лента одного прогона: названный, иначе последний прогон папки. */
function runTimeline(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];

    $runId = caller()['run_id'] ?: runAsked($projectId);
    if (!$runId && ($folderId = inputInt('folder'))) {
        folderRow($folderId, $projectId);                 // чужая папка — «не найдено»
        $runId = (int) (runForFolder($folderId)['id'] ?? 0);
    }
    if (!$runId) reply(['run' => null, 'lanes' => [], 'bars' => [], 'waits' => [], 'marks' => []]);

    reply(dbTransaction(static fn(): array => timelineShape(runRow((int) $runId, $projectId))));
}

/** Лента прогона. Звать внутри транзакции: версия, шаги и события — из одного снимка. */
function timelineShape(array $run): array
{
    $runId = (int) $run['id'];
    $now = dbNow();
    $folder = dbRow('SELECT * FROM folders WHERE id = ?', [(int) $run['folder_id']]) ?: [];
    $graph = engineGraph((int) $run['folder_id']);
    $steps = dbAll(
        'SELECT s.*, e.type FROM run_steps s LEFT JOIN elements e ON e.id = s.element_id
          WHERE s.run_id = ? ORDER BY s.opened_at, s.id', [$runId]);
    $events = dbAll(
        'SELECT id, step_id, element_no, kind, actor, at, title, meta FROM run_events
          WHERE run_id = ? AND kind IN (' . implode(',', array_fill(0, count(TIMELINE_EVENTS), '?')) . ')
          ORDER BY id', array_merge([$runId], TIMELINE_EVENTS));
    $ready = runReady($run, $steps, $graph);

    $marks = timelineMarks($events);
    $resets = array_values(array_filter($marks, static fn(array $m): bool => $m['kind'] === 'reset'));
    $lifeFrom = $resets ? (string) end($resets)['at'] : (string) $run['started_at'];
    $bars = timelineBars($run, $folder, $graph, $steps, $events, $ready, $lifeFrom);

    return [
        'run' => [
            'id' => $runId, 'no' => (int) $run['no'], 'label' => 'r' . (int) $run['no'],
            'state' => (string) $run['state'], 'epoch' => count($resets), 'version' => (int) $run['version'],
            'started' => $run['started_at'], 'finished' => $run['finished_at'],
            'lifeFrom' => $lifeFrom, 'now' => $now, 'folder' => (int) $run['folder_id'],
            'roleScheme' => (string) ($folder['role_scheme'] ?? 'solo'),
            'runEnv' => $folder ? folderActiveRunEnv($folder) : '',
            'showPause' => (float) $run['show_pause'],
        ],
        'lanes' => timelineLanes($run, $bars),
        'bars'  => $bars,
        'waits' => timelineWaits($run, $graph, $steps, $bars, $marks, $ready, $lifeFrom, $now),
        'marks' => $marks,
    ];
}

/* ── Отрезки ──────────────────────────────────────────────────── */

/**
 * Попытки блоков, решения ромбов, стартер и шлюз; ждущие решения ромбы — открытыми. По времени.
 * История не зависит от нынешней схемы: вид, ветка и цель шлюза — из событий шага,
 * исполнитель — из фактов попытки (stepExecutor). Нынешняя схема — только запасной путь старой истории.
 */
function timelineBars(array $run, array $folder, array $graph, array $steps, array $events, array $ready, string $lifeFrom): array
{
    $readyAt = timelineReadyAt((int) $run['id']);
    $facts = timelineFacts($events);
    $names = stepAgentNames($steps);
    $leadNow = stepLeadNow($folder);

    $taken = [];      // элемент → номера принятых попыток: для круга
    foreach ($steps as $s) if ($s['state'] === 'accepted') $taken[(int) $s['element_no']][] = (int) $s['attempt'];

    $bars = [];
    foreach ($steps as $s) {
        $id = (int) $s['id'];
        $fact = $facts[$id] ?? [];
        $kind = timelineKind($s, $fact, $graph);
        $lap = count(array_filter($taken[(int) $s['element_no']] ?? [], static fn(int $k): bool => $k < (int) $s['attempt'])) + 1;
        if ($kind === 'work') {
            $who = stepExecutor($s, $names, $leadNow);
            $bar = timelineBar($kind, timelineLaneOf($who), $s, $lap);
            $bar['guess'] = $who['guess'];
        } else {
            $bar = timelineBar($kind, timelineActorLane($fact['actor'] ?? 'script'), $s, $lap);
        }
        if ($kind === 'decision') {
            // Ромб на входе схемы жетона не брал — готов с начала жизни прогона.
            $bar['from'] = $readyAt[$id] ?? $lifeFrom;
            $bar['branch'] = timelineBranch($fact, $graph, $s);
            $bar['confidence'] = ($fact['actor'] ?? '') === 'judge' ? timelineConfidence($fact['said'] ?? []) : null;
        }
        if ($kind === 'pass') {
            $target = (int) ($fact['meta']['target_folder'] ?? $graph['nodes'][(int) $s['element_id']]['target_folder_id'] ?? 0);
            $bar['target'] = $target ?: null;
        }
        $bars[] = $bar;
    }

    // Ромб готов, а решения нет: открытый отрезок в строке того, кто решает.
    foreach ($ready as $item) {
        if ($item['type'] !== 'decision') continue;
        $bars[] = timelineBar('decision', (string) ($run['jev'] ?? 'off') === 'judge' ? 'jev' : 'lead', [
            'id' => null, 'element_id' => $item['id'], 'element_no' => $item['no'], 'element_title' => $item['title'],
            'attempt' => null, 'state' => 'ready',
            'opened_at' => timelineFreeAt((int) $run['id'], $item['via']) ?? $lifeFrom,
        ], count($taken[(int) $item['no']] ?? []) + 1);
    }

    usort($bars, static fn(array $a, array $b): int
        => [(string) $a['from'], (int) $a['step']] <=> [(string) $b['from'], (int) $b['step']]);
    return $bars;
}

/**
 * Что записано о каждом шаге: id шага → ['kind' => open|decide|pass|start, 'actor', 'meta', 'title', 'said'].
 * decide, pass и принятый стартер — кто сделал; open — была выдача работы; jev-say — ответ Jev.
 */
function timelineFacts(array $events): array
{
    $out = [];
    foreach ($events as $e) {
        if ($e['step_id'] === null) continue;
        $id = (int) $e['step_id'];
        $meta = json_decode((string) $e['meta'], true) ?: [];
        if ($e['kind'] === 'jev-say') { $out[$id]['said'] = $meta; continue; }
        $kind = match (true) {
            in_array($e['kind'], ['open', 'decide', 'pass'], true) => (string) $e['kind'],
            $e['kind'] === 'accept' && !empty($meta['starter']) => 'start',
            default => null,
        };
        // Первое такое событие шага — его вид; для старой истории без пометок остаётся актёр приёмки.
        if ($kind !== null && !isset($out[$id]['kind'])) {
            $out[$id] = ['kind' => $kind, 'actor' => (string) $e['actor'], 'meta' => $meta, 'title' => (string) $e['title']]
                + ($out[$id] ?? []);
        } elseif ($e['kind'] === 'accept') {
            $out[$id]['actor'] ??= (string) $e['actor'];
        }
    }
    return $out;
}

/** Вид отрезка по записанному событию; нет его (старая история) — по нынешней схеме. */
function timelineKind(array $s, array $fact, array $graph): string
{
    return match ($fact['kind'] ?? null) {
        'open' => 'work', 'decide' => 'decision', 'pass' => 'pass', 'start' => 'start',
        default => match (true) {
            $s['type'] === 'decision' => 'decision',
            $s['type'] === 'gateway'  => 'pass',
            $s['element_id'] !== null && (int) $s['element_id'] === graphStarter($graph) => 'start',
            default => 'work',
        },
    };
}

/** Ключ строки исполнителя (договор, §4): agent:<id> | who:<имя> | lead | worker. */
function timelineLaneOf(array $executor): string
{
    return match ($executor['kind']) {
        'agent' => 'agent:' . $executor['agentId'],
        'who' => 'who:' . $executor['title'],
        default => $executor['kind'],
    };
}

/** Отрезок одной формы: неприменимое — null (договор, §5). */
function timelineBar(string $kind, string $lane, array $s, int $lap): array
{
    $point = in_array($kind, ['start', 'pass'], true);
    $closed = in_array($s['state'], ['returned', 'failed', 'cancelled'], true);
    return [
        'kind' => $kind, 'lane' => $lane,
        'step' => $s['id'] !== null ? (int) $s['id'] : null,
        'element' => $s['element_id'] !== null ? (int) $s['element_id'] : null,
        'no' => (int) $s['element_no'], 'title' => (string) $s['element_title'],
        'address' => $s['attempt'] !== null ? $s['element_no'] . '.' . $s['attempt'] : null,
        'attempt' => $s['attempt'] !== null ? (int) $s['attempt'] : null,
        'lap' => $lap, 'state' => (string) $s['state'],
        'from' => $point ? ($s['finished_at'] ?? $s['opened_at']) : $s['opened_at'],
        'took' => $kind === 'work' ? ($s['started_at'] ?? null) : null,
        'sent' => $kind === 'work' ? ($s['submitted_at'] ?? null) : null,
        'to' => $point ? ($s['finished_at'] ?? $s['opened_at']) : ($s['finished_at'] ?? null),
        'result' => timelineText($s['result'] ?? null),
        'error' => $closed ? timelineText($s['error'] ?? null) : null,
        'branch' => null, 'confidence' => null, 'target' => null, 'guess' => false,
    ];
}

/** Строка решения, стартера и шлюза — по тому, кто сделал: Jev, ведущий или человек, движок. */
function timelineActorLane(string $actor): string
{
    return match ($actor) {
        'judge' => 'jev',
        'lead', 'human' => 'lead',
        'worker' => 'worker',
        default => 'engine',
    };
}

/** Когда шаг стал готов: последний забранный им жетон лёг на стрелку. id шага → время. */
function timelineReadyAt(int $runId): array
{
    $out = [];
    foreach (dbAll('SELECT taken_by_step_id AS step, MAX(created_at) AS at FROM run_marks
                     WHERE run_id = ? AND taken_by_step_id IS NOT NULL GROUP BY taken_by_step_id', [$runId]) as $row) {
        $out[(int) $row['step']] = (string) $row['at'];
    }
    return $out;
}

/** Когда лёг лежащий жетон на входе (via) ждущего узла; null — входа нет: узел — вход схемы. */
function timelineFreeAt(int $runId, ?int $edgeId): ?string
{
    if ($edgeId === null) return null;
    $at = dbValue('SELECT MAX(created_at) FROM run_marks WHERE run_id = ? AND edge_id = ? AND taken_by_step_id IS NULL',
        [$runId, $edgeId]);
    return $at ? (string) $at : null;
}

/**
 * Ветка решённого ромба — из записанного решения (decide.meta.branch). Старая история без неё —
 * из заголовка «→ да/нет», последнее средство — выбранная стрелка нынешней схемы.
 */
function timelineBranch(array $fact, array $graph, array $step): ?string
{
    $branch = (string) ($fact['meta']['branch'] ?? '');
    if (in_array($branch, ['yes', 'no'], true)) return $branch;
    if (preg_match('/→\s*(да|нет|yes|no)\b/u', (string) ($fact['title'] ?? ''), $m)) {
        return in_array($m[1], ['да', 'yes'], true) ? 'yes' : 'no';
    }
    $edge = (string) ($graph['edges'][(int) ($step['chosen_edge_id'] ?? 0)]['branch'] ?? '');
    return in_array($edge, ['yes', 'no'], true) ? $edge : null;
}

/** Уверенность Jev 0…1: из ответа (meta.confidence), у старых событий — из подсказки «Jev: да 0,93». */
function timelineConfidence(array $meta): ?float
{
    if (is_numeric($meta['confidence'] ?? null)) return round((float) $meta['confidence'], 3);
    if (preg_match('/Jev:\s*\S+\s+(\d+[.,]\d+)/u', (string) ($meta['reason'] ?? ''), $m)) {
        return round((float) str_replace(',', '.', $m[1]), 3);
    }
    return null;
}

/** Ответ или причина коротко: до TIMELINE_TEXT знаков, длинное — с многоточием. */
function timelineText(?string $text): ?string
{
    $text = trim((string) $text);
    if ($text === '') return null;
    return mb_strlen($text) > TIMELINE_TEXT ? mb_substr($text, 0, TIMELINE_TEXT - 1) . '…' : $text;
}

/* ── Строки ───────────────────────────────────────────────────── */

/** Строки: ведущий всегда; worker'ы — по первому появлению; Jev и движок — если заняты. */
function timelineLanes(array $run, array $bars): array
{
    $used = [];
    foreach ($bars as $bar) $used[$bar['lane']] ??= true;
    $agentIds = array_map(static fn(string $key): int => (int) substr($key, 6),
        array_filter(array_keys($used), static fn(string $key): bool => str_starts_with($key, 'agent:')));
    $leadId = $run['lead_agent_id'] ? (int) $run['lead_agent_id'] : null;
    if ($leadId) $agentIds[] = $leadId;
    $names = $agentIds
        ? array_column(dbAll('SELECT id, name FROM agents WHERE id IN (' . implode(',', array_fill(0, count($agentIds), '?')) . ')',
            array_values($agentIds)), 'name', 'id')
        : [];

    $lanes = [['key' => 'lead', 'kind' => 'lead',
               'title' => $leadId && isset($names[$leadId]) ? (string) $names[$leadId] : t('server.timeline.lead'),
               'agentId' => $leadId]];
    foreach (array_keys($used) as $key) {
        if (in_array($key, ['lead', 'jev', 'engine'], true)) continue;
        $agentId = str_starts_with($key, 'agent:') ? (int) substr($key, 6) : null;
        $title = match (true) {
            $agentId !== null => (string) ($names[$agentId] ?? t('server.timeline.worker')),
            str_starts_with($key, 'who:') => substr($key, 4),
            default => t('server.timeline.worker'),
        };
        $lanes[] = ['key' => $key, 'kind' => 'worker', 'title' => $title, 'agentId' => $agentId];
    }
    foreach (['jev', 'engine'] as $key) {
        if (isset($used[$key])) $lanes[] = ['key' => $key, 'kind' => $key, 'title' => t('server.timeline.' . $key), 'agentId' => null];
    }
    return $lanes;
}

/* ── Отметки ──────────────────────────────────────────────────── */

/** События уровня прогона: старт, пауза, продолжение, остановка, живой сброс (номер жизни), конец. */
function timelineMarks(array $events): array
{
    $marks = [];
    $epoch = 0;
    foreach ($events as $e) {
        $kind = (string) $e['kind'];
        if (!in_array($kind, ['start', 'pause', 'resume', 'stop', 'reset', 'finish'], true)) continue;
        if ($kind === 'reset' && $e['element_no'] !== null) continue;     // сброс блока — не отметка прогона
        $meta = json_decode((string) $e['meta'], true) ?: [];
        $marks[] = [
            'kind' => $kind, 'at' => (string) $e['at'], 'title' => (string) $e['title'], 'by' => (string) $e['actor'],
            'epoch' => $kind === 'reset' ? ++$epoch : null,
            'end' => $kind === 'finish' ? (string) ($meta['end'] ?? (!empty($meta['failed']) ? 'failed' : 'done')) : null,
            'asked' => $kind === 'stop' ? !empty($meta['asked']) : null,
        ];
    }
    return $marks;
}

/* ── Ожидания ─────────────────────────────────────────────────── */

/**
 * Где прогон стоял без открытой работы (договор, §6). Промежутки между попытками внутри
 * текущей жизни режутся по причинам, первая по порядку побеждает:
 * пауза прогона → ромб → шлюз → пауза показа → человек → ведущий.
 */
function timelineWaits(array $run, array $graph, array $steps, array $bars, array $marks,
                       array $ready, string $lifeFrom, string $now): array
{
    $from = timelineMs($lifeFrom);
    $live = in_array($run['state'], ['running', 'paused'], true);
    $end = timelineMs($live ? $now : (string) ($run['finished_at'] ?? $now));
    if ($from === null || $end === null || $end <= $from) return [];

    $work = array_values(array_filter($bars, static fn(array $b): bool => $b['kind'] === 'work'));
    $gaps = timelineGaps($from, $end, array_map(static fn(array $b): array
        => [timelineMs($b['from']), timelineMs($b['to']) ?? $end], $work));
    $why = timelineReasons($run, $steps, $bars, $marks, $ready, $lifeFrom, $end);
    $stuckNow = $live && in_array('person', array_map(static fn(array $item): string
        => $item['type'] === 'block' ? paintWait($run, $graph, (int) $item['id']) : '', $ready), true);

    $out = [];
    foreach ($gaps as [$a, $b]) {
        foreach (timelineSplit($a, $b, $why) as [$s, $e, $reason, $no]) {
            if ($reason === null) {
                $person = timelineLastFailed($work, $s) || ($stuckNow && $e === $end);
                [$reason, $no] = [$person ? 'person' : 'lead', null];
            }
            $n = count($out) - 1;
            if ($n >= 0 && $out[$n]['reason'] === $reason && $out[$n]['no'] === $no && $out[$n]['_to'] === $s) {
                $out[$n]['_to'] = $e;          // та же причина подряд — один отрезок
                continue;
            }
            $out[] = ['_from' => $s, '_to' => $e, 'reason' => $reason, 'no' => $no];
        }
    }

    $waits = [];
    foreach ($out as $w) {
        if ($w['_to'] - $w['_from'] < TIMELINE_WAIT_MIN_MS) continue;
        $waits[] = ['from' => timelineAt($w['_from']), 'to' => $live && $w['_to'] === $end ? null : timelineAt($w['_to']),
                    'reason' => $w['reason'], 'no' => $w['no']];
    }
    return $waits;
}

/** Промежутки окна [from, end], не покрытые отрезками работы. */
function timelineGaps(int $from, int $end, array $busy): array
{
    usort($busy, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
    $gaps = [];
    $at = $from;
    foreach ($busy as [$a, $b]) {
        if ($a === null) continue;
        if ($a > $at) $gaps[] = [$at, min($a, $end)];
        $at = max($at, $b);
        if ($at >= $end) break;
    }
    if ($at < $end) $gaps[] = [$at, $end];
    return array_values(array_filter($gaps, static fn(array $g): bool => $g[1] > $g[0]));
}

/**
 * Известные причины ожидания отрезками [от, до, причина, номер, порядок]:
 * пауза прогона (1), ромб (2), шлюз (3), пауза показа после приёмки и решения (4).
 */
function timelineReasons(array $run, array $steps, array $bars, array $marks, array $ready, string $lifeFrom, int $end): array
{
    $out = [];
    $readyAt = timelineReadyAt((int) $run['id']);
    $paused = null;
    foreach ($marks as $m) {
        $at = timelineMs($m['at']);
        if ($m['kind'] === 'pause') $paused ??= $at;
        elseif ($paused !== null && in_array($m['kind'], ['resume', 'stop', 'reset', 'finish'], true)
                && ($m['kind'] !== 'stop' || !$m['asked'])) {
            $out[] = [$paused, $at, 'pause', null, 1];
            $paused = null;
        }
    }
    if ($paused !== null) $out[] = [$paused, $end, 'pause', null, 1];

    foreach ($bars as $b) {
        if ($b['kind'] === 'decision') $out[] = [timelineMs($b['from']), timelineMs($b['to']) ?? $end, 'decision', $b['no'], 2];
    }
    foreach ($bars as $b) {
        if ($b['kind'] !== 'pass') continue;
        $out[] = [timelineMs($readyAt[(int) $b['step']] ?? $lifeFrom), timelineMs($b['to']) ?? $end, 'pass', $b['no'], 3];
    }
    foreach ($ready as $item) {
        if ($item['type'] !== 'gateway') continue;
        $out[] = [timelineMs(timelineFreeAt((int) $run['id'], $item['via']) ?? $lifeFrom), $end, 'pass', (int) $item['no'], 3];
    }

    $hold = (int) round((float) $run['show_pause'] * 2000);     // runPace: 2 × пауза показа
    if ($hold > 0) {
        foreach ($steps as $s) {
            if (!in_array($s['state'], ['accepted', 'returned'], true) || !($at = timelineMs($s['finished_at']))) continue;
            $out[] = [$at, $at + $hold, 'pause', null, 4];
        }
    }
    return array_values(array_filter($out, static fn(array $r): bool => $r[0] !== null && $r[1] !== null && $r[1] > $r[0]));
}

/** Разрезать промежуток по причинам: на каждом куске — причина с наименьшим порядком, иначе null. */
function timelineSplit(int $a, int $b, array $why): array
{
    $cuts = [$a, $b];
    foreach ($why as [$x, $y]) {
        if ($x > $a && $x < $b) $cuts[] = $x;
        if ($y > $a && $y < $b) $cuts[] = $y;
    }
    $cuts = array_values(array_unique($cuts));
    sort($cuts);

    $out = [];
    for ($i = 0; $i + 1 < count($cuts); $i++) {
        [$s, $e] = [$cuts[$i], $cuts[$i + 1]];
        $best = null;
        foreach ($why as $r) {
            if ($r[0] <= $s && $r[1] >= $e && ($best === null || $r[4] < $best[4])) $best = $r;
        }
        $out[] = [$s, $e, $best[2] ?? null, $best[3] ?? null];
    }
    return $out;
}

/** Последняя закрытая до этого мига попытка провалена или отменена — ждали человека. */
function timelineLastFailed(array $work, int $at): bool
{
    $last = null;
    foreach ($work as $b) {
        $to = timelineMs($b['to']);
        if ($to !== null && $to <= $at && ($last === null || $to >= $last[0])) $last = [$to, $b['state']];
    }
    return $last !== null && in_array($last[1], ['failed', 'cancelled'], true);
}

/** Время базы «ГГГГ-ММ-ДД чч:мм:сс.ммм» в миллисекундах — только для разностей. */
function timelineMs(?string $at): ?int
{
    if ($at === null || $at === '') return null;
    $utc = new DateTimeZone('UTC');
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $at, $utc)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $at, $utc);
    return $d ? (int) $d->format('Uv') : null;
}

/** Миллисекунды обратно во время базы — той же записью, что пришла. */
function timelineAt(int $ms): string
{
    return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)) . sprintf('.%03d', $ms % 1000);
}

/**
 * Проверили ли работу по существу: арифметика сошлась или образец с
 * переменными совпал. Техническая допустимость (вид строки, наличие файлов)
 * содержательной проверкой не считается — она ничего не говорит о работе.
 */
function stepProvenByChecks(string $verdict): bool
{
    if (trim($verdict) === '') return false;
    $said = json_decode($verdict, true);
    if (!is_array($said)) return false;

    $detail = is_array($said['detail'] ?? null) ? $said['detail'] : [];
    if (($detail['expr']['status'] ?? '') === 'ok') return true;

    $form = is_array($detail['form'] ?? null) ? $detail['form'] : [];
    return ($form['status'] ?? '') === 'ok' && !empty($form['bound']);
}

/** GET run.report — короткий итог для человека. */
function runReport(): void
{
    $project = requireProject(false);
    $who = caller();
    // Пропуск leader называет прогон сам; человек называет его ярлыком rN или id.
    $runId = $who['run_id'] ?: runAsked((int) $project['id']);
    if (!$runId) throw new ApiError(ta('agents.events.no_run'), 'not_found');
    $run = runRow((int) $runId, (int) $project['id']);

    /* Тип элемента нужен для отметки «слишком быстро»: ромб и шлюз решает код,
       и доли секунды там — норма, а не повод присматриваться. */
    $steps = dbAll(
        'SELECT s.*, e.type FROM run_steps s
      LEFT JOIN elements e ON e.id = s.element_id
          WHERE s.run_id = ? ORDER BY s.id',
        [$run['id']]
    );
    // Порог «подозрительно быстро» задаёт спрашивающий: для устного счёта
    // секунда — нормальная работа, для сборки проекта — нет.
    $slow = inputInt('slow');
    $slow = $slow > 0 ? $slow : 3;

    $lines = [];
    foreach ($steps as $step) {
        $seconds = $step['finished_at']
            ? strtotime((string) $step['finished_at']) - strtotime((string) $step['opened_at']) : null;
        $worked = !in_array((string) ($step['type'] ?? ''), ['decision', 'gateway'], true);

        /* Содержательная проверка сошлась — работа доказана по существу, и
           время значения не имеет. Тревожимся только там, где принято без
           разбора содержания: только там быстрота что-то говорит. */
        $proven = stepProvenByChecks((string) ($step['verdict'] ?? ''));
        $lines[] = [
            'no' => (int) $step['element_no'],
            'title' => (string) $step['element_title'],
            'attempt' => (int) $step['attempt'],
            'state' => (string) $step['state'],
            'type' => (string) ($step['type'] ?? ''),
            'result' => (string) ($step['result'] ?? ''),
            'error' => (string) ($step['error'] ?? ''),
            'seconds' => $seconds,
            'proven'  => $proven,
            // Быстрая работа worker — повод присмотреться, только если её
            // никто не проверил по существу.
            'suspiciouslyFast' => $worked && !$proven && $seconds !== null && $seconds < $slow
                && $step['state'] === 'accepted',
        ];
    }
    $accepted = count(array_filter($lines, static fn($l) => $l['state'] === 'accepted'));
    reply([
        'run' => runShape($run),
        'steps' => $lines,
        'accepted' => $accepted,
        'total' => count($lines),
        'jobs' => (int) dbValue('SELECT COUNT(*) FROM run_jobs WHERE run_id = ?', [$run['id']]),
    ]);
}
