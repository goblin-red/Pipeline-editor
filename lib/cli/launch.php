<?php
/* Запуск worker: собрать пакет задания и отдать его исполнителю.
   Отдаёт: cmdLaunch().
   Не делает: не решает, кто worker — агента и его CLI выбирает схема.

   Режимы:
     cli       — leader сам запускает чужой CLI строкой из config.txt;
     subagent  — печатается готовое сообщение для уже открытого терминала;
     terminal  — то же самое (имя оставлено для привычки). */

declare(strict_types=1);

function cmdLaunch(array $f): int
{
    // Пропуск шага уже на руках — шаг не выдаём заново.
    if (!empty($f['token']) && is_string($f['token'])) {
        $token = $f['token'];
        $step  = ['no' => arg($f), 'attempt' => 1];
    } else {
        /* Новый движок выдаёт готовый блок сам, ещё до launch: тогда open отвечает
           «уже открыт», и мы берём пропуск выданной попытки перевыдачей. Попытка та же. */
        $answer = quiet(static fn() => openStep($f));
        $again  = $answer === null && lastFail()->why === 'already_open';
        if ($again) {
            // Шаг уже в работе — перевыдача отобрала бы его у живого worker. Только с --force.
            $state = call('GET', 'run.state', runArgs($f), $f);
            foreach ($state['open'] ?? [] as $one) {
                if ((string) $one['no'] !== (string) arg($f) || ($one['state'] ?? '') === 'issued') continue;
                if (empty($f['force'])) {
                    die_("шаг {$one['no']}.{$one['attempt']} уже {$one['state']}: его держит worker. "
                        . 'Отобрать — goblin reissue ' . $one['no'] . ' или launch --force', 'step_busy');
                }
            }
            $answer = call('POST', 'step.reissue', runArgs($f) + ['no' => arg($f)], $f);
        } elseif ($answer === null) {
            die_(lastFail()->getMessage(), lastFail()->why);
        }
        $token  = (string) $answer['token'];
        $step   = $answer['step'];
        say("✓ шаг {$step['no']}.{$step['attempt']} " . ($again ? 'уже выдан движком — взят его пропуск' : 'выдан'));
    }

    // Пакет собираем с --peek: «в работе» шаг сделает сам worker, когда возьмётся.
    $data = call('GET', 'step.get', ['peek' => 1], ['step' => $token]);
    $path = writePackage($data, $step, $token);
    say("✓ пакет: $path");

    $mode = is_string($f['mode'] ?? null) ? $f['mode'] : 'cli';
    return match ($mode) {
        'cli'                  => launchCli($f, $data, $step, $token, $path),
        'subagent', 'terminal' => launchMessage($data, $step, $token, $path),
        'orca'                 => softDie('режим orca ещё не сделан: пока «cli» или «subagent»'),
        default                => softDie("неизвестный режим запуска: $mode"),
    };
}

/**
 * Файл задания. Место называет сервер: `packageFile` — storage/runs/<id прогона>/, вне public/
 * и вне рабочей папки, имя с id шага — два прогона одной схемы друг друга не затирают.
 * Старый сервер места не называет — тогда по-прежнему service/steps/14.2.md в рабочей папке.
 */
function writePackage(array $data, array $step, string $token): string
{
    $path = (string) ($data['packageFile'] ?? '');
    if ($path === '') {
        $path = (string) ($data['workDir'] ?? '.') . "/service/steps/{$step['no']}.{$step['attempt']}.md";
    }
    @mkdir(dirname($path), 0775, true);
    // Служебное закрываем от браузера: рабочая папка бывает и под public/.
    $deny = dirname($path, 2) . '/.htaccess';
    if (is_dir(dirname($deny)) && !is_file($deny)) {
        @file_put_contents($deny,
            "# Служебное прогона (пакеты, журналы) — только для сервера и CLI, не для браузера.\n"
            . "Require all denied\n");
    }

    $text  = "# Задание {$step['no']}.{$step['attempt']}\n\n";
    $text .= 'Инструкция worker: ' . cfg('url') . "?op=docs.get&file=worker\n\n";
    if (!empty($data['spec'])) {
        $text .= "## ТЗ (дословно из схемы)\n\n" . $data['spec']['text'] . "\n\n";
    }
    $text .= "## Пакет\n\n```json\n"
           . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
           . "\n```\n";

    file_put_contents($path, $text);
    return $path;
}

/**
 * Значение, которое можно подставить в строку запуска.
 *
 * Строку пишет админ в `config.txt`, а подстановки приходят из схемы и карточки агента —
 * их правит любой, кто пишет в проект. Кавычки вокруг `{пакет}` в шаблоне не дают
 * применить escapeshellarg, поэтому опасные знаки просто запрещены.
 */
function shellValue(string $value, string $what): string
{
    if ($value === '') return '';
    // Обратная косая, кавычки и перевод строки — тоже знаки оболочки.
    $bad = '`$;&|<>()*?!' . '\\' . '"' . "'\n\r";
    if (strpbrk($value, $bad) !== false) {
        softDie("в значении «{$what}» есть знаки оболочки: " . $value);
    }
    return $value;
}

/** Запустить чужой CLI самому. Строка запуска — четвёртое поле строки [cli]. */
function launchCli(array $f, array $data, array $step, string $token, string $path): int
{
    $line = is_string($f['cmd'] ?? null) ? $f['cmd'] : cliCommand($data);
    if ($line === '') {
        say('в config.txt у этого CLI нет строки запуска — вот команда, запусти сам:');
        say('  GOBLIN_URL=' . cfg('url') . ' GOBLIN_PROJECT=' . cfg('project')
            . " GOBLIN_STEP=$token <твой CLI> «прочитай $path и работай»");
        return 0;
    }

    $agent = $data['agent'] ?? [];
    $line  = str_replace(
        ['{пакет}', '{шаг}', '{папка}', '{модель}', '{усилие}'],
        [
            shellValue($path, 'пакет'),
            shellValue("{$step['no']}.{$step['attempt']}", 'шаг'),
            shellValue((string) ($data['workDir'] ?? '.'), 'папка'),
            shellValue((string) ($agent['model'] ?? ''), 'модель'),
            shellValue((string) ($agent['effort'] ?? ''), 'усилие'),
        ],
        $line
    );

    // Окружение worker: адрес, проект и пропуск шага.
    $env = 'GOBLIN_URL=' . escapeshellarg(cfg('url'))
         . ' GOBLIN_PROJECT=' . escapeshellarg(cfg('project'))
         . ' GOBLIN_STEP=' . escapeshellarg($token);
    $where = (string) ($data['workDir'] ?? '');
    $cd    = $where !== '' ? 'cd ' . escapeshellarg($where) . ' && ' : '';

    say("→ запускаю worker: $line");
    tell($f, 'launch', "запущен worker: $line", "рабочая папка: $where", ['no' => (string) $step['no']]);

    if (!empty($f['wait'])) {
        $code = 0;
        $began = microtime(true);
        ob_start();
        passthru("$cd$env $line", $code);
        $out = (string) ob_get_clean();
        echo $out;

        say("worker завершился с кодом $code");
        tell($f, 'console', 'вывод терминала · ' . round(strlen($out) / 1024, 1) . ' КБ', $out, [
            'no' => (string) $step['no'],
            'tookMs' => (string) round((microtime(true) - $began) * 1000),
            'meta' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
        ]);
        return $code;
    }

    // В фоне: вывод worker ложится рядом с пакетом задания, чтобы leader мог посмотреть.
    $log = preg_replace('/\.md$/', '', $path) . '.log';
    exec("$cd$env nohup $line > " . escapeshellarg($log) . " 2>&1 &");
    say("worker работает в фоне, вывод: $log");
    say('следи за `goblin next` или запусти сторожа: goblin watch --quiet &');
    return 0;
}

/** Готовое сообщение worker, который уже сидит в открытом терминале. */
function launchMessage(array $data, array $step, string $token, string $path): int
{
    say('--- скопируй это worker ---');
    say("Задание {$step['no']}.{$step['attempt']}. Инструкция worker: instructions/old/worker.md");
    say("Пакет задания: $path");
    say('Рабочая папка: ' . ($data['workDir'] ?? '—'));
    say('Окружение:');
    say('  export GOBLIN_URL=' . cfg('url'));
    say('  export GOBLIN_PROJECT=' . cfg('project'));
    say("  export GOBLIN_STEP=$token");
    say('Порядок: goblin task → работа по ТЗ → goblin submit --result "…".');
    say("Отпишись строкой: ok {$step['no']}.{$step['attempt']} или fail {$step['no']}.{$step['attempt']}: причина");
    say('--- конец сообщения ---');
    return 0;
}

/** Строка запуска для CLI этого агента: раздел [cli] в config.txt. */
function cliCommand(array $data): string
{
    $key = (string) (($data['agent'] ?? [])['cli'] ?? '');
    if ($key === '') return '';

    $lists = call('GET', 'config.get')['lists'] ?? [];
    foreach ($lists['cli'] ?? [] as $row) {
        if (($row['key'] ?? '') === $key) return (string) ($row['run'] ?? '');
    }
    return '';
}

function softDie(string $why): int
{
    die_($why);
    return 1;
}
