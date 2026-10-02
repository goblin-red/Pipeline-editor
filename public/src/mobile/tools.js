/* Вкладка «Инструменты» мобильного скина: чем рисовать пальцем.
   Отдаёт: initMobileTools(), MOBILE_TOOLS, toolInfo().
   Не делает: не ставит элементы — это mobile/touch.js по касанию холста.

   Узкая колонка значков слева, холст рядом виден; какой инструмент в руке и как
   им работать — плашка над холстом (mobile/bar.js). Основное: блок, ромб, шлюз,
   стрелка, пометка, таблица, ярлык — тот же state.tool, что у левой плашки
   компьютерной версии. И режимы только для пальцев: m-select — выделение рамкой,
   m-group — касаниями отметить блоки и собрать их в группу, m-ungroup — коснуться
   группы и разобрать (mobile/touch.js).
   Повторное касание плитки снимает инструмент. */

import { state, on, emit } from 'goblin/core/state.js';
import { KINDS, LINK_KIND } from 'goblin/core/kinds.js';
import { t } from 'goblin/core/i18n.js';

export const MOBILE_TOOLS = ['m-select', 'block', 'decision', 'gateway', 'arrow', 'note', 'table', 'link', 'm-group', 'm-ungroup'];

const svg = (path) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;

// Режимы пальцев — не типы элементов: в KINDS их нет.
const OWN = {
  link: LINK_KIND,
  'm-select': { title: t('editor.mobile.tool_select'),
    icon: svg('<path d="M4 9V5.5A1.5 1.5 0 0 1 5.5 4H9M15 4h3.5A1.5 1.5 0 0 1 20 5.5V9M20 15v3.5a1.5 1.5 0 0 1-1.5 1.5H15M9 20H5.5A1.5 1.5 0 0 1 4 18.5V15"/>') },
  'm-group': { title: t('editor.mobile.tool_group'),
    icon: svg('<rect x="3" y="3" width="18" height="18" rx="2.5" stroke-dasharray="3 2.4"/><rect x="6.5" y="6.5" width="5" height="5" rx="1"/><rect x="12.5" y="12.5" width="5" height="5" rx="1"/>') },
  'm-ungroup': { title: t('editor.mobile.tool_ungroup'),
    icon: svg('<rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.2"/><rect x="14" y="14" width="6.5" height="6.5" rx="1.2"/><path d="M14 3.5h6.5V10M3.5 14v6.5H10" stroke-dasharray="2.2 2"/>') },
};

/** Значок и название инструмента в руке — для плитки и для плашки над холстом. */
export function toolInfo(name) {
  return OWN[name] || KINDS[name] || null;
}

let grid;

export function initMobileTools(holder) {
  grid = document.createElement('div');
  grid.className = 'm-tool-grid';
  for (const type of MOBILE_TOOLS) {
    const kind = toolInfo(type);
    const button = document.createElement('button');
    button.className = 'm-tool';
    button.dataset.tool = type;
    button.innerHTML = `<b>${kind.icon}</b>`;
    button.setAttribute('aria-label', kind.title);   // подписи нет — значки; название для экранного диктора
    button.onclick = () => {
      if (state.viewOnly) return;
      state.tool = state.tool === type ? null : type;
      emit('tool');
    };
    grid.append(button);
  }
  holder.append(grid);
  on('tool', mark);
  mark();
}

function mark() {
  for (const button of grid.children) button.classList.toggle('on', button.dataset.tool === state.tool);
}
