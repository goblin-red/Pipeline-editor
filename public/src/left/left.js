/* Левая плашка: каркас.
   Что делает: держит список разделов, по скину решает их вид (разделы или
   вкладки, строки или плитки — классами на #rail), сворачивает разделы и
   помнит это, ведёт вкладки в шапке и уровень «папки / проекты».
   Отдаёт: initLeft(), leftLevel(), setLevel(), openPane(), rememberPane(), isWork().
   Не делает: не рисует содержимое разделов — это left/folders.js и соседи.

   Вид плашки задаёт только скин: холст, список, иерархия и прогон её
   не меняют — переключил вид работы, плашка осталась какой была. */

import { state, on, emit } from 'goblin/core/state.js';
import { LOOKS, currentLook } from 'goblin/canvas/looks.js';
import { dropPicked } from 'goblin/shell/projects.js';
import { initFolders } from 'goblin/left/folders.js';
import { initProjects, refreshProjects } from 'goblin/left/projects.js';
import { initSettings } from 'goblin/left/settings.js';
import { initRuns } from 'goblin/left/runs.js';
import { initTools } from 'goblin/left/tools.js';
import { initColors } from 'goblin/left/colors.js';
import { t } from 'goblin/core/i18n.js';

/* Разделы: чей узел и в каком блоке разметки (views/editor/left.html) он живёт. */
const SECTIONS = [
  { id: 'folders',  block: 'list',     holder: 'left-folders',  render: initFolders },
  { id: 'projects', block: 'list',     holder: 'left-projects', render: initProjects },
  { id: 'settings', block: 'settings', holder: 'rail-settings', render: initSettings },
  { id: 'runs',     block: 'runs',     holder: 'run-archive',   render: initRuns },
  { id: 'tools',    block: 'tools',    holder: 'tools',         render: initTools },
  { id: 'colors',   block: 'colors',   holder: 'colors',        render: initColors },
];

// Что видно на каждой вкладке.
const PANES = {
  settings: ['settings'],
  projects: ['list'],
  folders: ['list'],
  tools: ['tools', 'colors'],
};

const PLAIN = { layout: 'sections', folders: 'rows', tools: 'rows' };
const FOLDED = 'goblin-rail-folded';

let rail;
let view = PLAIN;
let level = 'folders';     // разделами: папки или проекты
let pane = 'folders';      // вкладками: открытая вкладка
let runTab = false;        // справа открыт «Прогон»
/* Архив прогонов разделом — на вкладке «Прогон» и в виде «Лента»: там выбирают прогон. */
const runsShown = () => runTab || state.variant === 'timeline';
let storeUsed = null;

/* Вид берём из currentLook(), а не с body: в списке и иерархии холст
   не монтируется, и body.dataset.look может быть ещё не выставлен. */
export const isWork = () => currentLook() === 'work';

/* Вкладками «Проекты» — одна вкладка на оба списка: проекты и папки открытого проекта. */
const LIST = new Set(['projects', 'folders']);
const tabOf = (name) => (name === 'folders' ? 'projects' : name);

/** Что в разделе «Проекты · папки» сейчас: папки или проекты. */
export function leftLevel() {
  if (view.layout === 'tabs') return pane === 'projects' ? 'projects' : 'folders';
  return level;
}

export function setLevel(next) {
  level = next;
  if (view.layout === 'tabs' && LIST.has(pane)) pane = next;
  show();
}

/* Открытая вкладка помнится: у «Студии» свой ключ. */
const paneKey = () => (currentLook() === 'studio' ? 'goblin-studio-rail-pane' : 'goblin-rail-pane');

export function rememberPane(value) {
  try { localStorage.setItem(paneKey(), value); } catch {}
}

/** Показать вкладку. Повторный щелчок плашку не сворачивает: закрытой вкладки нет. */
export function openPane(name) {
  if (name !== 'projects') dropPicked();
  pane = name;
  if (LIST.has(name)) level = name;
  show();
}

export function initLeft() {
  rail = document.getElementById('rail');
  if (!rail) return;

  initTabs();
  for (const section of SECTIONS) section.render(document.getElementById(section.holder));
  initFolding();

  on('look', sync);
  on('variant', sync);
  on('panel-tab', (tab) => { runTab = tab === 'run'; show(); });
  sync();
}

/* ── Вид по скину ─────────────────────────────────────────── */

function sync() {
  const own = LOOKS[currentLook()]?.left;
  view = own || PLAIN;

  rail.dataset.layout = view.layout;
  rail.dataset.folders = view.folders;
  rail.dataset.tools = view.tools;
  rail.classList.toggle('rail-work', isWork());
  document.body.dataset.left = view.layout;

  // Сменился ключ памяти вкладки (зашли в «Студию» или вышли) — перечитать.
  if (storeUsed !== paneKey()) {
    storeUsed = paneKey();
    restorePane();
  }
  emit('left-mode');
  show();
}

function restorePane() {
  let saved = null;
  try { saved = localStorage.getItem(paneKey()); } catch {}
  // Пустая строка — память прежних версий о свёрнутой плашке: теперь она всегда открыта.
  // Первый заход: вкладка установки (rail_default, lib/web/page.php); у «Студии» — инструменты.
  const first = PANES[globalThis.GOBLIN_DEFAULTS?.pane] ? globalThis.GOBLIN_DEFAULTS.pane : 'folders';
  if (saved === null) openPane(currentLook() === 'studio' ? 'tools' : first);
  else openPane(saved || pane || 'folders');
}

/** Какие блоки видны и что в разделе проектов и папок. */
function show() {
  const tabs = view.layout === 'tabs';
  const open = new Set(tabs ? PANES[pane] : plainBlocks());

  for (const block of rail.querySelectorAll('.rail-block')) {
    block.hidden = !open.has(block.dataset.block);
  }
  // Архив прогонов разделом: плашка отдаётся ему целиком и развёрнутым.
  rail.classList.toggle('rail-runs-only', !tabs && runsShown());
  if (!tabs && runsShown()) rail.querySelector('[data-block="runs"]')?.classList.remove('folded');

  rail.dataset.rail = pane;
  for (const tab of document.querySelectorAll('.railbar-tabs .rail-tab')) {
    tab.classList.toggle('on', tab.dataset.rail === tabOf(pane));
  }

  const projects = leftLevel() === 'projects';
  document.getElementById('left-folders').hidden = projects;
  document.getElementById('left-projects').hidden = !projects;
  document.getElementById('rail-folders-title').textContent = t('editor.rail.projects_folders');
}

/* Разделами: у всех скинов одни и те же разделы, на «Прогоне» — один архив. */
function plainBlocks() {
  if (runsShown()) return ['runs'];
  return ['list', 'settings', 'tools', 'colors'];
}

/* ── Вкладки в шапке ──────────────────────────────────────── */

function initTabs() {
  const tabs = rail.querySelector('.railbar-tabs');
  // Вкладки живут в шапке слева: там они видны, даже когда плашка убрана.
  document.getElementById('rail-tabs-slot')?.append(tabs);

  /* Щелчок открывает свою вкладку. «Проекты» возвращает на последний уровень списка: были в папках
     проекта — снова его папки, были в списке проектов — он; наверх — «↑ Проекты». */
  for (const tab of tabs.querySelectorAll('.rail-tab')) {
    tab.onclick = () => {
      const name = tab.dataset.rail;
      const target = name === 'projects' ? level : name;
      openPane(target);
      if (target === 'projects') refreshProjects();
      rememberPane(target);
    };
  }
}

/* ── Сворачивание разделов ────────────────────────────────── */

/* Выбор человека важнее вида: свернул сам — так и останется. Пока он ничего не трогал:
   «Дизайнер» прячет инструменты и цвет, остальные виды — проекты с папками и настройки
   (слева сразу «Чем рисовать» и «Цвет»; начать помогает окно приветствия). */
function savedFolds() {
  try {
    const saved = JSON.parse(localStorage.getItem(FOLDED) || 'null');
    if (saved && typeof saved === 'object') return saved;
  } catch {}
  return null;
}

const lookFolds = () => (currentLook() === 'design' ? { tools: true, colors: true } : { folders: true, settings: true });

function initFolding() {
  // Один объект правим на месте: подписка одна на всю жизнь страницы.
  const folds = savedFolds() || lookFolds();

  const draw = () => {
    for (const button of rail.querySelectorAll('.rail-fold')) {
      const name = button.dataset.fold;
      button.closest('.rail-block').classList.toggle('folded', !!folds[name]);
      button.title = folds[name] ? t('editor.rail.unfold') : t('editor.rail.fold');
    }
  };

  for (const button of rail.querySelectorAll('.rail-fold')) {
    button.onclick = () => {
      const name = button.dataset.fold;
      folds[name] = !folds[name];
      try { localStorage.setItem(FOLDED, JSON.stringify(folds)); } catch {}
      draw();
    };
  }
  draw();

  // Сменили вид — умолчание по виду, но только пока человек не выбирал сам.
  on('look', () => {
    if (savedFolds()) return;
    for (const key of Object.keys(folds)) delete folds[key];
    Object.assign(folds, lookFolds());
    draw();
  });
}
