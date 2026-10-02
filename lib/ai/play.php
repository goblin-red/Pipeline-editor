<?php
/* Проигрывание (тест-прогон): пройти схему на выдуманных исходах, не записав ни строчки.
   Отдаёт: aiPlay().
   Не делает: не трогает прогоны и статусы — это просто проверка схемы.

   Нужна, чтобы увидеть тупик до того, как на него потратят живого агента.
   Сначала — точная предпроверка сервера (та же, что у begin): она не гадает.
   Модель проходит схему поверх неё — по сути ТЗ: выполнимость, вход, стыки соседей, ромбы, цель —
   и не может сказать «дойдёт», если сервер нашёл помеху. */

declare(strict_types=1);

function aiPlay(): void
{
    $project = requireProject(false);
    $folderId = inputInt('folder');
    if (!$folderId) throw new ApiError(t('server.folder.not_given'), 'not_found');
    $folder = folderRow($folderId, (int) $project['id']);
    aiLimit((int) $project['id']);

    $check = folderPrecheck($folder);
    $problems = array_column($check['problems'], 'say');

    $messages = [
        ['role' => 'system', 'content' => aiRules('simulation') . "\n\n"
            . ta('agents.ai.play_format')],
        ['role' => 'user', 'content' => ta('agents.ai.play_user', ['problems' => $problems ? implode('; ', $problems) : ta('agents.ai.no_problems'),
                'scheme' => json_encode(aiContext($project, $folderId), JSON_UNESCAPED_UNICODE)])],
    ];

    $answer = aiAsk($messages);
    $play = $answer['data'];
    if (!$play) throw new ApiError(t('agents.ai.no_play'), 'server');
    // Сервер нашёл помеху — прогон встанет, что бы ни придумала модель.
    if ($problems) $play['verdict'] = ta('agents.ai.verdict_stuck');
    reply(['play' => $play, 'problems' => $problems, 'model' => $answer['model'], 'think' => $answer['think']]);
}
