<?php
/* Граф папки одной загрузкой: узлы прогона (блок, ромб, шлюз), стрелки и правила узлов.
   Отдаёт: engineGraph(), graphRules(), graphStarter(), graphEntries(), graphInLoop(), graphMaxAttempts(),
           graphProp(), graphIn(), graphOut().
   Не делает: не читает шаги и жетоны — только схему. Ярлыки (link) и рамки сюда не попадают.

   Одна форма схемы на всех: предпроверка, старт и ход читают правила и входы отсюда.
   Два запроса на папку вместо запроса на каждую стрелку: элементы и свойства. */

declare(strict_types=1);

/**
 * Граф одной папки.
 *   nodes — id узла → строка элемента + props (правила в своём виде, graphRules)
 *           + bad, если правило записано неверно: его называет предпроверка;
 *   edges — id стрелки → строка стрелки;
 *   in / out — id узла → список id стрелок, по возрастанию id.
 */
function engineGraph(int $folderId): array
{
    $rows = dbAll(
        "SELECT id, `no`, type, title, agent_id, from_id, to_id, branch, back, target_folder_id, folder_id
           FROM elements
          WHERE folder_id = ? AND type IN ('block','decision','gateway','arrow')
          ORDER BY id",
        [$folderId]
    );
    $props = propsMap($folderId);

    $graph = ['folder' => $folderId, 'nodes' => [], 'edges' => [], 'in' => [], 'out' => []];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if ($row['type'] === 'arrow') {
            $graph['edges'][$id] = $row;
            continue;
        }
        $row['props'] = graphRules($props[$id] ?? [], $bad);
        if ($bad) $row['bad'] = $bad;
        $graph['nodes'][$id] = $row;
        $graph['in'][$id] = [];
        $graph['out'][$id] = [];
    }

    // Стрелки раскладываем по концам. Конец не в этой папке — стрелку не видим.
    foreach ($graph['edges'] as $id => $edge) {
        $from = (int) $edge['from_id'];
        $to   = (int) $edge['to_id'];
        if (isset($graph['out'][$from])) $graph['out'][$from][] = $id;
        if (isset($graph['in'][$to]))    $graph['in'][$to][] = $id;
    }
    return $graph;
}

/**
 * Правила узла в одном виде — для предпроверки и для хода:
 *   start — флажок: включён только true, 1 или '1' (propOn), как в folderStarters;
 *   join  — all или any; другое значение правилом не считается: уходит в $bad,
 *           предпроверка называет его, а ход берёт значение по умолчанию (all).
 */
function graphRules(array $props, ?array &$bad = null): array
{
    $bad = [];
    if (array_key_exists('start', $props)) $props['start'] = propOn($props['start']);
    if (array_key_exists('join', $props) && !in_array($props['join'], ['all', 'any'], true)) {
        $bad['join'] = $props['join'];
        unset($props['join']);
    }
    return $props;
}

/** Стартер папки в графе (блок с флажком start) — id или null. */
function graphStarter(array $graph): ?int
{
    foreach ($graph['nodes'] as $id => $node) {
        if ($node['type'] === 'block' && ($node['props']['start'] ?? false) === true) return (int) $id;
    }
    return null;
}

/**
 * Входы схемы — одно правило для предпроверки, старта и хода (readyList).
 * Есть стартер — вход он и только он; нет — узлы без прямых входящих стрелок.
 * Отдаёт id узлов по возрастанию номера.
 */
function graphEntries(array $graph): array
{
    $starter = graphStarter($graph);
    if ($starter !== null) return [$starter];

    $out = [];
    foreach ($graph['nodes'] as $id => $node) {
        if (!graphIn($graph, (int) $id, false)) $out[(int) $node['no']] = (int) $id;
    }
    ksort($out);
    return array_values($out);
}

/** Предел выдач блока в круге без своего max_attempts: прогон не должен крутиться вечно. */
const LOOP_ATTEMPTS = 20;

/** Стоит ли узел в круге: по стрелкам (и возвратным) из него можно вернуться в него же. */
function graphInLoop(array $graph, int $id): bool
{
    $queue = [$id];
    $seen = [];
    while ($queue) {
        $node = array_shift($queue);
        foreach ($graph['out'][$node] ?? [] as $edgeId) {
            $to = (int) $graph['edges'][$edgeId]['to_id'];
            if ($to === $id) return true;
            if (isset($seen[$to])) continue;
            $seen[$to] = true;
            $queue[] = $to;
        }
    }
    return false;
}

/**
 * Предел выдач узла: свойство max_attempts; у узла в круге без него —
 * LOOP_ATTEMPTS (20). 0 — предела нет.
 */
function graphMaxAttempts(array $graph, int $id): int
{
    $max = (int) graphProp($graph, $id, 'max_attempts', 0);
    if ($max > 0) return $max;
    return graphInLoop($graph, $id) ? LOOP_ATTEMPTS : 0;
}

/** Правило узла с запасным значением — как runProp(), только без запроса. */
function graphProp(array $graph, int $nodeId, string $name, $fallback)
{
    $props = $graph['nodes'][$nodeId]['props'] ?? [];
    return array_key_exists($name, $props) ? $props[$name] : $fallback;
}

/**
 * Входящие стрелки узла.
 * $back: null — все, false — только прямые, true — только возвратные.
 */
function graphIn(array $graph, int $nodeId, ?bool $back = null): array
{
    $out = [];
    foreach ($graph['in'][$nodeId] ?? [] as $edgeId) {
        $isBack = (int) $graph['edges'][$edgeId]['back'] === 1;
        if ($back === null || $back === $isBack) $out[] = $edgeId;
    }
    return $out;
}

/** Выходящие стрелки узла — все, включая возвратные. */
function graphOut(array $graph, int $nodeId): array
{
    return $graph['out'][$nodeId] ?? [];
}
