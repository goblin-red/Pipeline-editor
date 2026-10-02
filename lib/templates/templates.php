<?php
/* Готовые схемы: снять заготовку с папки и развернуть её обратно в папку.
   Отдаёт: templateGet(), templateSave(), templateApply(), templateDelete(), templateDrop(),
           templateDeploy(), templateCheck(), templateMerge(), templateBodyOfFolder(), templateLost(), templateShape().
   Не делает: не рисует и не решает, куда разворачивать — папку называет вызвавший.

   Тело заготовки — это схема в понятиях v2, где вместо id стоят ссылки `ref`, и настройки прогона папки:
     {"folder":{"roleScheme":"solo","runEnv":"subagents","shareScheme":false},
      "elements":[{"ref":"e1","type":"block","title":…,"style":{…},"in":["e4"],"spec":"…"},
                  {"ref":"a1","type":"arrow","from":"e1","to":"e2","branch":"yes"}]}
   Каталог (in_catalog, паспорт) — lib/templates/catalog.php.
   Поэтому разворот — это обычная пачка element.create: ту же машинку, что рисует
   схему мышью, используем и здесь, и ничего не дублируется. */

declare(strict_types=1);

const TEMPLATE_FAMILIES = ['scheme' => 'схема-образец', 'flow' => 'демо-прогон'];

function templateShape(array $row, bool $withBody = false): array
{
    $out = [
        'id'       => (int) $row['id'],
        'key'      => (string) $row['key'],
        'title'    => (string) $row['title'],
        'family'   => (string) $row['family'],
        'category' => (string) $row['category'],
        'about'    => (string) $row['about'],
        'builtin'  => (bool) $row['builtin'],
        'sort'     => (int) $row['sort'],
    ];
    $body = json_decode((string) $row['body'], true) ?: ['elements' => []];
    $out['count'] = count($body['elements'] ?? []);
    if ($withBody) $out['body'] = $body;
    return $out;
}

/** GET template.get — полка заготовок или одна с телом. */
function templateGet(): void
{
    requireProject(false);          // заготовки общие, но смотрит их участник проекта

    if ($key = (string) (input('template') ?? '')) {
        $row = templateRow($key);
        reply(['template' => templateShape($row, true)]);
    }
    $rows = dbAll('SELECT * FROM templates ORDER BY family, sort, id');
    reply(['templates' => array_map(static fn(array $row) => templateShape($row), $rows)]);
}

function templateRow(string $key, ?string $lang = null): array
{
    $row = ctype_digit($key)
        ? dbRow('SELECT * FROM templates WHERE id = ?', [(int) $key])
        : dbRow('SELECT * FROM templates WHERE `key` = ?', [$key]);
    if (!$row) throw new ApiError(t('server.template.none', ['key' => $key]), 'not_found');
    return catalogLocal($row, $lang);   // по умолчанию — язык проекта: ТЗ читают его агенты
}

/** POST template.save — снять заготовку с папки как она есть. */
function templateSave(): void
{
    $project = requireProject(true);
    journalSingleProject($project);
    $user = currentUser();

    $folder = folderRow((int) inputInt('folder'), (int) $project['id']);
    $title = trim((string) (input('title') ?? '')) ?: (string) $folder['name'];
    if ($title === '') throw new ApiError(t('server.template.need_title'));

    $key = (string) (input('key') ?? '') ?: templateFreeKey($title);
    if (mb_strlen($key) > 64) throw new ApiError(t('server.template.key_long', ['max' => 64]));   // templates.key varchar(64)
    if (dbValue('SELECT id FROM templates WHERE `key` = ?', [$key])) {
        throw new ApiError(t('server.template.key_taken', ['key' => $key]), 'conflict');
    }

    $body = templateBodyOfFolder((int) $folder['id']);
    if (!$body['elements']) throw new ApiError(t('server.template.folder_empty'));

    dbRun(
        'INSERT INTO templates (`key`, title, family, category, about, body, sort, builtin, owner_id)
         VALUES (?,?,?,?,?,?,?,0,?)',
        [
            $key, mb_substr($title, 0, 255),
            templateFamily((string) (input('family') ?? 'scheme')),
            mb_substr((string) (input('category') ?? t('server.template.own_category')), 0, 64),
            mb_substr((string) (input('about') ?? ''), 0, 512),
            json_encode($body, JSON_UNESCAPED_UNICODE),
            (int) dbValue('SELECT COALESCE(MAX(sort), 0) + 10 FROM templates'),
            $user ? (int) $user['id'] : null,
        ]
    );
    $id = dbId();
    reply(['id' => $id, 'key' => $key, 'count' => count($body['elements'])] + array_filter(['lost' => templateLost((int) $folder['id'])]));
}

/** POST template.apply — развернуть заготовку в новую папку (или в указанную). */
function templateApply(): void
{
    // Журнал пишет сам разворот (запись «заготовка key»), вторая запись команды не нужна.
    $project = requireProject(true);

    $row = templateRow((string) (input('template') ?? ''), lang());   // схема из окна каталога — на языке, в котором её выбрали
    $body = json_decode((string) $row['body'], true) ?: [];
    if (!($body['elements'] ?? [])) throw new ApiError(t('server.template.empty'), 'not_found');

    $made = templateDeploy($project, $body, [
        'folder' => inputInt('folder'),
        'name'   => (string) (input('name') ?? $row['title']),
        'parent' => input('parent') ? (int) input('parent') : null,
        'label'  => t('server.template.journal_key', ['key' => $row['key']]),
    ]);
    // Готова ли схема к прогону — сразу: заготовка каталога обязана пройти предпроверку.
    $made['problems'] = array_column(folderPrecheck(folderRow($made['folder'], (int) $project['id']))['problems'], 'say');
    reply($made);
}

/**
 * Тело заготовки — в папку. Одна машинка для каталога и конструктора.
 * Папка новая (с настройками прогона из тела) или названная в $opts['folder'].
 * $opts: folder, name, parent, label и via (для журнала), workDir (пробный разворот — готовая папка на диске).
 * Отдаёт ['folder' => id, 'elements' => сколько создано, 'rev' => ревизия].
 */
function templateDeploy(array $project, array $body, array $opts): array
{
    $projectId = (int) $project['id'];
    $elements = $body['elements'] ?? [];
    $folderId = (int) ($opts['folder'] ?? 0);
    $made = 0;

    // Ревизия, журнал и схема — под замком проекта, как у пачки: параллельная правка не обгонит дельту.
    $rev = engineLocked($projectId, static function () use ($project, $projectId, &$folderId, $elements, $body, $opts, &$made) {
        $rev = bumpRev($projectId);
        $journalId = journalOpen($projectId, $rev, null, (string) ($opts['via'] ?? 'tool'),
            ['label' => (string) ($opts['label'] ?? t('server.template.journal'))]);
        $ctx = ['project' => $project, 'projectId' => $projectId, 'rev' => $rev,
                'journalId' => $journalId, 'refs' => [], 'folders' => [], 'warnings' => []];

        // Папки нет — заводим её с именем и настройками прогона из тела: разворот всегда во что-то.
        if (!$folderId) {
            $settings = (array) ($body['folder'] ?? []);
            $folderId = folderCreate([
                'name' => (string) ($opts['name'] ?? t('server.ai.new_scheme')),
                'parent' => $opts['parent'] ?? null,
                'workDir' => (string) ($opts['workDir'] ?? ''),
                'roleScheme' => (string) ($settings['roleScheme'] ?? ''),
                'runEnv' => (string) ($settings['runEnv'] ?? ''),
                'shareScheme' => !empty($settings['shareScheme']),
            ], $ctx)['id'];
        } else {
            folderRow($folderId, $projectId);
        }

        // Сначала всё, кроме стрелок и ярлыков: у них должны быть готовы концы.
        $withEnds = ['arrow', 'link'];
        foreach ([false, true] as $ends) {
            foreach ($elements as $item) {
                if (in_array($item['type'] ?? '', $withEnds, true) !== $ends) continue;
                templatePut($item, $folderId, $ctx);
                $made++;
            }
        }
        // Состав — последним: контейнеры к этому времени уже существуют.
        foreach ($elements as $item) {
            if (empty($item['in']) || empty($item['ref'])) continue;
            $id = $ctx['refs'][$item['ref']] ?? null;
            if ($id) membersSet($id, (array) $item['in'], $ctx);
        }
        touchFolder((int) $folderId, $rev);
        return $rev;
    });

    return ['folder' => (int) $folderId, 'elements' => $made, 'rev' => $rev];
}

/**
 * Пробный разворот тела: в транзакции, которую откатываем, — отказ сервера или помехи
 * предпроверки. Диск не трогаем: рабочая папка пробной папки — общая папка workfiles.
 * Отдаёт ['error' => отказ или null, 'problems' => помехи прогона].
 */
function templateCheck(array $project, array $body): array
{
    dbBegin();
    $GLOBALS['dbUndo'] = [];
    batchDryRun(true);
    try {
        $base = rtrim((string) (config()['workfiles_dir'] ?? config()['workspace_dir']), '/');
        $made = templateDeploy($project, $body, ['name' => t('server.template.check_name'), 'workDir' => $base,
            'label' => t('server.template.check_label')]);
        $problems = array_column(folderPrecheck(folderRow($made['folder'], (int) $project['id']))['problems'], 'say');
        return ['error' => null, 'problems' => $problems];
    } catch (Throwable $error) {
        return ['error' => $error->getMessage(), 'problems' => []];
    } finally {
        batchDryRun(false);
        dbRollback();
        dbUndoDisk();
    }
}

/**
 * Разница поверх тела — так конструктор правит основу, не переписывая её целиком.
 * Элемент с ref тела: поля заменяются, style и props — по ключам (null удаляет); delete — долой
 * вместе со своими стрелками и ярлыками. Новый ref — новый элемент (без type — блок).
 * Порядок тела сохраняется, новые — в конце.
 */
function templateMerge(array $base, array $diff): array
{
    $items = [];
    foreach ($base['elements'] ?? [] as $item) {
        if (!empty($item['ref'])) $items[(string) $item['ref']] = $item;
    }
    foreach ($diff as $change) {
        if (!is_array($change) || !isset($change['ref']) || (string) $change['ref'] === '') continue;
        $ref = (string) $change['ref'];
        if (!empty($change['delete'])) { unset($items[$ref]); continue; }
        $item = $items[$ref] ?? ['ref' => $ref, 'type' => 'block'];
        foreach ($change as $key => $value) {
            if ($key === 'delete') continue;
            if (($key === 'style' || $key === 'props') && is_array($value)) {
                $merged = (array) ($item[$key] ?? []);
                foreach ($value as $name => $one) {
                    if ($one === null) unset($merged[$name]); else $merged[$name] = $one;
                }
                $item[$key] = $merged;
            } else {
                $item[$key] = $value;
            }
        }
        $items[$ref] = $item;
    }

    // Стрелки и ярлыки без конца — долой; из состава — убранные контейнеры.
    $out = [];
    foreach ($items as $item) {
        $ends = in_array($item['type'] ?? '', ['arrow', 'link'], true);
        if ($ends && (!isset($items[(string) ($item['from'] ?? '')]) || !isset($items[(string) ($item['to'] ?? '')]))) continue;
        if (!empty($item['in'])) {
            $item['in'] = array_values(array_filter((array) $item['in'], static fn($ref) => isset($items[(string) $ref])));
        }
        $out[] = $item;
    }
    return ['folder' => (array) ($base['folder'] ?? []), 'elements' => $out];
}

/** Один элемент заготовки становится элементом схемы. */
function templatePut(array $item, int $folderId, array &$ctx): void
{
    $op = [
        'op' => 'element.create',
        'folder' => $folderId,
        'type' => (string) ($item['type'] ?? 'block'),
        'title' => (string) ($item['title'] ?? ''),
        'description' => (string) ($item['description'] ?? ''),
        'style' => (array) ($item['style'] ?? []),
    ];
    if (!empty($item['ref'])) $op['ref'] = $item['ref'];
    foreach (['from', 'to', 'branch', 'back', 'props'] as $name) {
        if (isset($item[$name])) $op[$name] = $item[$name];
    }
    $result = elementCreate($op, $ctx);
    if (!empty($item['ref'])) $ctx['refs'][$item['ref']] = (int) $result['id'];

    // ТЗ заготовки — обычный ассет с ролью spec.
    if (!empty($item['spec'])) {
        assetCreate([
            'kind' => 'spec', 'title' => ta('agents.template.spec_title', ['title' => $item['title'] ?: $item['ref'] ?? '']),
            'text' => (string) $item['spec'],
            'link' => ['element' => (int) $result['id'], 'role' => 'spec'],
        ], $ctx);
    }
}

/** POST template.delete — свою можно, встроенную нет. */
function templateDelete(): void
{
    $project = requireProject(true);
    journalSingleProject($project);

    $row = templateRow((string) (input('template') ?? ''));
    templateDrop($row, callerRole() === 'admin');
    reply(['deleted' => true, 'key' => $row['key']]);
}

/**
 * Убрать заготовку с полки — одно правило для API и кабинета: встроенную нельзя никому,
 * свою — хозяину, прочие — только администратору ($admin: пропуск админа в API, вход в админку в кабинете).
 * Полка общая на всю установку, а проект у каждого свой: без этой проверки
 * любой, кто пишет в свой проект, стирал бы чужие заготовки.
 */
function templateDrop(array $row, bool $admin): void
{
    if ($row['builtin']) throw new ApiError(t('server.template.builtin'), 'forbidden');
    $user = currentUser();
    $mine = $row['owner_id'] && $user && (int) $row['owner_id'] === (int) $user['id'];
    if (!$mine && !$admin) throw new ApiError(t('server.template.not_yours'), 'forbidden');
    dbRun('DELETE FROM templates WHERE id = ?', [$row['id']]);
}

/* ── Снятие заготовки с папки ─────────────────────────────────── */

/** Папка целиком в виде тела заготовки: номера заменяются ссылками. */
function templateBodyOfFolder(int $folderId): array
{
    $rows = dbAll('SELECT * FROM elements WHERE folder_id = ? ORDER BY `no`', [$folderId]);
    $refs = [];
    foreach ($rows as $row) $refs[(int) $row['id']] = 'e' . $row['no'];

    $members = membersMap($folderId);
    $props = propsMap($folderId);
    $specs = dbAll(
        "SELECT l.element_id, a.body FROM asset_links l
           JOIN assets a ON a.id = l.asset_id
           JOIN elements e ON e.id = l.element_id
          WHERE e.folder_id = ? AND l.role = 'spec'",
        [$folderId]
    );
    $specOf = [];
    foreach ($specs as $spec) $specOf[(int) $spec['element_id']] = (string) $spec['body'];

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $style = json_decode((string) $row['style'], true) ?: [];
        unset($style['locked']);                       // запертость — дело схемы, не заготовки

        $item = [
            'ref'   => $refs[$id],
            'type'  => (string) $row['type'],
            'title' => (string) $row['title'],
            'style' => $style,
        ];
        if ($row['description'] !== null && $row['description'] !== '') $item['description'] = (string) $row['description'];
        if ($row['type'] === 'arrow' || $row['type'] === 'link') {
            // Стрелка или ярлык без обоих концов в этой же папке заготовке не нужны.
            if (!isset($refs[(int) $row['from_id']], $refs[(int) $row['to_id']])) continue;
            $item['from'] = $refs[(int) $row['from_id']];
            $item['to']   = $refs[(int) $row['to_id']];
        }
        if ($row['type'] === 'arrow') {
            if ($row['branch'] !== 'flow') $item['branch'] = (string) $row['branch'];
            if ((int) $row['back']) $item['back'] = true;
        }
        if (!empty($members[$id])) {
            $item['in'] = array_values(array_filter(array_map(
                static fn($cid) => $refs[(int) $cid] ?? null, $members[$id])));
        }
        if (!empty($props[$id])) $item['props'] = $props[$id];
        if (isset($specOf[$id]) && $specOf[$id] !== '') $item['spec'] = $specOf[$id];
        $out[] = $item;
    }
    // Настройки прогона папки едут с заготовкой: состав команды, среда, общая картина.
    $folder = dbRow('SELECT role_scheme, run_env, share_scheme FROM folders WHERE id = ?', [$folderId]);
    return ['folder' => ['roleScheme' => (string) $folder['role_scheme'], 'runEnv' => (string) $folder['run_env'],
                         'shareScheme' => (bool) $folder['share_scheme']],
            'elements' => $out];
}

/**
 * Что заготовка папки не переносит (A19): материалы (кроме ТЗ), назначенных агентов, цели шлюзов,
 * вложенные папки и стрелки или ярлыки в другие папки. Одной строкой «Не перенеслось: …»; всё едет — ''.
 */
function templateLost(int $folderId): string
{
    $count = [
        'server.template.lost_assets'  => (int) dbValue("SELECT COUNT(*) FROM asset_links l LEFT JOIN elements e ON e.id = l.element_id
                                     WHERE (e.folder_id = ? AND l.role <> 'spec') OR l.folder_id = ?", [$folderId, $folderId]),
        'server.template.lost_agents'  => (int) dbValue('SELECT COUNT(*) FROM elements WHERE folder_id = ? AND agent_id IS NOT NULL', [$folderId]),
        'server.template.lost_gates'   => (int) dbValue("SELECT COUNT(*) FROM elements WHERE folder_id = ? AND type = 'gateway' AND target_folder_id IS NOT NULL", [$folderId]),
        'server.template.lost_folders' => (int) dbValue('SELECT COUNT(*) FROM folders WHERE parent_id = ?', [$folderId]),
        'server.template.lost_links'   => (int) dbValue("SELECT COUNT(*) FROM elements a
                                     LEFT JOIN elements f ON f.id = a.from_id LEFT JOIN elements t ON t.id = a.to_id
                                     WHERE a.folder_id = ? AND a.type IN ('arrow', 'link')
                                       AND (f.folder_id <> a.folder_id OR t.folder_id <> a.folder_id)", [$folderId]),
    ];
    $said = [];
    foreach (array_filter($count) as $key => $n) $said[] = t($key, ['n' => $n]);
    return $said ? t('server.template.lost', ['list' => implode(', ', $said)]) : '';
}

function templateFamily(string $family): string
{
    return isset(TEMPLATE_FAMILIES[$family]) ? $family : 'scheme';
}

/** Ключ из названия: латиницей, через дефис, свободный. */
function templateFreeKey(string $title): string
{
    $base = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(translitLatin($title)));
    $base = trim((string) $base, '-') ?: 'zagotovka';
    $key = $base;
    $n = 2;
    while (dbValue('SELECT id FROM templates WHERE `key` = ?', [$key])) $key = $base . '-' . $n++;
    return substr($key, 0, 64);
}

function translitLatin(string $text): string
{
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    return strtr(mb_strtolower($text), $map);
}
