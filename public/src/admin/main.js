/* Админка: вход экрана — боковое меню, переход по разделам, тема.
   Отдаёт: ничего — запускается сама.
   Не делает: не рисует разделы — каждый живёт в sections/<имя>.js и отдаёт render(root, {id}).

   Адрес: #раздел или #раздел/id — сразу с открытой карточкой (её можно прислать ссылкой). */

import { el, api } from 'goblin/admin/core.js';
import { closeDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const ICONS = {
  overview: '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>',
  projects: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  schemes: '<rect x="3" y="3" width="7" height="6" rx="1"/><rect x="14" y="15" width="7" height="6" rx="1"/><path d="M6.5 9v9H14"/>',
  runs: '<circle cx="12" cy="12" r="9"/><path d="M10 8.5v7l5.5-3.5z"/>',
  people: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11a3 3 0 1 0 0-6M18 20h3.5a5.5 5.5 0 0 0-4-5.3"/>',
  journal: '<path d="M6 3h11l3 3v15H6zM9 9h8M9 13h8M9 17h5"/>',
  ai: '<path d="M12 3.5 13.7 9l5.5 1.7-5.5 1.8L12 18l-1.7-5.5L4.8 10.7 10.3 9z"/>',
  tokens: '<circle cx="8" cy="12" r="4"/><path d="M12 12h9M18 12v3M21 12v2"/>',
  templates: '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M3.5 9.5h17M9.5 9.5v11"/>',
  settings: '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
  system: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M21.5 12h-3M5.5 12h-3M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1M18.7 18.7l-2.1-2.1M7.4 7.4 5.3 5.3"/>',
};

const SECTIONS = [
  ['overview', t('admin.nav.overview')], ['projects', t('admin.nav.projects')], ['schemes', t('admin.nav.schemes')], ['runs', t('admin.nav.runs')],
  ['people', t('admin.nav.people')], ['journal', t('admin.nav.journal')], ['ai', t('admin.nav.ai')], ['tokens', t('admin.nav.tokens')],
  ['templates', t('admin.nav.templates')], ['system', t('admin.nav.system')], ['settings', t('admin.nav.settings')],
];

// Тема — та же, что выбрана в редакторе.
try { const theme = localStorage.getItem('goblin-theme'); if (theme) document.documentElement.dataset.theme = theme; } catch {}

const nav = document.getElementById('adm-nav');
const main = document.getElementById('adm-main');
const links = {};
for (const [key, title] of SECTIONS) {
  const a = el('a');
  a.href = '#' + key;
  a.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${ICONS[key]}</svg>`;
  a.append(el('span', '', title), el('b'));
  links[key] = a;
  nav.append(a);
}

let turn = 0;
async function show() {
  const mine = ++turn;
  const [name, id] = location.hash.replace(/^#/, '').split('/');
  const key = SECTIONS.some(([k]) => k === name) ? name : 'overview';
  for (const [k, a] of Object.entries(links)) a.classList.toggle('on', k === key);
  closeDrawer(true);
  main.replaceChildren(el('div', 'adm-loading', t('admin.main.loading')));
  try {
    const module = await import(`goblin/admin/sections/${key}.js`);
    if (mine !== turn) return;
    const root = el('div');
    await module.render(root, { id: id ? Number(id) : null, refresh: show });
    if (mine !== turn) return;
    main.replaceChildren(root);
  } catch (error) {
    if (mine === turn) main.replaceChildren(el('p', 'adm-bad', t('admin.main.load_failed', { error: error.message })));
  }
  counts();
}

/** Числа в меню: сколько проектов, схем, живых прогонов, людей. */
async function counts() {
  try {
    const { kpi } = await api('overview');
    const put = (key, value) => { links[key].querySelector('b').textContent = value ? String(value) : ''; };
    put('projects', kpi.projects);
    put('schemes', kpi.schemes);
    put('runs', kpi.runsLive ? `● ${kpi.runsLive}` : '');
    put('people', kpi.users);
  } catch {}
}

window.addEventListener('hashchange', show);
show();
