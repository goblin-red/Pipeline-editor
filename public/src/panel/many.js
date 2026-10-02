/* Панель, когда выделено несколько: что делают с набором.
   Отдаёт: renderMany().
   Не делает: не правит по одному — для этого панель свойств.

   Выровнять, разложить ровно, собрать в группу, покрасить всех разом.
   Как было в прежнем Гоблине: сетка значков, а не список слов. */

import { state, emit } from 'goblin/core/state.js';
import { kindOf } from 'goblin/core/kinds.js';
import { PALETTE, paintOf, paintSwatch, colorName } from 'goblin/canvas/palette.js';
import * as scene from 'goblin/edit/scene.js';
import { ALIGN, alignSelected, alignable } from 'goblin/edit/align.js';
import { groupSelected, ungroupSelected, canGroup } from 'goblin/edit/grouping.js';
import { duplicateSelection } from 'goblin/edit/clipboard.js';
import { el, wrap, note, small } from 'goblin/panel/parts.js';
import { t } from 'goblin/core/i18n.js';

export function renderMany({ body, no, kindLabel }) {
  const items = alignable();
  no.textContent = state.selection.size;
  kindLabel.textContent = t('editor.many.selected');
  body.textContent = '';

  body.append(wrap('', [note(whatIsPicked())], true));

  // Выравнивание и раздача — сеткой значков.
  const grid = el('div', 'align-grid');
  for (const [how, rule] of Object.entries(ALIGN)) {
    const button = el('button', 'align-btn', rule.icon);
    button.title = rule.title;
    button.disabled = state.viewOnly || items.length < 2;
    button.onclick = () => alignSelected(how);
    grid.append(button);
  }
  body.append(wrap(t('editor.many.align'), [grid], true));

  const group = small(t('editor.tools.group_btn'), () => groupSelected());
  group.disabled = state.viewOnly || !canGroup();
  const tools = el('div', 'row');
  tools.append(group, small(t('editor.tools.ungroup_btn'), () => ungroupSelected()), small(t('editor.many.copy'), () => duplicateSelection()));
  body.append(wrap(t('editor.many.set'), [tools], true));

  // Цвет сразу на всех: у набора его меняют чаще всего.
  const palette = el('div', 'colors');
  for (const color of PALETTE) {
    const paint = paintOf(color);
    const swatch = el('button', 'swatch');
    paintSwatch(swatch, color);
    swatch.title = colorName(color);
    swatch.onclick = () => {
      for (const id of state.selection) scene.patch(id, { style: { color: color || null } });
    };
    palette.append(swatch);
  }
  body.append(wrap(t('editor.many.color_all'), [palette], true));
}

/** «блок · 3, стрелка · 2» — человеку понятнее, чем просто число. */
function whatIsPicked() {
  const kinds = new Map();
  for (const id of state.selection) {
    const element = state.elements.get(id);
    if (element) kinds.set(element.type, (kinds.get(element.type) || 0) + 1);
  }
  const words = [...kinds].map(([type, n]) => `${kindOf({ type }).title} · ${n}`);
  return words.join(', ') || t('editor.many.nothing');
}
