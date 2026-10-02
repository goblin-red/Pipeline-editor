<?php
/* Ход движка: сделать всё, что можно сделать без человека.
   Отдаёт: engineAdvance(), advanceAfter(), advanceOn(), advanceLoad(), advanceLive().
   `advanceRun()` не спит и не ходит в сеть: это одна транзакция под замком проекта.
   `engineAdvance()` оркестрирует Jev двумя короткими lock-секциями, а сеть вызывает между ними.

   За один ход, по порядку, пока есть что делать (не больше 50 действий, Jev — не больше ADVANCE_JEV_LIMIT за запрос):
     1. прогон не идёт — ничего; человек попросил остановиться — отменить открытые шаги;
     2. пауза показа не истекла — ничего, wakeIn = сколько осталось;
     3. приёмка сданного — решает проверяющий прогона (runs.judge);
     4. ромбы с условием — по переменным входа;
     5. выдача готовых блоков;
     6. конец прогона — если пройден.
   Чего ждёт прогон — строкой в runs.wait_for. Версия растёт только при настоящем изменении.

   Ход идёт у всех прогонов engine = 2 при любом водителе (manual и utility): водитель только
   просит ход, правила здесь. Прогоны engine = 1 движок не трогает. Браузер хода не делает.
   После команды (advanceAfter) ход только детерминированный, без сети; Jev зовёт run.advance. */

declare(strict_types=1);

/** Больше действий за один ход не делаем: защита от бесконечного круга. */
const ADVANCE_LIMIT = 50;

/** Больше Jev-запросов подряд за один запрос к серверу не делаем: каждый — сеть, до нескольких секунд. */
const ADVANCE_JEV_LIMIT = 5;

/**
 * Делает ли движок ход в этом прогоне. Одно место на всё условие.
 *
 * Ход идёт у всех новых прогонов (engine = 2) независимо от водителя: при ручном ведении
 * движок делает ромбы и выдачу после действий человека, приёмку — по проверяющему прогона.
 * Старые прогоны (engine = 1) движок не трогает.
 */
function advanceOn(array $run): bool
{
    return (int) ($run['engine'] ?? 1) === 2;
}

/**
 * Ход после команды — своей транзакцией, уже после её фиксации.
 * Сбой хода не отменяет сделанную команду: он пишется в лог и в ответ.
 * Отдаёт итог хода или null, если движку делать было нечего.
 */
function advanceAfter(int $runId): ?array
{
    try {
        // Команда submit/start/accept не ждёт внешнюю сеть. Она делает только
        // bounded deterministic ход; Jev подхватит явный driver run.advance.
        $out = engineAdvance($runId, false);
    } catch (Throwable $e) {
        error_log('[goblin] ход движка: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['did' => [], 'waitFor' => '', 'wakeIn' => null, 'error' => ta('agents.advance.engine_failed') . $e->getMessage()];
    }
    return ($out['did'] || $out['waitFor'] !== '' || $out['wakeIn'] !== null) ? $out : null;
}

/**
 * Сделать ход. Отдаёт ['did' => [...], 'waitFor' => '…', 'wakeIn' => ?секунд].
 * $depth — сколько Jev-запросов цепочка уже сделала; на ADVANCE_JEV_LIMIT Jev отпускает запрос,
 * остальное доделает следующий ход (needsAdvance его обещает).
 */
function engineAdvance(int $runId, bool $allowJev = true, int $depth = 0): array
{
    $projectId = (int) dbValue('SELECT project_id FROM runs WHERE id = ?', [$runId]);
    if (!$projectId) return ['did' => [], 'waitFor' => '', 'wakeIn' => null];
    $out = engineLocked($projectId, static fn(): array => advanceRun($runId, $projectId, $allowJev));
    if ($allowJev && !empty($out['pendingJev'])) {
        $after = advanceJevComplete($runId, (array) $out['pendingJev'], $depth);
        $out['did'] = array_merge($out['did'] ?? [], $after['did'] ?? []);
        $out['waitFor'] = (string) ($after['waitFor'] ?? $out['waitFor']);
        $out['wakeIn'] = $after['wakeIn'] ?? $out['wakeIn'];
        unset($out['pendingJev']);
    }
    return $out;
}

/** Сам детерминированный ход — только под замком; наружу может выйти безопасный pendingJev ref. */
function advanceRun(int $runId, int $projectId, bool $allowJev = true): array
{
    $project = dbRow('SELECT * FROM projects WHERE id = ?', [$projectId]);
    $did     = [];
    $wakeIn  = null;
    $changed = false;
    $pending = null;

    for ($n = 0; $n < ADVANCE_LIMIT; $n++) {
        $run = dbRow('SELECT * FROM runs WHERE id = ?', [$runId]);
        if (!$run || !advanceOn($run) || $run['state'] !== 'running') break;

        // 1. Человек попросил остановиться.
        if ($run['stop_at'] !== null) {
            runStopDo($run, 'script', ta('agents.advance.ran_out'));
            $did[] = ['kind' => 'stop'];
            break;
        }

        // 2. Пауза показа: раньше срока ход не делаем.
        $wait = advanceWake($run);
        if ($wait > 0) { $wakeIn = $wait; break; }
        if ($run['next_move_at'] !== null) {
            // Persisted due token погашается ровно тем ходом, который его обрабатывает.
            // GET только сообщает о нём и никогда не пишет.
            dbRun('UPDATE runs SET next_move_at = NULL WHERE id = ? AND next_move_at IS NOT NULL', [$runId]);
            $run['next_move_at'] = null;
            $changed = true;
        }

        // 3–5. Одно действие за круг, затем всё перечитываем заново.
        $ctx  = advanceLoad($run);
        $step = advanceJudge($project, $run, $ctx, true, $allowJev);
        $changed = $changed || $step['changed'];
        if (!empty($step['pendingJev'])) { $pending = $step['pendingJev']; break; }
        $decision = $step['did'] === null ? advanceDecide($run, $ctx, true, $allowJev) : ['did' => null];
        if (!empty($decision['pendingJev'])) { $pending = $decision['pendingJev']; break; }
        $act  = $step['did'] ?? $decision['did'] ?? advanceIssue($run, $ctx, true)['did'];
        if ($act === null) break;
        $did[] = $act;
    }

    // Итог: пройден ли прогон и чего он ждёт.
    $run = dbRow('SELECT * FROM runs WHERE id = ?', [$runId]);
    $waitFor = (string) ($run['wait_for'] ?? '');
    if ($run && advanceOn($run) && $run['state'] === 'running' && $run['stop_at'] === null) {
        $ctx   = advanceLoad($run);
        $ready = readyList($run, $ctx['graph'], $ctx['steps']);
        $fin   = finishCheck($run, $ctx['graph'], $ctx['steps'], $ctx['free'], $ready);

        if ($fin['done'] && $wakeIn === null) {
            $did[] = advanceFinish($run, $ctx);
            $waitFor = '';
        } elseif (!$fin['done']) {
            $waits = array_merge(
                advanceJudge($project, $run, $ctx, false, $allowJev)['waits'],
                advanceDecide($run, $ctx, false, $allowJev)['waits'],
                advanceIssue($run, $ctx, false)['waits']
            );
            $waitFor = $waits ? implode('; ', $waits) : $fin['why'];
            if ($waitFor === '' && $wakeIn !== null) $waitFor = ta('agents.advance.show_pause');
        }
    }

    // Строка «чего ждёт» — тоже изменение для тех, кто смотрит.
    $waitFor = mb_substr($waitFor, 0, 255);
    if ($run && $waitFor !== (string) $run['wait_for']) {
        dbRun('UPDATE runs SET wait_for = ? WHERE id = ?', [$waitFor, $runId]);
        $changed = true;
    }
    if ($changed && !$did) runTouch($runId);

    return ['did' => $did, 'waitFor' => $waitFor, 'wakeIn' => $wakeIn,
            ...($pending ? ['pendingJev' => $pending] : [])];
}

/** Сколько секунд осталось до конца паузы показа (0 — пауза прошла или её нет). */
function advanceWake(array $run): float
{
    if (!$run['next_move_at']) return 0.0;
    $left = (float) dbValue('SELECT TIMESTAMPDIFF(MICROSECOND, NOW(3), ?) / 1000000', [$run['next_move_at']]);
    return $left > 0 ? round($left, 3) : 0.0;
}

/** Всё, что нужно для хода: граф папки прогона, шаги, лежащие жетоны. */
function advanceLoad(array $run): array
{
    return [
        'graph' => engineGraph((int) $run['folder_id']),
        'steps' => dbAll('SELECT * FROM run_steps WHERE run_id = ? ORDER BY id', [(int) $run['id']]),
        'free'  => marksFree((int) $run['id']),
    ];
}

/**
 * Read-only обещание driver-у: один POST run.advance сейчас имеет работу.
 * Используется run.state; ничего не резервирует и не меняет.
 */
function advanceNeedsRequest(array $project, array $run, ?array $steps = null, ?array $graph = null): bool
{
    if ($run['state'] !== 'running' || !advanceOn($run)) return false;
    if ($run['next_move_at'] !== null) return advanceWake($run) <= 0;

    $steps ??= dbAll('SELECT * FROM run_steps WHERE run_id = ? ORDER BY id', [(int) $run['id']]);
    $graph ??= engineGraph((int) $run['folder_id']);

    foreach ($steps as $step) {
        if ($step['state'] !== 'submitted') continue;
        $saved = judgeSaved($step);
        if (in_array((string) ($saved['verdict'] ?? ''), ['accept', 'return'], true)) return true;
        if (($saved['verdict'] ?? '') === 'pending') {
            if ((int) ($saved['details']['until'] ?? 0) < time()) return true;
            continue;
        }
        if (!in_array((string) ($run['jev'] ?? 'off'), ['advisor', 'judge'], true)) {
            if (!$saved && (string) ($run['judge'] ?? 'human') === 'formal') {
                $formal = judgeFormal($project, $run, $step, $graph);
                if (in_array((string) $formal['verdict'], ['accept', 'return'], true)) return true;
            }
            continue;
        }
        $formal = judgeFormal($project, $run, $step, $graph);
        $plan = judgeJevPlan($run, $step, judgePackage($run, $step, $graph), [], $formal);
        if ($plan['call']) {
            $print = (string) ($saved['details']['fingerprint'] ?? '');
            if (!$saved || $print !== (string) $plan['fingerprint']) return true;
        } elseif (!$saved && in_array((string) ($plan['result']['verdict'] ?? ''), ['accept', 'return'], true)) {
            return true;
        }
    }

    if ((string) ($run['jev'] ?? 'off') !== 'judge') return false;
    foreach (readyList($run, $graph, $steps) as $item) {
        if ($item['type'] !== 'decision') continue;
        // Вопроса модели нет (число, нет данных, слишком длинно) — работы для хода нет, ромб ждёт человека.
        $current = advanceJevBranchPlanCurrent((int) $run['id'], (int) $item['id']);
        if (!$current) continue;
        $fp = (string) $current['plan']['fingerprint'];
        $done = advanceJevBranchEvent((int) $run['id'], (int) $item['no'], 'jev-say', $fp);
        if ($done) {
            $meta = json_decode((string) $done['meta'], true) ?: [];
            if (in_array((string) ($meta['verdict'] ?? ''), ['yes', 'no'], true)) return true;
            continue;
        }
        $pending = advanceJevBranchEvent((int) $run['id'], (int) $item['no'], 'jev-ask', $fp);
        if (!$pending) return true;
        $meta = json_decode((string) $pending['meta'], true) ?: [];
        if ((int) ($meta['until'] ?? 0) < time()) return true;
    }
    return false;
}

/** Узлы, у которых есть живая попытка (открыта или принята): им старт уже не положен. */
function advanceLive(array $steps): array
{
    $live = [];
    foreach ($steps as $step) {
        if (in_array($step['state'], ['issued', 'running', 'submitted', 'accepted'], true)) {
            $live[(int) $step['element_id']] = true;
        }
    }
    return $live;
}

/* ── 3. Приёмка ───────────────────────────────────────────────── */

/**
 * Сданные шаги: решение проверяющего. $act = false — только собрать, чего ждём.
 * Отдаёт ['did' => ?действие, 'waits' => [...], 'changed' => записано ли новое решение].
 */
function advanceJudge(array $project, array $run, array $ctx, bool $act, bool $allowJev = true): array
{
    $waits   = [];
    $changed = false;

    foreach ($ctx['steps'] as $step) {
        if ($step['state'] !== 'submitted') continue;
        $address = $step['element_no'] . '.' . $step['attempt'];

        $saved = judgeSaved($step);
        $jevMode = in_array((string) ($run['jev'] ?? 'off'), ['advisor', 'judge'], true);
        $plan = null;

        if ($jevMode) {
            $package = judgePackage($run, $step, $ctx['graph']);
            $formal = judgeFormal($project, $run, $step, $ctx['graph']);
            $plan = judgeJevPlan($run, $step, $package, [], $formal);

            // Jev-решение годится лишь для того же exact snapshot и режима.
            $savedPrint = (string) ($saved['details']['fingerprint'] ?? '');
            if ($saved && $plan['call'] && $savedPrint === '') {
                dbRun('UPDATE run_steps SET verdict = NULL WHERE id = ?', [(int) $step['id']]);
                $saved = null;
                $changed = true;
            }
            if ($saved && !$plan['call'] && $savedPrint === ''
                && ($saved['verdict'] ?? '') !== ($plan['result']['verdict'] ?? '')) {
                dbRun('UPDATE run_steps SET verdict = NULL WHERE id = ?', [(int) $step['id']]);
                $saved = null;
                $changed = true;
            }
            if ($savedPrint !== '' && (!$plan['call'] || $savedPrint !== (string) ($plan['fingerprint'] ?? ''))) {
                dbRun('UPDATE run_steps SET verdict = NULL WHERE id = ?', [(int) $step['id']]);
                $saved = null;
                $changed = true;
            }

            if ($saved && ($saved['verdict'] ?? '') === 'pending') {
                $until = (int) ($saved['details']['until'] ?? 0);
                if ($savedPrint === (string) ($plan['fingerprint'] ?? '') && $until >= time()) {
                    $waits[] = ta('agents.advance.jev_checking', ['address' => $address]);
                    continue;
                }
                // Оборванный владелец запроса не порождает бесконечные повторы.
                $saved = judgeSave($step, judgeJevOut('unavailable', [ta('agents.advance.jev_interrupted')], [
                    'formal' => $formal, 'role' => (string) $run['jev'], 'fingerprint' => $savedPrint,
                    'why' => 'expired',
                ]), 'jev-' . (string) $run['jev']);
                $changed = true;
            }

            if (!$saved && $plan['call']) {
                if (!$act || !$allowJev) { $waits[] = ta('agents.advance.wait_jev', ['address' => $address]); continue; }
                $nonce = bin2hex(random_bytes(12));
                $pendingVerdict = judgeJevOut('pending', [ta('agents.advance.jev_checking_short')], [
                    'formal' => $formal, 'role' => (string) $run['jev'],
                    'fingerprint' => $plan['fingerprint'], 'nonce' => $nonce,
                    'until' => time() + 10, 'source' => 'engine-jev-step-v1',
                ]);
                judgeSave($step, $pendingVerdict, 'jev-' . (string) $run['jev']);
                eventStep($step, 'jev-ask', ta('agents.advance.ev_jev_ask_step', ['address' => $address]), [
                    'state' => $plan['state'], 'questions' => $plan['questions'],
                ], ['actor' => 'judge', 'meta' => [
                    'source' => 'engine-jev-step-v1', 'status' => 'pending',
                    'fingerprint' => $plan['fingerprint'], 'nonce' => $nonce,
                ]]);
                return ['did' => null, 'waits' => [], 'changed' => true, 'pendingJev' => [
                    'scope' => 'step', 'step' => (int) $step['id'],
                    'fingerprint' => $plan['fingerprint'], 'nonce' => $nonce,
                ]];
            }

            if (!$saved && !$plan['call']) {
                $saved = judgeSave($step, $plan['result'], (string) $run['judge']);
                $changed = true;
            }
        } elseif ($saved && str_starts_with((string) ($saved['by'] ?? ''), 'jev-')) {
            dbRun('UPDATE run_steps SET verdict = NULL WHERE id = ?', [(int) $step['id']]);
            $saved = null;
            $changed = true;
        }

        if (!$saved) {
            if (!$act) { $waits[] = ta('agents.advance.wait_check', ['address' => $address]); continue; }
            $saved = judgeSave($step, judgeReview($project, $run, $step, $ctx['graph']), (string) $run['judge']);
            $changed = true;
        }

        $why = implode('; ', $saved['reasons'] ?? []);
        if ($act && $saved['verdict'] === 'accept') {
            stepAcceptDo($run, $step, $why, 'judge');
            return ['did' => ['kind' => 'accept', 'step' => $address, 'by' => $saved['by'], 'why' => $why],
                    'waits' => [], 'changed' => true];
        }
        if ($act && $saved['verdict'] === 'return') {
            stepReturnDo($run, $step, $why, 'judge');
            return ['did' => ['kind' => 'return', 'step' => $address, 'by' => $saved['by'], 'why' => $why],
                    'waits' => [], 'changed' => true];
        }
        if (in_array($saved['verdict'], ['accept', 'return'], true)) continue;

        $note = ($saved['by'] ?? '') === 'human' ? '' : ($saved['reasons'][0] ?? '');
        $waits[] = ta('agents.advance.wait_accept', ['address' => $address]) . ($note !== '' ? " ($note)" : '');
    }
    return ['did' => null, 'waits' => $waits, 'changed' => $changed, 'pendingJev' => null];
}

/* ── 4. Ромбы ─────────────────────────────────────────────────── */

/** Готовые ромбы с условием: посчитать и выбрать ветку. Словесное и без входа — ждёт человека. */
function advanceDecide(array $run, array $ctx, bool $act, bool $allowJev = true): array
{
    $graph = $ctx['graph'];
    $live  = advanceLive($ctx['steps']);
    $waits = [];

    foreach (readyList($run, $graph, $ctx['steps']) as $item) {
        if ($item['type'] !== 'decision') continue;
        $id   = (int) $item['id'];
        $no   = $item['no'];
        $node = $graph['nodes'][$id];

        $lead = advanceLeadRun($run);
        $cond = trim((string) graphProp($graph, $id, 'cond', ''));
        // Прогон leader спрашивает Jev и по ТЗ ромба — условие-формула не обязательно.
        if ($cond === '' && !$lead) { $waits[] = ta('agents.advance.wait_decision', ['no' => $no]); continue; }

        $pick = marksChoose($node, $graph, $ctx['free'], isset($live[$id]));
        if ($pick === null) continue;
        $in = varsFromMarks($pick['marks']);
        // У прогона leader числа не в ходу: разобранные из текста, они не повод стоять.
        if ($in['clash'] && !$lead) { $waits[] = ta('agents.advance.clash', ['no' => $no, 'list' => implode(', ', array_keys($in['clash']))]); continue; }

        // Условие и текст ответа на входе: для слов «содержит …» и для вопроса Jev.
        $ask = advanceBranchQuestion($run, $graph, $id, $pick['marks']);
        $cond = $ask['cond'];
        $text = $ask['data'];
        if ($cond === '') { $waits[] = ta('agents.advance.wait_decision_nocond', ['no' => $no]); continue; }

        // Прогон leader формулу не считает: ответ worker бывает свободным текстом.
        $ev = $lead ? ['status' => 'verbal'] : condEval($cond, $in['vars'], $text);
        if ($ev['status'] === 'verbal') {
            if ((string) ($run['jev'] ?? 'off') !== 'judge') {
                $waits[] = ta('agents.advance.wait_decision', ['no' => $no]);
                continue;
            }
            $jev = advanceJevBranch($run, $graph, $item, $node, $pick, $cond, $text, $act, $allowJev);
            if (!empty($jev['pendingJev'])) return $jev;
            if ($jev['did'] !== null) return $jev;
            $waits = array_merge($waits, $jev['waits']);
            continue;
        }
        if ($ev['status'] !== 'ok')     { $waits[] = ta('agents.advance.decision_why', ['no' => $no, 'why' => $ev['why']]); continue; }
        if (!$act) continue;

        $branch = $ev['value'] ? 'yes' : 'no';
        $arrow = null;
        foreach (graphOut($graph, $id) as $edgeId) {
            if ((string) $graph['edges'][$edgeId]['branch'] === $branch) { $arrow = $graph['edges'][$edgeId]; break; }
        }
        if (!$arrow) { $waits[] = ta('agents.advance.no_exit', ['no' => $no, 'branch' => ta($branch === 'yes' ? 'agents.advance.yes' : 'agents.advance.no')]); continue; }

        $element = dbRow('SELECT * FROM elements WHERE id = ?', [$id]);
        $made = stepDecideDo($run, $element, $arrow, $branch, $ev['why'], $pick, 'script');
        return ['did' => ['kind' => 'decide', 'step' => $made['element_no'] . '.' . $made['attempt'],
                          'branch' => $branch, 'why' => $ev['why']], 'waits' => []];
    }
    return ['did' => null, 'waits' => $waits];
}

/**
 * Прогон простого пути (driver = lead): ромбы решает только Jev.
 *
 * Ответ worker — свободный текст, не всегда «a=6». Формула движка по нему
 * ошибётся молча, поэтому здесь её не считаем вовсе: Jev получает задание
 * ромба и ответ worker и говорит «да» или «нет». Не уверен или недоступен —
 * ромб ждёт leader.
 */
function advanceLeadRun(array $run): bool
{
    return (string) ($run['driver'] ?? '') === 'lead';
}

/**
 * Что спросить у Jev о ромбе: ['cond' => условие, 'data' => ответ worker].
 * Одна функция и для хода, и для сверки ответа Jev: по ним считается
 * отпечаток запроса, и они обязаны совпадать до буквы.
 */
function advanceBranchQuestion(array $run, array $graph, int $id, array $marks): array
{
    $cond = trim((string) graphProp($graph, $id, 'cond', ''));
    $fromIds = array_map(static fn(array $m) => (int) $m['from_step_id'], $marks);

    if (!advanceLeadRun($run)) {
        $data = $fromIds ? (string) dbValue('SELECT result FROM run_steps WHERE id = ?', [$fromIds[0]]) : '';
        return ['cond' => $cond, 'data' => $data];
    }

    // Условия-формулы нет — задание ромба в его ТЗ, а пустое — в описании.
    if ($cond === '') $cond = taskText($id);
    $answers = array_map(static fn(array $one) => ta('agents.advance.answer_row', ['no' => $one['no'], 'title' => $one['title'], 'result' => $one['result']]),
        stepInputRows($fromIds));
    return ['cond' => $cond, 'data' => implode("\n", $answers)];
}

/** Словесный ромб: exact marks snapshot, server-only cache и резервирование одного запроса. */
function advanceJevBranch(array $run, array $graph, array $item, array $node, array $pick,
                          string $condition, string $data, bool $act, bool $allowJev = true): array
{
    $identity = [
        'scope' => 'branch', 'run' => (int) $run['id'], 'element' => (int) $item['id'],
        'mode' => (string) $run['jev'],
        'marks' => array_map(static fn(array $m): array => [
            'id' => (int) $m['id'], 'edge' => (int) $m['edge_id'],
            'fromStep' => (int) $m['from_step_id'], 'pass' => (int) $m['pass'],
        ], $pick['marks']),
    ];
    $plan = judgeJevBranchPlan($condition, $data, [], $identity);
    if (!$plan['call']) return ['did' => null, 'waits' => $plan['result']['reasons']];

    $cached = advanceJevBranchEvent((int) $run['id'], (int) $item['no'], 'jev-say', $plan['fingerprint']);
    if ($cached) {
        $meta = json_decode((string) $cached['meta'], true) ?: [];
        $verdict = (string) ($meta['verdict'] ?? 'unsure');
        if ($act && in_array($verdict, ['yes', 'no'], true)) {
            $arrow = null;
            foreach (graphOut($graph, (int) $item['id']) as $edgeId) {
                if ((string) $graph['edges'][$edgeId]['branch'] === $verdict) {
                    $arrow = $graph['edges'][$edgeId];
                    break;
                }
            }
            if (!$arrow) return ['did' => null, 'waits' => [ta('agents.advance.no_exit_jev', ['no' => $item['no']])]];
            $element = dbRow('SELECT * FROM elements WHERE id = ?', [(int) $item['id']]);
            $made = stepDecideDo($run, $element, $arrow, $verdict,
                (string) ($meta['reason'] ?? ta('agents.advance.jev_ruling')), $pick, 'judge');
            return ['did' => ['kind' => 'decide', 'step' => $made['element_no'] . '.' . $made['attempt'],
                              'branch' => $verdict, 'why' => (string) ($meta['reason'] ?? ta('agents.advance.jev_ruling'))],
                    'waits' => []];
        }
        return ['did' => null, 'waits' => [ta('agents.advance.decision_why', ['no' => $item['no'], 'why' => (string) ($meta['reason'] ?? ta('agents.advance.jev_unsure'))])],
                'cachedBranch' => $verdict, 'branchPlan' => $plan];
    }

    $pending = advanceJevBranchEvent((int) $run['id'], (int) $item['no'], 'jev-ask', $plan['fingerprint']);
    if ($pending) {
        $meta = json_decode((string) $pending['meta'], true) ?: [];
        if ((int) ($meta['until'] ?? 0) >= time()) {
            return ['did' => null, 'waits' => [ta('agents.advance.jev_deciding', ['no' => $item['no']])]];
        }
        eventAdd([
            'project_id' => (int) $run['project_id'], 'run_id' => (int) $run['id'],
            'element_no' => (int) $item['no'], 'kind' => 'jev-say', 'actor' => 'judge',
            'title' => ta('agents.advance.ev_jev_cut', ['no' => $item['no']]),
            'meta' => ['source' => 'engine-jev-branch-v1', 'status' => 'done',
                       'fingerprint' => $plan['fingerprint'], 'verdict' => 'unavailable',
                       'reason' => ta('agents.advance.jev_interrupted')],
        ]);
        return ['did' => null, 'waits' => [ta('agents.advance.decision_why', ['no' => $item['no'], 'why' => ta('agents.advance.jev_interrupted')])]];
    }
    if (!$act || !$allowJev) return ['did' => null, 'waits' => [ta('agents.advance.wait_jev_decision', ['no' => $item['no']])]];

    $nonce = bin2hex(random_bytes(12));
    $event = eventAdd([
        'project_id' => (int) $run['project_id'], 'run_id' => (int) $run['id'],
        'element_no' => (int) $item['no'], 'kind' => 'jev-ask', 'actor' => 'judge',
        'title' => ta('agents.advance.ev_jev_ask_decision', ['no' => $item['no']]), 'body' => ['state' => $plan['state'], 'questions' => $plan['questions']],
        'meta' => ['source' => 'engine-jev-branch-v1', 'status' => 'pending',
                   'fingerprint' => $plan['fingerprint'], 'nonce' => $nonce, 'until' => time() + 10],
    ]);
    return ['did' => null, 'waits' => [], 'pendingJev' => [
        'scope' => 'branch', 'event' => $event, 'element' => (int) $item['id'], 'no' => (int) $item['no'],
        'fingerprint' => $plan['fingerprint'], 'nonce' => $nonce,
    ]];
}

/** Только server-authored actor=judge события могут быть авторитетным cache. */
function advanceJevBranchEvent(int $runId, int $no, string $kind, string $fingerprint): ?array
{
    $rows = dbAll(
        'SELECT * FROM run_events WHERE run_id = ? AND element_no = ? AND kind = ? AND actor = ? ORDER BY id DESC LIMIT 20',
        [$runId, $no, $kind, 'judge']
    );
    foreach ($rows as $row) {
        $meta = json_decode((string) $row['meta'], true);
        if (($meta['source'] ?? '') !== 'engine-jev-branch-v1'
            || ($meta['fingerprint'] ?? '') !== $fingerprint) continue;
        if ($kind === 'jev-say') {
            if (($meta['status'] ?? '') === 'done') return $row;
            continue;
        }
        // Pending ask, который уже закрыл именно его stale/done marker, больше не claim.
        $resolved = dbAll(
            "SELECT meta FROM run_events WHERE run_id = ? AND element_no = ? AND kind = 'jev-say' AND actor = 'judge' AND id > ? ORDER BY id DESC LIMIT 20",
            [$runId, $no, (int) $row['id']]
        );
        $closed = false;
        foreach ($resolved as $said) {
            $one = json_decode((string) $said['meta'], true);
            if (($one['source'] ?? '') === 'engine-jev-branch-v1'
                && (int) ($one['resolvesEvent'] ?? 0) === (int) $row['id']) { $closed = true; break; }
        }
        if (!$closed) return $row;
    }
    return null;
}

/* ── 5. Выдача ────────────────────────────────────────────────── */

/**
 * Готовые блоки: выдать worker. Не выдаём, если нужен человек:
 * последняя попытка провалена или отменена, два автовозврата подряд,
 * нет агента или ТЗ, кончились попытки. Шлюз всегда ждёт человека.
 */
function advanceIssue(array $run, array $ctx, bool $act): array
{
    $graph = $ctx['graph'];
    $live  = advanceLive($ctx['steps']);
    $waits = [];

    foreach (readyList($run, $graph, $ctx['steps']) as $item) {
        $id = (int) $item['id'];
        $no = $item['no'];
        if ($item['type'] === 'gateway') { $waits[] = ta('agents.advance.gateway_waits', ['no' => $no]); continue; }
        if ($item['type'] !== 'block') continue;

        // Стартер worker не выдаётся: принимается сразу, жетон уходит по его стрелкам.
        if (graphStarter($graph) === $id) {
            if (!$act) continue;
            $pick = marksChoose($graph['nodes'][$id], $graph, $ctx['free'], false);
            if ($pick === null) continue;
            $step = stepStarterDo($run, dbRow('SELECT * FROM elements WHERE id = ?', [$id]), $pick, 'script');
            return ['did' => ['kind' => 'accept', 'step' => $no . '.' . $step['attempt'], 'starter' => true], 'waits' => []];
        }

        $tries = array_values(array_filter($ctx['steps'], static fn(array $s) => (int) $s['element_id'] === $id));
        usort($tries, static fn(array $a, array $b) => (int) $b['attempt'] <=> (int) $a['attempt']);
        $last = $tries[0] ?? null;

        if ($last && in_array($last['state'], ['failed', 'cancelled'], true)) {
            $word = ta($last['state'] === 'failed' ? 'agents.advance.word_failed' : 'agents.advance.word_cancelled');
            $waits[] = ta('agents.advance.need_person', ['task' => "{$no}.{$last['attempt']}", 'word' => $word]);
            continue;
        }
        if (advanceAutoReturns($tries) >= 2) { $waits[] = ta('agents.advance.two_returns', ['no' => $no]); continue; }

        $node    = $graph['nodes'][$id];
        /* Карточка агента — подсказка. worker на карточке (и среда с карточками) —
           шаг ему; нет worker или стоит ведущий — шаг без агента, делает ведущий. */
        $cards   = folderUsesAgentCards((int) $run['folder_id']);
        $agentId = $cards && $node['agent_id'] ? (int) $node['agent_id'] : 0;
        $role    = $agentId ? (string) dbValue('SELECT role FROM agents WHERE id = ?', [$agentId]) : '';
        if ($role !== 'worker') $agentId = 0;

        $spec = specText('element_id', $id);
        if (taskText($id) === '') { $waits[] = ta('agents.advance.block_no_spec', ['no' => $no]); continue; }

        $attempt = stepNextAttempt((int) $run['id'], (int) $no);
        $max = graphMaxAttempts($graph, $id);
        if (stepOverLimit((int) $run['id'], (int) $no, $max)) { $waits[] = ta('agents.advance.block_over_limit', ['no' => $no, 'max' => $max]); continue; }

        if (!$act) continue;

        $pick = marksChoose($node, $graph, $ctx['free'], isset($live[$id]));
        if ($pick === null) continue;
        $element = dbRow('SELECT * FROM elements WHERE id = ?', [$id]);
        $step = stepIssue($run, $element, $pick, $agentId ?: null, $spec, $attempt, '', 'script');
        $agent = (string) dbValue('SELECT name FROM agents WHERE id = ?', [$agentId]);
        return ['did' => ['kind' => 'open', 'step' => $no . '.' . $step['attempt'], 'agent' => $agent], 'waits' => []];
    }
    return ['did' => null, 'waits' => $waits];
}

/** Сколько последних попыток подряд вернул проверяющий сам. */
function advanceAutoReturns(array $triesNewestFirst): int
{
    $count = 0;
    foreach ($triesNewestFirst as $step) {
        if ($step['state'] !== 'returned') break;
        $verdict = $step['verdict'] ? json_decode((string) $step['verdict'], true) : null;
        if (($verdict['verdict'] ?? '') !== 'return') break;
        $count++;
    }
    return $count;
}

/* ── Jev вне project lock ────────────────────────────────────── */

/**
 * Завершить один зарезервированный Jev review. jevCall всегда выполняется между двумя lock-секциями.
 * Второй водитель видит pending nonce и сеть повторно не вызывает.
 */
function advanceJevComplete(int $runId, ?array $pending = null, int $depth = 0): array
{
    $empty = ['did' => [], 'waitFor' => '', 'wakeIn' => null];
    if (!$pending) return $empty;
    $projectId = (int) dbValue('SELECT project_id FROM runs WHERE id = ?', [$runId]);
    if (!$projectId) return $empty;

    $plan = engineLocked($projectId, static function () use ($runId, $pending): ?array {
        if (($pending['scope'] ?? '') === 'step') {
            $current = advanceJevStepPlanCurrent($runId, (int) ($pending['step'] ?? 0));
            if (!$current || !$current['plan']['call']) return null;
            $saved = judgeSaved($current['step']);
            if (($saved['verdict'] ?? '') !== 'pending'
                || ($saved['details']['nonce'] ?? '') !== ($pending['nonce'] ?? '')
                || ($saved['details']['fingerprint'] ?? '') !== $current['plan']['fingerprint']) return null;
            return $current['plan'];
        }
        if (($pending['scope'] ?? '') === 'branch') {
            $current = advanceJevBranchPlanCurrent($runId, (int) ($pending['element'] ?? 0));
            if (!$current) { advanceJevDropPending($pending); return null; }
            $event = dbRow('SELECT * FROM run_events WHERE id = ? AND run_id = ? AND kind = ? AND actor = ?',
                [(int) ($pending['event'] ?? 0), $runId, 'jev-ask', 'judge']);
            $meta = $event ? json_decode((string) $event['meta'], true) : null;
            if (!is_array($meta) || ($meta['source'] ?? '') !== 'engine-jev-branch-v1'
                || ($meta['nonce'] ?? '') !== ($pending['nonce'] ?? '')
                || ($meta['fingerprint'] ?? '') !== $current['plan']['fingerprint']) {
                advanceJevDropPending($pending);
                return null;
            }
            return $current['plan'] + ['item' => $current['item']];
        }
        return null;
    });
    if (!$plan) return $empty;

    // ВАЖНО: между lock-секциями. Интеграционная проверка утверждает !dbInTransaction().
    $said = jevCall($plan['state'], $plan['questions'], (float) $plan['settings']['deadline']);

    $stored = engineLocked($projectId, static function () use ($runId, $pending, $plan, $said): bool {
        $run = dbRow('SELECT * FROM runs WHERE id = ? AND project_id = ?', [$runId, (int) $plan['run']['project_id']]);
        if (!$run || $run['state'] !== 'running' || (string) ($run['jev'] ?? 'off') !== (string) $plan['role']) {
            advanceJevDropPending($pending);
            return false;
        }

        if (($pending['scope'] ?? '') === 'step') {
            $current = advanceJevStepPlanCurrent($runId, (int) $pending['step']);
            if (!$current || !$current['plan']['call']
                || $current['plan']['fingerprint'] !== $plan['fingerprint']) {
                advanceJevDropPending($pending);
                return false;
            }
            $saved = judgeSaved($current['step']);
            if (($saved['verdict'] ?? '') !== 'pending'
                || ($saved['details']['nonce'] ?? '') !== ($pending['nonce'] ?? '')) return false;

            $verdict = judgeJevResolve($current['plan'], $said);
            judgeSave($current['step'], $verdict, 'jev-' . (string) $run['jev']);
            eventStep($current['step'], 'jev-say', ta('agents.advance.ev_jev_say_step', ['task' => $current['step']['element_no'] . '.' . $current['step']['attempt']]),
                ['verdict' => $verdict['verdict'], 'reasons' => $verdict['reasons'],
                 'lines' => $verdict['details']['lines'] ?? []],
                ['actor' => 'judge', 'took_ms' => (int) ($verdict['details']['ms'] ?? 0), 'meta' => [
                    'source' => 'engine-jev-step-v1', 'status' => 'done',
                    'fingerprint' => $plan['fingerprint'], 'verdict' => $verdict['verdict'],
                ]]);
            runTouch($runId);
            return true;
        }

        $current = advanceJevBranchPlanCurrent($runId, (int) $pending['element']);
        if (!$current || $current['plan']['fingerprint'] !== $plan['fingerprint']) {
            advanceJevDropPending($pending);
            return false;
        }
        $verdict = judgeJevBranchResolve($current['plan'], $said);
        eventAdd([
            'project_id' => (int) $run['project_id'], 'run_id' => $runId,
            'element_no' => (int) $current['item']['no'], 'kind' => 'jev-say', 'actor' => 'judge',
            'title' => ta('agents.advance.ev_jev_say_decision', ['no' => $current['item']['no'], 'verdict' => $verdict['verdict']]),
            'body' => ['verdict' => $verdict['verdict'], 'reasons' => $verdict['reasons']],
            'took_ms' => (int) ($verdict['details']['ms'] ?? 0),
            'meta' => ['source' => 'engine-jev-branch-v1', 'status' => 'done',
                       'fingerprint' => $plan['fingerprint'], 'verdict' => $verdict['verdict'],
                       'reason' => implode('; ', $verdict['reasons']),
                       // Уверенность Jev 0…1 — для ленты времени (run.timeline).
                       'confidence' => $verdict['details']['answer']['confidence'] ?? null,
                       'nonce' => (string) ($pending['nonce'] ?? ''),
                       'resolvesEvent' => (int) ($pending['event'] ?? 0)],
        ]);
        runTouch($runId);
        return true;
    });

    return $stored ? engineAdvance($runId, $depth + 1 < ADVANCE_JEV_LIMIT, $depth + 1) : $empty;
}

/** Текущий exact plan сдачи; вызывать только под project lock. */
function advanceJevStepPlanCurrent(int $runId, int $stepId): ?array
{
    $run = dbRow('SELECT * FROM runs WHERE id = ?', [$runId]);
    $step = dbRow('SELECT * FROM run_steps WHERE id = ? AND run_id = ?', [$stepId, $runId]);
    if (!$run || !$step || $run['state'] !== 'running' || $step['state'] !== 'submitted') return null;
    $project = dbRow('SELECT * FROM projects WHERE id = ?', [(int) $run['project_id']]);
    $graph = engineGraph((int) $run['folder_id']);
    $formal = judgeFormal($project, $run, $step, $graph);
    $package = judgePackage($run, $step, $graph);
    return ['run' => $run, 'step' => $step,
            'plan' => judgeJevPlan($run, $step, $package, [], $formal)];
}

/**
 * Текущий exact plan словесного ромба; marks identity не даёт переиграть старый цикл.
 * Только план с вопросом модели (call): без него отпечатка нет и спрашивать некого — null.
 */
function advanceJevBranchPlanCurrent(int $runId, int $elementId): ?array
{
    $run = dbRow('SELECT * FROM runs WHERE id = ?', [$runId]);
    if (!$run || $run['state'] !== 'running' || (string) $run['jev'] !== 'judge') return null;
    $ctx = advanceLoad($run);
    $graph = $ctx['graph'];
    $item = null;
    foreach (readyList($run, $graph, $ctx['steps']) as $one) {
        if ((int) $one['id'] === $elementId && $one['type'] === 'decision') { $item = $one; break; }
    }
    if (!$item) return null;
    $node = $graph['nodes'][$elementId];
    $pick = marksChoose($node, $graph, $ctx['free'], isset(advanceLive($ctx['steps'])[$elementId]));
    if ($pick === null) return null;
    $vars = varsFromMarks($pick['marks']);
    if ($vars['clash'] && !advanceLeadRun($run)) return null;
    // Тот же вопрос, что задал ход (advanceDecide): отпечатки обязаны совпасть.
    $ask = advanceBranchQuestion($run, $graph, $elementId, $pick['marks']);
    $cond = $ask['cond'];
    $data = $ask['data'];
    if ($cond === '') return null;
    if (!advanceLeadRun($run) && condEval($cond, $vars['vars'], $data)['status'] !== 'verbal') return null;
    $identity = [
        'scope' => 'branch', 'run' => $runId, 'element' => $elementId, 'mode' => 'judge',
        'marks' => array_map(static fn(array $m): array => [
            'id' => (int) $m['id'], 'edge' => (int) $m['edge_id'],
            'fromStep' => (int) $m['from_step_id'], 'pass' => (int) $m['pass'],
        ], $pick['marks']),
    ];
    $plan = judgeJevBranchPlan($cond, $data, [], $identity);
    if (!$plan['call']) return null;
    return ['run' => $run, 'item' => $item, 'node' => $node, 'pick' => $pick, 'graph' => $graph,
            'plan' => $plan + ['run' => $run, 'role' => 'judge']];
}

/** Убрать только свой pending marker при stale/mode/state смене. */
function advanceJevDropPending(array $pending): void
{
    if (($pending['scope'] ?? '') === 'step' && !empty($pending['step'])) {
        $step = dbRow('SELECT * FROM run_steps WHERE id = ?', [(int) $pending['step']]);
        $saved = $step ? judgeSaved($step) : null;
        if (($saved['verdict'] ?? '') === 'pending'
            && ($saved['details']['nonce'] ?? '') === ($pending['nonce'] ?? '')) {
            dbRun('UPDATE run_steps SET verdict = NULL WHERE id = ?', [(int) $pending['step']]);
            dbRun('UPDATE runs SET wait_for = ? WHERE id = ?', ['', (int) $step['run_id']]);
            runTouch((int) $step['run_id']);
        }
    }
    if (($pending['scope'] ?? '') === 'branch' && !empty($pending['event'])) {
        $event = dbRow('SELECT * FROM run_events WHERE id = ? AND kind = ? AND actor = ?',
            [(int) $pending['event'], 'jev-ask', 'judge']);
        $meta = $event ? json_decode((string) $event['meta'], true) : null;
        if (!is_array($meta) || ($meta['source'] ?? '') !== 'engine-jev-branch-v1'
            || ($meta['nonce'] ?? '') !== ($pending['nonce'] ?? '')) return;
        $resolved = dbAll(
            "SELECT meta FROM run_events WHERE run_id = ? AND element_no = ? AND kind = 'jev-say' AND actor = 'judge' ORDER BY id DESC LIMIT 20",
            [(int) $event['run_id'], (int) $event['element_no']]
        );
        foreach ($resolved as $row) {
            $one = json_decode((string) $row['meta'], true);
            if (($one['source'] ?? '') === 'engine-jev-branch-v1'
                && (int) ($one['resolvesEvent'] ?? 0) === (int) $event['id']) return;
        }
        eventAdd([
            'project_id' => (int) $event['project_id'], 'run_id' => (int) $event['run_id'],
            'element_no' => (int) $event['element_no'], 'kind' => 'jev-say', 'actor' => 'judge',
            'title' => ta('agents.advance.ev_jev_stale'),
            'meta' => ['source' => 'engine-jev-branch-v1', 'status' => 'stale',
                       'fingerprint' => (string) ($meta['fingerprint'] ?? ''),
                       'nonce' => (string) $meta['nonce'], 'resolvesEvent' => (int) $event['id'],
                       'verdict' => 'stale'],
        ]);
        runTouch((int) $event['run_id']);
    }
}

/* ── 6. Конец ─────────────────────────────────────────────────── */

/** Прогон пройден — закрыть его. */
function advanceFinish(array $run, array $ctx): array
{
    $runId = (int) $run['id'];
    $taken = count(array_filter($ctx['steps'], static fn(array $s) => $s['state'] === 'accepted'));
    $summary = ta('agents.advance.summary_passed', ['n' => $taken]);

    runSetState($runId, 'done', (int) $run['project_id'], $summary);
    runTouch($runId);
    tokenRevokeFor('run_id', $runId);
    runFinishEvent($run, 'script', 'done');
    return ['kind' => 'finish', 'why' => $summary];
}
