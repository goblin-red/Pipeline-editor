<?php
/* Журнал вызовов платных моделей: DeepSeek и Jev — строка на вызов (таблица api_calls, миграция 033).
   Отдаёт: apiCallLog(), apiCallAbout(), apiCallJev(), apiBalance(), apiBalanceOf().
   Не делает: не звонит сам и не решает, звонить ли; сбой записи вызов не ломает.

   Кто звонил: DeepSeek — чей доступ взят (фоновое задание — спросивший, иначе вошедший человек);
   Jev — владелец проекта (его доступ, lib/ai/access.php). Проект ставит requireProject()
   (aiAccessProject) или фоновое задание (apiCallAbout). Админка показывает журнал в разделе «ИИ». */

declare(strict_types=1);

// Jev-клиент (lib/ai/jev/client.php) базы не знает: о каждом ответе он сообщает наблюдателю.
$GLOBALS['jevReport'] = 'apiCallJev';

/** Для чего звоним и в каком проекте — ставит тот, кто начинает работу (фоновое задание ИИ). */
function apiCallAbout(?int $projectId, string $what = ''): void
{
    $GLOBALS['goblin_call_project'] = $projectId;
    $GLOBALS['goblin_call_what'] = $what;
}

/**
 * Записать один вызов. $row: service (deepseek | jev), what, model, in, out (токены), ms, ok, error;
 * user — если не задан, берётся по правилу из шапки.
 */
function apiCallLog(array $row): void
{
    $service = (string) $row['service'];
    if (array_key_exists('user', $row)) {
        $user = $row['user'];
    } elseif ($service === 'jev') {
        $user = $GLOBALS['goblin_project_owner'] ?? null;
    } elseif (array_key_exists('goblin_access_user', $GLOBALS)) {
        $user = $GLOBALS['goblin_access_user'];
    } else {
        try { $user = caller()['user_id'] ?? null; } catch (Throwable) { $user = null; }
    }
    try {
        dbRun('INSERT INTO api_calls (service, what, user_id, project_id, model, tokens_in, tokens_out, ms, ok, error)
               VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $service,
            mb_substr((string) ($row['what'] ?? $GLOBALS['goblin_call_what'] ?? ''), 0, 24),
            $user ? (int) $user : null,
            ($GLOBALS['goblin_call_project'] ?? null) ? (int) $GLOBALS['goblin_call_project'] : null,
            mb_substr((string) ($row['model'] ?? ''), 0, 80),
            max(0, (int) ($row['in'] ?? 0)),
            max(0, (int) ($row['out'] ?? 0)),
            max(0, (int) ($row['ms'] ?? 0)),
            !empty($row['ok']) ? 1 : 0,
            mb_substr((string) ($row['error'] ?? ''), 0, 300),
        ]);
    } catch (Throwable) {
        // Журнал — справка: его сбой (нет таблицы до миграции, база занята) вызов не ломает.
    }
}

/** Наблюдатель Jev-клиента: ответ jevCall() — строкой журнала. Ромб или приёмка — по заданным вопросам. */
function apiCallJev(array $said): void
{
    apiCallLog([
        'service' => 'jev',
        'what'    => in_array('branch', $said['asked'] ?? [], true) ? 'branch' : 'step',
        'model'   => (string) ($said['model'] ?? (jevAccess()['jev_model'] ?? '')),
        'in'      => (int) ($said['tokensIn'] ?? 0),
        'out'     => (int) ($said['tokensOut'] ?? 0),
        'ms'      => (int) ($said['ms'] ?? 0),
        'ok'      => !empty($said['ok']),
        'error'   => empty($said['ok']) ? trim((string) ($said['why'] ?? '') . ' ' . (string) ($said['detail'] ?? '')) : '',
    ]);
}

/**
 * Остаток на счетах по общим ключам установки — для «Обзора» админки. Ключ — подключения ИИ (ai_key_<имя>),
 * какое бы из них ни было рабочим. DeepSeek: GET /user/balance; OpenRouter: GET /credits (куплено и потрачено).
 * Ответ: ['amount' => '4.63', 'currency' => 'USD', …]; не удалось — ['why' => no_key | silent].
 * Jev (TypeSafe) баланса по API не отдаёт — null.
 */
function apiBalance(): array
{
    $c = config();
    $ask = [
        'deepseek'   => ['https://api.deepseek.com/user/balance', (string) ($c['ai_key_deepseek'] ?? '')],
        'openrouter' => ['https://openrouter.ai/api/v1/credits', (string) ($c['ai_key_openrouter'] ?? '')],
    ];

    // Запросы разом: «Обзор» ждёт самый медленный ответ, а не их сумму.
    $multi = curl_multi_init();
    $handles = [];
    foreach ($ask as $name => [$url, $key]) {
        if ($key === '') continue;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json']]);
        curl_multi_add_handle($multi, $ch);
        $handles[$name] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach (array_keys($ask) as $name) {
        if (!isset($handles[$name])) {
            $out[$name] = ['why' => 'no_key'];
            continue;
        }
        $data = json_decode((string) curl_multi_getcontent($handles[$name]), true);
        curl_multi_remove_handle($multi, $handles[$name]);
        $out[$name] = apiBalanceOf($name, is_array($data) ? $data : []);
    }
    curl_multi_close($multi);
    return $out + ['jev' => null];
}

/** Ответ сервиса о счёте → сумма с валютой; непонятный ответ — ['why' => 'silent']. */
function apiBalanceOf(string $name, array $data): array
{
    if ($name === 'openrouter') {
        $total = $data['data']['total_credits'] ?? null;
        $used = $data['data']['total_usage'] ?? null;
        if (!is_numeric($total) || !is_numeric($used)) return ['why' => 'silent'];
        $money = static fn(float $sum): string => number_format($sum, 2, '.', '');
        return ['amount' => $money($total - $used), 'currency' => 'USD', 'available' => true,
                'total' => $money((float) $total), 'used' => $money((float) $used)];
    }
    $info = $data['balance_infos'][0] ?? null;
    return is_array($info)
        ? ['amount' => (string) ($info['total_balance'] ?? ''), 'currency' => (string) ($info['currency'] ?? ''),
           'available' => !empty($data['is_available'])]
        : ['why' => 'silent'];
}
