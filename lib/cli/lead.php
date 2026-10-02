<?php
/* Команды leader: прогон, шаги, приёмка, круг без рук.
   Отдаёт: cmd* функции leader и noteWithLead().
   Не делает: не решает за leader — ветки ромбов и спорную приёмку не трогает. */

declare(strict_types=1);

/** Значки состояний: одна картинка на все списки шагов. */
const MARKS = [
    'accepted' => '✓', 'running' => '◉', 'submitted' => '⌛', 'issued' => '→',
    'failed'   => '✗', 'returned' => '↩', 'cancelled' => '—',
];


// ── Схема и подготовка ─────────────────────────────────────────

function cmdRead(array $f): int
{
    if (!empty($f['element'])) {
        show(call('GET', 'element.get', ['no' => $f['element']], $f));
    } else {
        show(call('GET', 'folder.get', ['folder' => $f['folder'] ?? null], $f));
    }
    return 0;
}

function cmdCheck(array $f): int
{
    $answer = call('GET', 'run.check', ['folder' => $f['folder'] ?? null], $f);

    if (!empty($answer['ready'])) {
        say("✓ схема готова: {$answer['steps']} шагов, вход — блок {$answer['entries'][0]['no']}");
        return 0;
    }
    say('✗ схема не готова:');
    foreach ($answer['problems'] ?? [] as $problem) {
        $where = !empty($problem['no']) ? "блок {$problem['no']}: " : '';
        say("  · $where{$problem['say']}");
    }
    return 1;
}

/**
 * Подготовить папку к новому прогону: снять статусы прошлых прогонов,
 * убрать обложки, которые поставил прогон, и (с --files) вычистить `out/`
 * и `service/steps` в рабочей папке. Входы остаются на месте.
 */
function cmdPrepare(array $f): int
{
    $answer = call('POST', 'run.prepare', [
        'folder' => $f['folder'] ?? null,
        'files'  => !empty($f['files']) ? 1 : null,
    ], $f);

    $was = $answer['prepared'] ?? [];
    say('✓ папка готова к новому прогону');
    // Ярлык rN, а не id строки; старые серверы ярлыка не присылают — тогда молчим.
    $upTo = (int) ($was['runsHiddenNo'] ?? 0);
    say('  статусы прошлых прогонов' . ($upTo ? " по r$upTo" : '') . ' сняты с холста');
    say('  обложек из прогона снято: ' . (int) ($was['coversDropped'] ?? 0)
        . ', всего материалов прогона: ' . (int) ($was['linksDropped'] ?? 0));

    // Раньше строка читалась как жалоба на недостачу, хотя это норма.
    if (!empty($was['filesCleaned'])) {
        foreach ($was['filesCleaned'] as $part => $count) {
            say(is_int($count) ? "  $part: убрано файлов $count" : "  $part: $count");
        }
    } else {
        say('  файлы в рабочей папке оставлены как есть — чистит их только --files');
    }
    return 0;
}

function cmdStart(array $f): int
{
    $args = ['folder' => $f['folder'] ?? null, 'lead' => $f['agent'] ?? null];
    if (!empty($f['from'])) $args['from'] = $f['from'];

    // Вариант прогона (md_backend/ru/progon.md): кто ведёт, кто принимает, Jev, пауза показа.
    foreach (['driver', 'judge', 'jev'] as $key) {
        if (is_string($f[$key] ?? null)) $args[$key] = $f[$key];
    }
    if (isset($f['pause'])) {
        if (!is_numeric($f['pause'])) die_('--pause <секунды>: число, 0 — без паузы показа');
        $args['pause'] = (float) $f['pause'];
    }
    $args['commandId'] = commandId($f, 'run.start', $args);

    $answer = call('POST', 'run.start', $args, $f);
    $run = $answer['run'];
    say("✓ прогон {$run['label']} начат, старт — блок {$answer['start']['no']}");
    // Вариант печатаем, только если сервер его понял и вернул.
    if (isset($run['driver'])) {
        say('вариант: ' . implode(' · ', array_filter([
            DRIVER_WORDS[$run['driver']] ?? null,
            JUDGE_WORDS[$run['judge'] ?? ''] ?? null,
            JEV_WORDS[$run['jev'] ?? ''] ?? null,
            ((float) ($run['showPause'] ?? 0)) > 0 ? 'пауза показа ' . secs($run['showPause']) . ' с' : null,
        ])));
    }
    /* Ярлык нужен дальше каждой команде (--id rN) и worker, а внутри фразы
       «прогон r85 начат» его не выкусишь: отдаём отдельной строкой. */
    say("export GOBLIN_RUN={$answer['token']}");
    say("export GOBLIN_RUN_LABEL={$run['label']}");
    return 0;
}


// ── Ход прогона ────────────────────────────────────────────────

function cmdNext(array $f): int
{
    $answer = call('GET', 'run.get', runArgs($f), $f);
    say("прогон r{$answer['run']['no']} · {$answer['run']['state']}");
    foreach ($answer['steps'] as $step) {
        $mark = MARKS[$step['state']] ?? '·';
        say("  $mark {$step['no']}.{$step['attempt']} {$step['title']} — {$step['state']}");
    }

    if (!empty($answer['ready'])) {
        $list = array_map(static fn($r) => "{$r['no']} ({$r['title']})", $answer['ready']);
        say('можно открыть: ' . implode(', ', $list));
    } else {
        say('открыть нечего: ждём сдачи или прогон окончен');
    }
    if (!empty($answer['stop'])) say('⚠ человек попросил остановиться');
    return 0;
}

/** Записка к шагу: дописать адрес leader, чтобы worker знал, кому доложить. */
function noteWithLead(?string $note): ?string
{
    $who = cfg('lead');
    if ($who === '') return $note;
    $line = "leader: $who";
    return ($note !== null && $note !== '') ? "$note\n$line" : $line;
}

function cmdOpen(array $f): int
{
    $answer = openStep($f);
    say("✓ шаг {$answer['step']['no']}.{$answer['step']['attempt']} выдан");
    say("export GOBLIN_STEP={$answer['token']}");
    return 0;
}

/** Выдать шаг и вернуть ответ сервера: нужен и `open`, и `launch`. */
function openStep(array $f, ?string $no = null): array
{
    $args = runArgs($f) + [
        'no'    => $no ?? arg($f),
        'via'   => $f['via'] ?? null,
        'agent' => $f['agent'] ?? null,
        'note'  => noteWithLead(is_string($f['note'] ?? null) ? $f['note'] : null),
    ];
    $args['commandId'] = commandId($f, 'step.open', $args);
    return call('POST', 'step.open', $args, $f);
}

function cmdWait(array $f): int
{
    $no      = arg($f);
    $timeout = num($f, 'timeout', 900);
    $waited  = 0;

    while ($waited < $timeout) {
        $answer = call('GET', 'run.get', runArgs($f), $f);
        foreach ($answer['steps'] as $step) {
            if ($no !== null && (string) $step['no'] !== (string) $no) continue;
            if (in_array($step['state'], ['submitted', 'failed', 'returned', 'cancelled'], true)) {
                $said = $step['result'] ?? $step['error'] ?? '';
                say("{$step['no']}.{$step['attempt']} → {$step['state']}" . ($said !== '' ? ": $said" : ''));
                return 0;
            }
        }
        sleep(2);
        $waited += 2;
    }
    die_('не дождались');
    return 1;
}


// ── Приёмка и решения ──────────────────────────────────────────

/**
 * Сообщение в ленту прогона.
 *
 * Сообщения между сессиями проходят мимо Гоблина, и в журнале прогона их
 * не видно. Эта команда кладёт их туда: и leader, и worker пишут одинаково.
 */
function cmdSay(array $f): int
{
    $text = (string) (arg($f) ?? $f['text'] ?? '');
    if (trim($text) === '') die_('нечего говорить: goblin say «текст» [--to ОПУС-3]');

    $who = is_string($f['to'] ?? null) ? " → {$f['to']}" : '';
    tell($f, 'message', mb_substr(trim($who === '' ? $text : "$who: $text"), 0, 200), $text,
        ['no' => is_string($f['no'] ?? null) ? $f['no'] : null]);

    say('✓ записано в ленту прогона');
    return 0;
}

/**
 * Лента прогона в терминале.
 *
 * Без флагов — заголовки: по строке на событие. С `--full` раскрывается тело
 * каждого, с `--event N` — одно событие целиком.
 */
function cmdLog(array $f): int
{
    if (!empty($f['event'])) {
        $one = call('GET', 'run.log', runArgs($f) + ['event' => $f['event']], $f)['event'];
        say(logLine($one));
        if (($one['body'] ?? '') !== '') say("\n" . $one['body']);
        return 0;
    }

    // Журнал чаще всего читают уже после конца прогона, когда пропуск погас.
    $answer = askAboutRun('run.log', runArgs($f) + array_filter([
        'step'  => $f['step'] ?? null,
        'kind'  => $f['kind'] ?? null,
        'limit' => $f['limit'] ?? null,
    ], static fn($v) => is_string($v)), $f);

    say("прогон {$answer['run']['label']} · {$answer['run']['state']} · событий "
        . count($answer['events']));

    foreach ($answer['events'] as $one) {
        say(logLine($one));
        if (!empty($f['full']) && $one['size'] > 0) {
            $body = call('GET', 'run.log', runArgs($f) + ['event' => (string) $one['id']], $f)['event']['body'] ?? '';
            foreach (explode("\n", rtrim((string) $body)) as $row) say('      ' . $row);
        }
    }
    return 0;
}

/** Одна строка ленты: время, кто, что и сколько весит тело. */
function logLine(array $e): string
{
    $mark = ['worker' => '·', 'lead' => '→', 'human' => '☺', 'judge' => '⚖', 'script' => '•'][$e['actor']] ?? '•';
    $when = mb_substr((string) $e['at'], 11, 8);
    $step = $e['no'] ? sprintf('%s.%s', $e['no'], $e['attempt'] ?? 1) : '—';
    /* Размер тела. Некоторые события называют его прямо в заголовке
       («пакет задания · 3,3 КБ»), и строка выходила с двумя размерами подряд
       в разных написаниях — свой не добавляем. */
    $body = '';
    if ($e['size'] > 0 && !preg_match('/\d+([.,]\d+)?\s*(Б|КБ|МБ)\s*$/u', (string) $e['title'])) {
        $body = ' · ' . ($e['size'] < 1024 ? $e['size'] . ' Б' : round($e['size'] / 1024, 1) . ' КБ');
    }
    $took = $e['tookMs'] ? ' · ' . round($e['tookMs'] / 1000, 1) . ' с' : '';
    return sprintf('%4d %s %s %-7s %-9s %s%s%s', $e['id'], $when, $mark, $step, $e['kind'], $e['title'], $took, $body);
}

/**
 * Спросить сигнальщика о сданном шаге или о ромбе.
 *
 * Ничего не меняет: печатает, что он увидел. Решение остаётся за leader.
 */
function cmdJev(array $f): int
{
    $answer = call('POST', 'step.jev', runArgs($f) + [
        'no'  => num($f, 'no', 0) ?: (int) arg($f),
        'raw' => !empty($f['raw']) ? 1 : null,
    ], $f);

    $jev = $answer['jev'];
    if (isset($jev['branch'])) {
        say(sprintf('◆ джев: ветка %s · уверенность %d%%',
            $jev['branch'], round(((float) $jev['confidence']) * 100)));
    } else {
        sayJev($jev, (string) (arg($f) ?? 'шаг'));
    }
    if (!empty($f['raw'])) show($jev);
    return 0;
}

/** Суждение о сдаче одной-двумя строками. */
function sayJev(array $jev, string $where): void
{
    $against = (float) ($jev['contradicts'] ?? 0);
    $mark = $against >= 0.8 ? '✗' : ($against >= 0.5 ? '⚠' : '✓');
    say(sprintf('%s %s · противоречие %d%% · %s · принять %d%% (уверенность %d%%)',
        $mark, $where, round($against * 100), $jev['why'] ?? '?',
        round(((float) ($jev['accept'] ?? 0)) * 100),
        round(((float) ($jev['confidence'] ?? 0)) * 100)));
}

function cmdAccept(array $f): int
{
    $answer = call('POST', 'step.accept', runArgs($f) + [
        'no'        => arg($f),
        'why'       => $f['why'] ?? null,
        'tokensIn'  => $f['tokens-in'] ?? null,
        'tokensOut' => $f['tokens-out'] ?? null,
    ], $f);

    foreach ($answer['warnings'] ?? [] as $warning) say("⚠ $warning");
    say("✓ принят шаг {$answer['step']['no']}.{$answer['step']['attempt']}");
    return 0;
}

function cmdReturn(array $f): int
{
    $answer = call('POST', 'step.return', runArgs($f) + ['no' => arg($f), 'why' => $f['why'] ?? null], $f);
    say("↩ возвращён шаг {$answer['step']['no']}.{$answer['step']['attempt']}");
    return 0;
}

function cmdDecide(array $f): int
{
    $branch = arg($f, 1);
    $answer = call('POST', 'step.decide',
        runArgs($f) + ['no' => arg($f), 'branch' => $branch, 'why' => $f['why'] ?? null], $f);
    say("✓ ромб {$answer['step']['no']}: ветка $branch");
    return 0;
}

function cmdPass(array $f): int
{
    $answer = call('POST', 'step.pass', runArgs($f) + ['no' => arg($f)], $f);
    say("✓ шлюз {$answer['step']['no']}: прогон перешёл в папку {$answer['folder']}");
    return 0;
}

function cmdCancel(array $f): int
{
    call('POST', 'step.cancel', runArgs($f) + ['no' => arg($f), 'why' => $f['why'] ?? null], $f);
    say('✓ шаг отменён');
    return 0;
}

function cmdReissue(array $f): int
{
    $answer = call('POST', 'step.reissue', runArgs($f) + ['no' => arg($f)], $f);
    say("export GOBLIN_STEP={$answer['token']}");
    return 0;
}


// ── Управление прогоном ────────────────────────────────────────

function cmdPause(array $f): int
{
    call('POST', 'run.pause', runArgs($f), $f);
    say('⏸ пауза');
    return 0;
}

function cmdResume(array $f): int
{
    call('POST', 'run.resume', runArgs($f), $f);
    say('▶ продолжаем');
    return 0;
}

function cmdStop(array $f): int
{
    $answer = call('POST', 'run.stop', runArgs($f) + ['now' => !empty($f['now']) ? 1 : null], $f);
    $already = alreadyLine($answer);
    say($already !== null ? "· $already" : '■ остановка запрошена');
    return 0;
}

/**
 * Команда пришла к уже закрытому прогону (`already`): сервер ничего не менял — так и
 * говорим, без «✓» свежего успеха. Не закрыт раньше — null.
 */
function alreadyLine(array $answer): ?string
{
    if (!isset($answer['already'])) return null;
    $word = RUN_WORDS[$answer['already']] ?? $answer['already'];
    return 'прогон ' . ($answer['run']['label'] ?? '?') . " закрыт раньше — $word; ничего не изменилось";
}

function cmdAttach(array $f): int
{
    $answer = call('POST', 'run.attach', ['run' => arg($f)], $f);
    say("export GOBLIN_RUN={$answer['token']}");
    foreach ($answer['open'] ?? [] as $step) {
        say("  открыт шаг {$step['no']}.{$step['attempt']} — {$step['state']}");
    }
    return 0;
}

function cmdFinish(array $f): int
{
    $answer = call('POST', 'run.finish', runArgs($f) + [
        'failed'  => !empty($f['failed']) ? 1 : null,
        'why'     => $f['why'] ?? null,
        'summary' => $f['summary'] ?? null,
    ], $f);
    $already = alreadyLine($answer);
    say($already !== null ? "· $already" : "✓ прогон {$answer['run']['label']} закрыт: {$answer['run']['state']}");
    return 0;
}

/** Сделать блок снова новым, а с --all — весь прогон. */
function cmdReset(array $f): int
{
    if (!empty($f['all'])) {
        $answer = call('POST', 'run.reset', runArgs($f), $f);
        say("✓ прогон начат заново, стёрто шагов: {$answer['reset']}");
        return 0;
    }
    $answer = call('POST', 'step.reset', runArgs($f) + ['no' => arg($f)], $f);
    say("✓ блок {$answer['no']} снова новый, стёрто попыток: {$answer['reset']}");
    return 0;
}


// ── Обзор ──────────────────────────────────────────────────────

/** Все шаги прогона: что открыто, что сдано, чем чинить. */
function cmdSteps(array $f): int
{
    $answer = askAboutRun('run.get', runArgs($f), $f);
    say("прогон r{$answer['run']['no']} · {$answer['run']['state']}");

    foreach ($answer['steps'] as $step) {
        $mark = MARKS[$step['state']] ?? '·';
        $said = (string) ($step['result'] ?? $step['error'] ?? '');
        say("  $mark {$step['no']}.{$step['attempt']} {$step['title']} — {$step['state']}"
            . ($said !== '' ? ' · ' . mb_substr($said, 0, 70) : ''));
    }

    // Пропуск шага сервер отдаёт один раз: потерянный не показать, его выписывают
    // заново. Тем, кто берёт работу сам по долгому пропуску агента, он не нужен —
    // о них и не напоминаем.
    $live = array_filter($answer['steps'], static fn($s) =>
        in_array($s['state'], ['issued', 'running'], true) && empty($s['selfServe']));
    if ($live) {
        $fix = array_map(static fn($s) => "goblin reissue {$s['no']}", $live);
        say('нужен пропуск шага — выпиши новый: ' . implode(', ', $fix));
    }
    return 0;
}

function cmdStatus(array $f): int
{
    show(call('GET', 'run.get', runArgs($f), $f));
    return 0;
}


// ── Круг без рук ───────────────────────────────────────────────

/**
 * Открыть всё, что готово, дождаться сдачи, показать её.
 *
 * Решения остаются leader: ветки ромбов и спорную приёмку команда не трогает.
 * Её дело — чтобы между шагами не было пауз из-за ненажатой кнопки.
 */
/**
 * Круг leader без рук.
 *
 * Без пропуска прогона и без `--id` ведёт сразу все живые прогоны проекта:
 * один фоновый процесс на любое их число. Каждый круг делает один шаг работы
 * по каждому прогону — принять чистое, решить ромб с условием, открыть
 * готовое — и, когда работать больше не с чем, закрывает прогон сам.
 *
 * Останавливается там, где нужен человек: спорная приёмка, `fail`, ромб без
 * условия, шлюз.
 */
function cmdDrive(array $f): int
{
    asScript();
    $rounds  = num($f, 'rounds', 50);
    $timeout = num($f, 'timeout', 900);
    // Весь проект — только когда прогон не назван. Мусор в --id — ошибка в runIdArg().
    $whole   = runIdArg($f) === null && tokenOf($f) === '';

    // Прогоны, где нужен человек: прогон → круг, на котором его отложили.
    // Насовсем не бросаем: человек мог всё починить, поэтому время от времени
    // заглядываем снова.
    $left  = [];
    $again = 20;

    for ($round = 0; $round < $rounds; $round++) {
        foreach ($left as $runId => $when) {
            if ($round - $when >= $again) unset($left[$runId]);
        }
        $live = $whole ? liveRunIds($f) : [];
        $runs = $whole
            ? array_values(array_diff($live, array_keys($left)))
            : [null];

        if ($whole && !$runs) {
            say($left ? 'остальные прогоны ждут человека' : 'живых прогонов в проекте нет');
            return 0;
        }

        // Что круг увидел в каждом прогоне — с этим и сравнивает ожидание.
        // null — прогон отложен (ждёт человека): его точка отсчёта — первое чтение ожидания.
        $pictures = array_fill_keys($live, null);

        $idle = 0;
        foreach ($runs as $runId) {
            $how = driveOnce($f, $runId, empty($f['no-finish']), $pictures);

            // Остановка одного прогона не должна ронять остальные: в режиме
            // «весь проект» просто выходим из этого прогона и ведём другие.
            if ($how === 'stop') {
                if (!$whole) return 0;
                $left[(int) $runId] = $round;
                continue;
            }
            if ($how === 'idle') $idle++;
        }

        // Все оставшиеся ждут worker — ждём смены состояния, не опрашивая вхолостую.
        if ($runs && $idle === count($runs)) {
            if (!waitForChange($f, $timeout, $whole, $pictures)) {
                say("⚠ никто не сдал работу за $timeout с — посмотри, живы ли worker");
                return 4;
            }
        }
    }
    return 0;
}

/**
 * Один заход по одному прогону.
 *
 * Вернёт: `moved` — что-то сделали; `idle` — ждём worker; `stop` — нужен
 * человек или прогон закончился. В `$pictures` кладёт картину, которую видел.
 */
function driveOnce(array $f, ?int $runId, bool $mayFinish, array &$pictures = []): string
{
    $args   = $runId ? ['run' => (string) $runId] : runArgs($f);
    $answer = call('GET', 'run.get', $args, $f);
    $run    = $answer['run'];

    // Запомнить увиденное: ожидание сравнит с этим, а не со своим первым чтением.
    $pictures[(int) $run['id']] = drivePicture($answer);
    $where  = (string) ($run['workDir'] ?? '');
    $label  = (string) ($run['label'] ?? '?');

    if (!in_array($run['state'], ['running', 'paused'], true)) {
        say("$label {$run['state']} — работать не с чем");
        return 'stop';
    }
    if (!empty($answer['stop'])) {
        drivesay($where, "$label: человек попросил остановиться", '⚠', $f);
        return 'stop';
    }

    // 1. Сдано или упало — принять чистое, остальное отдать человеку.
    $done = array_filter($answer['steps'], static fn($s) => in_array($s['state'], ['submitted', 'failed'], true));
    if ($done) {
        foreach ($done as $step) {
            $said = (string) ($step['result'] ?? $step['error'] ?? '');
            drivesay($where, "$label · {$step['no']}.{$step['attempt']} {$step['title']} — {$step['state']}: $said", '⌛', $f);

            if (empty($f['accept-clean']) || $step['state'] !== 'submitted') return 'stop';

            /* Сигнальщик смотрит на сдачу раньше приёмки. Он ничего не решает:
               образец ответа и условие ромба проверил сервер, а Джев видит то,
               чего алгоритм не видит, — сходится ли сданное по смыслу. Флаг
               поднят — шаг не принимается сам, а уходит к leader. */
            if (empty($f['no-jev'])) {
                $seen = quiet(static fn() => call('POST', 'step.jev', $args + ['no' => $step['no']], $f));
                $name = "$label · {$step['no']}.{$step['attempt']}";

                /* Джев не ответил — это не «можно принимать»: шаг ждёт человека.
                   Исключение одно — ключа нет вовсе (jev_off): Джев выключен,
                   и приёмка идёт как без него. */
                if ($seen === null && lastFail()->why !== 'jev_off') {
                    drivesay($where, "$name: джев недоступен — посмотри сам ("
                        . lastFail()->getMessage() . ')', '⚠', $f);
                    return 'stop';
                }
                if ($seen !== null) {
                    $jev   = $seen['jev'];
                    $limit = is_string($f['jev-at'] ?? null) ? (float) $f['jev-at'] : 0.8;

                    if ((float) $jev['contradicts'] >= $limit) {
                        sayJev($jev, $name);
                        drivesay($where, "$name: джев видит противоречие — посмотри сам", '⚠', $f);
                        return 'stop';
                    }
                    if ((float) $jev['contradicts'] >= 0.5) sayJev($jev, $name);
                }
            }

            $got = call('POST', 'step.accept', $args + ['no' => $step['no']], $f);
            foreach ($got['warnings'] ?? [] as $warning) drivesay($where, "$label: $warning", '⚠', $f);
            if (!empty($got['warnings'])) {
                drivesay($where, "$label: приёмка с оговорками — посмотри сам", ' ', $f);
                return 'stop';
            }
            drivesay($where, "$label · принят {$step['no']}.{$step['attempt']}", '✓', $f);
        }
        return 'moved';
    }

    // 2. Есть что открыть. Ромб решаем по условию, шлюз — дело человека.
    if (!empty($answer['ready'])) {
        foreach ($answer['ready'] as $item) {
            $type = (string) ($item['type'] ?? 'block');

            if ($type === 'decision') {
                $choice = condDecide($item, $answer['steps']);
                if ($choice === null) {
                    drivesay($where, "$label: ромб {$item['no']} «{$item['title']}» ждёт решения", '◆', $f);

                    /* Условие словесное или посчитать его не вышло — спросим
                       Джева. Ветку он не выбирает, это подсказка leader. */
                    if (empty($f['no-jev'])) {
                        $seen = quiet(static fn() => call('POST', 'step.jev', $args + ['no' => $item['no']], $f));
                        if ($seen !== null) {
                            $jev = $seen['jev'];
                            say(sprintf('  джев: ветка %s (уверенность %d%%) — решать всё равно тебе',
                                $jev['branch'], round(((float) $jev['confidence']) * 100)));
                        }
                    }
                    say("  goblin decide {$item['no']} yes|no --why «почему»");
                    if (trim((string) ($item['cond'] ?? '')) !== '') {
                        say("  условие «{$item['cond']}» посчитать не удалось — реши сам");
                    }
                    return 'stop';
                }
                call('POST', 'step.decide', $args + [
                    'no' => $item['no'], 'branch' => $choice['branch'], 'why' => $choice['why'],
                ], $f);
                drivesay($where, "$label: ромб {$item['no']} → {$choice['branch']} ({$choice['why']})", '◆', $f);
                continue;
            }
            if ($type === 'gateway') {
                drivesay($where, "$label: шлюз {$item['no']} «{$item['title']}» ждёт перехода", '⇥', $f);
                say("  goblin pass {$item['no']}");
                return 'stop';
            }

            $opened = call('POST', 'step.open', $args + [
                'no'    => (string) $item['no'],
                'note'  => noteWithLead(null),
            ], $f);
            drivesay($where, "$label: открыт {$opened['step']['no']}.{$opened['step']['attempt']} {$item['title']}", '→', $f);
        }
        return 'moved';
    }

    // 3. Никто не работает и открывать нечего — прогон пройден.
    $live = array_filter($answer['steps'], static fn($s) => in_array($s['state'], ['issued', 'running'], true));
    if (!$live) {
        if (!$mayFinish) {
            drivesay($where, "$label: открывать нечего — нужен ромб, шлюз или finish", ' ', $f);
            return 'stop';
        }
        return driveFinish($f, $args, $answer, $where, $label);
    }
    return 'idle';
}

/** Закрыть пройденный прогон и сказать об этом одной строкой. */
function driveFinish(array $f, array $args, array $answer, string $where, string $label): string
{
    $taken = 0;
    $loops = [];
    foreach ($answer['steps'] as $step) {
        if ($step['state'] !== 'accepted') continue;
        $taken++;
        $loops[$step['no']] = max($loops[$step['no']] ?? 0, (int) $step['attempt']);
    }
    $rounds = $loops ? max($loops) : 1;
    $summary = "круг без рук: принято шагов $taken, кругов $rounds";

    $got = quiet(static fn() => call('POST', 'run.finish', $args + ['summary' => $summary], $f));
    if ($got === null) {
        drivesay($where, "$label: прогон пройден, но закрыть не вышло — " . lastFail()->getMessage(), '⚠', $f);
        return 'stop';
    }
    if ($said = alreadyLine($got)) {
        drivesay($where, $said, '·', $f);
        return 'stop';
    }
    drivesay($where, "$label закрыт: $summary", '✓', $f);
    return 'stop';
}

/** Номера живых прогонов проекта. */
function liveRunIds(array $f): array
{
    $answer = call('GET', 'run.get', [], $f);
    $out = [];
    foreach ($answer['runs'] ?? [] as $run) {
        if (in_array($run['state'] ?? '', ['running', 'paused'], true)) $out[] = (int) $run['id'];
    }
    return $out;
}

/**
 * Строка на экран, в файл журнала и в ленту прогона.
 *
 * Лента — главное место: она видна человеку в панели и переживает хостинг.
 * Файл остаётся аварийным следом на случай, если сервер не ответил.
 */
function drivesay(string $workDir, string $text, string $mark = ' ', array $f = [], string $kind = 'drive'): void
{
    say(trim("$mark $text"));
    journalLine($workDir, $text);

    /* В журнал ярлык прогона не пишем: там и так всё об одном прогоне.
       В терминале он нужен — круг ведёт несколько прогонов сразу. */
    if ($f) tell($f, $kind, preg_replace('/^r\d+\s*[:·]\s*/u', '', $text));
}

/**
 * Решение ромба по свойству `cond`.
 *
 * Условие пишется на ромбе словами языка схемы: `a > 5`, `a >= 3 && s > 10`.
 * Числа берутся из результата того шага, который дал жетон (`from`), а если
 * его не найти — из последнего принятого шага. Не разобрали условие или нет
 * нужного числа — возвращаем null: решение остаётся leader.
 */
function condDecide(array $item, array $steps): ?array
{
    $cond = trim((string) ($item['cond'] ?? ''));
    if ($cond === '') return null;

    $said = lastResult($steps, isset($item['from']) ? (int) $item['from'] : 0);
    if ($said === '') return null;

    $vars = condVars($said);

    $yes = condTrue($cond, $vars, $said);
    if ($yes === null) return null;

    $seen = [];
    foreach ($vars as $name => $value) $seen[] = "$name=$value";
    return [
        'branch' => $yes ? 'yes' : 'no',
        'why'    => implode(' ', $seen) . ': условие «' . $cond . '» ' . ($yes ? 'верно' : 'неверно'),
    ];
}

/** Результат последней принятой попытки блока (или любого блока). */
function lastResult(array $steps, int $no): string
{
    $said = '';
    foreach ($steps as $step) {
        if ($step['state'] !== 'accepted') continue;
        if ($no > 0 && (int) $step['no'] !== $no) continue;
        $said = (string) ($step['result'] ?? '');
    }
    if ($said === '' && $no > 0) return lastResult($steps, 0);
    return $said;
}

/**
 * Числа из результата.
 *
 * Понимаем два вида записи: «a=3 s=6» и «строк 0 · лиц 2». Имя может быть
 * русским — условия на схемах пишут по-русски.
 */
function condVars(string $said): array
{
    $out = [];
    $name = '[\\p{L}_][\\p{L}\\p{N}_]*';
    $num  = '-?\\d+(?:[.,]\\d+)?';

    // Сначала пары через знак равенства — они точнее.
    if (preg_match_all("/($name)\\s*=\\s*($num)/u", $said, $found, PREG_SET_ORDER)) {
        foreach ($found as $one) $out[$one[1]] = (float) str_replace(',', '.', $one[2]);
    }
    // Затем «слово число»: так пишут «строк 3», «лиц 0».
    if (preg_match_all("/($name)\\s+($num)/u", $said, $found, PREG_SET_ORDER)) {
        foreach ($found as $one) {
            if (!array_key_exists($one[1], $out)) $out[$one[1]] = (float) str_replace(',', '.', $one[2]);
        }
    }
    return $out;
}

/**
 * Посчитать условие. Понимаем сравнения, связанные && и ||, без скобок:
 * `a > 5`, `a >= 3 && s > 10`. Чего не поняли — null.
 */
function condTrue(string $cond, array $vars, string $said = ''): ?bool
{
    $parts = preg_split('/\s*(&&|\|\|)\s*/', $cond, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!$parts) return null;

    $value = null;
    $join  = '';

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '&&' || $part === '||') { $join = $part; continue; }

        $one = condOne($part, $vars, $said);
        if ($one === null) return null;

        if ($value === null)      $value = $one;
        elseif ($join === '&&')   $value = $value && $one;
        elseif ($join === '||')   $value = $value || $one;
        else                      return null;
    }
    return $value;
}

/**
 * Одно условие: либо сравнение чисел, либо правило про слова.
 *
 * Сравнение: `a > 5`, `строк >= 1`.
 * Слова: `содержит лицо есть`, `кончается есть`, `начинается лица нет` —
 * так пишутся развилки, где ответ словесный, а не числовой.
 */
function condOne(string $part, array $vars, string $said = ''): ?bool
{
    $word = condWord($part, $said);
    if ($word !== null) return $word;

    $name = '[\\p{L}_][\\p{L}\\p{N}_]*';
    $num  = '-?\\d+(?:[.,]\\d+)?';
    if (!preg_match("/^($name)\\s*(>=|<=|==|!=|=|>|<)\\s*($num|$name)$/u", $part, $found)) {
        return null;
    }
    [, $name, $sign, $right] = $found;
    if (!array_key_exists($name, $vars)) return null;

    $left = $vars[$name];
    $to   = is_numeric(str_replace(',', '.', $right))
        ? (float) str_replace(',', '.', $right)
        : ($vars[$right] ?? null);
    if ($to === null) return null;

    return match ($sign) {
        '>'  => $left >  $to,
        '<'  => $left <  $to,
        '>=' => $left >= $to,
        '<=' => $left <= $to,
        '==', '=' => abs($left - $to) < 0.000001,
        '!=' => abs($left - $to) >= 0.000001,
        default => null,
    };
}

/** Правило про слова: содержит / начинается / кончается. Не оно — null. */
function condWord(string $part, string $said): ?bool
{
    if (!preg_match('/^(содержит|начинается|кончается)\s+(.+)$/ui', trim($part), $found)) {
        return null;
    }
    $what = mb_strtolower(trim($found[2], " \t«»\"'"));
    $text = mb_strtolower(trim($said));
    if ($what === '') return null;

    return match (mb_strtolower($found[1])) {
        'содержит'   => str_contains($text, $what),
        'начинается' => str_starts_with($text, $what),
        'кончается'  => str_ends_with($text, $what),
        default      => null,
    };
}

/**
 * Ждать, пока хоть один шаг сменит состояние.
 *
 * Сравнивает с тем, что уже видел круг (`$pictures` из driveOnce), а не со своим
 * первым чтением: иначе сдача, пришедшая между чтением круга и началом ожидания,
 * становилась точкой отсчёта, и круг спал до конца срока.
 *
 * $pictures: прогон → картина; null — прогон круг не вёл, отсчёт от первого чтения.
 */
function waitForChange(array $f, int $timeout, bool $whole = false, array $pictures = []): bool
{
    $waited = 0;

    while ($waited < $timeout) {
        $now = [];
        foreach ($whole ? liveRunIds($f) : [null] as $runId) {
            $args   = $runId ? ['run' => (string) $runId] : runArgs($f);
            $answer = call('GET', 'run.get', $args, $f);
            $now[(int) $answer['run']['id']] = drivePicture($answer);
        }

        foreach ($now as $id => $picture) {
            if (!array_key_exists($id, $pictures)) return true;      // появился новый прогон
            if ($pictures[$id] === null) {                            // отложенный: отсчёт отсюда
                $pictures[$id] = $picture;
                continue;
            }
            if ($pictures[$id] !== $picture) return true;             // шаг сменил состояние
        }
        if (array_diff_key($pictures, $now)) return true;             // прогон закрылся

        sleep(3);
        $waited += 3;
    }
    return false;
}

/** Картина прогона для сравнения: по строке «шаг.попытка:состояние». */
function drivePicture(array $answer): array
{
    $out = [];
    foreach ($answer['steps'] ?? [] as $step) {
        $out[] = "{$step['no']}.{$step['attempt']}:{$step['state']}";
    }
    return $out;
}
