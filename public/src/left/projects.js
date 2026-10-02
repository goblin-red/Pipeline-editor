/* Левая плашка: мои проекты.
   Что делает: рисует проекты строками («…» — приветствие, число папок в
   скобках) и плитками (надпись «Проекты», порядок, «Новый проект»);
   один щелчок — проект текущий (схема на холсте, свойства справа), двойной — к его папкам.
   Отдаёт: initProjects(), refreshProjects().
   Не делает: не грузит проекты в обход api/sync.js; щелчки и переход —
   общие, из shell/projects.js. */

import { state, on } from 'goblin/core/state.js';
import { loadMine } from 'goblin/api/sync.js';
import { newProject, openProject, projectClicks, dropPicked, markPicked, showWelcome } from 'goblin/shell/projects.js';
import { setLevel, openPane, rememberPane } from 'goblin/left/left.js';
import { PROJECT_ICON, sortMode, byName, listHead, make, escape } from 'goblin/left/parts.js';
import { t } from 'goblin/core/i18n.js';

const EMPTY = t('editor.projects.empty');

let rows;      // список строками
let tiles;     // список плитками

export function initProjects(holder) {
  holder.append(rowsForm(), tilesForm());

  /* Рисуем из state.projects: loadMine() сам шлёт 'projects', звать его
     из обработчика — значит крутить запросы по кругу, пока список пуст. */
  on('projects', draw);
  on('project', drawRows);
  on('picked-project', markRows);
  on('live-runs', draw);

  // Панель больше не про проект: выбрали объект или открыли папку.
  on('selection', () => { if (state.selection.size) dropPicked(); });
  on('folder', dropPicked);
  on('enter-project', enter);

  showReading();
  loadMine().then(draw, draw);
}

/** Перечитать проекты с сервера: список перерисуется по событию 'projects'. */
export function refreshProjects() {
  showReading();
  loadMine(true).catch(draw);
}

function draw() {
  drawRows();
  drawTiles();
}

/** Войти в проект: плашка встречает его папками, а не списком проектов. */
function enter(key) {
  rememberPane('folders');
  dropPicked();
  if (key === state.project?.key) {
    setLevel('folders');
    openPane('folders');
    return;
  }
  // Папки показываем, когда проект уже пришёл: иначе мелькнули бы старые.
  openProject(key).then(() => openPane('folders'));
}

/* ── Строками ─────────────────────────────────────────────── */

function rowsForm() {
  const form = make('div', 'left-rows');
  rows = make('div', 'tree');
  rows.id = 'project-tree';

  const actions = make('div', 'row rail-row');
  actions.id = 'project-actions';
  const add = make('button', 'btn btn-quiet btn-small', t('editor.projects.add'));
  add.id = 'btn-project-add';
  add.onclick = askProject;
  actions.append(add);

  form.append(rows, actions);
  return form;
}

function drawRows() {
  rows.textContent = '';

  // «…» над проектами: приветствие в правой панели.
  const welcome = make('button', 'tree-item tree-up' + (state.welcome ? ' picked' : ''), '<span>…</span>');
  welcome.title = t('editor.rail.logo');
  welcome.onclick = showWelcome;
  rows.append(welcome);

  if (!state.projects.length) {
    rows.insertAdjacentHTML('beforeend', `<small class="muted">${EMPTY}</small>`);
    return;
  }
  for (const project of state.projects) {
    const here = project.key === state.project?.key;
    // Сколько папок — в скобках у названия.
    const item = make('button', 'tree-item' + (here ? ' on' : '')
      + (state.runProjects.has(project.key) ? ' live' : ''),
      `<span>🗂</span><span>${escape(project.title || project.key)}`
      + ` <em class="tree-count">(${Number(project.folders) || 0})</em></span>`);
    projectClicks(item, project.key);
    rows.append(item);
  }
}

function markRows() {
  markPicked(document.querySelectorAll('#left-projects [data-key]'));
  rows.querySelector('.tree-up')?.classList.toggle('picked', state.welcome);
}

/* ── Плитками ─────────────────────────────────────────────── */

function tilesForm() {
  const form = make('div', 'left-tiles');
  // Надпись «Проекты» неактивна: выше проектов идти некуда. Разметка та же,
  // что у «↑ Проекты» над папками: шапка не прыгает при переходе туда и обратно.
  const here = make('span', 'tile tile-up tile-here', `<span></span><small>${t('editor.folders.projects')}</small>`);

  tiles = make('div', 'folder-tiles');
  tiles.id = 'project-tiles';

  const add = make('button', 'tile tile-new', `<span>＋</span><small>${t('editor.projects.new_tile')}</small>`);
  add.id = 'cl-project-add';
  add.onclick = askProject;

  form.append(listHead(here, 'projects', drawTiles), tiles, add);
  return form;
}

/* Проекты плитками — как папки, только значок стопкой и свой цвет. */
function drawTiles() {
  tiles.textContent = '';
  if (!state.projects.length) {
    tiles.innerHTML = `<small class="muted">${EMPTY}</small>`;
    return;
  }

  const order = sortMode('projects') === 'name'
    ? (a, b) => byName(a.title || a.key, b.title || b.key)
    : () => 0;                       // с сервера они уже идут по свежести

  for (const project of state.projects.slice().sort(order)) {
    const here = project.key === state.project?.key;
    const tile = make('button', 'tile tile-project' + (here ? ' on' : '')
      + (state.runProjects.has(project.key) ? ' live' : ''),
      `<span>${PROJECT_ICON}</span><small>${escape(project.title || project.key)}`
      + ` <span class="tile-count">(${Number(project.folders) || 0})</span></small>`);
    projectClicks(tile, project.key);
    tiles.append(tile);
  }
}

function showReading() {
  if (!tiles.children.length) tiles.innerHTML = `<small class="muted">${t('editor.projects.loading')}</small>`;
}

async function askProject() {
  const name = prompt(t('editor.projects.prompt_name'));
  if (name === null) return;
  await newProject(name || t('editor.projects.new_tile'));
}
