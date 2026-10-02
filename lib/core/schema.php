<?php
/* Схема базы: установка с нуля, миграции на обе базы, схема SQLite из схемы MySQL.
   Отдаёт: schemaInstall(), schemaMigrations(), schemaMigrate(), schemaCatalog(), schemaSqlite(), SCHEMA_DIR.
   Не делает: не создаёт саму базу MySQL — её заводит установщик (или хостер); файл SQLite появляется сам.

   Источник правды — sql/schema.sql (MySQL). sql/schema.sqlite.sql собирает schemaSqlite()
   (`php bin/server.php schema-sqlite`) — руками не правят. Миграция с расхождением синтаксиса —
   двумя файлами: 035-x.sql и 035-x.sqlite.sql; для SQLite без двойника миграция не применяется.
   Каталог готовых схем — содержимое, а не данные людей: sql/catalog.json (`php bin/server.php catalog-export`)
   ложится в пустую таблицу templates при установке. */

declare(strict_types=1);

const SCHEMA_DIR = __DIR__ . '/../../sql';

/** Пустая база → все таблицы по драйверу; все миграции помечаются применёнными (схема их уже содержит). */
function schemaInstall(): void
{
    $file = SCHEMA_DIR . (dbDriver() === 'sqlite' ? '/schema.sqlite.sql' : '/schema.sql');
    $sql = (string) file_get_contents($file);
    // MySQL (не MariaDB) не даёт полям TEXT значения по умолчанию — снимаем; пропущенное поле там — пустая строка (db()).
    if (dbDriver() === 'mysql' && !str_contains((string) db()->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
        $sql = (string) preg_replace("/(`\\w+` (?:tiny|medium|long)?text\\b[^,\\n]*?) DEFAULT '(?:[^'\\\\]|\\\\.)*'/i", '$1', $sql);
    }
    db()->exec($sql);
    // Схема сама отмечает свои миграции; доотмечаем те, что новее её шапки.
    foreach (schemaMigrations()['waiting'] as $name) dbRun('INSERT INTO schema_migrations (name, took_ms) VALUES (?, 0)', [$name]);

    // Каталог готовых схем — в пустую таблицу.
    $catalog = json_decode((string) @file_get_contents(SCHEMA_DIR . '/catalog.json'), true) ?: [];
    if ((int) dbValue('SELECT COUNT(*) FROM templates') === 0) {
        foreach ($catalog as $row) {
            $cols = array_keys($row);
            dbRun('INSERT INTO templates (`' . implode('`, `', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
                array_values($row));
        }
    }
}

/** Встроенные заготовки каталога — JSON для sql/catalog.json: без id, владельца и отметок времени. */
function schemaCatalog(): string
{
    $rows = dbAll('SELECT `key`, title, family, category, about, body, passport, i18n, in_catalog, sort, builtin
                     FROM templates WHERE builtin = 1 AND owner_id IS NULL ORDER BY category, sort, id');
    return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

/** Миграции: ['list' => [['name', 'applied' => когда или null]…], 'waiting' => [имена]]. Двойники .sqlite.sql — не отдельные. */
function schemaMigrations(): array
{
    $done = [];
    foreach (dbAll('SELECT name, applied_at FROM schema_migrations') as $row) $done[$row['name']] = $row['applied_at'] ?? '';
    $files = array_filter(glob(SCHEMA_DIR . '/migrations/*.sql') ?: [], static fn($f) => !str_ends_with($f, '.sqlite.sql'));
    sort($files);
    $list = array_map(static fn(string $f) => ['name' => basename($f), 'applied' => $done[basename($f)] ?? null], $files);
    return ['list' => $list, 'waiting' => array_values(array_column(
        array_filter($list, static fn($m) => $m['applied'] === null), 'name'))];
}

/** Применить одну миграцию: для SQLite — её двойник .sqlite.sql. */
function schemaMigrate(string $name): void
{
    $file = SCHEMA_DIR . '/migrations/' . basename($name);
    if (dbDriver() === 'sqlite') {
        $file = substr($file, 0, -4) . '.sqlite.sql';
        if (!is_file($file)) throw new RuntimeException('SQLite: у миграции ' . $name . ' нет двойника ' . basename($file));
    }
    $started = microtime(true);
    db()->exec((string) file_get_contents($file));
    dbRun('INSERT INTO schema_migrations (name, took_ms) VALUES (?,?)', [basename($name), (int) ((microtime(true) - $started) * 1000)]);
}

/**
 * Схема MySQL (текст schema.sql) → схема SQLite. Построчно, по известному виду дампа SHOW CREATE TABLE:
 *   целые → INTEGER, строки и даты → TEXT, ENUM → TEXT с CHECK, AUTO_INCREMENT-id → INTEGER PRIMARY KEY AUTOINCREMENT;
 *   строки без _bin — COLLATE NOCASE (в MySQL сравнение без учёта регистра);
 *   KEY / UNIQUE KEY → CREATE [UNIQUE] INDEX; ON UPDATE current_timestamp → триггер.
 * Непонятная строка — отказ: схема SQLite не должна молча разойтись с MySQL.
 */
function schemaSqlite(string $mysql): string
{
    $now = static fn(string $digits) => $digits !== '' && $digits !== '0'
        ? "(strftime('%Y-%m-%d %H:%M:%f','now','localtime'))" : "(strftime('%Y-%m-%d %H:%M:%S','now','localtime'))";
    $out = ["-- Гоблин: схема SQLite. Собрана из sql/schema.sql командой `php bin/server.php schema-sqlite` — руками не править.", '',
        'PRAGMA foreign_keys = OFF;', ''];
    $table = null;
    $lines = $indexes = $triggers = [];
    $autoId = $insert = false;

    // Строго \r и \n: «\R» без /u считал разрывом и байт 0x85 — он внутри русской «х».
    foreach (preg_split('/\r\n|\r|\n/', $mysql) as $line) {
        $trim = trim($line);
        if ($table === null) {
            // INSERT (отметки миграций в конце схемы) — как есть, до «;».
            if ($insert || str_starts_with($trim, 'INSERT ')) {
                $out[] = preg_replace('/^INSERT IGNORE /', 'INSERT OR IGNORE ', $line);
                $insert = !str_ends_with($trim, ';');
                continue;
            }
            if (preg_match('/^CREATE TABLE `(\w+)` \($/', $trim, $m)) {
                [$table, $lines, $autoId] = [$m[1], [], false];
            } elseif (str_starts_with($trim, '--') || $trim === '') {
                $out[] = $line;
            }
            continue;
        }
        if (str_starts_with($trim, ')')) {
            // Хвост таблицы: «) ENGINE=…;» — SQLite он не нужен.
            $out[] = 'CREATE TABLE "' . $table . '" (';
            $out[] = '  ' . implode(",\n  ", $lines);
            $out[] = ');';
            array_push($out, ...$indexes, ...$triggers);
            $out[] = '';
            [$table, $indexes, $triggers] = [null, [], []];
            continue;
        }
        $trim = rtrim($trim, ',');
        $name = static fn(string $s) => str_replace('`', '"', $s);
        $cols = static fn(string $list) => $name(preg_replace('/\(\d+\)/', '', $list));   // KEY x (`a`(191)) — без длины

        if (preg_match('/^`(\w+)` (.+)$/', $trim, $m)) {
            [$col, $rest] = [$m[1], $m[2]];
            preg_match('/^(\w+)(?:\(([^)]*)\))?/', $rest, $t);
            $kind = strtolower($t[1]);
            $type = match (true) {
                in_array($kind, ['bigint', 'int', 'tinyint', 'smallint', 'mediumint'], true) => 'INTEGER',
                in_array($kind, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'datetime', 'date', 'enum'], true) => 'TEXT',
                in_array($kind, ['decimal', 'double', 'float'], true) => 'REAL',
                default => throw new RuntimeException("schema-sqlite: тип $kind у $table.$col"),
            };
            if (str_contains($rest, 'AUTO_INCREMENT')) {
                $lines[] = '"' . $col . '" INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            $def = '"' . $col . '" ' . $type;
            if (in_array($kind, ['varchar', 'char'], true) && !preg_match('/COLLATE \w+_bin/', $rest)) $def .= ' COLLATE NOCASE';
            if (str_contains($rest, 'NOT NULL')) $def .= ' NOT NULL';
            if (preg_match("/DEFAULT ('(?:[^'\\\\]|\\\\.)*'|NULL|-?\d+(?:\.\d+)?|current_timestamp\((\d*)\))/i", $rest, $d)) {
                $def .= ' DEFAULT ' . (stripos($d[1], 'current_timestamp') === 0 ? $now($d[2] ?? '') : $d[1]);
            }
            if ($kind === 'enum') $def .= ' CHECK ("' . $col . '" IN (' . $t[2] . ')' . (str_contains($rest, 'NOT NULL') ? '' : ' OR "' . $col . '" IS NULL') . ')';
            if (preg_match('/CHECK \((.+)\)$/', $rest, $c)) $def .= ' CHECK (' . $name($c[1]) . ')';
            if (preg_match('/ON UPDATE current_timestamp\((\d*)\)/i', $rest, $u)) {
                $triggers[] = "CREATE TRIGGER \"tr_{$table}_{$col}\" AFTER UPDATE ON \"$table\" FOR EACH ROW WHEN NEW.\"$col\" IS OLD.\"$col\"\n"
                    . "BEGIN UPDATE \"$table\" SET \"$col\" = {$now($u[1])} WHERE rowid = NEW.rowid; END;";
            }
            $lines[] = $def;
        } elseif (preg_match('/^PRIMARY KEY \((.+)\)$/', $trim, $m)) {
            if (!($autoId && $m[1] === '`id`')) $lines[] = 'PRIMARY KEY (' . $cols($m[1]) . ')';
        } elseif (preg_match('/^(UNIQUE )?KEY `(\w+)` \((.+)\)$/', $trim, $m)) {
            // Имена индексов в SQLite общие на базу — с именем таблицы, чтобы не столкнулись.
            $indexes[] = 'CREATE ' . ($m[1] ? 'UNIQUE ' : '') . 'INDEX "' . $table . '__' . $m[2] . '" ON "' . $table . '" (' . $cols($m[3]) . ');';
        } elseif (preg_match('/^CONSTRAINT `(\w+)` (FOREIGN KEY|CHECK) (.+)$/', $trim, $m)) {
            $lines[] = 'CONSTRAINT "' . $m[1] . '" ' . $m[2] . ' ' . $name($m[3]);
        } else {
            throw new RuntimeException("schema-sqlite: не понял строку в $table: $trim");
        }
    }
    $out[] = 'PRAGMA foreign_keys = ON;';
    return implode("\n", $out) . "\n";
}
