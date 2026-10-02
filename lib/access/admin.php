<?php
/* Вход в админку: один пароль из config.php, свой пропуск, своя cookie.
   Отдаёт: adminIn(), adminLogin(), adminLogout().
   Не делает: не решает, что админ может — это сами страницы админки (сводка — lib/admin/data.php). */

declare(strict_types=1);

const ADMIN_COOKIE = 'goblin_admin';

function adminIn(): bool
{
    $secret = (string) ($_COOKIE[ADMIN_COOKIE] ?? '');
    if ($secret === '') return false;
    $token = tokenFind($secret);
    return $token !== null && $token['scope'] === 'admin';
}

function adminLogin(string $login, string $password): bool
{
    $c = config();
    if ($login !== (string) $c['admin_login']) return false;
    $hash = (string) $c['admin_pass_hash'];
    if ($hash === '' || !password_verify($password, $hash)) return false;

    $token = tokenIssue('admin', [], t('server.token.admin_label'), 86400 * 7);
    setcookie(ADMIN_COOKIE, $token['secret'], [
        'expires' => time() + 86400 * 7, 'path' => '/', 'httponly' => true,
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on', 'samesite' => 'Lax',
    ]);
    return true;
}

function adminLogout(): void
{
    $secret = (string) ($_COOKIE[ADMIN_COOKIE] ?? '');
    if ($secret !== '') {
        $token = tokenFind($secret);
        if ($token) tokenRevoke((int) $token['id']);
    }
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}
