<?php
/* Служебные операции: списки, инструкции, пропуска проекта.
   Отдаёт: configGet(), configList(), docsGet(), docsRun(), precheckLines(), tokenCreateOp(), tokenRevokeOp().
   Не делает: не трогает схему. */

declare(strict_types=1);

/** GET config.get — всё, что нужно интерфейсу и агенту, чтобы говорить одними словами. */
function configGet(): void
{
    $data = lists();
    $out = [];
    foreach ($data['sections'] as $name => $rows) {
        $out[$name] = [];
        foreach ($rows as $key => $row) {
            $item = ['key' => $key, 'label' => $row['label']];
            if ($row['color'] !== '') $item['color'] = $row['color'];
            if ($row['extra'])        $item['extra'] = $row['extra'];
            if (($row['run'] ?? '') !== '') $item['run'] = $row['run'];
            $out[$name][] = $item;
        }
    }
    reply([
        'lists'        => $out,
        'props'        => propsDict(),
        'kinds'        => array_map(static fn($k, $v) => ['type' => $k, 'title' => $v['title'], 'about' => $v['about']], array_keys(KINDS), KINDS),
        'roles'        => LINK_ROLES,
        'interface'    => $data['interface'],
        'pulseSeconds' => interfaceValue('pulse-seconds', 5),
        'ops'          => opsCatalog(),
        // Настроен ли Jev: окну запуска нужен только признак, сам ключ наружу не уходит.
        'jev'          => [
            'ready' => jevReady(),
            'model' => jevAccess()['jev_model'],
        ],
    ]);
}

/** POST config.list — правка одной строки списка. value=null удаляет. */
function configList(): void
{
    if (callerRole() !== 'admin') throw new ApiError(t('server.config.admin_only'), 'scope');
    requireProject(false);
    $section = (string) (input('section') ?? '');
    $key = trim((string) (input('key') ?? ''));
    if ($section === '' || $key === '') throw new ApiError(t('server.config.need_key'));
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) throw new ApiError(t('server.config.key_format'));

    $value = input('value');
    listWrite($section, $key, $value === null ? null : [
        'label' => (string) ($value['label'] ?? $key),
        'color' => (string) ($value['color'] ?? ''),
        'extra' => array_values((array) ($value['extra'] ?? [])),
        'run'   => (string) ($value['run'] ?? ''),
    ]);
    reply(['section' => $section, 'key' => $key, 'deleted' => $value === null]);
}

/** Сколько секунд после последнего хода прогон считается ведомым прямо сейчас (docs.run, «Незакрытый прогон»). */
const DOCS_RUN_LIVE_SEC = 180;

/**
 * GET docs.run&role=lead&folder=ID — задание leader по этой папке, готовым текстом.
 * GET docs.run&role=draw&folder=ID — задание агенту нарисовать или поправить схему папки (docsDrawText).
 * role=lead серверу не нужен: он для агента — по одной ссылке видно, что он leader
 * (в r141 leader по &worker= в ссылке на миг решил, что он worker). Без role — leader;
 * другая роль (worker и прочие) — отказ с перечнем lead|draw.
 *
 * Это единственная ссылка, которую человек даёт агенту: в ней и адрес схемы,
 * параметры прогона, исполнители и ссылки на инструкции в MD-файлах.
 * Собирается каждый раз заново — схему успевают поправить.
 */
function docsRun(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    // Ролей у ссылки две; worker её не получает — вводную ему даёт ведущий (op=invite).
    $role = (string) (input('role') ?? '');
    if (!in_array($role, ['', 'lead', 'draw'], true)) {
        throw new ApiError(ta('agents.docs.bad_role', ['role' => $role]), 'invalid');
    }
    $folderId = inputInt('folder') ?: (int) dbValue(
        'SELECT id FROM folders WHERE project_id = ? ORDER BY sort, id LIMIT 1', [$projectId]);
    if (!$folderId) throw new ApiError(t('server.folder.not_given'), 'not_found');
    $folder = folderRow($folderId, $projectId);

    $api  = runBaseUrl();
    $site = preg_replace('#/api\.php$#', '/', $api);
    $key  = (string) $project['url_key'];
    $check = folderPrecheck($folder);

    // role=draw — та же ссылка, но задание не на прогон, а на рисование схемы этой папки.
    if ($role === 'draw') {
        $agents = dbAll('SELECT id, name, role, cli, model FROM agents WHERE project_id = ? ORDER BY id', [$projectId]);
        $md = docsDrawText($project, $folder, $check, $agents, $api, $site, $key, runActive($folderId));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: no-store');
        echo $md;
        exit;
    }

    // Кто здесь кто: leader и worker, назначенные на блоки этой папки.
    $leads = dbAll("SELECT id, name, cli, model FROM agents WHERE project_id = ? AND role = 'lead' ORDER BY id", [$projectId]);
    $workers = dbAll(
        "SELECT DISTINCT a.id, a.name, a.cli, a.model FROM agents a
           JOIN elements e ON e.agent_id = a.id
          WHERE e.folder_id = ? AND e.type = 'block' ORDER BY a.id",
        [$folderId]
    );

    // Незакрытый прогон этой папки: без его остановки ни prepare, ни start не пройдут.
    $busy = runActive($folderId);

    // &worker=… — адрес worker: терминал в Орке, имя сессии в SendMessage. Его знает тот, кто рассадил агентов.
    $worker = trim((string) (input('worker') ?? ''));
    if (!preg_match('/^[\p{L}\p{N}_.-]{1,120}$/u', $worker)) $worker = '';

    $md = docsRunText($project, $folder, $check, $leads, $workers, $api, $site, $key, $busy, $worker);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Cache-Control: no-store');
    echo $md;
    exit;
}

/** Текст задания. Отдельно от сборки данных: так его легче читать и править. */
function docsRunText(array $project, array $folder, array $check, array $leads, array $workers,
                     string $api, string $site, string $key, ?array $busy = null, string $worker = ''): string
{
    $folderId = (int) $folder['id'];
    $name = (string) $folder['name'];
    $scheme = $site . '#p=' . $key . '&f=' . $folderId;

    /* Состав команды и среда папки (вкладка «Агенты»): инструкция ниже собрана
       только под них — о других сценариях leader знать незачем. */
    $s = instrScenario($folder);
    $doc = $api . '?op=docs.get&file=leader&folder=' . $folderId . '&project=' . $key;

    $lines = [];
    // Первая строка — что это за ссылка: агент не должен спрашивать, что с ней делать (решение хозяина 30.09.2026).
    $lines[] = ta('agents.docs.run_order');
    $lines[] = '';
    $lines[] = ta('agents.docs.run_title', ['name' => $name]);
    $lines[] = '';
    $lines[] = ta('agents.docs.run_intro');
    $lines[] = ta('agents.docs.run_no_others');
    $lines[] = '';
    $lines[] = ta('agents.docs.table_head');
    $lines[] = '|---|---|';
    $lines[] = ta('agents.docs.row_role_leader');
    $lines[] = '| API | ' . $api . ' |';
    $lines[] = ta('agents.docs.row_scheme') . $scheme . ' |';
    $lines[] = ta('agents.docs.row_project') . $key . '`' . ($project['title'] !== '' ? ' — ' . $project['title'] : '') . ' |';
    $lines[] = ta('agents.docs.row_folder') . $folderId . '` — ' . $name . ' |';
    $lines[] = ta('agents.docs.row_steps') . (int) $check['steps'] . ' |';
    $lines[] = ta('agents.docs.row_work_dir') . docsWorkDir($folder) . ' |';
    $lines[] = ta('agents.docs.row_team') . $s['title'] . ($s['soon'] ? ta('agents.docs.team_soon') : '') . ' |';
    $lines[] = ta('agents.docs.row_env') . ($s['solo'] ? ta('agents.docs.env_none') : $s['envTitle']) . ' |';
    $lines[] = ta('agents.docs.row_doc') . $doc . ' |';
    if ($worker !== '' && !$s['solo']) $lines[] = '| worker | `' . $worker . '` |';
    $lines[] = '';

    array_push($lines, ...precheckLines($check));

    // Карточки агентов на блоках действуют только в Орке и SendMessage (folderUsesAgentCards).
    if ($s['cards']) {
        $lines[] = ta('agents.docs.who');
        $lines[] = '';
        // «Записано в карточке» — не указание поднимать субагентов: это лишь
        // какой инструмент и модель записал человек.
        $lines[] = ta('agents.docs.who_head');
        $lines[] = '|---|---|---|---|';
        foreach ($leads as $one) {
            $lines[] = '| leader | ' . (int) $one['id'] . ' | ' . (string) $one['name'] . ' | '
                . trim((string) $one['cli'] . ' ' . (string) $one['model']) . ' |';
        }
        foreach ($workers as $one) {
            $lines[] = '| worker | ' . (int) $one['id'] . ' | ' . (string) $one['name'] . ' | '
                . trim((string) $one['cli'] . ' ' . (string) $one['model']) . ' |';
        }
        if (!$workers) $lines[] = ta('agents.docs.no_worker');
        $lines[] = '';
    }

    if ($busy) {
        $lines[] = ta('agents.docs.open_run');
        $lines[] = '';
        /* Прогон ходил только что — его ведёт другой агент: ссылку дали двоим. Второй не должен ни
           продолжать его, ни закрывать — иначе два ведущих в одном прогоне (ревизия 30.09.2026). */
        $quiet = dbValue('SELECT TIMESTAMPDIFF(SECOND, MAX(`at`), NOW(3)) FROM run_events WHERE run_id = ?', [(int) $busy['id']]);
        $lines[] = $quiet !== null && (int) $quiet < DOCS_RUN_LIVE_SEC
            ? ta('agents.docs.open_run_live', ['run' => 'r' . (int) $busy['no'], 'sec' => (int) $quiet])
            : ta('agents.docs.open_run_text', ['run' => 'r' . (int) $busy['no']]);
        $lines[] = '';
    }

    $lines[] = '---';
    $lines[] = '';
    $lines[] = leaderDoc($project, $folder, $api);

    return implode("\n", $lines) . "\n";
}

/**
 * Текст задания на рисование (docs.run&role=draw): создать схему в пустой папке
 * или поправить ту, что уже на холсте. Правила — в рисование.md, здесь — параметры папки.
 */
function docsDrawText(array $project, array $folder, array $check, array $agents,
                      string $api, string $site, string $key, ?array $busy = null): string
{
    $folderId = (int) $folder['id'];
    $name = (string) $folder['name'];
    $scheme = $site . '#p=' . $key . '&f=' . $folderId;
    // Правила рисования — на языке агентов проекта: instructions/<язык>/рисование.md.
    $drawMd = 'рисование.md';
    $drawPath = langFile(config()['instructions_dir'], $drawMd, langAgents());
    $rules = $api . '?op=docs.get&file=' . rawurlencode('рисование') . '&project=' . $key;
    $full = $api . '?op=folder.get&folder=' . $folderId . '&project=' . $key;

    // Что уже на холсте: по типам, без ярлыков.
    $count = [];
    foreach (dbAll('SELECT type, COUNT(*) AS n FROM elements WHERE folder_id = ? GROUP BY type', [$folderId]) as $row) {
        $count[(string) $row['type']] = (int) $row['n'];
    }
    $words = [];
    foreach (['block', 'decision', 'gateway', 'arrow', 'group', 'area', 'note', 'table', 'link'] as $type) $words[$type] = ta('agents.docs.w.' . $type);
    $parts = [];
    foreach ($words as $type => $word) {
        if (!empty($count[$type])) $parts[] = $word . ' ' . $count[$type];
    }
    $empty = !$parts;

    $lines = [];
    $lines[] = ta('agents.docs.draw_title', ['name' => $name]);
    $lines[] = '';
    $lines[] = ta('agents.docs.draw_rules_link', ['rules' => $rules]);
    $lines[] = '';
    $lines[] = ta('agents.docs.table_head');
    $lines[] = '|---|---|';
    $lines[] = ta('agents.docs.draw_role', ['what' => $empty ? ta('agents.docs.draw_role_new') : ta('agents.docs.draw_role_edit')]);
    $lines[] = '| API | ' . $api . ' |';
    $lines[] = ta('agents.docs.row_scheme') . $scheme . ' |';
    $lines[] = ta('agents.docs.row_project') . $key . '`' . ($project['title'] !== '' ? ' — ' . $project['title'] : '') . ' |';
    $lines[] = ta('agents.docs.row_folder') . $folderId . '` — ' . $name . ' |';
    $lines[] = ta('agents.docs.draw_canvas', ['what' => $empty ? ta('agents.docs.empty') : implode(', ', $parts)]);
    $lines[] = ta('agents.docs.row_work_dir') . docsWorkDir($folder) . ' |';
    $lines[] = ta('agents.docs.draw_rules_row', ['dir' => dirname($drawPath), 'file' => $drawMd, 'rules' => $rules]);
    if (!$empty) $lines[] = ta('agents.docs.draw_full') . $full . ' |';
    $lines[] = ta('agents.docs.draw_run', ['what' => $busy
        ? ta('agents.docs.draw_run_live', ['run' => 'r' . (int) $busy['no']])
        : ta('agents.docs.draw_run_idle')]);
    $lines[] = '';

    $lines[] = ta('agents.docs.order');
    $lines[] = '';
    $lines[] = ta('agents.docs.step1', ['rules' => $rules]);
    $lines[] = $empty
        ? ta('agents.docs.step2_new')
        : ta('agents.docs.step2_edit', ['full' => $full, 'asset' => $api . '?op=asset.get&asset=ID&project=' . $key]);
    $lines[] = ta('agents.docs.step3', ['folder' => $folderId]);
    $lines[] = '';
    $lines[] = '```bash';
    $lines[] = ta('agents.docs.curl', ['api' => $api, 'key' => $key]);
    $lines[] = '```';
    $lines[] = '';
    $lines[] = ta('agents.docs.step4');
    $lines[] = ta('agents.docs.step5', ['scheme' => $api . '?op=scheme&folder=' . $folderId . '&project=' . $key,
        'check' => $api . '?op=run.check&folder=' . $folderId . '&project=' . $key]);
    $lines[] = ta('agents.docs.step6');
    $lines[] = '';

    if (!$empty) {
        // Каркас схемы — только для ориентира; номера здесь — `no`, для правки нужен `id` из folder.get.
        $lines[] = ta('agents.docs.now_on_canvas');
        $lines[] = '';
        $lines[] = ta('agents.docs.outline_note');
        $lines[] = '';
        $lines[] = '```text';
        foreach (goSchemeMap($folderId) as $one) $lines[] = $one;
        $lines[] = '```';
        $lines[] = '';

        array_push($lines, ...precheckLines($check));
    }

    // Исполнитель блока — поле `agent` = id отсюда.
    $lines[] = ta('agents.docs.project_agents');
    $lines[] = '';
    if ($agents) {
        $lines[] = ta('agents.docs.agents_head');
        $lines[] = '|---|---|---|---|';
        foreach ($agents as $one) {
            $lines[] = '| ' . (int) $one['id'] . ' | ' . (string) $one['name'] . ' | ' . (string) $one['role'] . ' | '
                . trim((string) $one['cli'] . ' ' . (string) $one['model']) . ' |';
        }
    } else {
        $lines[] = ta('agents.docs.no_agents');
    }
    $lines[] = '';

    return implode("\n", $lines) . "\n";
}

/** Рабочая папка в таблице задания: агенту в вебе — путь схемы в его текущей папке, иначе — на диске сервера. */
function docsWorkDir(array $folder): string
{
    if (agentsRemote()) return ta('agents.go.scheme_dir_agent', ['path' => agentFolderPath($folder)]);
    return (string) $folder['work_dir'] ?: ta('agents.docs.not_set');
}

/**
 * GET docs.get&file=leader|worker&folder=N — инструкция роли, собранная под состав и среду папки
 * (lib/api/instructions.php); worker — ещё &run=rN. file=рисование|deepseek|legacy-* — файлы.
 */
function docsGet(): void
{
    // leader и worker собирает сервер; рисование и правила ИИ — в instructions; утилита — в old.
    $file = (string) (input('file') ?? 'leader');
    $aliases = ['lead' => 'leader', 'ведущий' => 'leader', 'воркер' => 'worker'];
    $file = $aliases[$file] ?? $file;
    if ($file === 'leader' || $file === 'worker') {
        $project = requireProject(false);
        $folderId = inputInt('folder');
        if (!$folderId) throw new ApiError(t('server.docs.need_folder'), 'not_found');
        $folder = folderRow($folderId, (int) $project['id']);
        if ($file === 'leader') {
            $md = leaderDoc($project, $folder, runBaseUrl());
        } else {
            if (instrScenario($folder)['solo']) {
                throw new ApiError(t('server.docs.no_worker'), 'conflict');
            }
            $run = input('run') !== null ? goRun((int) $project['id']) : null;
            $md = workerDoc($folder, $run);
        }
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: no-store');
        echo $md;
        exit;
    }
    // Сторож ведущего — скриптом по ссылке: агент в вебе диска сервера не видит.
    if ($file === 'lead-watch') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        readfile(dirname(__DIR__, 2) . '/bin/lead-watch.sh');
        exit;
    }
    $known = ['leader', 'worker', 'рисование', 'deepseek', 'legacy-lead', 'legacy-worker', 'lead-watch'];
    if (!in_array($file, $known, true)) {
        throw new ApiError(t('server.docs.available', ['list' => implode(', ', $known)]), 'not_found');
    }
    // Прежние инструкции — instructions/old/; нынешние — по языку проекта: instructions/ru|en/.
    $legacy = str_starts_with($file, 'legacy-');
    $path = $legacy ? config()['instructions_dir'] . '/old/' . substr($file, 7) . '.md'
                    : langFile(config()['instructions_dir'], $file . '.md', langAgents());
    if (!is_file($path)) throw new ApiError(t('server.docs.not_written', ['file' => $file]), 'not_found');

    header('Content-Type: text/markdown; charset=utf-8');
    header('Cache-Control: no-store');
    echo file_get_contents($path);
    exit;
}

/** POST token.create — пропуск проекта для агента или внешнего скрипта. */
function tokenCreateOp(): void
{
    $project = requireProject(true);
    $token = tokenIssue('project', [
        'project_id' => (int) $project['id'],
        'created_by' => caller()['user_id'],
    ], (string) (input('label') ?? ''));

    // Секрет показывается один раз: в базе только отпечаток.
    reply(['token' => $token['secret'], 'id' => $token['id'], 'tail' => $token['tail']]);
}

/** POST token.revoke */
function tokenRevokeOp(): void
{
    $project = requireProject(true);
    $id = inputInt('id');
    $row = dbRow('SELECT * FROM tokens WHERE id = ? AND project_id = ?', [$id, $project['id']]);
    if (!$row) throw new ApiError(t('server.token.not_found'), 'not_found');
    tokenRevoke((int) $row['id']);
    reply(['id' => (int) $row['id'], 'revoked' => true]);
}

/** Раздел «Предполётная проверка» для docs.run: помехи прогону и замечания к схеме. */
function precheckLines(array $check): array
{
    $lines = [ta('agents.docs.pre_head'), ''];
    if ($check['ready']) {
        $lines[] = ta('agents.docs.pre_clean');
    } else {
        $lines[] = ta('agents.docs.pre_not_ready');
        $lines[] = '';
        foreach ($check['problems'] as $one) {
            $lines[] = '- ' . (isset($one['no']) ? ta('agents.docs.pre_element', ['no' => (int) $one['no']]) : ta('agents.docs.pre_folder')) . ': ' . $one['say'];
        }
    }
    if ($check['warnings']) {
        array_push($lines, '', ta('agents.docs.pre_warnings'), '');
        foreach ($check['warnings'] as $one) {
            $lines[] = ta('agents.docs.pre_warning', ['no' => $one['no'], 'title' => $one['title'], 'say' => $one['say']]);
        }
    }
    $lines[] = '';
    return $lines;
}
