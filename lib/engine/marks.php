<?php
/* Жетоны: «по этой стрелке можно идти».
   Отдаёт: stepsOpenCount(), marksFree(), marksChoose(), marksTake(), marksPut(), marksRelease(),
           marksHeirs(), marksHeirsError(), marksDrop(), marksEnter(), marksGive(), marksReconcile().
   Не делает: не решает, принят ли шаг, и не открывает шаги — только ведёт жетоны.

   Жетон — строка run_marks (прогоны engine = 2): положили при приёмке, забрали
   при выдаче, вернули при возврате, отмене и провале. Поэтому залп переходов
   невозможен: второй шаг просто не на чем открыть. Старые прогоны (engine = 1)
   считают жетоны прежним способом — engine/legacy.php.

   Всё здесь зовётся только под замком проекта (engine/lock.php). */

declare(strict_types=1);

/** Сколько незакрытых шагов у элемента в этом прогоне. */
function stepsOpenCount(int $runId, int $elementId): int
{
    return (int) dbValue(
        "SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND element_id = ? AND state IN ('issued','running','submitted')",
        [$runId, $elementId]
    );
}

/* ── Жетоны строками ─────────────────────────────────────────────
   Жетон — строка run_marks. Положили при приёмке, забрали при выдаче,
   вернули при возврате, отмене и провале. Всё — только под замком проекта. */

/** Лежащие жетоны прогона: id стрелки → строки жетонов, старшие первыми. */
function marksFree(int $runId): array
{
    $out = [];
    $rows = dbAll('SELECT * FROM run_marks WHERE run_id = ? AND taken_by_step_id IS NULL ORDER BY id', [$runId]);
    foreach ($rows as $row) $out[(int) $row['edge_id']][] = $row;
    return $out;
}

/**
 * Какими жетонами открыть узел. Одно правило для выдачи, ромба, шлюза и списка «можно открыть».
 * Отдаёт ['via' => id стрелки входа или null, 'marks' => строки жетонов] либо null — открыть нечем.
 *
 *   • join=all (по умолчанию) — по жетону с каждого прямого входа, все разом;
 *   • любое другое join — один жетон, старший из лежащих на прямых входах;
 *   • возвратная стрелка работает сама по себе, независимо от join;
 *   • старт — прямых входов нет и шагов у узла в прогоне не было: жетоны не нужны.
 */
function marksChoose(array $node, array $graph, array $free, bool $had, ?int $via = null): ?array
{
    $id     = (int) $node['id'];
    $direct = graphIn($graph, $id, false);
    $back   = graphIn($graph, $id, true);
    $all    = (string) graphProp($graph, $id, 'join', 'all') === 'all';

    // Старший лежащий жетон стрелки или null.
    $top = static fn(int $edgeId): ?array => $free[$edgeId][0] ?? null;
    // Старший из нескольких жетонов.
    $oldest = static function (array $marks): ?array {
        $best = null;
        foreach ($marks as $mark) {
            if ($mark && (!$best || (int) $mark['id'] < (int) $best['id'])) $best = $mark;
        }
        return $best;
    };

    // Жетоны прямых входов: хватает ли их по join.
    $lying = [];
    foreach ($direct as $edgeId) {
        if ($mark = $top($edgeId)) $lying[$edgeId] = $mark;
    }
    $byDirect = null;
    if ($direct && $all && count($lying) === count($direct)) $byDirect = array_values($lying);
    if ($direct && !$all && $lying) $byDirect = [$oldest($lying)];

    // Вход назван явно — открываем только через него.
    if ($via !== null) {
        if (in_array($via, $back, true)) {
            return $top($via) ? ['via' => $via, 'marks' => [$top($via)]] : null;
        }
        if (!isset($lying[$via])) return null;
        if ($all) return $byDirect ? ['via' => $via, 'marks' => $byDirect] : null;
        return ['via' => $via, 'marks' => [$lying[$via]]];
    }

    if ($byDirect) return ['via' => (int) $byDirect[0]['edge_id'], 'marks' => $byDirect];

    $byBack = $oldest(array_map($top, $back));
    if ($byBack) return ['via' => (int) $byBack['edge_id'], 'marks' => [$byBack]];

    if (!$direct && !$had) return ['via' => null, 'marks' => []];
    return null;
}

/** Забрать выбранные жетоны новым шагом. Один жетон дважды не забрать. */
function marksTake(int $stepId, array $marks): void
{
    foreach ($marks as $mark) {
        $took = dbRun(
            'UPDATE run_marks SET taken_by_step_id = ?, taken_at = NOW(3) WHERE id = ? AND taken_by_step_id IS NULL',
            [$stepId, (int) $mark['id']]
        );
        if ($took !== 1) throw new ApiError(ta('agents.marks.taken'), 'conflict', ['code2' => 'no_mark']);
    }
}

/**
 * Положить жетоны на выходы принятого шага: у ромба — только на выбранную ветку.
 * Повтор для того же шага ничего не добавляет (ключ run_id, edge_id, from_step_id).
 * pass — сколько жетонов уже бывало на этой стрелке в прогоне, плюс один.
 * Отдаёт id стрелок, на которые жетон лёг сейчас.
 */
function marksPut(array $run, array $step, array $graph): array
{
    $nodeId = (int) ($step['element_id'] ?? 0);
    $node = $graph['nodes'][$nodeId] ?? null;
    if (!$node) return [];

    $edges = $node['type'] === 'decision'
        ? ($step['chosen_edge_id'] ? [(int) $step['chosen_edge_id']] : [])
        : graphOut($graph, $nodeId);

    $runId = (int) $run['id'];
    $put = [];
    foreach ($edges as $edgeId) {
        if (!isset($graph['edges'][$edgeId])) continue;      // стрелку успели удалить

        $was = dbValue('SELECT id FROM run_marks WHERE run_id = ? AND edge_id = ? AND from_step_id = ?',
            [$runId, $edgeId, (int) $step['id']]);
        if ($was) continue;

        $pass = 1 + (int) dbValue('SELECT COUNT(*) FROM run_marks WHERE run_id = ? AND edge_id = ?', [$runId, $edgeId]);
        dbRun(
            'INSERT INTO run_marks (project_id, run_id, edge_id, from_step_id, pass) VALUES (?,?,?,?,?)',
            [(int) $run['project_id'], $runId, $edgeId, (int) $step['id'], $pass]
        );
        $put[] = $edgeId;
    }
    return $put;
}

/** Шаг закрыт без приёмки (возврат, отмена, провал) — его жетоны снова лежат на стрелках. */
function marksRelease(int $stepId): int
{
    return dbRun('UPDATE run_marks SET taken_by_step_id = NULL, taken_at = NULL WHERE taken_by_step_id = ?', [$stepId]);
}

/**
 * Шаги, построенные на результате этих шагов: забрали положенные ими жетоны.
 * Сами шаги набора не в счёт. Отдаёт строки шагов-потомков по порядку.
 */
function marksHeirs(array $stepIds): array
{
    if (!$stepIds) return [];
    $in = implode(',', array_fill(0, count($stepIds), '?'));
    return dbAll(
        "SELECT DISTINCT s.* FROM run_marks m JOIN run_steps s ON s.id = m.taken_by_step_id
          WHERE m.from_step_id IN ($in) AND m.taken_by_step_id NOT IN ($in)
          ORDER BY s.id",
        array_merge($stepIds, $stepIds)
    );
}

/** Отказ «сначала сбросьте 36.2 и 35.3 — они построены на этом результате». */
function marksHeirsError(array $heirs): ApiError
{
    $names = array_map(static fn(array $s) => $s['element_no'] . '.' . $s['attempt'], $heirs);
    $list = count($names) > 1
        ? implode(', ', array_slice($names, 0, -1)) . ta('agents.marks.and') . end($names)
        : $names[0];
    return new ApiError(ta(count($names) > 1 ? 'agents.marks.heirs_many' : 'agents.marks.heirs_one', ['list' => $list]), 'conflict',
        ['code2' => 'has_heirs', 'steps' => $names]);
}

/** Снять жетоны, которые положил шаг: его приёмку отменили руками. Забранные — отказ. */
function marksDrop(int $stepId): void
{
    $heirs = marksHeirs([$stepId]);
    if ($heirs) throw marksHeirsError($heirs);
    dbRun('DELETE FROM run_marks WHERE from_step_id = ?', [$stepId]);
}

/**
 * Вход нового шага: выбрать жетоны или отказать «нет жетона».
 * Старые прогоны (engine = 1) — прежним расчётом, жетонов-строк у них нет.
 */
function marksEnter(array $run, array $element, ?int $via): array
{
    if ((int) $run['engine'] !== 2) {
        return ['via' => markTakeLegacy((int) $run['id'], $element, $via), 'marks' => []];
    }

    $graph = engineGraph((int) $element['folder_id']);
    $node  = $graph['nodes'][(int) $element['id']] ?? null;
    // Живая попытка — открытая или принятая: после возврата или провала старт снова открыт.
    $had   = (bool) dbValue(
        "SELECT 1 FROM run_steps WHERE run_id = ? AND element_id = ?
           AND state IN ('issued','running','submitted','accepted') LIMIT 1",
        [(int) $run['id'], (int) $element['id']]);
    $pick  = $node ? marksChoose($node, $graph, marksFree((int) $run['id']), $had, $via) : null;

    if ($pick === null) {
        throw new ApiError($via !== null
            ? ta('agents.marks.no_mark')
            : ta('agents.marks.nothing_to_open'), 'conflict', ['code2' => 'no_mark']);
    }
    return $pick;
}

/** Шаг принят, ромб решён или шлюз пройден — жетоны на выходы. У старых прогонов жетонов-строк нет. */
function marksGive(array $run, array $step): array
{
    if ((int) $run['engine'] !== 2 || !$step['element_id']) return [];
    $folderId = (int) dbValue('SELECT folder_id FROM elements WHERE id = ?', [(int) $step['element_id']]);
    return marksPut($run, $step, engineGraph($folderId));
}

/**
 * Человек поставил шагу состояние руками — жетоны приводим в согласие:
 *   ушёл из «принят» — положенные им жетоны снимаются (уже забранные — отказ);
 *   закрыт без приёмки — забранные им жетоны возвращаются на стрелки;
 *   открыт или принят, а входа не держит — берёт то, что лежит (если лежит);
 *   принят — кладёт жетоны на выходы.
 */
function marksReconcile(array $run, array $step, string $was, string $now): void
{
    if ((int) $run['engine'] !== 2) return;
    $stepId = (int) $step['id'];
    $holds = static fn(string $s): bool => in_array($s, ['issued', 'running', 'submitted', 'accepted'], true);

    if ($was === 'accepted' && $now !== 'accepted') marksDrop($stepId);
    if (!$holds($now)) {
        marksRelease($stepId);
        return;
    }

    $held = (int) dbValue('SELECT COUNT(*) FROM run_marks WHERE taken_by_step_id = ?', [$stepId]);
    if (!$held && $step['element_id']) {
        $folderId = (int) dbValue('SELECT folder_id FROM elements WHERE id = ?', [(int) $step['element_id']]);
        $graph = engineGraph($folderId);
        $node  = $graph['nodes'][(int) $step['element_id']] ?? null;
        $pick  = $node ? marksChoose($node, $graph, marksFree((int) $run['id']), true) : null;
        if ($pick && $pick['marks']) {
            marksTake($stepId, $pick['marks']);
            dbRun('UPDATE run_steps SET via_edge_id = ? WHERE id = ?', [$pick['via'], $stepId]);
        }
    }
    if ($now === 'accepted') {
        marksGive($run, dbRow('SELECT * FROM run_steps WHERE id = ?', [$stepId]));
    }
}
