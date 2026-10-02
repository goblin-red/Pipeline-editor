/* Левая плашка: палитра.
   Что делает: рисует кружки цвета (пастель, контрастные, именованные, узоры),
   щелчок — цвет для новых объектов и сразу для выделенных; отмечает, какой
   цвет стоит.
   Отдаёт: initColors().
   Не делает: не задаёт размер и ряд кружков — это CSS (26px, 6 в ряд). */

import { state, on } from 'goblin/core/state.js';
import { PALETTE, paintOf, paintSwatch, colorName } from 'goblin/canvas/palette.js';
import * as scene from 'goblin/edit/scene.js';

let colors;

export function initColors(holder) {
  colors = holder;
  colors.textContent = '';

  for (const color of PALETTE) {
    const paint = paintOf(color);
    const swatch = document.createElement('button');
    swatch.className = 'swatch';
    swatch.dataset.color = color;
    paintSwatch(swatch, color);
    swatch.style.setProperty('--swatch-edge', paint ? paint.edge : 'var(--line)');
    swatch.title = colorName(color);
    swatch.onclick = () => {
      state.color = color;
      mark();
      for (const id of state.selection) scene.patch(id, { style: { color: color || null } });
    };
    colors.append(swatch);
  }

  on('selection', mark);
  on('element', mark);
  mark();
}

/* Какой цвет отмечен: у выбранного объекта — его собственный, иначе тот,
   которым красят дальше. Так видно и «что стоит», и «чем красим». */
function mark() {
  const picked = state.selection.size === 1
    ? state.elements.get([...state.selection][0])?.style?.color ?? ''
    : state.color;
  for (const swatch of colors.children) {
    swatch.classList.toggle('on', (swatch.dataset.color || '') === (picked || ''));
  }
}
