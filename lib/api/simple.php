<?php
/* Простой путь для агента-leader: обычные GET-адреса и ответ текстом; сдача
   (done, fail) — ещё и POST-формой, для длинного и многострочного ответа.
   Отдаёт: goScheme(), goBegin(), goTask(), goWork(), goDone(), goFail(),
           goAgain(), goWhere(), goWait(), goStop(), goInvite(), goText().
   Не делает: не заводит своих правил прогона — зовёт тот же движок
              (lib/engine/), что и полный API. Сдачу не проверяет: за работу worker
              отвечает leader, сервер лишь мягко сверяет вид ответа («⚠», goAnswerWarnings).

   Зачем отдельно от полного API: агенту в терминале нужен один curl и ответ,
   который читается глазами. JSON, пропуска и пачки — не его дело.

   leader видит только каркас: номер и имя блока, адрес задания, команду
   (goNextLines). ТЗ и вход видит только worker: op=task пишет задание в файл (goTaskFile)
   и отдаёт строку «задание по ссылке …» — worker читает её curl-ом (op=text, goText),
   хоть с другого компьютера; текст — &text=1. Адрес задания «137.3» (блок.круг) стоит в первой
   строке задания и в начале ответа worker; done по нему отказывает ответу
   на чужой круг (goAnswerAddress).

   Договор: ответ на любую команду сразу содержит следующий шаг. Отдельного
   запроса «что дальше» не нужно — иначе leader гадал бы, когда спрашивать. */

declare(strict_types=1);

/** Имена простого пути: по ним вход отличает текстовый ответ от JSON. */
const SIMPLE_OPS = ['scheme', 'begin', 'task', 'work', 'done', 'fail', 'again', 'where', 'wait', 'stop', 'invite', 'text'];

/** Состояние шага словом — для короткой сводки. */

/** Спросили ли простую операцию: смотрим прямо в запрос, до разбора. */
function simpleOp(): bool
{
    $op = (string) ($_GET['op'] ?? '');
    return in_array($op, SIMPLE_OPS, true);
}

/** Ответ обычным текстом: агент читает его глазами, а не разбирает JSON. */
function plain(array $lines, int $code = 200): void
{
    goExchangeLog($lines, $code);
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo implode("\n", $lines) . "\n";
    exit;
}

/** Прогон этого запроса — для ленты переписки (goExchangeLog). */
function goLogRun(array $run): array
{
    $GLOBALS['goLogRun'] = ['project' => (int) $run['project_id'], 'run' => (int) $run['id']];
    return $run;
}

/**
 * Переписка leader с сервером — в ленту прогона: что он прислал и что получил.
 * Одна строка на обращение: «→ done · 137.3: a=11 · ← ok: блок 137 принят…»,
 * раскрыл — полный ответ сервера (текст задания не повторяем — он один раз в событии package).
 * Время сервера на команду — в took_ms. Ошибки тоже: они идут через plain().
 * Опросы статуса (where&short, wait) не пишем — их зовёт и сторож, лента
 * утонула бы в одинаковых строках.
 */
function goExchangeLog(array $lines, int $code): void
{
    $ctx = $GLOBALS['goLogRun'] ?? null;
    $op = (string) ($_GET['op'] ?? '');
    if (!$ctx || !in_array($op, ['begin', 'task', 'work', 'done', 'fail', 'again', 'stop', 'where', 'invite'], true)) return;
    if ($op === 'where' && input('short')) return;

    $ask = [$op];
    foreach (['block', 'branch', 'result', 'why', 'text'] as $key) {
        $value = trim((string) (input($key) ?? ''));
        if ($value !== '') $ask[] = $key === 'result' || $key === 'why' ? $value : "$key=$value";
    }
    $rows = array_values(array_filter(array_map('trim', explode("\n", implode("\n", $lines))), 'strlen'));
    // Полный текст задания — в событии package: здесь только ссылка на него.
    if ($op === 'task' && input('text')) {
        $rows = [ta('agents.go.log_task_text') . (int) dbValue(
            "SELECT COALESCE(MAX(id), 0) FROM run_events WHERE run_id = ? AND kind = 'package'", [$ctx['run']])];
    }
    try {
        eventAdd([
            'project_id' => $ctx['project'], 'run_id' => $ctx['run'], 'kind' => 'message', 'actor' => 'lead',
            'title' => '→ ' . mb_substr(implode(' · ', $ask), 0, 110) . '  ← ' . mb_substr($rows[0] ?? '', 0, 110),
            'body'  => ta('agents.go.log_ask') . implode(' · ', $ask) . "\n\n" . ta('agents.go.log_reply')
                . ($code !== 200 ? ta('agents.go.log_code', ['code' => $code]) : '') . ":\n" . implode("\n", $rows) . "\n",
            'took_ms' => (int) round((microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000),
        ]);
    } catch (Throwable $e) {
        error_log('[goblin] лента переписки: ' . $e->getMessage());   // ответ leader важнее ленты
    }
}

/**
 * Ход движка после команды leader — с правом спросить Jev.
 *
 * Ромб в прогоне leader решает Jev, а запрос к нему — сеть. advanceAfter
 * сеть не зовёт, поэтому здесь полный engineAdvance: он ходит к Jev между
 * замками и сразу сворачивает по его ответу. Сбой хода команду не отменяет.
 */
function goMove(int $runId): void
{
    try {
        engineAdvance($runId, true);
    } catch (Throwable $e) {
        error_log('[goblin] ход простого пути: ' . $e->getMessage());
    }
}

/**
 * Ход движка и строки о ромбах, которые он решил: «ромб 138 → НЕТ · Jev: нет 1,00».
 * Без них leader угадывал ветку по тому, какой блок выдан следующим.
 */
function goMoveLines(int $runId): array
{
    $before = (int) dbValue('SELECT COALESCE(MAX(id), 0) FROM run_events WHERE run_id = ?', [$runId]);
    goMove($runId);
    return array_map(static fn(array $one) => (string) $one['title'], dbAll(
        "SELECT title FROM run_events WHERE run_id = ? AND id > ? AND kind = 'decide' ORDER BY id",
        [$runId, $before]));
}

/** Итог прогона: сколько приёмок и чьих — «приёмок 10: блоков 6, ромбов 4». */
function goTally(int $runId): string
{
    $rows = dbAll(
        "SELECT e.type, COUNT(*) n FROM run_steps s LEFT JOIN elements e ON e.id = s.element_id
          WHERE s.run_id = ? AND s.state = 'accepted' GROUP BY e.type", [$runId]);
    $by = array_column($rows, 'n', 'type');
    $total = array_sum(array_map('intval', $by));
    // Формат постоянный: блоки и ромбы всегда, шлюзы — когда были.
    $parts = [ta('agents.go.tally_blocks', ['n' => (int) ($by['block'] ?? 0)]), ta('agents.go.tally_decisions', ['n' => (int) ($by['decision'] ?? 0)])];
    if (!empty($by['gateway'])) $parts[] = ta('agents.go.tally_gateways', ['n' => (int) $by['gateway']]);
    // Приёмки, а не карточки: блок цикла принят столько раз, сколько было кругов.
    return ta('agents.go.tally', ['n' => $total, 'parts' => implode(', ', $parts)]);
}

/** Прогон, о котором речь: по имени (`rN` или id) или живой прогон папки. */
function goRun(int $projectId): array
{
    $asked = runAsked($projectId);
    if ($asked) return goLogRun(runRow($asked, $projectId));

    $folderId = inputInt('folder');
    if ($folderId) {
        $live = runActive($folderId);
        if ($live) return goLogRun($live);
        throw new ApiError(ta('agents.go.no_live_run', ['folder' => $folderId]), 'not_found');
    }
    throw new ApiError(ta('agents.go.which_run'), 'not_found');
}

/** Ответ стартера без посылки шлюза — «старт» или «start» (язык проекта, agents.steps.start_result). */
const GO_START_WORDS = ['старт', 'start'];

/**
 * Сдача блоков, чьи жетоны вошли в этот блок.
 *
 * leader ничего не пересказывает, поэтому вход собирает сервер: берём
 * отчёты предшественников дословно и кладём их в текст задания. На повторе
 * по циклу добавляется вход прошлого круга — с пометкой «(прежний вход)».
 */
function goInputs(array $run, array $element, ?array $step): array
{
    if ($step) {
        $marks = dbAll('SELECT from_step_id FROM run_marks WHERE taken_by_step_id = ? ORDER BY id',
            [(int) $step['id']]);
    } else {
        try {
            $marks = marksEnter($run, $element, null)['marks'];
        } catch (Throwable $e) {
            return [];   // нечем открыть — значит и входа ещё нет
        }
    }

    $ids = array_values(array_unique(array_map(
        static fn(array $m) => (int) $m['from_step_id'], $marks)));
    /* «блок 13 «Старт» → старт» ничего не говорит исполнителю — строку стартера не показываем.
       Посылку шлюза стартер несёт своим текстом — она остаётся во входе. */
    $rows = array_values(array_filter($ids ? stepInputRows($ids) : [],
        static fn(array $row) => !in_array($row['result'], GO_START_WORDS, true)));

    /* Повтор по обратной стрелке приносит один жетон — от ромба. Вход прошлого
       круга добираем: без него «тема» из блока выше пропадала. Свежая строка
       того же блока заменяет прежнюю, прежние помечены — «(прежний вход)». */
    $prev = dbRow("SELECT * FROM run_steps WHERE run_id = ? AND element_id = ? AND state = 'accepted'
                     AND id < ? ORDER BY id DESC LIMIT 1",
        [(int) $run['id'], (int) $element['id'], $step ? (int) $step['id'] : PHP_INT_MAX]);
    if (!$prev) return $rows;
    $fresh = array_column($rows, null, 'no');
    $out = [];
    foreach (goInputs($run, $element, $prev) as $old) {
        // «старт» стартера (stepStarterDo) на круге 2 ничего не говорит — не повторяем.
        if (!isset($fresh[$old['no']]) && in_array($old['result'], GO_START_WORDS, true)) continue;
        $out[$old['no']] = $fresh[$old['no']] ?? ['old' => true] + $old;
    }
    return array_values($out + $fresh);
}

/**
 * «Круг 2 — вернул ромб 44 «Ровно три?»: нет» — почему блок делается снова.
 * Без строки worker видел изменившийся файл и писал замечание о странности.
 */
function goRoundLine(array $step): ?string
{
    if ((int) $step['attempt'] < 2) return null;
    $back = dbRow("SELECT s.element_no, s.result, e.title, e.type FROM run_marks m
                     JOIN run_steps s ON s.id = m.from_step_id JOIN elements e ON e.id = s.element_id
                    WHERE m.taken_by_step_id = ? ORDER BY m.id DESC LIMIT 1", [(int) $step['id']]);
    if (!$back || !dbValue("SELECT 1 FROM run_steps WHERE run_id = ? AND element_id = ? AND state = 'accepted'
                              AND id < ? LIMIT 1", [(int) $step['run_id'], (int) $step['element_id'], (int) $step['id']])) {
        return null;   // не цикл, а переделка (again): вход тот же
    }
    return ta('agents.go.round_line', ['n' => (int) $step['attempt'],
        'what' => ta($back['type'] === 'decision' ? 'agents.go.word_decision' : 'agents.go.word_block'),
        'no' => $back['element_no'], 'title' => $back['title'], 'result' => mb_strtolower(trim((string) $back['result']))]);
}

/**
 * Текст задания для worker — ровно то, что копируют.
 *
 * Собран один раз и здесь: ТЗ человека дословно, а вход и образец ответа
 * подставляет сервер, потому что они меняются от круга к кругу. Смысл задания —
 * описание, контекст групп и областей, ТЗ — общий сборщик движка (taskMeaning):
 * то же получают полный пакет и проверяющий.
 */
function goTaskText(array $element, array $vars, array $inputs = [], string $workDir = '',
                    string $address = '', string $outDir = '', int $spent = 0, array $run = []): array
{
    // Агент в вебе: папки — у него, материалы — ссылками, сделанное прогоном — по сданным файлам.
    $remote = (string) ($run['agent_dir'] ?? '') !== '';
    $meaning = taskMeaning((int) $element['id']);
    // Адрес «137.3» — блок и круг: с ним worker начинает ответ, по нему done узнаёт круг.
    $lines = [ta('agents.go.task_head', ['no' => (int) $element['no'], 'title' => $element['title']])
        . ($address !== '' ? ta('agents.go.task_address', ['address' => $address]) : '')];

    /* worker сидит в своей папке и «pic/» у себя не найдёт: путь ему даёт
       сервер, чтобы leader нечего было дописывать от себя. */
    if ($workDir !== '') $lines[] = ta('agents.go.work_dir') . $workDir . ($remote ? ta('agents.go.work_dir_agent') : '');
    /* Материалы — in/, результаты — своя папка прогона out/rN. ТЗ пишут просто «out/…»
       (и так же называют файлы ответы прошлых блоков) — это и есть она. */
    if ($outDir !== '') {
        $rel = substr($outDir, strlen(rtrim($workDir, '/')) + 1) . '/';
        $lines[] = ta('agents.go.out_dir', ['rel' => $rel]);
    }

    /* Описание блока — короткая строка человека на карточке. Оно важнее ТЗ:
       ТЗ бывает длинным и отстаёт от правок, а описание человек видит на
       холсте и правит первым. Расходятся — worker делает по описанию. ТЗ нет — описание и есть задание. */
    if ($meaning['about'] !== '') $lines[] = ta($meaning['spec'] === '' ? 'agents.go.about_task' : 'agents.go.about') . $meaning['about'];

    /* Материалы, прикреплённые к блоку (справка, вход, вложение), — строкой с путями.
       Так инструкция к сервису (grok.md) доходит только до тех блоков, что к нему
       обращаются: её прикрепили к ним, а не положили в общее ТЗ. */
    $files = goBlockMaterials((int) $element['id'], $workDir, $remote ? $run : []);
    if ($files) $lines[] = ta($remote ? 'agents.go.block_files_web' : 'agents.go.block_files') . implode(', ', $files);

    /* Что прогон уже сделал — файлы out/rN/ с диска. «Вход» несёт только ответы
       прямых предшественников, а блоку после развилки нужны и файлы выше по схеме. */
    $made = $remote ? goRunFilesAgent($run) : goRunFiles($workDir, $outDir);
    if ($made) $lines[] = ta('agents.go.run_files') . implode(', ', $made);

    if ($vars) {
        $lines[] = ta('agents.go.input') . ' ' . implode(' ', array_map(
            static fn($k, $v) => "$k=$v", array_keys($vars), $vars));
    }
    /* Что сдали блоки-предшественники. Без этого worker не знает, с чем
       работать: числа движок носит сам, а отчёты строками — нет. */
    // Ответ из одних чисел («a=1 s=1») уже стоит строкой выше — не повторяем.
    if ($vars) {
        $inputs = array_values(array_filter($inputs, static fn(array $one) =>
            trim((string) preg_replace('/[\p{L}_][\p{L}\p{N}_]*\s*=\s*-?\d+(?:[.,]\d+)?|[\s·,;]+/u', '',
                $one['result'])) !== ''));
    }
    if ($inputs) {
        $lines[] = ta($vars ? 'agents.go.input_answers' : 'agents.go.input');
        foreach ($inputs as $one) {
            $lines[] = ta('agents.go.input_row', ['no' => $one['no'], 'title' => $one['title']]) . $one['result']
                . (!empty($one['old']) ? ta('agents.go.input_old') : '');
        }
    }
    // Платные вызовы стоят денег: предел worker должен видеть в задании.
    $paid = (int) runProp((int) $element['id'], 'paid_calls', 0);
    if ($paid > 0) $lines[] = ta('agents.go.paid_max', ['n' => $paid]);
    // Сколько уже ушло за прогон — итоговому блоку не нужно искать по чужим заданиям.
    if ($spent > 0) $lines[] = ta('agents.go.paid_spent', ['n' => $spent]);

    $answer = trim((string) runProp((int) $element['id'], 'answer', ''));
    if ($address !== '') {
        $lines[] = ta('agents.go.answer_as') . $address . ': ' . ($answer !== '' ? $answer : ta('agents.go.your_answer'));
    } elseif ($answer !== '') {
        $lines[] = ta('agents.go.answer_as') . $answer;
    }

    /* Группы и области вокруг блока — общие правила этапа, от внешней рамки к
       внутренней; ТЗ самого блока — последним. Рамок нет — строк нет. */
    $context = taskContextLines($meaning['context']);
    if ($context) array_push($lines, '', ...$context);
    if ($meaning['spec'] !== '') array_push($lines, '', $meaning['spec']);
    return $lines;
}

/** Файлы в out/rN/ прогона — от рабочей папки, по имени, не больше 30. */
function goRunFiles(string $workDir, string $outDir): array
{
    if ($outDir === '' || !is_dir($outDir)) return [];
    $rel = $workDir !== '' ? substr($outDir, strlen(rtrim($workDir, '/')) + 1) . '/' : '';
    $out = [];
    foreach (scandir($outDir) ?: [] as $name) {
        if ($name[0] === '.' || !is_file("$outDir/$name")) continue;
        $out[] = $rel . $name;
    }
    sort($out);
    return array_slice($out, 0, 30);
}

/** Что прогон уже сделал у агента в вебе — сданные файлы результата, пути от его папки (не больше 30). */
function goRunFilesAgent(array $run): array
{
    $rows = dbAll("SELECT DISTINCT a.uri FROM asset_links l JOIN assets a ON a.id = l.asset_id
                    WHERE l.made_by_run = ? AND l.role = 'result' AND a.uri IS NOT NULL ORDER BY a.uri", [(int) $run['id']]);
    return array_slice(array_column($rows, 'uri'), 0, 30);
}

/** «вызовов=3» в ответе — сколько платных вызовов ушло; нет пометки — null. Слово целиком: «recalls=5» — не вызовы. */
function goCalls(string $result): ?int
{
    return preg_match('/\b(?:вызов\w*|calls?)\s*[=:]\s*(\d+)/iu', $result, $m) ? (int) $m[1] : null;
}

/** Платные вызовы прогона — сумма пометок в принятых ответах блоков с пределом paid_calls. */
function goSpent(int $runId): int
{
    $sum = 0;
    foreach (dbAll("SELECT element_id, result FROM run_steps WHERE run_id = ? AND state = 'accepted'", [$runId]) as $row) {
        if ((int) runProp((int) $row['element_id'], 'paid_calls', 0) > 0) $sum += goCalls((string) $row['result']) ?? 0;
    }
    return $sum;
}

/**
 * Повтор блока пишет в тот же out/rN/файл. Прежнюю версию бережём копией
 * out/rN/.попытки/42.1-story.md, и запись прошлой попытки смотрит на копию —
 * иначе у неё в истории оказались бы байты нового круга. Точка в имени папки
 * прячет её из строки «Файлы прогона» (goRunFiles).
 */
function goKeepTries(array $run, array $step): void
{
    $out = realpath(runOutDir($run, false));
    if (!$out) return;
    $rows = dbAll("SELECT DISTINCT a.id, a.uri, a.sha256, s.element_no, s.attempt FROM asset_links l
                     JOIN assets a ON a.id = l.asset_id JOIN run_steps s ON s.id = l.step_id
                    WHERE l.made_by_run = ? AND l.role = 'result' AND s.element_id = ? AND s.id <> ?",
        [(int) $run['id'], (int) $step['element_id'], (int) $step['id']]);
    foreach ($rows as $row) {
        $path = (string) $row['uri'];
        if (!str_starts_with($path, $out . '/') || str_contains($path, '/.попытки/') || !is_file($path)
            || hash_file('sha256', $path) !== $row['sha256']) continue;
        $keep = "$out/.попытки/{$row['element_no']}.{$row['attempt']}-" . basename($path);
        if (!is_dir(dirname($keep))) @mkdir(dirname($keep), 0775, true);
        if (@copy($path, $keep)) dbRun('UPDATE assets SET uri = ? WHERE id = ?', [$keep, (int) $row['id']]);
    }
}

/**
 * Материалы блока для задания: «in/grok.md (справка)». Путь внутри рабочей папки —
 * от неё, прочий — как есть; материал без файла (текст в базе) — по названию.
 * Агент в вебе ($run с agent_dir): файл лежит на сервере — «in/grok.md ← ссылка», агент скачивает его в свою in/.
 */
function goBlockMaterials(int $elementId, string $workDir, array $run = []): array
{
    $root = rtrim($workDir, '/') . '/';
    $out = [];
    $key = $run ? (string) dbValue('SELECT url_key FROM projects WHERE id = ?', [(int) $run['project_id']]) : '';
    $server = $run ? rtrim((string) dbValue('SELECT work_dir FROM folders WHERE id = ?', [(int) $run['folder_id']]), '/') . '/' : '';
    foreach (dbAll("SELECT l.role, a.id, a.uri, a.file_key, a.original_name, a.title FROM asset_links l JOIN assets a ON a.id = l.asset_id
                     WHERE l.element_id = ? AND l.role IN ('reference','input','attachment') AND l.made_by_run IS NULL
                     ORDER BY FIELD(l.role, 'reference', 'input', 'attachment'), l.sort, l.id", [$elementId]) as $row) {
        $uri = (string) $row['uri'];
        if ($run && ($row['file_key'] !== null || str_starts_with($uri, '/'))) {
            $name = $server !== '/' && str_starts_with($uri, $server) ? substr($uri, strlen($server))
                : 'in/' . basename($uri !== '' ? $uri : (string) ($row['original_name'] ?: $row['title']));
            $link = runBaseUrl() . '?' . http_build_query(['project' => $key, 'op' => 'asset.file', 'asset' => (int) $row['id']]);
            $out[] = $name . ' ← ' . $link . ' (' . ta('agents.go.role.' . $row['role']) . ')';
            continue;
        }
        $name = $uri === '' ? '«' . $row['title'] . '»'
            : ($workDir !== '' && str_starts_with($uri, $root) ? substr($uri, strlen($root)) : $uri);
        $out[] = $name . ' (' . ta('agents.go.role.' . $row['role']) . ')';
    }
    return array_values(array_unique($out));
}

/**
 * Почему движок сам не выдаёт готовый блок — или null, если выдаст.
 * Те же причины, по которым advanceIssue зовёт человека.
 */
function goStuck(int $runId, array $element): ?string
{
    $id = (int) $element['id'];
    // Попытки — как их видит advanceIssue: свежая первой.
    $tries = dbAll('SELECT * FROM run_steps WHERE run_id = ? AND element_id = ? ORDER BY attempt DESC',
        [$runId, $id]);
    $last = $tries[0] ?? null;

    if ($last && $last['state'] === 'failed') {
        return ta('agents.go.stuck_failed') . (trim((string) $last['error']) !== '' ? ': ' . $last['error'] : '');
    }
    if ($last && $last['state'] === 'cancelled') return ta('agents.go.stuck_cancelled');
    if (advanceAutoReturns($tries) >= 2) return ta('agents.go.stuck_twice');

    // Пустая карточка агента блок не держит: его делает ведущий сам.
    if (taskText($id) === '') return ta('agents.go.stuck_no_spec');

    $limit = graphMaxAttempts(engineGraph((int) $element['folder_id']), $id);
    if (stepOverLimit($runId, (int) $element['no'], $limit)) return ta('agents.go.stuck_limit', ['n' => $limit]);
    return null;
}

/** GET op=scheme&folder=N — вся схема одним экраном. */
function goScheme(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $folder = folderRow((int) inputInt('folder'), $projectId);
    $folderId = (int) $folder['id'];

    $lines = [ta('agents.go.scheme_head', ['name' => $folder['name'], 'id' => $folderId])];
    $lines[] = ta('agents.go.scheme_dir') . (agentsRemote() ? ta('agents.go.scheme_dir_agent', ['path' => agentFolderPath($folder)])
        : ((string) $folder['work_dir'] ?: ta('agents.go.scheme_no_dir')));
    $lines[] = '';

    $starters = array_map(static fn(array $one) => (int) $one['id'], folderStarters($folderId));
    foreach (dbAll("SELECT * FROM elements WHERE folder_id = ? AND type <> 'arrow' ORDER BY `no`", [$folderId]) as $one) {
        // Стартер — прогон начинается с него; исполнитель ему не нужен.
        if (in_array((int) $one['id'], $starters, true)) {
            $lines[] = sprintf(ta('agents.go.scheme_starter'), (int) $one['no'], $one['title']);
            continue;
        }
        $agent = $one['agent_id'] ? dbValue('SELECT name FROM agents WHERE id = ?', [(int) $one['agent_id']]) : '';
        $lines[] = sprintf(ta('agents.go.scheme_block'), (int) $one['no'], $one['type'], $one['title'],
            $agent ? ' → ' . $agent : '');
    }
    $lines[] = '';
    foreach (dbAll("SELECT a.branch, f.`no` AS from_no, t.`no` AS to_no FROM elements a
                      JOIN elements f ON f.id = a.from_id JOIN elements t ON t.id = a.to_id
                     WHERE a.folder_id = ? AND a.type = 'arrow' ORDER BY a.`no`", [$folderId]) as $one) {
        $lines[] = sprintf(ta('agents.go.scheme_arrow'), (int) $one['from_no'], (int) $one['to_no'],
            $one['branch'] && $one['branch'] !== 'flow' ? ' [' . $one['branch'] . ']' : '');
    }

    $live = runActive($folderId);
    $lines[] = '';
    $lines[] = $live
        ? ta('agents.go.scheme_live', ['no' => (int) $live['no']])
        : ta('agents.go.scheme_idle', ['folder' => $folderId]);
    plain($lines);
}

/** GET op=begin&folder=N — начать прогон и сразу получить первое дело. */
function goBegin(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $folderId = inputInt('folder');
    if (!$folderId) plain([ta('agents.go.need_folder')], 422);

    $folder = folderRow($folderId, $projectId);
    if ($live = runActive($folderId)) {
        plain(array_merge(
            [ta('agents.go.run_going', ['no' => (int) $live['no']])],
            goNextLines($live)
        ), 409);
    }

    // Предполётная проверка: лучше отказать здесь, чем встать на середине.
    $check = folderPrecheck($folder);
    if (!$check['ready']) {
        $lines = [ta('agents.go.not_ready')];
        foreach ($check['problems'] as $one) {
            $lines[] = '— ' . (isset($one['no']) ? ta('agents.go.not_ready_block', ['no' => (int) $one['no']]) : '') . (string) $one['say'];
        }
        plain($lines, 409);
    }

    /* Сброс под новый прогон: статусы блоков, картинки прогона с карточек. Файлы
       прошлых прогонов остаются в своих out/rN, материалы человека (in/) не трогаются
       никогда (workDirClean). leader об этом ни слова. */
    engineLocked($projectId, static function () use ($folder, $projectId): void {
        // Два begin подряд: второй не должен сбросить папку под только что начатым прогоном.
        runLiveGuard((int) $folder['id'], 0, 'agents.go.run_going');
        runPrepareDo($folder, $projectId, bumpRev($projectId), false);
    });

    // Агент в вебе: папка прогона — у него (&here= — его текущая папка), диск сервера не трогаем.
    $agentDir = agentsRemote() ? agentRunDir($folder, (string) (input('here') ?? '')) : '';
    $runId = goStartRun($projectId, $folderId, $agentDir);
    if ($agentDir === '') workDirClean((string) $folder['work_dir'], $folderId);   // диск — вне замка и только победителю begin
    runOutDir(runRow($runId, $projectId));      // своя папка результатов out/rN — до первого задания
    goWorkerFile(runRow($runId, $projectId), $folder, true);   // инструкция worker — одна на прогон
    goMove($runId);
    $run = goLogRun(runRow($runId, $projectId));

    // Первое дело не выдалось — так и говорим, а не молча «начат».
    $issued = (int) dbValue("SELECT COUNT(*) FROM run_steps WHERE run_id = ?", [$runId]);
    $stuck = !$issued && $run['state'] === 'running'
        ? [ta('agents.go.first_not_issued') . (trim((string) $run['wait_for']) ?: ta('agents.go.no_reason'))]
        : [];
    // Агент в вебе: папку прогона заводит он сам — сервер называет её и команду.
    $made = $agentDir !== '' ? [ta('agents.go.agent_dir_make', ['dir' => $agentDir, 'no' => (int) $run['no']])] : [];
    plain(array_merge([
        ta('agents.go.run_started', ['no' => (int) $run['no'], 'name' => $folder['name']]),
        ...$made,
        ...$stuck,
        ta('agents.go.call_it', ['no' => (int) $run['no']]),
        ...goWarnings($check['warnings']),
    ], goAbout($folderId), goNextLines($run)));
}

/**
 * Мягкая сверка принятого ответа — строки «⚠» ведущему и в ленту, не отказ:
 * вид по образцу answer (та же грамматика, что у проверок, answerFits) и
 * «вызовов=K» сверх paid_calls. Честное слово остаётся: принято всё.
 */
function goAnswerWarnings(array $element, string $result, ?array $step = null): array
{
    $id = (int) $element['id'];
    $out = [];
    $answer = trim((string) runProp($id, 'answer', ''));
    if ($answer !== '' && answerFits($result, $answer) === false) $out[] = ta('agents.go.warn_form', ['answer' => $answer]);

    $paid = (int) runProp($id, 'paid_calls', 0);
    $calls = goCalls($result);
    if ($paid && $calls !== null && $calls > $paid) $out[] = ta('agents.go.warn_paid', ['calls' => $calls, 'paid' => $paid]);

    /* Арифметика блока (свойство expr) — та же сверка, что у формального судьи
       (lib/engine/judges/formal.php), только мягко: ответ уже принят, ⚠ для leader. */
    $expr = trim((string) runProp($id, 'expr', ''));
    if ($expr !== '' && $step) {
        $math = exprCheck($expr, varsIn((int) $step['id'])['vars'], varsParse($result));
        // Ожидаемых чисел не называем: ответ по подсказке — не счёт (как у судьи).
        if ($math['status'] === 'fail') $out[] = ta('agents.go.warn_math', ['got' => $math['got']]);
    }
    return $out;
}

/** Замечания к схеме (folderWarnings) строками для ведущего: прогон идёт, но знать стоит. */
function goWarnings(array $warnings): array
{
    return array_map(static fn(array $w) => ta('agents.go.warn_scheme', ['no' => $w['no'], 'title' => $w['title']]) . $w['say'], $warnings);
}

/**
 * Описание прогона — ТЗ стартера — и каркас всей схемы (goSchemeMap). leader
 * видит их один раз, при begin: это второе исключение из каркаса текущего дела
 * (первое — ромб, который Jev не решил).
 */
function goAbout(int $folderId): array
{
    $lines = [];
    $starter = folderStarters($folderId)[0] ?? null;
    if ($starter) {
        $spec = specText('element_id', (int) $starter['id']);
        $text = trim(is_array($spec) ? (string) ($spec['text'] ?? '') : (string) $spec);
        $lines[] = ta('agents.go.starter_passed', ['no' => (int) $starter['no'], 'title' => $starter['title']]);
        if ($text !== '') {
            $lines[] = ta('agents.go.about_head');
            foreach (preg_split('/\R/u', $text) as $one) $lines[] = $one;
            $lines[] = '╰───────────────────────────────────────────';
        }
    }
    /* Каркас всей схемы — один раз, для понимания прогона в целом: узлы и
       переходы без ТЗ. Дальше leader видит только своё текущее дело. */
    $lines[] = ta('agents.go.map_head');
    $lines[] = ta('agents.go.map_note1');
    $lines[] = ta('agents.go.map_note2');
    foreach (goSchemeMap($folderId) as $one) $lines[] = $one;
    $lines[] = '╰───────────────────────────────────────────';
    return $lines;
}

/**
 * Завести прогон без пропусков и настроек.
 *
 * Вариант всегда один: ведёт человек-агент, проверок нет, Jev выключен.
 * Простому пути незачем знать про драйверы и судей. $agentDir — папка прогона у агента (веб), пусто — агент рядом.
 */
function goStartRun(int $projectId, int $folderId, string $agentDir = ''): int
{
    return engineLocked($projectId, static function () use ($projectId, $folderId, $agentDir): int {
        $folder = folderRow($folderId, $projectId);
        runLiveGuard($folderId, 0, 'agents.go.one_run');   // один живой прогон в папке — правило движка

        $entries = runEntries($folderId);
        if (count($entries) !== 1) {
            throw new ApiError(ta('agents.go.one_entry', ['n' => count($entries)]), 'conflict');
        }

        $start = $entries[0];
        $no = nextRunNo($projectId);
        $rev = bumpRev($projectId);

        /* Жетоны при старте не раскладываем: первый блок открывает сам ход
           движка — у входа схемы нет входящих стрелок, и он готов сразу. */
        dbRun(
            'INSERT INTO runs (project_id, folder_id, `no`, title, work_dir, work_url, agent_dir, rev,
                               engine, driver, judge, jev, show_pause)
             VALUES (?,?,?,?,?,?,?,?,2,?,?,?,0)',
            [$projectId, $folderId, $no, (string) $folder['name'],
             // Агент в вебе: диск сервера — только служебное (storage/runs/<id>), работа — в agent_dir.
             $agentDir === '' ? (string) $folder['work_dir'] : '', (string) $folder['work_url'], $agentDir, $rev,
             'lead', 'human', 'judge']   // ромбы решает Jev: lib/engine/advance.php
        );
        $runId = dbId();
        runTouch($runId);

        runStartEvent(runRow($runId, $projectId), $folder, $start, 'lead');
        return $runId;
    });
}

/** Причина &why= у done и fail — не длиннее стольких знаков: у done она ложится в note шага, varchar(500). */
const GO_WHY_CHARS = 480;

/** Приставка из Орки перед ответом worker: «ОТВЕТ worker: 137.3: …», «WORKER ANSWER: …», «Answer: …». */
const GO_ANSWER_PREFIX = '/^(?:ОТВЕТ\s+(?:worker|ВОРКЕРА)|(?:worker\s+)?ANSWER(?:\s+worker)?)\s*:\s*/iu';

/**
 * op=done&block=N[&result=…][&branch=yes|no] — дело сделано. GET или POST-форма:
 * длинный и многострочный ответ идёт телом (curl --data-urlencode result@-), в адрес он не влезает.
 *
 * Проверок нет: сказал leader — значит сделано. Ромбу нужна ветка,
 * обычному блоку — ответ worker строкой.
 */
function goDone(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    $result = trim((string) (input('result') ?? ''));
    $block = inputInt('block') ?: 0;
    // «137.3: a=13 b=3» — ответ на задание 137.3: адрес отрезаем и сверяем с кругом.
    $open = array_map(static fn(array $s) => $s['element_no'] . '.' . $s['attempt'], dbAll(
        "SELECT element_no, attempt FROM run_steps WHERE run_id = ? AND state IN ('issued','running','submitted')", [$runId]));
    /* Блок назван явно — дробь с двоеточием, какой в прогоне не было, это текст ответа, а не
       адрес: «3.5: половина» при block=3. Были — выданные, принятые и стёртые круги: номер
       меньше следующего (stepNextAttempt). Старый ответ на прошлый круг так и остаётся адресом. */
    $known = $block ? static fn(int $no, int $try): bool => $try < stepNextAttempt($runId, $no) : null;
    // Приставка из Орки «ОТВЕТ worker: 137.3: …» — не замечание worker: снимаем, если за ней адрес.
    $bare = (string) preg_replace(GO_ANSWER_PREFIX, '', $result);
    $address = goAnswerAddress($bare, $open, $known);
    $sent = $address ? $bare : $result;   // как прислал leader — с адресом: так и показываем в подтверждении
    if ($address) $result = $address['rest'];
    // Замечание worker перед адресом — в журнал отдельной строкой, в ответ не идёт.
    $note = $address['note'] ?? '';
    if ($note !== '') $sent = "{$address['no']}.{$address['try']}: $result";

    $no = $block ?: ($address['no'] ?? 0);
    if (!$no) plain([ta('agents.go.need_block_done')], 422);
    if ($address && $address['no'] !== $no) {
        plain([ta('agents.go.wrong_block', ['no' => $address['no'], 'try' => $address['try'], 'block' => $no])], 409);
    }
    // Ответ хранится целиком: не помещается в шаг (предел движка) — говорим сразу, до записи.
    if (strlen($result) > STEP_RESULT_BYTES) {
        plain([ta('agents.go.too_long', ['bytes' => strlen($result), 'max' => STEP_RESULT_BYTES, 'run' => (int) $run['no']])], 422);
    }

    $branch = goBranch();
    $why = goWhy();
    $said = '';

    engineLocked($projectId, static function () use ($runId, $projectId, $no, $result, $sent, $note, $branch, $why, $address, &$said): array {
        $run = runRow($runId, $projectId);
        /* Прогон закрыт: ответ на последний done мог потеряться в сети, и ведущий шлёт его снова.
           Точный повтор принятой сдачи — «повтор» и итог прогона, ничего не меняется;
           новое действие по закрытому прогону — отказ. */
        if ($run['state'] !== 'running') {
            $said = goRepeat($runId, $no, $address, $result)
                ?? throw new ApiError(ta('agents.go.not_running', ['state' => $run['state']]), 'conflict');
            return [];
        }

        $element = dbRow('SELECT * FROM elements WHERE project_id = ? AND folder_id = ? AND `no` = ?',
            [$projectId, (int) $run['folder_id'], $no]);
        if (!$element) throw new ApiError(ta('agents.go.no_block', ['no' => $no]), 'not_found');

        // Ромб: своя запись шага, ветку называет leader.
        if ($element['type'] === 'decision') {
            if ($branch === '') {
                throw new ApiError(ta('agents.go.need_branch', ['no' => $no]), 'invalid');
            }
            $arrow = dbRow("SELECT * FROM elements WHERE type = 'arrow' AND from_id = ? AND branch = ?",
                [(int) $element['id'], $branch]);
            if (!$arrow) throw new ApiError(ta('agents.go.no_exit', ['no' => $no, 'branch' => $branch]), 'conflict');

            try {
                $enter = marksEnter($run, $element, null);
            } catch (ApiError $e) {
                // Ромб уже решён, нового входа нет: та же ветка — повтор, другая — отказ.
                $said = goDecidedAgain($runId, $element, $branch) ?? throw $e;
                return [];
            }
            stepDecideDo($run, $element, $arrow, $branch, $why, $enter, 'lead');
            $said = ta('agents.go.decided', ['no' => $no, 'branch' => ta($branch === 'yes' ? 'agents.go.yes' : 'agents.go.no')]);
            return [];
        }

        // Шлюз: шага у него нет, переход отмечает leader.
        if ($element['type'] === 'gateway') {
            $made = stepPassDo($run, $element);
            $target = (int) $element['target_folder_id'];
            $name = (string) dbValue('SELECT name FROM folders WHERE id = ?', [$target]);
            $said = ta('agents.go.gateway_done', ['no' => $no, 'target' => $target, 'name' => $name, 'try' => $made['attempt']]);
            return [];
        }

        // Обычный блок: находим открытую попытку, пишем ответ и принимаем.
        $step = dbRow(
            "SELECT * FROM run_steps WHERE run_id = ? AND element_no = ?
              AND state IN ('issued','running','submitted') ORDER BY id DESC LIMIT 1",
            [$runId, $no]
        );

        /* Ответ с адресом — сверяем круг. Старый или повторный ответ на прошлый
           круг иначе ушёл бы принятым за новый круг — без работы worker, и счёт
           цикла тихо сбился бы. */
        if ($address && ($step ? (int) $step['attempt'] : 0) !== $address['try']) {
            if (($said = goRepeat($runId, $no, $address, $result)) !== null) return [];
            $open = $step ? ta('agents.go.open_task', ['task' => "$no.{$step['attempt']}"]) : ta('agents.go.block_not_open', ['no' => $no]);
            throw new ApiError(ta('agents.go.other_round', ['task' => "$no.{$address['try']}", 'open' => $open]), 'conflict');
        }

        /* Повтор той же команды: curl переотправил done, а ход уже выдал этот
           блок на новый круг — новую выдачу ещё не брали в работу (task не звали).
           Ответ с верным адресом — точно этот круг, угадывать повтор по тексту не нужно. */
        if (!$address && (!$step || $step['state'] === 'issued') && ($said = goRepeat($runId, $no, null, $result)) !== null) {
            return [];
        }
        if (!$step) throw new ApiError(ta('agents.go.not_open_where', ['no' => $no]), 'not_found');

        if ($result !== '') {
            dbRun('UPDATE run_steps SET result = ?, submitted_at = NOW(3), rev = ? WHERE id = ?',
                [$result, bumpRev($projectId), (int) $step['id']]);
            $step = stepRow((int) $step['id'], $projectId);
        }
        /* Ответ worker — отдельной строкой ленты, как «сдан» у старого круга.
           Без неё в журнале было только «принят», а что ответил worker — не видно. */
        eventStep($step, 'submit',
            ta('agents.go.ev_answer', ['task' => "{$step['element_no']}.{$step['attempt']}"])
                . ($result !== '' ? mb_substr($result, 0, 110) : ta('agents.go.ev_no_text')),
            ['result' => $result],
            ['actor' => goStepSelf($step) ? 'lead' : 'worker']);
        if ($note !== '') {
            eventStep($step, 'message', ta('agents.go.ev_note', ['task' => "{$step['element_no']}.{$step['attempt']}"])
                . mb_substr($note, 0, 160), ['note' => $note], ['actor' => 'worker']);
        }
        stepAcceptDo($run, $step, $why !== '' ? $why : ta('agents.go.accepted_by_lead'), 'lead');
        $shown = goCatchFiles($run, $element, $step, $result);
        // В журнал — где файлы лежат на самом деле (out/rN/…).
        if ($shown) {
            eventStep($step, 'file', ta('agents.go.ev_file', ['task' => "{$step['element_no']}.{$step['attempt']}"])
                . mb_substr(implode(', ', $shown), 0, 200), ['files' => $shown]);
        }
        $warn = goAnswerWarnings($element, $result, $step);
        if ($warn) eventStep($step, 'warn', '⚠ ' . implode(' · ', $warn), null, ['actor' => 'script']);
        $said = ta('agents.go.accepted', ['no' => $no, 'task' => "$no.{$step['attempt']}"])
            . ($sent !== '' ? ta('agents.go.accepted_answer', ['answer' => $sent]) : '')
            . ($shown ? ta('agents.go.accepted_card') . implode(', ', $shown) : '')
            . ($note !== '' ? ta('agents.go.accepted_note', ['note' => $note]) : '')
            . implode('', array_map(static fn(string $w) => "\n   ⚠ $w", $warn));
        return [];
    });

    $decided = goMoveLines($runId);
    $next = goNextLines(runRow($runId, $projectId));
    plain(array_merge([$said], $decided, $next));
}

/**
 * Повтор уже принятой сдачи — строка «повтор», иначе null. С адресом: тот же круг N.K
 * принят с тем же текстом. Без адреса: блок принят с тем же текстом минуту назад.
 * Ничего не пишет — прежний исход только называется.
 */
function goRepeat(int $runId, int $no, ?array $address, string $result): ?string
{
    if ($address) {
        $was = dbRow("SELECT state, result FROM run_steps WHERE run_id = ? AND element_no = ? AND attempt = ?
                       ORDER BY id DESC LIMIT 1", [$runId, $no, $address['try']]);
        return $was && $was['state'] === 'accepted' && trim((string) $was['result']) === $result
            ? ta('agents.go.repeat_task', ['task' => "$no.{$address['try']}"]) : null;
    }
    if ($result === '') return null;
    $prev = dbValue("SELECT 1 FROM run_steps WHERE run_id = ? AND element_no = ? AND state = 'accepted'
                       AND result = ? AND finished_at > NOW(3) - INTERVAL 60 SECOND LIMIT 1", [$runId, $no, $result]);
    return $prev ? ta('agents.go.repeat_block', ['no' => $no]) : null;
}

/** Ветка ромба из &branch=: yes/no или да/нет, без учёта регистра; другое — пусто. */
function goBranch(): string
{
    $said = mb_strtolower(trim((string) (input('branch') ?? '')));
    return ['yes' => 'yes', 'no' => 'no', 'да' => 'yes', 'нет' => 'no'][$said] ?? '';
}

/** Причина &why= у done и fail — не длиннее GO_WHY_CHARS знаков, режется по знакам, не по байтам. */
function goWhy(): string
{
    return trim(mb_substr(trim((string) (input('why') ?? '')), 0, GO_WHY_CHARS));
}

/**
 * Ветка по уже решённому ромбу, а нового входа у него нет: curl переотправил, ответ
 * потерялся. Та же ветка — строка «повтор» с прежним решением, ничего не меняется;
 * другая — отказ 409 с ним же. Ромб ещё не решали — null: отвечает движок.
 */
function goDecidedAgain(int $runId, array $element, string $branch): ?string
{
    $was = (string) dbValue(
        "SELECT a.branch FROM run_steps s JOIN elements a ON a.id = s.chosen_edge_id
          WHERE s.run_id = ? AND s.element_id = ? AND s.state = 'accepted' ORDER BY s.id DESC LIMIT 1",
        [$runId, (int) $element['id']]);
    if (!in_array($was, ['yes', 'no'], true)) return null;
    $vars = ['no' => (int) $element['no'], 'branch' => ta($was === 'yes' ? 'agents.go.yes' : 'agents.go.no')];
    if ($was !== $branch) throw new ApiError(ta('agents.go.decided_other', $vars), 'conflict');
    return ta('agents.go.repeat_decision', $vars);
}

/**
 * Что делать сейчас — каркас: по строке на дело.
 *
 * Сердце простого пути: после любой команды leader видит, какой блок, кому и
 * какую команду звать. ТЗ, вход и ответы прошлых блоков leader не нужны —
 * их видит только worker (op=task пишет файл). Ничего, что ждёт leader, не
 * прячется: ромб, шлюз, застрявший блок. Одно исключение — ромб, который Jev
 * не решил: без условия и ответа worker leader ветку не выберет.
 */
function goNextLines(array $run): array
{
    $projectId = (int) $run['project_id'];
    $runId = (int) $run['id'];
    $run = runRow($runId, $projectId);

    if ($run['state'] === 'done')    return [ta('agents.go.passed') . goTally($runId)];
    if ($run['state'] !== 'running') return [ta('agents.go.run_state', ['state' => $run['state']]) . $run['summary']];

    $lines = [];
    $shown = [];
    $say = static function (array $element, string $what) use (&$lines): void {
        $lines[] = ta('agents.go.say', ['what' => $what, 'no' => (int) $element['no'], 'title' => $element['title']]);
    };

    // 1. Выданные шаги: задание — через op=task.
    $open = dbAll(
        "SELECT * FROM run_steps WHERE run_id = ? AND state IN ('issued','running','submitted') ORDER BY id",
        [$runId]
    );
    foreach ($open as $step) {
        $element = dbRow('SELECT * FROM elements WHERE id = ?', [(int) $step['element_id']]);
        if (!$element) continue;
        $shown[(int) $element['id']] = true;
        /* Имя worker не пишем: работает тот worker, которого позвал leader, —
           имя из схемы («GROK») при worker-Claude только сбивало. Пишем одно:
           делает ли блок ведущий сам (goSelf). */
        $self = goSelf($run, $step);
        $lines[] = ta('agents.go.next', ['no' => (int) $element['no'], 'title' => $element['title'],
                'task' => goAddress($step), 'state' => ta('agents.go.state.' . $step['state'])])
            . ($self ? ta('agents.go.next_self') : '');
        /* Задание уже у исполнителя — второй op=task отдал бы его ещё раз. Пишем,
           чего ждём: ответа worker или своей сдачи. */
        $lines[] = match (true) {
            $step['state'] === 'issued' => ta('agents.go.next_take', ['no' => (int) $element['no']]),
            $self                       => ta('agents.go.next_submit'),
            default                     => ta('agents.go.next_wait'),
        };
        // Номер круга вырос из-за стёртых попыток — говорим прямо, иначе ведущий ищет сбой.
        $gone = dbAll(
            "SELECT DISTINCT attempt, actor FROM run_events
              WHERE run_id = ? AND element_no = ? AND kind = 'reset' AND attempt < ? ORDER BY attempt",
            [$runId, (int) $step['element_no'], (int) $step['attempt']]);
        foreach ($gone as $one) {
            $lines[] = ta('agents.go.erased', ['task' => (int) $step['element_no'] . '.' . (int) $one['attempt'],
                'who' => $one['actor'] === 'human' ? ta('agents.go.erased_human') : '', 'now' => goAddress($step)]);
        }
    }

    // 2. Готовое, чего движок сам не делает: ромб без решения Jev, шлюз, застрявший блок.
    $ctx = advanceLoad($run);
    foreach (readyList($run, $ctx['graph'], $ctx['steps']) as $one) {
        $element = dbRow('SELECT * FROM elements WHERE id = ?', [(int) ($one['id'] ?? 0)]);
        if (!$element || isset($shown[(int) $element['id']])) continue;
        $no = (int) $element['no'];
        if ($element['type'] === 'block' && ($why = goStuck($runId, $element)) !== null) {
            $say($element, ta('agents.go.stuck'));
            $lines[] = ta('agents.go.stuck_hint', ['why' => $why, 'no' => $no]);
        } elseif ($element['type'] === 'decision') {
            $say($element, ta('agents.go.diamond'));
            $lines[] = ta('agents.go.diamond_hint', ['no' => $no]);
            // Исключение из каркаса: без условия и ответа worker ветку не выбрать.
            $cond = trim((string) runProp((int) $element['id'], 'cond', ''));
            if ($cond !== '') $lines[] = ta('agents.go.diamond_cond') . $cond;
            foreach (goInputs($run, $element, null) as $one) {
                $lines[] = ta('agents.go.diamond_answer', ['no' => $one['no']]) . $one['result'];
            }
            $jev = dbValue(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.reason')) FROM run_events
                  WHERE run_id = ? AND element_no = ? AND kind = 'jev-say' ORDER BY id DESC LIMIT 1",
                [$runId, $no]);
            if ($jev) $lines[] = ta('agents.go.diamond_jev') . $jev;
        } elseif ($element['type'] === 'gateway') {
            $say($element, ta('agents.go.gateway'));
            $lines[] = ta('agents.go.gateway_hint', ['no' => $no]);
        } else {
            $say($element, ta('agents.go.ready_not_issued'));
            $lines[] = ta('agents.go.see_where');
        }
    }

    if (!$lines) {
        $wait = trim((string) $run['wait_for']);
        $lines[] = ta('agents.go.idle', ['wait' => $wait !== '' ? ': ' . $wait : '', 'no' => (int) $run['no']]);
    }
    return $lines;
}

/**
 * Забрать файлы, которые worker назвал в отчёте, и повесить их на блок.
 *
 * Утилиты в круге больше нет, а картинка на карточке — смысл Гоблина:
 * человек смотрит на схему и видит саму работу, а не строку текста.
 * Поэтому пути ищет сервер — прямо в отчёте, по рабочей папке прогона.
 * Первая картинка становится заглавной, остальные — просто вложениями.
 */
function goCatchFiles(array $run, array $element, array $step, string $result): array
{
    // Агент в вебе: файлы у него на компьютере — сервер верит его слову.
    if ((string) ($run['agent_dir'] ?? '') !== '') return goCatchAgentFiles($run, $element, $step, $result);

    $work = realpath((string) $run['work_dir']) ?: rtrim((string) $run['work_dir'], '/');
    // Слова отчёта, похожие на путь с расширением: «out/face.png», «final.jpg»; и строки &files=.
    preg_match_all('~[\w./\-]+\.[A-Za-z0-9]{2,5}~u', $result, $found);
    $pieces = array_merge($found[0] ?? [], array_keys(goFilesSaid()));
    if ($work === '' || !$pieces) return [];

    $outputs = (array) runProp((int) $element['id'], 'outputs', []);
    /* Две последние пометки разрешают сменить обложку живой схемы: это
       сделал принятый шаг этого же прогона, а не человек мимо прогона. */
    $ctx = ['projectId' => (int) $run['project_id'], 'rev' => bumpRev((int) $run['project_id']),
            'refs' => [], 'folders' => [], 'warnings' => [],
            'activeGuardRuntimeStep' => (int) $step['id'],
            'activeGuardRuntimeRun'  => (int) $run['id']];
    $taken = [];
    $first = true;
    /* Время выдачи — числом из базы: строку opened_at база пишет в своём поясе,
       а strtotime() читал её в поясе PHP, и шаг «выдавался» на часы позже.
       Секунда запаса: часы файловой системы и базы идут не минута в минуту. */
    $since = (int) dbValue('SELECT UNIX_TIMESTAMP(opened_at) FROM run_steps WHERE id = ?', [(int) $step['id']]);
    $since = $since ? $since - 1 : 0;

    /* «out/cutout.png» в ответе — это out/rN/cutout.png: так велит задание.
       Ищем по порядку: путь как назван, в папке прогона, голое имя — там же. */
    $outRun = runOutDir($run, false);
    foreach ($pieces as $piece) {
        $tries = [str_starts_with($piece, $work . '/') ? $piece : $work . '/' . ltrim($piece, '/')];
        $piece = ltrim($piece, '/');
        if ($outRun !== '') {
            if (str_starts_with($piece, 'out/')) array_unshift($tries, $outRun . '/' . substr($piece, 4));
            elseif (!str_contains($piece, '/')) $tries[] = $outRun . '/' . $piece;
        }
        $path = false;
        foreach ($tries as $try) { if (($real = realpath($try)) && is_file($real)) { $path = $real; break; } }
        if (!$path || !str_starts_with($path, $work . '/')) continue;
        if (isset($taken[$path])) continue;
        /* Результат — только то, что шаг сделал: файл создан или изменён после
           выдачи. Упомянутый исходник («взял test.png») результатом не станет —
           иначе чистка перед следующим прогоном удалила бы его как чужую работу. */
        if (filemtime($path) < $since) continue;
        /* Тот же файл в том же виде уже сдал другой блок этого прогона — автор у него есть.
           Иначе секунда запаса выше приписала бы его и блоку, выданному в ту же секунду. */
        if (dbValue("SELECT 1 FROM asset_links l JOIN assets a ON a.id = l.asset_id JOIN run_steps s ON s.id = l.step_id
                      WHERE l.made_by_run = ? AND l.role = 'result' AND s.element_id <> ? AND a.uri = ? AND a.sha256 = ?
                      LIMIT 1", [(int) $run['id'], (int) $element['id'], $path, hash_file('sha256', $path)])) continue;
        $taken[$path] = true;

        $assetId = assetFromFile((int) $run['project_id'], $path, $ctx['rev']);
        $output  = (string) (array_shift($outputs) ?? '');

        // К шагу — как сдача (её видно в ленте), к блоку — как картинка.
        assetLink(['asset' => $assetId, 'step' => (int) $step['id'], 'role' => 'result',
                   'output' => $output, 'madeByRun' => (int) $run['id']], $ctx);

        // Заглавной становится первая картинка: она и видна на холсте.
        if ($first && fileKindOf($path) === 'image') {
            assetLink(['asset' => $assetId, 'element' => (int) $element['id'], 'role' => 'cover',
                       'output' => $output, 'madeByRun' => (int) $run['id']], $ctx);
            $first = false;
        }
    }
    /* Настоящий путь от рабочей папки: «out/r30/video.mp4», а не «video.mp4».
       Ответ worker пишет out/…, и без этого человек не видел, где файл лежит. */
    return array_map(static fn(string $path) => substr($path, strlen($work) + 1), array_keys($taken));
}

/**
 * Необязательный &files= у done: файлы результата строками «путь размер» (размер в байтах, wc -c).
 * Нужен агенту в вебе — его файлы сервер не видит и верит этим строкам; локально сервер смотрит файл сам.
 */
function goFilesSaid(): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) (input('files') ?? '')) as $line) {
        if (!preg_match('/^\s*(.+?)(?:\s+(\d+))?\s*$/u', $line, $m)) continue;
        $path = trim($m[1], " \t'\"");
        if ($path !== '') $out[$path] = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
        if (count($out) >= 50) break;
    }
    return $out;
}

/**
 * Файлы результата агента в вебе: сервер их не видит и верит слову агента (решение хозяина 30.09.2026).
 * Берём пути из &files= (с размером) и пути out/… из ответа. Путь — от рабочей папки, ровно как сказал
 * агент (так велит инструкция): «test.txt» — это корень папки, а не out/rN. Только «out/x» без номера
 * прогона — это out/rN/x (так велит задание). Исходник in/…, чужой прогон out/rK и выход из папки работой
 * шага не станут. Тип — по расширению.
 */
function goCatchAgentFiles(array $run, array $element, array $step, string $result): array
{
    $said = goFilesSaid();
    $agent = rtrim((string) $run['agent_dir'], '/') . '/';
    // Полный путь в ответе («/Users/…/схема-12/out/r5/a.png») — тот же out/…: папку агента снимаем.
    preg_match_all('~(?<![\w/])(?:\./)?out/[\w./\-]+\.[A-Za-z0-9]{2,5}~u', str_replace($agent, '', $result), $found);
    $out = 'out/r' . (int) $run['no'] . '/';

    $sizes = [];
    foreach (array_merge(array_keys($said), $found[0] ?? []) as $piece) {
        $bytes = $said[$piece] ?? null;
        if (str_starts_with($piece, $agent)) $piece = substr($piece, strlen($agent));
        if (str_starts_with($piece, './')) $piece = substr($piece, 2);
        // «out/cutout.png» — это out/rN/cutout.png (так велит задание); остальное — как сказано.
        if (str_starts_with($piece, 'out/') && !preg_match('~^out/r\d+/~', $piece)) $piece = $out . substr($piece, 4);
        if ($piece === '' || $piece[0] === '/' || str_contains($piece, '..') || str_starts_with($piece, 'in/')
            || (str_starts_with($piece, 'out/') && !str_starts_with($piece, $out))) continue;
        $sizes[$piece] = $bytes ?? ($sizes[$piece] ?? null);
    }
    if (!$sizes) return [];

    $ctx = ['projectId' => (int) $run['project_id'], 'rev' => bumpRev((int) $run['project_id']),
            'refs' => [], 'folders' => [], 'warnings' => [],
            'activeGuardRuntimeStep' => (int) $step['id'],
            'activeGuardRuntimeRun'  => (int) $run['id']];
    $outputs = (array) runProp((int) $element['id'], 'outputs', []);
    $first = true;
    foreach ($sizes as $rel => $bytes) {
        $assetId = assetAtAgent((int) $run['project_id'], $rel, $bytes, $ctx['rev']);
        $output  = (string) (array_shift($outputs) ?? '');
        assetLink(['asset' => $assetId, 'step' => (int) $step['id'], 'role' => 'result',
                   'output' => $output, 'madeByRun' => (int) $run['id']], $ctx);
        // Заглавной — первая картинка, как у файлов на диске сервера.
        if ($first && fileKindOf($rel) === 'image') {
            assetLink(['asset' => $assetId, 'element' => (int) $element['id'], 'role' => 'cover',
                       'output' => $output, 'madeByRun' => (int) $run['id']], $ctx);
            $first = false;
        }
    }
    return array_keys($sizes);
}

/**
 * GET op=task&block=N[&who=имя] — только текст задания, без обвязки. who — кому ведущий
 * отдал блок (субагент), для ленты времени; в ответ ничего не добавляет.
 *
 * Чтобы leader мог переслать его одной командой, ничего не вырезая глазами:
 * весь ответ целиком и есть то, что уходит worker.
 */
function goTask(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    $no = inputInt('block');
    if (!$no) {
        /* Номер блока не назвали — отдаём задание того, что открыто сейчас.
           В прогонах это самый частый случай: leader берёт текущее дело. */
        $open = dbAll(
            "SELECT element_no, state FROM run_steps WHERE run_id = ?
              AND state IN ('issued','running','submitted') ORDER BY id",
            [$runId]
        );
        if (!$open) plain([ta('agents.go.none_open')], 404);
        // Открыто несколько — не угадываем: молча отданный «последний» терял бы второй.
        if (count($open) > 1) {
            $list = array_map(static fn(array $s) => $s['element_no'] . ' (' . ta('agents.go.state.' . $s['state']) . ')', $open);
            plain([ta('agents.go.many_open', ['list' => implode(', ', $list)])], 409);
        }
        $no = (int) $open[0]['element_no'];
    }

    $element = dbRow('SELECT * FROM elements WHERE project_id = ? AND folder_id = ? AND `no` = ?',
        [$projectId, (int) $run['folder_id'], $no]);
    if (!$element) plain([ta('agents.go.no_block_lc', ['no' => $no])], 404);

    // Числа входа берём у открытой попытки, а если её нет — по жетонам.
    $step = dbRow(
        "SELECT * FROM run_steps WHERE run_id = ? AND element_no = ?
          AND state IN ('issued','running','submitted') ORDER BY id DESC LIMIT 1",
        [$runId, $no]
    );
    // Задание есть только у выданного блока; текст невыданного (&text=1) — для человека и проверок.
    if (!$step && !input('text')) plain([ta('agents.go.not_issued', ['no' => $no])], 409);
    $vars = $step ? (varsIn((int) $step['id'])['vars'] ?? []) : goEnterVars($run, $element);

    /* Взял задание — значит сейчас отдашь worker: зажигаем блок сами.
       Подсветка «в работе» — смысл Гоблина, её нельзя оставлять на память
       leader: отдельную команду op=work он рано или поздно забудет. */
    // &who= — кому ведущий отдал блок (субагент): записываем у шага и у уже взятого.
    $who = trim((string) (input('who') ?? ''));
    if ($step && ($step['state'] === 'issued' || $who !== '')) goStepRunning($projectId, $runId, $step, $who);
    if ($step && $step['state'] === 'issued') goKeepTries($run, $step);

    /* Прогон leader чисел из ответов не разбирает: «кадр 2832 × 4240» и «a
       равно двум» worker и Jev поймут сами, а разбор ошибался молча. Вход —
       только дословные ответы прошлых блоков. */
    if (advanceLeadRun($run)) $vars = [];
    $text = goTaskText($element, $vars, goInputs($run, $element, $step), runWorkShown($run),
        $step ? goAddress($step) : '', runOutShown($run), goSpent($runId), $run);
    // Общая картина схемы (галочка в свойствах папки) — строкой со ссылкой, сразу под шапкой.
    $scheme = $step ? goSchemeFile($run) : null;
    if ($scheme !== null) {
        array_splice($text, 1, 0, [ta('agents.go.scheme_file') . goTextUrl($project, $run, 'scheme')]);
    }
    // Круг цикла — первой строкой под шапкой: worker знает, почему делает снова.
    $round = $step ? goRoundLine($step) : null;
    if ($round !== null) array_splice($text, 1, 0, [$round]);

    /* Текст задания видит только worker: сервер пишет его в файл служебной папки,
       а leader отдаёт строку «задание по ссылке …» — worker читает её curl-ом (op=text),
       хоть с другого компьютера. Сам текст — &text=1, для человека и проверок.
       Блок делает сам ведущий (lead_self: solo или нет worker) — пересылать некому:
       текст сразу, без второго запроса по ссылке. (&file=1 из прежних инструкций ничего не меняет.) */
    if (input('text') || ($step && goStepSelf($step))) {
        if ($step) goTaskLog($step, $text, ta('agents.go.as_text'));
        plain($text);
    }
    $file = goTaskFile($run, $step, $text);
    goTaskLog($step, $text, basename($file));
    plain([ta('agents.go.task_link') . goTextUrl($project, $run, goAddress($step))]);
}

/** Ссылка на текст для worker: задание «137.3», общая картина «scheme» или инструкция «worker». */
function goTextUrl(array $project, array $run, string $what): string
{
    return runBaseUrl() . '?' . http_build_query([
        'project' => $project['url_key'], 'op' => 'text', 'run' => 'r' . (int) $run['no'], 'task' => $what,
    ]);
}

/**
 * GET op=text&run=rN&task=137.3 — задание worker тем же текстом, что записал task.
 * task=scheme — общая картина схемы, task=worker — инструкция worker. Ничего не меняет.
 * Файлы лежат в служебной папке прогона (goTasksDir); папку здесь не заводим — чтение.
 */
function goText(): void
{
    $project = requireProject(false);
    $run = goRun((int) $project['id']);
    $task = trim((string) (input('task') ?? ''));
    $name = match (true) {
        $task === 'scheme' => 'схема.txt',
        $task === 'worker' => 'worker.md',
        (bool) preg_match('/^\d+\.\d+$/', $task) => $task . '.txt',
        default => '',
    };
    $file = runServiceDir((int) $run['id']) . '/tasks/r' . (int) $run['no'] . '/' . $name;
    if ($name === '' || !is_file($file)) plain([ta('agents.go.no_text', ['task' => $task])], 404);
    plain(explode("\n", rtrim((string) file_get_contents($file), "\n")));
}

/** Числа входа невыданного блока — по лежащим жетонам; открыть нечем — пусто, как у goInputs. */
function goEnterVars(array $run, array $element): array
{
    try {
        return varsFromMarks(marksEnter($run, $element, null)['marks'])['vars'] ?? [];
    } catch (ApiError $e) {
        return [];
    }
}

/**
 * В ленту прогона — ровно то, что получил worker: событие «задание worker»,
 * раскрыл — видно текст слово в слово. Тот же текст второй раз не пишем:
 * повторный task ничего нового worker не дал.
 */
function goTaskLog(array $step, array $text, string $how): void
{
    $body = implode("\n", $text) . "\n";
    $last = dbValue("SELECT body FROM run_events WHERE step_id = ? AND kind = 'package' ORDER BY id DESC LIMIT 1",
        [(int) $step['id']]);
    if ($last === $body) return;
    eventStep($step, 'package', ta(goStepSelf($step) ? 'agents.go.ev_package_self' : 'agents.go.ev_package') . $how . ' · ' . kbOf($body),
        $body, ['actor' => 'script']);
}

/** Адрес задания: блок и круг (номер попытки) — «137.3». */
function goAddress(array $step): string
{
    return (int) $step['element_no'] . '.' . (int) $step['attempt'];
}

/**
 * Адрес ответа worker: «137.3: a=13 b=3» → no 137, try 3, rest «a=13 b=3», note ''.
 * Нет адреса — null: ответ принимается как раньше.
 *
 * Адрес может стоять и внутри строки: через Orca worker шлёт одну строку без
 * переносов, и замечание встаёт перед адресом — «Замечание: … 76.4: a=4, …».
 * Текст до адреса — замечание worker (note), ответ — от адреса.
 *
 * $known(no, try) — был ли такой круг в прогоне; задан, когда блок назван явно:
 * дробь, какой не было («3.5: половина»), — часть ответа, а не адрес.
 */
function goAnswerAddress(string $result, array $open = [], ?callable $known = null): ?array
{
    // Все «N.K:» строки; адрес — совпавший с открытым кругом, иначе первый: в
    // замечании или в самом ответе бывает своя дробь с двоеточием («см. 2.5: …»).
    // Совпавших несколько («ответ на задание 43.1: …» в пояснении субагента) —
    // берём последний с начала строки: строка ответа идёт последней.
    if (!preg_match_all('/(?:^|\s)(\d+)\.(\d+)\s*:\s*/u', $result, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return null;
    if ($known) {
        $all = array_values(array_filter($all, static fn(array $one) => $known((int) $one[1][0], (int) $one[2][0])));
        if (!$all) return null;
    }
    $pick = $all[0];
    $found = false;
    foreach ($all as $one) {
        if (!in_array($one[1][0] . '.' . $one[2][0], $open, true)) continue;
        $line = $one[0][1] === 0 || $result[$one[0][1]] === "\n" || $result[$one[0][1] - 1] === "\n";
        if (!$found || $line) $pick = $one;
        $found = $found || $line;
    }
    // Хвост замечания — разделители перед адресом: «…не обработается — 76.4: …».
    // Субагент пишет пояснения строками выше ответа — в журнал они идут одной строкой.
    $note = preg_replace('/\s+/u', ' ', preg_replace('/[\s—–·;,-]+$/u', '', substr($result, 0, $pick[0][1])));
    return ['no' => (int) $pick[1][0], 'try' => (int) $pick[2][0],
            'rest' => trim(substr($result, $pick[0][1] + strlen($pick[0][0]))), 'note' => trim($note)];
}

/**
 * Записать задание в служебную папку прогона и отдать путь к файлу.
 *
 * <рабочая папка>/service/tasks/r<N>/<блок>.<круг>.txt. Служебная папка лежит В
 * рабочей папке нарочно (runServiceDir): worker запускается в рабочей папке и
 * дальше неё читать может не уметь или встать на вопросе о разрешении. От своих
 * файлов worker задания отделены папкой service, от браузера — её .htaccess
 * (serviceDirMake). Имя ставит сервер: leader нечего считать. Повторный task
 * пишет тот же файл.
 */
function goTaskFile(array $run, array $step, array $text): string
{
    $file = goTasksDir($run) . '/' . goAddress($step) . '.txt';
    if (@file_put_contents($file, implode("\n", $text) . "\n") === false) {
        throw new RuntimeException('Не удалось записать задание: ' . $file);
    }
    return $file;
}

/** Папка заданий прогона: <рабочая папка>/service/tasks/r<N>. */
function goTasksDir(array $run): string
{
    $dir = serviceDirMake(runServiceDir((int) $run['id'])) . '/tasks/r' . (int) $run['no'];
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось завести папку заданий: ' . $dir);
    }
    return $dir;
}

/**
 * Общая картина схемы для worker — файл «схема.txt» рядом с заданиями, один на прогон.
 *
 * Только если в свойствах папки включено «Давать worker общую картину схемы»
 * (share_scheme). В задании — одна строка с путём: worker читает файл один раз,
 * задания не раздуваются. Живую схему во время прогона править нельзя, поэтому
 * файл пишется при первом задании и дальше не меняется.
 */
function goSchemeFile(array $run): ?string
{
    $folder = dbRow('SELECT id, name, share_scheme FROM folders WHERE id = ?', [(int) $run['folder_id']]);
    if (!$folder || (int) $folder['share_scheme'] !== 1) return null;

    $file = goTasksDir($run) . '/схема.txt';
    $text = array_merge([
        ta('agents.go.scheme_file_head', ['name' => $folder['name']]),
        ta('agents.go.scheme_file_note'),
        ta('agents.go.scheme_file_legend'),
        '',
    ], goSchemeMap((int) $folder['id']));
    if (!is_file($file) && @file_put_contents($file, implode("\n", $text) . "\n") === false) {
        throw new RuntimeException('Не удалось записать общую картину: ' . $file);
    }
    return $file;
}

/**
 * Каркас схемы цепочками — без ТЗ:
 *   ◯190 Старт → ▣75 Начать счёт → ▣76 Следующий файл → ◆77 Файл есть?
 *   ◆77 да → ▣78 Искать лицо → ◆79 Лицо есть?
 * Ветки ромба — своими строками; уже показанный узел — коротко, номером (▣76).
 */
function goSchemeMap(int $folderId): array
{
    $nodes = [];
    foreach (dbAll("SELECT id, `no`, type, title FROM elements
                     WHERE folder_id = ? AND type IN ('block','decision','gateway') ORDER BY `no`", [$folderId]) as $row) {
        $nodes[(int) $row['id']] = $row;
    }
    $starters = array_map(static fn(array $one) => (int) $one['id'], folderStarters($folderId));

    // Выходы узлов: у ромба «да» раньше «нет».
    $outs = [];
    foreach (dbAll("SELECT from_id, to_id, branch, title FROM elements
                     WHERE folder_id = ? AND type = 'arrow' ORDER BY branch = 'no', `no`", [$folderId]) as $arrow) {
        if (isset($nodes[(int) $arrow['from_id']], $nodes[(int) $arrow['to_id']])) {
            $outs[(int) $arrow['from_id']][] = $arrow;
        }
    }

    $short = static function (int $id) use ($nodes, $starters): string {
        $icon = in_array($id, $starters, true) ? '◯'
            : (['decision' => '◆', 'gateway' => '⬡'][$nodes[$id]['type']] ?? '▣');
        return $icon . (int) $nodes[$id]['no'];
    };
    $full = static fn(int $id): string =>
        $short($id) . ' ' . trim((string) preg_replace('/\s+/u', ' ', (string) $nodes[$id]['title']));

    $lines = [];
    $seen = [];

    // Цепочка идёт, пока у узла один выход; на развилке — ветки своими строками.
    $walk = function (int $id, string $head) use (&$walk, &$seen, &$lines, $nodes, $outs, $short, $full): void {
        $chain = $head !== '' ? [$head] : [];
        $cur = $id;
        $fork = false;
        while (true) {
            if (isset($seen[$cur])) { $chain[] = $short($cur); break; }
            $seen[$cur] = true;
            $chain[] = $full($cur);
            $next = $outs[$cur] ?? [];
            if (count($next) === 1 && $nodes[$cur]['type'] !== 'decision') { $cur = (int) $next[0]['to_id']; continue; }
            $fork = count($next) > 0;
            break;
        }
        $lines[] = implode(' → ', $chain);
        if (!$fork) return;
        foreach ($outs[$cur] as $arrow) {
            $word = in_array((string) $arrow['branch'], ['yes', 'no'], true) ? ta('agents.go.branch.' . $arrow['branch']) : trim((string) $arrow['title']);
            $walk((int) $arrow['to_id'], $short($cur) . ($word !== '' ? ' ' . $word : ''));
        }
    };

    $entries = $starters ?: array_map(static fn(array $one) => (int) $one['id'], runEntriesByArrows($folderId));
    foreach ($entries as $id) if (isset($nodes[$id]) && !isset($seen[$id])) $walk($id, '');
    // Узлы, до которых от входа не дойти, — тоже в картину.
    foreach (array_keys($nodes) as $id) if (!isset($seen[$id])) $walk($id, '');
    return $lines;
}

/** Перевести выданный шаг в «работает»: блок на холсте загорается. $who — исполнитель по слову ведущего. */
function goStepRunning(int $projectId, int $runId, array $step, string $who = ''): void
{
    engineLocked($projectId, static function () use ($projectId, $runId, $step, $who): array {
        $now = stepRow((int) $step['id'], $projectId);
        // Кто делает блок — по слову ведущего (&who=, среда «Субагенты»): строка ленты времени.
        $named = stepWhoSave((int) $now['id'], $who);
        if ($now['state'] !== 'issued') {
            if ($named) runTouch($runId);   // имя сменилось у взятого шага — лента перечитает
            return [];
        }

        dbRun("UPDATE run_steps SET state = 'running', started_at = NOW(3), rev = ? WHERE id = ?",
            [bumpRev($projectId), (int) $now['id']]);
        runTouch($runId);
        eventStep($now, 'task', ta(goStepSelf($now) ? 'agents.go.ev_self' : 'agents.go.ev_given'), null, ['actor' => 'lead']);
        return [];
    });
}

/**
 * GET op=work&block=N — задание отдано worker, он взялся.
 *
 * Статус нужен только для картинки: блок загорается «в работе», и человек
 * у экрана видит, где сейчас прогон. Пропустить его не страшно.
 */
function goWork(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    $no = inputInt('block');
    if (!$no) plain([ta('agents.go.need_block_work')], 422);

    engineLocked($projectId, static function () use ($runId, $projectId, $no): array {
        $step = dbRow(
            "SELECT * FROM run_steps WHERE run_id = ? AND element_no = ?
              AND state IN ('issued','running') ORDER BY id DESC LIMIT 1",
            [$runId, $no]
        );
        if (!$step) throw new ApiError(ta('agents.go.not_open', ['no' => $no]), 'not_found');
        if ($step['state'] === 'running') return [];

        dbRun("UPDATE run_steps SET state = 'running', started_at = NOW(3), rev = ? WHERE id = ?",
            [bumpRev($projectId), (int) $step['id']]);
        runTouch($runId);
        eventStep($step, 'task', ta(goStepSelf($step) ? 'agents.go.ev_self' : 'agents.go.ev_given'), null, ['actor' => 'lead']);
        return [];
    });

    plain(array_merge([ta('agents.go.in_work', ['no' => $no])], goNextLines(runRow($runId, $projectId))));
}

/** op=fail&block=N&why=… — дело не вышло. GET или POST-форма, как done. */
function goFail(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    $no = inputInt('block');
    $why = goWhy() ?: ta('agents.go.lead_stopped_step');
    if (!$no) plain([ta('agents.go.need_block_fail')], 422);

    engineLocked($projectId, static function () use ($runId, $projectId, $no, $why): array {
        $run = runRow($runId, $projectId);
        $step = dbRow(
            "SELECT * FROM run_steps WHERE run_id = ? AND element_no = ?
              AND state IN ('issued','running','submitted') ORDER BY id DESC LIMIT 1",
            [$runId, $no]
        );
        if (!$step) throw new ApiError(ta('agents.go.not_open', ['no' => $no]), 'not_found');

        stepClose((int) $step['id'], 'failed', $projectId, ['error' => $why]);
        marksRelease((int) $step['id']);
        runTouch($runId);
        tokenRevokeFor('step_id', (int) $step['id']);
        eventStep($step, 'fail', ta('agents.go.ev_failed', ['task' => "{$step['element_no']}.{$step['attempt']}"]) . mb_substr($why, 0, 150),
            $why, ['actor' => 'lead']);
        return [];
    });

    $decided = goMoveLines($runId);
    plain(array_merge([ta('agents.go.stopped_block', ['no' => $no, 'why' => $why])], $decided, goNextLines(runRow($runId, $projectId))));
}

/**
 * GET op=invite&run=rN[&reply=АДРЕС] — вводная worker, готовой строкой.
 *
 * leader ничего worker не сочиняет: пересылает эту строку как есть. В ней —
 * путь к инструкции worker, которую сервер собрал под среду папки при begin
 * (goWorkerFile), и как отвечать — только для этой среды. reply — адрес leader:
 * в Орке терминал (тогда в строке готовая команда ответа), в SendMessage — имя
 * его сессии. Двойных кавычек в строке нет: её пересылают командой
 * --text "$(curl …invite…)".
 */
function goInvite(): void
{
    $project = requireProject(false);
    $run = goRun((int) $project['id']);
    $folder = folderRow((int) $run['folder_id'], (int) $project['id']);
    if (instrScenario($folder)['solo']) {
        throw new ApiError(ta('agents.go.solo_no_invite'), 'conflict');
    }
    $reply = trim((string) (input('reply') ?? ''));
    // Адрес уходит внутрь команды worker — только буквы, цифры, «_», «.» и «-».
    if ($reply !== '' && !preg_match('/^[\p{L}\p{N}_.-]{1,120}$/u', $reply)) {
        plain([ta('agents.go.bad_reply')], 422);
    }
    $env = folderActiveRunEnv($folder);
    goWorkerFile($run, $folder);
    $file = goTextUrl($project, $run, 'worker');

    $how = match ($env) {
        'orca' => $reply !== ''
            ? ta('agents.go.how_orca_reply', ['reply' => $reply])
            : ta('agents.go.how_orca'),
        'sendmessage' => ta('agents.go.how_send', ['to' => $reply !== '' ? ta('agents.go.how_send_to', ['reply' => $reply]) : ta('agents.go.how_send_back')]),
        default => ta('agents.go.how_sub'),
    };
    // Субагент получает вводную вместе с первым заданием — ждать ему нечего.
    $then = $env === 'subagents'
        ? ta('agents.go.then_task')
        : ta('agents.go.then_wait');

    plain([ta('agents.go.invite', ['no' => (int) $run['no'], 'name' => $folder['name'], 'file' => $file, 'how' => $how, 'then' => $then])]);
}

/**
 * Инструкция worker прогона — файл worker.md рядом с заданиями, один на прогон.
 *
 * Собирается при begin под среду папки (workerDoc); worker в сервер не ходит,
 * а файл в рабочей папке прочитает всегда. $fresh — переписать (begin);
 * без него файл пишется, только если его нет (прогон начат до этой правки или
 * состав сменили на команду уже после begin). В solo worker нет — файла тоже.
 */
function goWorkerFile(array $run, array $folder, bool $fresh = false): ?string
{
    if (instrScenario($folder)['solo']) return null;
    $file = goTasksDir($run) . '/worker.md';
    if (($fresh || !is_file($file)) && @file_put_contents($file, workerDoc($folder, $run)) === false) {
        throw new RuntimeException('Не удалось записать инструкцию worker: ' . $file);
    }
    return $file;
}

/**
 * Делает ли шаг сам ведущий: в solo — всегда; в Орке и SendMessage — когда шагу
 * не достался worker (на карточке ведущий или пусто). В субагентах решает он сам.
 */
function goSelf(array $run, array $step): bool
{
    $folder = dbRow('SELECT role_scheme, run_env FROM folders WHERE id = ?', [(int) $run['folder_id']]);
    if (!$folder) return false;
    $s = instrScenario($folder);
    return $s['solo'] || ($s['cards'] && !$step['agent_id']);
}

/** GET op=where[&run=r12] — где прогон и что делать сейчас. */
function goWhere(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);

    // После отката через op=again блоки не выданы: where выдаёт всё готовое.
    if ($run['state'] === 'running') {
        goMove((int) $run['id']);
        $run = runRow((int) $run['id'], $projectId);
    }

    $done = (int) dbValue("SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND state = 'accepted'", [(int) $run['id']]);
    $head = ta('agents.go.status', ['no' => (int) $run['no'], 'state' => $run['state'], 'n' => $done]);

    // &short=1 — одна строка статуса (её читает и сторож leader).
    if (input('short')) plain([$head . goBrief($run)]);
    plain(array_merge([$head], goNextLines($run)));
}

/** Хвост короткой сводки: какие блоки сейчас в работе и сколько раз ходили по кругу. */
function goBrief(array $run): string
{
    $runId = (int) $run['id'];
    $open = dbAll(
        "SELECT s.element_no, s.state, s.agent_id, e.title FROM run_steps s
           LEFT JOIN elements e ON e.id = s.element_id
          WHERE s.run_id = ? AND s.state IN ('issued','running','submitted') ORDER BY s.id",
        [$runId]);
    // Блок ведущего помечаем: иначе «ТИШИНА … в работе» сторожа читается как молчание worker.
    $now = array_map(static fn(array $s) =>
        ta('agents.go.brief_block', ['no' => $s['element_no'], 'title' => $s['title'], 'state' => ta('agents.go.state.' . $s['state'])])
        . ($s['state'] !== 'submitted' && goSelf($run, $s) ? ta('agents.go.brief_self') : ''), $open);

    // Самый хоженый блок и есть число кругов цикла.
    $laps = (int) dbValue(
        "SELECT MAX(n) FROM (SELECT COUNT(*) n FROM run_steps
          WHERE run_id = ? AND state = 'accepted' GROUP BY element_id) t", [$runId]);

    return ($laps > 1 ? ta('agents.go.brief_laps') . $laps : '')
        . ($now ? ta('agents.go.brief_now') . implode(', ', $now) : '');
}

/**
 * GET op=wait[&run=r12][&sec=25] — подождать перемен.
 *
 * Держим соединение, пока версия прогона не вырастет. Нужно, только когда
 * работают несколько worker и leader прямо сейчас делать нечего.
 */
function goWait(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    // Сначала ход: после отката (again) готовое иначе ждало бы where или done.
    goMove($runId);
    $since = (int) dbValue('SELECT version FROM runs WHERE id = ?', [$runId]);
    $sec = inputInt('sec') ?: 25;
    $until = microtime(true) + min(max($sec, 1), 25);

    while (microtime(true) < $until) {
        if ((int) dbValue('SELECT version FROM runs WHERE id = ?', [$runId]) > $since) break;
        if (connection_aborted()) exit;
        usleep(150000);
    }
    plain(goNextLines(runRow($runId, $projectId)));
}

/** GET op=stop[&run=r12] — закрыть прогон. */
function goStop(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    // Закрытый прогон не трогаем: пройденный не должен стать «остановленным».
    if (!in_array($run['state'], ['running', 'paused'], true)) {
        plain([ta('agents.go.already_closed', ['no' => (int) $run['no'], 'state' => $run['state']])]);
    }

    engineLocked($projectId, static function () use ($run): array {
        runStopDo($run, 'lead', ta('agents.go.stop_why'), ta('agents.go.stop_summary'));
        return [];
    });
    plain([ta('agents.go.stopped', ['no' => (int) $run['no']])]);
}

/**
 * GET op=again&block=N — переделать блок: последняя попытка стирается,
 * блок выдаётся заново.
 *
 * Нужна, когда worker напортачил, а leader уже отметил «готово».
 * Правило движка то же: стереть можно, только если на этом результате
 * ещё ничего не построено, — иначе сервер назовёт, что сбросить раньше.
 */
function goAgain(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $run = goRun($projectId);
    $runId = (int) $run['id'];

    $no = inputInt('block');
    if (!$no) plain([ta('agents.go.need_block_again')], 422);

    /* Что стёрто — открытая выдача или принятая работа, блок или ромб — говорит
       сам stepResetDo под тем же замком: иначе чужой done успел бы сменить
       попытку, и решение «выдавать ли заново» относилось бы не к ней. */
    $last = ['state' => '', 'type' => '', 'also' => []];
    engineLocked($projectId, static function () use ($project, $runId, $projectId, $no, &$last): array {
        $last = stepResetDo($project, runRow($runId, $projectId), $no, true, 'lead') + $last;
        return [];
    });
    $also = $last['also'];

    // Что снято заодно — невзятые выдачи и ромбы после блока (stepResetDo).
    $with = $also ? ta('agents.go.also') . implode(', ', $also) : '';

    /* Сняли открытую выдачу или решение ромба — заново не выдаём: leader
       откатывает цепочку назад, и следующий шаг — сбросить то, что перед ним.
       Выдай мы сразу, откат упирался бы в это снова и снова (ромб сервер
       перерешает мгновенно). Заново выдаётся только принятый настоящий блок. */
    $open = in_array($last['state'], ['issued', 'running', 'submitted'], true);
    if ($open || $last['type'] !== 'block') {
        plain([ta('agents.go.reset_back', ['what' => ta($open ? 'agents.go.reset_issue' : 'agents.go.reset_decision', ['no' => $no]), 'with' => $with])]);
    }

    $decided = goMoveLines($runId);
    plain(array_merge([ta('agents.go.redo', ['no' => $no, 'with' => $with])], $decided, goNextLines(runRow($runId, $projectId))));
}

/** Шаг делает сам ведущий — факт в миг выдачи (run_steps.lead_self), не нынешние настройки папки (goSelf). */
function goStepSelf(array $step): bool
{
    return (int) ($step['lead_self'] ?? 0) === 1;
}
