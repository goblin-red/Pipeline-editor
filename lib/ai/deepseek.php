<?php
/* Разговор с моделью: один HTTP-вызов и разбор ответа.
   Отдаёт: aiAsk().
   Не делает: не знает, о чём спрашивают — контекст собирает ai/context.php.

   Ответ всегда JSON: модель просят вернуть строгий объект, при первой неудаче
   переспрашивают один раз. Сырой ответ, рассуждения модели (reasoning_content)
   и расход токенов возвращаются вызвавшему. */

declare(strict_types=1);

function aiAsk(array $messages, array $options = []): array
{
    $c = aiAccess();   // свой доступ человека или общий установки (lib/ai/access.php)
    $body = [
        'model' => $options['model'] ?? $c['ai_model'],
        'messages' => $messages,
        'temperature' => $options['temperature'] ?? 0.2,
        'response_format' => ['type' => 'json_object'],
    ];

    $answer = aiHttp($c['ai_api_url'], $c['ai_api_key'], $body);
    $usage = aiUsageOf($answer);
    $text = $answer['choices'][0]['message']['content'] ?? '';
    // Модель думает вслух в отдельном поле: показываем это человеку сворачиваемым блоком.
    $think = trim((string) ($answer['choices'][0]['message']['reasoning_content'] ?? ''));
    $data = json_decode($text, true);

    // Одна попытка исправить формат: дальше это уже не наша забота.
    // Расход второго вызова прибавляется к первому: платим за оба.
    if (!is_array($data)) {
        $messages[] = ['role' => 'assistant', 'content' => $text];
        $messages[] = ['role' => 'user', 'content' => ta('agents.ai.retry_json')];
        $body['messages'] = $messages;
        $answer = aiHttp($c['ai_api_url'], $c['ai_api_key'], $body);
        $more = aiUsageOf($answer);
        foreach (['prompt', 'completion', 'total', 'cached'] as $key) $usage[$key] += $more[$key];
        $usage['known'] = $usage['known'] && $more['known'];
        $text = $answer['choices'][0]['message']['content'] ?? '';
        $second = trim((string) ($answer['choices'][0]['message']['reasoning_content'] ?? ''));
        if ($second !== '') $think = trim($think . ta('agents.ai.think_reask') . $second);
        $data = json_decode($text, true);
    }

    return [
        'data'  => is_array($data) ? $data : null,
        'raw'   => $text,
        'think' => $think,
        'model' => $answer['model'] ?? $body['model'],
        'usage' => $usage,
    ];
}

/** Расход одного вызова. */
function aiUsageOf(array $answer): array
{
    return [
        'prompt' => (int) ($answer['usage']['prompt_tokens'] ?? 0),
        'completion' => (int) ($answer['usage']['completion_tokens'] ?? 0),
        'total' => (int) ($answer['usage']['total_tokens'] ?? 0),
        'cached' => (int) ($answer['usage']['prompt_cache_hit_tokens'] ?? 0),
        'known' => isset($answer['usage']),
    ];
}

function aiHttp(string $url, string $key, array $body): array
{
    /* Заглушка транспорта для проверок без сети (tests/v2/check-constructor.php): функция
       получает тело запроса и отдаёт ответ в форме API — как у Jev (tests/v2/jev-stub.php). */
    if (isset($GLOBALS['aiFake']) && is_callable($GLOBALS['aiFake'])) return ($GLOBALS['aiFake'])($body);

    $started = microtime(true);
    $log = static fn(array $data, string $error) => apiCallLog([
        'service' => 'deepseek', 'model' => (string) ($data['model'] ?? $body['model'] ?? ''),
        'in' => (int) ($data['usage']['prompt_tokens'] ?? 0), 'out' => (int) ($data['usage']['completion_tokens'] ?? 0),
        'ms' => (int) round((microtime(true) - $started) * 1000), 'ok' => $error === '', 'error' => $error,
    ]);

    /* Ответ потоком: так видно, когда модель начала писать (рассуждение или ответ). Перегруженный DeepSeek
       держит запрос в очереди минутами, присылая только «: keep-alive», — ждём первых слов не дольше
       ai_timeout секунд (админка → подключение ИИ), дальше обрыв. Начала писать — пишет сколько нужно (до 10 минут). */
    $wait = max(5, (int) (config()['ai_timeout'] ?? 40));
    $body['stream'] = true;
    $body['stream_options'] = ['include_usage' => true];

    $line = '';                    // недочитанная строка потока
    $plain = '';                   // ответ не потоком (отказ сервера или адрес без потока) — целиком
    $streamed = false;             // пришло хоть одно событие data:
    $begun = false;                // модель начала писать
    $out = ['content' => '', 'reasoning' => '', 'model' => '', 'usage' => null, 'error' => ''];

    $ch = curl_init($url);
    curl_setopt_array($ch, aiUrlGuard($url, 'ai_api_url') + [
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => min(10, $wait),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$line, &$plain, &$streamed, &$begun, &$out): int {
            if (!$streamed) $plain .= $chunk;
            $line .= $chunk;
            while (($end = strpos($line, "\n")) !== false) {
                $one = rtrim(substr($line, 0, $end), "\r");
                $line = substr($line, $end + 1);
                // «: keep-alive» и пустые строки — запрос ещё в очереди, это не ответ.
                if (!str_starts_with($one, 'data:')) continue;
                $event = json_decode(trim(substr($one, 5)), true);
                if (!is_array($event)) continue;   // data: [DONE]
                $streamed = true;
                if (!empty($event['model'])) $out['model'] = (string) $event['model'];
                if (!empty($event['usage'])) $out['usage'] = $event['usage'];
                // OpenRouter сообщает об ошибке посреди потока отдельным событием.
                if (!empty($event['error'])) $out['error'] = (string) ($event['error']['message'] ?? json_encode($event['error']));
                $delta = $event['choices'][0]['delta'] ?? [];
                $content = (string) ($delta['content'] ?? '');
                $reasoning = (string) ($delta['reasoning_content'] ?? $delta['reasoning'] ?? '');   // reasoning — OpenRouter
                $out['content'] .= $content;
                $out['reasoning'] .= $reasoning;
                if ($content !== '' || $reasoning !== '') $begun = true;
            }
            return strlen($chunk);
        },
        // Ненулевой ответ обрывает запрос: первых слов нет дольше предела.
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => static function () use (&$begun, $started, $wait): int {
            return !$begun && microtime(true) - $started > $wait ? 1 : 0;
        },
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    if ($errno !== 0 && !$begun) {
        $log([], $error);
        if ($errno === CURLE_ABORTED_BY_CALLBACK) throw new ApiError(t('agents.ai.model_slow', ['sec' => $wait]), 'server');
        throw new ApiError(t('agents.ai.model_silent', ['error' => $error]), 'server');
    }
    if ($errno !== 0) {
        // Начала писать и оборвалась (сеть, предел в 10 минут): недописанный ответ не годится.
        $log(['model' => $out['model'], 'usage' => $out['usage']], $error);
        throw new ApiError(t('agents.ai.model_silent', ['error' => $error]), 'server');
    }
    if (!$streamed) {
        // Не поток: отказ сервера (JSON с ошибкой) или адрес, который поток не умеет, — ответ целиком.
        $data = json_decode(trim((string) preg_replace('/^:.*$/m', '', $plain)), true);   // без «: keep-alive»
        $data = is_array($data) ? $data : [];
        if ($code >= 400 || !isset($data['choices'])) {
            $why = $data['error']['message'] ?? t('agents.ai.code', ['code' => $code]);
            $log($data, (string) $why);
            throw new ApiError(t('agents.ai.model_refused', ['why' => $why]), 'server');
        }
        $log($data, '');
        return $data;
    }
    if ($out['error'] !== '' && $out['content'] === '') {
        $log(['model' => $out['model'], 'usage' => $out['usage']], $out['error']);
        throw new ApiError(t('agents.ai.model_refused', ['why' => $out['error']]), 'server');
    }
    // Поток собран в ту же форму, что ответ целиком: aiAsk() разницы не видит.
    $data = [
        'model' => $out['model'] ?: (string) ($body['model'] ?? ''),
        'choices' => [['message' => ['content' => $out['content'], 'reasoning_content' => $out['reasoning']]]],
    ];
    if ($out['usage']) $data['usage'] = $out['usage'];
    $log($data, '');
    return $data;
}
