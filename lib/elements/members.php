<?php
/* Состав: кто лежит в какой группе или области.
   Отдаёт: membersSet(), membersOf(), containersOf(), membersMap().
   Не делает: не угадывает вхождение по координатам — прилепили руками, значит вошёл.

   У элемента может быть несколько контейнеров. Стрелка и ярлык участниками
   не бывают: они следуют за своими концами. */

declare(strict_types=1);

/** Переписать список контейнеров элемента целиком. */
function membersSet(int $elementId, array $containers, array &$ctx): void
{
    $projectId = $ctx['projectId'];
    $element = elementRow($elementId, $projectId);
    activeGuardElement($element, 'edit_members');

    if (in_array($element['type'], ['arrow', 'link'], true) && $containers) {
        throw new ApiError(t('server.members.arrow_link'));
    }

    $ids = [];
    foreach ($containers as $value) {
        $id = resolveRef($value, $ctx, 'container');
        $container = elementRow($id, $projectId);

        if (!isContainer((string) $container['type'])) {
            throw new ApiError(t('server.members.not_container', ['kind' => kindWord((string) $container['type'])]));
        }
        if (!canContain((string) $container['type'], (string) $element['type'])) {
            throw new ApiError(t('server.members.cant_contain', ['container' => kindWord((string) $container['type']), 'child' => kindWord((string) $element['type'])]));
        }
        if ((int) $container['folder_id'] !== (int) $element['folder_id']) {
            throw new ApiError(t('server.members.same_folder'));
        }
        if ($id === $elementId) throw new ApiError(t('server.members.self'));
        membersNoCycle($id, $elementId);
        $ids[] = $id;
    }

    dbRun('DELETE FROM members WHERE element_id = ?', [$elementId]);
    $sort = 0;
    foreach (array_unique($ids) as $id) {
        dbRun('INSERT INTO members (container_id, element_id, sort) VALUES (?,?,?)', [$id, $elementId, $sort++]);
    }
    dbRun('UPDATE elements SET rev = ? WHERE id = ?', [$ctx['rev'], $elementId]);
}

/** Контейнер не может оказаться внутри самого себя — ни прямо, ни через цепочку. */
function membersNoCycle(int $containerId, int $elementId): void
{
    $seen = [];
    $queue = [$containerId];
    while ($queue) {
        $id = array_pop($queue);
        if ($id === $elementId) throw new ApiError(t('server.members.ring'));
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        foreach (dbAll('SELECT container_id FROM members WHERE element_id = ?', [$id]) as $row) {
            $queue[] = (int) $row['container_id'];
        }
    }
}

/** Кто лежит в контейнере. */
function membersOf(int $containerId): array
{
    return array_map('intval', array_column(
        dbAll('SELECT element_id FROM members WHERE container_id = ? ORDER BY sort', [$containerId]),
        'element_id'
    ));
}

/** В каких контейнерах лежит элемент. */
function containersOf(int $elementId): array
{
    return array_map('intval', array_column(
        dbAll('SELECT container_id FROM members WHERE element_id = ? ORDER BY sort', [$elementId]),
        'container_id'
    ));
}

/** Весь состав папки одним запросом: element_id → [container_id…]. */
function membersMap(int $folderId): array
{
    $rows = dbAll(
        'SELECT m.element_id, m.container_id
           FROM members m
           JOIN elements e ON e.id = m.element_id
          WHERE e.folder_id = ?
          ORDER BY m.sort',
        [$folderId]
    );
    $map = [];
    foreach ($rows as $row) $map[(int) $row['element_id']][] = (int) $row['container_id'];
    return $map;
}
