<?php
/* Голос: живое распознавание речи и озвучка ответов (OpenAI).
   Отдаёт: voiceSession(), voiceSpeak().
   Не делает: не слушает микрофон и не играет звук — это браузер (public/src/panel/voice.js).

   Ключ OpenAI в браузер не уходит: для распознавания сервер выдаёт временный пропуск на одну
   сессию (realtime/client_secrets), браузер с ним подключается к OpenAI по WebRTC сам. Озвучку
   сервер заказывает сам и отдаёт звук. Доступ — свой человека или общий (lib/ai/access.php). */

declare(strict_types=1);

/** Запрос к OpenAI от имени доступа: JSON туда, [код, тело, тип] обратно. */
function voiceHttp(string $path, array $body, array $access): array
{
    if ($access['voice_api_key'] === '') throw new ApiError(t('server.voice.no_key'), 'forbidden');
    $url = rtrim($access['voice_api_url'], '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, aiUrlGuard($url, 'voice_api_url') + [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $access['voice_api_key']],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $text = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    if ($text === false) throw new ApiError(t('server.voice.silent', ['error' => $error]), 'server');
    if ($code >= 400) {
        $why = json_decode((string) $text, true)['error']['message'] ?? ('HTTP ' . $code);
        throw new ApiError(t('server.voice.refused', ['why' => $why]), 'server');
    }
    return [$code, (string) $text, $type];
}

/** POST voice.session — временный пропуск на живое распознавание: {value, expires, url}. */
function voiceSession(): void
{
    requireProject(false);   // говорить может участник проекта
    $access = aiAccess();
    $transcription = ['model' => $access['voice_model']];
    // Языки речи — подсказка модели; пусто — любой. gpt-live-transcribe ждёт список (languages).
    $langs = array_values(array_filter(array_map('trim', explode(',', $access['voice_languages']))));
    if ($langs) $transcription[str_starts_with($access['voice_model'], 'gpt-live') ? 'languages' : 'language'] =
        str_starts_with($access['voice_model'], 'gpt-live') ? $langs : $langs[0];
    [, $text] = voiceHttp('/realtime/client_secrets', [
        'expires_after' => ['anchor' => 'created_at', 'seconds' => 600],
        'session' => [
            'type' => 'transcription',
            'audio' => ['input' => [
                'transcription' => $transcription,
                'noise_reduction' => ['type' => 'near_field'],
                // Без разбиения на реплики: запись идёт, пока человек не нажмёт «стоп».
                'turn_detection' => null,
            ]],
        ],
    ], $access);
    $data = json_decode($text, true) ?: [];
    if (empty($data['value'])) throw new ApiError(t('server.voice.refused', ['why' => 'no client secret']), 'server');
    reply(['value' => (string) $data['value'], 'expires' => (int) ($data['expires_at'] ?? 0),
           'url' => rtrim($access['voice_api_url'], '/')]);
}

/** POST voice.speak {text} — озвучить ответ: звук mp3 прямо в ответе. */
function voiceSpeak(): void
{
    requireProject(false);
    $input = trim((string) (input('text') ?? ''));
    if ($input === '') throw new ApiError(t('server.voice.no_text'));
    $access = aiAccess();
    [, $audio, $type] = voiceHttp('/audio/speech', [
        'model' => $access['voice_tts_model'],
        'voice' => $access['voice_tts_voice'],
        'input' => mb_substr($input, 0, 4000),
        'instructions' => t('server.voice.tts_style'),
        'response_format' => 'mp3',
    ], $access);
    header('Content-Type: ' . ($type ?: 'audio/mpeg'));
    header('Cache-Control: no-store');
    echo $audio;
    exit;
}
