<?php
/* Ключ проекта, номера объектов и секреты пропусков.
   Отдаёт: validProjectKey(), nextNo(), nextRunNo(), newSecret(), hashSecret().
   Не делает: не проверяет права и не пишет в журнал. */

declare(strict_types=1);

/** Ключ проекта из ссылки #p=…: латиница и цифры, 6–32 знака. */
function validProjectKey(string $key): bool
{
    return (bool) preg_match('/^[a-z0-9]{6,32}$/', $key);
}

/**
 * Следующий номер элемента в проекте: 10, 11, 12…
 * Номер выдаёт только сервер и никогда не выдаёт дважды —
 * даже если объект с этим номером удалён.
 */
function nextNo(int $projectId): int
{
    dbRun('UPDATE projects SET next_no = next_no + 1 WHERE id = ?', [$projectId]);
    return (int) dbValue('SELECT next_no - 1 FROM projects WHERE id = ?', [$projectId]);
}

/** Следующий номер прогона: r1, r2, r3… Свой счётчик, чтобы читалось человеком. */
function nextRunNo(int $projectId): int
{
    dbRun('UPDATE projects SET next_run_no = next_run_no + 1 WHERE id = ?', [$projectId]);
    return (int) dbValue('SELECT next_run_no - 1 FROM projects WHERE id = ?', [$projectId]);
}

/**
 * Новый секрет пропуска. Показывается человеку один раз;
 * в базе живёт только его отпечаток.
 *   gbl_ — проект, gbr_ — прогон (leader), gbs_ — шаг (worker).
 */
function newSecret(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(24));
}

function hashSecret(string $secret): string
{
    return hash('sha256', $secret);
}
