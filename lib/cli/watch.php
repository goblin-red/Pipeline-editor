<?php
/* Сторож прогонов: молчит, пока всё движется, и завершается, когда нужен leader.
   Отдаёт: cmdWatch().
   Не делает: ничего не меняет — только читает run.get и пишет журнал.

   Без --run, --id и --folder смотрит за всеми живыми прогонами проекта:
   один процесс на проект, а не по процессу на прогон.

   Завершение — это и есть будильник: leader запускает сторожа фоновой
   задачей, а оболочка отдаёт ему последнюю строку, когда процесс кончился.

   Коды выхода: 0 конец прогона · 2 сервер молчит · 3 ждёт приёмки
                4 шаг завис · 5 сбой шага · 6 прогон стоит без шагов. */

declare(strict_types=1);

function cmdWatch(array $f): int
{
    asScript();
    watchFlags($f);
    $every   = max(1,  num($f, 'every',   5));    // как часто спрашивать
    $timeout = max(30, num($f, 'timeout', 600));  // сколько ждать работу
    $accept  = max(10, num($f, 'accept',  20));   // сколько ждать приёмку
    $idle    = max(30, num($f, 'idle',    120));  // сколько терпеть тишину

    $log = is_string($f['log'] ?? null)
        ? $f['log']
        : dirname(__DIR__, 2) . '/data/сторож/' . date('Y-m-d') . '.log';
    @mkdir(dirname($log), 0777, true);
    $quiet = !empty($f['quiet']);

    // Прогон: пропуском, номером или ярлыком (--id 49, --id r53), папкой — живой найдётся сам.
    // Мусор в --id останавливает команду в runIdArg(), а не уводит на весь проект.
    $runAsk = runIdArg($f) ?? '';
    if ($runAsk === '' && !empty($f['folder'])) {
        $found = liveRun($f);
        if ($found === 0) return watchBye($log, $quiet, 1, "в папке {$f['folder']} нет идущего прогона");
        $runAsk = (string) $found;
    }
    // Прогон не назвали и пропуска нет — смотрим за всем проектом.
    $whole = $runAsk === '' && tokenOf($f) === '';

    watchLog($log, $quiet, "сторож встал: работа {$timeout}с, приёмка {$accept}с, тишина {$idle}с");

    $seen       = [];    // шаг → [состояние, когда заметили]
    $misses     = 0;     // сколько раз подряд сервер не ответил
    $quietSince = null;  // с какой секунды нет живых шагов

    while (true) {
        if ($whole) {
            $live = liveRuns($f);
            if ($live === []) {
                return watchBye($log, $quiet, 0, 'живых прогонов в проекте не осталось');
            }
            $trouble = null;
            foreach ($live as $id) {
                $trouble = watchRun($f, (int) $id, $timeout, $accept, $idle, $seen, $quietSince, $log, $quiet);
                if ($trouble !== null) return watchBye($log, $quiet, $trouble[0], $trouble[1]);
            }
            sleep($every);
            continue;
        }

        $data = quiet(static fn() => call('GET', 'run.get', $runAsk !== '' ? ['run' => $runAsk] : [], $f, 20));

        // Назвали прогон, а сервер вернул список прогонов — такого прогона нет.
        if (is_array($data) && $runAsk !== '' && !isset($data['run'])) {
            return watchBye($log, $quiet, 1, "прогона $runAsk в проекте нет");
        }

        if (!is_array($data)) {
            // Прогон закрыли — его пропуск сразу отзывают. Это конец работы,
            // а не сбой сети: будить leader тревогой незачем.
            $why = lastFail()->why;
            if (in_array($why, ['unauthorized', 'not_found'], true)) {
                return watchBye($log, $quiet, 0, 'прогон закрыт: ' . lastFail()->getMessage());
            }
            if (++$misses >= 5) return watchBye($log, $quiet, 2, 'сервер не ответил пять раз подряд');
            sleep($every);
            continue;
        }
        $misses = 0;

        $run   = $data['run'] ?? [];
        $label = (string) ($run['label'] ?? '?');
        $state = (string) ($run['state'] ?? '?');

        if (!in_array($state, ['running', 'paused'], true)) {
            return watchBye($log, $quiet, 0, "прогон $label закончился: $state");
        }
        // Пауза — осознанное решение человека, а не залипание.
        if ($state === 'paused') {
            sleep($every);
            continue;
        }

        $now   = time();
        $clock = isset($data['now']) ? (strtotime((string) $data['now']) ?: $now) : $now;
        $alive = 0;

        foreach ($data['steps'] ?? [] as $step) {
            $id    = (int)    ($step['id']    ?? 0);
            $where = (string) ($step['state'] ?? '');
            $name  = stepName($run, $step);

            if (!isset($seen[$id]) || $seen[$id][0] !== $where) {
                $seen[$id] = [$where, $now];
                watchLog($log, $quiet, "$name → $where");
            }

            // Сколько шаг в этом состоянии: по часам сервера, если он их дал.
            $stamp   = stampOf($step, $where);
            $waiting = $stamp !== null ? max(0, $clock - $stamp) : $now - $seen[$id][1];

            switch ($where) {
                case 'issued':
                case 'running':
                    $alive++;
                    if ($waiting > $timeout) {
                        return watchBye($log, $quiet, 4, "$name висит «{$where}» уже " . howLong($waiting) . " — worker молчит");
                    }
                    break;

                case 'submitted':
                    $alive++;
                    if ($waiting > $accept) {
                        return watchBye($log, $quiet, 3, "$name сдан " . howLong($waiting) . ' назад и ждёт приёмки');
                    }
                    break;

                case 'failed':
                case 'returned':
                    return watchBye($log, $quiet, 5, "$name в состоянии «{$where}» — нужно решение leader");
            }
        }

        // Никто не работает, а прогон идёт: leader не открыл следующий шаг.
        if ($alive === 0) {
            $quietSince ??= $now;
            if ($now - $quietSince > $idle) {
                return watchBye($log, $quiet, 6, "прогон $label стоит " . howLong($now - $quietSince) . ': ни одного открытого шага');
            }
        } else {
            $quietSince = null;
        }

        sleep($every);
    }
}

/** Номера живых прогонов проекта. */
function liveRuns(array $f): array
{
    $data = quiet(static fn() => call('GET', 'run.get', [], $f, 20));
    $out = [];
    foreach (($data['runs'] ?? []) as $run) {
        if (in_array($run['state'] ?? '', ['running', 'paused'], true)) $out[] = (int) $run['id'];
    }
    return $out;
}

/**
 * Разобрать один прогон в режиме «весь проект».
 *
 * Вернёт [код, причина], если пора будить leader, и null, если всё идёт.
 */
function watchRun(array $f, int $runId, int $timeout, int $accept, int $idle,
                  array &$seen, ?int &$quietSince, string $log, bool $quiet): ?array
{
    $data = quiet(static fn() => call('GET', 'run.get', ['run' => (string) $runId], $f, 20));
    if (!is_array($data)) return null;
    watchSnap($data);

    $run   = $data['run'] ?? [];
    $state = (string) ($run['state'] ?? '');
    if ($state === 'paused') return null;

    $now   = time();
    $clock = isset($data['now']) ? (strtotime((string) $data['now']) ?: $now) : $now;
    $alive = 0;

    foreach ($data['steps'] ?? [] as $step) {
        $id    = (int)    ($step['id']    ?? 0);
        $where = (string) ($step['state'] ?? '');
        $name  = stepName($run, $step);

        if (!isset($seen[$id]) || $seen[$id][0] !== $where) {
            $seen[$id] = [$where, $now];
            watchLog($log, $quiet, "$name → $where");
        }
        $stamp   = stampOf($step, $where);
        $waiting = $stamp !== null ? max(0, $clock - $stamp) : $now - $seen[$id][1];

        switch ($where) {
            case 'issued':
            case 'running':
                $alive++;
                if ($waiting > $timeout) return [4, "$name висит «{$where}» уже " . howLong($waiting) . ' — worker молчит'];
                break;
            case 'submitted':
                $alive++;
                if ($waiting > $accept) return [3, "$name сдан " . howLong($waiting) . ' назад и ждёт приёмки'];
                break;
            case 'failed':
            case 'returned':
                return [5, "$name в состоянии «{$where}» — нужно решение leader"];
        }
    }

    $key = 'тишина-' . $runId;
    if ($alive === 0) {
        $seen[$key] ??= ['', $now];
        if ($now - $seen[$key][1] > $idle) {
            return [6, ($run['label'] ?? "r$runId") . ' стоит ' . howLong($now - $seen[$key][1]) . ': ни одного открытого шага'];
        }
    } else {
        unset($seen[$key]);
    }
    return null;
}

/** Номер идущего или приостановленного прогона папки. */
function liveRun(array $f): int
{
    $data = quiet(static fn() => call('GET', 'run.get', ['folder' => $f['folder']], $f, 20));
    foreach (($data['runs'] ?? []) as $run) {
        if (in_array($run['state'] ?? '', ['running', 'paused'], true)) return (int) $run['id'];
    }
    return 0;
}

/** Отметка сервера о входе шага в это состояние. */
function stampOf(array $step, string $where): ?int
{
    $field = match ($where) {
        'issued'    => 'opened',
        'running'   => 'started',
        'submitted' => 'sent',
        default     => null,
    };
    if ($field === null || empty($step[$field])) return null;
    return strtotime((string) $step[$field]) ?: null;
}

/** Адрес шага так, как его видит человек: r11 · 25.1 «Завести счётчик». */
function stepName(array $run, array $step): string
{
    $title = (string) ($step['title'] ?? '');
    return ($run['label'] ?? 'r?') . " · {$step['no']}.{$step['attempt']}"
        . ($title !== '' ? " «{$title}»" : '');
}

function howLong(int $seconds): string
{
    return $seconds < 90 ? $seconds . 'с' : intdiv($seconds, 60) . 'м ' . ($seconds % 60) . 'с';
}

/** Флаги команды: нужны там, где событие пишется без них под рукой. */
function watchFlags(?array $f = null): array
{
    static $kept = [];
    if ($f !== null) $kept = $f;
    return $kept;
}

/** Рабочие папки прогонов, за которыми смотрим: туда идёт журнал прогона. */
function watchDirs(?string $add = null): array
{
    static $dirs = [];
    if ($add !== null && $add !== '' && !in_array($add, $dirs, true)) $dirs[] = $add;
    return $dirs;
}

/** Последняя картина: что где висит. Хранится между кругами опроса. */
function watchPicture(?array $lines = null): array
{
    static $last = [];
    if ($lines !== null) $last = $lines;
    return $last;
}

/** Собрать картину по ответу сервера: только то, что сейчас в движении. */
function watchSnap(array $data): void
{
    $run = $data['run'] ?? [];
    watchDirs((string) ($run['workDir'] ?? ''));
    $lines = [];
    foreach ($data['steps'] ?? [] as $step) {
        if (!in_array($step['state'] ?? '', ['issued', 'running', 'submitted', 'failed', 'returned'], true)) continue;
        $said = (string) ($step['result'] ?? $step['error'] ?? '');
        $lines[] = '   ' . stepName($run, $step) . ' — ' . $step['state']
            . ($said !== '' ? ' · ' . mb_substr($said, 0, 60) : '');
    }
    $was = watchPicture();
    $key = (string) ($run['label'] ?? '?');
    $was[$key] = $lines ? implode("\n", $lines) : '   всё закрыто, открытых шагов нет';
    watchPicture($was);
}

/** Строка в свой журнал и на экран; в журнал прогона — через watchTold(). */
function watchLog(string $log, bool $quiet, string $text): void
{
    $line = date('H:i:s') . '  ' . $text;
    @file_put_contents($log, $line . "\n", FILE_APPEND);
    if (!$quiet) fwrite(STDERR, $line . "\n");
}

/** То же самое, но ещё и в журнал прогона: там человек смотрит одну картину. */
function watchTold(string $log, bool $quiet, array $run, string $text): void
{
    watchLog($log, $quiet, $text);
    journalLine((string) ($run['workDir'] ?? ''), 'сторож: ' . $text);
}

/** Последнее слово и выход: именно он будит leader. */
function watchBye(string $log, bool $quiet, int $code, string $why): int
{
    watchLog($log, $quiet, ($code === 0 ? 'конец: ' : 'подъём: ') . $why);
    foreach (watchDirs() as $dir) journalLine($dir, 'сторож: ' . $why);

    // В ленту прогона — вместе со сводкой: по ней видно, что висело.
    $picture = '';
    foreach (watchPicture() as $label => $lines) $picture .= "$label:\n$lines\n";
    tell(watchFlags(), 'watch', 'сторож: ' . $why, $picture !== '' ? $picture : null);
    say(($code === 0 ? '✓ ' : '⚠ ') . $why);

    // Сводка: что где висит на момент подъёма — чтобы не звать `goblin steps`.
    foreach (watchPicture() as $label => $lines) {
        say(" $label:");
        say($lines);
    }
    return $code;
}
