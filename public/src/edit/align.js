/* Выравнивание и раздача: поставить выделенное в один ряд, разложить ровно,
   сделать одного размера.
   Отдаёт: ALIGN, alignSelected(), alignable().
   Не делает: не рисует кнопки — их ставит панель (panel/panel.js).

   Считается по рамкам элементов, а не по их серединам: человек видит края,
   и «по левому краю» должно значить именно край. Всё одним шагом отмены. */

import { state } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry.js';
import { isContainer } from 'goblin/core/containers.js';
import * as scene from 'goblin/edit/scene.js';
import { group as oneStep } from 'goblin/edit/history.js';
import { t } from 'goblin/core/i18n.js';

/* Порядок здесь — порядок кнопок в панели. */
export const ALIGN = {
  left:    { title: t('editor.align.left'),   icon: '⭰' },
  centerX: { title: t('editor.align.center_x'), icon: '⭹' },
  right:   { title: t('editor.align.right'),  icon: '⭲' },
  top:     { title: t('editor.align.top'), icon: '⭱' },
  centerY: { title: t('editor.align.center_y'),  icon: '⭻' },
  bottom:  { title: t('editor.align.bottom'),  icon: '⭳' },
  rowGaps: { title: t('editor.align.row_gaps'), icon: '⇹' },
  colGaps: { title: t('editor.align.col_gaps'),  icon: '⇳' },
  sameW:   { title: t('editor.align.same_w'),     icon: '▭' },
  sameH:   { title: t('editor.align.same_h'),     icon: '▯' },
};

/** Что можно выравнивать: сами выделенные, контейнеры — вместе с содержимым. */
export function alignable() {
  return [...state.selection]
    .map((id) => state.elements.get(id))
    .filter((element) => element && element.type !== 'arrow');
}

export function alignSelected(how) {
  if (state.viewOnly || !ALIGN[how]) return 0;
  const items = alignable();
  if (items.length < 2) return 0;

  const boxes = new Map(items.map((element) => [element.id, box(element)]));
  const all = [...boxes.values()];
  const left = Math.min(...all.map((b) => b.x));
  const right = Math.max(...all.map((b) => b.x + b.w));
  const top = Math.min(...all.map((b) => b.y));
  const bottom = Math.max(...all.map((b) => b.y + b.h));
  const middleX = (left + right) / 2;
  const middleY = (top + bottom) / 2;

  // Раздача считается по порядку на холсте, а не по порядку выделения.
  const alongX = [...items].sort((a, b) => boxes.get(a.id).x - boxes.get(b.id).x);
  const alongY = [...items].sort((a, b) => boxes.get(a.id).y - boxes.get(b.id).y);
  const gapX = spacing(alongX, boxes, 'w', right - left);
  const gapY = spacing(alongY, boxes, 'h', bottom - top);

  let done = 0;
  oneStep(ALIGN[how].title.toLowerCase(), () => {
    let runX = left;
    let runY = top;
    for (const element of (how === 'colGaps' ? alongY : alongX)) {
      const b = boxes.get(element.id);
      const patch = {};

      switch (how) {
        case 'left':    patch.x = left; break;
        case 'right':   patch.x = right - b.w; break;
        case 'centerX': patch.x = middleX - b.w / 2; break;
        case 'top':     patch.y = top; break;
        case 'bottom':  patch.y = bottom - b.h; break;
        case 'centerY': patch.y = middleY - b.h / 2; break;
        case 'rowGaps': patch.x = runX; runX += b.w + gapX; break;
        case 'colGaps': patch.y = runY; runY += b.h + gapY; break;
        case 'sameW':   patch.width = Math.round(all.reduce((sum, one) => sum + one.w, 0) / all.length); break;
        case 'sameH':   patch.height = Math.round(all.reduce((sum, one) => sum + one.h, 0) / all.length); break;
      }
      const same = Object.entries(patch).every(([key, value]) =>
        Math.round(value) === Math.round(b[{ x: 'x', y: 'y', width: 'w', height: 'h' }[key]]));
      if (same) continue;

      for (const key of Object.keys(patch)) patch[key] = Math.round(patch[key]);
      // Контейнер везёт содержимое: иначе группа уедет, а блоки останутся.
      if (isContainer(element) && (patch.x !== undefined || patch.y !== undefined)) {
        moveWithContents(element, b, patch);
      } else {
        scene.patch(element.id, { style: patch });
      }
      done++;
    }
  });
  return done;
}

/** Промежуток, при котором элементы заполнят ту же полосу без наложений. */
function spacing(items, boxes, side, span) {
  const filled = items.reduce((sum, element) => sum + boxes.get(element.id)[side], 0);
  const gaps = items.length - 1;
  if (gaps < 1) return 0;
  return Math.max(0, Math.round((span - filled) / gaps));
}

/** Рамка поехала — содержимое едет тем же сдвигом. */
function moveWithContents(container, was, patch) {
  const shift = {
    x: (patch.x !== undefined ? patch.x : was.x) - was.x,
    y: (patch.y !== undefined ? patch.y : was.y) - was.y,
  };
  scene.patch(container.id, { style: patch });
  if (!shift.x && !shift.y) return;

  for (const element of state.elements.values()) {
    if (element.type === 'arrow' || !(element.in || []).includes(container.id)) continue;
    const b = box(element);
    scene.patch(element.id, { style: { x: Math.round(b.x + shift.x), y: Math.round(b.y + shift.y) } });
  }
}
