<?php
/* SQLite вместо MySQL: соединение, недостающие функции MySQL и переводчик запросов.
   Отдаёт: sqliteOpen(), sqliteSql().
   Не делает: не знает таблиц и не правит смысл запросов — код пишет SQL как для MySQL,
              здесь он только дотягивается до SQLite (план — md_backend/arch/github-install.md).

   Функции MySQL (NOW, TIMESTAMPDIFF, GREATEST…) регистрируются в SQLite под теми же именами —
   запросы не меняются. Что функцией не сделать (INTERVAL, <=>, FOR UPDATE, IF, ON DUPLICATE KEY),
   переписывает sqliteSql() — один раз на текст запроса.

   Часы — у базы, как в MySQL: местное время системы (SQLite 'localtime'), строка `Y-m-d H:i:s.mmm`.
   Пояс PHP тут ни при чём: он бывает другим (date.timezone), и время разъехалось бы. */

declare(strict_types=1);

/** Открыть файл базы SQLite и научить её функциям MySQL. */
function sqliteOpen(string $file, array $options): PDO
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    // PHP 8.4+ — свой класс Pdo\Sqlite (старый PDO::sqliteCreateFunction с 8.5 устарел); PHP 8.1–8.3 — обычный PDO.
    $modern = class_exists('Pdo\\Sqlite');
    $pdo = $modern ? new \Pdo\Sqlite('sqlite:' . $file, null, null, $options) : new PDO('sqlite:' . $file, null, null, $options);
    // WAL — читатели не ждут писателя; ждать замка не дольше 5 с; связи между таблицами — всерьёз.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    // Сдвиг местного времени системы от UTC — им живут все часы ниже.
    $shift = (int) $pdo->query("SELECT strftime('%s','now','localtime') - strftime('%s','now')")->fetchColumn();
    foreach (sqliteFunctions($shift) as $name => [$work, $args]) {
        $modern ? $pdo->createFunction($name, $work, $args) : $pdo->sqliteCreateFunction($name, $work, $args);
    }
    return $pdo;
}

/** Функции MySQL для SQLite: имя → [работа, число аргументов; -1 — сколько угодно]. */
function sqliteFunctions(int $shift): array
{
    // Строка времени базы ↔ секунды эпохи. Строка — местное время, поэтому сдвиг вычитается.
    $toUnix = static fn(string $moment): float => (float) (new DateTimeImmutable($moment, new DateTimeZone('UTC')))
        ->format('U.u') - $shift;
    $toMoment = static function (float $unix, bool $ms) use ($shift): string {
        $at = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $unix + $shift), new DateTimeZone('UTC'));
        return $at->format($ms ? 'Y-m-d H:i:s.v' : 'Y-m-d H:i:s');
    };
    $units = ['MICROSECOND' => 0.000001, 'SECOND' => 1, 'MINUTE' => 60, 'HOUR' => 3600, 'DAY' => 86400];
    $nulls = static fn(array $args): bool => in_array(null, $args, true);

    return [
        'NOW' => [static fn($precision = 0) => $toMoment(microtime(true), (int) $precision > 0), -1],
        'UTC_TIMESTAMP' => [static fn() => gmdate('Y-m-d H:i:s'), 0],
        'CURDATE' => [static fn() => substr($toMoment(microtime(true), false), 0, 10), 0],
        'FROM_UNIXTIME' => [static fn($unix) => $unix === null ? null : $toMoment((float) $unix, false), 1],
        'UNIX_TIMESTAMP' => [static fn($moment = null) => $moment === null ? time() : (int) $toUnix((string) $moment), -1],
        // TIMESTAMPDIFF(SECOND, a, b) — единицу sqliteSql() превращает в строку 'SECOND'.
        'TIMESTAMPDIFF' => [static function ($unit, $from, $to) use ($toUnix, $units) {
            if ($from === null || $to === null) return null;
            return (int) floor(($toUnix((string) $to) - $toUnix((string) $from)) / $units[strtoupper((string) $unit)]);
        }, 3],
        // NOW(3) - INTERVAL 2 MINUTE → TIME_SHIFT(NOW(3), '-', 2, 'MINUTE'); у даты без часов — дата.
        'TIME_SHIFT' => [static function ($moment, $sign, $amount, $unit) use ($toUnix, $toMoment, $units) {
            if ($moment === null || $amount === null) return null;
            $moment = (string) $moment;
            $step = (float) $amount * $units[strtoupper((string) $unit)] * ($sign === '-' ? -1 : 1);
            $out = $toMoment($toUnix(strlen($moment) === 10 ? $moment . ' 00:00:00' : $moment) + $step, strlen($moment) > 19);
            return strlen($moment) === 10 ? substr($out, 0, 10) : $out;
        }, 4],
        // Как в MySQL: хоть один NULL — ответ NULL.
        'GREATEST' => [static fn(...$args) => $nulls($args) ? null : max($args), -1],
        'LEAST' => [static fn(...$args) => $nulls($args) ? null : min($args), -1],
        'CHAR_LENGTH' => [static fn($text) => $text === null ? null : mb_strlen((string) $text), 1],
        'CONCAT' => [static fn(...$args) => $nulls($args) ? null : implode('', array_map('strval', $args)), -1],
        'FIELD' => [static function ($value, ...$list) {
            $at = array_search((string) $value, array_map('strval', $list), true);
            return $at === false ? 0 : $at + 1;
        }, -1],
        // «a REGEXP b» SQLite зовёт как regexp(b, a); без учёта регистра — как сравнение в MySQL.
        'REGEXP' => [static fn($pattern, $text) => $text === null ? null
            : (int) preg_match('~' . str_replace('~', '\~', (string) $pattern) . '~iu', (string) $text), 2],
        // json_extract в SQLite и так отдаёт строку без кавычек.
        'JSON_UNQUOTE' => [static fn($value) => is_string($value) && str_starts_with($value, '"')
            ? json_decode($value) : $value, 1],
    ];
}

/** Текст запроса MySQL → SQLite. Для MySQL его не зовут. Непереведённый INTERVAL — отказ сразу. */
function sqliteSql(string $sql): string
{
    $rules = [
        // NOW(3) - INTERVAL 2 MINUTE, CURDATE() - INTERVAL ? DAY
        '~(NOW\(\d?\)|CURDATE\(\))\s*([+-])\s*INTERVAL\s+(\?|\d+)\s+(MICROSECOND|SECOND|MINUTE|HOUR|DAY)\b~i'
            => "TIME_SHIFT($1, '$2', $3, '$4')",
        // DATE_ADD(NOW(3), INTERVAL ? MICROSECOND)
        '~DATE_ADD\(\s*(NOW\(\d?\))\s*,\s*INTERVAL\s+(\?|\d+)\s+(MICROSECOND|SECOND|MINUTE|HOUR|DAY)\s*\)~i'
            => "TIME_SHIFT($1, '+', $2, '$3')",
        '~TIMESTAMPDIFF\(\s*(\w+)\s*,~i' => "TIMESTAMPDIFF('$1',",
        '~<=>~' => ' IS ',
        '~\s+FOR\s+UPDATE\b~i' => '',
        '~\bIF\(~' => 'IIF(',
        // GROUP_CONCAT(DISTINCT x ORDER BY y SEPARATOR ', ') — в SQLite у DISTINCT один аргумент
        '~GROUP_CONCAT\(\s*(DISTINCT\s+)?([^()]+?)\s+ORDER\s+BY\s+[^()]+?\s+SEPARATOR\s+\'[^\']*\'\s*\)~i'
            => 'GROUP_CONCAT($1$2)',
    ];
    $sql = (string) preg_replace(array_keys($rules), array_values($rules), $sql);

    // ON DUPLICATE KEY UPDATE a = VALUES(a) → ON CONFLICT DO UPDATE SET a = excluded.a
    if (preg_match('~\bON\s+DUPLICATE\s+KEY\s+UPDATE\b~i', $sql, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1];
        $tail = (string) preg_replace('~\bVALUES\(\s*(\w+)\s*\)~i', 'excluded.$1', substr($sql, $at + strlen($m[0][0])));
        $sql = substr($sql, 0, $at) . 'ON CONFLICT DO UPDATE SET' . $tail;
    }

    if (preg_match('~\bINTERVAL\b~i', $sql)) throw new RuntimeException('SQLite: не переведён INTERVAL — ' . $sql);
    return $sql;
}
