/* Агенты проекта в панели: список или плитки, сортировка и работа пачкой.
   Отдаёт: agentsSection().
   Не делает: не правит самого агента — его карточку открывает shell/agentview.js.

   Щелчок выбирает агента, двойной — открывает карточку, а протяжка с кирпича
   уносит его на блок схемы. Рамкой по пустому месту подложки выделяют сразу
   несколько, дальше — удалить
   выбранных пачкой. Несколько новых заводятся в самой карточке агента:
   там у нового поле имени принимает список строк. */

import { state, on } from 'goblin/core/state.js';
import { settings, setSetting } from 'goblin/core/settings.js';
import * as api from 'goblin/api/client.js';
import { loadProject, loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { openAgent, newAgent, cliColor, cliRows } from 'goblin/shell/agentview.js';
import { AGENT_DATA } from 'goblin/edit/place.js';
import { agentUsed } from 'goblin/core/usage.js';
import { pick, checkbox } from 'goblin/panel/parts.js';
import { t } from 'goblin/core/i18n.js';

/* Среда прогона папки: как leader связан с worker. Есть только в команде;
   по ней сервер собирает инструкции агентов (lib/folders/folders.php, RUN_ENVS).
   Первая — по умолчанию. Настройка папки, а не проекта. */
const RUN_ENVS = [
  { key: 'subagents', label: t('editor.agents.env_subagents') },
  { key: 'orca', label: t('editor.agents.env_orca') },
  { key: 'sendmessage', label: t('editor.agents.env_sendmessage') },
];

/* Состав команды папки: solo оставляет работу ведущему; остальные варианты
   допускают worker. Консультант и аудитор пока только отмечены в ссылке 🔗. */
const ROLE_SCHEMES = [
  { key: 'solo', label: t('editor.agents.scheme_solo') },
  { key: 'leader-worker', label: t('editor.agents.scheme_lw') },
  { key: 'consult-worker', label: t('editor.agents.scheme_lcw') },
  { key: 'consult-audit-worker', label: t('editor.agents.scheme_lcaw') },
];

const SORTS = [
  { key: 'name', title: t('editor.agents.sort_name') },
  { key: 'cli', title: t('editor.agents.sort_cli') },
  { key: 'new', title: t('editor.agents.sort_new') },
  { key: 'used', title: t('editor.agents.sort_used') },
];

let sort = 'name';
const picked = new Set();     // id выбранных агентов

let colorsReady = false;      // списки config.txt: без них цвета инструментов серые

/** Вся вкладка «Агенты проекта». redraw — перерисовать её же. */
export function agentsSection(body, redraw) {
  // Цвета инструментов живут в config.txt: читаем один раз и перерисовываем,
  // иначе первая отрисовка выходит серой.
  if (!colorsReady) {
    cliRows().then(() => { colorsReady = true; redraw(); }).catch(() => { colorsReady = true; });
  }
  /* «+ агент» держится наверху, кнопки выбранного — внизу: и то и другое
     видно при любой прокрутке списка. Переключатели вида и сортировки идут
     под кнопкой — они уезжают вместе со списком. */
  body.append(adder());
  const roles = roleScheme(redraw);
  if (roles) body.append(roles);
  if ((state.folder?.roleScheme || 'solo') !== 'solo') {
    const env = runEnv();
    if (env) body.append(env);
  }
  if (state.folder) body.append(shareScheme());
  body.append(tools(redraw));

  /* Кирпичи лежат на подложке: по ней видно, где можно тянуть рамку. Щелчок
     мимо подложки снимает выбор. */
  const board = document.createElement('div');
  board.className = 'agents-board';
  const list = [...state.agents].sort(order);
  const holder = settings.agentsView === 'tiles' ? tiles(list, redraw) : rows(list, redraw);
  board.append(holder);
  body.append(board);
  if (!list.length) body.append(note(t('editor.agents.none')));

  marquee(board, redraw);
  watchOutside(redraw);
  watchCounts();
  body.append(bottom(redraw));
}

/** «+ агент» — ярлычок по центру вверху панели: заводить можно всегда. */
function adder() {
  const line = document.createElement('div');
  line.className = 'agents-add';
  line.append(button(t('editor.agents.add'), 'btn btn-quiet', () => newAgent()));
  return line;
}

/** «Среда прогона» открытой папки — выпадающим списком над агентами. */
function runEnv() {
  const folder = state.folder;
  if (!folder) return null;

  const line = document.createElement('div');
  line.className = 'field agents-env';
  const title = document.createElement('span');
  title.textContent = t('editor.agents.env_title');
  title.title = t('editor.agents.env_hint');

  const box = pick(RUN_ENVS, folder.runEnv || 'subagents', false);
  box.disabled = state.viewOnly;
  box.onchange = () => {
    folder.runEnv = box.value;
    api.post('folder.update', { id: folder.id, runEnv: box.value });
  };
  line.append(title, box);
  return line;
}

/** Общая картина схемы для исполнителей — настройка команды, поэтому здесь, под составом и средой. */
function shareScheme() {
  const folder = state.folder;
  return checkbox(t('editor.panel.share_scheme'), folder.shareScheme, (value) => {
    folder.shareScheme = value;
    api.post('folder.update', { id: folder.id, shareScheme: value });
  }, t('editor.panel.share_hint'));
}

/** Сначала состав команды; среда нужна только когда есть исполнители. */
function roleScheme(redraw) {
  const folder = state.folder;
  if (!folder) return null;

  const line = document.createElement('div');
  line.className = 'field agents-env';
  const title = document.createElement('span');
  title.textContent = t('editor.agents.team');

  const box = pick(ROLE_SCHEMES, folder.roleScheme || 'solo', false);
  box.disabled = state.viewOnly;
  box.onchange = () => {
    folder.roleScheme = box.value;
    api.post('folder.update', { id: folder.id, roleScheme: box.value });
    redraw();
  };
  line.append(title, box);
  return line;
}

/* Агента назначили или сняли на холсте — число на кирпиче меняем на месте.
   Перерисовывать всю вкладку нельзя: сбился бы выбор и выделение рамкой. */
let counting = false;
function watchCounts() {
  if (counting) return;
  counting = true;
  const fresh = () => {
    const board = document.querySelector('.agents-board');
    if (!board) return;
    // Агентов завели или удалили — вкладку собираем заново, иначе новых не видно.
    const drawn = [...board.querySelectorAll('.agent-pickable[data-agent]')].map((node) => Number(node.dataset.agent));
    if (drawn.length !== state.agents.length || state.agents.some((item) => !drawn.includes(item.id))) {
      again?.();
      return;
    }
    for (const node of board.querySelectorAll('.agent-pickable[data-agent]')) {
      const agent = state.agents.find((item) => item.id === Number(node.dataset.agent));
      if (!agent) continue;
      const many = agentUsed(agent);
      let mark = node.querySelector('.agent-count');
      if (!many) { mark?.remove(); continue; }
      if (!mark) {
        mark = document.createElement('b');
        mark.className = 'agent-count';
        node.append(mark);
      }
      mark.textContent = String(many);
      mark.title = t('editor.agents.placed', { n: many });
    }
  };
  on('element', fresh);
  on('agents', fresh);
  on('folder', fresh);
}

/* ── Порядок ──────────────────────────────────────────────────── */

function order(a, b) {
  // По блокам — сначала самые занятые: видно, кто тянет схему.
  if (sort === 'used') return agentUsed(b) - agentUsed(a) || a.name.localeCompare(b.name);
  if (sort === 'cli') return (a.cli || 'яя').localeCompare(b.cli || 'яя') || a.name.localeCompare(b.name);
  if (sort === 'new') return String(b.created || '').localeCompare(String(a.created || ''));
  return a.name.localeCompare(b.name);
}

/* ── Верхняя строка: вид, сортировка, «выбрано» ───────────────── */

function tools(redraw) {
  const line = document.createElement('div');
  line.className = 'agents-tools';

  const view = document.createElement('div');
  view.className = 'switch switch-small';
  for (const [value, word] of [['list', t('editor.agents.view_list')], ['tiles', t('editor.agents.view_tiles')]]) {
    const button = document.createElement('button');
    button.className = 'switch-item' + (settings.agentsView === value ? ' on' : '');
    button.textContent = word;
    button.onclick = () => { setSetting('agentsView', value); redraw(); };
    view.append(button);
  }

  const by = document.createElement('div');
  by.className = 'switch switch-small';
  for (const item of SORTS) {
    const button = document.createElement('button');
    button.className = 'switch-item' + (sort === item.key ? ' on' : '');
    button.textContent = item.title;
    button.onclick = () => { sort = item.key; redraw(); };
    by.append(button);
  }

  line.append(view, by);
  return line;
}

/* ── Две раскладки ────────────────────────────────────────────── */

function rows(list, redraw) {
  const holder = document.createElement('div');
  holder.className = 'agent-rows';
  holder.append(rowsHead(redraw));
  for (const agent of list) {
    /* Строка — сетка в четыре столбца: точка, имя, кто и чем работает, число
       блоков. Столбцы одинаковой ширины у всех строк, поэтому список читается
       колонками, а не рваными хвостами. */
    const row = document.createElement('button');
    row.className = 'agent-row agent-pickable' + (picked.has(agent.id) ? ' picked' : '');
    row.dataset.agent = agent.id;
    row.style.setProperty('--cli', cliColor(agent));
    row.innerHTML = `<i class="cli-dot" style="background:${cliColor(agent)}"></i>`
      + `<span class="agent-row-name">${escape(agent.name)}</span>`
      + `<span class="agent-row-role">${agent.role === 'lead' ? 'leader' : 'worker'}</span>`
      + `<span class="agent-row-cli">${escape(agent.cli || t('editor.agents.no_cli'))}</span>`
      + count(agent);
    row.onclick = (event) => choose(agent, row, event);
    row.ondblclick = () => openAgent(agent);
    dragging(row, agent);
    holder.append(row);
  }
  return holder;
}

/* Шапка таблицы: по ней же и сортируют — щелчок по столбцу меняет порядок,
   как в любом списке файлов. Столбцы те же, что и у строк. */
function rowsHead(redraw) {
  const head = document.createElement('div');
  head.className = 'agent-row agent-head';
  head.append(document.createElement('i'));           // столбец точки CLI
  for (const [key, title] of [['name', t('editor.agents.col_name')], [null, t('editor.agents.col_role')], ['cli', t('editor.agents.col_cli')], ['used', t('editor.agents.col_used')]]) {
    const cell = document.createElement('span');
    cell.textContent = title;
    if (key) {
      cell.className = 'agent-head-sort' + (sort === key ? ' on' : '');
      cell.onclick = () => { sort = key; redraw(); };
    }
    head.append(cell);
  }
  return head;
}

function tiles(list, redraw) {
  const holder = document.createElement('div');
  holder.className = 'agent-tiles';
  for (const agent of list) {
    const tile = document.createElement('button');
    tile.className = 'agent-tile agent-pickable' + (agent.role === 'lead' ? ' lead' : '')
      + (picked.has(agent.id) ? ' picked' : '');
    tile.dataset.agent = agent.id;
    tile.title = `${agent.name} · ${agent.cli || t('editor.agents.no_cli')}`;
    tile.style.setProperty('--cli', cliColor(agent));
    tile.innerHTML = `<small>${escape(agent.name)}</small><i>${escape(agent.cli || t('editor.agents.no_cli'))}</i>`
      + count(agent);
    tile.onclick = (event) => choose(agent, tile, event);
    tile.ondblclick = () => openAgent(agent);
    dragging(tile, agent);
    holder.append(tile);
  }
  return holder;
}

/** Значок с числом блоков: ноль не показываем — пустая цифра только мешает. */
function count(agent) {
  const many = agentUsed(agent);
  return many ? `<b class="agent-count" title="${t('editor.agents.placed', { n: many })}">${many}</b>` : '';
}

/**
 * Щелчок выбирает агента, двойной — открывает его карточку.
 * Перерисовывать всю вкладку на выбор нельзя: узел под курсором сменился бы,
 * и второй щелчок уже не пришёл бы по нему. Поэтому правим только сам узел
 * и нижнюю строку с действиями.
 */
function choose(agent, node, event) {
  if (justPicked) return;                       // щелчок после рамки не считается

  // Обычный щелчок выбирает одного — прежний выбор снимается. Shift (или
  // Cmd/Ctrl) добавляет к выбранным, как везде.
  if (!(event?.shiftKey || event?.metaKey || event?.ctrlKey)) {
    picked.clear();
    for (const other of node.parentElement.querySelectorAll('.agent-pickable.picked')) {
      other.classList.remove('picked');
    }
    picked.add(agent.id);
    node.classList.add('picked');
    drawBottom();
    return;
  }

  const on = !picked.has(agent.id);
  if (on) picked.add(agent.id); else picked.delete(agent.id);
  node.classList.toggle('picked', on);
  drawBottom();
}

/**
 * Ручка переноса: за неё агента тянут на холст, а нажатие на саму плитку
 * оставлено рамке выделения. Иначе выделять было нечем: плитки занимают
 * почти всё место, и любое нажатие начинало перенос.
 */
function dragging(node, agent) {
  // Тянуть можно и сам кирпич, и ручку: ручка просто говорит, что так можно.
  const grip = document.createElement('span');
  grip.className = 'agent-grip';
  grip.title = t('editor.agents.drag');
  grip.textContent = '⠿';
  node.append(grip);
  grip.addEventListener('click', (event) => event.stopPropagation());

  node.draggable = !state.viewOnly;
  node.addEventListener('dragstart', (event) => {
    event.dataTransfer.setData(AGENT_DATA, String(agent.id));
    event.dataTransfer.setData('text/plain', agent.name);
    event.dataTransfer.effectAllowed = 'copy';
    document.body.classList.add('dragging-agent');
  });
  node.addEventListener('dragend', () => document.body.classList.remove('dragging-agent'));
}

/* ── Выделение рамкой ─────────────────────────────────────────── */

/** Тянем по пустому месту — выбираем всех, кого накрыли. */
let justPicked = false;      // рамка только что отработала: щелчок за ней не считается
let watching = false;       // слушатель «щёлкнули мимо подложки» ставится один раз

/** Щелчок мимо подложки снимает выбор — как на холсте щелчок по пустому месту. */
function watchOutside(redraw) {
  if (watching) { again = redraw; return; }
  watching = true;
  document.addEventListener('pointerdown', (event) => {
    if (!picked.size) return;
    if (event.target.closest('.agents-board, .agents-bottom, #dialog-agent')) return;
    picked.clear();
    (again || redraw)();
  });
}

function marquee(holder, redraw) {
  holder.addEventListener('pointerdown', (event) => {
    // По кирпичу — перенос на холст, по пустому месту подложки — рамка выбора.
    if (event.target.closest('.agent-pickable')) return;
    if (event.button !== 0) return;

    const from = { x: event.clientX, y: event.clientY };
    const add = event.shiftKey || event.metaKey || event.ctrlKey;
    const onTile = !!event.target.closest('.agent-pickable');
    let frame = null;
    let moved = false;

    const move = (now) => {
      if (!moved && Math.abs(now.clientX - from.x) + Math.abs(now.clientY - from.y) <= 4) return;
      if (!moved) {
        // Поехали — это рамка, а не щелчок. Прежний выбор снимаем здесь же.
        moved = true;
        frame = document.createElement('div');
        frame.className = 'agent-marquee';
        document.body.append(frame);
        if (!add) {
          picked.clear();
          for (const node of holder.querySelectorAll('.agent-pickable.picked')) node.classList.remove('picked');
        }
      }
      const box = {
        left: Math.min(from.x, now.clientX), top: Math.min(from.y, now.clientY),
        right: Math.max(from.x, now.clientX), bottom: Math.max(from.y, now.clientY),
      };
      Object.assign(frame.style, {
        left: box.left + 'px', top: box.top + 'px',
        width: (box.right - box.left) + 'px', height: (box.bottom - box.top) + 'px',
      });
      for (const node of holder.querySelectorAll('.agent-pickable')) {
        const r = node.getBoundingClientRect();
        const hit = !(r.right < box.left || r.left > box.right || r.bottom < box.top || r.top > box.bottom);
        node.classList.toggle('picked', hit || picked.has(Number(node.dataset.agent)));
      }
    };
    const up = () => {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      if (!moved) {
        // Щёлкнули по пустому месту подложки — это снятие выбора.
        if (!onTile && picked.size) { picked.clear(); redraw(); }
        return;                                 // щелчок по плитке разберёт она сама
      }
      for (const node of holder.querySelectorAll('.agent-pickable.picked')) picked.add(Number(node.dataset.agent));
      frame?.remove();
      justPicked = true;
      setTimeout(() => { justPicked = false; }, 0);
      redraw();
    };
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
  });
}

/* ── Нижняя строка: завести и удалить ─────────────────────────── */

let bottomLine = null;     // нижняя строка: её и обновляем при выборе
let again = null;          // как перерисовать вкладку целиком

function drawBottom() {
  if (!bottomLine) return;
  bottomLine.textContent = '';
  fillBottom(bottomLine, again || (() => {}));
}

function bottom(redraw) {
  const line = document.createElement('div');
  line.className = 'agents-bottom';
  bottomLine = line;
  again = redraw;
  fillBottom(line, redraw);
  return line;
}

function fillBottom(line, redraw) {
  // Плашка живёт только при выборе: пустая она лишь отнимала бы место.
  line.hidden = !picked.size;
  if (picked.size) {
    // Снимает выбранных агентов со всех блоков проекта — сами агенты остаются.
    line.append(button(t('editor.agent.detach'), 'btn btn-quiet', async () => {
      await api.batch([...picked].map((id) => ({ op: 'agent.detach', id })), { opId: 'agents-off-' + Date.now() });
      await loadProject();
      await loadFolder();
      toast(t('editor.agents.detached'));
      redraw();
    }));
    line.append(button(t('editor.agents.delete_n', { n: picked.size }), 'btn btn-quiet btn-danger', async () => {
      if (!confirm(t('editor.agents.delete_ask', { n: picked.size }))) return;
      await api.batch([...picked].map((id) => ({ op: 'agent.delete', id })), { opId: 'agents-' + Date.now() });
      picked.clear();
      await loadProject();
      toast(t('editor.agents.deleted'));
      redraw();
    }));

  }
}

/* ── Мелочи ───────────────────────────────────────────────────── */


function note(text) {
  const node = document.createElement('p');
  node.className = 'muted';
  node.style.fontSize = '12.5px';
  node.textContent = text;
  return node;
}

function button(text, cls, onClick) {
  const node = document.createElement('button');
  node.className = cls;
  node.textContent = text;
  node.onclick = onClick;
  node.disabled = state.viewOnly && !cls.includes('quiet');
  return node;
}

const escape = (value) => String(value ?? '')
  .replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
