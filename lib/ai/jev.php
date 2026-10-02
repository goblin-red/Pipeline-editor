<?php
/* Сигнальщик сдачи: TypeSafe Jev.
   Отдаёт: jevReady(), jevAsk(), jevSignal(), jevBranch(), stepJev().
   Не делает: ничего не принимает и не возвращает — только поднимает флаг.

   Роль. Всё, что проверяется точно, проверяет сам Гоблин: образец ответа,
   условие ромба, наличие файлов, время шага. Джев берётся за то, чего
   алгоритм не видит — сходится ли сданное по смыслу с заданием и с уже
   принятым. Строка `a=7` после `a=1` образцу отвечает, а заданию нет.

   Правило на расхождение: сказал «нельзя» алгоритм — нельзя, мнения не
   спрашивают. Сказал «можно» алгоритм, а Джев поднял флаг — шаг не
   принимается сам собой, а уходит к leader.

   Замеры на живых прогонах: противоречие он узнаёт уверенно (89% на
   подложенном браке), а вердикт «принять/вернуть» гуляет (47…95%
   уверенности), поэтому решающим голосом он не бывает. */

declare(strict_types=1);

/** Настроен ли сигнальщик: без ключа Гоблин работает как раньше. */
function jevReady(): bool
{
    return trim(jevAccess()['jev_api_key']) !== '';
}

/**
 * Один запрос к модели.
 *
 * Вопросы идут пачкой: состояние — самая дорогая часть запроса, а считаются
 * они по нему параллельно и друг друга не видят.
 */
function jevAsk(array $state, array $questions, int $timeout = 20): array
{
    $c = jevAccess();   // личный Jev владельца проекта или общий (lib/ai/access.php)
    $body = json_encode([
        'state'     => $state,
        'model'     => (string) ($c['jev_model'] ?? 'jev-latest'),
        'questions' => $questions,
    ], JSON_UNESCAPED_UNICODE);

    // Через cURL: под Apache обёртка потоков на https молча зависала.
    $ch = curl_init((string) $c['jev_api_url']);
    curl_setopt_array($ch, aiUrlGuard((string) $c['jev_api_url'], 'jev_api_url') + [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $c['jev_api_key'],
            'Content-Type: application/json',
        ],
    ]);
    $started = microtime(true);
    $answer = curl_exec($ch);
    $why    = curl_error($ch);
    $parsed = is_string($answer) ? json_decode($answer, true) : null;
    apiCallLog(['service' => 'jev', 'what' => isset($questions['branch']) ? 'branch' : 'step',
        'model' => (string) ($parsed['model'] ?? $c['jev_model'] ?? ''),
        'in' => (int) ($parsed['usage']['input_tokens'] ?? 0), 'out' => (int) ($parsed['usage']['output_tokens'] ?? 0),
        'ms' => (int) round((microtime(true) - $started) * 1000),
        'ok' => is_array($parsed) && isset($parsed['answers']), 'error' => $answer === false ? $why : '']);

    if ($answer === false) {
        throw new ApiError(t('agents.ai.jev_down', ['why' => $why ?: t('agents.ai.no_link')]), 'conflict', ['code2' => 'jev_down']);
    }
    $data = json_decode((string) $answer, true);
    if (!is_array($data) || !isset($data['answers'])) {
        throw new ApiError(t('agents.ai.jev_bad', ['text' => mb_substr((string) $answer, 0, 200)]),
            'conflict', ['code2' => 'jev_bad']);
    }
    return $data;
}

/* ── Вопросы ──────────────────────────────────────────────────── */

/* Спрашиваем по-английски: это основной язык модели, на русских вопросах
   она отвечает заметно хуже. Само ТЗ и ответ worker идут как есть. */

const JEV_STEP_QUESTIONS = [
    'contradicts' => [
        'type' => 'noul',
        'instructions' =>
            'Does `result` contradict `task` or `previous` — for example a number that cannot follow '
            . 'from `previous` by the rule in `task`, or a claim `previous` rules out? Judge against '
            . '`previous`, the one result this step continues; `earlier` is older context only.',
    ],
    'verdict' => [
        'type' => 'choice',
        'instructions' =>
            '`task` is the instruction given to a worker. `answer_form` is the exact shape the answer '
            . 'line must have, when the task defines one. `previous` is the result of the step this one '
            . 'continues, and is empty for the first step; `earlier` holds a few older results for '
            . 'context and `inputs` the files handed to the worker. `result` is the single line the '
            . 'worker submitted and `files` are the files attached to the submission. '
            . 'Decide what to do with this submission.',
        'criteria' => [
            'accept'            => 'The submission does what the task asks, in the form the task asks for',
            'return_wrong_work' => 'The worker did a different job than the task describes',
            'return_bad_form'   => 'The job looks done, but the submitted line is not in the form the task requires',
            'return_empty'      => 'Nothing was really submitted: no result, or a line that reports nothing',
        ],
    ],
];

const JEV_BRANCH_QUESTIONS = [
    'branch' => [
        'type' => 'choice',
        'instructions' =>
            '`condition` is the rule written on a decision diamond of a flow chart, `result` is the '
            . 'line of the step that led into it and `inputs` are the earlier accepted results. '
            . 'Does the condition hold?',
        'criteria' => [
            'yes' => 'The condition is true for this result',
            'no'  => 'The condition is false for this result',
        ],
    ],
];

/** По-человечески: что именно не так. */
function jevWhyLabel(string $choice): string
{
    return match ($choice) {
        'accept'            => ta('agents.ai.jev_accept'),
        'return_wrong_work' => ta('agents.ai.jev_wrong_work'),
        'return_bad_form'   => ta('agents.ai.jev_bad_form'),
        'return_empty'      => ta('agents.ai.jev_empty'),
        default             => $choice,
    };
}

/* ── Суждения ─────────────────────────────────────────────────── */

/**
 * Что Джев увидит на сдаче: задание (описание, контекст рамок, ТЗ — как у worker), входы, ответ worker, файлы.
 *
 * Собирается отдельно от запроса: ровно это уходит в журнал строкой
 * «джев ←», чтобы потом было видно, на что он смотрел.
 */
function jevStepState(array $step, array $package): array
{
    $spec = (string) (($package['spec'] ?? [])['text'] ?? '');
    // Задание — то же, что получил worker: описание, контекст групп и областей, ТЗ (taskMeaningText).
    $task = trim((string) ($package['task'] ?? '')) !== '' ? (string) $package['task'] : $spec;
    if (trim($task) === '') {
        throw new ApiError(ta('agents.ai.jev_no_spec'), 'conflict', ['code2' => 'no_spec']);
    }

    /* Вход у шага один — результат, который он продолжает. Раньше сюда
       валилась вся история прогона, и на шестом круге цикла модель уже
       путалась, какая строка последняя: противоречие ползло с 7% до 16%
       на одинаково честной работе. Теперь последний результат стоит
       отдельным полем, а истории даём три строки — только для духа. */
    $earlier = [];
    foreach ($package['earlier'] ?? [] as $one) {
        $earlier[] = trim(($one['no'] ?? '') . ' ' . ($one['title'] ?? '') . ': ' . ($one['result'] ?? ''));
    }
    $previous = $earlier ? (string) array_pop($earlier) : '';
    $earlier  = array_slice($earlier, -3);

    $inputs = [];
    foreach ($package['inputs'] ?? [] as $one) {
        $inputs[] = trim(($one['title'] ?? ta('agents.ai.jev_input')) . ': ' . ($one['uri'] ?? ta('agents.ai.jev_file')));
    }

    $files = [];
    foreach ($package['files'] ?? [] as $one) {
        $files[] = trim(($one['output'] ?? '') . ' ' . ($one['title'] ?? $one['uri'] ?? ''));
    }

    $state = [
        'task'     => $task,
        'previous' => $previous,
        'earlier'  => $earlier,
        'inputs'   => $inputs,
        'result'   => (string) ($step['result'] ?? ''),
        'files'    => $files,
    ];
    /* Образец ответа — это и есть «нужный вид строки»: без него модель
       угадывает форму по тексту ТЗ и чаще придирается к честной работе. */
    if (!empty($package['answer'])) $state['answer_form'] = (string) $package['answer'];
    return $state;
}

/**
 * Посмотреть на сдачу.
 *
 * Главное число — `contradicts`: по нему круг решает, звать ли leader.
 * Вердикт идёт рядом как пояснение, решающим голосом он не бывает.
 */
function jevSignal(array $state): array
{
    $said    = jevAsk($state, JEV_STEP_QUESTIONS);
    $answers = $said['answers'];
    $verdict = $answers['verdict'];

    return [
        'contradicts'   => round((float) ($answers['contradicts']['noul'] ?? 0), 3),
        'verdict'       => (string) $verdict['choice'],
        'why'           => jevWhyLabel((string) $verdict['choice']),
        'confidence'    => round((float) ($verdict['confidence'] ?? 0), 3),
        'accept'        => round((float) ($verdict['probabilities']['accept'] ?? 0), 3),
        'probabilities' => $verdict['probabilities'] ?? [],
        'raw'           => $said,
    ];
}

/**
 * Посмотреть на ромб.
 *
 * Спрашивается только тогда, когда круг сам условие посчитать не смог:
 * арифметику держит `cond`, а словесное правило — этот вопрос. Ветку
 * всё равно выбирает человек, это подсказка в журнал.
 */
function jevBranchState(string $cond, string $result, array $earlier): array
{
    $inputs = [];
    foreach ($earlier as $one) {
        $inputs[] = trim(($one['no'] ?? '') . ' ' . ($one['title'] ?? '') . ': ' . ($one['result'] ?? ''));
    }
    return ['condition' => $cond, 'result' => $result, 'inputs' => $inputs];
}

function jevBranch(array $state): array
{
    $said = jevAsk($state, JEV_BRANCH_QUESTIONS);
    $one  = $said['answers']['branch'];

    return [
        'branch'        => (string) $one['choice'],
        'confidence'    => round((float) ($one['confidence'] ?? 0), 3),
        'probabilities' => $one['probabilities'] ?? [],
        'raw'           => $said,
    ];
}

/* ── Операция ─────────────────────────────────────────────────── */

/**
 * POST step.jev — что скажет Джев о сданном шаге или о ромбе.
 *
 * Ничего не меняет. Обе стороны разговора ложатся в журнал прогона
 * отдельной графой: `jev-ask` — что отправили, `jev-say` — что ответил.
 */
function stepJev(): void
{
    [$project, $run] = runOwn(false);
    // Настройка конкретного прогона главнее наличия ключа: off означает ноль внешних вызовов.
    if ((string) ($run['jev'] ?? 'off') === 'off') {
        throw new ApiError(t('agents.ai.jev_off'), 'conflict', ['code2' => 'jev_off']);
    }
    if (!jevReady()) throw new ApiError(t('agents.ai.jev_no_key'), 'conflict', ['code2' => 'jev_off']);

    $projectId = (int) $project['id'];
    $runId     = (int) $run['id'];
    $no        = inputInt('no');
    if (!$no) throw new ApiError(t('agents.ai.jev_which'));

    $element = dbRow('SELECT * FROM elements WHERE project_id = ? AND `no` = ?', [$projectId, $no]);
    if (!$element) throw new ApiError(t('agents.ai.jev_no_element', ['no' => $no]), 'not_found');

    $branch = (string) $element['type'] === 'decision';
    $step   = null;

    if ($branch) {
        $cond = trim((string) (input('cond') ?? runProp((int) $element['id'], 'cond', '')));
        if ($cond === '') $cond = (string) $element['title'];

        $earlier = stepEarlier($runId);
        $last    = $earlier ? (string) (end($earlier)['result'] ?? '') : '';
        $state   = jevBranchState($cond, (string) (input('result') ?? $last), $earlier);
        $asked   = ta('agents.ai.ev_jev_ask_decision', ['no' => $no]);
    } else {
        $step = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? ORDER BY id DESC LIMIT 1',
            [$runId, $no]);
        if (!$step) throw new ApiError(t('agents.ai.jev_not_opened'), 'not_found');
        if ($step['state'] !== 'submitted') {
            throw new ApiError(t('agents.ai.jev_only_submitted'), 'conflict', ['code2' => 'not_submitted']);
        }

        $state = jevStepState($step, [
            'spec'    => specText('element_id', (int) $element['id']),
            'task'    => taskMeaningText(taskMeaning((int) $element['id'])),
            'inputs'  => stepInputs((int) $element['id']),
            'earlier' => stepEarlier($runId),
            'files'   => jevFilesOf((int) $step['id']),
            'answer'  => (string) runProp((int) $element['id'], 'answer', ''),
        ]);
        $asked = ta('agents.ai.ev_jev_ask_step', ['task' => "{$step['element_no']}.{$step['attempt']}"]);
    }

    jevEvent($project, $run, $element, 'jev-ask', $asked . ' · ' . kbOf($state), $state, $step);

    $started = microtime(true);
    $said    = $branch ? jevBranch($state) : jevSignal($state);
    $took    = (int) round((microtime(true) - $started) * 1000);

    $title = $branch
        ? ta('agents.ai.ev_jev_say_branch', ['branch' => $said['branch'],
            'p' => round(((float) ($said['probabilities'][$said['branch']] ?? 0)) * 100),
            'conf' => round($said['confidence'] * 100)])
        : ta('agents.ai.ev_jev_say_step', ['why' => $said['why'], 'contra' => round($said['contradicts'] * 100),
            'accept' => round($said['accept'] * 100), 'conf' => round($said['confidence'] * 100)]);

    jevEvent($project, $run, $element, 'jev-say', $title, $said['raw'], $step, $took);

    unset($said['raw']);
    if (!empty(input('raw'))) $said['state'] = $state;
    reply(['jev' => $said]);
}

/** Файлы, сданные этим шагом: имя выхода и название. */
function jevFilesOf(int $stepId): array
{
    return dbAll(
        "SELECT l.output, a.title, a.uri FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.step_id = ? AND l.role = 'result' ORDER BY l.id",
        [$stepId]
    );
}

/** Строка в журнал прогона: и вопрос, и ответ пишутся одинаково. */
function jevEvent(array $project, array $run, array $element, string $kind, string $title,
                  $body = null, ?array $step = null, ?int $tookMs = null): void
{
    eventAdd([
        'project_id' => (int) $project['id'],
        'run_id'     => (int) $run['id'],
        'step_id'    => $step ? (int) $step['id'] : null,
        'element_no' => (int) $element['no'],
        'attempt'    => $step ? (int) $step['attempt'] : null,
        'kind'       => $kind,
        'actor'      => 'judge',
        'title'      => $title,
        'body'       => $body,
        'took_ms'    => $tookMs,
    ]);
}
