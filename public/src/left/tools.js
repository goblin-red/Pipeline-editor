/* Левая плашка: чем рисовать.
   Что делает: кнопки типов из KINDS (кроме стрелки) и отдельная кнопка
   «ярлык»; строками (с клавишей) и сеткой плиток; «собрать / разобрать»
   группу; подсветка выбранного инструмента.
   Отдаёт: initTools().
   Не делает: не ставит объект на холст — это edit/place.js (перетаскивание
   значка) и edit/pointer.js (щелчок); клавиши 1–8 — shell/topbar.js по тому
   же списку TOOLS (core/kinds.js); какая форма видна, решает CSS по #rail[data-tools].

   «Ярлык» — не тип из KINDS: его не ставят на холст, а тянут от объекта
   к объекту. Поэтому кнопка без data-type и не перетаскивается (бросок
   создал бы ярлык без концов). Плитку #cl-link ведёт edit/pointer.js. */

import { state, on, emit } from 'goblin/core/state.js';
import { KINDS, LINK_KIND, TOOLS } from 'goblin/core/kinds.js';
import { groupSelected, ungroupSelected, canGroup } from 'goblin/edit/grouping.js';
import { make } from 'goblin/left/parts.js';
import { t } from 'goblin/core/i18n.js';

const LINK_ABOUT = t('editor.tools.link_about');

let holder;

export function initTools(node) {
  holder = node;
  holder.append(rowsForm(), gridForm());

  on('tool', mark);
  on('selection', markGroup);
  mark();
  markGroup();
}

/** Типы для кнопок: всё из KINDS, кроме стрелки — её тянут от порта. */
const kinds = () => Object.entries(KINDS).filter(([type]) => type !== 'arrow');

/** Щелчок по инструменту: второй щелчок снимает выбор. */
function pick(tool) {
  if (state.viewOnly) return;
  state.tool = state.tool === tool ? null : tool;
  emit('tool');
}

/* ── Строками ─────────────────────────────────────────────── */

function rowsForm() {
  const form = make('div', 'left-rows');
  const list = make('div', 'tools');

  for (const [type, kind] of kinds()) {
    const key = TOOLS.indexOf(type) + 1;
    const button = make('button', 'tool', `<b>${kind.icon}</b> ${kind.title}<span>${key}</span>`);
    button.draggable = true;            // значок можно утащить на холст
    button.dataset.type = type;
    button.dataset.key = key;
    button.onclick = () => pick(type);
    list.append(button);
  }

  const linkKey = TOOLS.indexOf('link') + 1;
  const link = make('button', 'tool', `<b>${LINK_KIND.icon}</b> ${LINK_KIND.title}<span>${linkKey}</span>`);
  link.dataset.tool = 'link';
  link.dataset.key = linkKey;
  link.title = LINK_ABOUT;
  link.onclick = () => pick('link');
  list.append(link);

  const group = make('div', 'row rail-row');
  group.append(
    groupButton('btn btn-quiet btn-small', 'btn-group', t('editor.tools.group_btn'), t('editor.tools.group_title'), groupSelected),
    groupButton('btn btn-quiet btn-small', 'btn-ungroup', t('editor.tools.ungroup_btn'), t('editor.tools.ungroup_title'), ungroupSelected),
  );

  // Как рисовать — в меню «Настройки», пункт «Инструкция» (shell/info.js, openHelp).
  form.append(list, group);
  return form;
}

/* ── Сеткой плиток ────────────────────────────────────────── */

function gridForm() {
  const form = make('div', 'left-grid');
  const grid = make('div', 'tool-grid');
  grid.id = 'tool-grid';

  for (const [type, kind] of kinds()) {
    const button = make('button', 'tool-icon', `<b>${kind.icon}</b><small>${kind.title}</small>`);
    button.draggable = true;
    button.dataset.type = type;
    button.title = t('editor.tools.drag_hint', { about: kind.about });
    button.onclick = () => pick(type);
    grid.append(button);
  }

  // Ярлык — своей строкой под типами. Щелчок ведёт edit/pointer.js по id.
  const links = make('div', 'tool-grid');
  const link = make('button', 'tool-icon', `<b>${LINK_KIND.icon}</b><small>${LINK_KIND.title}</small>`);
  link.id = 'cl-link';
  link.dataset.tool = 'link';
  link.title = LINK_ABOUT + ' · ' + (TOOLS.indexOf('link') + 1);
  links.append(link);

  // Те же плитки, что у инструментов: строки меню рядом выглядели чужими.
  const groups = make('div', 'tool-grid');
  groups.append(
    groupButton('tool-icon', 'cl-group', `<b>▭</b><small>${t('editor.tools.gather')}</small>`, t('editor.tools.group_title_plain'), groupSelected),
    groupButton('tool-icon', 'cl-ungroup', `<b>⊟</b><small>${t('editor.tools.ungroup_btn')}</small>`, t('editor.tools.ungroup_title_plain'), ungroupSelected),
  );

  form.append(grid, links, make('div', 'menu-title', t('editor.tools.group_section')), groups);
  return form;
}

function groupButton(className, id, html, title, action) {
  const button = make('button', className, html);
  button.id = id;
  button.title = title;
  button.onclick = action;
  return button;
}

/* ── Отметки ──────────────────────────────────────────────── */

function mark() {
  for (const button of holder.querySelectorAll('[data-type], [data-tool]')) {
    const tool = button.dataset.type || button.dataset.tool;
    button.classList.toggle('on', state.tool === tool);
  }
}

/* Строками кнопки группы гаснут, когда делать нечего; плитки — как были. */
function markGroup() {
  const group = document.getElementById('btn-group');
  const ungroup = document.getElementById('btn-ungroup');
  group.disabled = state.viewOnly || !canGroup();
  ungroup.disabled = state.viewOnly
    || ![...state.selection].some((id) => state.elements.get(id)?.type === 'group');
}
