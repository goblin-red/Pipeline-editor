<?php
/* Проверяющий «человек»: решение всегда за человеком.
   Отдаёт: judgeHuman(), judgeHint().
   Не делает: не принимает и не возвращает — только готовит подсказку.

   Итог всегда «не уверен», но в подсказке — что сказали формальные проверки:
   «образец — да · арифметика — да (a=5 s=15)». Человеку не надо пересчитывать руками. */

declare(strict_types=1);

/** Решение проверяющего «человек»: ждать человека, с подсказкой от формальных проверок. */
function judgeHuman(array $project, array $run, array $step, array $graph): array
{
    $formal = judgeFormal($project, $run, $step, $graph);
    return [
        'verdict' => 'unsure',
        'reasons' => [ta('agents.judge.human_accepts')],
        'checks'  => $formal['checks'],
        'detail'  => $formal['detail'] + ['formal' => $formal['verdict'], 'formalReasons' => $formal['reasons']],
        'hint'    => judgeHint($formal),
    ];
}

/** Подсказка одной строкой: «образец — да · арифметика — да (a=5 s=15)» или причина провала. */
function judgeHint(array $formal): string
{
    $parts = array_map(static fn(array $c) => $c['say'], $formal['checks']);
    if ($formal['verdict'] === 'return') $parts[] = ta('agents.judge.return_prefix') . implode('; ', $formal['reasons']);
    if ($formal['verdict'] === 'unsure' && !$parts) $parts = $formal['reasons'];
    return implode(' · ', $parts);
}
