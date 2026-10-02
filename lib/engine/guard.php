<?php
/* Защита схемы живого engine2-прогона от изменений смысла.
   Отдаёт: activeGuardFolders(), activeGuardProject(), activeGuardAsset(), activeGuardAgent().
   Не делает: не останавливает прогон и не запрещает оформление. */

declare(strict_types=1);

/** Папки, от которых зависит один прогон: ветви дерева и gateway targets рекурсивно. */
function activeGuardScope(int $projectId, int $rootFolderId): array
{
    $seen = [];
    $queue = [$rootFolderId];
    while ($queue) {
        $folderId = (int) array_pop($queue);
        if (!$folderId || isset($seen[$folderId])) continue;
        $folder = dbRow('SELECT id FROM folders WHERE id = ? AND project_id = ?', [$folderId, $projectId]);
        if (!$folder) continue;
        $seen[$folderId] = true;

        foreach (dbAll('SELECT id FROM folders WHERE parent_id = ? AND project_id = ?', [$folderId, $projectId]) as $row) {
            $queue[] = (int) $row['id'];
        }
        foreach (dbAll(
            "SELECT target_folder_id FROM elements
              WHERE project_id = ? AND folder_id = ? AND type = 'gateway' AND target_folder_id IS NOT NULL",
            [$projectId, $folderId]
        ) as $row) {
            $queue[] = (int) $row['target_folder_id'];
        }
    }
    return array_map('intval', array_keys($seen));
}

/** Найти live engine2 run, чья зависимость пересекает названные папки. */
function activeGuardHit(int $projectId, array $folderIds): ?array
{
    $wanted = array_fill_keys(array_map('intval', array_filter($folderIds)), true);
    if (!$wanted) return null;
    $runs = dbAll(
        "SELECT * FROM runs
          WHERE project_id = ? AND engine = 2 AND state IN ('running','paused')
          ORDER BY id",
        [$projectId]
    );
    foreach ($runs as $run) {
        foreach (activeGuardScope($projectId, (int) $run['folder_id']) as $folderId) {
            if (!isset($wanted[$folderId])) continue;
            $folder = dbRow('SELECT id, name FROM folders WHERE id = ?', [$folderId]);
            return ['run' => $run, 'folder' => $folder ?: ['id' => $folderId, 'name' => '']];
        }
    }
    return null;
}

/** Что именно запретили — словами для человека; $what — код действия. Незнакомое отдаём как есть. */
function activeGuardWhat(string $what): string
{
    return match ($what) {
        'strict_checks' => t('server.guard.what.strict_checks'),
        'delete_project' => t('server.guard.what.delete_project'),
        'edit_members' => t('server.guard.what.edit_members'),
        'edit_agent' => t('server.guard.what.edit_agent'),
        'delete_agent' => t('server.guard.what.delete_agent'),
        'unassign_agent' => t('server.guard.what.unassign_agent'),
        'edit_element' => t('server.guard.what.edit_element'),
        'move_element_in' => t('server.guard.what.move_element_in'),
        'delete_element' => t('server.guard.what.delete_element'),
        'restore_element' => t('server.guard.what.restore_element'),
        'folder_settings' => t('server.guard.what.folder_settings'),
        'move_folder' => t('server.guard.what.move_folder'),
        'delete_folder' => t('server.guard.what.delete_folder'),
        'transfer_folder' => t('server.guard.what.transfer_folder'),
        'rename_file' => t('server.guard.what.rename_file'),
        'delete_file' => t('server.guard.what.delete_file'),
        'edit_rules' => t('server.guard.what.edit_rules'),
        'edit_assets' => t('server.guard.what.edit_assets'),
        'edit_cover' => t('server.guard.what.edit_cover'),
        'edit_asset_role' => t('server.guard.what.edit_asset_role'),
        'unlink_asset' => t('server.guard.what.unlink_asset'),
        'edit_asset' => t('server.guard.what.edit_asset'),
        'delete_asset' => t('server.guard.what.delete_asset'),
        'agent_lang' => t('server.guard.what.agent_lang'),
        default => $what,
    };
}

/** Запретить смысловую правку защищённой папки. Звать под project lock. */
function activeGuardFolders(int $projectId, array $folderIds, string $what): void
{
    // Во всех штатных write-entrypoints уже открыта транзакция; повторный
    // FOR UPDATE либо подтверждает тот же lock, либо берёт его для legacy caller.
    dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
    $hit = activeGuardHit($projectId, $folderIds);
    if (!$hit) return;
    $run = $hit['run'];
    $folder = $hit['folder'];
    throw new ApiError(
        t('server.guard.message', ['what' => activeGuardWhat($what), 'run' => 'r' . (int) $run['no']]),
        'conflict',
        [
            'code2' => 'active_run',
            'active_run' => 'r' . (int) $run['no'],
            'run' => (int) $run['id'],
            'folder' => (int) $folder['id'],
            'folderName' => (string) $folder['name'],
            'instruction' => t('server.guard.instruction'),
        ]
    );
}

function activeGuardProject(int $projectId, string $what): void
{
    $folders = array_map('intval', array_column(
        dbAll("SELECT folder_id FROM runs WHERE project_id = ? AND engine = 2 AND state IN ('running','paused')", [$projectId]),
        'folder_id'
    ));
    activeGuardFolders($projectId, $folders, $what);
}

function activeGuardElement(array $element, string $what, array $alsoFolders = []): void
{
    activeGuardFolders(
        (int) $element['project_id'],
        array_merge([(int) $element['folder_id']], $alsoFolders),
        $what
    );
}

/** Материал влияет на выполнение либо является схемной cover в protected scope. */
function activeGuardAsset(int $projectId, int $assetId, string $what): void
{
    $rows = dbAll(
        "SELECT DISTINCT COALESCE(e.folder_id, l.folder_id, r.folder_id) AS folder_id
           FROM asset_links l
           LEFT JOIN elements e ON e.id = l.element_id
           LEFT JOIN run_steps s ON s.id = l.step_id
           LEFT JOIN runs r ON r.id = COALESCE(l.run_id, s.run_id)
          WHERE l.project_id = ? AND l.asset_id = ? AND l.role IN ('spec','input','reference','cover')",
        [$projectId, $assetId]
    );
    activeGuardFolders($projectId, array_column($rows, 'folder_id'), $what);
}

/** Изменение карточки назначенного агента меняет пакет следующего шага. */
function activeGuardAgent(int $projectId, int $agentId, string $what): void
{
    $folders = array_column(
        dbAll('SELECT DISTINCT folder_id FROM elements WHERE project_id = ? AND agent_id = ?', [$projectId, $agentId]),
        'folder_id'
    );
    activeGuardFolders($projectId, $folders, $what);
}

/** Папка схемного владельца asset link; runtime run/step owner возвращает 0. */
function activeGuardLinkFolder(array $link): int
{
    if (!empty($link['element_id'])) {
        return (int) dbValue('SELECT folder_id FROM elements WHERE id = ?', [(int) $link['element_id']]);
    }
    return (int) ($link['folder_id'] ?? 0);
}

/** Единственный bypass схемной cover: accepted step сервера, а не client madeByRun. */
function activeGuardRuntimeCover(array $ctx, array $op, int $elementId): bool
{
    $stepId = (int) ($ctx['activeGuardRuntimeStep'] ?? 0);
    $runId = (int) ($ctx['activeGuardRuntimeRun'] ?? 0);
    if (!$stepId || !$runId || (int) ($op['madeByRun'] ?? 0) !== $runId) return false;
    return (bool) dbValue(
        "SELECT id FROM run_steps
          WHERE id = ? AND run_id = ? AND element_id = ? AND state = 'accepted'",
        [$stepId, $runId, $elementId]
    );
}
