/* Значки «свернуть / развернуть» групп в мобильном скине — поверх всего на холсте.
   Отдаёт: initFolds(), drawFolds().
   Не делает: не сворачивает сам — касание значка ловит mobile/touch.js
   и зовёт edit/grouping.js.

   Свой значок группы (.node-fold) живёт внутри её рамки, а рамки лежат в нижнем
   слое, под стрелками и блоками (canvas/element.js, layerOf) — блоки группы его
   закрывали. Здесь значки — отдельным слоем над всеми карточками, в координатах
   схемы: камера двигает и масштабирует их вместе с холстом — значок меняет размер
   вместе с группой (css/mobile.css). */

import { state, on } from 'goblin/core/state.js';
import { isCollapsed, isHiddenByCollapse } from 'goblin/core/containers.js';
import { isMobile } from 'goblin/mobile/drawer.js';
import { t } from 'goblin/core/i18n.js';

let layer = null;

export function initFolds() {
  const elements = document.getElementById('layer-elements');
  if (!elements) return;
  layer = document.createElement('div');
  layer.className = 'm-folds';
  elements.after(layer);
  for (const event of ['element', 'scheme', 'folder', 'look', 'steps']) on(event, drawFolds);
  drawFolds();
}

/** Значок в правом верхнем углу каждой видимой группы; лишние — убрать. */
export function drawFolds() {
  if (!layer) return;
  if (!isMobile()) { layer.textContent = ''; return; }

  const seen = new Set();
  for (const group of state.elements.values()) {
    if (group.type !== 'group' || isHiddenByCollapse(group)) continue;
    seen.add(String(group.id));
    let button = layer.querySelector(`[data-id="${group.id}"]`);
    if (!button) {
      button = document.createElement('button');
      button.className = 'm-fold';
      button.dataset.id = group.id;
      button.setAttribute('aria-label', t('editor.mobile.fold_group'));
      layer.append(button);
    }
    const style = group.style || {};
    button.style.left = (Number(style.x) || 0) + (Number(style.width) || 0) + 'px';
    button.style.top = (Number(style.y) || 0) + 'px';
    button.classList.toggle('collapsed', isCollapsed(group));
  }
  for (const button of [...layer.children]) {
    if (!seen.has(button.dataset.id)) button.remove();
  }
}
