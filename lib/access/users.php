<?php
/* Люди: вход, регистрация, выход, текущий пользователь.
   Отдаёт: userRegister(), userLogin(), userLogout(), currentUser(), userProjects().
   Не делает: не решает права на проект — это access/rights.php.

   Пароль хранится хешем, сеанс — пропуском scope=session в cookie. */

declare(strict_types=1);

const SESSION_DAYS = 60;

function currentUser(): ?array
{
    $who = caller();
    if (!$who['user_id']) return null;
    return dbRow('SELECT id, email, name, created_at, last_login_at FROM users WHERE id = ?', [$who['user_id']]);
}

function userRegister(string $email, string $password, string $name = ''): array
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(t('server.user.bad_email'));
    if (mb_strlen($password) < 6) throw new ApiError(t('server.user.short_password'));
    if (dbValue('SELECT id FROM users WHERE email = ?', [$email])) throw new ApiError(t('server.user.email_taken'), 'conflict');
    // Имя — тоже логин: двух одинаковых быть не должно.
    $name = trim($name);
    if ($name !== '' && dbValue('SELECT id FROM users WHERE LOWER(name) = LOWER(?)', [$name])) {
        throw new ApiError(t('server.user.name_taken'), 'conflict');
    }

    // Новому человеку — копия общих настроек ИИ: дальше она его собственная.
    dbRun('INSERT INTO users (email, pass_hash, name, access) VALUES (?,?,?,?)',
        [$email, password_hash($password, PASSWORD_DEFAULT), $name, aiAccessDefaults()]);
    return userStart((int) dbId());
}

/** Вход по почте или по имени — как удобно человеку. */
function userLogin(string $login, string $password): array
{
    $login = trim($login);
    $found = str_contains($login, '@')
        ? dbAll('SELECT * FROM users WHERE email = ?', [mb_strtolower($login)])
        : dbAll('SELECT * FROM users WHERE LOWER(name) = LOWER(?) AND name <> ""', [$login]);
    // Имён-двойников (заведены до проверки при регистрации) — вход у того, чей пароль подошёл.
    $user = null;
    foreach ($found as $one) if (password_verify($password, (string) $one['pass_hash'])) { $user = $one; break; }
    if (!$user) {
        throw new ApiError(t('server.user.bad_login'), 'unauthorized');
    }
    dbRun('UPDATE users SET last_login_at = NOW(3) WHERE id = ?', [$user['id']]);
    return userStart((int) $user['id']);
}

/** Новый сеанс: пропуск живёт в cookie и гаснет сам. */
function userStart(int $userId): array
{
    $token = tokenIssue('session', ['user_id' => $userId], t('server.token.session_label'), SESSION_DAYS * 86400);

    setcookie(SESSION_COOKIE, $token['secret'], [
        'expires' => time() + SESSION_DAYS * 86400,
        'path' => '/',
        'httponly' => true,
        // На защищённом соединении кука не должна уходить по случайному http-запросу.
        'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
        'samesite' => 'Lax',
    ]);
    return ['user' => $userId, 'token' => $token['secret']];
}

function userLogout(): void
{
    $who = caller();
    if ($who['token_id']) tokenRevoke((int) $who['token_id']);
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

/** Проекты человека: свои и те, куда он заходил. */
function userProjects(int $userId): array
{
    return dbAll(
        'SELECT p.*, (SELECT COUNT(*) FROM folders f WHERE f.project_id = p.id) AS folders,
                (SELECT COUNT(*) FROM elements e WHERE e.project_id = p.id) AS elements,
                (p.owner_id = ?) AS mine
           FROM projects p
          WHERE p.owner_id = ?
             OR p.id IN (SELECT project_id FROM visits WHERE user_id = ? AND project_id IS NOT NULL)
          ORDER BY p.updated_at DESC LIMIT 200',
        [$userId, $userId, $userId]
    );
}

function userPassword(int $userId, string $old, string $new): void
{
    $user = dbRow('SELECT pass_hash FROM users WHERE id = ?', [$userId]);
    if (!$user || !password_verify($old, (string) $user['pass_hash'])) throw new ApiError(t('server.user.bad_old_password'));
    if (mb_strlen($new) < 6) throw new ApiError(t('server.user.short_new_password'));
    dbRun('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
}
