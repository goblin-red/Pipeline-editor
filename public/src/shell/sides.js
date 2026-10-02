/* Боковые плашки: убрать с глаз и вернуть.
   Отдаёт: initSides().
   Не делает: ничего не знает о содержимом рейки и панели — только прячет их.

   Ярлычок остаётся всегда: он стоит на уровне кнопок масштаба, у самого края
   спрятанной плашки, и возвращает её тем же щелчком. Выбор помнится
   в этом браузере — открыл заново, всё осталось как было. */

import { t } from 'goblin/core/i18n.js';

const SIDES = [
  { id: 'side-left', flag: 'rail-off', store: 'goblin-rail-off',
    hide: t('editor.layout.hide_left'), show: t('editor.layout.show_left') },
  { id: 'side-right', flag: 'panel-off', store: 'goblin-panel-off',
    hide: t('editor.layout.hide_right'), show: t('editor.layout.show_right') },
];

export function initSides() {
  for (const side of SIDES) {
    const button = document.getElementById(side.id);
    if (!button) continue;

    let off = false;
    try { off = localStorage.getItem(side.store) === '1'; } catch {}
    apply(side, button, off);

    button.onclick = () => set(side, button, !document.body.classList.contains(side.flag));
  }

  // Щелчок по значку вкладки спрятанной плашки возвращает её: человек явно хочет её видеть.
  // Вкладки левой — .rail-tab (стоят в шапке), правой — [data-tab] в #panel-tabs.
  document.addEventListener('click', (event) => {
    const hit = event.target.closest('.rail-tab') ? 0 : event.target.closest('#panel-tabs [data-tab]') ? 1 : -1;
    if (hit < 0) return;
    const side = SIDES[hit];
    const button = document.getElementById(side.id);
    if (button && document.body.classList.contains(side.flag)) set(side, button, false);
  });
}

function set(side, button, off) {
  apply(side, button, off);
  try { localStorage.setItem(side.store, off ? '1' : '0'); } catch {}
}

function apply(side, button, off) {
  document.body.classList.toggle(side.flag, off);
  button.title = off ? side.show : side.hide;
  button.textContent = arrow(side.id, off);
}

/* Стрелка всегда показывает, куда уедет плашка. */
function arrow(id, off) {
  if (id === 'side-left') return off ? '›' : '‹';
  return off ? '‹' : '›';
}
