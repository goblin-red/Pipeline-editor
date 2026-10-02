<?php
/* Команды worker: взять задание, вехи, заявки, сдача, падение.
   Отдаёт: cmd* функции worker, workerExactScope(), peek(), workDir(), leadName().
   Не делает: не меняет состояний прогона — это дело leader. */

declare(strict_types=1);

/**
 * Exact scope для долгого agent-token в engine 2.
 *
 * Short step-token сам адресует попытку, engine 1 сохраняет прежний current-step
 * договор. Но long agent в engine 2 не имеет права заново выбирать «последний»
 * шаг: run/step берём только из кэша того же work/session.
 */
function workerExactScope(array $f, bool $claim): array
{
    $token = tokenOf($f);
    if (!str_starts_with($token, 'gba_')) return [];

    $session = workerSession($f);
    $record = $session !== '' ? stepCacheRecord($f) : null;
    if ($record) {
        $scope = ['run' => (int) $record['run'], 'step' => (int) $record['step']];
        if ($claim) $scope['session'] = workerSession($f, true);
        return $scope;
    }

    // Явный run для legacy также остаётся точным: step.get не имеет права
    // заново выбирать другой открытый шаг того же агента.
    $asked = runIdArg($f);
    if ($asked !== null && serverHas('run.state')) {
        $auth = ['_' => [], 'token' => workerAgentToken($f)];
        $state = quiet(static fn() => call('GET', 'run.state', ['run' => $asked], $auth, 15));
        if ($state === null) die_(lastFail()->getMessage(), lastFail()->why, 2);
        if ((int) ($state['run']['engine'] ?? 1) === 1) return ['run' => $asked];
    }

    // Старый движок не знает session и продолжает работать через current step.
    // Узнаём engine только read-only запросами, но не используем найденный шаг
    // как скрытый fallback для engine 2.
    if (!serverHas('run.state')) return [];
    $auth = ['_' => [], 'token' => $token];
    $mine = quiet(static fn() => call('GET', 'step.mine', ['wait' => 0], $auth, 15));
    $runId = (int) ($mine['step']['run'] ?? 0);
    if ($runId) {
        $state = quiet(static fn() => call('GET', 'run.state', ['run' => $runId], $auth, 15));
        if ($state !== null && (int) ($state['run']['engine'] ?? 1) === 1) return [];
    }

    die_('для engine 2 нет точного run/step этой worker session — сначала goblin work',
        'worker_scope_required', 3);
}


// ── Задание ────────────────────────────────────────────────────

/** Долгий пропуск агента: с ним worker сам берёт свои шаги. */
function cmdToken(array $f): int
{
    $answer = call('POST', 'agent.token', ['id' => arg($f) ?? ($f['agent'] ?? null)], $f);
    $session = workerSession($f) ?: 'worker-' . bin2hex(random_bytes(12));

    /* Пропуск принадлежит агенту в проекте, а не прогону, но выдают его всегда
       под конкретный прогон. Две одинаковые пары export от разных прогонов
       путались: называем тот, в котором пропуск выписан. */
    $where = runIdArg($f);
    say("✓ пропуск агента {$answer['agent']['name']}"
        . ($where !== null ? ' · выдан в прогоне ' . (ctype_digit($where) ? "r$where" : $where) : ''));
    say("export GOBLIN_AGENT={$answer['token']}");
    say("export GOBLIN_WORKER_SESSION=$session");
    if ($where !== null) say('worker скажи работать в этом прогоне: goblin work --id '
        . (ctype_digit($where) ? "r$where" : $where));
    return 0;
}

/**
 * Ждать свой следующий шаг и сразу взять его.
 *
 * Сервер держит запрос до появления работы (`step.mine --wait`), поэтому
 * вхолостую никто не опрашивает.
 */
function cmdWork(array $f): int
{
    $wait   = num($f, 'wait', 60);
    $answer = quiet(static fn() => call('GET', 'step.mine', ['wait' => $wait] + runArgs($f), $f, $wait + 15));

    // Прогон закрыли — пропуск агента гаснет вместе с ним. Для worker это
    // не ошибка, а сигнал расходиться: иначе круг зацикливался на отказе.
    if ($answer === null) {
        if (in_array(lastFail()->why, ['unauthorized', 'not_found'], true)) {
            say('пропуск закрыт: прогон окончен, работа больше не придёт');
            return 0;
        }
        die_(lastFail()->getMessage());
    }

    if (empty($answer['ready'])) {
        if (!empty($answer['running'])) {
            say('работы пока нет: leader ещё не открыл следующий шаг');
            return 3;
        }
        say('прогонов больше нет — работа окончена');
        return 0;
    }

    $step = $answer['step'];
    say("→ твой шаг {$step['no']}.{$step['attempt']}: {$step['title']}");
    return cmdTask($f);
}

/** Взять задание. С --peek только посмотреть, не берясь за работу. */
function cmdTask(array $f): int
{
    $peek = !empty($f['peek']);
    $answer = call('GET', 'step.get', [
        'ranOn' => $f['ran-on'] ?? null,
        'peek'  => $peek ? 1 : null,
    ] + workerExactScope($f, !$peek), $f);

    if (!empty($f['raw'])) {
        say(json_encode($answer, JSON_UNESCAPED_UNICODE));
        return 0;
    }

    /* Печатник один на все пути. Старый sayTask() не знал ни образца ответа,
       ни критериев, ни пределов, и `goblin task` показывал меньше, чем
       `goblin work`, — хотя за ними стоит один и тот же step.get. */
    say('# Шаг ' . ($answer['address'] ?? '?') . ' · прогон ' . ($answer['run'] ?? '?'));
    sayPackageHead($answer, !empty($f['brief']));
    if (empty($f['brief'])) sayPackage($answer);
    return 0;
}

// ── По ходу работы ─────────────────────────────────────────────

function cmdNote(array $f): int
{
    $answer = call('POST', 'step.note', [
        'text' => arg($f), 'stage' => $f['stage'] ?? null,
    ] + workerExactScope($f, true), $f);
    // Молчание выглядело как «команда не сработала»: отвечаем строкой.
    say('✓ веха записана' . (!empty($f['stage']) ? " · {$f['stage']}" : ''));
    if (!empty($answer['stop'])) say('⚠ просьба остановиться');
    return 0;
}

/** Заявка во внешний сервис: заводится ДО обращения, закрывается сразу после. */
function cmdJob(array $f): int
{
    // Свои заявки видно: какая заведена, какая закрыта и с каким номером.
    if (arg($f) === 'list') {
        $jobs = peek($f)['jobs'] ?? [];
        if (!$jobs) {
            say('заявок по этому шагу нет');
            return 0;
        }
        foreach ($jobs as $job) {
            say(sprintf('%-4s %-10s %-26s %s', $job['ref'] ?? '?', $job['state'] ?? '?',
                (string) ($job['model'] ?? $job['tool'] ?? ''), (string) ($job['jobId'] ?? '')));
        }
        return 0;
    }

    if (arg($f) === 'add') {
        $request = [];
        if (!empty($f['request']) && is_string($f['request']) && is_file($f['request'])) {
            $request = json_decode((string) file_get_contents($f['request']), true) ?: [];
        }
        $answer = call('POST', 'step.job', [
            'action'   => 'add',
            'provider' => $f['provider'] ?? null,
            'tool'     => $f['tool'] ?? null,
            'model'    => $f['model'] ?? null,
            'request'  => $request,
            'paid'     => empty($f['free']),
        ], $f);
        say($answer['job']['ref']);
        return 0;
    }

    $answer = call('POST', 'step.job', [
        'action' => 'set',
        'ref'    => arg($f, 1),
        'jobId'  => $f['id'] ?? null,
        'state'  => $f['state'] ?? null,
        'error'  => $f['error'] ?? null,
    ], $f);
    say("✓ заявка {$answer['job']['ref']}: {$answer['job']['state']} {$answer['job']['jobId']}");
    return 0;
}


// ── Сдача ──────────────────────────────────────────────────────

/**
 * Сдать работу.
 *
 * Пути считаются от рабочей папки прогона, а не от каталога, из которого
 * позвали команду: иначе сдача из чужого места падала «файла нет», хотя
 * файл лежал где велено.
 */
function cmdSubmit(array $f): int
{
    $work  = workDir($f);
    $files = [];
    // Запоминаем фактический engine-2 scope до удаления step cache: после
    // submit следующий take должен остаться в том же прогоне.
    $submittedScope = stepCacheRecord($f);

    $items = $f['_'];
    if (isset($f['file']) && is_string($f['file'])) $items[] = $f['file'];

    foreach ($items as $item) {
        [$path, $output] = array_pad(explode('=', $item, 2), 2, '');
        $files[] = ['path' => wholePath($path, $work), 'output' => $output];
    }

    $answer = call('POST', 'step.submit', [
        'result'    => $f['result'] ?? null,
        'files'     => $files,
        'ranOn'     => $f['ran-on'] ?? null,
        'tokensIn'  => $f['tokens-in'] ?? null,
        'tokensOut' => $f['tokens-out'] ?? null,
    ] + workerExactScope($f, true), $f);

    foreach ($answer['warnings'] ?? [] as $warning) say("⚠ $warning");
    $step = $answer['step'];
    say("ok {$step['no']}.{$step['attempt']}");
    stepCacheForget($f);   // шаг сдан: пропуск больше не нужен
    // Про обычную сдачу leader писать не надо: круг видит её сам. Напоминаем
    // только там, где без слов не обойтись, — в падении и на последнем шаге
    // (последний шаг называет ТЗ).
    if (!empty($answer['warnings'])) remind($f, $step, 'сдал с оговорками');

    /* --next: два запроса подряд — сдать, затем взять следующий шаг. Это удобство
       утилиты, а не API: оборвётся между ними — сдача уже подтверждена, и работу
       возьмёт обычный `goblin work`. */
    if (!empty($f['next'])) {
        // Сдача и новая выдача сливались в один кусок текста: отделяем.
        say('');
        if (!serverHas('step.take')) return cmdWork($f);

        if ($submittedScope) {
            $agent = workerAgentToken($f);
            return workTake([
                '_'       => [],
                'id'      => (string) $submittedScope['run'],
                'token'   => $agent,
                'wait'    => $f['wait'] ?? null,
                'session' => $f['session'] ?? null,
                // Вид вывода — свойство вызова, а не шага: --brief и --raw
                // пропадали при --next, хотя круг worker идёт именно через него.
                'brief'   => $f['brief'] ?? null,
                'raw'     => $f['raw'] ?? null,
            ]);
        }
        return workTake([
            '_'       => [],
            'wait'    => $f['wait'] ?? null,
            'session' => $f['session'] ?? null,
            'brief'   => $f['brief'] ?? null,
            'raw'     => $f['raw'] ?? null,
        ]);
    }
    return 0;
}

function cmdFail(array $f): int
{
    peek($f);   // адрес leader спрашиваем заранее: после падения шаг закрыт
    $answer = call('POST', 'step.fail', [
        'reason'  => $f['reason'] ?? arg($f),
        'blocker' => !empty($f['blocker']) ? 1 : null,
    ] + workerExactScope($f, true), $f);

    $step = $answer['step'];
    say("fail {$step['no']}.{$step['attempt']}: " . ($step['error'] ?? ''));
    stepCacheForget($f);   // шаг закрыт: пропуск больше не нужен
    remind($f, $step, 'упал');
    return 0;
}

function wholePath(string $path, string $work): string
{
    if ($path === '' || $path[0] === '/' || file_exists($path) || $work === '') {
        return realpath($path) ?: $path;
    }
    $near = $work . '/' . $path;
    return realpath(file_exists($near) ? $near : $path) ?: $path;
}


// ── Пакет шага под рукой ───────────────────────────────────────

/** Пакет шага, не берясь за работу. За запуск спрашиваем один раз. */
function peek(array $f): array
{
    static $seen = [];
    $key = tokenOf($f) ?: '_';

    if (!isset($seen[$key])) {
        // Пакета может не быть вовсе (шаг закрыт, чужой пропуск) — это не беда.
        $seen[$key] = quiet(static fn() => call(
            'GET', 'step.get', ['peek' => 1] + workerExactScope($f, false), $f
        )) ?: [];
    }
    return $seen[$key];
}

function workDir(array $f): string
{
    return (string) (peek($f)['workDir'] ?? '');
}

/** Имя сессии leader из записки к шагу: строка «leader: goblin-3b». */
function leadName(array $f): string
{
    return leadNameFromNote((string) (peek($f)['note'] ?? ''));
}

function leadNameFromNote(string $note): string
{
    foreach (explode("\n", $note) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'leader:')) return trim(substr($line, strlen('leader:')));
    }
    return '';
}

/** Напомнить worker доложить: без доклада leader считает, что он работает. */
function remind(array $f, array $step, string $what): void
{
    $who = leadName($f);
    if ($who === '') return;
    say("→ доложи leader: SendMessage(to: \"$who\", "
        . "message: \"$what {$step['no']}.{$step['attempt']} — …\")");
}
