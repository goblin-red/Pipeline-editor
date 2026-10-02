<?php
/* Доступ к моделям: общий установки и личный пользователя.
   Отдаёт: ACCESS_FIELDS, ACCESS_AI, aiSharedKeys(), aiAccess(), aiAccessOf(), aiAccessOwn(), aiAccessSave(), aiAccessUse(),
           aiAccessDefaults(), aiAccessProject(), jevAccess(), aiAccessHidden(), aiAccessPair(), aiUrlGuard(), ACCESS_MASK.
   Не делает: не зовёт модели — только говорит, куда идти и с каким ключом.

   Общий доступ — настройки установки (админка, config.php, secrets.php): рабочее подключение ИИ (ai_*), голос OpenAI
   (voice_*) и Jev (jev_*). Свой у человека — users.access, правится в личном кабинете (раздел «ИИ»); пустое поле — общее.
   Новому человеку при регистрации кладётся копия общих голоса и Jev (aiAccessDefaults): смена общих потом его не касается.
   ИИ помощника — свой только со своим ключом: без него (или с копией общего ключа) человек идёт через рабочее
   подключение админки и переключается вместе с ним (решение хозяина 01.10.2026).
   Ключ по умолчанию (личный = общий) кабинет показывает звёздочками (ACCESS_MASK) — сам ключ в страницу не уходит.
   Адрес и ключ — пара: общий ключ уходит только на общий адрес; свой адрес — только со своим ключом (aiAccessPair).
   Свой адрес — только https на внешний сервер: file://, localhost и внутренняя сеть — отказ (aiUrlGuard).
   Чей доступ: в запросе — вошедшего человека; в фоновом задании ИИ — того, кто спросил (aiAccessUse);
   Jev в прогоне — владельца проекта (jevAccess). */

declare(strict_types=1);

const ACCESS_FIELDS = [
    'ai_api_url', 'ai_model', 'ai_api_key',
    'voice_api_url', 'voice_model', 'voice_languages', 'voice_tts_model', 'voice_tts_voice', 'voice_api_key',
    'jev_api_url', 'jev_model', 'jev_api_key',
];

/** Поля ИИ помощника: свои у человека — только вместе со своим ключом. */
const ACCESS_AI = ['ai_api_url', 'ai_model', 'ai_api_key'];

/** Адрес модели → её ключ. */
const ACCESS_PAIRS = ['ai_api_url' => 'ai_api_key', 'voice_api_url' => 'voice_api_key', 'jev_api_url' => 'jev_api_key'];

/** Фоновое задание: чей доступ брать (ai_jobs.user_id); null — общий. */
function aiAccessUse(?int $userId): void
{
    $GLOBALS['goblin_access_user'] = $userId;
}

/** Действующий доступ: личные поля поверх общих. */
function aiAccess(): array
{
    if (array_key_exists('goblin_access_user', $GLOBALS)) {
        $userId = $GLOBALS['goblin_access_user'];
    } else {
        try { $userId = caller()['user_id'] ?? null; } catch (Throwable) { $userId = null; }
    }
    return aiAccessOf($userId ? (int) $userId : null);
}

/** Доступ этого человека: его непустые поля поверх общих; null — только общие. */
function aiAccessOf(?int $userId): array
{
    $c = config();
    $out = [];
    foreach (ACCESS_FIELDS as $field) $out[$field] = (string) ($c[$field] ?? '');
    foreach (aiAccessOwn($userId) as $field => $value) {
        if ($value !== '') $out[$field] = $value;
    }
    return aiAccessPair($out);
}

/** Общий ключ — только на общий адрес: при своём адресе он стирается (свой ключ остаётся). Нет адреса — общий. */
function aiAccessPair(array $access): array
{
    $c = config();
    foreach (ACCESS_PAIRS as $url => $key) {
        $sharedKey = (string) ($c[$key] ?? '');
        $foreign = isset($access[$url]) && rtrim((string) $access[$url], '/') !== rtrim((string) ($c[$url] ?? ''), '/');
        if ($foreign && $sharedKey !== '' && ($access[$key] ?? $sharedKey) === $sharedKey) $access[$key] = '';
    }
    return $access;
}

/** Проект запроса: Jev прогона работает на доступе его владельца. Зовёт requireProject(). */
function aiAccessProject(array $project): void
{
    $GLOBALS['goblin_project_owner'] = $project['owner_id'] ? (int) $project['owner_id'] : null;
    $GLOBALS['goblin_call_project'] = (int) $project['id'];   // журнал вызовов моделей (lib/ai/calls.php)
}

/** Доступ Jev: личный владельца проекта, без него — общий (решение хозяина 30.09.2026). */
function jevAccess(): array
{
    $owner = $GLOBALS['goblin_project_owner'] ?? null;
    $out = aiAccessOf($owner);
    // Проект гостя (без владельца), а платный ИИ гостям выключен: общего ключа Jev ему не даём.
    if (!$owner && array_key_exists('goblin_project_owner', $GLOBALS) && !(config()['guest_ai'] ?? true)) {
        $out['jev_api_key'] = '';
    }
    return $out;
}

/** Копия общих значений для нового человека — JSON в users.access. Пустые и ИИ помощника (он за админкой) не кладём. */
function aiAccessDefaults(): string
{
    $shared = array_diff_key(aiAccessOf(null), array_flip(ACCESS_AI));
    $shared = array_filter($shared, static fn($value) => $value !== '');
    return json_encode((object) $shared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Маска ключа по умолчанию в кабинете; пришла обратно из формы — «ключ не меняли». */
const ACCESS_MASK = '************';

/** Ключ по умолчанию: личное значение совпадает с общим ключом установки — показываем только звёздочки. */
function aiAccessHidden(string $field, string $value): bool
{
    return str_ends_with($field, '_key') && $value !== '' && $value === (string) (config()[$field] ?? '');
}

/** Личные поля человека — только заданные. */
function aiAccessOwn(?int $userId): array
{
    if (!$userId) return [];
    $own = json_decode((string) dbValue('SELECT access FROM users WHERE id = ?', [$userId]), true);
    if (!is_array($own)) return [];
    $own = array_map('strval', array_intersect_key($own, array_flip(ACCESS_FIELDS)));
    // ИИ помощника — своё только со своим ключом: копия общего ключа идёт за рабочим подключением админки.
    $key = $own['ai_api_key'] ?? '';
    if ($key === '' || in_array($key, aiSharedKeys(), true)) $own = array_diff_key($own, array_flip(ACCESS_AI));
    return $own;
}

/** Ключи всех подключений ИИ установки (и прежний ai_api_key): такой ключ в кабинете — не свой. */
function aiSharedKeys(): array
{
    $c = config();
    $keys = [(string) ($c['ai_api_key'] ?? '')];
    foreach (array_keys((array) ($c['ai_connections'] ?? [])) as $id) $keys[] = (string) ($c['ai_key_' . $id] ?? '');
    return array_values(array_filter($keys, static fn($key) => $key !== ''));
}

/**
 * Опции cURL для запроса к модели по адресу $url из поля $field. Общий адрес установки (тот же сервер) —
 * только http и https. Свой адрес человека — только https на внешний сервер: имя сверяется с IP и закрепляется
 * (CURLOPT_RESOLVE), чтобы подмена DNS между проверкой и запросом не увела его внутрь. Иначе — ApiError.
 */
function aiUrlGuard(string $url, string $field): array
{
    $web = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    $opts = [CURLOPT_PROTOCOLS => $web, CURLOPT_REDIR_PROTOCOLS => $web, CURLOPT_FOLLOWLOCATION => false];
    $origin = static fn(string $u): string => strtolower(parse_url($u, PHP_URL_SCHEME) . '://' . parse_url($u, PHP_URL_HOST) . ':' . parse_url($u, PHP_URL_PORT));
    $shared = (string) (config()[$field] ?? '');
    if ($shared !== '' && $origin($url) === $origin($shared)) return $opts;

    $parts = parse_url($url) ?: [];
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    $bad = new ApiError(t('server.ai.bad_url', ['url' => mb_substr($url, 0, 120)]), 'forbidden');
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '') throw $bad;
    $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
    $ips = $literal ? [$host] : (gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw $bad;
    }
    if (!$ips) throw $bad;
    $opts = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS] + $opts;
    if (!$literal) $opts[CURLOPT_RESOLVE] = [$host . ':' . (int) ($parts['port'] ?? 443) . ':' . $ips[0]];
    return $opts;
}

/** Сохранить личный доступ из формы кабинета: пустое поле — вернуться к общему. Свой адрес сбрасывает ключ по умолчанию. */
function aiAccessSave(int $userId, array $input): void
{
    $before = aiAccessOwn($userId);
    $own = [];
    foreach (ACCESS_FIELDS as $field) {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === ACCESS_MASK) $value = $before[$field] ?? '';   // скрытый ключ по умолчанию — прежний
        if ($value !== '' && isset(ACCESS_PAIRS[$field])) aiUrlGuard($value, $field);   // плохой адрес — отказ сразу
        if ($value !== '') $own[$field] = mb_substr($value, 0, 500);
    }
    $own = array_filter(aiAccessPair($own), static fn($value) => $value !== '');
    dbRun('UPDATE users SET access = ? WHERE id = ?', [json_encode($own, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId]);
}
