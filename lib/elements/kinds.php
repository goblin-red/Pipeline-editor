<?php
/* Девять типов элементов и всё, что о них известно: что чем может быть,
   что во что кладётся, у кого бывает ТЗ и агент.
   Отдаёт: KINDS, kindRule(), requireKind(), canContain(), isContainer(),
           canLink(), requireLinkable(), isLinkEnd(), requireLinkEnd().
   Не делает: не пишет в базу — только правила.

   Хочешь добавить тип — правка здесь, в src/core/kinds.js и одна миграция ENUM. */

declare(strict_types=1);

const KINDS = [
    'block' => [
        'title'     => 'блок',
        'about'     => 'работа, её делает агент',
        'agent'     => true,     // можно назначить агента
        'step'      => true,     // бывает шагом прогона
        'spec'      => true,     // бывает ТЗ
        'container' => false,
    ],
    'decision' => [
        'title'     => 'ромб',
        'about'     => 'развилка: leader выбирает ветку',
        'agent'     => false,
        'step'      => true,
        'spec'      => true,     // ТЗ ромба — это критерий выбора
        'container' => false,
    ],
    'gateway' => [
        'title'     => 'шлюз',
        'about'     => 'дверь в другую папку',
        'agent'     => false,
        'step'      => true,
        'spec'      => false,
        'container' => false,
    ],
    'arrow' => [
        'title'     => 'стрелка',
        'about'     => 'переход от элемента к элементу',
        'agent'     => false,
        'step'      => false,
        'spec'      => false,
        'container' => false,
    ],
    'group' => [
        'title'     => 'группа',
        'about'     => 'рамка: держит элементы, области и группы',
        'agent'     => false,
        'step'      => false,
        'spec'      => true,     // ТЗ группы приходит участнику как контекст
        'container' => true,
    ],
    'area' => [
        'title'     => 'область',
        'about'     => 'полоса или зона: держит элементы, как группа',
        'agent'     => false,
        'step'      => false,
        'spec'      => true,
        'container' => true,
    ],
    'note' => [
        'title'     => 'пометка',
        'about'     => 'надпись или черта',
        'agent'     => false,
        'step'      => false,
        'spec'      => false,
        'container' => false,
    ],
    'table' => [
        'title'     => 'таблица',
        'about'     => 'ряды и столбцы: матрица ответственности, список признаков',
        'agent'     => false,
        'step'      => false,
        'spec'      => true,     // к таблице бывает пояснение
        'container' => false,
    ],
    // Ярлык — тематическая связь «это связано вон с тем», а не переход.
    // Прогон его не видит: граф прогона строится только из стрелок.
    'link' => [
        'title'     => 'ярлык',
        'about'     => 'ссылка на связанный объект, в том числе в другой папке',
        'agent'     => false,
        'step'      => false,
        'spec'      => false,
        'container' => false,
    ],
];

function kindRule(string $type): array
{
    if (!isset(KINDS[$type])) throw new ApiError(t('server.kind.unknown', ['type' => $type]));
    return KINDS[$type];
}

/** Слово для типа в сообщениях людям; константа KINDS остаётся русской — её читают агенты. */
function kindWord(string $type): string
{
    return match ($type) {
        'block' => t('server.kind.block'), 'decision' => t('server.kind.decision'), 'gateway' => t('server.kind.gateway'),
        'arrow' => t('server.kind.arrow'), 'group' => t('server.kind.group'), 'area' => t('server.kind.area'),
        'note' => t('server.kind.note'), 'table' => t('server.kind.table'), 'link' => t('server.kind.link'),
        default => kindRule($type)['title'],
    };
}

function requireKind(string $type): string
{
    kindRule($type);
    return $type;
}

function isContainer(string $type): bool
{
    return kindRule($type)['container'];
}

/**
 * Что во что можно класть.
 *   в группу  — элементы, области и группы;
 *   в область — только элементы;
 *   стрелка и ярлык не бывают участниками: они следуют за своими концами.
 */
function canContain(string $container, string $child): bool
{
    if ($child === 'arrow' || $child === 'link') return false;
    // Группа и область держат одинаково — в том числе рамку внутри рамки.
    return $container === 'group' || $container === 'area';
}

/**
 * Кто бывает концом стрелки. Только шаги схемы: блок, ромб и шлюз.
 * Группа и область — контейнеры, а не звенья потока: стрелка к рамке
 * ничего не значит, потому что рамка ничего не исполняет. Пометка — надпись.
 */
function canLink(string $type): bool
{
    return in_array($type, ['block', 'decision', 'gateway'], true);
}

function requireLinkable(int $elementId, int $projectId, string $what): int
{
    $row = elementRow($elementId, $projectId);
    if (!canLink((string) $row['type'])) {
        throw new ApiError(t('server.kind.arrow_cant', ['what' => entityLabel($what), 'kind' => kindWord((string) $row['type'])]), 'invalid');
    }
    return $elementId;
}

/**
 * Кто бывает концом ярлыка: блок, группа, область — в любом сочетании.
 * Правило обратное стрелке: ярлык говорит «про это есть ещё вон там»,
 * а такое можно сказать и про рамку целиком.
 */
function isLinkEnd(string $type): bool
{
    return in_array($type, ['block', 'group', 'area'], true);
}

function requireLinkEnd(int $elementId, int $projectId, string $what): array
{
    $row = elementRow($elementId, $projectId);
    if (!isLinkEnd((string) $row['type'])) {
        throw new ApiError(t('server.kind.link_cant', ['what' => entityLabel($what), 'kind' => kindWord((string) $row['type'])]), 'invalid');
    }
    return $row;
}
