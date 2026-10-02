<?php
/* Разбор запроса и единственный способ ответить.
   Отдаёт: ApiError, request(), input(), reply(), fail(), clientIp(), requireSameOrigin().
   Не делает: не решает права (это access/rights.php) и не трогает базу. */

declare(strict_types=1);

/** Ошибка, понятная человеку и клиенту. Код определяет статус HTTP. */
class ApiError extends RuntimeException
{
    public string $errCode;
    public array $extra;

    public function __construct(string $message, string $code = 'invalid', array $extra = [])
    {
        parent::__construct($message);
        $this->errCode = $code;
        $this->extra = $extra;
    }

    public function status(): int
    {
        return match ($this->errCode) {
            'unauthorized' => 401,
            'forbidden', 'scope' => 403,
            'not_found'    => 404,
            'conflict'     => 409,
            default        => 422,
        };
    }
}

/** Запрос целиком: что вызвали и с чем. Читается один раз. */
function request(): array
{
    static $req = null;
    if ($req !== null) return $req;

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $body = [];
    $files = [];

    if ($method === 'POST') {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_starts_with($type, 'multipart/form-data')) {
            // Загрузка файла — поля в payload (JSON); форма без payload — поля как есть (curl -F).
            $body = isset($_POST['payload']) ? (json_decode((string) $_POST['payload'], true) ?: []) : $_POST;
            $files = $_FILES['file'] ?? [];
        } elseif (str_starts_with($type, 'application/x-www-form-urlencoded')) {
            // Обычная форма: кабинет, админка и сдача простого пути (curl --data-urlencode).
            $body = $_POST;
        } else {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $body = json_decode($raw, true);
                if (!is_array($body)) throw new ApiError(t('server.api.body_not_json'));
            }
        }
    }

    $req = [
        'method'  => $method,
        'op'      => (string) ($_GET['op'] ?? $body['op'] ?? ''),
        'query'   => $_GET,
        'body'    => $body,
        'files'   => $files,
        'token'   => trim((string) ($_SERVER['HTTP_X_GOBLIN_TOKEN'] ?? '')),
        'project' => (string) ($_GET['project'] ?? $body['project'] ?? ''),
    ];
    return $req;
}

/**
 * Запись — только со своих страниц (Б5). К POST браузер прикладывает Origin: чужой сайт получает отказ,
 * иначе открытая вкладка писала бы в localhost/goblin от имени человека. Агенты (curl) Origin не шлют;
 * пропуск в заголовке — не cookie, его чужая страница не подставит.
 */
function requireSameOrigin(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || $origin === '' || !empty($_SERVER['HTTP_X_GOBLIN_TOKEN'])) return;
    $port = parse_url($origin, PHP_URL_PORT);
    $from = strtolower(parse_url($origin, PHP_URL_HOST) . ($port ? ':' . $port : ''));
    if ($from !== strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        throw new ApiError(t('server.api.foreign_origin'), 'forbidden');
    }
}

/** Значение параметра: сначала из тела, потом из адреса. */
function input(string $name, $fallback = null)
{
    $req = request();
    return $req['body'][$name] ?? $req['query'][$name] ?? $fallback;
}

function inputInt(string $name, ?int $fallback = null): ?int
{
    $v = input($name);
    if ($v === null || $v === '') return $fallback;
    if (!is_numeric($v)) throw new ApiError(t('server.api.expected_number', ['name' => $name]));
    return (int) $v;
}

/** Удачный ответ. Всегда одинаковой формы. */
function reply(array $data = [], array $warnings = []): void
{
    $body = array_merge(['ok' => true], $data);
    if (function_exists('journalSingleWrite')) journalSingleWrite($body);
    if ($warnings) $body['warnings'] = array_values($warnings);
    sendJson($body, 200);
}

/** Отказ. Причина и код — одной строкой, без вложенных структур. */
function fail(string $message, string $code = 'invalid', array $extra = [], int $status = 0): void
{
    $body = array_merge(['ok' => false, 'error' => $message, 'code' => $code], $extra);
    $e = new ApiError($message, $code);
    sendJson($body, $status ?: $e->status());
}

function sendJson(array $body, int $status): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, X-Goblin-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
    $text = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Длина — чтобы браузер получил ответ сразу, даже если запрос ещё дорабатывает задание ИИ (aiLaunch).
    if (!headers_sent() && !ini_get('zlib.output_compression')) header('Content-Length: ' . strlen($text));
    echo $text;
    exit;
}

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}
