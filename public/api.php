<?php
/* Единственный вход для данных: чтение, правки, прогон, материалы.
   Отдаёт: JSON. Ничего больше здесь не происходит — вся работа в lib/.
   Не делает: не знает ни одной операции по имени (их список в lib/api/router.php). */

declare(strict_types=1);

require __DIR__ . '/../lib/boot.php';

try {
    dispatch();
} catch (ApiError $e) {
    // Простой путь отвечает текстом — и ошибки тоже: агент в терминале
    // читает их глазами, а JSON ему только мешает.
    if (simpleOp()) plain([$e->getMessage()], $e->status());
    fail($e->getMessage(), $e->errCode, $e->extra);
} catch (Throwable $e) {
    // Настоящая причина уходит в лог сервера, наружу — короткая строка.
    error_log('[goblin] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (simpleOp()) plain([ta('agents.go.server_failed')], 500);
    fail(t('server.api.failed'), 'server', [], 500);
}
