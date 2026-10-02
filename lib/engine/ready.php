<?php
/* Что можно открыть прямо сейчас.
   Отдаёт: runReady(), readyList(), readyShape().
   Не делает: ничего не открывает и не пишет.

   Прогоны engine = 2 считаются по графу папки прогона и жетонам-строкам —
   несколько запросов на весь список. Старые прогоны (engine = 1) — прежним
   расчётом из engine/legacy.php. */

declare(strict_types=1);

/** Готовое к открытию по строке прогона. $steps и $graph — уже прочитанные, чтобы не читать их второй раз. */
function runReady(array $run, ?array $steps = null, ?array $graph = null): array
{
    // Закрытому прогону открывать нечего — граф и жетоны не считаем.
    if (!in_array($run['state'], ['running', 'paused'], true)) return [];
    if ((int) $run['engine'] !== 2) return marksReadyLegacy($run);
    return readyList($run, $graph ?? engineGraph((int) $run['folder_id']), $steps);
}

/**
 * Узлы папки, которые можно открыть: у них собрались входы по join,
 * или по возвратной стрелке лежит жетон, или это старт.
 */
function readyList(array $run, array $graph, ?array $steps = null): array
{
    $steps ??= dbAll('SELECT element_id, state FROM run_steps WHERE run_id = ?', [(int) $run['id']]);
    $free = marksFree((int) $run['id']);

    /* Живая попытка — открытая или принятая. Возвращённая, провалившаяся и отменённая
       не в счёт: стартовый блок после возврата снова можно открыть, иначе доработать
       первый блок схемы было бы нечем. */
    $had  = [];
    $open = [];
    foreach ($steps as $step) {
        $elementId = (int) $step['element_id'];
        if (in_array($step['state'], ['issued', 'running', 'submitted'], true)) $open[$elementId] = true;
        if (in_array($step['state'], ['issued', 'running', 'submitted', 'accepted'], true)) $had[$elementId] = true;
    }

    $ready = [];
    $entries = array_flip(graphEntries($graph));
    foreach ($graph['nodes'] as $id => $node) {
        if (isset($open[$id])) continue;              // уже открыт — второй раз нельзя
        $pick = marksChoose($node, $graph, $free, isset($had[$id]));
        // Без жетона открывается только вход схемы (graphEntries): при стартере — он один.
        if ($pick !== null && $pick['via'] === null && !isset($entries[$id])) continue;
        if ($pick !== null) $ready[] = readyShape($node, $pick['via'], $graph);
    }
    return $ready;
}

/**
 * Строка списка «что можно открыть».
 *
 * Кроме имени даёт тип (ромб решают, а не открывают), условие ромба из
 * свойства `cond` и номер блока, откуда пришёл жетон: по его результату
 * условие и считается.
 */
function readyShape(array $node, ?int $viaId, array $graph): array
{
    $id = (int) $node['id'];
    $row = [
        'no'    => (int) $node['no'],
        'id'    => $id,
        'type'  => (string) $node['type'],
        'title' => (string) $node['title'],
        'via'   => $viaId,
        'entry' => $viaId === null,
    ];

    if ($node['type'] === 'decision') {
        $row['cond'] = (string) graphProp($graph, $id, 'cond', '');
        $from = $viaId !== null ? (int) ($graph['edges'][$viaId]['from_id'] ?? 0) : 0;
        $row['from'] = $from ? (int) ($graph['nodes'][$from]['no'] ?? 0) : null;
    }
    return $row;
}
