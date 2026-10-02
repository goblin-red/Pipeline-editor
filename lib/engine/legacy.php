<?php
/* Старый учёт жетонов — для прогонов engine = 1, заведённых до движка.
   Отдаёт: marksReadyLegacy(), markTakeLegacy(), marksOnLegacy(), markReadyOnLegacy(), readyShapeLegacy().
   Не делает: ничего не хранит — жетон вычисляется из принятых шагов, как было до run_marks.

   Код перенесён из прежнего runs/marks.php без изменений поведения, только имена
   с суффиксом Legacy. Новые прогоны (engine = 2) сюда не ходят: у них жетоны
   строками в run_marks (engine/marks.php). Файл можно удалить, когда старые
   прогоны перестанут открывать. */

declare(strict_types=1);

/**
 * Жетоны, лежащие сейчас на входящих стрелках элемента.
 * Возвращает список id стрелок.
 */
function marksOnLegacy(int $runId, int $elementId): array
{
    $arrows = dbAll(
        "SELECT * FROM elements WHERE type = 'arrow' AND to_id = ? ORDER BY id",
        [$elementId]
    );
    $out = [];
    foreach ($arrows as $arrow) {
        if (markReadyOnLegacy($runId, $arrow)) $out[] = (int) $arrow['id'];
    }
    return $out;
}

/** Есть ли неистраченный жетон на этой стрелке. */
function markReadyOnLegacy(int $runId, array $arrow): bool
{
    $fromId = (int) $arrow['from_id'];
    $arrowId = (int) $arrow['id'];

    // Сколько раз начало стрелки было принято и по какой ветке пошло.
    $accepted = dbAll(
        "SELECT id, chosen_edge_id FROM run_steps
          WHERE run_id = ? AND element_id = ? AND state = 'accepted' ORDER BY id",
        [$runId, $fromId]
    );
    $given = 0;
    foreach ($accepted as $step) {
        // У ромба жетон получает только выбранная ветка.
        if ($step['chosen_edge_id'] !== null) {
            if ((int) $step['chosen_edge_id'] === $arrowId) $given++;
        } else {
            $given++;
        }
    }
    if ($given === 0) return false;

    // Отменённый, возвращённый и провалившийся шаги жетон не тратят.
    $used = (int) dbValue(
        "SELECT COUNT(*) FROM run_steps
          WHERE run_id = ? AND via_edge_id = ? AND state NOT IN ('cancelled','returned','failed')",
        [$runId, $arrowId]
    );
    return $used < $given;
}

/**
 * Что можно открыть прямо сейчас: элементы, у которых собрались входы.
 * join=all ждёт все прямые входы, join=any — любой.
 */
function marksReadyLegacy(array $run): array
{
    $runId = (int) $run['id'];

    $elements = dbAll(
        "SELECT e.* FROM elements e
          WHERE e.project_id = ? AND e.type IN ('block','decision','gateway')",
        [(int) $run['project_id']]
    );

    $ready = [];
    foreach ($elements as $element) {
        $id = (int) $element['id'];
        if (stepsOpenCount($runId, $id) > 0) continue;   // уже открыт — второй раз нельзя

        $marks = marksOnLegacy($runId, $id);
        $incoming = (int) dbValue("SELECT COUNT(*) FROM elements WHERE type = 'arrow' AND to_id = ? AND back = 0", [$id]);

        // Стартовый элемент: входов нет и шагов ещё не было.
        if ($incoming === 0 && !$marks) {
            $had = (int) dbValue('SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND element_id = ?', [$runId, $id]);
            if ($had === 0 && (int) $element['folder_id'] === (int) $run['folder_id']) {
                $ready[] = readyShapeLegacy($element, null);
            }
            continue;
        }
        if (!$marks) continue;

        $join = (string) runProp($id, 'join', 'all');
        if ($join === 'all' && $incoming > 0) {
            $direct = dbAll("SELECT * FROM elements WHERE type = 'arrow' AND to_id = ? AND back = 0", [$id]);
            $allReady = true;
            foreach ($direct as $arrow) {
                if (!markReadyOnLegacy($runId, $arrow)) { $allReady = false; break; }
            }
            // Возвратная стрелка цикла работает независимо от join.
            $byBack = false;
            foreach ($marks as $arrowId) {
                $back = (int) dbValue('SELECT back FROM elements WHERE id = ?', [$arrowId]);
                if ($back === 1) $byBack = true;
            }
            if (!$allReady && !$byBack) continue;
        }
        $ready[] = readyShapeLegacy($element, (int) $marks[0]);
    }
    return $ready;
}

/** Строка списка «что можно открыть» — прежним способом, запросами по одному. */
function readyShapeLegacy(array $element, ?int $viaId): array
{
    $id = (int) $element['id'];
    $row = [
        'no'    => (int) $element['no'],
        'id'    => $id,
        'type'  => (string) $element['type'],
        'title' => (string) $element['title'],
        'via'   => $viaId,
        'entry' => $viaId === null,
    ];

    if ($element['type'] === 'decision') {
        $row['cond'] = (string) runProp($id, 'cond', '');
        $row['from'] = $viaId ? (int) dbValue(
            'SELECT f.`no` FROM elements a JOIN elements f ON f.id = a.from_id WHERE a.id = ?', [$viaId]
        ) : null;
    }
    return $row;
}

/** Взять жетон под открытие шага. Без жетона шаг не открыть — это и есть защита от залпов. */
function markTakeLegacy(int $runId, array $element, ?int $viaId): ?int
{
    $id = (int) $element['id'];
    $marks = marksOnLegacy($runId, $id);

    if ($viaId !== null) {
        if (!in_array($viaId, $marks, true)) {
            throw new ApiError(ta('agents.marks.no_mark'), 'conflict', ['code2' => 'no_mark']);
        }
        return $viaId;
    }
    if ($marks) return $marks[0];

    $incoming = (int) dbValue("SELECT COUNT(*) FROM elements WHERE type = 'arrow' AND to_id = ? AND back = 0", [$id]);
    $had = (int) dbValue('SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND element_id = ?', [$runId, $id]);
    if ($incoming === 0 && $had === 0) return null;      // это старт

    throw new ApiError(ta('agents.marks.nothing_to_open'), 'conflict', ['code2' => 'no_mark']);
}
