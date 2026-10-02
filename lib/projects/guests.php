<?php
/* Гости — люди без учётки (решение хозяина 30.09.2026).
   Отдаёт: GUEST_AI_OPS, guestKeys(), guestAdopt(), guestList(), guestSeen(), guestSweep(), guestAiAllowed().
   Не делает: не заводит учёток и не решает права на проект — это access/.

   Проект гостя — без владельца (owner_id пуст). Его ключи помнит браузер гостя и шлёт их в «Мои проекты»
   (project.get без ключа, &keys=). Вошёл — такие проекты становятся его (guestAdopt).
   Не открывали guest_days дней — уборка раз в сутки (guestSweep; 0 — выключено).
   Платный ИИ гостю — только при guest_ai (настройки установки); голос и проигрывание — только вошедшим. */

declare(strict_types=1);

/** Операции, которые зовут платные модели: гостю — только при guest_ai. */
const GUEST_AI_OPS = ['ai.chat.send', 'ai.build.start', 'ai.build.answer', 'voice.session', 'voice.speak', 'ai.play'];

/** Из них только вошедшим, при любом guest_ai: голос и проигрывание (решение хозяина 30.09.2026, Б11). */
const LOGIN_AI_OPS = ['voice.session', 'voice.speak', 'ai.play'];

/** Ключи проектов из браузера гостя: &keys=a,b,c — только правильные, не больше 50. */
function guestKeys(): array
{
    $keys = array_filter(array_map('trim', explode(',', (string) (input('keys') ?? ''))), 'validProjectKey');
    return array_slice(array_values(array_unique($keys)), 0, 50);
}

/** Вошедшему: проекты без владельца, которые помнит его браузер, становятся его. */
function guestAdopt(int $userId, array $keys): int
{
    if (!$keys) return 0;
    $marks = implode(',', array_fill(0, count($keys), '?'));
    // updated_at = updated_at — смена владельца не правка: порядок «последних» не сбиваем.
    return dbRun("UPDATE projects SET owner_id = ?, updated_at = updated_at WHERE owner_id IS NULL AND url_key IN ($marks)",
        [$userId, ...$keys]);
}

/** Гостю — его проекты по ключам из браузера, последние сверху. Вид — как у projectList(). */
function guestList(array $keys): array
{
    if (!$keys) return [];
    $marks = implode(',', array_fill(0, count($keys), '?'));
    $rows = dbAll(
        "SELECT p.*, GREATEST(p.updated_at, COALESCE(p.seen_at, p.updated_at)) AS touched,
                (SELECT COUNT(*) FROM folders f WHERE f.project_id = p.id) AS folders
           FROM projects p WHERE p.url_key IN ($marks) ORDER BY touched DESC",
        $keys
    );
    return array_map(static fn(array $row) => projectShape($row) + [
        'folders' => (int) $row['folders'],
        'seen'    => (string) ($row['seen_at'] ?: $row['updated_at']),
        'touched' => (string) $row['touched'],
    ], $rows);
}

/** Проект открыли: отметка для уборки, не чаще раза в час — лишних записей на каждое открытие нет. */
function guestSeen(int $projectId): void
{
    dbRun("UPDATE projects SET seen_at = NOW(3), updated_at = updated_at
            WHERE id = ? AND (seen_at IS NULL OR seen_at < NOW(3) - INTERVAL 1 HOUR)", [$projectId]);
}

/**
 * Уборка: проекты гостей, которые не открывали и не правили guest_days дней, удаляются — раз в сутки,
 * тем же project.delete, что у человека. Проект с живым прогоном не трогаем.
 */
function guestSweep(): int
{
    $days = (int) (config()['guest_days'] ?? 0);
    if ($days < 1) return 0;
    $stamp = dirname(__DIR__, 2) . '/storage/guest-sweep.stamp';
    if (is_file($stamp) && filemtime($stamp) > time() - 86400) return 0;
    @touch($stamp);

    $gone = 0;
    $old = dbAll(
        "SELECT * FROM projects p
          WHERE p.owner_id IS NULL
            AND GREATEST(p.updated_at, COALESCE(p.seen_at, p.updated_at)) < NOW(3) - INTERVAL ? DAY
            AND NOT EXISTS (SELECT 1 FROM runs r WHERE r.project_id = p.id AND r.state IN ('running', 'paused'))
          LIMIT 200",
        [$days]
    );
    foreach ($old as $project) {
        try {
            runBatch($project, [['op' => 'project.delete']],
                static fn(array $op, array &$ctx): array => opRule((string) $op['op'])[1]($op, $ctx), false);
            $gone++;
        } catch (Throwable $e) {
            error_log('[goblin] уборка проектов гостей: ' . $e->getMessage());
        }
    }
    return $gone;
}

/** Можно ли звать платный ИИ операцией $op: вошедшему — да, гостю — если разрешено (guest_ai) и это не голос. */
function guestAiAllowed(string $op = ''): bool
{
    if (!empty(caller()['user_id'])) return true;
    return !in_array($op, LOGIN_AI_OPS, true) && (bool) (config()['guest_ai'] ?? true);
}
