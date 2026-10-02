/* Меню мобильного скина: одна шторка слева, её вкладки — всегда в шапке.
   Отдаёт: isMobile(), initDrawer(), openDrawer(), closeDrawer(), toggleDrawer(), drawerTab().
   Не делает: не наполняет вкладки заново — показывает то, что уже есть в редакторе:
   разделы левой плашки (#rail) и вкладки правой панели (#panel). Какой узел
   виден, решает css/mobile.css по body[data-m-tab] и классу m-open.

   Порядок вкладок — порядок важности: сначала инструменты (рисуют на холсте),
   потом папки, потом всё остальное. */

import { state, on } from 'goblin/core/state.js';
import { setLook } from 'goblin/canvas/looks.js';
import { initMobileTools } from 'goblin/mobile/tools.js';
import { t } from 'goblin/core/i18n.js';

export const isMobile = () => document.body.dataset.look === 'mobile';

/* where: rail — раздел левой плашки, panel — вкладка правой панели,
   own — своя вкладка шторки (инструменты). */
const TABS = [
  { name: 'tools',   where: 'own',   title: t('editor.mobile.tab_tools'),
    icon: '<path d="M14.5 5.5 18.5 9.5M4 20l1-4.2 10-10a2.1 2.1 0 0 1 3 3l-10 10z"/>' },
  { name: 'folders', where: 'rail',  title: t('editor.rail.folders'),
    icon: '<path d="M3 7a2 2 0 0 1 2-2h4.6l2 2.4H19a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>' },
  { name: 'props',   where: 'panel', title: t('editor.mobile.tab_block'),
    icon: '<path d="M4 7h9M17 7h3M4 12h3M11 12h9M4 17h11M19 17h1"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="17" r="2"/>' },
  { name: 'assets',  where: 'panel', title: t('editor.mobile.tab_assets'),
    icon: '<path d="M19.5 11 12 18.5a4.6 4.6 0 0 1-6.5-6.5l7.8-7.8a3.1 3.1 0 0 1 4.4 4.4l-7.8 7.8a1.6 1.6 0 0 1-2.2-2.2l7.1-7.1"/>' },
  { name: 'run',     where: 'panel', title: t('editor.variants.run'),
    icon: '<circle cx="12" cy="12" r="8.5"/><path d="M10 8.5v7l5.5-3.5z"/>' },
  { name: 'ai',      where: 'panel', title: t('editor.mobile.tab_ai'),
    icon: '<path d="M12 3.5 13.7 9l5.5 1.7-5.5 1.8L12 18l-1.7-5.5L4.8 10.7 10.3 9z"/>' },
  { name: 'more',    where: 'rail',  title: t('editor.mobile.tab_more'),
    icon: '<circle cx="5.5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="18.5" cy="12" r="1.3"/>' },
];

const STORE = 'goblin-mobile-tab';
let current = 'tools';

export function initDrawer() {
  try { current = TABS.some((tab) => tab.name === localStorage.getItem(STORE)) ? localStorage.getItem(STORE) : 'tools'; } catch {}
  document.body.dataset.mTab = current;

  const drawer = document.createElement('div');
  drawer.className = 'm-drawer';
  const row = document.createElement('div');
  row.className = 'm-tabs';
  for (const tab of TABS) {
    const button = document.createElement('button');
    button.className = 'm-tab';
    button.dataset.mTab = tab.name;
    button.innerHTML = `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${tab.icon}</svg>`;
    button.setAttribute('aria-label', tab.title);   // подписи нет — значки; название для экранного диктора
    button.onclick = () => toggleDrawer(tab.name);   // та же вкладка ещё раз — меню закрыто
    row.append(button);
  }
  const tools = document.createElement('div');
  tools.className = 'm-tools';
  drawer.append(tools);
  document.body.append(drawer);
  // Строка вкладок живёт в шапке (mobile/mobile.js) и видна всегда.
  const gap = document.querySelector('.m-top .m-gap');
  if (gap) gap.before(row); else drawer.prepend(row);
  initMobileTools(tools);

  // «Ещё»: внизу меню настроек — выход в компьютерную версию.
  const foot = document.createElement('div');
  foot.className = 'm-rail-foot';
  foot.innerHTML = `<a class="btn" href="account.php"></a><button class="btn">${t('editor.mobile.desktop')}</button>`;
  foot.querySelector('button').onclick = () => { closeDrawer(); setLook('work'); };
  // Шапки на телефоне нет — вход здесь: «Мой кабинет» или «Войти».
  const account = foot.querySelector('a');
  const label = () => { account.textContent = t(state.me ? 'editor.account.mine' : 'editor.account.login'); };
  label();
  on('project', label);
  document.getElementById('rail-settings')?.append(foot);

  // Выбрали папку — шторка своё дело сделала.
  on('folder', () => { if (current === 'folders') closeDrawer(); });
  // «Инструменты» остаются открытыми и при выборе инструмента, и при касании холста:
  // их убирают повторным касанием значка в шапке.
  mark();
}

export function drawerTab() { return current; }

export function openDrawer(tab = current) {
  showTab(tab);
  document.body.classList.add('m-open');
}

export function closeDrawer() {
  document.body.classList.remove('m-open');
}

export function toggleDrawer(tab = null) {
  if (document.body.classList.contains('m-open') && (!tab || tab === current)) closeDrawer();
  else openDrawer(tab || current);
}

/** Вкладка шторки: своя, раздел плашки или вкладка панели. */
function showTab(name) {
  const tab = TABS.find((one) => one.name === name) || TABS[0];
  current = tab.name;
  document.body.dataset.mTab = current;
  try { localStorage.setItem(STORE, current); } catch {}
  document.body.classList.add('m-open');
  // Вкладку панели переключает её же кнопка: тот же обработчик, что в шапке.
  if (tab.where === 'panel') {
    const button = document.querySelector(`#panel-tabs [data-tab="${tab.name}"]`);
    if (button && !button.classList.contains('on')) button.onclick?.();
  }
  mark();
}

function mark() {
  for (const button of document.querySelectorAll('.m-tab')) {
    button.classList.toggle('on', button.dataset.mTab === current);
  }
}
