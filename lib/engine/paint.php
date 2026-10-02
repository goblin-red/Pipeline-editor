<?php
/* Картинка прогона: что на холсте как горит. Считает сервер, клиент только рисует.
   Отдаёт: runPaint(), runPaintShape(), runEpoch(), paintCovers(), paintWait().
   Не делает: ничего не меняет и не решает — только смотрит на шаги и жетоны.
   Договор с экраном — scratchpad/impl/paint-contract.md и md_backend/ru/progon.md.

   Узел (блок, ромб, шлюз, стартер) — одна строка:
     state   — состояние последней попытки; `ready` — жетоны собраны, работа ещё не выдана
               (попыток не было или последняя принята), нет строки — узел не тронут;
     attempt — номер последней попытки (адрес N.K), round — сколько проходов принято,
     lap     — круг текущей работы: у принятой — round, у остальных и у ready — round + 1;
     wait    — у ready: чего ждёт (pause | decision | pass | person);
     cover   — картинка-результат этого прогона у блока (id ассета): видна и после подготовки папки.
   Стрелка:
     lit  — на ней лежит жетон: бегущий пунктир, `pass` — какой это проход;
     done — жетоны были и все забраны: сплошная, при `pass` > 1 метка «×N»;
     `passed` — сколько жетонов по ней уже прошло (забрано); обычная стрелка в ответ не попадает.
   Один снимок базы: version, epoch и картинка согласованы.

   Прогоны на старом учёте (engine = 1) отдают engine = 1 и пустые списки:
   их подсветку клиент рисует по-прежнему, из шагов. */

declare(strict_types=1);

/** GET run.paint — что горит на холсте. */
function runPaint(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $runId = (int) runOfRequest($projectId)['id'];
    reply(dbTransaction(static fn(): array => runPaintShape(runRow($runId, $projectId))));
}

/** Картинка прогона. Звать внутри транзакции: версия и строки — из одного снимка. */
function runPaintShape(array $run): array
{
    $runId = (int) $run['id'];
    $answer = [
        'run'     => $runId,
        'engine'  => (int) $run['engine'],
        'state'   => (string) $run['state'],
        'version' => (int) $run['version'],
        'epoch'   => runEpoch($runId),
        'pause'   => (float) $run['show_pause'],
        'now'     => dbNow(),
        'blocks'  => [],
        'edges'   => [],
    ];
    if ((int) $run['engine'] !== 2) return $answer;

    $steps = dbAll('SELECT * FROM run_steps WHERE run_id = ? ORDER BY id', [$runId]);
    $marks = dbAll('SELECT * FROM run_marks WHERE run_id = ? ORDER BY id', [$runId]);
    $graph = engineGraph((int) $run['folder_id']);

    $answer['blocks'] = paintBlocks($run, $steps, $graph, runReady($run, $steps, $graph));
    $answer['edges']  = paintEdges($marks);
    return $answer;
}

/**
 * Жизнь прогона: сколько раз его сбрасывали целиком (run.reset живого).
 * Тот же id и rN, но прежняя картинка и очередь показа больше не его.
 */
function runEpoch(int $runId): int
{
    return (int) dbValue("SELECT COUNT(*) FROM run_events WHERE run_id = ? AND kind = 'reset' AND element_no IS NULL",
        [$runId]);
}

/** Узлы: последняя попытка каждого элемента, сколько его проходов принято и готовые к открытию. */
function paintBlocks(array $run, array $steps, array $graph, array $ready): array
{
    $covers = paintCovers((int) $run['id']);
    $last = [];
    $round = [];
    foreach ($steps as $step) {
        $id = (int) $step['element_id'];
        if (!$id) continue;
        if ($step['state'] === 'accepted') $round[$id] = ($round[$id] ?? 0) + 1;
        if (!isset($last[$id]) || (int) $step['attempt'] >= (int) $last[$id]['attempt']) $last[$id] = $step;
    }
    $waiting = [];
    foreach ($ready as $item) $waiting[(int) $item['id']] = (int) $item['no'];

    $out = [];
    foreach (array_keys($last + $waiting) as $id) {
        $step = $last[$id] ?? null;
        // Готов, но не выдан: новый круг ждёт работы. Возврат, провал и отмена важнее — их видно.
        $state = isset($waiting[$id]) && (!$step || $step['state'] === 'accepted') ? 'ready' : (string) $step['state'];
        $done = $round[$id] ?? 0;
        $one = [
            'id'      => $id,
            'no'      => $step ? (int) $step['element_no'] : $waiting[$id],
            'kind'    => paintKind($graph, $id),
            'state'   => $state,
            'attempt' => $step ? (int) $step['attempt'] : 0,
            'round'   => $done,
            'lap'     => $done + ($state === 'accepted' ? 0 : 1),
            'at'      => $step ? ($step['finished_at'] ?? $step['submitted_at'] ?? $step['started_at'] ?? $step['opened_at']) : null,
            'result'  => $step && $step['result'] !== null ? (string) $step['result'] : null,
        ];
        if ($state === 'ready') $one['wait'] = paintWait($run, $graph, $id);
        if (isset($covers[$id])) $one['cover'] = $covers[$id];
        if ($state === 'accepted' && ($branch = paintBranch($graph, $step))) $one['branch'] = $branch;
        if (in_array($state, ['returned', 'failed', 'cancelled'], true) && (string) $step['error'] !== '') {
            $one['error'] = mb_substr((string) $step['error'], 0, 200);
        }
        $out[] = $one;
    }
    return $out;
}

/**
 * Чего ждёт готовый узел — код для экрана, без разбора текста waitFor:
 *   pause    — ход движка впереди: прогон на паузе, идёт пауза показа или ближайший ход;
 *   decision — ромб ждёт решения (Jev, ведущий или человек);
 *   pass     — шлюз ждёт step.pass;
 *   person   — блок движок сам не выдаст: нет ТЗ или кончились попытки.
 */
function paintWait(array $run, array $graph, int $id): string
{
    if ($run['state'] === 'paused' || $run['next_move_at'] !== null) return 'pause';
    $node = $graph['nodes'][$id] ?? null;
    if (!$node) return 'person';
    if ($node['type'] === 'decision') return 'decision';
    if ($node['type'] === 'gateway') return 'pass';
    if (graphStarter($graph) === $id) return 'pause';
    // Те же причины, по которым advanceIssue не выдаёт готовый блок.
    $stuck = taskText($id) === ''
        || stepOverLimit((int) $run['id'], (int) $node['no'], graphMaxAttempts($graph, $id));
    return $stuck ? 'person' : 'pause';
}

/**
 * Картинки-результаты прогона: id элемента → id ассета. Та же картинка, что при приёмке
 * становится обложкой (stepCoverToElement): последняя картинка последней принятой попытки.
 * Живёт в шагах, поэтому подготовка папки (снятие обложек) её у старого прогона не отнимает.
 */
function paintCovers(int $runId): array
{
    $rows = dbAll(
        "SELECT s.element_id, l.asset_id, a.uri, a.file_key FROM asset_links l
           JOIN assets a ON a.id = l.asset_id
           JOIN run_steps s ON s.id = l.step_id
          WHERE s.run_id = ? AND s.state = 'accepted' AND l.role = 'result' AND " . STEP_PICTURE_SQL . "
          ORDER BY s.attempt, l.id",
        [$runId]
    );
    $out = [];
    foreach ($rows as $row) {
        if ($row['element_id'] === null) continue;
        // Картинка у агента в вебе: браузеру нужен её путь — он возьмёт её из подключённой папки.
        $out[(int) $row['element_id']] = assetAtAgentUri($row['file_key'], $row['uri'])
            ? ['asset' => (int) $row['asset_id'], 'uri' => (string) $row['uri'], 'agent' => true]
            : (int) $row['asset_id'];
    }
    return $out;
}

/** Вид узла для экрана: starter | block | decision | gateway. */
function paintKind(array $graph, int $id): string
{
    if (graphStarter($graph) === $id) return 'starter';
    return (string) ($graph['nodes'][$id]['type'] ?? 'block');
}

/** Ветка решённого ромба: yes | no по выбранной стрелке, иначе null. */
function paintBranch(array $graph, array $step): ?string
{
    $chosen = (int) ($step['chosen_edge_id'] ?? 0);
    $branch = $chosen ? (string) ($graph['edges'][$chosen]['branch'] ?? '') : '';
    return in_array($branch, ['yes', 'no'], true) ? $branch : null;
}

/** Стрелки: лежит жетон — горит; все забраны — пройдена. */
function paintEdges(array $marks): array
{
    $onEdge = [];
    foreach ($marks as $mark) $onEdge[(int) $mark['edge_id']][] = $mark;

    $out = [];
    foreach ($onEdge as $edgeId => $rows) {
        $free = array_values(array_filter($rows, static fn(array $m) => $m['taken_by_step_id'] === null));
        $passed = count($rows) - count($free);
        if ($free) {
            $newest = $free[count($free) - 1];
            $out[] = ['id' => $edgeId, 'state' => 'lit', 'pass' => (int) $newest['pass'], 'passed' => $passed,
                      'at' => $newest['created_at']];
            continue;
        }
        $taken = array_filter(array_column($rows, 'taken_at'));
        $out[] = ['id' => $edgeId, 'state' => 'done', 'pass' => count($rows), 'passed' => $passed,
                  'at' => $taken ? max($taken) : null];
    }
    return $out;
}
