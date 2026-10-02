<?php
/* Утилита, лицевая часть: дерево слева, карточка выбранного справа.
   Отдаёт: structureView().
   Не делает: ничего не сохраняет — правки уходят формами в structure.php.

   Дерево устроено как раньше: проекты → папки (с вложенностью) → элементы.
   Стрелок в дереве нет — они в «Связях» карточки. Щёлкнул — справа всё
   об объекте, там же и правится. */

declare(strict_types=1);

/** Значок и слово для типа элемента. Стартер — блок с флажком start. */
function structureKinds(): array
{
    return [
        'block' => ['▣', t('account.kind.block')], 'decision' => ['◆', t('account.kind.decision')], 'gateway' => ['⬡', t('account.kind.gateway')],
        'arrow' => ['→', t('account.kind.arrow')], 'group' => ['▭', t('account.kind.group')], 'area' => ['▤', t('account.kind.area')],
        'note' => ['✎', t('account.kind.note')], 'table' => ['▦', t('account.kind.table')], 'link' => ['↗', t('account.kind.link')],
    ];
}

/** Роли материалов словами. */
function structureRoles(): array
{
    return [
        'spec' => t('account.role.spec'), 'code' => t('account.role.code'), 'input' => t('account.role.input'),
        'reference' => t('account.role.reference'), 'attachment' => t('account.role.attachment'), 'cover' => t('account.role.cover'),
        'result' => t('account.role.result'), 'preview' => t('account.role.preview'),
    ];
}

/** Состояния попытки словами. */
function structureStates(): array
{
    return [
        'issued' => t('account.step.issued'), 'running' => t('account.step.running'), 'submitted' => t('account.step.submitted'),
        'accepted' => t('account.step.accepted'), 'returned' => t('account.step.returned'), 'failed' => t('account.step.failed'),
        'cancelled' => t('account.step.cancelled'),
    ];
}

/** Названия составов команды и сред прогона для экрана: русский текст тот же, что в ROLE_SCHEMES и RUN_ENVS. */
function structureRoleTitle(string $value, string $fallback): string
{
    return [
        'solo' => t('account.role_scheme.solo'), 'leader-worker' => t('account.role_scheme.leader_worker'),
        'consult-worker' => t('account.role_scheme.consult_worker'), 'consult-audit-worker' => t('account.role_scheme.consult_audit_worker'),
    ][$value] ?? $fallback;
}

function structureEnvTitle(string $value, string $fallback): string
{
    return [
        'subagents' => t('account.run_env.subagents'), 'orca' => t('account.run_env.orca'), 'sendmessage' => t('account.run_env.sendmessage'),
    ][$value] ?? $fallback;
}

/* Свойства, которые показываются всегда, даже пустыми: у блока — образец ответа, у ромба — условие. */
const STRUCTURE_MAIN_PROPS = ['block' => ['answer'], 'decision' => ['cond']];

function structureView(array $user, string $notice = ''): void
{
    $projects = userProjects((int) $user['id']);
    $key = (string) ($_GET['project'] ?? ($projects[0]['url_key'] ?? ''));
    $project = $key ? dbRow('SELECT * FROM projects WHERE url_key = ?', [$key]) : null;
    $folderId = (int) ($_GET['folder'] ?? 0);
    $elementId = (int) ($_GET['element'] ?? 0);

    echo '<div class="util">';
    echo '<aside class="util-tree">';
    structureTree($projects, $project, $folderId, $elementId);
    echo '</aside><section class="util-card">';
    if ($notice) echo '<p class="notice">', h($notice), '</p>';

    if ($elementId && $project) structureElementCard($project, $elementId);
    elseif ($folderId && $project) structureFolderCard($project, $folderId, $projects);
    elseif ($project) structureProjectCard($project);
    else echo '<p class="muted">', h(t('account.structure.no_projects')), '</p>';

    echo '</section></div>';
}

/* ── Дерево ───────────────────────────────────────────────────── */

function structureTree(array $projects, ?array $current, int $folderId, int $elementId): void
{
    echo '<div class="util-user"><b>', h(currentUser()['name'] ?: currentUser()['email']), '</b>',
         '<span class="muted">', h(t('account.structure.projects_n', ['n' => count($projects)])), '</span></div>';

    echo '<ul class="tree-list">';
    foreach ($projects as $project) {
        $open = $current && $project['id'] === $current['id'];
        echo '<li>';
        echo '<a class="tree-line project', $open && !$folderId && !$elementId ? ' on' : '', '" href="?section=structure&project=', h($project['url_key']), '">',
             '<span class="tree-ico">🗂</span>', h($project['title'] ?: $project['url_key']),
             '<small>', (int) $project['folders'], '</small></a>';

        if ($open) {
            structureFolderBranch((int) $project['id'], $project['url_key'], null, $folderId, $elementId, 1);
            echo '<form class="tree-add" method="post"><input type="hidden" name="do" value="folder-add">',
                 '<input type="hidden" name="project" value="', h($project['url_key']), '">',
                 '<input class="input" name="name" placeholder="', h(t('account.structure.new_folder')), '">',
                 '<button class="btn btn-quiet">+</button></form>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

/** Папки ветвью, с вложенностью и элементами внутри открытой. */
function structureFolderBranch(int $projectId, string $key, ?int $parent, int $folderId, int $elementId, int $depth): void
{
    $folders = dbAll(
        "SELECT f.*, (SELECT COUNT(*) FROM elements e WHERE e.folder_id = f.id AND e.type <> 'arrow') AS elements
           FROM folders f WHERE f.project_id = ? AND f.parent_id <=> ? ORDER BY f.sort, f.id",
        [$projectId, $parent]
    );
    if (!$folders) return;

    echo '<ul class="tree-list">';
    foreach ($folders as $folder) {
        $open = (int) $folder['id'] === $folderId || structureHasOpen((int) $folder['id'], $folderId);
        echo '<li><a class="tree-line folder', (int) $folder['id'] === $folderId && !$elementId ? ' on' : '',
             '" style="padding-left:', 8 + $depth * 14, 'px" href="?section=structure&project=', h($key),
             '&folder=', (int) $folder['id'], '">',
             '<span class="tree-ico">🗀</span>', h($folder['name'] ?: t('account.w.unnamed')),
             '<small>', (int) $folder['elements'], '</small></a>';

        if ($open) {
            structureFolderBranch($projectId, $key, (int) $folder['id'], $folderId, $elementId, $depth + 1);
            if ((int) $folder['id'] === $folderId) structureElementList($key, (int) $folder['id'], $elementId, $depth + 1);
        }
        echo '</li>';
    }
    echo '</ul>';
}

function structureHasOpen(int $folderId, int $wanted): bool
{
    if (!$wanted) return false;
    $id = $wanted;
    while ($id) {
        if ($id === $folderId) return true;
        $id = (int) (dbValue('SELECT parent_id FROM folders WHERE id = ?', [$id]) ?? 0);
    }
    return false;
}

/** Элементы папки без стрелок: номер, значок типа, название. */
function structureElementList(string $key, int $folderId, int $elementId, int $depth): void
{
    $rows = dbAll("SELECT id, `no`, type, title, to_id FROM elements WHERE folder_id = ? AND type <> 'arrow' ORDER BY `no`", [$folderId]);
    if (!$rows) return;

    $starters = array_map(static fn(array $one) => (int) $one['id'], folderStarters($folderId));
    echo '<ul class="tree-list">';
    foreach ($rows as $row) {
        $icon = in_array((int) $row['id'], $starters, true) ? '◯' : (structureKinds()[$row['type']][0] ?? '·');
        echo '<li><a class="tree-line element', (int) $row['id'] === $elementId ? ' on' : '',
             '" style="padding-left:', 8 + $depth * 14, 'px" href="?section=structure&project=', h($key),
             '&folder=', $folderId, '&element=', (int) $row['id'], '">',
             '<span class="tree-ico">', $icon, '</span>',
             '<b class="mono">', (int) $row['no'], '</b> ', structureUntitled($row, 44),
             '</a></li>';
    }
    echo '</ul>';
}

/* ── Карточки ─────────────────────────────────────────────────── */

function structureProjectCard(array $project): void
{
    $counts = dbRow(
        'SELECT (SELECT COUNT(*) FROM folders WHERE project_id = p.id) folders,
                (SELECT COUNT(*) FROM elements WHERE project_id = p.id) elements,
                (SELECT COUNT(*) FROM assets WHERE project_id = p.id) assets,
                (SELECT COUNT(*) FROM runs WHERE project_id = p.id) runs
           FROM projects p WHERE p.id = ?', [$project['id']]
    );

    echo '<h1>', t('account.structure.project_h1', ['name' => h($project['title'] ?: $project['url_key'])]), '</h1>';
    echo '<p class="muted mono">', t('account.structure.project_meta', ['key' => h($project['url_key']), 'id' => (int) $project['id'], 'rev' => (int) $project['rev']]), '</p>';
    echo '<div class="cards">';
    foreach ([t('account.w.folders') => $counts['folders'], t('account.w.elements') => $counts['elements'],
              t('account.structure.assets') => $counts['assets'], t('account.structure.runs') => $counts['runs']] as $title => $value) {
        echo '<div class="card"><b>', (int) $value, '</b><span>', h($title), '</span></div>';
    }
    echo '</div>';

    echo '<form method="post" class="util-form"><input type="hidden" name="do" value="project-save">',
         '<input type="hidden" name="project" value="', h($project['url_key']), '">',
         '<label class="field"><span>', h(t('account.w.title')), '</span><input class="input" name="title" value="', h((string) $project['title']), '"></label>',
         structureCheck('guestWrite', t('account.structure.guest_write'), (int) $project['guest_write'] === 1),
         structureCheck('aiConfirm', t('account.structure.ai_confirm'), (int) $project['ai_confirm'] === 1),
         structureCheck('strictChecks', t('account.structure.strict'), (int) $project['strict_checks'] === 1),
         '<div class="row util-buttons"><button class="btn btn-accent">', h(t('common.save')), '</button>',
         '<a class="btn btn-quiet" href="index.php#p=', h($project['url_key']), '">', h(t('account.structure.open_in_editor')), '</a></div></form>';
}

function structureFolderCard(array $project, int $folderId, array $projects): void
{
    $folder = dbRow('SELECT * FROM folders WHERE id = ? AND project_id = ?', [$folderId, $project['id']]);
    if (!$folder) { echo '<p class="notice">', h(t('account.err.no_folder')), '</p>'; return; }

    $counts = dbRow(
        "SELECT (SELECT COUNT(*) FROM elements WHERE folder_id = f.id AND type <> 'arrow') elements,
                (SELECT COUNT(*) FROM runs WHERE folder_id = f.id) runs,
                (SELECT MAX(`no`) FROM runs WHERE folder_id = f.id) last_run
           FROM folders f WHERE f.id = ?", [$folderId]
    );
    echo '<h1>', t('account.structure.folder_h1', ['name' => h($folder['name'] ?: t('account.w.unnamed'))]), '</h1>';
    $last = $counts['last_run'] ? t('account.structure.last_run', ['n' => (int) $counts['last_run']]) : '';
    echo '<p class="muted mono">', t('account.structure.folder_meta', ['id' => $folderId, 'elements' => (int) $counts['elements'],
         'runs' => (int) $counts['runs'], 'last' => $last, 'rev' => (int) $folder['content_rev']]), '</p>';

    $env = (string) $folder['run_env'];
    $roles = (string) ($folder['role_scheme'] ?? 'solo');
    echo '<form method="post" class="util-form"><input type="hidden" name="do" value="folder-save">',
         '<input type="hidden" name="project" value="', h($project['url_key']), '">',
         '<input type="hidden" name="folder" value="', $folderId, '">',
         '<label class="field"><span>', h(t('account.w.name')), '</span><input class="input" name="name" value="', h((string) $folder['name']), '"></label>',
         '<label class="field"><span>', h(t('account.structure.work_dir')), '</span><input class="input mono" name="workDir" value="', h((string) $folder['work_dir']), '">',
         '<small class="muted">', h(t('account.structure.work_dir_hint')), '</small></label>',
         '<label class="field"><span>', h(t('account.structure.work_url')), '</span><input class="input mono" name="workUrl" value="', h((string) $folder['work_url']), '"></label>',
         '<label class="field"><span>', h(t('account.structure.team')), '</span><select class="input" name="roleScheme" onchange="var e=this.form.elements.runEnv;e.disabled=this.value===this.options[0].value;e.parentNode.hidden=e.disabled">';
    foreach (ROLE_SCHEMES as $value => $one) {
        $soon = !empty($one['soon']);
        echo '<option value="', h($value), '"', $roles === $value ? ' selected' : '', '>',
             h(structureRoleTitle((string) $value, $one['title'])), $soon ? t('account.structure.soon') : '', '</option>';
    }
    echo '</select></label>',
         '<label class="field"', $roles === 'solo' ? ' hidden' : '', '><span>', h(t('account.structure.run_env')), '</span><select class="input" name="runEnv"', $roles === 'solo' ? ' disabled' : '', '>';
    // Пустая среда — субагенты (folderActiveRunEnv): выбор «не выбрана» убран.
    $envNow = isset(RUN_ENVS[$env]) ? $env : 'subagents';
    foreach (RUN_ENVS as $value => $one) {
        echo '<option value="', h($value), '"', $envNow === $value ? ' selected' : '', '>', h(structureEnvTitle((string) $value, $one['title'])), '</option>';
    }
    echo '</select><small class="muted">', h(t('account.structure.cards_hint')), '</small></label>',
         structureCheck('shareScheme', t('account.structure.share_scheme'), (int) $folder['share_scheme'] === 1),
         '<div class="row util-buttons"><button class="btn btn-accent">', h(t('common.save')), '</button>',
         '<a class="btn btn-quiet" href="index.php#p=', h($project['url_key']), '&f=', $folderId, '">', h(t('account.structure.open_in_editor')), '</a></div></form>';

    echo '<h2>', h(t('account.structure.move_title')), '</h2>';
    $others = array_filter($projects, static fn(array $other) => $other['url_key'] !== $project['url_key']);
    if (!$others) {
        echo '<p class="muted">', h(t('account.structure.no_others')), '</p>';
    } else {
        echo '<form method="post" class="row util-row">',
             '<input type="hidden" name="do" value="folder-move">',
             '<input type="hidden" name="project" value="', h($project['url_key']), '">',
             '<input type="hidden" name="folder" value="', $folderId, '">',
             '<select class="input" name="to">';
        foreach ($others as $other) {
            echo '<option value="', h($other['url_key']), '">', h($other['title'] ?: $other['url_key']), '</option>';
        }
        echo '</select><button class="btn btn-quiet">', h(t('account.structure.move')), '</button></form>';
    }

    echo '<h2>', h(t('account.structure.delete')), '</h2><form method="post"', confirmAttr(t('account.structure.delete_folder_ask')), '>',
         '<input type="hidden" name="do" value="folder-drop">',
         '<input type="hidden" name="project" value="', h($project['url_key']), '">',
         '<input type="hidden" name="folder" value="', $folderId, '">',
         '<button class="btn btn-danger btn-quiet">', h(t('account.structure.delete_folder')), '</button></form>';
}

function structureElementCard(array $project, int $elementId): void
{
    $element = dbRow('SELECT * FROM elements WHERE id = ? AND project_id = ?', [$elementId, $project['id']]);
    if (!$element) { echo '<p class="notice">', h(t('account.structure.no_element_found')), '</p>'; return; }

    $type = (string) $element['type'];
    $folderId = (int) $element['folder_id'];
    $folder = dbRow('SELECT id, name, work_dir, run_env, role_scheme FROM folders WHERE id = ?', [$folderId]);
    $starter = $type === 'block' && isStarter($elementId);
    $key = (string) $project['url_key'];

    echo '<h1><span class="util-kind">', $starter ? t('account.structure.starter') : h(implode(' ', structureKinds()[$type] ?? ['·', $type])), '</span> ',
         '⟨', (int) $element['no'], '⟩ ', h((string) $element['title']), '</h1>';
    $folderLink = '<a href="?section=structure&project=' . h($key) . '&folder=' . $folderId . '">' . h((string) ($folder['name'] ?? $folderId)) . '</a>';
    $editorLink = '<a href="index.php#p=' . h($key) . '&f=' . $folderId . '&e=' . (int) $element['no'] . '">' . h(t('account.structure.open_lc')) . '</a>';
    echo '<p class="muted mono">', t('account.structure.element_meta', ['id' => $elementId, 'folder' => $folderLink,
         'rev' => (int) $element['rev'], 'open' => $editorLink]), '</p>';

    // Стрелка и ярлык: откуда → куда.
    if ($element['from_id'] || $element['to_id']) {
        echo '<p class="util-ends">', structureElementRef($key, (int) $element['from_id']), ' → ',
             structureElementRef($key, (int) $element['to_id']),
             $element['branch'] && $element['branch'] !== 'flow' ? t('account.structure.branch', ['name' => '<b>' . h(mb_strtoupper((string) $element['branch'])) . '</b>']) : '',
             '</p>';
    }

    // Первичное: название, описание, агент.
    echo '<form method="post" class="util-form"><input type="hidden" name="do" value="element-save">',
         '<input type="hidden" name="project" value="', h($key), '">',
         '<input type="hidden" name="element" value="', $elementId, '">',
         '<label class="field"><span>', h(t('account.w.title')), '</span><input class="input" name="title" value="', h((string) $element['title']), '"></label>',
         '<label class="field"><span>', h(t('account.structure.description')), '</span><textarea class="input" name="description" rows="3">', h((string) $element['description']), '</textarea>';
    if ($type === 'block' && !$starter) {
        echo '<small class="muted">', h(t('account.structure.desc_hint')), '</small>';
    }
    echo '</label>';

    if ($type === 'block' && !$starter) {
        $agents = dbAll("SELECT id, name FROM agents WHERE project_id = ? AND role = 'worker' ORDER BY name", [$project['id']]);
        echo '<label class="field"><span>', h(t('account.structure.agent_worker')), '</span><select class="input" name="agent">',
             '<option value="">', h(t('account.structure.not_assigned')), '</option>';
        foreach ($agents as $agent) {
            $on = (int) $element['agent_id'] === (int) $agent['id'] ? ' selected' : '';
            echo '<option value="', (int) $agent['id'], '"', $on, '>', h($agent['name']), '</option>';
        }
        echo '</select>';
        // Карточка действует только в команде, в Орке и SendMessage (folderUsesAgentCards).
        $s = instrScenario($folder + ['role_scheme' => 'solo']);
        if ($s['solo']) {
            echo '<small class="muted">', h(t('account.structure.solo_hint')), '</small>';
        } elseif (!$s['cards']) {
            echo '<small class="muted">', h(t('account.structure.env_no_cards', ['env' => structureEnvTitle(folderActiveRunEnv($folder), $s['envTitle'])])), '</small>';
        }
        echo '</label>';
    }

    // Свойства: сохранённые плюс главные для типа (у блока answer, у ромба cond), даже пустые.
    $props = [];
    foreach (dbAll('SELECT name, value FROM props WHERE element_id = ? ORDER BY name', [$elementId]) as $prop) {
        $props[$prop['name']] = (string) $prop['value'];
    }
    foreach ($starter ? [] : (STRUCTURE_MAIN_PROPS[$type] ?? []) as $name) $props += [$name => ''];
    echo '<h2>', h(t('account.structure.props')), '</h2>';
    foreach ($props as $name => $value) {
        echo '<label class="field"><span>', h($name), '</span>',
             '<input class="input mono" name="props[', h($name), ']" value="', h($value), '"></label>';
    }
    echo '<div class="row util-row"><input class="input mono" name="propNewName" placeholder="', h(t('account.structure.new_prop')), '">',
         '<input class="input mono" name="propNewValue" placeholder="', h(t('account.structure.prop_value')), '"></div>',
         '<p class="muted util-hint">', h(t('account.structure.props_hint')), '</p>';
    echo '<button class="btn btn-accent">', h(t('common.save')), '</button></form>';

    // Связи: куда входит и какие стрелки.
    echo '<h2>', h(t('account.structure.links')), '</h2><dl class="kv">';
    $containers = dbAll('SELECT e.id, e.`no`, e.title, e.type FROM members m JOIN elements e ON e.id = m.container_id WHERE m.element_id = ?', [$elementId]);
    echo '<dt>', h(t('account.structure.member_of')), '</dt><dd>', $containers
        ? implode(', ', array_map(static fn($c) => structureElementRef($key, (int) $c['id']), $containers))
        : '—', '</dd>';
    foreach ([['to_id', 'from_id', t('account.structure.incoming'), '←'], ['from_id', 'to_id', t('account.structure.outgoing'), '→']] as [$column, $other, $title, $sign]) {
        $arrows = dbAll("SELECT id, `no`, branch, $other AS other FROM elements WHERE type = 'arrow' AND $column = ? ORDER BY `no`", [$elementId]);
        echo '<dt>', h($title), '</dt><dd>';
        if (!$arrows) echo '—';
        foreach ($arrows as $arrow) {
            echo '<div><a class="mono" href="?section=structure&project=', h($key), '&folder=', $folderId, '&element=', (int) $arrow['id'], '">⟨',
                 (int) $arrow['no'], '⟩</a>', $arrow['branch'] && $arrow['branch'] !== 'flow' ? ' <b>' . h(mb_strtoupper((string) $arrow['branch'])) . '</b>' : '',
                 ' ', $sign, ' ', structureElementRef($key, (int) $arrow['other']), '</div>';
        }
        echo '</dd>';
    }
    echo '</dl>';

    // ТЗ: текстовый ассет с ролью spec — у того, что исполняется или даёт контекст.
    if (in_array($type, ['block', 'decision', 'gateway', 'group', 'area'], true)) {
        $spec = specText('element_id', $elementId);
        echo '<h2>', $starter ? t('account.structure.starter_spec') : t('account.structure.spec_h'), '</h2>',
             '<form method="post" class="util-form util-wide"><input type="hidden" name="do" value="spec-save">',
             '<input type="hidden" name="project" value="', h($key), '">',
             '<input type="hidden" name="element" value="', $elementId, '">',
             '<textarea class="input mono" name="spec" rows="12">', h($spec['text'] ?? ''), '</textarea>',
             '<button class="btn btn-accent">', h(t('account.structure.save_spec')), '</button></form>';
    }

    // Материалы (кроме ТЗ — оно выше): путь от рабочей папки; файл пропал — пометка.
    $assets = dbAll(
        'SELECT a.kind, a.title, a.uri, l.role FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.element_id = ? AND l.role <> \'spec\' ORDER BY l.sort, l.id', [$elementId]
    );
    echo '<h2>', h(t('account.structure.assets_h')), '</h2>';
    if ($assets) {
        echo '<table class="acc-table"><thead><tr><th>', h(t('account.structure.what')), '</th><th>', h(t('account.agents.role')), '</th><th>', h(t('account.structure.where')), '</th></tr></thead><tbody>';
        foreach ($assets as $asset) {
            echo '<tr><td>', h($asset['title'] ?: $asset['kind']), '</td>',
                 '<td>', h(structureRoles()[$asset['role']] ?? $asset['role']), '</td>',
                 '<td class="mono">', structureAssetPlace((string) $asset['uri'], (string) ($folder['work_dir'] ?? '')), '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted">', h(t('account.structure.no_assets')), '</p>';
    }

    // История шагов прогона.
    $steps = dbAll(
        'SELECT s.*, r.`no` AS run_no FROM run_steps s JOIN runs r ON r.id = s.run_id
          WHERE s.element_id = ? ORDER BY s.id DESC LIMIT 20', [$elementId]
    );
    echo '<h2>', h(t('account.structure.steps')), '</h2>';
    if ($steps) {
        echo '<table class="acc-table"><thead><tr><th>', h(t('account.structure.run')), '</th><th>', h(t('account.structure.address')), '</th><th>', h(t('account.w.state')), '</th><th>', h(t('account.structure.result')), '</th><th>', h(t('account.ai.when')), '</th></tr></thead><tbody>';
        foreach ($steps as $step) {
            echo '<tr><td>r', (int) $step['run_no'], '</td><td class="mono">', (int) $element['no'], '.', (int) $step['attempt'], '</td>',
                 '<td>', h(structureStates()[$step['state']] ?? $step['state']), '</td><td>', h(mb_strimwidth((string) $step['result'], 0, 120, '…')), '</td>',
                 '<td class="muted">', h(substr((string) $step['opened_at'], 0, 19)), '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted">', h(t('account.structure.not_run')), '</p>';
    }

    echo '<h2>', h(t('account.structure.delete')), '</h2><form method="post"', confirmAttr(t('account.structure.delete_element_ask')), '>',
         '<input type="hidden" name="do" value="element-drop">',
         '<input type="hidden" name="project" value="', h($key), '">',
         '<input type="hidden" name="element" value="', $elementId, '">',
         '<button class="btn btn-danger btn-quiet">', h(t('account.structure.delete_element')), '</button></form>';
}

/** Ссылка на элемент: значок, номер и имя. */
function structureElementRef(string $key, int $id): string
{
    if (!$id) return '—';
    $row = dbRow('SELECT id, `no`, type, title, folder_id, to_id FROM elements WHERE id = ?', [$id]);
    if (!$row) return '<span class="muted">' . h(t('account.structure.no_element', ['id' => $id])) . '</span>';
    $icon = $row['type'] === 'block' && isStarter($id) ? '◯' : (structureKinds()[$row['type']][0] ?? '·');
    return '<a href="?section=structure&project=' . h($key) . '&folder=' . (int) $row['folder_id'] . '&element=' . $id . '">'
        . $icon . ' <b class="mono">' . (int) $row['no'] . '</b> ' . structureUntitled($row, 48) . '</a>';
}

/** Название элемента; нет его — ярлык называет цель, остальное — «без имени» бледным. */
function structureUntitled(array $row, int $width): string
{
    $title = trim((string) $row['title']);
    if ($title !== '') return h(mb_strimwidth($title, 0, $width, '…'));
    if ($row['type'] === 'link' && $row['to_id']) {
        $to = dbRow('SELECT `no`, title FROM elements WHERE id = ?', [(int) $row['to_id']]);
        if ($to) return '<span class="muted">' . t('account.structure.link_to', ['no' => (int) $to['no'], 'title' => h(mb_strimwidth((string) $to['title'], 0, $width - 8, '…'))]) . '</span>';
    }
    return '<span class="muted">' . h(t('account.w.unnamed')) . '</span>';
}

/** Путь материала: от рабочей папки, если внутри неё; иначе хвост. Файла нет — пометка. */
function structureAssetPlace(string $uri, string $workDir): string
{
    if ($uri === '') return '<span class="muted">' . h(t('account.structure.in_db')) . '</span>';
    $work = rtrim($workDir, '/') . '/';
    $shown = $workDir !== '' && str_starts_with($uri, $work)
        ? substr($uri, strlen($work))
        : (mb_strlen($uri) > 60 ? '…' . mb_substr($uri, -59) : $uri);
    $lost = str_starts_with($uri, '/') && !is_file($uri) ? ' <span class="util-lost">' . h(t('account.structure.file_lost')) . '</span>' : '';
    return '<span title="' . h($uri) . '">' . h($shown) . '</span>' . $lost;
}

function structureCheck(string $name, string $label, bool $on): string
{
    return '<label class="row util-check"><input type="checkbox" name="' . $name . '"'
        . ($on ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
}
