<?php
/* Клиент TypeSafe Jev.
   Что делает: один запрос с общим сроком на все попытки, повторы на 429/529/5xx/обрыв,
               строгая проверка ответа (все вопросы, нужные типы, числа в 0…1, та же модель).
   Что отдаёт: jevCall() → ['ok' => true, 'answers', 'model', 'tokens', 'ms', 'tries']
               либо ['ok' => false, 'why' => timeout|network|rate|overloaded|auth|bad_request|
               bad_answer|other_model, 'ms', 'tries', 'code', 'detail'].
   Чего не делает: не решает, не пишет в журнал и базу (о каждом ответе лишь сообщает наблюдателю
                   jevReport()), не бросает исключений,
                   не печатает ключ. Вызывать ВНЕ блокировки проекта.

   Транспорт подменяется: 4-й параметр или $GLOBALS['jevSend'] — для проверок без сети.
   Транспорт: fn(string $url, array $headers, string $body, float $timeout): array
     ['code' => int, 'body' => string, 'headers' => [имя в нижнем регистре => значение],
      'error' => ?string, 'timeout' => bool] */

declare(strict_types=1);

require_once __DIR__ . '/../../core/i18n.php';   // проверки грузят файл отдельно от boot.php: ta() нужен и им

/** Паузы перед 2-й и 3-й попыткой, секунды. */
const JEV_PAUSES = [0.5, 1.5];

/** Меньше этого остатка срока новую попытку не начинаем. */
const JEV_MIN_TRY = 0.2;

function jevCall(array $state, array $questions, float $deadline = 5.0, ?callable $send = null): array
{
    $send  ??= (isset($GLOBALS['jevSend']) && is_callable($GLOBALS['jevSend'])) ? $GLOBALS['jevSend'] : 'jevSendCurl';
    $c     = jevAccess();   // личный Jev владельца проекта или общий (lib/ai/access.php)
    $model = (string) ($c['jev_model'] ?? '');

    $body = json_encode(
        ['state' => $state, 'model' => $model, 'questions' => $questions],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $headers = [
        'Authorization: Bearer ' . (string) ($c['jev_api_key'] ?? ''),
        'Content-Type: application/json',
    ];

    $start = microtime(true);
    $tries = 0;

    while (true) {
        $left = $deadline - (microtime(true) - $start);
        $tries++;
        $r = jevSendSafe($send, (string) ($c['jev_api_url'] ?? ''), $headers, (string) $body, $left);

        // Разбор ответа
        [$why, $retry] = jevClassify($r);
        if ($why === null) {
            $done = jevParse((string) $r['body'], $questions, $model);
            $done['ms']    = jevMs($start);
            $done['tries'] = $tries;
            if (!$done['ok']) $done['code'] = 200;
            return jevReport($done, $questions, $send);
        }

        $fail = ['ok' => false, 'why' => $why, 'ms' => 0, 'tries' => $tries,
                 'code' => (int) ($r['code'] ?? 0), 'detail' => jevDetail($r)];

        // Повторять нельзя или попытки кончились
        if (!$retry || $tries > count(JEV_PAUSES)) {
            $fail['ms'] = jevMs($start);
            return jevReport($fail, $questions, $send);
        }

        // Пауза: Retry-After от сервиса, иначе своя
        $pause = JEV_PAUSES[$tries - 1];
        $after = $r['headers']['retry-after'] ?? null;
        if (is_string($after) && is_numeric(trim($after)) && (float) $after >= 0) $pause = (float) $after;

        // Пауза не влезает в общий срок — дальше не ждём
        if ((microtime(true) - $start) + $pause + JEV_MIN_TRY > $deadline) {
            $fail['ms'] = jevMs($start);
            return jevReport($fail, $questions, $send);
        }
        usleep((int) round($pause * 1e6));
    }
}

/**
 * Сообщить наблюдателю об ответе ($GLOBALS['jevReport'] — журнал вызовов lib/ai/calls.php) и вернуть его.
 * Наблюдателя нет (проверки грузят клиент отдельно) или транспорт подменён проверкой — ничего:
 * в журнал идут только настоящие вызовы. Сбой наблюдателя ответа не портит.
 */
function jevReport(array $said, array $questions, callable|string $send): array
{
    $hook = $GLOBALS['jevReport'] ?? null;
    if (is_callable($hook) && $send === 'jevSendCurl') {
        try { $hook($said + ['asked' => array_keys($questions)]); } catch (Throwable) {}
    }
    return $said;
}

/** Вызов транспорта: сбой транспорта — это обрыв, а не падение программы. */
function jevSendSafe(callable $send, string $url, array $headers, string $body, float $left): array
{
    if ($left < JEV_MIN_TRY) return ['code' => 0, 'body' => '', 'headers' => [], 'error' => ta('agents.ai.jev_time_out'), 'timeout' => true];
    try {
        $r = $send($url, $headers, $body, $left);
    } catch (Throwable $e) {
        return ['code' => 0, 'body' => '', 'headers' => [], 'error' => $e->getMessage(), 'timeout' => false];
    }
    return is_array($r) ? $r + ['code' => 0, 'body' => '', 'headers' => [], 'error' => null, 'timeout' => false]
                        : ['code' => 0, 'body' => '', 'headers' => [], 'error' => ta('agents.ai.jev_transport'), 'timeout' => false];
}

/** Код ответа → [почему, можно ли повторить]; [null, false] — это 200. */
function jevClassify(array $r): array
{
    if (!empty($r['timeout'])) return ['timeout', true];

    $code = (int) $r['code'];
    if ($code === 0 || !empty($r['error'])) return ['network', true];
    if ($code === 200) return [null, false];

    if ($code === 401 || $code === 403) return ['auth', false];
    if ($code === 429)                  return ['rate', true];
    if ($code === 529 || $code >= 500)  return ['overloaded', true];
    return ['bad_request', false];
}

/** Ответ 200: та ли модель и полный ли ответ. */
function jevParse(string $raw, array $questions, string $model): array
{
    $data = json_decode($raw, true);
    if (!is_array($data) || !is_array($data['answers'] ?? null)) {
        return ['ok' => false, 'why' => 'bad_answer', 'detail' => ta('agents.ai.jev_not_json')];
    }
    if (($data['model'] ?? null) !== $model) {
        return ['ok' => false, 'why' => 'other_model', 'detail' => ta('agents.ai.jev_answered', ['model' => (string) ($data['model'] ?? '?')])];
    }

    $answers = [];
    foreach ($questions as $id => $q) {
        $a = $data['answers'][$id] ?? null;
        if (!is_array($a) || !jevAnswerOk($a, $q)) {
            return ['ok' => false, 'why' => 'bad_answer', 'detail' => ta('agents.ai.jev_bad_answer', ['id' => $id])];
        }
        $answers[$id] = $a;
    }

    $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
    return [
        'ok'      => true,
        'answers' => $answers,
        'model'   => $data['model'],
        'tokens'  => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0),
        'tokensIn'  => (int) ($usage['input_tokens'] ?? 0),
        'tokensOut' => (int) ($usage['output_tokens'] ?? 0),
    ];
}

/** Ответ нужного типа и с правильными числами. */
function jevAnswerOk(array $a, array $q): bool
{
    $type = (string) ($q['type'] ?? '');
    if (($a['type'] ?? null) !== $type) return false;

    if ($type === 'noul') return jevUnit($a['noul'] ?? null);

    if ($type === 'choice') {
        $options = array_map('strval', array_keys($q['criteria'] ?? []));
        if (!in_array($a['choice'] ?? null, $options, true)) return false;
        if (!jevUnit($a['confidence'] ?? null)) return false;
        foreach ((array) ($a['probabilities'] ?? []) as $p) {
            if (!jevUnit($p)) return false;
        }
        return true;
    }

    if ($type === 'score') {
        $s = $a['score'] ?? null;
        return (is_int($s) || is_float($s)) && is_finite((float) $s) && jevUnit($a['confidence'] ?? null);
    }
    return false;
}

/** Конечное число от 0 до 1. */
function jevUnit($v): bool
{
    if (!is_int($v) && !is_float($v)) return false;
    $v = (float) $v;
    return is_finite($v) && $v >= 0.0 && $v <= 1.0;
}

function jevMs(float $start): int
{
    return (int) round((microtime(true) - $start) * 1000);
}

/** Короткое описание сбоя для журнала: без заголовков запроса и ключа. */
function jevDetail(array $r): string
{
    $text = !empty($r['error']) ? (string) $r['error'] : (string) ($r['body'] ?? '');
    return mb_substr(trim($text), 0, 200, 'UTF-8');
}

/** Настоящий транспорт: cURL (обёртка потоков под Apache на https зависала). */
function jevSendCurl(string $url, array $headers, string $body, float $timeout): array
{
    // Без ключа в сеть не ходим
    if (preg_match('/^Authorization: Bearer\s*$/', $headers[0] ?? '')) {
        return ['code' => 401, 'body' => ta('agents.ai.jev_no_ts_key'), 'headers' => [], 'error' => null, 'timeout' => false];
    }

    $got = [];
    $ch  = curl_init($url);
    curl_setopt_array($ch, aiUrlGuard($url, 'jev_api_url') + [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => $body,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_HTTPHEADER        => $headers,
        CURLOPT_NOSIGNAL          => true,
        CURLOPT_TIMEOUT_MS        => max(1, (int) ($timeout * 1000)),
        CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) (min($timeout, 3.0) * 1000)),
        CURLOPT_HEADERFUNCTION    => function ($ch, string $line) use (&$got): int {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) $got[strtolower(trim($pair[0]))] = trim($pair[1]);
            return strlen($line);
        },
    ]);

    $answer = curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return [
        'code'    => $answer === false ? 0 : $code,
        'body'    => $answer === false ? '' : (string) $answer,
        'headers' => $got,
        'error'   => $answer === false ? ($error ?: ta('agents.ai.no_link')) : null,
        'timeout' => $errno === CURLE_OPERATION_TIMEDOUT,
    ];
}
