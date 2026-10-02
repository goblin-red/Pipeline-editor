<?php
/* Агенты проекта: кто может вести прогон и кто — выполнять шаги.
   Отдаёт: agentRow(), agentList(), agentSave(), agentDelete(), agentDetach(), agentUnassign(),
           agentToken(), agentShape().
   Не делает: не запускает агентов — это делает leader у себя в терминале.

   Роль ровно две: lead (leader-оркестратор) и worker (исполнитель).
   Значения cli, model, effort, permission, sandbox проверяются по config.txt. */

declare(strict_types=1);

function agentRow(int $id, int $projectId): array
{
    $row = dbRow('SELECT * FROM agents WHERE id = ?', [$id]);
    return sameProject($row, $projectId, 'agent');
}

function agentShape(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'role'       => (string) $row['role'],
        'cli'        => (string) $row['cli'],
        'model'      => (string) $row['model'],
        'effort'     => (string) $row['effort'],
        'permission' => (string) $row['permission'],
        'sandbox'    => (string) $row['sandbox'],
        'closeAfter' => (string) $row['close_after'],
        'created'    => (string) $row['created_at'],
        'style'      => json_decode((string) $row['style'], true) ?: [],
        'used'       => agentUsage((int) $row['id']),
        'rev'        => (int) $row['rev'],
    ];
}

/**
 * На скольких блоках стоит этот агент — по папкам: id папки => сколько.
 * По папкам, а не одним числом: открытую папку экран считает сам по живым
 * данным, иначе счётчик отставал бы до следующей загрузки проекта.
 */
function agentUsage(int $agentId): array
{
    $out = [];
    foreach (dbAll('SELECT folder_id, COUNT(*) AS n FROM elements WHERE agent_id = ? GROUP BY folder_id',
        [$agentId]) as $row) {
        $out[(string) (int) $row['folder_id']] = (int) $row['n'];
    }
    return $out;
}

function agentList(int $projectId): array
{
    return array_map('agentShape', dbAll('SELECT * FROM agents WHERE project_id = ? ORDER BY role, name', [$projectId]));
}

/** Поле операции agent.save → колонка agents. */
const AGENT_FIELDS = ['name' => 'name', 'role' => 'role', 'cli' => 'cli', 'model' => 'model', 'effort' => 'effort',
    'permission' => 'permission', 'sandbox' => 'sandbox', 'closeAfter' => 'close_after', 'style' => 'style'];

/**
 * Операция agent.save: без id — создание, с id — правка только присланных полей:
 * редактор карточки не шлёт style, и правка не должна сбрасывать его в {} (а роль — в worker).
 */
function agentSave(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $row = !empty($op['id']) ? agentRow((int) $op['id'], $projectId) : null;

    // Неприслано — прежнее значение карточки (при создании — пустое); проверяется итоговый набор.
    $raw = [];
    foreach (AGENT_FIELDS as $field => $column) {
        $raw[$field] = array_key_exists($field, $op) ? $op[$field]
            : ($row ? ($column === 'style' ? json_decode((string) $row['style'], true) : $row[$column]) : null);
    }
    $fields = [
        'name'       => mb_substr((string) ($raw['name'] ?? ''), 0, 255),
        'role'       => agentValue('roles', $raw['role'] ?? 'worker', 'role'),
        'cli'        => agentValue('cli', $raw['cli'] ?? '', 'cli'),
        'model'      => (string) ($raw['model'] ?? ''),
        'effort'     => agentValue('effort', $raw['effort'] ?? '', 'effort'),
        'permission' => agentValue('permission', $raw['permission'] ?? '', 'permission'),
        'sandbox'    => agentValue('sandbox', $raw['sandbox'] ?? '', 'sandbox'),
        'close_after' => (($raw['closeAfter'] ?? 'keep') === 'close') ? 'close' : 'keep',
        'style'      => json_encode((array) ($raw['style'] ?? []), JSON_UNESCAPED_UNICODE),
    ];
    agentModel($fields['cli'], $fields['model']);
    agentEffort($fields['cli'], $fields['model'], $fields['effort']);

    if ($row) {
        activeGuardAgent($projectId, (int) $row['id'], 'edit_agent');
        $set = [];
        $args = [];
        foreach (AGENT_FIELDS as $field => $column) {
            if (!array_key_exists($field, $op)) continue;
            $set[] = "$column = ?";
            $args[] = $fields[$column];
        }
        $set[] = 'rev = ?';
        $args[] = $ctx['rev'];
        $args[] = (int) $row['id'];
        dbRun('UPDATE agents SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        return ['id' => (int) $row['id'], 'prev' => agentShape($row)];
    }

    dbRun(
        'INSERT INTO agents (project_id, name, role, cli, model, effort, permission, sandbox, close_after, style, rev)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        array_merge([$projectId], array_values($fields), [$ctx['rev']])
    );
    return ['id' => dbId()];
}

function agentDelete(array $op, array &$ctx): array
{
    $row = agentRow((int) ($op['id'] ?? 0), $ctx['projectId']);
    activeGuardAgent((int) $ctx['projectId'], (int) $row['id'], 'delete_agent');
    agentUnassign((int) $row['id'], $ctx);
    dbRun('DELETE FROM agents WHERE id = ?', [$row['id']]);
    markDeleted($ctx['projectId'], 'agent', (int) $row['id'], $ctx['rev'], ['title' => $row['name']]);
    return ['id' => (int) $row['id'], 'deleted' => true];
}

/**
 * Операция agent.detach: снять агента со всех блоков проекта. Сам агент
 * остаётся — убираем только назначения.
 */
function agentDetach(array $op, array &$ctx): array
{
    $row = agentRow((int) ($op['id'] ?? 0), $ctx['projectId']);
    activeGuardAgent((int) $ctx['projectId'], (int) $row['id'], 'unassign_agent');
    $many = agentUnassign((int) $row['id'], $ctx);
    if ($many) dbRun('UPDATE agents SET rev = ? WHERE id = ?', [$ctx['rev'], $row['id']]);
    return ['id' => (int) $row['id'], 'detached' => $many];
}

/**
 * Снять агента с блоков. У блоков растёт rev, а их папки считаются изменёнными:
 * иначе правка не доедет до открытых экранов — внешний ключ обнулил бы agent_id молча.
 * Отдаёт, со скольких блоков снят.
 */
function agentUnassign(int $agentId, array &$ctx): int
{
    $folders = dbAll('SELECT DISTINCT folder_id FROM elements WHERE agent_id = ?', [$agentId]);
    if (!$folders) return 0;
    foreach ($folders as $one) $ctx['folders'][] = (int) $one['folder_id'];
    return dbRun('UPDATE elements SET agent_id = NULL, rev = ? WHERE agent_id = ?', [$ctx['rev'], $agentId]);
}

/**
 * POST agent.token — выписать агенту долгий пропуск.
 *
 * Им worker живёт весь прогон: сам спрашивает `step.mine`, сам берёт задание,
 * сдаёт и идёт за следующим. Пропуск шага после этого нужен только тому, кто
 * работает разово.
 */
function agentToken(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $agent = agentRow((int) (input('id') ?? input('agent') ?? 0), $projectId);
    if ($agent['role'] !== 'worker') throw new ApiError(t('server.agent.token_worker_only'));

    /* Прежние пропуска этого агента живут дальше: один агент работает сразу
       в нескольких прогонах, и выдача второго пропуска не должна обрывать
       первого worker посреди шага. Погасить старые просит `renew`. */
    if (!empty(input('renew'))) tokenRevokeFor('agent_id', (int) $agent['id']);

    $token = tokenIssue('agent', [
        'project_id' => $projectId,
        'agent_id'   => (int) $agent['id'],
    ], t('server.token.agent_label', ['name' => $agent['name']]));

    reply([
        'agent' => agentShape($agent),
        'token' => $token['secret'],
        'howto' => sprintf('GOBLIN_URL=%s GOBLIN_PROJECT=%s GOBLIN_AGENT=%s goblin work',
            runBaseUrl(), $project['url_key'], $token['secret']),
    ]);
}

/** Значение должно быть в списке config.txt, иначе человек не поймёт, что выбрал. */
function agentValue(string $section, $value, string $what): string
{
    $value = (string) $value;
    if (!listHas($section, $value)) throw new ApiError(t('server.agent.bad_value', ['what' => entityLabel($what), 'value' => $value]));
    return $value;
}

/** Модель должна принадлежать выбранному CLI. */
/**
 * Усилие должно быть из набора этой модели.
 *
 * Набор пишется в config.txt за именем модели: `opus-5[low, high]`.
 * Скобок нет — у модели годится любое усилие из раздела [effort].
 */
function agentEffort(string $cli, string $model, string $effort): void
{
    if ($effort === '' || $model === '' || $cli === '') return;

    $known = lists()['sections']['cli'][$cli]['extra'] ?? [];
    foreach ($known as $item) {
        $name = trim(explode('[', $item)[0]);
        if ($name !== $model) continue;
        if (!str_contains($item, '[')) return;

        $inside = trim(explode(']', explode('[', $item)[1])[0]);
        $able = array_map('trim', explode(',', $inside));
        if (in_array($effort, $able, true)) return;

        throw new ApiError(t('server.agent.no_effort', ['model' => $model, 'effort' => $effort, 'able' => implode(', ', $able)]));
    }
}

function agentModel(string $cli, string $model): void
{
    if ($model === '' || $cli === '') return;
    $known = lists()['sections']['cli'][$cli]['extra'] ?? [];
    foreach ($known as $item) {
        $name = trim(explode('[', $item)[0]);
        if ($name === $model) return;
    }
    if ($known) throw new ApiError(t('server.agent.no_model', ['cli' => $cli, 'model' => $model]));
}
