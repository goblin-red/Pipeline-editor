/* Мои проекты: один список и для панели, и для вкладки «Проекты».
   Отдаёт: fillProjects(), openProject(), newProject().
   Не делает: не решает, где список показывать — только рисует строки в то,
   что ему дали.

   Список приходит из api/sync.js и живёт в state.projects: первым идёт тот
   проект, который правили последним, — его же открывает пустой адрес. */

import { state, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { loadMine, loadProject, loadFolder } from 'goblin/api/sync.js';
import { flush } from 'goblin/edit/scene.js';
import { t, tn } from 'goblin/core/i18n.js';

/** Нарисовать список своих проектов внутри переданного узла. */
export async function fillProjects(holder) {
  holder.textContent = t('editor.projects.loading');
  holder.classList.add('muted');
  const list = await loadMine();
  holder.classList.remove('muted');
  holder.textContent = '';

  if (!list.length) {
    holder.append(hint(t('editor.projects.none_here')));
    holder.append(link(t('editor.projects.open_account'), 'account.php'));
    return;
  }
  for (const project of list) holder.append(row(project));
}

/* ── Выбор проекта в списке: одинаково во всех скинах ─────────────
   Один щелчок — проект текущий, свойства в правой панели; двойной — внутрь, к папкам
   (событие 'enter-project': как именно войти, решает сама плашка).
   Одиночный ждёт чуть-чуть: двойной щелчок начинается с двух одиночных,
   и панель успевала мигнуть свойствами проекта перед самым переходом.
   Какой проект выбран — state.pickedProject; сменился — 'picked-project'. */

const PICK_WAIT = 250;          // мс: столько ждём второго щелчка
let pickTimer = 0;

/** Повесить на строку проекта один и двойной щелчок. */
export function projectClicks(node, key) {
  node.dataset.key = key;
  node.classList.toggle('picked', state.pickedProject === key);
  node.onclick = (event) => {
    clearTimeout(pickTimer);
    if (event.detail > 1) return;
    // Один щелчок — проект становится текущим (схема на холсте, свойства справа), список остаётся.
    pickTimer = setTimeout(async () => { await openProject(key); pickProject(key); }, PICK_WAIT);
  };
  node.ondblclick = () => { clearTimeout(pickTimer); emit('enter-project', key); };
}

/** Показать свойства проекта в правой панели, на вкладке «Свойства». */
export function pickProject(key) {
  state.pickedProject = key;
  state.welcome = false;
  state.selection.clear();
  emit('selection');
  emit('picked-project');
  emit('props-tab');
  emit('panel');
}

/** «…» над проектами: приветствие в правой панели (логотип, позже — новости). */
export function showWelcome() {
  state.pickedProject = null;
  state.welcome = true;
  state.selection.clear();
  emit('selection');
  emit('picked-project');
  emit('props-tab');
  emit('panel');
}

/** Панель больше не про проект: вошли в него, выбрали объект или папку. */
export function dropPicked() {
  if (!state.pickedProject && !state.welcome) return;
  state.pickedProject = null;
  state.welcome = false;
  emit('picked-project');
  emit('panel');
}

/** Подсветить выбранный проект среди строк списка. */
export function markPicked(nodes) {
  for (const node of nodes) node.classList.toggle('picked', node.dataset.key === state.pickedProject);
}

/**
 * Перейти в проект — на месте, без перезагрузки страницы: раньше она
 * перечитывалась целиком, плашки мигали и всё дёргалось. Проект и его первая
 * папка грузятся теми же loadProject() и loadFolder(), что и при старте.
 * Не вышло — старый путь: перечитать страницу по новому адресу.
 */
export async function openProject(key) {
  if (!key || key === state.project?.key) return;
  await flush();                     // недописанные правки уходят в свой проект
  state.project = null;              // опрос молчит, пока проект сменяется
  state.selection.clear();
  emit('selection');
  api.setHash({ p: key, f: null, run: null });
  try {
    await loadProject();
    await loadFolder();
  } catch {
    location.reload();
  }
}

/**
 * Завести новый проект: придумываем ключ и сразу пишем в него название —
 * проект рождается первой записью. Дальше открываем его как обычно.
 */
export async function newProject(title = t('editor.projects.new_tile')) {
  const key = Array.from({ length: 10 },
    () => 'abcdefghijklmnopqrstuvwxyz0123456789'[Math.floor(Math.random() * 36)]).join('');
  await api.post('project.update', { project: key, title });
  location.hash = '#p=' + key;
  location.reload();
}

function row(project) {
  const here = project.key === state.project?.key;
  const node = document.createElement('button');
  node.className = 'project-row' + (here ? ' is-current' : '');
  node.setAttribute('aria-label', project.title || project.key);
  node.disabled = here;
  node.innerHTML = `<span class="project-icon">${here ? '📂' : '🗂'}</span>`
    + `<span class="project-name">${escape(project.title || project.key)}</span>`
    // Дата — та же, по которой список отсортирован: последнее касание проекта,
    // открывал его человек или правил. Дата открытия сама по себе порядку
    // не соответствовала, и список казался перемешанным.
    + `<span class="project-meta"><span>${papers(project.folders)}</span><span>${when(project.touched || project.seen)}</span></span>`;
  node.onclick = () => openProject(project.key);
  return node;
}

function papers(count) {
  return tn('editor.projects.folders', Number(count) || 0);
}

/** Когда открывали: сегодняшнее — временем, остальное — датой. */
function when(stamp) {
  if (!stamp) return '';
  const date = new Date(String(stamp).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return '';
  const two = (value) => String(value).padStart(2, '0');
  const today = new Date();
  const sameDay = date.toDateString() === today.toDateString();
  return sameDay
    ? t('editor.projects.today', { time: `${two(date.getHours())}:${two(date.getMinutes())}` })
    : `${two(date.getDate())}.${two(date.getMonth() + 1)}.${date.getFullYear()}`;
}

function hint(text) {
  const node = document.createElement('p');
  node.className = 'muted';
  node.style.fontSize = '12.5px';
  node.textContent = text;
  return node;
}

function link(text, href) {
  const node = document.createElement('a');
  node.className = 'btn btn-quiet btn-wide';
  node.href = href;
  node.textContent = text;
  return node;
}

function escape(text) {
  return String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
}
