<?php
/* Приёмка: единый вход к проверяющим и запись их решения в шаг.
   Отдаёт: judgeReview(), judgeSave(), judgeSaved().
   Не делает: не меняет состояние шага — это движок (engine/advance.php).

   Кто проверяет — настройка прогона runs.judge: human (по умолчанию) или formal.
   Jev (engine/judges/jev.php) зовёт ход движка отдельно: сеть — только вне замка проекта.
   Решение лежит в run_steps.verdict и относится к одной сдаче: новая сдача — новое решение. */

declare(strict_types=1);

/** Решение по сданному шагу: ['verdict', 'reasons', 'checks', 'detail'(, 'hint')]. */
function judgeReview(array $project, array $run, array $step, array $graph): array
{
    return (string) ($run['judge'] ?? 'human') === 'formal'
        ? judgeFormal($project, $run, $step, $graph)
        : judgeHuman($project, $run, $step, $graph);
}

/** Записать решение в шаг: кто решил, когда и по какой сдаче. */
function judgeSave(array $step, array $verdict, string $by): array
{
    $row = $verdict + [
        'by'        => $by,
        'at'        => dbNow(),
        'submitted' => (string) $step['submitted_at'],
    ];
    dbRun('UPDATE run_steps SET verdict = ? WHERE id = ?',
        [json_encode($row, JSON_UNESCAPED_UNICODE), (int) $step['id']]);
    return $row;
}

/** Решение, уже вынесенное по этой самой сдаче, или null. */
function judgeSaved(array $step): ?array
{
    $saved = $step['verdict'] ? json_decode((string) $step['verdict'], true) : null;
    if (!is_array($saved)) return null;
    return ($saved['submitted'] ?? '') === (string) $step['submitted_at'] ? $saved : null;
}
