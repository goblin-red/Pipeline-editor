<?php
/* Расход на встроенный ИИ: сколько потрачено и на что.
   Отдаёт: aiUsage().
   Не делает: не ограничивает — предел живёт в ai/settings.php. */

declare(strict_types=1);

function aiUsage(int $projectId, int $days = 30): array
{
    $rows = dbAll(
        'SELECT DATE(j.created_at) AS day, COUNT(*) AS calls, SUM(j.tokens_total) AS tokens
           FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id
          WHERE c.project_id = ? AND j.created_at > NOW(3) - INTERVAL ? DAY
          GROUP BY day ORDER BY day DESC',
        [$projectId, $days]
    );
    return array_map(static fn(array $r) => [
        'day' => $r['day'], 'calls' => (int) $r['calls'], 'tokens' => (int) $r['tokens'],
    ], $rows);
}
