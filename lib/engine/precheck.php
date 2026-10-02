<?php
/* Предполётная проверка схемы перед прогоном.
   Отдаёт: runCheck(), folderPrecheck(), precheckSay(), precheckExits(), folderWarnings(), folderLoopWithoutBack(),
           branchesOfSameFork(), forksBehind(), runEntries(), runEntriesByArrows().
   Не делает: ничего не пишет — только находит, что помешает прогону. */

declare(strict_types=1);

/** GET run.check — предполётная проверка. Ничего не пишет. */
function runCheck(): void
{
    $project = requireProject(false);
    $folderId = inputInt('folder');
    if (!$folderId) throw new ApiError(ta('agents.precheck.no_folder'), 'not_found');
    $folder = folderRow($folderId, (int) $project['id']);

    reply(['folder' => folderShape($folder, VIEW_WORK)] + folderPrecheck($folder));
}

/**
 * Что помешает прогону этой папки.
 * Отдаёт ['steps' => сколько исполняемых, 'entries' => входы, 'problems' => список, 'ready' => bool].
 * Тем же смотрит задание leader (`docs.run`), поэтому это отдельная функция, а не часть ответа.
 * Схему читает тем же графом, что и ход (engineGraph): правила, входы и выходы — одни.
 */
function folderPrecheck(array $folder): array
{
    $folderId = (int) $folder['id'];
    $graph = engineGraph($folderId);
    // По номерам — так схему видит человек, и так называются проблемы.
    $nodes = $graph['nodes'];
    uasort($nodes, static fn(array $a, array $b): int => (int) $a['no'] <=> (int) $b['no']);

    $problems = [];
    $steps = 0;

    // Агенты в вебе работают в своей папке на своём компьютере: папка на диске сервера им не нужна.
    if (agentsRemote()) {
        // проверять на сервере нечего
    } elseif ((string) $folder['work_dir'] === '') {
        $problems[] = ['what' => 'folder', 'say' => ta('agents.precheck.no_work_dir')];
    } elseif (!is_dir($folder['work_dir'])) {
        $problems[] = ['what' => 'folder', 'say' => ta('agents.precheck.dir_missing', ['dir' => $folder['work_dir']])];
    }

    // Стартер: один на папку, в него ничего не входит, из него есть выход.
    $starters = array_values(array_filter($nodes,
        static fn(array $node): bool => $node['type'] === 'block' && ($node['props']['start'] ?? false) === true));
    $starterIds = array_map(static fn(array $one) => (int) $one['id'], $starters);
    if (count($starters) > 1) {
        $problems[] = ['what' => 'folder', 'say' => ta('agents.precheck.starters_many', ['n' => count($starters),
            'list' => implode(', ', array_map(static fn(array $one) => (int) $one['no'], $starters))])];
    }
    foreach ($starters as $one) {
        $id = (int) $one['id'];
        if ($graph['in'][$id]) $problems[] = ['what' => 'element', 'no' => (int) $one['no'], 'say' => ta('agents.precheck.starter_in')];
        if (!$graph['out'][$id]) $problems[] = ['what' => 'element', 'no' => (int) $one['no'], 'say' => ta('agents.precheck.starter_no_out')];
    }
    // При стартере вход один: другой узел без входящих стрелок стоял бы вечно.
    if (count($starters) === 1) {
        foreach ($nodes as $id => $node) {
            if ((int) $id === $starterIds[0] || graphIn($graph, (int) $id, false)) continue;
            $problems[] = ['what' => 'element', 'no' => (int) $node['no'],
                'say' => ta('agents.precheck.no_incoming', ['no' => (int) $starters[0]['no']])];
        }
    }

    foreach ($nodes as $id => $node) {
        $id = (int) $id;
        $type = (string) $node['type'];
        $no = (int) $node['no'];
        $steps++;
        // Стартеру не нужны ни ТЗ для worker, ни worker: он проходит сам.
        if (in_array($id, $starterIds, true)) continue;

        // Критерий выбора у ромба — условие cond (его считает движок) или ТЗ (решает человек).
        $byCond = $type === 'decision' && trim((string) graphProp($graph, $id, 'cond', '')) !== '';
        if (kindRule($type)['spec'] && !$byCond && taskText($id) === '') {
            $problems[] = ['what' => 'element', 'no' => $no,
                'say' => $type === 'decision' ? ta('agents.precheck.decision_no_cond') : ta('agents.precheck.no_spec')];
        }
        /* Карточку агента на блоке здесь не проверяем: она подсказка, а не
           условие запуска. Нет worker или стоит ведущий — блок делает ведущий сам. */
        if ($type === 'decision' && ($say = precheckExits($graph, $id)) !== '') {
            $problems[] = ['what' => 'element', 'no' => $no, 'say' => $say];
        }
        if ($type === 'gateway' && !$node['target_folder_id']) {
            $problems[] = ['what' => 'element', 'no' => $no, 'say' => ta('agents.precheck.gateway_no_target')];
        }
        // Правило записано неверно — ход его не поймёт: назвать, а не угадывать.
        if (array_key_exists('join', $node['bad'] ?? [])) {
            $problems[] = ['what' => 'element', 'no' => $no,
                'say' => ta('agents.precheck.join_values', ['value' => precheckValue($node['bad']['join'])])];
        }
    }

    // Сборщик после развилки: ждёт все входы, а придёт только один. Прогон
    // на таком блоке встаёт навсегда — это ровно тот случай, из-за которого
    // прогон закрывался, не дойдя до последнего блока.
    foreach ($nodes as $id => $node) {
        $ins = precheckIns($graph, (int) $id);
        if (count($ins) < 2) continue;
        if ((string) graphProp($graph, (int) $id, 'join', 'all') !== 'all') continue;

        if (branchesOfSameFork($ins)) {
            $problems[] = ['what' => 'element', 'no' => (int) $node['no'],
                'say' => ta('agents.precheck.join_fork')];
        }
    }

    // Цикл без возвратной стрелки: блок в начале круга ждёт и вход, и возврат,
    // а возврат не придёт, пока блок не сделан, — прогон встаёт на первом же круге.
    $loop = folderLoopWithoutBack($folderId);
    if ($loop) {
        $problems[] = ['what' => 'element', 'no' => $loop['to'],
            'say' => ta('agents.precheck.loop', ['from' => $loop['from'], 'to' => $loop['to']])];
    }

    $entries = runEntries($folderId, $graph);
    if (!$entries) $problems[] = ['what' => 'folder', 'say' => ta('agents.precheck.no_entry')];

    return [
        'steps'    => $steps,
        'entries'  => array_map(static fn(array $r) => ['id' => (int) $r['id'], 'no' => (int) $r['no'], 'title' => $r['title']], $entries),
        'problems' => $problems,
        'ready'    => $problems === [],
        'warnings' => folderWarnings($folderId, $graph),
    ];
}

/** Проблема предпроверки одной строкой: «блок 5: Нет ТЗ». */
function precheckSay(array $one): string
{
    return (isset($one['no']) ? ta('agents.precheck.at_block', ['no' => (int) $one['no']]) : '') . (string) $one['say'];
}

/**
 * Выходы ромба: ровно один «да» и ровно один «нет», других нет. Отдаёт текст проблемы или ''.
 * Два «да» или стрелка без ветки — движок пошёл бы по первой подходящей, вторую молча бросил.
 */
function precheckExits(array $graph, int $id): string
{
    $count = ['yes' => 0, 'no' => 0, 'other' => 0];
    foreach (graphOut($graph, $id) as $edgeId) {
        $branch = (string) ($graph['edges'][$edgeId]['branch'] ?? '');
        $count[in_array($branch, ['yes', 'no'], true) ? $branch : 'other']++;
    }
    if (!$count['yes'] || !$count['no']) return ta('agents.precheck.decision_exits');
    if ($count['yes'] > 1 || $count['no'] > 1 || $count['other']) return ta('agents.precheck.decision_exits_extra', $count);
    return '';
}

/** Прямые входящие стрелки узла строками (id, from_id, branch) — для branchesOfSameFork. */
function precheckIns(array $graph, int $id): array
{
    return array_map(static fn(int $edgeId): array => $graph['edges'][$edgeId], graphIn($graph, $id, false));
}

/** Неверное значение правила для человека: коротко, строкой. */
function precheckValue($value): string
{
    $text = is_scalar($value) ? var_export($value, true) : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    return mb_substr($text, 0, 40);
}

/**
 * Замечания к схеме: прогон пойдёт, но не так, как, скорее всего, задумано.
 * Одни и те же для begin, run.check и инструкции рисующего агента (docs.run&role=draw).
 * Отдаёт [['no' => номер, 'title' => имя, 'say' => что не так], …].
 */
function folderWarnings(int $folderId, ?array $graph = null): array
{
    $graph ??= engineGraph($folderId);
    $out = [];
    foreach ($graph['nodes'] as $id => $node) {
        $id = (int) $id;

        // Блок круга без своего предела: сервер остановит его на LOOP_ATTEMPTS выдачах.
        if ($node['type'] === 'block' && (int) graphProp($graph, $id, 'max_attempts', 0) <= 0
            && graphInLoop($graph, $id)) {
            $out[] = ['no' => (int) $node['no'], 'title' => (string) $node['title'],
                'say' => ta('agents.precheck.no_max_attempts', ['n' => LOOP_ATTEMPTS])];
        }

        /* join=any на параллельных ветках (не ветках одного ромба): блок забирает
           по одному жетону и выдаётся по разу на каждую ветку, каждый раз с частью входа. */
        if ((string) graphProp($graph, $id, 'join', 'all') === 'all') continue;
        $ins = precheckIns($graph, $id);
        if (count($ins) >= 2 && !branchesOfSameFork($ins)) {
            $out[] = ['no' => (int) $node['no'], 'title' => (string) $node['title'],
                'say' => ta('agents.precheck.join_any')];
        }
    }
    return $out;
}

/**
 * Стрелка, замыкающая круг без пометки back, — или null.
 *
 * Обходим схему в глубину по обычным стрелкам. Встретили блок, который
 * ещё на пути, — это круг, и стрелка к нему его замыкает: её и помечают.
 */
function folderLoopWithoutBack(int $folderId): ?array
{
    $next = [];
    $no = [];
    foreach (dbAll('SELECT id, `no` FROM elements WHERE folder_id = ?', [$folderId]) as $row) {
        $no[(int) $row['id']] = (int) $row['no'];
    }
    foreach (dbAll("SELECT from_id, to_id FROM elements WHERE folder_id = ? AND type = 'arrow' AND back = 0",
        [$folderId]) as $arrow) {
        $next[(int) $arrow['from_id']][] = (int) $arrow['to_id'];
    }

    $state = [];   // 1 — на пути сейчас, 2 — пройден
    $walk = static function (int $at) use (&$walk, &$state, $next, $no): ?array {
        $state[$at] = 1;
        foreach ($next[$at] ?? [] as $to) {
            if (($state[$to] ?? 0) === 1) return ['from' => $no[$at] ?? 0, 'to' => $no[$to] ?? 0];
            if (($state[$to] ?? 0) === 0 && ($found = $walk($to))) return $found;
        }
        $state[$at] = 2;
        return null;
    };
    foreach (array_keys($next) as $start) {
        if (($state[$start] ?? 0) === 0 && ($found = $walk($start))) return $found;
    }
    return null;
}

/**
 * Идут ли два входа из разных веток одного ромба.
 *
 * Смотрим назад по стрелкам на несколько шагов: развилка редко стоит прямо
 * перед сборщиком, чаще между ними есть пара блоков.
 */
function branchesOfSameFork(array $arrows): bool
{
    $forks = [];
    foreach ($arrows as $arrow) {
        // Стрелка прямо из ромба — её ветка известна сразу; дальше — все ромбы позади.
        $own = in_array($arrow['branch'] ?? '', ['yes', 'no'], true) ? [(int) $arrow['from_id'] => $arrow['branch']] : [];
        foreach ($own + forksBehind((int) $arrow['from_id']) as $forkId => $branch) {
            if (isset($forks[$forkId]) && $forks[$forkId] !== $branch) return true;
            $forks[$forkId] = $branch;
        }
    }
    return false;
}

/**
 * Ромбы, из которых можно прийти в этот элемент: ромб => ветка. Идём назад
 * по прямым стрелкам до входа — и сквозь ромбы тоже: развилка бывает вложенной.
 * Ближний ромб записан первым; каждый элемент смотрим один раз.
 */
function forksBehind(int $elementId, array &$seen = []): array
{
    $out = [];
    $ins = dbAll(
        "SELECT a.branch, f.id, f.type FROM elements a JOIN elements f ON f.id = a.from_id
          WHERE a.type = 'arrow' AND a.to_id = ? AND a.back = 0",
        [$elementId]
    );
    foreach ($ins as $row) {
        $id = (int) $row['id'];
        if ($row['type'] === 'decision') $out += [$id => (string) $row['branch']];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $out += forksBehind($id, $seen);
    }
    return $out;
}

/**
 * Входы схемы строками узлов графа (id, no, title, type, …) — с них начинается прогон.
 * Правило одно с ходом (graphEntries): есть стартер — вход он и только он.
 */
function runEntries(int $folderId, ?array $graph = null): array
{
    $graph ??= engineGraph($folderId);
    return array_map(static fn(int $id): array => $graph['nodes'][$id], graphEntries($graph));
}

/** Узлы без прямых входящих стрелок (стартер не в счёт) — по возрастанию номера. */
function runEntriesByArrows(int $folderId): array
{
    $graph = engineGraph($folderId);
    $out = [];
    foreach ($graph['nodes'] as $id => $node) {
        if (!graphIn($graph, (int) $id, false)) $out[(int) $node['no']] = $node;
    }
    ksort($out);
    return array_values($out);
}
