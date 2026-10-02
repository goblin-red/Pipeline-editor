<?php
/* Снимок прогона, ход по просьбе и настройка на ходу.
   Отдаёт: runState(), runAdvanceOp(), runUpdate(), runStateShape(), runOfRequest(), stepReview().
   Не делает: не решает правила прогона — это движок (engine/advance.php).

   run.state читает и умеет ждать: с `since` и `wait` запрос держится, пока версия прогона
   не вырастет, но не дольше 25 секунд и не дольше паузы показа. Так утилита и браузер
   узнают об изменении сразу, а не через опрос раз в пару секунд. */

declare(strict_types=1);

/** Дольше этого ни один запрос не ждёт: за ним стоит обычный HTTP. */
const STATE_WAIT_LIMIT = 25;

/** GET run.state — где прогон и чего он ждёт. */
function runState(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $run = runOfRequest($projectId);

    $since = inputInt('since');
    $wait  = max(0, min(STATE_WAIT_LIMIT, (int) (inputInt('wait') ?? 0)));
    if ($wait > 0 && $since !== null) $run = runStateWait($run, $since, $wait, $projectId);

    reply(runStateResponse(
        $project,
        (int) $run['id'],
        !empty(input('full')),
        inputInt('sinceEvent')
    ));
}

/**
 * POST run.advance — сделать ход. С `wait`: нечего делать — подождать изменения и попробовать снова.
 * Ответ тот же, что у run.state, плюс `did` — что сделал движок.
 */
function runAdvanceOp(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $run = runOfRequest($projectId);
    $runId = (int) $run['id'];

    $commandId = trim((string) (input('commandId') ?? ''));
    if ($commandId !== '') {
        // В receipt хранится только детерминированный bounded result под lock.
        // Jev завершается после commit и никогда не запускается на replay.
        $sinceEvent = inputInt('sinceEvent');
        if ($sinceEvent === null) $sinceEvent = runLastEvent($runId);
        $repeated = false;
        $commandResult = engineCommand(
            static fn(): array => advanceRun($runId, $projectId),
            $repeated
        );
        if (!$repeated && !empty($commandResult['pendingJev'])) {
            advanceJevComplete($runId, (array) $commandResult['pendingJev']);
        }

        $out = runStateResponse($project, $runId, !empty(input('full')), $sinceEvent);
        $out['commandResult'] = $commandResult;
        $out['repeat'] = $repeated;
        reply($out);
    }

    $sinceEvent = inputInt('sinceEvent');
    if ($sinceEvent === null) $sinceEvent = runLastEvent($runId);
    $move = engineAdvance($runId);
    $wait = max(0, min(STATE_WAIT_LIMIT, (int) (inputInt('wait') ?? 0)));

    if (!$move['did'] && $wait > 0) {
        $fresh = runRow($runId, $projectId);
        $since = inputInt('since') ?? (int) $fresh['version'];
        runStateWait($fresh, $since, $wait, $projectId);
        $move = engineAdvance($runId);
    }

    $out = runStateResponse($project, $runId, !empty(input('full')), $sinceEvent);
    reply($out);
}

/** POST run.update — сменить на ходу паузу показа, водителя, проверяющего или роль Jev. */
function runUpdate(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $runId = (int) $run['id'];

        $set = [];
        $args = [];
        if (input('pause') !== null) {
            $pause = max(0, min(999.9, round((float) input('pause'), 1)));
            $set[] = 'show_pause = ?';
            $args[] = $pause;
            // Ноль отпускает прогон сразу: ждать больше нечего.
            if ($pause <= 0) $set[] = 'next_move_at = NULL';
        }
        foreach (['driver' => ['manual', 'utility'], 'judge' => ['human', 'formal'],
                  'jev' => ['off', 'advisor', 'judge']] as $name => $allowed) {
            if (input($name) === null) continue;
            $set[] = "$name = ?";
            $args[] = runChoice($name, $allowed, (string) $run[$name]);
        }
        if (!$set) throw new ApiError(ta('agents.state.nothing_to_change'));

        $args[] = $runId;
        dbRun('UPDATE runs SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        runTouch($runId);

        return ['run' => runShape(runRow($runId, (int) $project['id']))];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/* ── Общее ────────────────────────────────────────────────────── */

/** Состояние и верхний event cursor из одного согласованного снимка БД. */
function runStateResponse(array $project, int $runId, bool $full, ?int $sinceEvent): array
{
    return dbTransaction(static function () use ($project, $runId, $full, $sinceEvent): array {
        $run = runRow($runId, (int) $project['id']);
        $out = runStateShape($project, $run, $full);
        $eventHead = runLastEvent($runId);
        $events = $sinceEvent === null
            ? ['did' => [], 'lastEvent' => $eventHead, 'hasMoreEvents' => false]
            : runDidPage($runId, $sinceEvent, $eventHead);
        $out['lastEvent'] = $events['lastEvent'];
        $out['hasMoreEvents'] = $events['hasMoreEvents'];
        $out['did'] = $events['did'];
        return $out;
    });
}

/** Прогон из запроса: пропуск leader знает свой, человек называет номер или ярлык. */
function runOfRequest(int $projectId): array
{
    $who = caller();
    $runId = $who['run_id'] ?: runAsked($projectId);
    if (!$runId) throw new ApiError(ta('agents.events.no_run'), 'not_found');
    return runRow((int) $runId, $projectId);
}

/**
 * Подождать, пока версия прогона вырастет.
 * Не дольше 25 секунд, не дольше `wait` и не дольше паузы показа: держать запрос сверх
 * паузы незачем — после неё движку всё равно нужен толчок.
 */
function runStateWait(array $run, int $since, int $wait, int $projectId): array
{
    $runId = (int) $run['id'];
    $wake  = advanceWake($run);
    $until = microtime(true) + min($wait, STATE_WAIT_LIMIT, $wake > 0 ? $wake : STATE_WAIT_LIMIT);

    while (microtime(true) < $until) {
        if ((int) dbValue('SELECT version FROM runs WHERE id = ?', [$runId]) > $since) break;
        if (connection_aborted()) exit;
        usleep(150000);
    }
    return runRow($runId, $projectId);
}

/** Снимок: сам прогон, открытые шаги, что можно открыть, когда будить. */
function runStateShape(array $project, array $run, bool $full): array
{
    $steps = dbAll('SELECT * FROM run_steps WHERE run_id = ? ORDER BY id', [(int) $run['id']]);
    $wake  = advanceWake($run);

    // Разбор сдачи видит leader и человек: worker ожидаемые значения знать нельзя.
    $forLead = callerRole() !== 'worker';
    // Граф папки грузим один раз: он нужен и списку «можно открыть», и ходу, и разбору сдачи.
    $graph = advanceOn($run) && in_array($run['state'], ['running', 'paused'], true)
        ? engineGraph((int) $run['folder_id']) : null;

    $open = [];
    foreach ($steps as $step) {
        if (!in_array($step['state'], ['issued', 'running', 'submitted'], true)) continue;
        $one = [
            'id'      => (int) $step['id'],
            'no'      => (int) $step['element_no'],
            'attempt' => (int) $step['attempt'],
            'state'   => (string) $step['state'],
            'agent'   => $step['agent_id'] ? (int) $step['agent_id'] : null,
            'title'   => (string) $step['element_title'],
        ];
        if ($step['state'] === 'submitted') {
            $one['result'] = (string) $step['result'];
            if ($forLead) {
                $graph ??= engineGraph((int) $run['folder_id']);
                $one['review'] = stepReview($project, $run, $step, $graph);
            }
        }
        $open[] = $one;
    }

    $out = [
        'run'    => runShape($run),
        // Жизнь прогона: выросла — был run.reset живого, прежняя картинка экрана не его (run/paint.js).
        'epoch'  => runEpoch((int) $run['id']),
        'open'   => $open,
        'ready'  => runReady($run, $steps, $graph),
        'wakeIn' => $wake > 0 ? $wake : null,
        // Read-only сигнал driver-у: persisted pause due либо queued semantic work.
        // Текст wait_for — только подпись для человека и на решение не влияет.
        'needsAdvance' => advanceNeedsRequest($project, $run, $steps, $graph),
        'now'    => dbNow(),
    ];
    if ($full) {
        // Исполнитель — тот же ответ, что у ленты времени (stepExecutor): панель шага своего правила не держит.
        $names = stepAgentNames($steps);
        $leadNow = stepLeadNow(dbRow('SELECT role_scheme, run_env FROM folders WHERE id = ?', [(int) $run['folder_id']]) ?: []);
        $out['steps'] = array_map(static fn(array $s) => [
            'id'      => (int) $s['id'],
            'no'      => (int) $s['element_no'],
            'attempt' => (int) $s['attempt'],
            'state'   => (string) $s['state'],
            'result'  => $s['result'] !== null ? (string) $s['result'] : null,
            'via'     => $s['via_edge_id'] ? (int) $s['via_edge_id'] : null,
            'chosen'  => $s['chosen_edge_id'] ? (int) $s['chosen_edge_id'] : null,
            'executor' => stepExecutor($s, $names, $leadNow),
        ] + (($s['who'] ?? '') !== '' ? ['who' => (string) $s['who']] : []), $steps);
    }
    return $out;
}

/**
 * Всё для приёмки одним взглядом: ТЗ, вход, переменные, результат, файлы и проверки.
 * Решение проверяющего берётся из шага; если его ещё нет — формальные проверки считаются
 * на месте (они ничего не пишут). worker это не отдаётся.
 */
function stepReview(array $project, array $run, array $step, array $graph): array
{
    $elementId = (int) $step['element_id'];
    $spec = $elementId ? specText('element_id', $elementId) : null;

    $files = dbAll(
        "SELECT a.title, a.uri, l.output FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.step_id = ? AND l.role = 'result' ORDER BY l.id",
        [(int) $step['id']]
    );

    $saved = judgeSaved($step) ?? judgeFormal($project, $run, $step, $graph);

    return [
        'spec'   => (string) ($spec['text'] ?? ''),
        'input'  => stepInputText($step),
        'vars'   => varsIn((int) $step['id'])['vars'],
        'result' => (string) $step['result'],
        'files'  => array_map(static fn(array $f) => [
            'name'   => basename((string) $f['uri']),
            'output' => (string) $f['output'],
        ], $files),
        'checks' => [
            'verdict'  => (string) ($saved['verdict'] ?? 'unsure'),
            'by'       => (string) ($saved['by'] ?? $run['judge']),
            'reasons'  => $saved['reasons'] ?? [],
            'lines'    => $saved['checks'] ?? [],
            // Ожидаемое значение видит только leader: worker подсказку не даём.
            'expected' => $saved['detail']['expr']['expected'] ?? ($saved['detail']['form']['expected'] ?? ''),
        ],
    ];
}
