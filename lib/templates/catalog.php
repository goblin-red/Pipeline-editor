<?php
/* Каталог готовых схем: разделы, карточки, превью и опыт каталога для конструктора.
   Отдаёт: catalogGet(), catalogCard(), catalogPreview(), catalogDigest(), catalogPassport(), catalogPick(), catalogLocal(),
           catalogBodyLocal(), catalogOver().
   Не делает: не разворачивает заготовку (templateApply, templates.php) и не правит каталог (админка).

   В каталоге — только заготовки с отметкой in_catalog: рабочие схемы, проверенные прогоном.
   Паспорт — знание о схеме: для чего она, что на входе и выходе, какие сервисы, что настраивается,
   что спросить у человека, на чём спотыкались прогоны. Паспорта разделов и заготовок —
   единственный источник опыта каталога: конструктор получает его разделом catalogDigest(). */

declare(strict_types=1);

/** Поля паспорта заготовки и раздела: строка или список строк. Прочее не храним. */
const PASSPORT_TEMPLATE = ['for', 'input', 'output', 'steps', 'services', 'tune', 'questions', 'pitfalls', 'tags'];
const PASSPORT_CATEGORY = ['skeleton', 'questions', 'tune', 'pitfalls'];

/** Паспорт из JSON: только знакомые поля, строки обрезаны, списки — строками. */
function catalogPassport(string $json, array $fields): array
{
    $raw = json_decode($json, true);
    $out = [];
    foreach ($fields as $name) {
        $value = is_array($raw) ? ($raw[$name] ?? null) : null;
        if (is_array($value)) {
            $list = array_values(array_filter(array_map(static fn($one) => trim((string) $one), $value), 'strlen'));
            if ($list) $out[$name] = $list;
        } elseif (is_string($value) && trim($value) !== '') {
            $out[$name] = trim($value);
        }
    }
    return $out;
}

/**
 * GET catalog.get — разделы и карточки каталога; &template=ключ — одна карточка
 * с паспортом и превью схемы.
 */
function catalogGet(): void
{
    // Каталог общий: смотрит участник проекта и новый гость, чей проект заведётся первой правкой (окно приветствия).
    try {
        requireProject(false);
    } catch (ApiError $error) {
        if ($error->errCode !== 'not_found' || !validProjectKey(trim((string) request()['project']))) throw $error;
    }

    if ($key = (string) (input('template') ?? '')) {
        $row = templateRow($key, lang());
        if (!(int) $row['in_catalog']) throw new ApiError(t('server.catalog.not_in_catalog'), 'not_found');
        reply(['template' => catalogCard($row, true)]);
    }

    // Каталог смотрит человек — на языке его интерфейса.
    $human = static fn(array $row) => catalogLocal($row, lang());
    $rows = array_map($human, dbAll('SELECT * FROM templates WHERE in_catalog = 1 ORDER BY sort, id'));
    $count = array_count_values(array_column($rows, 'category'));
    $categories = array_map(static fn(array $c) => [
        'key'   => (string) $c['key'],
        'title' => (string) $c['title'],
        'icon'  => (string) $c['icon'],
        'about' => (string) $c['about'],
        'count' => (int) ($count[$c['key']] ?? 0),
    ], array_map($human, dbAll('SELECT * FROM template_categories ORDER BY sort, title')));

    reply(['categories' => $categories, 'templates' => array_map(static fn(array $row) => catalogCard($row), $rows)]);
}

/**
 * Строка каталога на языке: перевод из поля i18n поверх русских полей — название, описание,
 * тело схемы с ТЗ, паспорт. Паспорт и тело сливаются по полям: чего нет в переводе, остаётся
 * русским. Перевода нет — русская строка как есть. Язык по умолчанию — проекта
 * (конструктор, опыт каталога); окно «Новая схема» и разворот из него — язык человека (lang()).
 */
function catalogLocal(array $row, ?string $lang = null): array
{
    $lang ??= langAgents();
    if ($lang === 'ru') return $row;
    $over = (json_decode((string) ($row['i18n'] ?? '{}'), true) ?: [])[$lang] ?? [];
    foreach (['title', 'about'] as $field) {
        if (isset($over[$field]) && is_string($over[$field])) $row[$field] = $over[$field];
    }
    if (isset($row['passport']) && is_array($over['passport'] ?? null)) {
        $row['passport'] = json_encode(array_replace(json_decode((string) $row['passport'], true) ?: [], $over['passport']),
            JSON_UNESCAPED_UNICODE);
    }
    if (isset($row['body']) && is_array($over['body'] ?? null)) {
        $row['body'] = json_encode(catalogBodyLocal(json_decode((string) $row['body'], true) ?: [], $over['body']),
            JSON_UNESCAPED_UNICODE);
    }
    return $row;
}

/** Свойства элемента, которые переводятся: смысл для агента и Jev. Прочие (join, expr, start…) — только из русского тела. */
const CATALOG_TEXT_PROPS = ['answer', 'cond', 'accept', 'reject'];

/**
 * Тело схемы на языке: устройство — русское (элементы, стрелки, оформление, настройки папки,
 * технические свойства), из перевода по ref — только тексты: название, описание, ТЗ и свойства
 * CATALOG_TEXT_PROPS, которые есть у русского элемента. Правка русского тела после перевода
 * не теряется, старый перевод не вернёт прежние join/expr, новый элемент без перевода остаётся русским.
 */
function catalogBodyLocal(array $body, array $over): array
{
    $local = [];
    foreach ((array) ($over['elements'] ?? []) as $item) {
        if (is_array($item) && isset($item['ref'])) $local[(string) $item['ref']] = $item;
    }
    foreach ((array) ($body['elements'] ?? []) as $n => $item) {
        $text = $local[(string) ($item['ref'] ?? '')] ?? null;
        if (!$text) continue;
        foreach (['title', 'description', 'spec'] as $field) {
            if (isset($text[$field]) && is_string($text[$field])) $body['elements'][$n][$field] = $text[$field];
        }
        foreach (CATALOG_TEXT_PROPS as $name) {
            if (array_key_exists($name, (array) ($item['props'] ?? [])) && isset($text['props'][$name])) {
                $body['elements'][$n]['props'][$name] = $text['props'][$name];
            }
        }
    }
    return $body;
}

/** Перевод строки каталога на язык: title, about, passport и есть ли перевод тела (для админки). */
function catalogOver(array $row, string $lang, array $passportFields): array
{
    $over = (json_decode((string) ($row['i18n'] ?? '{}'), true) ?: [])[$lang] ?? [];
    return [
        'title'    => is_string($over['title'] ?? null) ? $over['title'] : '',
        'about'    => is_string($over['about'] ?? null) ? $over['about'] : '',
        'passport' => catalogPassport(json_encode($over['passport'] ?? [], JSON_UNESCAPED_UNICODE), $passportFields),
        'hasBody'  => is_array($over['body'] ?? null) && !empty($over['body']['elements']),
    ];
}

/** Карточка каталога: коротко для списка, с паспортом и превью — для окна схемы. */
function catalogCard(array $row, bool $full = false): array
{
    $body = json_decode((string) $row['body'], true) ?: ['elements' => []];
    $passport = catalogPassport((string) $row['passport'], PASSPORT_TEMPLATE);
    $blocks = array_filter($body['elements'] ?? [], static fn(array $e) => ($e['type'] ?? '') === 'block'
        && !propOn($e['props']['start'] ?? false));   // стартер — по тому же правилу, что у схемы (propOn)
    $out = [
        'key'      => (string) $row['key'],
        'title'    => (string) $row['title'],
        'category' => (string) $row['category'],
        'about'    => (string) $row['about'],
        'steps'    => count($blocks),
        'services' => $passport['services'] ?? [],
        'tags'     => $passport['tags'] ?? [],
        'checked'  => $row['checked_at'] ? substr((string) $row['checked_at'], 0, 10) : null,
    ];
    if ($full) {
        $out['passport'] = $passport;
        $out['preview'] = catalogPreview($body);
    }
    return $out;
}

/**
 * Превью схемы для миникарты: узлы с рамками и стрелки между ними — без ТЗ и оформления.
 * Размеры по умолчанию — как у холста (defaultSize, public/src/core/kinds.js).
 */
function catalogPreview(array $body): array
{
    $size = ['block' => [280, 180], 'decision' => [240, 130], 'gateway' => [240, 120]];
    $nodes = [];
    $arrows = [];
    foreach ($body['elements'] ?? [] as $e) {
        $type = (string) ($e['type'] ?? '');
        if ($type === 'arrow') {
            $arrows[] = ['from' => (string) $e['from'], 'to' => (string) $e['to'],
                         'branch' => (string) ($e['branch'] ?? ''), 'back' => !empty($e['back'])];
            continue;
        }
        if (!isset($size[$type])) continue;           // заметки, полосы, таблицы в миникарте не нужны
        $style = (array) ($e['style'] ?? []);
        $nodes[] = [
            'ref'   => (string) ($e['ref'] ?? ''),
            'type'  => $type,
            'title' => (string) ($e['title'] ?? ''),
            'start' => propOn($e['props']['start'] ?? false),
            'x'     => (float) ($style['x'] ?? 0),
            'y'     => (float) ($style['y'] ?? 0),
            'w'     => (float) ($style['width'] ?? $size[$type][0]),
            'h'     => (float) ($style['height'] ?? $size[$type][1]),
        ];
    }
    return ['nodes' => $nodes, 'arrows' => $arrows];
}

/**
 * «Опыт каталога» для конструктора: разделы с их паспортами и заготовки с паспортами —
 * текстом, по которому модель выбирает ближайшую схему и знает, что в ней настраивается.
 * Собирается при каждом запросе: новая заготовка сразу становится знанием конструктора.
 */
function catalogDigest(): string
{
    $line = static function (string $name, $value): string {
        return '- ' . ta('agents.catalog.' . $name) . ': ' . (is_array($value) ? implode('; ', $value) : $value);
    };

    $templates = [];
    foreach (dbAll('SELECT * FROM templates WHERE in_catalog = 1 ORDER BY sort, id') as $row) {
        $templates[(string) $row['category']][] = catalogLocal($row);
    }

    $out = [ta('agents.catalog.digest_head')];
    foreach (array_map('catalogLocal', dbAll('SELECT * FROM template_categories ORDER BY sort, title')) as $category) {
        $out[] = "\n## {$category['title']} ({$category['key']})\n{$category['about']}";
        foreach (catalogPassport((string) $category['passport'], PASSPORT_CATEGORY) as $name => $value) {
            $out[] = $line($name, $value);
        }
        foreach ($templates[$category['key']] ?? [] as $row) {
            $out[] = "\n### {$row['title']} (`{$row['key']}`)\n{$row['about']}";
            foreach (catalogPassport((string) $row['passport'], PASSPORT_TEMPLATE) as $name => $value) {
                $out[] = $line($name, $value);
            }
        }
    }
    return implode("\n", $out) . "\n";
}

/** Слова без смысла для подбора: служебные и общие для любой задачи. */
const CATALOG_STOP = ['для', 'что', 'как', 'это', 'нужн', 'надо', 'хочу', 'сдела', 'мне', 'или', 'чтоб', 'чтобы',
    'из', 'на', 'по', 'от', 'под', 'при', 'без', 'про', 'все', 'всё', 'его', 'её', 'они', 'есть', 'будет', 'такой',
    'the', 'and', 'for', 'with', 'from', 'that', 'this', 'want', 'need', 'make', 'into', 'your', 'some', 'there'];

/** Основы слов: нижний регистр, буквы и цифры, первые пять знаков — грубо, но без словарей. */
function catalogStems(string $text): array
{
    preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $words);
    $out = [];
    foreach ($words[0] as $word) {
        $stem = mb_substr($word, 0, 5);
        if (!in_array($stem, CATALOG_STOP, true)) $out[$stem] = true;
    }
    return array_keys($out);
}

/**
 * Похожие схемы каталога по словам цели и ответов человека — подсказка конструктору,
 * а не решение: основу выбирает модель по паспортам. Название весит втрое, метки и раздел —
 * вдвое, описание и паспорт — один раз. Отдаёт до $limit схем с очками, лучшие первыми.
 */
function catalogPick(string $text, int $limit = 3): array
{
    $want = catalogStems($text);
    if (!$want) return [];
    $titles = array_column(array_map('catalogLocal', dbAll('SELECT `key`, title, i18n FROM template_categories')), 'title', 'key');

    $scored = [];
    foreach (array_map('catalogLocal', dbAll('SELECT * FROM templates WHERE in_catalog = 1')) as $row) {
        $passport = catalogPassport((string) $row['passport'], PASSPORT_TEMPLATE);
        $flat = static fn($value) => is_array($value) ? implode(' ', $value) : (string) $value;
        $weights = [
            [(string) $row['title'], 3],
            [$flat($passport['tags'] ?? []) . ' ' . ($titles[$row['category']] ?? ''), 2],
            [(string) $row['about'] . ' ' . implode(' ', array_map($flat, array_intersect_key($passport,
                array_flip(['for', 'input', 'output', 'steps', 'services'])))), 1],
        ];
        $score = 0;
        foreach ($weights as [$field, $weight]) {
            $score += $weight * count(array_intersect($want, catalogStems($field)));
        }
        if ($score > 0) $scored[] = ['key' => (string) $row['key'], 'title' => (string) $row['title'],
                                     'category' => (string) $row['category'], 'score' => $score];
    }
    usort($scored, static fn(array $a, array $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}
