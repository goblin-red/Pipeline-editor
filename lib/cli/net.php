<?php
/* Разговор командной строки с сервером.
   Отдаёт: cfg(), call(), die_(), show(), flags(), arg(), tokenOf(), tokenPick(), runArgs(), runIdArg(),
           workerSession(), workerAgentToken(), commandId(), stepCache(), stepCacheKeep(), stepCacheForget() — состояние CLI.
   Не делает: ничего не знает про команды — только HTTP и разбор строки.

   Состояния у командной строки нет: всё живёт на сервере. Исключение одно —
   кэш пропуска шага, который взяла команда work (файл 0600 во временной папке,
   отдельный для URL + проекта + прогона + агента + экземпляра worker). */

declare(strict_types=1);

// Часы: php.ini в XAMPP стоит на своей зоне, а человек читает журнал по своим
// часам. Берём зону системы, если её видно.
date_default_timezone_set(systemZone() ?: date_default_timezone_get());

function systemZone(): string
{
    $link = @readlink('/etc/localtime');
    if (!is_string($link)) return '';
    return preg_match('~zoneinfo/(\w+/[\w/+-]+)$~', $link, $found) ? $found[1] : '';
}


// ── Окружение ──────────────────────────────────────────────────

/**
 * Настройки запуска: адрес, проект, пропуска. Читаются один раз.
 *
 * Со вторым доводом — правка на время процесса: ею `goblin.php` убирает
 * агентский пропуск у команд leader, чтобы смешанное окружение не уводило
 * запрос под чужой ролью. В файлы и окружение это не пишется.
 */
function cfg(?string $key = null, ?string $set = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'url'     => env('GOBLIN_URL', 'http://localhost/goblin/api.php'),
            'project' => env('GOBLIN_PROJECT'),
            'run'     => env('GOBLIN_RUN'),     // пропуск leader
            'step'    => env('GOBLIN_STEP'),    // пропуск шага
            'agent'   => env('GOBLIN_AGENT'),   // долгий пропуск агента
            'session' => env('GOBLIN_WORKER_SESSION'), // экземпляр worker engine 2
            'lead'    => env('GOBLIN_LEAD'),    // имя сессии leader
        ];
    }
    if ($key !== null && $set !== null) $cfg[$key] = $set;
    return $key === null ? $cfg : ($cfg[$key] ?? '');
}

function env(string $name, string $fallback = ''): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $fallback : $value;
}

/**
 * Пропуск для этой команды: флаг сильнее переменной окружения.
 *
 * Пропуск дают только флаги `--step`, `--run` и `--token` (долгий пропуск
 * агента). Флаг `--agent` — это всегда номер агента, а не пропуск: иначе
 * `goblin start --agent 18970` уходил на сервер с «пропуском» 18970.
 */
function tokenOf(array $f): string
{
    return tokenPick($f)[0];
}

/**
 * Пропуск и откуда он взят: флаг → GOBLIN_STEP → кэш шага → GOBLIN_AGENT → GOBLIN_RUN.
 *
 * Кэш — пропуск шага, который взяла команда work (step.take): submit, note, job
 * и fail идут отдельными процессами и узнают его отсюда. Кэш смотрим только
 * у worker с пропуском агента.
 */
function tokenPick(array $f): array
{
    // Явное «без пропуска»: читаем ключом проекта, как человек с холста.
    if (!empty($f['anon'])) return ['', 'anon'];
    foreach (['step', 'run', 'token'] as $kind) {
        if (!empty($f[$kind]) && is_string($f[$kind])) return [$f[$kind], 'flag'];
    }
    if (cfg('step') !== '') return [cfg('step'), 'env'];
    if (cfg('agent') !== '') {
        $kept = stepCache($f);
        return $kept !== '' ? [$kept, 'cache'] : [cfg('agent'), 'env'];
    }
    return [cfg('run'), 'env'];
}

/**
 * Чтение о прогоне, которое переживает конец прогона.
 *
 * Пройденный прогон гасит пропуск leader, а посмотреть журнал и итог нужно
 * именно после конца. Отказ по пропуску — не тупик: перечитываем ключом
 * проекта, как это делает человек с холста. Ярлык берём из --id или из
 * GOBLIN_RUN_LABEL; без него сказать серверу, о каком прогоне речь, нечем.
 */
function askAboutRun(string $op, array $args, array $f, int $timeout = 30): array
{
    $a = quiet(static fn() => call('GET', $op, $args, $f, $timeout));
    if ($a !== null) return $a;

    $why   = lastFail()->why;
    $sorry = lastFail()->getMessage();
    $label = runIdArg($f);

    if (in_array($why, ['unauthorized', 'scope'], true) && $label !== null) {
        $byKey = quiet(static fn() => call('GET', $op, ['run' => $label] + $args, ['anon' => true], $timeout));
        if ($byKey !== null) return $byKey;
    }
    die_($sorry, $why ?: 'server', $why === 'network' ? 2 : 1);
}

/** Постоянный id одного экземпляра worker: флаг сильнее окружения. */
function workerSession(array $f = [], bool $required = false): string
{
    $session = is_string($f['session'] ?? null) ? trim($f['session']) : trim((string) cfg('session'));
    if ($session !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $session)) {
        die_('session: 8–120 знаков A–Z, a–z, 0–9, точка, подчёркивание, двоеточие или дефис');
    }
    if ($required && $session === '') {
        die_('для нового движка задай один id экземпляра и сохраняй его между вызовами: '
            . 'export GOBLIN_WORKER_SESSION=$(openssl rand -hex 16)', 'worker_session_required', 3);
    }
    return $session;
}

/** Устойчивый ключ одной логической команды на время процесса. */
function commandId(array $f, string $op, array $args = []): string
{
    if (is_string($f['command-id'] ?? null) && trim($f['command-id']) !== '') {
        return substr(trim($f['command-id']), 0, 120);
    }
    static $made = [];
    $key = $op . '|' . json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $made[$key] ??= 'cli-' . bin2hex(random_bytes(16));
}

/** Старый кэш engine 1: сохраняем до снятия legacy-пути. */
function stepCacheLegacyFile(): string
{
    $key = substr(hash('sha256', cfg('project') . '|' . cfg('agent')), 0, 16);
    return rtrim(sys_get_temp_dir(), '/') . "/goblin-$key.step";
}

/** Префикс кэша engine 2; run входит в имя файла, остальной scope — в хеш. */
function stepCachePrefix(array $f = []): string
{
    $session = workerSession($f);
    if ($session === '') return '';
    $scope = implode('|', [cfg('url'), cfg('project'), hash('sha256', workerAgentToken($f)), $session]);
    return rtrim(sys_get_temp_dir(), '/') . '/goblin2-' . substr(hash('sha256', $scope), 0, 24) . '-';
}

/** Agent identity для cache scope: явный long token либо GOBLIN_AGENT. */
function workerAgentToken(array $f = []): string
{
    $explicit = is_string($f['token'] ?? null) ? trim($f['token']) : '';
    return str_starts_with($explicit, 'gba_') ? $explicit : (string) cfg('agent');
}

/** Запись кэша engine 2 или null. Секрет никогда не печатается. */
function stepCacheRecord(array $f = []): ?array
{
    $prefix = stepCachePrefix($f);
    if ($prefix === '') return null;
    $files = glob($prefix . '*.step') ?: [];
    if (isset($f['id']) && is_string($f['id']) && ctype_digit(trim($f['id']))) {
        $asked = trim($f['id']);
        $files = array_values(array_filter($files, static fn($file) => str_ends_with($file, '-' . $asked . '.step')));
    }
    if (!$files) return null;
    if (count($files) > 1) {
        die_('у worker session несколько активных прогонов — укажи --id rN', 'cache_conflict');
    }
    $one = json_decode((string) @file_get_contents($files[0]), true);
    if (!is_array($one) || empty($one['token']) || ($one['scope'] ?? '') !== hash('sha256', implode('|', [
        cfg('url'), cfg('project'), hash('sha256', workerAgentToken($f)), workerSession($f),
    ]))) return null;
    $one['_file'] = $files[0];
    return $one;
}

/** Пропуск шага из кэша или пустая строка. */
function stepCache(array $f = []): string
{
    if (workerSession($f) === '') {
        $file = stepCacheLegacyFile();
        return is_file($file) ? trim((string) @file_get_contents($file)) : '';
    }
    return (string) (stepCacheRecord($f)['token'] ?? '');
}

/** Запомнить пропуск шага: файл только для владельца (0600). */
function stepCacheKeep(string $token, array $meta = [], array $f = []): void
{
    $session = workerSession($f);
    if ($session === '') {
        $file = stepCacheLegacyFile();
        $body = $token;
    } else {
        $run = (int) ($meta['run'] ?? 0);
        if (!$run) die_('сервер выдал шаг без номера прогона — кэшировать секрет небезопасно', 'bad_contract');
        $prefix = stepCachePrefix($f);
        foreach (glob($prefix . '*.step') ?: [] as $old) @unlink($old);
        $file = $prefix . $run . '.step';
        $body = json_encode([
            'scope' => hash('sha256', implode('|', [cfg('url'), cfg('project'), hash('sha256', workerAgentToken($f)), $session])),
            'run' => $run, 'agent' => (int) ($meta['agent'] ?? 0), 'step' => (int) ($meta['step'] ?? 0),
            'token' => $token,
        ], JSON_UNESCAPED_SLASHES);
    }
    $mask = umask(0077);
    @file_put_contents($file, $body, LOCK_EX);
    umask($mask);
    @chmod($file, 0600);
}

function stepCacheForget(array $f = []): void
{
    if (workerSession($f) === '') {
        @unlink(stepCacheLegacyFile());
        return;
    }
    $one = stepCacheRecord($f);
    if ($one && !empty($one['_file'])) @unlink($one['_file']);
}


// ── Запрос ─────────────────────────────────────────────────────

/**
 * Позвать операцию сервера.
 *
 * GET — параметрами в строке запроса, POST — телом JSON. Ошибка сервера
 * печатается по-человечески и прекращает работу: командная строка не
 * продолжает круг на сломанном ответе.
 */
function call(string $method, string $op, array $args = [], array $f = [], int $timeout = 30): array
{
    [$token, $from] = tokenPick($f);
    $args  = array_filter($args, static fn($v) => $v !== null && $v !== false);
    $args['project'] = cfg('project');
    if (!empty($GLOBALS['goblinScript'])) $args['by'] = 'script';

    $headers = "Accept: application/json\r\n";
    if ($token !== '') $headers .= "X-Goblin-Token: $token\r\n";

    if ($method === 'GET') {
        $flat  = array_filter($args, static fn($v) => !is_array($v));
        $where = cfg('url') . '?' . http_build_query(['op' => $op] + $flat);
        $http  = ['method' => 'GET'];
    } else {
        $where = cfg('url');
        $headers .= "Content-Type: application/json\r\n";
        $http = [
            'method'  => 'POST',
            'content' => json_encode(['op' => $op] + $args, JSON_UNESCAPED_UNICODE),
        ];
    }

    $context = stream_context_create(['http' => $http + [
        'header'        => $headers,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);

    // Повторяем только подтверждённые пути: run.start/step.open с receipt commandId,
    // а step.take — как session-aware retake той же попытки.
    $receiptRetry = in_array($op, ['run.start', 'step.open'], true) && !empty($args['commandId']);
    $sessionRetake = $op === 'step.take' && !empty($args['session']);
    $tries = $method === 'POST' && ($receiptRetry || $sessionRetake) ? 2 : 1;
    $body = false;
    for ($try = 0; $try < $tries && $body === false; $try++) {
        $body = @file_get_contents($where, false, $context);
        if ($body === false && $try + 1 < $tries) usleep(150000);
    }
    // Сервер недоступен — код 2: так утилита отличает обрыв от отказа (md_backend/ru/progon.md).
    if ($body === false) die_("сервер недоступен: " . cfg('url'), 'network', 2);

    $data = json_decode($body, true);
    if (!is_array($data)) die_("сервер ответил не по-человечески: " . substr((string) $body, 0, 200));

    if (empty($data['ok'])) {
        $code = (string) ($data['code2'] ?? $data['code'] ?? '');

        // Команда уже зафиксирована, но одноразовый секрет нельзя повторить.
        // Это не приглашение повторять создание: вызывающий либо восстанавливает
        // доступ по безопасному receipt, либо просит человека сделать это явно.
        if ($code === 'replay_secret_unavailable' && !empty($data['committed'])) {
            $fail = new CommittedReplay((string) ($data['error'] ?? 'команда выполнена, секрет не воспроизводится'), $data);
            if (soft()) throw $fail;
            fwrite(STDERR, '✗ ' . committedReplayMessage($fail) . "  [replay_secret_unavailable]\n");
            exit(3);
        }

        // Пропуск из кэша погас — шаг закрыт: кэш стираем и говорим worker, что делать.
        if ($from === 'cache' && in_array($code, ['step_closed', 'unauthorized'], true)) {
            stepCacheForget($f);
            die_('шаг закрыт — зови goblin work (' . ($data['error'] ?? '') . ')', $code);
        }
        die_((string) ($data['error'] ?? 'ошибка'), $code);
    }
    return $data;
}


// ── Вывод ──────────────────────────────────────────────────────

/** Отказ сервера в мягком режиме: причина остаётся в свойстве why. */
class ApiFail extends RuntimeException
{
    public function __construct(string $message, public string $why = '')
    {
        parent::__construct($message);
    }
}

/** Команда зафиксирована, но ответ содержал невоспроизводимый секрет. */
class CommittedReplay extends ApiFail
{
    public function __construct(string $message, public array $response)
    {
        parent::__construct($message, 'replay_secret_unavailable');
    }
}

function committedReplayMessage(CommittedReplay $fail): string
{
    $recovery = is_array($fail->response['recovery'] ?? null) ? $fail->response['recovery'] : [];
    $where = array_filter([
        !empty($recovery['op']) ? 'операция ' . $recovery['op'] : null,
        !empty($recovery['run']) ? 'прогон r' . $recovery['run'] : null,
        !empty($recovery['step']) ? 'шаг ' . $recovery['step'] : null,
    ]);
    return 'команда выполнена, секрет не воспроизводится'
        . ($where ? '; восстановить доступ: ' . implode(', ', $where) : '; нужно восстановление доступа');
}

/** Отказ: напечатать и выйти с кодом (1 — отказ, 2 — сервер недоступен). */
function die_(string $message, string $code = '', int $exit = 1): void
{
    // В мягком режиме отказ сервера — не конец работы, а обычное исключение.
    if (soft()) throw new ApiFail($message, $code);
    fwrite(STDERR, "✗ $message" . ($code !== '' ? "  [$code]" : '') . "\n");
    exit($exit);
}

/** Мягкий режим: включён — отказ бросается исключением, а не убивает команду. */
function soft(?bool $on = null): bool
{
    static $soft = false;
    if ($on !== null) $soft = $on;
    return $soft;
}

/** Спросить сервер, не прекращая работу при отказе. Не вышло — null. */
function quiet(callable $what)
{
    soft(true);
    lastFail(new ApiFail(''));
    try {
        return $what();
    } catch (ApiFail $fail) {
        lastFail($fail);
        return null;
    } finally {
        soft(false);
    }
}

/** Причина последнего мягкого отказа: нужна тому, кто решает, что делать. */
function lastFail(?ApiFail $fail = null): ApiFail
{
    static $last = null;
    if ($fail !== null) $last = $fail;
    return $last ?? new ApiFail('');
}

function say(string $line = ''): void
{
    // Через @: вывод часто читают через head или grep, и закрытая труба —
    // это не ошибка команды.
    @fwrite(STDOUT, $line . "\n");
}

function show(array $data): void
{
    say(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}


/**
 * Событие в ленту прогона.
 *
 * Тихое: лента — дело полезное, но падать из-за неё команда не должна.
 */
/* Круг и сторож ходят с пропуском leader, но пишет-то их скрипт.
   Пометка `by=script` нужна, чтобы в журнале было видно, где работал
   живой leader, а где сам собой крутился `goblin drive`. */
function asScript(): void
{
    $GLOBALS['goblinScript'] = true;
}

function tell(array $f, string $kind, string $title, $body = null, array $more = []): void
{
    quiet(static fn() => call('POST', 'run.event', runArgs($f) + array_filter([
        'kind'  => $kind,
        'title' => $title,
        'body'  => is_string($body) || $body === null ? $body : json_encode($body, JSON_UNESCAPED_UNICODE),
        'no'    => $more['no'] ?? null,
        'meta'  => $more['meta'] ?? null,
        'tookMs'=> $more['tookMs'] ?? null,
    ], static fn($v) => $v !== null), $f));
}


// ── Журнал прогона ─────────────────────────────────────────────

/**
 * Строка в журнал прогона: `<рабочая папка>/service/прогон.log`.
 *
 * Сюда пишут и круг leader, и сторож: человеку нужна одна картина, а не
 * два разных файла. Рабочей папки нет — молча ничего не пишем.
 */
function journalLine(string $workDir, string $text): void
{
    if (trim($workDir) === '') return;
    $dir = rtrim($workDir, '/') . '/service';
    @mkdir($dir, 0777, true);
    @file_put_contents($dir . '/прогон.log', date('H:i:s') . '  ' . $text . "\n", FILE_APPEND);
}


// ── Разбор строки ──────────────────────────────────────────────

/** --ключ значение, --ключ=значение и --флаг → массив; всё прочее — в ключ '_'. */
function flags(array $argv): array
{
    // `--ключ=значение` разворачиваем в два слова: дальше разбор один на оба вида.
    $words = [];
    foreach ($argv as $one) {
        if (preg_match('/^--[^=\s]+=/', (string) $one)) {
            [$name, $value] = explode('=', (string) $one, 2);
            $words[] = $name;
            $words[] = $value;
            continue;
        }
        $words[] = $one;
    }
    $argv = $words;

    $out = ['_' => []];
    for ($i = 0; $i < count($argv); $i++) {
        $word = $argv[$i];
        if (!str_starts_with($word, '--')) {
            $out['_'][] = $word;
            continue;
        }
        $name = substr($word, 2);
        $next = $argv[$i + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $out[$name] = $next;
            $i++;
        } else {
            $out[$name] = true;
        }
    }
    return $out;
}

/** Слово без ключа: `goblin open 14` → arg($f) === '14'. */
function arg(array $f, int $n = 0): ?string
{
    return $f['_'][$n] ?? null;
}

/**
 * Какой прогон имеется в виду.
 *
 * Пропуск leader сам указывает на свой прогон. Человеку пропуска не нужно:
 * он называет прогон номером — `--id 49` — или ярлыком с холста — `--id r53`.
 */
function runArgs(array $f): array
{
    $id = runIdArg($f);
    return $id !== null ? ['run' => $id] : [];
}

/**
 * Прогон из флага `--id`: номер или ярлык `rN`. Флага нет — null.
 *
 * Мусор — ошибка, а не «весь проект»: раньше `(int) 'r53'` давал 0,
 * и сторож молча уходил смотреть за всеми прогонами.
 */
function runIdArg(array $f): ?string
{
    /* Ярлык из окружения — запасной, а не главный: явный --id всегда сильнее,
       и названный прогон утилита не подменяет (md_backend/ru/progon.md §15). */
    if (!array_key_exists('id', $f)) {
        $label = strtolower(trim((string) env('GOBLIN_RUN_LABEL')));
        return preg_match('/^r?[1-9]\d*$/', $label) ? $label : null;
    }

    $id = is_string($f['id']) ? strtolower(trim($f['id'])) : '';
    if (!preg_match('/^r?[1-9]\d*$/', $id)) {
        die_('--id: номер прогона или ярлык rN, а не «' . (is_string($f['id']) ? $f['id'] : '') . '»');
    }
    return $id;
}

/** Число из флага с запасным значением. */
function num(array $f, string $key, int $fallback): int
{
    $value = $f[$key] ?? null;
    return (is_string($value) && $value !== '') ? (int) $value : $fallback;
}
