/* Левая плашка: папки открытого проекта.
   Что делает: рисует папки строками (дерево, «…» к проектам у плашки разделами,
   число объектов у каждой) и плитками (значок, порядок, выход к проектам);
   заводит новую папку и разворачивает заготовку.
   Отдаёт: initFolders(), createFolder().
   Не делает: не грузит папку сама — это api/sync.js; какая форма видна,
   решает CSS по #rail[data-folders]. */

import { state, on } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { loadFolder, loadProject } from 'goblin/api/sync.js';
import { openNewScheme } from 'goblin/shell/newscheme.js';
import { setLevel, openPane, rememberPane } from 'goblin/left/left.js';
import { refreshProjects } from 'goblin/left/projects.js';
import { FOLDER_ICON, sortMode, byName, listHead, make, escape } from 'goblin/left/parts.js';
import { currentLook } from 'goblin/canvas/looks.js';
import { t } from 'goblin/core/i18n.js';

let rows;      // список строками
let tiles;     // список плитками

export function initFolders(holder) {
  holder.append(rowsForm(), tilesForm());
  for (const event of ['project', 'folders', 'folder', 'scheme', 'left-mode', 'live-runs', 'look']) on(event, draw);
  draw();
}

function draw() {
  drawRows();
  drawTiles();
}

/* ── Строками ─────────────────────────────────────────────── */

function rowsForm() {
  const form = make('div', 'left-rows');
  rows = make('div', 'tree');
  rows.id = 'folder-tree';

  const actions = make('div', 'row rail-row');
  actions.id = 'folder-actions';
  const add = make('button', 'btn btn-quiet btn-small', t('editor.folders.add'));
  add.id = 'btn-folder-add';
  add.onclick = () => addFolder();
  const templates = make('button', 'btn btn-quiet btn-small', t('editor.folders.new_scheme'));
  templates.id = 'btn-templates';
  templates.onclick = () => openNewScheme('catalog');
  actions.append(add, templates);

  form.append(rows, actions, dragHint());
  return form;
}

function drawRows() {
  rows.textContent = '';
  rows.append(upRow());

  const children = (parent) => state.folders
    .filter((f) => (f.parent || null) === parent)
    .sort((a, b) => a.sort - b.sort || a.id - b.id);
  const shut = shutFolders();
  // Ветка открытой папки всегда раскрыта: иначе не видно, где ты.
  for (let up = parentOf(state.folder?.id); up; up = parentOf(up)) shut.delete(up);

  const line = (folder, depth) => {
    const kids = children(folder.id);
    const closed = kids.length > 0 && shut.has(folder.id);
    // Стрелка ветки — внутри значка: первый span строки остаётся значком (стили live и мобильного вида).
    const fold = kids.length ? `<b class="tree-fold kids${closed ? '' : ' open'}"></b>` : '<b class="tree-fold"></b>';
    const item = make('button', 'tree-item' + (state.folder?.id === folder.id ? ' on' : '')
      + (state.runFolders.has(folder.id) ? ' live' : ''),
      `<span>${fold}📁</span><span>${escape(folder.name || t('editor.folders.unnamed'))}</span>`);
    nest(item, depth);
    // Число объектов без стрелок: у открытой — по живой схеме, у прочих — от сервера.
    const count = make('small');
    count.textContent = folder.id === state.folder?.id ? liveCount() : (folder.count ?? '');
    // Вложенной — значок «на уровень выше» перед числом.
    if (folder.parent) item.append(make('i', 'tree-lift', LIFT_ICON));
    item.append(count);
    item.onclick = (event) => {
      if (kids.length && event.target.closest('.tree-fold')) {
        toggleFolder(folder.id);
        return;
      }
      if (event.target.closest('.tree-lift')) {
        moveFolder(folder.id, parentOf(folder.parent));
        return;
      }
      loadFolder(folder.id);
    };
    dragFolder(item, folder.id);
    rows.append(item);
    if (!closed) for (const child of kids) line(child, depth + 1);
  };
  for (const folder of children(null)) line(folder, 0);
}

/** Строка «…» над папками: уровнем выше, к списку проектов. Папку, брошенную сюда, — на уровень выше. */
function upRow() {
  const item = make('button', 'tree-item tree-up', '<span>…</span>');
  item.title = t('editor.folders.to_projects');
  item.onclick = () => setLevel('projects');
  dropOn(item, UP);
  return item;
}

/* ── Свёрнутые ветки: какие папки закрыты, помнит браузер (по проекту) ── */

const SHUT_KEY = 'goblin-folders-shut';

function shutFolders() {
  try {
    return new Set(JSON.parse(localStorage.getItem(SHUT_KEY) || '{}')[state.project?.key] || []);
  } catch {
    return new Set();
  }
}

function toggleFolder(id) {
  const shut = shutFolders();
  if (shut.has(id)) shut.delete(id); else shut.add(id);
  try {
    const all = JSON.parse(localStorage.getItem(SHUT_KEY) || '{}');
    all[state.project?.key] = [...shut];
    localStorage.setItem(SHUT_KEY, JSON.stringify(all));
  } catch { /* без памяти ветка просто раскроется при следующей отрисовке */ }
  draw();
}

/* ── Перетаскивание: папку на папку — внутрь; на своего же родителя или на «…» — уровнем выше ── */

let dragged = null;   // id папки, которую тащат
const UP = 'up';      // цель «…»
const LIFT_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5.5 3 2.5 6l3 3M2.5 6h7a4 4 0 0 1 4 4v3"/></svg>';

const parentOf = (id) => state.folders.find((f) => f.id === id)?.parent || null;

/** Можно ли бросить папку на target: не на саму себя и не в свою же ветку; выше верхнего уровня — некуда. */
function canDrop(target) {
  if (!dragged || target === dragged) return false;
  if (target === UP || parentOf(dragged) === target) return parentOf(dragged) !== null;   // уровнем выше
  for (let up = target; up; up = parentOf(up)) if (up === dragged) return false;
  return true;
}

function dragFolder(item, id) {
  item.draggable = true;
  item.addEventListener('dragstart', (event) => {
    dragged = id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(id));
    item.classList.add('dragging');
  });
  item.addEventListener('dragend', () => {
    dragged = null;
    for (const one of document.querySelectorAll('.dragging, .drop-in')) one.classList.remove('dragging', 'drop-in');
  });
  dropOn(item, id);
}

function dropOn(item, target) {
  item.addEventListener('dragover', (event) => {
    if (!canDrop(target)) return;
    event.preventDefault();
    item.classList.add('drop-in');
  });
  item.addEventListener('dragleave', () => item.classList.remove('drop-in'));
  item.addEventListener('drop', async (event) => {
    item.classList.remove('drop-in');
    if (!canDrop(target)) return;
    event.preventDefault();
    const id = dragged;
    dragged = null;
    moveFolder(id, target === UP || parentOf(id) === target ? parentOf(parentOf(id)) : target);
  });
}

/** Переложить папку к новому родителю (null — верхний уровень) и перечитать список папок. */
async function moveFolder(id, parent) {
  try {
    // Пачкой: api.post выбрасывает поля со значением null, а null здесь и значит «верхний уровень».
    await api.batch([{ op: 'folder.update', id, parent }]);
  } catch {
    return;   // причину показал api (например, живой прогон)
  }
  // Положили в свёрнутую — раскрываем, чтобы было видно, куда легла.
  if (parent && shutFolders().has(parent)) toggleFolder(parent);
  await loadProject();
}

/** Объекты открытой папки без стрелок — так же считает сервер. */
function liveCount() {
  let n = 0;
  for (const element of state.elements.values()) if (element.type !== 'arrow') n++;
  return n;
}

/* ── Плитками ─────────────────────────────────────────────── */

/* «Классика» — уровнями, как в Finder: видны папки одного уровня, щелчок по папке с подпапками
   заходит внутрь, «↑» — уровнем выше. Прочие скины с плитками («Студия») — деревом со сдвигом. */
const byLevels = () => currentLook() === 'classic';
let level = null;     // папка, внутри которой стоим; null — верх проекта
let seen = null;      // для какой открытой папки уровень уже выбран
let upName;           // подпись плитки «↑»

function tilesForm() {
  const form = make('div', 'left-tiles');

  // Вверх: уровнем выше по папкам («Классика»), с верха — к проектам.
  const up = make('button', 'tile tile-up', `<span></span><small>${t('editor.folders.projects')}</small>`);
  up.id = 'cl-projects-up';
  upName = up.querySelector('small');
  up.onclick = () => {
    if (byLevels() && level) {
      level = parentOf(level);
      drawTiles();
      return;
    }
    openPane('projects');
    rememberPane('projects');
    refreshProjects();
  };
  dropOn(up, UP);   // папку, брошенную сюда, — на уровень выше, как на «…»

  tiles = make('div', 'folder-tiles');
  tiles.id = 'folder-tiles';

  const add = make('button', 'tile tile-new', `<span>＋</span><small>${t('editor.folders.new_tile')}</small>`);
  add.id = 'cl-folder-add';
  add.onclick = () => addFolder(byLevels() ? level : null);   // «Классика» — в тот уровень, где стоим

  form.append(listHead(up, 'folders', draw), tiles, add, dragHint());
  return form;
}

/** Примечание под кнопками: как вложить папку в папку. */
const dragHint = () => make('p', 'folders-hint', escape(t('editor.folders.drag_hint')));

function drawTiles() {
  tiles.textContent = '';
  // «По дате» — новые папки сверху.
  const order = sortMode('folders') === 'name'
    ? (a, b) => byName(a.name, b.name)
    : (a, b) => b.id - a.id;
  if (byLevels()) {
    drawLevel(order);
    return;
  }
  upName.textContent = t('editor.folders.projects');

  // Вложенные — сразу под своей папкой, со сдвигом; ветку сворачивает стрелка, как в строках.
  const shut = shutFolders();
  for (let up = parentOf(state.folder?.id); up; up = parentOf(up)) shut.delete(up);
  const put = (parent, depth) => {
    for (const folder of state.folders.filter((f) => (f.parent || null) === parent).sort(order)) {
      const kids = state.folders.some((f) => f.parent === folder.id);
      const closed = kids && shut.has(folder.id);
      const tile = tileOf(folder, () => loadFolder(folder.id), kids ? (closed ? 'kids' : 'kids open') : '');
      nest(tile, depth);
      tiles.append(tile);
      if (!closed) put(folder.id, depth + 1);
    }
  };
  put(null, 0);
}

/** «Классика»: папки одного уровня; сверху — папка, внутри которой стоим (её холст тоже открывается). */
function drawLevel(order) {
  // Открыли другую папку (ссылкой, из другого вида) — встаём на её уровень, чтобы её было видно.
  const open = state.folder?.id ?? null;
  if (open !== seen) {
    seen = open;
    if (open && open !== level && parentOf(open) !== level) level = parentOf(open);
  }
  if (level && !state.folders.some((f) => f.id === level)) level = null;
  const here = state.folders.find((f) => f.id === level);
  upName.textContent = !here ? t('editor.folders.projects')
    : (state.folders.find((f) => f.id === here.parent)?.name || t('editor.folders.root'));

  if (here) {
    const head = tileOf(here, () => loadFolder(here.id));
    head.classList.add('tile-here');
    tiles.append(head);
  }
  for (const folder of state.folders.filter((f) => (f.parent || null) === level).sort(order)) {
    const kids = state.folders.some((f) => f.parent === folder.id);
    const tile = tileOf(folder, () => {
      if (kids) level = folder.id;
      loadFolder(folder.id);
      drawTiles();
    });
    if (kids) tile.classList.add('has-kids');
    tiles.append(tile);
  }
}

/** Плитка папки: щелчок — open(); у вложенной — значок «на уровень выше»; тащится, как строка.
    fold — стрелка ветки в дереве ('kids', 'kids open', '' — место под неё); null — без стрелки. */
function tileOf(folder, open, fold = null) {
  // Подсказку не вешаем: имя и так под значком, а всплывашка закрыла бы соседей.
  const arrow = fold === null ? '' : `<b class="tree-fold${fold ? ' ' + fold : ''}"></b>`;
  const tile = make('button', 'tile' + (state.folder?.id === folder.id ? ' on' : '')
    + (state.runFolders.has(folder.id) ? ' live' : ''),
    `<span>${arrow}${FOLDER_ICON}</span><small>${escape(folder.name || t('editor.folders.unnamed'))}</small>`);
  if (folder.parent) tile.append(make('i', 'tree-lift', LIFT_ICON));
  tile.onclick = (event) => {
    if (event.target.closest('.tree-fold.kids')) {
      toggleFolder(folder.id);
      return;
    }
    if (event.target.closest('.tree-lift')) {
      moveFolder(folder.id, parentOf(folder.parent));
      return;
    }
    open();
  };
  dragFolder(tile, folder.id);
  return tile;
}

/** Глубина вложенности — CSS: отступ и уголок «└» (строки, плитки), сдвиг с полосой (телефон). */
function nest(node, depth) {
  if (!depth) return;
  node.classList.add('nested');
  node.style.setProperty('--depth', depth);
}

/* ── Новая папка ──────────────────────────────────────────── */

/* Новая папка сразу со стартером — с него начинается прогон (решение хозяина 30.09.2026).
   Круг — ровный, как при галочке «Стартер» в панели. parent — внутри какой папки (null — наверху). */
async function addFolder(parent = null) {
  const name = prompt(t('editor.folders.prompt_name'));
  if (name !== null) await createFolder(name, parent);
}

/** Завести папку со стартером и открыть её (её же зовёт окно приветствия, shell/welcome.js). */
export async function createFolder(name, parent = null) {
  const answer = await api.batch([
    { op: 'folder.create', ref: 'f', name, parent },
    { op: 'element.create', folder: 'f', type: 'block', title: t('editor.folders.starter_title'), props: { start: true },
      style: { x: 120, y: 120, width: 160, height: 160 } },
  ]);
  await loadProject();
  const id = answer.results?.[0]?.id;
  if (id) await loadFolder(id);
}
