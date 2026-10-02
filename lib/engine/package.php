<?php
/* Смысл задания, пакет задания worker и пакет для проверяющего.
   Отдаёт: taskMeaning(), taskMeaningText(), taskContextLines(), taskText(), stepPackage(), judgePackage(),
           stepInputText(), stepWorkFile(), packageLines(), stepContext(), stepInputs(),
           stepEarlier(), stepPrevious().
   Не делает: ничего не пишет — только собирает. Описание и ТЗ человека не переписывает. */

declare(strict_types=1);

/**
 * Смысл задания элемента — один на все пути: задание worker простого пути (goTaskText),
 * полный пакет (stepPackage) и проверяющий (judgePackage) получают одно и то же.
 *   about   — описание элемента одной строкой; оно важнее ТЗ;
 *   spec    — ТЗ человека дословно;
 *   context — группы и области, где лежит элемент, от внешней к внутренней (stepContext).
 */
function taskMeaning(int $elementId): array
{
    return [
        'about'   => taskOneLine((string) dbValue('SELECT description FROM elements WHERE id = ?', [$elementId])),
        'spec'    => taskSpec($elementId),
        'context' => stepContext($elementId),
    ];
}

/** Смысл задания одним текстом — проверяющему: описание, контекст рамок и ТЗ, как их видит worker. */
function taskMeaningText(array $meaning): string
{
    $parts = [];
    if ($meaning['about'] !== '') $parts[] = ta($meaning['spec'] === '' ? 'agents.go.about_task' : 'agents.go.about') . $meaning['about'];
    if ($meaning['context']) $parts[] = implode("\n", taskContextLines($meaning['context']));
    if ($meaning['spec'] !== '') $parts[] = $meaning['spec'];
    return implode("\n\n", $parts);
}

/**
 * Контекст рамок строками: шапка, по строке на группу или область, под ней её ТЗ с отступом.
 * Нет рамок — пусто: задание без групп выглядит как раньше.
 */
function taskContextLines(array $context): array
{
    if (!$context) return [];
    $lines = [ta('agents.task.context_head')];
    foreach ($context as $one) {
        $lines[] = '  ' . ta('agents.task.context_row', ['what' => ta('agents.kinds.' . $one['type']), 'no' => $one['no'], 'title' => $one['title']])
            . ($one['about'] !== '' ? ta('agents.task.context_about') . $one['about'] : '');
        if ($one['text'] === '') continue;
        foreach (preg_split('/\R/u', $one['text']) as $row) $lines[] = $row === '' ? '' : '    ' . $row;
    }
    return $lines;
}

/** ТЗ элемента дословно, без пустых краёв; нет ТЗ — пусто. */
function taskSpec(int $elementId): string
{
    $spec = specText('element_id', $elementId);
    return trim(is_array($spec) ? (string) ($spec['text'] ?? '') : (string) $spec);
}

/**
 * Задание элемента текстом: ТЗ, а пустое — описание (ТЗ-файл необязателен). Пусто — задания нет:
 * по этому признаку предпроверка, выдача шага и «ждёт человека» решают, можно ли работать.
 */
function taskText(int $elementId): string
{
    $spec = taskSpec($elementId);
    return $spec !== '' ? $spec : taskOneLine((string) dbValue('SELECT description FROM elements WHERE id = ?', [$elementId]));
}

/** Описание — короткая строка карточки: переносы и лишние пробелы — одним пробелом. */
function taskOneLine(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * Пакет задания: всё, что уходит worker.
 *
 * Отдельной функцией, потому что тем же пакетом пишется снимок в ленту
 * прогона при выдаче шага: потом видно, что именно получил worker, даже
 * если схему успели поправить.
 */
function stepPackage(array $step, int $projectId): array
{
    $run = runRow((int) $step['run_id'], $projectId);
    $element = $step['element_id'] ? elementRow((int) $step['element_id'], $projectId) : null;
    $folder = $element ? folderRow((int) $element['folder_id'], $projectId) : null;
    $meaning = $element ? taskMeaning((int) $element['id']) : null;

    $package = [
        'step'    => stepShape($step),
        'address' => $step['element_no'] . '.' . $step['attempt'],
        'run'     => 'r' . $run['no'],
        'element' => $element ? elementShape($element, VIEW_WORK) : null,
        'spec'    => $element ? specText('element_id', (int) $element['id']) : null,
        // Тот же смысл задания, что у простого пути и проверяющего (taskMeaning).
        'about'   => $meaning['about'] ?? '',
        'context' => $meaning['context'] ?? [],
        'task'    => $meaning ? taskMeaningText($meaning) : '',
        'inputs'  => $element ? stepInputs((int) $element['id']) : [],
        'earlier' => stepEarlier((int) $run['id']),
        'workDir' => (string) $run['work_dir'],
        // Результаты прогона — его папка out/rN, как в задании простого пути.
        'outDir'  => runOutDir($run, false),
        // Служебное прогона — вне рабочей папки и вне public/: пакеты и журнал круга.
        'serviceDir'  => runServiceDir((int) $run['id']),
        'packageFile' => runServiceDir((int) $run['id']) . '/steps/' . (int) $step['id']
            . '-' . $step['element_no'] . '.' . $step['attempt'] . '.md',
        'limits'  => $element ? [
            'timeout'    => runProp((int) $element['id'], 'timeout', null),
            'paidCalls'  => (int) runProp((int) $element['id'], 'paid_calls', 1),
            'outputs'    => runProp((int) $element['id'], 'outputs', []),
            // Сколько выдач у блока всего: worker важно знать, что попытка последняя.
            'maxAttempts' => runProp((int) $element['id'], 'max_attempts', null),
            'attempt'     => (int) $step['attempt'],
        ] : [],
        'previous' => stepPrevious((int) $run['id'], (int) $step['element_no'], (int) $step['attempt']),
        // Что leader сказал вслух (`goblin say`). Раньше это оставалось только
        // в ленте, и worker слова leader не видел вовсе.
        'said'     => stepSaid((int) $run['id'], (int) $step['element_no'], (string) $step['opened_at']),
        'jobs'     => jobsOfStep((int) $step['id']),
        'note'     => (string) $step['note'],
        // По чему будут проверять: образец ответа и смысловые критерии блока.
        // worker должен знать правила до работы, а не узнавать их из возврата.
        'input'    => stepInputText($step),
        'vars'     => varsIn((int) $step['id'])['vars'],
        'answer'   => $element ? (string) runProp((int) $element['id'], 'answer', '') : '',
        'accept'   => $element ? packageLines(propsOf((int) $element['id'])['accept'] ?? []) : [],
        'reject'   => $element ? packageLines(propsOf((int) $element['id'])['reject'] ?? []) : [],
        // Кем работать: чем, какой моделью и с каким усилием. Без этого worker
        // выбирал настройки на глазок, а в карточке агента они уже записаны.
        'agent'    => $step['agent_id'] ? agentShape(dbRow('SELECT * FROM agents WHERE id = ?', [(int) $step['agent_id']])) : null,
        'rules'    => 'docs.get?file=worker',
        'stop'     => $run['stop_at'] !== null,
    ];

    // Общая картина схемы — только если человек включил это в свойствах папки.
    if ($folder && (int) $folder['share_scheme'] === 1) {
        $package['scheme'] = folderScheme((int) $folder['id'], VIEW_WORK);
        $package['folder'] = folderShape($folder, VIEW_WORK);
    }
    return $package;
}

/**
 * Пакет для проверяющего: ровно то, по чему выносится решение.
 * Уходит в judges/jev.php (а наружу — только его поля task, input, work, accept, reject).
 */
function judgePackage(array $run, array $step, array $graph): array
{
    $elementId = (int) $step['element_id'];
    $props = $graph['nodes'][$elementId]['props'] ?? [];

    return [
        // Та же задача, что получил worker: описание, контекст групп и областей, ТЗ.
        'task'      => $elementId ? taskMeaningText(taskMeaning($elementId)) : '',
        'input'     => stepInputText($step),
        'vars'      => varsIn((int) $step['id'])['vars'],
        'work_file' => stepWorkFile($step),
        // В fingerprint входят идентичности всех сдаваемых артефактов, но не пути наружу.
        'artifacts' => stepArtifacts((int) $step['id']),
        'answer'    => (string) ($props['answer'] ?? ''),
        'accept'    => packageLines($props['accept'] ?? []),
        'reject'    => packageLines($props['reject'] ?? []),
        'judge'     => (string) ($props['judge'] ?? ''),
    ];
}

/** Стабильная локальная идентичность сданных материалов; содержимое больших файлов не читаем. */
function stepArtifacts(int $stepId): array
{
    return dbAll(
        "SELECT a.id, l.output, a.kind, a.title, a.mime, a.bytes, a.sha256, a.updated_at
           FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.step_id = ? AND l.role = 'result' ORDER BY l.id",
        [$stepId]
    );
}

/** Вход шага: отчёты блоков, давших ему жетоны, — сквозь ромбы и шлюзы. У слияния — по строке на вход. */
function stepInputText(array $step): string
{
    $ids = array_map('intval', array_column(
        dbAll('SELECT from_step_id FROM run_marks WHERE taken_by_step_id = ? ORDER BY id', [(int) $step['id']]),
        'from_step_id'));
    return implode("\n", array_column(stepInputRows($ids), 'result'));
}

/** Путь к сданному текстовому файлу — его и проверяют по смыслу. Нет такого — пусто. */
function stepWorkFile(array $step): ?string
{
    $rows = dbAll(
        "SELECT a.uri FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.step_id = ? AND l.role = 'result' ORDER BY l.id",
        [(int) $step['id']]
    );
    foreach ($rows as $row) {
        $uri = (string) $row['uri'];
        $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
        if ($uri !== '' && in_array($ext, ['txt', 'md', 'csv', 'json', 'html', 'htm', 'xml', 'yml', 'yaml'], true)) {
            return $uri;
        }
    }
    return null;
}

/** Список критериев: строками из свойства блока (текст в несколько строк или уже список). */
function packageLines($value): array
{
    $lines = is_array($value) ? $value : preg_split('/\R/u', (string) $value);
    return array_values(array_filter(array_map('trim', $lines ?: []), static fn(string $one) => $one !== ''));
}

/**
 * Группы и области, в которых лежит элемент, — все предки по составу (members), от внешней
 * к внутренней, без повторов. Порядок устойчивый: рамки — по порядку состава, предки
 * рамки — раньше неё. У каждой: тип, номер, название, описание и ТЗ (text; нет — пусто).
 * Рамка без названия, описания и ТЗ ничего не говорит — её нет.
 */
function stepContext(int $elementId): array
{
    $order = [];
    $climb = static function (int $id, array $path) use (&$climb, &$order): void {
        foreach (containersOf($id) as $up) {
            if (isset($order[$up]) || isset($path[$up])) continue;   // уже взята; кольца состав не пускает
            $climb($up, $path + [$up => true]);
            $order[$up] = true;
        }
    };
    $climb($elementId, [$elementId => true]);

    $out = [];
    foreach (array_keys($order) as $id) {
        $row = dbRow('SELECT `no`, type, title, description FROM elements WHERE id = ?', [$id]);
        if (!$row) continue;
        $one = ['no' => (int) $row['no'], 'type' => (string) $row['type'], 'title' => trim((string) $row['title']),
                'about' => taskOneLine((string) $row['description']), 'text' => taskSpec($id)];
        if ($one['title'] !== '' || $one['about'] !== '' || $one['text'] !== '') $out[] = $one;
    }
    return $out;
}

/** Входы: что прицеплено к элементу как исходник. */
function stepInputs(int $elementId): array
{
    $rows = dbAll(
        "SELECT a.id, a.kind, a.title, a.uri, a.file_key FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.element_id = ? AND l.role IN ('input','reference') ORDER BY l.sort",
        [$elementId]
    );
    return array_map(static fn(array $r) => [
        'id' => (int) $r['id'], 'kind' => $r['kind'], 'title' => $r['title'],
        'uri' => $r['uri'], 'file' => $r['file_key'] !== null,
    ], $rows);
}

/**
 * Слова leader и человека этому шагу: события `message` из ленты.
 *
 * Берём сказанное про этот блок и сказанное всему прогону, начиная с момента,
 * когда шаг открыли: старые разговоры про прошлые круги worker ни к чему.
 * Пять последних — больше в задание не помещается и не нужно.
 */
function stepSaid(int $runId, int $no, string $since): array
{
    $rows = dbAll(
        "SELECT title, body, actor FROM run_events
          WHERE run_id = ? AND kind = 'message'
            AND (element_no IS NULL OR element_no = ?)
            AND at >= ?
          ORDER BY id DESC LIMIT 5",
        [$runId, $no, $since !== '' ? $since : '1970-01-01']
    );
    $said = [];
    foreach (array_reverse($rows) as $row) {
        $text = trim((string) ($row['body'] ?? '')) !== ''
            ? (string) $row['body']
            : (string) $row['title'];
        if (trim($text) !== '') $said[] = ['by' => (string) $row['actor'], 'text' => trim($text)];
    }
    return $said;
}

/** Коротко: что уже принято в этом прогоне. */
function stepEarlier(int $runId): array
{
    /* Решения ромбов сюда не идут. Это шаги leader, а не работа: чисел
       в них нет, но стоят они последними и притворяются входом — на этом
       спотыкались и worker, и тот, кто писал им ТЗ. Ветку worker знать
       незачем: его вход — результат предыдущей работы. */
    $rows = dbAll(
        "SELECT s.element_no, s.element_title, s.result FROM run_steps s
           JOIN elements e ON e.id = s.element_id
          WHERE s.run_id = ? AND s.state = 'accepted' AND e.type NOT IN ('decision','gateway')
          ORDER BY s.id",
        [$runId]
    );
    return array_map(static fn(array $r) => [
        'no' => (int) $r['element_no'], 'title' => $r['element_title'], 'result' => (string) $r['result'],
    ], $rows);
}

/** Прошлая попытка того же элемента: причина возврата и её заявки. */
function stepPrevious(int $runId, int $no, int $attempt): ?array
{
    if ($attempt <= 1) return null;
    $row = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? AND attempt = ?', [$runId, $no, $attempt - 1]);
    if (!$row) return null;
    return [
        'attempt' => (int) $row['attempt'],
        'state'   => $row['state'],
        'why'     => (string) $row['error'],
        'jobs'    => jobsOfStep((int) $row['id']),
    ];
}
