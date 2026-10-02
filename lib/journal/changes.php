<?php
/* Что изменилось после ревизии N и кто сейчас в проекте.
   Отдаёт: changesGet(), journalGet().
   Не делает: не пишет — только читает.

   Дельта = проект (его настройки: название, гостевая запись — у проекта своей ревизии
   нет, поэтому он едет всякий раз, когда общая ревизия выросла) + строки с rev > since
   + надгробия. Клиент применяет сначала удаления. */

declare(strict_types=1);

/** GET changes&since=REV */
function changesGet(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $since = inputInt('since', 0) ?? 0;
    $view = askedView();

    $rev = (int) $project['rev'];
    if ($since >= $rev) {
        reply(['rev' => $rev, 'changed' => new stdClass(), 'deleted' => [], 'now' => dbNow(), 'agents' => activeAgents($projectId)]);
    }

    $changed = ['project' => projectShape($project)];

    $folders = dbAll('SELECT * FROM folders WHERE project_id = ? AND rev > ?', [$projectId, $since]);
    if ($folders) $changed['folders'] = array_map(static fn(array $r) => folderShape($r, $view), $folders);

    $elements = dbAll('SELECT * FROM elements WHERE project_id = ? AND rev > ? ORDER BY `no`', [$projectId, $since]);
    if ($elements) {
        $links = [];   // папка → материалы её элементов: одна выборка на папку, а не на каждый элемент
        $changed['elements'] = array_map(static function (array $row) use ($view, &$links) {
            $id = (int) $row['id'];
            $folderId = (int) $row['folder_id'];
            if ($view === VIEW_FULL && !isset($links[$folderId])) $links[$folderId] = assetLinksOfFolder($folderId);
            return elementShape($row, $view, [
                'in'      => containersOf($id),
                'props'   => $view === VIEW_FULL ? propsOf($id) : [],
                // Материалы едут вместе с элементом: прикрепили картинку —
                // карточка меняется сразу, без перезагрузки страницы.
                'assets'  => $view === VIEW_FULL ? ($links[$folderId][$id] ?? []) : [],
                'hasSpec' => (bool) specText('element_id', $id),
            ]);
        }, $elements);
    }

    $agents = dbAll('SELECT * FROM agents WHERE project_id = ? AND rev > ?', [$projectId, $since]);
    if ($agents) $changed['agents'] = array_map('agentShape', $agents);

    $assets = dbAll('SELECT * FROM assets WHERE project_id = ? AND rev > ?', [$projectId, $since]);
    if ($assets) $changed['assets'] = array_map(static fn(array $r) => assetShape($r), $assets);

    $steps = dbAll(
        'SELECT * FROM run_steps WHERE project_id = ? AND rev > ? ORDER BY id',
        [$projectId, $since]
    );
    if ($steps) $changed['steps'] = array_map('stepShape', $steps);

    $runs = dbAll('SELECT * FROM runs WHERE project_id = ? AND rev > ?', [$projectId, $since]);
    if ($runs) $changed['runs'] = array_map('runShape', $runs);

    $deleted = dbAll('SELECT entity, entity_id, `no`, folder_id FROM deletions WHERE project_id = ? AND rev > ?', [$projectId, $since]);

    reply([
        'rev'     => $rev,
        'changed' => $changed,
        'deleted' => array_map(static fn(array $r) => [
            'entity' => $r['entity'], 'id' => (int) $r['entity_id'],
            'no' => $r['no'] !== null ? (int) $r['no'] : null,
            'folder' => $r['folder_id'] !== null ? (int) $r['folder_id'] : null,
        ], $deleted),
        'now'    => dbNow(),
        'agents' => activeAgents($projectId),
    ]);
}

/** Кто писал в проект последние две минуты — для строки присутствия. */
function activeAgents(int $projectId): array
{
    $rows = dbAll(
        "SELECT a.name, a.role, COUNT(*) AS writes
           FROM journal j JOIN agents a ON a.id = j.agent_id
          WHERE j.project_id = ? AND j.created_at > NOW(3) - INTERVAL 2 MINUTE
          GROUP BY a.id ORDER BY writes DESC",
        [$projectId]
    );
    return array_map(static fn(array $r) => ['name' => $r['name'], 'role' => $r['role'], 'writes' => (int) $r['writes']], $rows);
}

/** GET journal.get — история со значениями. */
function journalGet(): void
{
    $project = requireProject(false);
    $limit = min(500, max(1, inputInt('limit', 100) ?? 100));

    $rows = dbAll(
        'SELECT j.*, a.name AS agent_name FROM journal j
           LEFT JOIN agents a ON a.id = j.agent_id
          WHERE j.project_id = ? ORDER BY j.id DESC LIMIT ' . $limit,
        [$project['id']]
    );
    $out = [];
    foreach ($rows as $row) {
        $ops = dbAll('SELECT n, op, target_id, target_no, args, prev, result FROM journal_ops WHERE journal_id = ? ORDER BY n', [$row['id']]);
        $out[] = [
            'id'    => (int) $row['id'],
            'rev'   => (int) $row['rev'],
            'via'   => $row['via'],
            'who'   => $row['agent_name'] ?: ($row['label'] ?: ($row['user_id'] ? t('server.journal.human') : t('server.journal.guest'))),
            'at'    => $row['created_at'],
            'ops'   => array_map(static fn(array $o) => [
                'op'     => $o['op'],
                'id'     => $o['target_id'] !== null ? (int) $o['target_id'] : null,
                'no'     => $o['target_no'] !== null ? (int) $o['target_no'] : null,
                'args'   => journalStoredJson($o['args']),
                'prev'   => journalStoredJson($o['prev']),
                'result' => journalStoredJson($o['result']),
            ], $ops),
        ];
    }
    reply(['journal' => $out]);
}

/** Исторические строки могли содержать token/howto: наружу они выходят только redacted. */
function journalStoredJson(?string $json): ?array
{
    if (!$json) return null;
    $decoded = json_decode($json, true);
    return is_array($decoded) ? journalCleanSecrets($decoded) : null;
}
