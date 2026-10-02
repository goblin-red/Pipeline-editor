<?php
/* Как элемент выглядит в ответе API. Здесь же живёт различие
   первичного и вторичного — то самое, ради которого агенты не видят оформления.
   Отдаёт: elementShape(), folderShape(), linkEndsMap(), VIEW_WORK, VIEW_FULL.
   Не делает: не ходит в базу за соседями — данные передаются готовыми.
              Исключение — ярлык без готовых концов (дельта, один элемент):
              его концы дочитываются одним запросом.

   Рабочий вид (work) — только первичное: номер, тип, название, описание,
   куда входит, связи. Полный вид (full) — плюс style, свойства и ассеты. */

declare(strict_types=1);

const VIEW_WORK = 'work';
const VIEW_FULL = 'full';

/**
 * Элемент в ответе.
 *
 * @param array $row   строка elements
 * @param array $extra ['in' => [id…], 'props' => [...], 'assets' => [...], 'hasSpec' => bool,
 *                     'ends' => linkEndsMap() — концы ярлыков, если уже прочитаны]
 */
function elementShape(array $row, string $view = VIEW_FULL, array $extra = []): array
{
    $type = (string) $row['type'];

    // ── Первичное: смысл и связи ──────────────────────────────
    $out = [
        'id'    => (int) $row['id'],
        'no'    => (int) $row['no'],
        'type'  => $type,
        'title' => (string) $row['title'],
    ];
    if (($row['description'] ?? '') !== '' && $row['description'] !== null) {
        $out['description'] = (string) $row['description'];
    }
    $out['folder'] = (int) $row['folder_id'];
    if (!empty($extra['in'])) $out['in'] = array_map('intval', $extra['in']);

    if ($type === 'arrow') {
        $out['from'] = (int) $row['from_id'];
        $out['to']   = (int) $row['to_id'];
        if ($row['branch'] && $row['branch'] !== 'flow') $out['branch'] = (string) $row['branch'];
        if ((int) $row['back'] === 1) $out['back'] = true;
    }
    if ($type === 'link') {
        $out = array_merge($out, linkShape($row, $view, $extra['ends'] ?? linkEndsMap([$row])));
    }
    if ($type === 'gateway' && $row['target_folder_id']) {
        $out['target'] = (int) $row['target_folder_id'];
    }
    if ($row['agent_id']) $out['agent'] = (int) $row['agent_id'];
    // Признак шлём всегда, когда его посчитали: пропуск значил бы «не знаю»,
    // и на холсте оставался бы старый значок MD у блока с удалённым ТЗ.
    if (array_key_exists('hasSpec', $extra)) $out['hasSpec'] = (bool) $extra['hasSpec'];

    /* Правила прогона (`cond`, `answer`, `max_attempts`, `join`…) — это смысл,
       а не оформление: без них не видно ни ветки ромба, ни образца ответа.
       Раньше они отрезались вместе со стилем, и встроенный ИИ честно писал
       «условия не видно» о схеме, где условие стоит. */
    if (!empty($extra['props'])) $out['props'] = $extra['props'];

    if ($view === VIEW_WORK) return $out;   // агентам оформление не нужно

    // ── Вторичное: оформление и ассеты ────────────────────────
    $style = json_decode((string) $row['style'], true);
    $out['style'] = $style ? $style : new stdClass();
    if (!empty($extra['assets'])) $out['assets'] = $extra['assets'];
    $out['rev'] = (int) $row['rev'];
    return $out;
}

/**
 * Концы ярлыка в ответе. Обоим видам — id и папки концов: без папки ярлык
 * в другую папку не прочесть. Полному виду — ещё номер, тип и название
 * каждого конца: холст подписывает ими кружки, не зная чужой папки.
 */
function linkShape(array $row, string $view, array $ends): array
{
    $out = [];
    foreach (['from' => (int) $row['from_id'], 'to' => (int) $row['to_id']] as $side => $id) {
        $end = $ends[$id] ?? null;
        $out[$side] = $id;
        $out[$side . 'Folder'] = $end ? (int) $end['folder_id'] : null;
        if ($view === VIEW_WORK || !$end) continue;
        $out[$side . 'No']    = (int) $end['no'];
        $out[$side . 'Type']  = (string) $end['type'];
        $out[$side . 'Title'] = (string) $end['title'];
    }
    return $out;
}

/** Концы ярлыков одним запросом: id конца → строка (id, no, type, title, folder_id). */
function linkEndsMap(array $linkRows): array
{
    $ids = [];
    foreach ($linkRows as $row) {
        if (($row['type'] ?? '') !== 'link') continue;
        $ids[] = (int) $row['from_id'];
        $ids[] = (int) $row['to_id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];

    $rows = dbAll(
        'SELECT id, `no`, type, title, folder_id FROM elements WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    );
    $map = [];
    foreach ($rows as $one) $map[(int) $one['id']] = $one;
    return $map;
}

/** Папка в ответе. Камера и оформление — только в полном виде. */
function folderShape(array $row, string $view = VIEW_FULL): array
{
    $out = [
        'id'      => (int) $row['id'],
        'name'    => (string) $row['name'],
        'parent'  => $row['parent_id'] ? (int) $row['parent_id'] : null,
        'sort'    => (int) $row['sort'],
        'workDir' => (string) $row['work_dir'],
    ];
    // Агенты в вебе: папка схемы — у агента, путь от его текущей папки (agentFolderPath).
    if (agentsRemote() && isset($row['project_id'])) $out['agentPath'] = agentFolderPath($row);
    if ($view === VIEW_WORK) return $out;

    $out['workUrl']     = (string) $row['work_url'];
    $out['shareScheme'] = (int) $row['share_scheme'] === 1;
    $out['runEnv']      = (string) ($row['run_env'] ?? '');
    $out['roleScheme']  = (string) ($row['role_scheme'] ?? 'solo');
    $style = json_decode((string) $row['style'], true);
    $out['style']       = $style ? $style : new stdClass();
    $out['camera']      = $row['camera'] ? json_decode((string) $row['camera'], true) : null;
    $out['contentRev']  = (int) $row['content_rev'];
    $out['rev']         = (int) $row['rev'];
    // Сколько объектов в папке — без стрелок и ярлыков: его видно у каждой папки в рейке.
    $out['count']       = (int) dbValue(
        "SELECT COUNT(*) FROM elements WHERE folder_id = ? AND type NOT IN ('arrow', 'link')", [(int) $row['id']]);
    return $out;
}

/** Какой вид просят: work или full. leader и worker всегда отдаётся work. */
function askedView(): string
{
    $role = callerRole();
    if ($role === 'lead' || $role === 'worker') return VIEW_WORK;
    return ((string) (input('view') ?? '')) === VIEW_WORK ? VIEW_WORK : VIEW_FULL;
}
