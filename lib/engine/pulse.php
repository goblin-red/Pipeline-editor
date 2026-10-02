<?php
/* Пульс проекта: кто сейчас работает.
   Отдаёт: pulseGet().
   Не делает: ничего не меняет и ничего не ждёт — один быстрый запрос.

   Нужен шапке: три кружка — worker, круг leader и встроенный ИИ —
   мигают, пока кто-то работает. Поэтому здесь только счёт и имена,
   никаких тел и никаких прогонных решений.
   runFolders, runProjects — левой плашке: где идёт прогон. */

declare(strict_types=1);

/** Сколько секунд после последнего события считаем, что скрипт ещё работает. */
const PULSE_FRESH = 25;

/** GET pulse — кто сейчас работает в этом проекте. */
function pulseGet(): void
{
    $project   = requireProject(false);
    $projectId = (int) $project['id'];

    reply([
        'workers' => pulseWorkers($projectId),
        'script'  => pulseScript($projectId),
        'jev'     => pulseKinds($projectId, ['jev-ask', 'jev-say']),
        'ai'      => pulseAi($projectId),
        // Левая плашка: где идёт прогон — значок папки и проекта подсвечен.
        'runFolders'  => pulseRunFolders($projectId),
        'runProjects' => pulseRunProjects($projectId),
    ]);
}

/** Папки проекта, где прогон идёт или стоит на паузе. */
function pulseRunFolders(int $projectId): array
{
    $rows = dbAll(
        "SELECT DISTINCT folder_id FROM runs
          WHERE project_id = ? AND state IN ('running','paused') AND folder_id IS NOT NULL",
        [$projectId]
    );
    return array_map('intval', array_column($rows, 'folder_id'));
}

/**
 * Ключи проектов человека (свои и где он бывал — как в списке проектов),
 * где сейчас идёт прогон. Гость видит только открытый проект.
 */
function pulseRunProjects(int $projectId): array
{
    $userId = caller()['user_id'];
    if (!$userId) {
        $live = dbValue("SELECT 1 FROM runs WHERE project_id = ? AND state IN ('running','paused') LIMIT 1", [$projectId]);
        return $live ? [(string) dbValue('SELECT url_key FROM projects WHERE id = ?', [$projectId])] : [];
    }
    $rows = dbAll(
        "SELECT DISTINCT p.url_key FROM projects p
           JOIN runs r ON r.project_id = p.id AND r.state IN ('running','paused')
          WHERE p.owner_id = ?
             OR EXISTS (SELECT 1 FROM visits v WHERE v.user_id = ? AND v.project_id = p.id)",
        [$userId, $userId]
    );
    return array_column($rows, 'url_key');
}

/** Шаги, которые прямо сейчас у кого-то в руках. */
function pulseWorkers(int $projectId): array
{
    $rows = dbAll(
        "SELECT s.element_no, s.attempt, s.state, s.element_title, a.name AS agent,
                TIMESTAMPDIFF(SECOND, s.opened_at, NOW()) AS секунд, r.`no` AS run_no
           FROM run_steps s
           JOIN runs r ON r.id = s.run_id
      LEFT JOIN agents a ON a.id = s.agent_id
          WHERE s.project_id = ? AND r.state = 'running'
            AND s.state IN ('issued','running','submitted')
          ORDER BY s.id",
        [$projectId]
    );

    return array_map(static fn(array $row) => [
        'no'      => (int) $row['element_no'],
        'attempt' => (int) $row['attempt'],
        'title'   => (string) $row['element_title'],
        'agent'   => (string) ($row['agent'] ?? t('agents.w.no_agent')),
        'state'   => (string) $row['state'],
        'run'     => 'r' . (int) $row['run_no'],
        'seconds' => (int) $row['секунд'],
    ], $rows);
}

/**
 * leader (и круг утилиты, и сторож): живого процесса нам отсюда не видно,
 * поэтому считаем по свежести их последней записи в журнале. leader простого
 * пути пишет переписку с сервером (message), берёт задания (task) и сдаёт
 * ответы worker (submit) — это тоже его работа.
 */
function pulseScript(int $projectId): array
{
    $row = pulseKinds($projectId, ['drive', 'watch', 'open', 'accept', 'decide', 'message', 'task', 'submit', 'package']);
    $row['runs'] = (int) dbValue("SELECT COUNT(*) FROM runs WHERE project_id = ? AND state = 'running'", [$projectId]);
    return $row;
}

/** Последнее событие названных видов: когда было и что говорило. */
function pulseKinds(int $projectId, array $kinds): array
{
    $row = dbRow(
        'SELECT title, TIMESTAMPDIFF(SECOND, at, NOW()) AS секунд FROM run_events
          WHERE project_id = ? AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')
          ORDER BY id DESC LIMIT 1',
        array_merge([$projectId], $kinds)
    );
    if (!$row) return ['live' => false, 'seconds' => null, 'title' => ''];

    $seconds = (int) $row['секунд'];
    return [
        'live'    => $seconds <= PULSE_FRESH,
        'seconds' => $seconds,
        'title'   => (string) $row['title'],
    ];
}

/** Встроенный ИИ: считает ли он сейчас ответ. */
function pulseAi(int $projectId): array
{
    $row = dbRow(
        "SELECT j.kind, TIMESTAMPDIFF(SECOND, j.created_at, NOW()) AS секунд
           FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id
          WHERE c.project_id = ? AND j.state IN ('queued','running')
          ORDER BY j.id DESC LIMIT 1",
        [$projectId]
    );
    return $row
        ? ['live' => true, 'seconds' => (int) $row['секунд'], 'title' => (string) $row['kind']]
        : ['live' => false, 'seconds' => null, 'title' => ''];
}
