<?php
/* Элементы схемы: создать, изменить, удалить, прочитать.
   Отдаёт: elementCreate(), elementUpdate(), elementDelete(), elementRestore(), elementRow(),
           linkEnds(), linksCutAcross(), gatesCutAcross(), endFolders().
   Не делает: не решает, что во что кладётся (kinds.php), не считает состав (members.php),
              не трогает ассеты (assets/links.php).

   Первичное лежит в колонках, всё оформление — в одном поле style.
   Ярлык (link) хранится как стрелка — from_id и to_id, — но живёт по своим
   правилам: концы — блок, группа, область; папки концов могут быть разными;
   папка ярлыка — всегда папка владельца (from). */

declare(strict_types=1);

/** Строка элемента этого проекта или «не найдено». */
function elementRow(int $id, int $projectId): array
{
    $row = dbRow('SELECT * FROM elements WHERE id = ?', [$id]);
    return sameProject($row, $projectId, 'element');
}

/** Операция element.create. Возвращает id и номер — их выдаёт сервер. */
function elementCreate(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $type = requireKind((string) ($op['type'] ?? ''));

    // Папка — числом или ссылкой на папку, созданную в этой же пачке (конструктор: folder.create с ref).
    $folderRaw = $op['folder'] ?? 0;
    $folderId = is_string($folderRaw) && isset($ctx['refs'][$folderRaw]) ? (int) $ctx['refs'][$folderRaw] : (int) $folderRaw;
    $from = $to = $target = $agent = null;
    $branch = null;
    $back = 0;

    // Папку ярлыка задаёт владелец, поэтому концы разбираем раньше папки.
    if ($type === 'link') {
        [$from, $to, $folderId] = linkEnds($op, $ctx, $folderId);
    }
    $folder = dbRow('SELECT * FROM folders WHERE id = ?', [$folderId]);
    sameProject($folder, $projectId, 'folder');

    if ($type === 'arrow') {
        $from = requireLinkable(resolveRef($op['from'] ?? null, $ctx, 'arrow_start'), $projectId, 'arrow_start');
        $to   = requireLinkable(resolveRef($op['to'] ?? null, $ctx, 'arrow_end'), $projectId, 'arrow_end');
        $branch = arrowBranch($op['branch'] ?? 'flow', $from, $projectId);
        $back = !empty($op['back']) ? 1 : 0;
        // Между двумя объектами переход один: вторая такая же стрелка ничего
        // не добавляет к схеме, а прогон по ней пришлось бы толковать дважды.
        $twin = dbValue(
            "SELECT `no` FROM elements WHERE type = 'arrow' AND from_id = ? AND to_id = ?",
            [$from, $to]
        );
        if ($twin) {
            throw new ApiError(t('server.element.arrow_exists_with', ['twin' => $twin]), 'conflict');
        }
    } elseif ($type !== 'link') {
        if (isset($op['from']) || isset($op['to'])) {
            throw new ApiError(t('server.element.ends_only'));
        }
    }
    if ($type === 'gateway' && isset($op['target'])) {
        $target = gatewayTarget($op['target'], $projectId);
    }
    if (!empty($op['agent'])) {
        if (!kindRule($type)['agent']) throw new ApiError(t('server.element.agent_block_only'));
        $agent = resolveAgentRef($op['agent'], $ctx);
    }

    activeGuardFolders($projectId, array_merge([$folderId, $target], endFolders([$from, $to])), 'edit_members');

    $no = nextNo($projectId);
    dbRun(
        'INSERT INTO elements (project_id, folder_id, `no`, type, title, description, agent_id, from_id, to_id, branch, back, target_folder_id, style, rev)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $projectId, $folderId, $no, $type,
            mb_substr((string) ($op['title'] ?? ''), 0, 512),
            $op['description'] ?? null,
            $agent, $from, $to, $branch, $back, $target,
            styleJson($type, $op['style'] ?? [], []),
            $ctx['rev'],
        ]
    );
    $id = dbId();

    if (isset($op['in']))    membersSet($id, (array) $op['in'], $ctx);
    if (isset($op['props'])) propsPatch($id, (array) $op['props']);

    $ctx['folders'][] = $folderId;
    return ['id' => $id, 'no' => $no, 'folder_id' => $folderId];
}

/** Операция element.update. Меняются только присланные поля. */
function elementUpdate(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $id = resolveRef($op['id'] ?? null, $ctx);
    $row = elementRow($id, $projectId);
    $type = (string) $row['type'];

    $semantic = ['type', 'title', 'description', 'folder', 'agent', 'target',
                 'branch', 'back', 'from', 'to', 'in', 'props'];
    if (array_intersect($semantic, array_keys($op))) {
        // Новые концы и цель шлюза тоже под защитой: ярлык не переведёшь в папку живого прогона.
        $newEnds = [];
        if (in_array($type, ['arrow', 'link'], true)) {
            foreach (['from', 'to'] as $end) {
                if (array_key_exists($end, $op)) $newEnds[] = resolveRef($op[$end], $ctx, 'end');
            }
        }
        activeGuardElement($row, 'edit_element',
            array_merge(endFolders($newEnds), isset($op['target']) ? [(int) $op['target']] : []));
    }

    $set = [];
    $args = [];
    $prev = [];

    // Тип меняется только у рамок и только одной заменой: группа ⇄ область.
    // Остальные превращения ломают смысл схемы: блок с ТЗ и агентом не может
    // стать стрелкой, а шлюз — таблицей.
    if (array_key_exists('type', $op)) {
        $want = requireKind((string) $op['type']);
        $pair = ['group' => 'area', 'area' => 'group'];
        if (!isset($pair[$type]) || $pair[$type] !== $want) {
            throw new ApiError(t('server.element.type_change'));
        }
        $prev['type'] = $type;
        $set[] = 'type = ?';
        $args[] = $want;
        $type = $want;              // дальше поля проверяются по новому типу
    }

    if (array_key_exists('title', $op)) {
        $prev['title'] = $row['title'];
        $set[] = 'title = ?';
        $args[] = mb_substr((string) $op['title'], 0, 512);
    }
    if (array_key_exists('description', $op)) {
        $prev['description'] = $row['description'];
        $set[] = 'description = ?';
        $args[] = $op['description'];
    }
    if (array_key_exists('folder', $op)) {
        if ($type === 'link') throw new ApiError(t('server.element.link_folder'));
        $folder = dbRow('SELECT * FROM folders WHERE id = ?', [(int) $op['folder']]);
        // Стартер уезжает в папку, где свой стартер уже есть, — отказ.
        if (isStarter($id)) starterGuard((int) $op['folder'], $id);
        sameProject($folder, $projectId, 'folder');
        activeGuardFolders($projectId, [(int) $folder['id']], 'move_element_in');
        $prev['folder'] = (int) $row['folder_id'];
        $set[] = 'folder_id = ?';
        $args[] = (int) $folder['id'];
        $ctx['folders'][] = (int) $folder['id'];
    }
    if (array_key_exists('agent', $op)) {
        if (!kindRule($type)['agent']) throw new ApiError(t('server.element.agent_block_only'));
        if ($op['agent'] !== null && isStarter($id)
            && !array_key_exists('start', (array) ($op['props'] ?? []))) {
            throw new ApiError(t('server.element.starter_no_agent'));
        }
        $prev['agent'] = $row['agent_id'] ? (int) $row['agent_id'] : null;
        $set[] = 'agent_id = ?';
        $args[] = $op['agent'] === null ? null : resolveAgentRef($op['agent'], $ctx);
    }
    if (array_key_exists('target', $op)) {
        if ($type !== 'gateway') throw new ApiError(t('server.element.target_gateway_only'));
        $prev['target'] = $row['target_folder_id'] ? (int) $row['target_folder_id'] : null;
        $set[] = 'target_folder_id = ?';
        $args[] = $op['target'] === null ? null : gatewayTarget($op['target'], $projectId);
    }
    // Итоговое начало стрелки: ветку «да/нет» сверяем по нему, а не по прежнему.
    $nowFrom = $type === 'arrow' && array_key_exists('from', $op)
        ? resolveRef($op['from'], $ctx, 'arrow_start') : (int) $row['from_id'];
    if (array_key_exists('branch', $op) || array_key_exists('back', $op)) {
        if ($type !== 'arrow') throw new ApiError(t('server.element.branch_arrow_only'));
        if (array_key_exists('branch', $op)) {
            $prev['branch'] = $row['branch'];
            $set[] = 'branch = ?';
            $args[] = arrowBranch($op['branch'], $nowFrom, $projectId);
        }
        if (array_key_exists('back', $op)) {
            $prev['back'] = (int) $row['back'];
            $set[] = 'back = ?';
            $args[] = !empty($op['back']) ? 1 : 0;
        }
    } elseif ($type === 'arrow' && array_key_exists('from', $op)) {
        // Перецепили начало без новой ветки: прежние «да/нет» годятся только у выхода из ромба.
        arrowBranch($row['branch'], $nowFrom, $projectId);
    }
    $ends = array_key_exists('from', $op) || array_key_exists('to', $op);
    if ($ends && $type === 'link') {
        // Перецепили владельца — ярлык переезжает в его папку.
        [$nowFrom, $nowTo, $nowFolder] = linkEnds([
            'from' => array_key_exists('from', $op) ? $op['from'] : (int) $row['from_id'],
            'to'   => array_key_exists('to', $op) ? $op['to'] : (int) $row['to_id'],
        ], $ctx, 0, $id);
        $prev['from'] = (int) $row['from_id'];
        $prev['to']   = (int) $row['to_id'];
        array_push($set, 'from_id = ?', 'to_id = ?', 'folder_id = ?');
        array_push($args, $nowFrom, $nowTo, $nowFolder);
        $ctx['folders'][] = $nowFolder;
    } elseif ($ends) {
        if ($type !== 'arrow') throw new ApiError(t('server.element.ends_only'));
        if (array_key_exists('from', $op)) {
            $prev['from'] = (int) $row['from_id'];
            $set[] = 'from_id = ?';
            $args[] = requireLinkable($nowFrom, $projectId, 'arrow_start');
        }
        $nowTo = array_key_exists('to', $op)
            ? resolveRef($op['to'], $ctx, 'arrow_end') : (int) $row['to_id'];
        if (array_key_exists('to', $op)) {
            $prev['to'] = (int) $row['to_id'];
            $set[] = 'to_id = ?';
            $args[] = requireLinkable($nowTo, $projectId, 'arrow_end');
        }
        // Перецепили конец на тот же путь, где стрелка уже есть, — тоже двойник.
        if (dbValue("SELECT `no` FROM elements WHERE type = 'arrow' AND from_id = ? AND to_id = ? AND id <> ?",
                [$nowFrom, $nowTo, $id])) {
            throw new ApiError(t('server.element.arrow_exists'), 'conflict');
        }
    }
    if (array_key_exists('style', $op)) {
        $old = json_decode((string) $row['style'], true) ?: [];
        $prev['style'] = $old;
        $set[] = 'style = ?';
        $args[] = styleJson($type, (array) $op['style'], $old);
    }

    if ($set) {
        $set[] = 'rev = ?';
        $args[] = $ctx['rev'];
        $args[] = $id;
        dbRun('UPDATE elements SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }

    // Объект переехал в другую папку — его ярлыки едут с ним: ярлык живёт у владельца.
    if (array_key_exists('folder', $op)) {
        dbRun("UPDATE elements SET folder_id = ?, rev = ? WHERE type = 'link' AND from_id = ?",
            [(int) $op['folder'], $ctx['rev'], $id]);
    }

    if (array_key_exists('in', $op))    membersSet($id, (array) ($op['in'] ?? []), $ctx);
    if (array_key_exists('props', $op)) {
        propsPatch($id, (array) $op['props']);
        dbRun('UPDATE elements SET rev = ? WHERE id = ?', [$ctx['rev'], $id]);
    }

    $ctx['folders'][] = (int) $row['folder_id'];
    return ['id' => $id, 'no' => (int) $row['no'], 'prev' => $prev ?: null, 'folder_id' => (int) $row['folder_id']];
}

/** Операция element.delete. Стрелки уходят следом за своими концами (CASCADE). */
function elementDelete(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $id = resolveRef($op['id'] ?? null, $ctx);
    $row = elementRow($id, $projectId);

    // Надгробия ставим и на стрелки, которые уедут каскадом: клиент узнаёт об удалении
    // только по ним.
    $doomed = dbAll('SELECT id, `no`, type, title, folder_id FROM elements WHERE id = ? OR from_id = ? OR to_id = ?', [$id, $id, $id]);
    activeGuardFolders($projectId, array_column($doomed, 'folder_id'), 'delete_element');
    dbRun('DELETE FROM elements WHERE id = ?', [$id]);

    // Ушёл узел или стрелка прогона — история прошлых прогонов папки стирается (runsForget).
    $graph = array_filter($doomed, static fn(array $one) => in_array($one['type'], ['block', 'decision', 'gateway', 'arrow'], true));
    $forgot = 0;
    foreach (array_unique(array_column($graph, 'folder_id')) as $folderId) {
        $forgot += runsForget($projectId, (int) $folderId, $ctx['rev']);
    }

    foreach ($doomed as $gone) {
        markDeleted($projectId, 'element', (int) $gone['id'], $ctx['rev'], [
            'no' => (int) $gone['no'], 'folder_id' => (int) $gone['folder_id'], 'title' => $gone['title'],
        ]);
        // Ярлык из другой папки уехал каскадом — та папка тоже изменилась.
        $ctx['folders'][] = (int) $gone['folder_id'];
    }
    $ctx['folders'][] = (int) $row['folder_id'];
    return ['id' => $id, 'no' => (int) $row['no'], 'deleted' => count($doomed), 'folder_id' => (int) $row['folder_id']]
        + ($forgot ? ['runsForgotten' => $forgot] : []);
}

/**
 * Операция element.restore — отмена удаления.
 * Элемент возвращается под своим прежним номером: номер — это имя объекта
 * для человека, и вернуть объект под чужим именем значит не вернуть его.
 * Номер берётся обратно из надгробия, поэтому новым элементам он не достанется.
 */
function elementRestore(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $no = (int) ($op['no'] ?? 0);
    if ($no <= 0) throw new ApiError(t('server.element.restore_no'));

    $grave = dbRow(
        "SELECT * FROM deletions WHERE project_id = ? AND entity = 'element' AND `no` = ? ORDER BY id DESC LIMIT 1",
        [$projectId, $no]
    );
    if (!$grave) throw new ApiError(t('server.element.restore_nothing', ['no' => $no]), 'not_found');
    if (dbValue('SELECT id FROM elements WHERE project_id = ? AND `no` = ?', [$projectId, $no])) {
        throw new ApiError(t('server.element.restore_taken', ['no' => $no]), 'conflict');
    }

    $type = requireKind((string) ($op['type'] ?? ''));
    $folderId = (int) ($op['folder'] ?? $grave['folder_id']);
    sameProject(dbRow('SELECT * FROM folders WHERE id = ?', [$folderId]), $projectId, 'folder');

    $from = $to = $branch = $target = $agent = null;
    $back = 0;
    if ($type === 'link') {
        [$from, $to, $folderId] = linkEnds($op, $ctx, 0);
    }
    if ($type === 'arrow') {
        $from = requireLinkable(resolveRef($op['from'] ?? null, $ctx, 'arrow_start'), $projectId, 'arrow_start');
        $to   = requireLinkable(resolveRef($op['to'] ?? null, $ctx, 'arrow_end'), $projectId, 'arrow_end');
        $branch = arrowBranch($op['branch'] ?? 'flow', $from, $projectId);
        $back = !empty($op['back']) ? 1 : 0;
        // Между двумя объектами переход один: вторая такая же стрелка ничего
        // не добавляет к схеме, а прогон по ней пришлось бы толковать дважды.
        $twin = dbValue(
            "SELECT `no` FROM elements WHERE type = 'arrow' AND from_id = ? AND to_id = ?",
            [$from, $to]
        );
        if ($twin) {
            throw new ApiError(t('server.element.arrow_exists_with', ['twin' => $twin]), 'conflict');
        }
    }
    if ($type === 'gateway' && !empty($op['target'])) $target = gatewayTarget($op['target'], $projectId);
    if (!empty($op['agent'])) $agent = resolveAgentRef($op['agent'], $ctx);

    activeGuardFolders($projectId, array_merge([$folderId, $target], endFolders([$from, $to])), 'restore_element');

    dbRun(
        'INSERT INTO elements (project_id, folder_id, `no`, type, title, description, agent_id, from_id, to_id, branch, back, target_folder_id, style, rev)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $projectId, $folderId, $no, $type,
            mb_substr((string) ($op['title'] ?? ''), 0, 512),
            $op['description'] ?? null,
            $agent, $from, $to, $branch, $back, $target,
            styleJson($type, $op['style'] ?? [], []),
            $ctx['rev'],
        ]
    );
    $id = dbId();

    if (!empty($op['in']))    membersSet($id, (array) $op['in'], $ctx);
    if (!empty($op['props'])) propsPatch($id, (array) $op['props']);

    // Материалы пережили удаление — возвращаем только их привязки.
    foreach ((array) ($op['assets'] ?? []) as $link) {
        if (empty($link['asset'])) continue;
        assetLink(['asset' => $link['asset'], 'role' => $link['role'] ?? 'attachment',
                   'output' => $link['output'] ?? '', 'element' => $id], $ctx);
    }

    dbRun('DELETE FROM deletions WHERE id = ?', [$grave['id']]);
    $ctx['folders'][] = $folderId;
    return ['id' => $id, 'no' => $no, 'restored' => true, 'folder_id' => $folderId];
}

/**
 * Концы ярлыка: from — владелец, to — цель. Оба из этого проекта, папки любые.
 * Отдаёт [from, to, папка владельца]. $askedFolder — папка из запроса (0 — не задана):
 * если задана, она обязана совпасть с папкой владельца.
 */
function linkEnds(array $op, array &$ctx, int $askedFolder, int $selfId = 0): array
{
    $projectId = $ctx['projectId'];
    $owner  = requireLinkEnd(resolveRef($op['from'] ?? null, $ctx, 'link_owner'), $projectId, 'link_owner');
    $target = requireLinkEnd(resolveRef($op['to'] ?? null, $ctx, 'link_target'), $projectId, 'link_target');

    if ((int) $owner['id'] === (int) $target['id']) {
        throw new ApiError(t('server.element.link_self'));
    }
    $branch = $op['branch'] ?? null;
    if (($branch !== null && $branch !== '' && $branch !== 'flow') || !empty($op['back'])) {
        throw new ApiError(t('server.element.branch_back_arrow_only'));
    }

    $folderId = (int) $owner['folder_id'];
    if ($askedFolder && $askedFolder !== $folderId) {
        throw new ApiError(t('server.element.link_owner_folder'));
    }

    // Второй такой же ярлык ничего не добавляет — только загромождает край.
    $twin = dbValue(
        "SELECT `no` FROM elements WHERE type = 'link' AND from_id = ? AND to_id = ? AND id <> ?",
        [(int) $owner['id'], (int) $target['id'], $selfId]
    );
    if ($twin) throw new ApiError(t('server.element.link_exists', ['twin' => $twin]), 'conflict');

    return [(int) $owner['id'], (int) $target['id'], $folderId];
}

/**
 * Удалить ярлыки, у которых ровно один конец внутри этих папок, и поставить надгробия.
 * Зовут перенос папок в другой проект (ярлык не связывает разные проекты)
 * и удаление папок (каскад унёс бы их молча — открытые редакторы не узнали бы).
 * Отдаёт, сколько удалено.
 */
function linksCutAcross(array $folderIds, int $projectId, int $rev): int
{
    $ids = array_values(array_unique(array_map('intval', $folderIds)));
    if (!$ids) return 0;
    $in = implode(',', $ids);

    $rows = dbAll(
        "SELECT l.id, l.`no`, l.title, l.folder_id
           FROM elements l
           JOIN elements f ON f.id = l.from_id
           JOIN elements t ON t.id = l.to_id
          WHERE l.type = 'link' AND l.project_id = ?
            AND (f.folder_id IN ($in)) <> (t.folder_id IN ($in))",
        [$projectId]
    );
    foreach ($rows as $row) {
        dbRun('DELETE FROM elements WHERE id = ?', [(int) $row['id']]);
        markDeleted($projectId, 'element', (int) $row['id'], $rev, [
            'no' => (int) $row['no'], 'folder_id' => (int) $row['folder_id'], 'title' => $row['title'],
        ]);
        touchFolder((int) $row['folder_id'], $rev);
    }
    return count($rows);
}

/** Папки, где лежат эти элементы (пустые ссылки пропускаются) — для защиты живого прогона. */
function endFolders(array $elementIds): array
{
    $ids = array_values(array_filter(array_map('intval', $elementIds)));
    if (!$ids) return [];
    $rows = dbAll('SELECT DISTINCT folder_id FROM elements WHERE id IN (' . implode(',', $ids) . ')');
    return array_map('intval', array_column($rows, 'folder_id'));
}

/**
 * Обнулить цель у шлюзов, у которых папка и цель по разные стороны границы этих папок,
 * и поднять им ревизию. Без этого внешний ключ обнулил бы цель молча — открытые редакторы
 * не узнали бы. Зовут перенос и удаление папок. Отдаёт, сколько шлюзов затронуто.
 */
function gatesCutAcross(array $folderIds, int $projectId, int $rev): int
{
    $ids = array_values(array_unique(array_map('intval', $folderIds)));
    if (!$ids) return 0;
    $in = implode(',', $ids);

    $rows = dbAll(
        "SELECT id, folder_id FROM elements
          WHERE type = 'gateway' AND project_id = ? AND target_folder_id IS NOT NULL
            AND (folder_id IN ($in)) <> (target_folder_id IN ($in))",
        [$projectId]
    );
    foreach ($rows as $row) {
        dbRun('UPDATE elements SET target_folder_id = NULL, rev = ? WHERE id = ?', [$rev, (int) $row['id']]);
        touchFolder((int) $row['folder_id'], $rev);
    }
    return count($rows);
}

/** Агент: число — id, строка — временная ссылка на агента, созданного в этой же пачке. */
function resolveAgentRef($value, array &$ctx): int
{
    if (is_string($value) && isset($ctx['refs'][$value])) $value = $ctx['refs'][$value];
    if (!is_numeric($value)) throw new ApiError(t('server.not_found.agent'), 'not_found');
    return (int) agentRow((int) $value, $ctx['projectId'])['id'];
}

/** Ветка стрелки: «да» и «нет» бывают только у выхода из ромба. */
function arrowBranch($value, int $fromId, int $projectId): string
{
    $branch = (string) ($value ?: 'flow');
    if (!in_array($branch, ['flow', 'yes', 'no'], true)) {
        throw new ApiError(t('server.element.branch_values'));
    }
    if ($branch !== 'flow') {
        $from = dbRow('SELECT type FROM elements WHERE id = ? AND project_id = ?', [$fromId, $projectId]);
        if (!$from || $from['type'] !== 'decision') {
            throw new ApiError(t('server.element.branch_decision_only'));
        }
    }
    return $branch;
}

/** Цель шлюза — папка того же проекта. */
function gatewayTarget($value, int $projectId): int
{
    $folder = dbRow('SELECT id, project_id FROM folders WHERE id = ?', [(int) $value]);
    sameProject($folder, $projectId, 'target_folder');
    return (int) $folder['id'];
}

/**
 * Цвет: именованный ключ из config.txt, пастельный 1–6, шестнадцатеричный,
 * узор (полоски и градиенты) или роль стрелки. Прежние схемы красились
 * всем этим, и перенос обязан их сохранить.
 */
function colorAllowed(string $value): bool
{
    if (listHas('colors', $value)) return true;
    if (in_array($value, ['1', '2', '3', '4', '5', '6'], true)) return true;
    if (in_array($value, ['muted', 'accent', 'link'], true)) return true;
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)) return true;
    return (bool) preg_match('/^(stripe|gradient)-[a-z-]+$/', $value);
}

/**
 * Оформление: присланные ключи накладываются на прежние, null убирает ключ.
 * Цвет — только ключ палитры из config.txt: произвольный HEX не переживает смену темы.
 */
function styleJson(string $type, array $patch, array $old): string
{
    $style = $old;
    foreach ($patch as $key => $value) {
        if ($value === null) { unset($style[$key]); continue; }
        if ($key === 'color' && $value !== '' && !colorAllowed((string) $value)) {
            throw new ApiError(t('server.element.unknown_color', ['value' => $value]));
        }
        $style[$key] = $value;
    }
    // Блок без глубины уходит под полосу — сервер поднимает его сам.
    if (in_array($type, ['block', 'decision', 'gateway'], true) && !isset($style['z'])) {
        $style['z'] = 1;
    }
    return json_encode($style, JSON_UNESCAPED_UNICODE);
}
