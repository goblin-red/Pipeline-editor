<?php
/* Соединение с базой и четыре способа её спросить.
   Отдаёт: db(), dbDriver(), dbAll(), dbRow(), dbValue(), dbRun(), dbId(), dbTransaction(), dbBegin(), dbRollback(),
           dbInTransaction(), dbOnRollback(), dbAfterCommit(), dbUndoDisk(), dbNow(), dbStamp(), dbMoment().
   Не делает: не знает ни одной таблицы — SQL живёт только в доменах.

   База — MySQL/MariaDB или SQLite (настройка driver). Код пишет SQL как для MySQL; для SQLite
   запрос переводит lib/core/sqlite.php, там же — недостающие функции MySQL. */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $c = config();
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];
    if (dbDriver() === 'sqlite') {
        require_once __DIR__ . '/sqlite.php';   // страницы, что берут db.php без boot.php (документация)
        return $pdo = sqliteOpen(dbSqliteFile(), $options);
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
    /* MySQL (хостинг — 5.7) строже MariaDB: у полей TEXT не бывает значения по умолчанию, а GROUP BY
       требует все поля. Сеансу — мягкий режим: пропущенное поле TEXT — пустая строка, как ждёт код. */
    if (!str_contains((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
        $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

/** Какая база: mysql (MySQL, MariaDB) или sqlite. */
function dbDriver(): string
{
    return (config()['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
}

/** Файл SQLite: путь из настройки sqlite_file — от корня Гоблина или абсолютный. */
function dbSqliteFile(): string
{
    $file = (string) (config()['sqlite_file'] ?? '') ?: 'data/goblin.sqlite';
    return str_starts_with($file, '/') ? $file : configPath($file);
}

/** Подготовить запрос; для SQLite текст сначала переводится — один раз на текст. */
function dbPrepare(string $sql): PDOStatement
{
    static $sqlite = [];
    if (dbDriver() === 'sqlite') $sql = $sqlite[$sql] ??= sqliteSql($sql);
    return db()->prepare($sql);
}

/** Все строки запроса. */
function dbAll(string $sql, array $args = []): array
{
    $st = dbPrepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Одна строка или null. */
function dbRow(string $sql, array $args = []): ?array
{
    $st = dbPrepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Одно значение первой строки или null. */
function dbValue(string $sql, array $args = [])
{
    $st = dbPrepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** Запрос без выборки. Возвращает число затронутых строк. */
function dbRun(string $sql, array $args = []): int
{
    $st = dbPrepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/** Id последней вставленной строки. */
function dbId(): int
{
    return (int) db()->lastInsertId();
}

/** Выполнить замыкание в транзакции. Вложенные вызовы транзакцию не открывают. */
function dbTransaction(callable $work)
{
    if (dbInTransaction()) return $work();

    dbBegin();
    $GLOBALS['dbUndo'] = $GLOBALS['dbAfter'] = [];
    try {
        $result = $work();
        dbCommit();
        $after = $GLOBALS['dbAfter'];
        $GLOBALS['dbUndo'] = $GLOBALS['dbAfter'] = [];
        foreach ($after as $done) {
            try { $done(); } catch (Throwable) {}   // база уже решена: сбой на диске ответа не портит
        }
        return $result;
    } catch (Throwable $e) {
        dbRollback();
        dbUndoDisk();
        throw $e;
    }
}

/* Транзакции SQLite PDO не видит: BEGIN IMMEDIATE (запись сразу по очереди — как FOR UPDATE
   в MySQL) открывается командой, поэтому её состояние помним сами. */

/** Открыта ли транзакция. */
function dbInTransaction(): bool
{
    return dbDriver() === 'sqlite' ? !empty($GLOBALS['dbSqliteTx']) : db()->inTransaction();
}

/** Открыть транзакцию (обычно — через dbTransaction()). */
function dbBegin(): void
{
    if (dbDriver() !== 'sqlite') {
        db()->beginTransaction();
        return;
    }
    db()->exec('BEGIN IMMEDIATE');
    $GLOBALS['dbSqliteTx'] = true;
}

function dbCommit(): void
{
    if (dbDriver() !== 'sqlite') {
        db()->commit();
        return;
    }
    db()->exec('COMMIT');
    $GLOBALS['dbSqliteTx'] = false;
}

/** Откатить, если транзакция открыта. */
function dbRollback(): void
{
    if (!dbInTransaction()) return;
    if (dbDriver() !== 'sqlite') {
        db()->rollBack();
        return;
    }
    $GLOBALS['dbSqliteTx'] = false;
    try { db()->exec('ROLLBACK'); } catch (Throwable) {}   // SQLite мог откатить сам (сбой записи)
}

/** Откат базы не убирает сделанное на диске (новую рабочую папку) — запомнить, как убрать. */
function dbOnRollback(callable $undo): void
{
    if (dbInTransaction()) $GLOBALS['dbUndo'][] = $undo;
}

/**
 * Дело на диске, которое нельзя откатить (удалить файл), — только после фиксации:
 * откатят транзакцию, и файл останется на месте. Вне транзакции выполняется сразу.
 */
function dbAfterCommit(callable $work): void
{
    if (dbInTransaction()) $GLOBALS['dbAfter'][] = $work;
    else $work();
}

/** Транзакцию откатили: убрать с диска то, что она успела завести, и забыть отложенное. Звать после rollBack(). */
function dbUndoDisk(): void
{
    $list = array_reverse($GLOBALS['dbUndo'] ?? []);
    $GLOBALS['dbUndo'] = $GLOBALS['dbAfter'] = [];
    foreach ($list as $undo) {
        try { $undo(); } catch (Throwable) {}
    }
}

/** Время сервера с миллисекундами — единственный источник времени. */
function dbNow(): string
{
    return (string) dbValue('SELECT NOW(3)');
}

/**
 * Момент из поля базы в секундах эпохи.
 *
 * Строку `2026-09-21 19:51:33` нельзя разбирать через strtotime(): PHP прочтёт её
 * в своём часовом поясе, а он у базы бывает другой — час разницы превращал свежий
 * файл в «сделанный впрок». Пересчёт делает та же база, что и записала время.
 */
function dbStamp(?string $moment): int
{
    $moment = trim((string) $moment);
    if ($moment === '' || str_starts_with($moment, '0000')) return 0;
    return (int) dbValue('SELECT UNIX_TIMESTAMP(?)', [$moment]);
}

/**
 * Обратное dbStamp(): секунды эпохи (время файла на диске) — строкой по часам базы, чтобы стоять
 * рядом с её датами. date() дал бы пояс PHP. Сдвиг пояса базы спрашиваем один раз на запрос.
 */
function dbMoment(int $unix): string
{
    static $shift = null;
    $shift ??= (int) dbValue('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
    return gmdate('Y-m-d H:i:s', $unix + $shift);
}
