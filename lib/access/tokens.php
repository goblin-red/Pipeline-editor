<?php
/* Пропуска: выпустить, найти, погасить.
   Отдаёт: tokenIssue(), tokenFind(), tokenRevoke(), tokenRevokeFor(), tokenTouch().
   Не делает: не решает, что пропуск разрешает — это access/rights.php. */

declare(strict_types=1);

/**
 * Выпустить пропуск. Секрет возвращается ОДИН раз — в базе только отпечаток.
 * scope: session | admin | project | run | step | agent.
 * $ttl — сколько секунд живёт (null — бессрочно). Срок считает база от NOW(3): с ним же
 * сверяет tokenFind(), а пояс PHP бывает другим.
 */
function tokenIssue(string $scope, array $link = [], string $label = '', ?int $ttl = null): array
{
    $prefix = match ($scope) {
        'project' => 'gbl_',
        'run'     => 'gbr_',
        'step'    => 'gbs_',
        'agent'   => 'gba_',
        default   => 'gbt_',
    };
    $secret = newSecret($prefix);

    dbRun(
        'INSERT INTO tokens (token_hash, scope, tail, label, user_id, project_id, run_id, step_id, agent_id, created_by, expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,?, NOW(3) + INTERVAL ? SECOND)',
        [
            hashSecret($secret), $scope, substr($secret, -8), mb_substr($label, 0, 64),
            $link['user_id'] ?? null, $link['project_id'] ?? null, $link['run_id'] ?? null,
            $link['step_id'] ?? null, $link['agent_id'] ?? null, $link['created_by'] ?? null,
            $ttl,
        ]
    );
    return ['id' => dbId(), 'secret' => $secret, 'tail' => substr($secret, -8), 'scope' => $scope];
}

/** Пропуск агента (прогона, шага, агента) без дела столько дней гаснет сам: срок продлевает каждое обращение (Б3). */
const TOKEN_IDLE_DAYS = 7;

/** Живой пропуск по секрету или null. Отозванный, просроченный и давно молчащий пропуск агента не возвращаются. */
function tokenFind(string $secret): ?array
{
    if ($secret === '') return null;
    return dbRow(
        "SELECT * FROM tokens
          WHERE token_hash = ?
            AND revoked_at IS NULL
            AND (expires_at IS NULL OR expires_at > NOW(3))
            AND (scope NOT IN ('run', 'step', 'agent') OR COALESCE(last_used_at, created_at) > NOW(3) - INTERVAL ? DAY)",
        [hashSecret($secret), TOKEN_IDLE_DAYS]
    );
}

function tokenTouch(int $id): void
{
    dbRun('UPDATE tokens SET last_used_at = NOW(3) WHERE id = ?', [$id]);
}

function tokenRevoke(int $id): void
{
    dbRun('UPDATE tokens SET revoked_at = NOW(3) WHERE id = ? AND revoked_at IS NULL', [$id]);
}

/** Погасить все пропуска прогона или шага — например, когда прогон остановлен. */
function tokenRevokeFor(string $field, int $id): void
{
    if (!in_array($field, ['run_id', 'step_id', 'project_id', 'user_id', 'agent_id'], true)) {
        throw new ApiError(t('server.token.unknown_field'));
    }
    dbRun("UPDATE tokens SET revoked_at = NOW(3) WHERE $field = ? AND revoked_at IS NULL", [$id]);
}
