<?php
/* Чтение одного элемента и поиск по свойству.
   Отдаёт: elementGetOp().
   Не делает: не меняет данные. */

declare(strict_types=1);

/** GET element.get: ?element=ID | ?no=НОМЕР | ?prop=имя&value=значение */
function elementGetOp(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $view = askedView();

    // Поиск по допу — тем же индексом, что и раньше op=find.
    if ($name = (string) (input('prop') ?? '')) {
        $value = (string) (input('value') ?? '');
        $rows = dbAll(
            'SELECT e.* FROM elements e JOIN props p ON p.element_id = e.id
              WHERE e.project_id = ? AND p.name = ?' . ($value !== '' ? ' AND p.value = ?' : '') . '
              ORDER BY e.`no` LIMIT 200',
            $value !== '' ? [$projectId, $name, $value] : [$projectId, $name]
        );
        reply(['elements' => array_map(static fn(array $r) => elementShape($r, $view), $rows)]);
    }

    $id = inputInt('element');
    $no = inputInt('no');
    $row = $id
        ? elementRow($id, $projectId)
        : dbRow('SELECT * FROM elements WHERE project_id = ? AND `no` = ?', [$projectId, $no]);
    if (!$row) throw new ApiError(t('server.not_found.element'), 'not_found');

    $id = (int) $row['id'];
    $spec = specText('element_id', $id);

    reply([
        'element' => elementShape($row, $view, [
            'in'      => containersOf($id),
            'props'   => propsOf($id),
            'assets'  => assetLinksOfFolder((int) $row['folder_id'])[$id] ?? [],
            'hasSpec' => $spec !== null,
        ]),
        'spec' => $spec,
    ]);
}
