<?php
/* Конец прогона: пройден ли он, а если нет — почему стоит.
   Отдаёт: finishCheck().
   Не делает: не закрывает прогон — это движок (engine/advance.php) и run.finish.

   Прогон пройден, когда выполнено всё сразу:
     1. нет открытых шагов (issued, running, submitted);
     2. нет лежащих жетонов — иначе застряло слияние;
     3. принят хотя бы один конечный блок или реальным step.pass принят конечный шлюз;
     4. у каждого блока/шлюза, который в прогоне открывался, последняя попытка принята —
        иначе провалена или брошена ветка, не оставившая жетона. */

declare(strict_types=1);

/**
 * Итог по прогону (engine = 2).
 * $ready — что можно открыть сейчас (readyList).
 * Отдаёт ['done' => bool, 'dead' => bool, 'why' => '…']:
 *   dead — открытых шагов нет и открыть нечего, а прогон не пройден.
 */
function finishCheck(array $run, array $graph, array $steps, array $free, array $ready): array
{
    $open = array_filter($steps, static fn(array $s) => in_array($s['state'], ['issued', 'running', 'submitted'], true));
    if ($open) return ['done' => false, 'dead' => false, 'why' => ''];

    // Последняя попытка каждого узла.
    $last = [];
    foreach ($steps as $step) {
        $id = (int) $step['element_id'];
        if (!$id) continue;
        if (!isset($last[$id]) || (int) $step['attempt'] > (int) $last[$id]['attempt']) $last[$id] = $step;
    }

    $dead = !$ready;
    $whys = [];

    // 4. Работы и шлюзы, чья последняя попытка не принята.
    $words = ['failed' => ta('agents.advance.word_failed'), 'returned' => ta('agents.finish.word_returned'), 'cancelled' => ta('agents.advance.word_cancelled')];
    foreach ($last as $id => $step) {
        $type = (string) ($graph['nodes'][$id]['type'] ?? '');
        if (!in_array($type, ['block', 'gateway'], true) || $step['state'] === 'accepted') continue;
        $whys[] = "{$step['element_no']}.{$step['attempt']} " . ($words[$step['state']] ?? $step['state']);
    }

    // 2. Лежащие жетоны: кому их не хватает.
    if ($free) $whys[] = finishStuck($graph, $free);

    // 3. Конечный блок либо terminal gateway принят последней попыткой?
    // Целевая папка здесь не исполняется: step.pass завершает только текущую.
    $ended = false;
    foreach ($graph['nodes'] as $id => $node) {
        $terminal = !graphOut($graph, $id)
            && ($node['type'] === 'block'
                || ($node['type'] === 'gateway' && !empty($node['target_folder_id'])));
        if (!$terminal || ($last[$id]['state'] ?? '') !== 'accepted') continue;
        $ended = true;
        break;
    }
    if (!$ended) $whys[] = ta('agents.finish.no_end');

    if (!$whys) return ['done' => true, 'dead' => false, 'why' => ''];
    return ['done' => false, 'dead' => $dead, 'why' => ($dead ? ta('agents.finish.dead_end') : '') . implode('; ', $whys)];
}

/** Лежащий жетон, который некому забрать: «блоку 41 не хватает входа от 39». */
function finishStuck(array $graph, array $free): string
{
    foreach ($graph['nodes'] as $id => $node) {
        $direct = graphIn($graph, $id, false);
        $lying  = array_filter($direct, static fn(int $edge) => !empty($free[$edge]));
        if (!$lying || count($lying) === count($direct)) continue;

        $missing = array_diff($direct, $lying);
        $from = array_map(static fn(int $edge) => (string) ($graph['nodes'][(int) $graph['edges'][$edge]['from_id']]['no'] ?? '?'), $missing);
        return ta('agents.finish.lacks_input', ['no' => $node['no'], 'from' => implode(', ', $from)]);
    }

    // Жетон лежит, а слияние ни при чём — назовём стрелку.
    $edgeId = (int) array_key_first($free);
    $edge   = $graph['edges'][$edgeId] ?? null;
    $to     = $edge ? ($graph['nodes'][(int) $edge['to_id']]['no'] ?? '?') : '?';
    return ta('agents.finish.mark_lies', ['to' => $to]);
}
