/* Реестр типов: как тип называется по-русски, чем рисуется и какие секции
   показывает панель. Добавляешь тип — правишь здесь, в lib/elements/kinds.php
   и добавляешь одну строку в ENUM миграцией.
   Отдаёт: KINDS, LINK_KIND, kindOf(), iconOf(), STARTER_ICON, sectionsOf(), canLink(), isLinkEnd(), COLORS,
           STEP_WORDS, STEP_MARKS, stepMark(), RUN_WORDS, RUN_MARKS, TOOLS, defaultSize(), minSize().
   Не делает: ничего не рисует и не считает.

   Ярлык (link) в KINDS нарочно не входит: из KINDS строятся кнопки, которые
   бросают на холст, а ярлык не ставится на место — его тянут от объекта
   к объекту. Его описание — LINK_KIND. */

import { t } from 'goblin/core/i18n.js';

export const KINDS = {
  block:    { title: t('editor.kinds.block'),    icon: '▣', about: t('editor.kinds.block_about'),
              sections: ['main', 'starter', 'step', 'spec', 'assets', 'shortcuts', 'agent', 'props', 'look', 'raw'] },
  decision: { title: t('editor.kinds.decision'),    icon: '◆', about: t('editor.kinds.decision_about'),
              sections: ['main', 'step', 'spec', 'props', 'look', 'raw'] },
  gateway:  { title: t('editor.kinds.gateway'),    icon: '⬡', about: t('editor.kinds.gateway_about'),
              sections: ['main', 'gateway', 'step', 'look', 'raw'] },
  arrow:    { title: t('editor.kinds.arrow'), icon: '→', about: t('editor.kinds.arrow_about'),
              sections: ['main', 'arrow', 'look', 'raw'] },
  group:    { title: t('editor.kinds.group'),  icon: '▭', about: t('editor.kinds.group_about'),
              sections: ['main', 'members', 'spec', 'assets', 'shortcuts', 'look', 'raw'] },
  area:     { title: t('editor.kinds.area'), icon: '▤', about: t('editor.kinds.area_about'),
              sections: ['main', 'members', 'spec', 'shortcuts', 'look', 'raw'] },
  note:     { title: t('editor.kinds.note'), icon: '✎', about: t('editor.kinds.note_about'),
              sections: ['main', 'look', 'raw'] },
  table:    { title: t('editor.kinds.table'), icon: '▦', about: t('editor.kinds.table_about'),
              sections: ['main', 'table', 'assets', 'look', 'raw'] },
};

/** Ярлык: тематическая связь «это связано вон с тем». В прогоне не участвует. */
export const LINK_KIND = {
  title: t('editor.kinds.link'), icon: '↗', about: t('editor.kinds.link_about'),
  sections: [],
};

export function kindOf(element) {
  if (element?.type === 'link') return LINK_KIND;
  return KINDS[element?.type] || KINDS.note;
}

/** Значок стартера прогона — круг; один на весь редактор, как и у типов. */
export const STARTER_ICON = '◯';

/** Значок элемента — один на весь редактор; у стартера прогона свой — круг. */
export function iconOf(element) {
  if (element?.type === 'block' && element.props?.start) return STARTER_ICON;
  return KINDS[element?.type]?.icon || '•';
}

export function sectionsOf(element) {
  return kindOf(element).sections;
}

/**
 * Кто бывает концом стрелки: только звенья потока — блок, ромб, шлюз.
 * Группа и область держат другие элементы, но сами ничего не исполняют,
 * поэтому стрелка к рамке бессмысленна. Зеркало lib/elements/kinds.php.
 */
export function canLink(type) {
  return ['block', 'decision', 'gateway'].includes(type);
}

/**
 * Кто бывает концом ярлыка: блок, группа, область — в любом сочетании.
 * Правило обратное стрелке. Зеркало isLinkEnd() в lib/elements/kinds.php.
 */
export function isLinkEnd(type) {
  return ['block', 'group', 'area'].includes(type);
}

/** Двенадцать ключей палитры — единственный способ покрасить элемент. */
export const COLORS = ['blue', 'green', 'teal', 'purple', 'red', 'orange',
                       'yellow', 'pink', 'cyan', 'lime', 'brown', 'gray'];

/** Состояния шага словами: одни и те же в панели, полосе прогона и подсказках.
    ready — жетоны пришли, работу не выдали: ждёт команды ведущего или человека. */
export const STEP_WORDS = {
  issued: t('editor.kinds.step_issued'),
  running: t('editor.kinds.step_running'),
  submitted: t('editor.kinds.step_submitted'),
  accepted: t('editor.kinds.step_accepted'),
  returned: t('editor.kinds.step_returned'),
  failed: t('editor.kinds.step_failed'),
  cancelled: t('editor.kinds.step_cancelled'),
  none: t('editor.kinds.step_none'),
  ready: t('editor.kinds.step_ready'),
};

/** Значок состояния — второй признак рядом с цветом: различается и без цвета (css/run.css). */
export const STEP_MARKS = {
  issued: '→', running: '◉', submitted: '⌛', accepted: '✓',
  returned: '↩', failed: '✗', cancelled: '—', none: '·', ready: '✋',
};

/** Состояние прогона словами и значком: полоса, угол холста, вид «Прогон», лента. */
export const RUN_WORDS = {
  running: t('editor.run.state_running'),
  paused: t('editor.run.state_paused'),
  stopped: t('editor.run.state_stopped'),
  done: t('editor.run.state_done'),
  failed: t('editor.run.state_failed'),
};
export const RUN_MARKS = { running: '●', paused: '‖', stopped: '■', done: '✓', failed: '✗' };

/** Значок состояния узла: ромб, который ждёт, спрашивает ветку, а не зовёт руку;
    пауза показа — многоточие: движок продолжит сам. */
export function stepMark(element, name, wait = '') {
  if (name === 'ready' && wait === 'pause') return '…';
  if (name === 'ready' && element?.type === 'decision') return '?';
  return STEP_MARKS[name] || '·';
}

/** Инструменты рисования по клавишам 1–8: типы из KINDS без стрелки, последним — ярлык. */
export const TOOLS = [...Object.keys(KINDS).filter((type) => type !== 'arrow'), 'link'];

/** Меньше этого не ужать: иначе карточка перестаёт читаться. */
export function minSize(type) {
  if (type === 'note') return { width: 90, height: 36 };
  if (type === 'table') return { width: 220, height: 90 };
  if (type === 'group' || type === 'area') return { width: 160, height: 80 };
  return { width: 140, height: 70 };
}

/** Размер по умолчанию: блок 280×180, рамки крупнее. */
export function defaultSize(type) {
  if (type === 'table') return { width: 520, height: 240 };
  if (type === 'group') return { width: 420, height: 300 };
  if (type === 'area') return { width: 640, height: 200 };
  if (type === 'note') return { width: 240, height: 90 };
  if (type === 'decision') return { width: 240, height: 130 };
  if (type === 'gateway') return { width: 240, height: 120 };
  return { width: 280, height: 180 };
}
