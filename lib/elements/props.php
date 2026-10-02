<?php
/* Допы: мелкие дополнительные значения элемента.
   Отдаёт: propsPatch(), propsOf(), propsMap(), propsDict(), runProp(), propOn(), folderStarters(), isStarter().
   Не делает: не хранит оформление (оно в style) и не хранит тексты и файлы (они ассеты).

   Правила прогона читает сервер: join, max_attempts, outputs, paid_calls,
   timeout, cond, answer и start — флажок стартера (блок, с которого
   начинается прогон; worker не выдаётся). Шестое — `table`: содержимое таблицы,
   его форму держит elements/table.php. Остальное сервер не толкует. */

declare(strict_types=1);

const RUN_PROPS = ['join', 'max_attempts', 'outputs', 'paid_calls', 'timeout', 'cond', 'answer', 'expr', 'start'];

/** Правка допов: значение null удаляет свойство. */
function propsPatch(int $elementId, array $patch): void
{
    if ($patch) {
        $element = dbRow('SELECT * FROM elements WHERE id = ?', [$elementId]);
        if ($element) activeGuardElement($element, 'edit_rules');
    }
    foreach ($patch as $name => $value) {
        $name = trim((string) $name);
        if ($name === '' || mb_strlen($name) > 64) throw new ApiError(t('server.props.name_length'));

        if ($value === null || $value === '') {
            dbRun('DELETE FROM props WHERE element_id = ? AND name = ?', [$elementId, $name]);
            continue;
        }
        [$type, $text] = propValue($name, $value);
        // Стартер — только блок и только один на папку: второй сервер не даст поставить.
        if ($name === 'start' && $text === '1') {
            $row = dbRow('SELECT id, type, folder_id FROM elements WHERE id = ?', [$elementId]);
            if (!$row || $row['type'] !== 'block') throw new ApiError(t('server.props.starter_block'));
            starterGuard((int) $row['folder_id'], $elementId);
            dbRun('UPDATE elements SET agent_id = NULL WHERE id = ?', [$elementId]);
        }
        dbRun(
            'INSERT INTO props (element_id, name, type, value) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE type = VALUES(type), value = VALUES(value)',
            [$elementId, $name, $type, $text]
        );
    }
}

/**
 * Стартеры папки: блоки с флажком start. Прогон начинается со стартера,
 * стартер worker не выдаётся — движок сразу отмечает его пройденным.
 */
function folderStarters(int $folderId): array
{
    return dbAll(
        "SELECT e.* FROM elements e JOIN props p ON p.element_id = e.id
          WHERE e.folder_id = ? AND e.type = 'block' AND p.name = 'start' AND p.value = '1'
          ORDER BY e.`no`", [$folderId]);
}

/** Второй стартер в папке — отказ: вход прогона должен быть один. */
function starterGuard(int $folderId, int $exceptId): void
{
    foreach (folderStarters($folderId) as $one) {
        if ((int) $one['id'] !== $exceptId) {
            throw new ApiError(t('server.props.starter_exists', ['no' => (int) $one['no']]), 'conflict');
        }
    }
}

/** Блок — стартер прогона? */
function isStarter(int $elementId): bool
{
    return (bool) dbValue("SELECT 1 FROM props WHERE element_id = ? AND name = 'start' AND value = '1'", [$elementId]);
}

/** Флажок правила включён: true, 1 или '1' — так же, как его читают folderStarters и isStarter (value = '1'). */
function propOn($value): bool
{
    return $value === true || (is_scalar($value) && (string) $value === '1');
}

/**
 * Тип значения выводится из самого значения, а подпись берётся из config.txt.
 * Правила прогона пишутся в своём виде, какой бы тип ни прислали: start — флажок '1'/'0'
 * (true/false, 1/0, «yes»/«no»), join — только all или any. Иначе граф и SQL читали бы их по-разному.
 */
function propValue(string $name, $value): array
{
    // Содержимое таблицы — не произвольный JSON: форма одна, и её держит сервер.
    if ($name === TABLE_PROP) {
        return ['json', json_encode(tableNormalize($value), JSON_UNESCAPED_UNICODE)];
    }
    if ($name === 'start') {
        $on = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($on === null) throw new ApiError(t('server.props.start_values'));
        return ['bool', $on ? '1' : '0'];
    }
    if ($name === 'join') {
        if (!in_array($value, ['all', 'any'], true)) throw new ApiError(t('server.props.join_values'));
        return ['text', $value];
    }
    if (is_bool($value))            return ['bool', $value ? '1' : '0'];
    if (is_int($value) || is_float($value)) return ['number', (string) $value];
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        return [$isList ? 'list' : 'json', json_encode($value, JSON_UNESCAPED_UNICODE)];
    }
    return ['text', (string) $value];
}

function propsDecode(array $row)
{
    return match ($row['type']) {
        'number' => 0 + $row['value'],
        'bool'   => $row['value'] === '1',
        'list', 'json' => json_decode($row['value'], true),
        default  => $row['value'],
    };
}

/** Допы одного элемента: имя → значение. */
function propsOf(int $elementId): array
{
    $out = [];
    foreach (dbAll('SELECT name, type, value FROM props WHERE element_id = ? ORDER BY sort, name', [$elementId]) as $row) {
        $out[$row['name']] = propsDecode($row);
    }
    return $out;
}

/** Все допы папки одним запросом: element_id → [имя => значение]. */
function propsMap(int $folderId): array
{
    $rows = dbAll(
        'SELECT p.element_id, p.name, p.type, p.value
           FROM props p JOIN elements e ON e.id = p.element_id
          WHERE e.folder_id = ? ORDER BY p.sort, p.name',
        [$folderId]
    );
    $map = [];
    foreach ($rows as $row) $map[(int) $row['element_id']][$row['name']] = propsDecode($row);
    return $map;
}

/** Правило прогона с запасным значением: runProp($id, 'join', 'all'). */
function runProp(int $elementId, string $name, $fallback)
{
    if (!in_array($name, RUN_PROPS, true)) throw new ApiError(t('server.props.not_run_rule', ['name' => $name]));
    $row = dbRow('SELECT name, type, value FROM props WHERE element_id = ? AND name = ?', [$elementId, $name]);
    return $row ? propsDecode($row) : $fallback;
}

/** Словарь допов для интерфейса: подписи и виды полей из config.txt [props]. */
function propsDict(): array
{
    $out = [];
    foreach (lists()['sections']['props'] ?? [] as $key => $row) {
        $out[] = [
            'name'    => $key,
            'label'   => $row['label'],
            'kind'    => $row['color'] ?: 'text',   // второй столбец здесь — вид поля
            'options' => $row['extra'],
            'run'     => in_array($key, RUN_PROPS, true),
        ];
    }
    return $out;
}
