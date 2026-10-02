/* Мобильный скин: телефон и планшет, главная ориентация — вертикальная.
   Отдаёт: initMobile().
   Не делает: не меняет компьютерные скины — всё своё видно только при
   body[data-look="mobile"] (css/mobile.css). Бэкенд не трогает.

   Холст главный и занимает весь экран. Всё остальное — в меню
   (mobile/drawer.js): инструменты, папки, свойства, материалы, прогон, ИИ.
   В шапке всегда видны вкладки меню (значки): касание открывает вкладку,
   повторное — закрывает меню. На широком экране справа — название папки.
   Отмены и возврата нет. Жесты — mobile/touch.js,
   полоски поверх холста — mobile/bar.js. Объёмного вида здесь нет. */

import { state, on } from 'goblin/core/state.js';
import { camera, toggleIso } from 'goblin/canvas/view.js';
import { setVariant } from 'goblin/core/variants.js';
import { initDrawer, isMobile, toggleDrawer, closeDrawer } from 'goblin/mobile/drawer.js';
import { initTouch } from 'goblin/mobile/touch.js';
import { initBar } from 'goblin/mobile/bar.js';
import { initFolds } from 'goblin/mobile/folds.js';
import { initMobileCamera, savedCamera, readableFit } from 'goblin/mobile/camera.js';
import { toggleAi } from 'goblin/panel/ai.js';
import { t } from 'goblin/core/i18n.js';

export function initMobile() {
  buildTop();
  initDrawer();
  initTouch();
  initBar();
  initFolds();
  initMobileCamera();
  buildPanelHint();
  buildAiClose();
  followViewport();

  // Скин включают при монтировании холста — камера к этому мигу ещё не готова.
  on('look', () => setTimeout(enter));
  on('variant', keepCanvas);
  on('folder', showFolder);
  on('folders', showFolder);
  // iOS: щипок масштабирует всю страницу — холст масштабирует себя сам.
  document.addEventListener('gesturestart', (event) => { if (isMobile()) event.preventDefault(); });
}

function enter() {
  if (!isMobile()) { closeDrawer(); return; }
  if (camera.iso) toggleIso(false);
  keepCanvas();
  showFolder();
  // Первый показ папки на этом устройстве — читаемый масштаб, а не «всё в точку».
  if (!savedCamera()) readableFit();
}

/** В мобильном скине — холст и лента прогона: список и иерархия — компьютерные виды. */
function keepCanvas() {
  if (isMobile() && state.variant && !['canvas', 'timeline'].includes(state.variant)) setVariant('canvas');
}

/* ── Шапка ───────────────────────────────────────────────────── */

/* Вкладки меню встают в шапку сами (mobile/drawer.js) — перед .m-gap. */
function buildTop() {
  const top = document.createElement('div');
  top.className = 'm-top';
  top.innerHTML = `<span class="m-gap"></span><button class="m-folder"></button>`;
  top.querySelector('.m-folder').onclick = () => toggleDrawer('folders');
  document.querySelector('.topbar')?.prepend(top);
  showFolder();
}

function showFolder() {
  const name = document.querySelector('.m-folder');
  if (name) name.textContent = state.folder?.name || t('editor.rail.folders');
}

/* ── Клавиатура телефона ─────────────────────────────────────── */

/** Видимая часть экрана — над клавиатурой. Окна (css/mobile.css) берут её высоту
    и отступ сверху из --m-vh и --m-vv-top: иначе клавиатура закрывала низ окна
    вместе с кнопкой «Готово». */
function followViewport() {
  const view = window.visualViewport;
  if (!view) return;
  const root = document.documentElement.style;
  const put = () => {
    root.setProperty('--m-vh', Math.round(view.height) + 'px');
    root.setProperty('--m-vv-top', Math.round(view.offsetTop) + 'px');
  };
  view.addEventListener('resize', put);
  view.addEventListener('scroll', put);
  put();
}

/* ── Панель и помощник в шторке ──────────────────────────────── */

/** Вкладка «Блок», когда ничего не выбрано: экран папки вторичен — вместо него подсказка. */
function buildPanelHint() {
  const hint = document.createElement('div');
  hint.className = 'm-panel-empty';
  hint.innerHTML = `<b>${t('editor.mobile.nothing')}</b><span>${t('editor.mobile.nothing_hint')}</span>`;
  document.getElementById('panel-body')?.before(hint);
}

/** Разговор с ИИ встаёт справа, холст слева виден; шторка уступает ему место.
    Своей кнопки закрытия у экрана помощника нет — на телефоне она нужна. */
function buildAiClose() {
  const screen = document.getElementById('screen-ai');
  if (!screen) return;
  const close = document.createElement('button');
  close.className = 'm-ai-close';
  close.setAttribute('aria-label', t('editor.mobile.close_chat'));
  close.innerHTML = `<span>${t('editor.mobile.chat_title')}</span><b>✕</b>`;
  close.onclick = () => toggleAi(false);
  screen.prepend(close);
  new MutationObserver(() => {
    if (isMobile() && !screen.hidden) closeDrawer();
  }).observe(screen, { attributes: true, attributeFilter: ['hidden'] });
}
