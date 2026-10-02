/* Вкладки правой панели: свойства, агенты, материалы, прогон, помощник.
   Что делает: переключает вкладки (они стоят в шапке справа), помнит
   открытую, рисует тело вкладок кроме «Свойств» — те рисует panel/panel.js.
   Отдаёт: initPanelTabs().
   Не делает: не трогает левую плашку — только сообщает ей 'panel-tab'. */

import { state, on, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { mountRunbar, parkRunbar } from 'goblin/shell/runbar.js';
import { openStartDialog } from 'goblin/run/start.js';
import { runLogStrip } from 'goblin/shell/runlog.js';
import { setVariant } from 'goblin/core/variants.js';
import { agentsSection } from 'goblin/panel/agents-view.js';
import { openChat, recentChats, forgetChat, stopPlay } from 'goblin/panel/ai.js';
import { toast } from 'goblin/shell/topbar.js';
import { refreshRun } from 'goblin/run/paint.js';
import { dropZone, viewSwitch, workFiles, groupOpen, toggleGroup } from 'goblin/panel/assets-view.js';
import { loadFolder } from 'goblin/api/sync.js';
import { t } from 'goblin/core/i18n.js';

const escape = (text) => String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));

/* ── Вкладки правой панели ────────────────────────────────────
   В прежнем Гоблине панель была пятью приложениями под значками.
   Здесь то же самое, только данные берутся из v2. */

const TABS = {
  props: renderProps,
  agents: renderAgents,
  assets: renderAssets,
  run: renderRunTab,
  ai: renderAiTab,
};

/* Вкладка панели тоже помнится: после обновления страницы человек оказывается
   там, где был, а не всегда в «Свойствах». */
const TAB_STORE = 'goblin-panel-tab';
let activeTab = readTab();
let panelRender = 0;

function readTab() {
  try {
    const saved = localStorage.getItem(TAB_STORE);
    return saved && (saved === 'props' || saved in TABS) ? saved : 'props';
  } catch { return 'props'; }
}

export function initPanelTabs() {
  const tabs = document.getElementById('panel-tabs');
  if (!tabs) return;
  /* Вкладки живут в шапке справа: они нужны в любом виде и не должны
     уезжать вместе с панелью. Если места в шапке нет, остаётся прежнее
     место в самой панели. */
  const home = document.getElementById('panel-tabs-top')
    || document.getElementById('panel-tabs-slot');
  home?.append(tabs);

  for (const button of tabs.querySelectorAll('[data-tab]')) {
    button.classList.toggle('on', button.dataset.tab === activeTab);
    button.onclick = () => {
      activeTab = button.dataset.tab;
      try { localStorage.setItem(TAB_STORE, activeTab); } catch {}
      for (const other of tabs.children) other.classList.toggle('on', other === button);
      showTab();
    };
  }
  // С холста попросили показать свойства — переключаемся на их вкладку.
  on('props-tab', () => {
    if (activeTab === 'props') return;
    activeTab = 'props';
    try { localStorage.setItem(TAB_STORE, activeTab); } catch {}
    for (const button of tabs.querySelectorAll('[data-tab]')) {
      button.classList.toggle('on', button.dataset.tab === 'props');
    }
    showTab();
  });
  on('look', syncTabs);
  on('variant', syncTabs);
  on('project', () => { if (activeTab !== 'props') showTab(); });
  /* Материалы — это файлы рабочей папки на диске, а не вложения блоков:
     правки элементов их не меняют. Раньше вкладка слушала ещё 'element' и
     'drop', и от каждого переноса блока список перезагружался и мигал. */
  for (const event of ['folder', 'project', 'scheme', 'folders']) {
    on(event, () => {
      if (activeTab === 'assets') showTab();
    });
  }
  // История помощника — чаты открытой папки: перешли в другую — список заново.
  on('folder', () => { if (activeTab === 'ai') showTab(); });
  syncTabs();
}

/* Вкладки панели есть в любом виде: прогон, материалы и агенты нужны
   одинаково всем. Раньше они были только в «Классике», и полоса прогона в
   остальных видах уезжала вниз экрана. */
function syncTabs() {
  const tabs = document.getElementById('panel-tabs');
  tabs.hidden = false;
  showTab();
}

function showTab() {
  panelRender++;
  // Левая плашка подстраивается под вкладку: на «Прогоне» она отдаётся архиву.
  emit('panel-tab', activeTab);
  const body = document.getElementById('panel-body');
  // Шапка с номером и названием выделенного — только у «Свойств».
  const head = document.getElementById('panel-head');
  if (head && activeTab !== 'props') head.hidden = true;
  // Полосу прогона уносим заранее: очистка тела панели снесла бы её узел.
  parkRunbar();
  if (activeTab === 'props') {
    // Тело ещё держит чужую вкладку: очищаем, чтобы «Свойства» нарисовали себя заново.
    if (body.dataset.tab !== 'props') body.textContent = '';
    body.dataset.tab = 'props';
    emit('panel');        // панель свойств перерисует себя сама
    return;
  }
  body.textContent = '';
  body.dataset.tab = activeTab;
  TABS[activeTab]?.(body);
}

function renderProps() { emit('panel'); }

function renderAgents(body) {
  agentsSection(body, showTab);
}

/* Материалы — это файлы из in/ рабочих папок: открытой папки или всех папок проекта,
   переключатель сверху. Любой файл можно перетащить на блок этой папки.
   Выбор помнится в этом браузере. */
const ASSET_SCOPE_STORE = 'goblin-assets-scope';

function assetScope() {
  try { return localStorage.getItem(ASSET_SCOPE_STORE) === 'project' ? 'project' : 'folder'; }
  catch { return 'folder'; }
}

function scopeSwitch(scope) {
  const row = document.createElement('div');
  row.className = 'switch switch-small asset-scope';
  for (const [value, word] of [['folder', t('editor.linksview.folder')], ['project', t('editor.settings.project')]]) {
    const button = document.createElement('button');
    button.className = 'switch-item' + (scope === value ? ' on' : '');
    button.textContent = word;
    button.onclick = () => {
      try { localStorage.setItem(ASSET_SCOPE_STORE, value); } catch {}
      showTab();
    };
    row.append(button);
  }
  return row;
}

async function renderAssets(body) {
  const scope = assetScope();
  body.append(scopeSwitch(scope));      // заголовка нет: переключатель сам говорит, чьи материалы
  if (scope === 'project') { await renderProjectFiles(body, panelRender); return; }
  if (!state.folder) { body.append(note(t('editor.tabs.open_folder'))); return; }
  await renderWorkFolder(body, state.folder, panelRender);
}

/* Режим «Проект»: файлы из in/ рабочих папок всех папок схемы — разделами, в порядке
   дерева. Открыт раздел текущей папки, остальные свёрнуты (память — как в окне
   «из хранилища»). Папки без рабочей папки не показываем. */
async function renderProjectFiles(body, render) {
  const project = api.projectKey();
  body.append(dropZone(null, () => showTab(), null));
  const head = document.createElement('div');
  head.className = 'row asset-sort';
  head.append(viewSwitch(() => showTab()));
  body.append(head);

  const folders = [];
  const walk = (parent) => state.folders
    .filter((one) => (one.parent || null) === parent)
    .sort((a, b) => a.sort - b.sort || a.id - b.id)
    .forEach((one) => { if (one.workDir) folders.push(one); walk(one.id); });
  walk(null);
  if (!folders.length) { body.append(note(t('editor.pick.no_workdir_any'))); return; }

  const answers = await Promise.all(folders.map((one) => api.get('asset.workfiles', { folder: one.id, sub: 'in' }).catch(() => null)));
  if (render !== panelRender || project !== api.projectKey()) return;

  folders.forEach((folder, i) => {
    const root = String(answers[i]?.dir || folder.workDir).replace(/\/$/, '');
    const files = (answers[i]?.files || []).filter((file) => !file.path.startsWith(root + '/service/'));
    const key = 'f' + folder.id;

    const title = document.createElement('button');
    const box = document.createElement('div');
    box.className = 'asset-board';
    box.append(files.length ? workFiles(files.slice(0, 300), root, folder.id, () => showTab()) : note(t('editor.tabs.no_files')));
    const paint = () => {
      const open = groupOpen(key);
      title.className = 'asset-group-title' + (open ? '' : ' shut');
      title.innerHTML = `<span></span><b>${open ? '▾' : '▸'}</b>`;
      title.querySelector('span').textContent = `${folder.name || t('editor.folders.unnamed')} · ${files.length}`;
      box.hidden = !open;
    };
    title.onclick = () => { toggleGroup(key); paint(); };
    paint();
    body.append(title, box);
  });
}

/* Режим «Папка»: материалы — файлы из in/ рабочей папки (путь — в свойствах
   папки) и поле загрузки; загруженное ложится туда же, в in/. */
async function renderWorkFolder(body, folder, render) {
  const project = api.projectKey();
  body.append(dropZone(null, () => showTab(), folder.id));
  if (!folder.workDir) {
    body.append(note(t('editor.tabs.no_workdir')));
    return;
  }

  const [answer, done] = await Promise.all(['in', 'out'].map((sub) =>
    api.get('asset.workfiles', { folder: folder.id, sub }).catch(() => null)));
  if (render !== panelRender || project !== api.projectKey()) return;
  const root = String(answer?.dir || folder.workDir).replace(/\/$/, '');
  const files = (answer?.files || []).filter((file) => !file.path.startsWith(root + '/service/'));

  const head = document.createElement('div');
  head.className = 'row asset-sort';
  head.append(viewSwitch(() => showTab()));
  body.append(head);

  const board = document.createElement('div');
  board.className = 'asset-board';
  board.append(files.length ? workFiles(files.slice(0, 300), root, folder.id, () => showTab()) : note(t('editor.tabs.no_files')));
  body.append(board);

  body.append(...resultsSection(done?.files || [], root, folder.id));
}

/* Результаты — файлы из out/ рабочей папки (все прогоны, свежие сверху).
   Загрузки сюда нет, перенос на блок — как у материалов. Свёрнута по умолчанию,
   раскрытие помнится в этом браузере. */
const RESULTS_OPEN_STORE = 'goblin-results-open';

function resultsSection(files, root, folderId) {
  const title = document.createElement('button');
  const box = document.createElement('div');
  box.className = 'asset-board';
  box.append(files.length ? workFiles(files.slice(0, 300), root, folderId, () => showTab()) : note(t('editor.tabs.no_results')));

  const isOpen = () => { try { return localStorage.getItem(RESULTS_OPEN_STORE) === '1'; } catch { return false; } };
  const paint = () => {
    const open = isOpen();
    title.className = 'asset-group-title asset-results' + (open ? '' : ' shut');
    title.innerHTML = `<span></span><b>${open ? '▾' : '▸'}</b>`;
    title.querySelector('span').textContent = t('editor.tabs.results', { n: files.length });
    box.hidden = !open;
  };
  title.onclick = () => {
    try { localStorage.setItem(RESULTS_OPEN_STORE, isOpen() ? '0' : '1'); } catch {}
    paint();
  };
  paint();
  return [title, box];
}

/**
 * «Остановить прогон»: гасит и тест-прогон ИИ, и настоящий прогон папки.
 *
 * Настоящий останавливается сразу (run.stop с now): открытые шаги
 * отменяются, подсветка замирает, leader на следующей команде получит
 * отказ. Это необратимо, поэтому первое нажатие только спрашивает.
 */
function stopButton() {
  const button = document.createElement('button');
  button.className = 'btn btn-quiet btn-wide btn-stop';
  button.textContent = t('editor.tabs.stop_run');

  let armed = null;   // таймер «нажмите ещё раз»
  button.onclick = async () => {
    const run = state.run;
    const live = run && ['running', 'paused'].includes(run.state);

    if (!live) {
      if (!stopPlay()) toast(t('editor.tabs.nothing_running'));
      return;
    }
    if (!armed) {
      button.textContent = t('editor.tabs.stop_confirm', { no: run.no });
      armed = setTimeout(() => { armed = null; button.textContent = t('editor.tabs.stop_run'); }, 4000);
      return;
    }
    clearTimeout(armed);
    armed = null;
    button.disabled = true;
    stopPlay();
    // Закрепить прогон: иначе после остановки папка покажет прошлый, где было больше шагов.
    api.setHash({ run: run.id });
    try {
      await api.post('run.stop', { run: run.id, now: 1 });
      toast(t('editor.tabs.stopped', { no: run.no }));
    } catch {
      // Причину уже показал общий обработчик ошибок api.
    }
    refreshRun();
    button.disabled = false;
    button.textContent = t('editor.tabs.stop_run');
  };
  return button;
}

/**
 * «Сбросить схему для прогона»: снимает с холста статусы и картинки прошлых
 * прогонов (run.prepare, files): блоки снова «не пройдены», результаты прошлого
 * прогона — в резерве service/archive/out_oldN, материалы человека не трогаются.
 * Пока прогон идёт, сервер откажет — сначала «Остановить». Первое нажатие спрашивает.
 */
function resetButton() {
  const button = document.createElement('button');
  button.className = 'btn btn-quiet btn-wide';
  button.textContent = t('editor.tabs.reset');

  let armed = null;
  button.onclick = async () => {
    if (!state.folder) return;
    const run = state.run;
    if (run && ['running', 'paused'].includes(run.state)) {
      toast(t('editor.tabs.running_stop_first', { no: run.no }), true);
      return;
    }
    if (!armed) {
      button.textContent = t('editor.tabs.reset_confirm');
      armed = setTimeout(() => { armed = null; button.textContent = t('editor.tabs.reset'); }, 4000);
      return;
    }
    clearTimeout(armed);
    armed = null;
    button.disabled = true;
    try {
      await api.post('run.prepare', { folder: state.folder.id, files: 1 });
      api.setHash({ run: null });
      await loadFolder();
      toast(t('editor.tabs.reset_done'));
    } catch {
      // Причину уже показал общий обработчик ошибок api.
    }
    button.disabled = false;
    button.textContent = t('editor.tabs.reset');
  };
  return button;
}

function renderRunTab(body) {
  body.append(title(t('editor.variants.run')));

  const start = document.createElement('button');
  start.className = 'btn btn-accent btn-wide';
  start.textContent = t('editor.start.go');
  start.onclick = openStartDialog;
  body.append(start);

  body.append(stopButton());
  body.append(resetButton());

  /* Архив прогонов живёт на экране «Прогон» — там ему место и ширина.
     В панели остаётся только текущий: полоса управления и лента событий. */
  // Лента времени выбранного прогона: кто какой блок брал и когда.
  const timeline = document.createElement('button');
  timeline.className = 'btn btn-quiet btn-wide';
  timeline.textContent = t('editor.timeline.open_view');
  timeline.onclick = () => setVariant('timeline');
  body.append(timeline);

  mountRunbar(body);
  runLogStrip(body, () => setVariant('run'));

  if (!(state.runs || []).length) body.append(note(t('editor.tabs.no_runs')));
}

function renderAiTab(body) {
  body.append(title(t('editor.tabs.ai')));
  const open = document.createElement('button');
  open.className = 'btn btn-accent btn-wide';
  open.textContent = t('editor.ai.new');
  open.onclick = () => openChat(null);
  body.append(open);

  // История под кнопкой — разговоры этой папки: сначала пять свежих,
  // «Показать ещё» раскрывает следующие здесь же.
  body.append(title(t('editor.tabs.history')));
  const list = document.createElement('div');
  list.className = 'asset-list';
  body.append(list);
  list.textContent = t('editor.projects.loading');
  // Папка ещё грузится (открыли страницу) — список нарисует событие 'folder'.
  if (!state.folder) return;

  const render = panelRender;
  recentChats(50).then((chats) => {
    if (render !== panelRender) return;   // вкладку или папку уже сменили
    list.textContent = '';
    if (!chats.length) { list.append(note(t('editor.tabs.no_chats'))); return; }

    const more = document.createElement('button');
    more.className = 'btn btn-quiet btn-wide';
    more.textContent = t('editor.tabs.more');
    list.append(more);
    let shown = 0;
    const showMore = (count) => {
      for (const chat of chats.slice(shown, shown + count)) more.before(chatRow(chat));
      shown += count;
      more.hidden = shown >= chats.length;
    };
    more.onclick = () => showMore(10);
    showMore(5);
  });
}

/* Строка истории: щелчок открывает разговор на экране помощника, корзина справа удаляет.
   Строка — не кнопка: кнопка внутри кнопки недопустима. */
function chatRow(chat) {
  const row = document.createElement('div');
  row.className = 'asset asset-open';
  row.style.border = 'none';
  row.tabIndex = 0;
  row.innerHTML = `<span>💬</span><span class="asset-name">${escape(chat.title || t('editor.tabs.chat'))}</span>`
    + `<span class="role">${escape(when(chat.at))}</span>`;
  row.onclick = () => openChat(chat.id);
  row.onkeydown = (event) => { if (event.key === 'Enter' && event.target === row) openChat(chat.id); };
  row.append(chatDelete(chat, row));
  return row;
}

/* Удалить разговор — двумя нажатиями, как остановку прогона: первое только
   спрашивает (корзина краснеет), второе удаляет. Удалённый разговор не вернуть. */
function chatDelete(chat, row) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'chat-del';
  button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
    + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M3 6h18M8 6V3h8v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>';

  // Подсказки у корзины нет: значок понятен и так.
  let armed = 0;
  const disarm = () => {
    armed = 0;
    button.classList.remove('sure');
  };

  button.onclick = async (event) => {
    event.stopPropagation();   // щелчок по корзине не открывает разговор
    if (!armed) {
      button.classList.add('sure');
      armed = setTimeout(disarm, 3000);
      return;
    }
    clearTimeout(armed);
    button.disabled = true;
    try {
      await api.post('ai.chat.delete', { chat: chat.id });
    } catch {
      disarm();                // причину покажет общий обработчик ошибок
      button.disabled = false;
      return;
    }
    forgetChat(chat.id);
    const list = row.parentNode;
    row.remove();
    // Видимых строк не осталось — перечитать: покажет следующие или «разговоров не было».
    if (list?.isConnected && !list.querySelector('.asset')) showTab();
    toast(t('editor.tabs.chat_deleted'));
  };
  return button;
}

/* Когда был разговор: сегодняшний — временем, прежний — датой. */
function when(stamp) {
  if (!stamp) return '';
  const date = new Date(String(stamp).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return '';
  const two = (value) => String(value).padStart(2, '0');
  return date.toDateString() === new Date().toDateString()
    ? `${two(date.getHours())}:${two(date.getMinutes())}`
    : `${two(date.getDate())}.${two(date.getMonth() + 1)}`;
}

function title(text) {
  const node = document.createElement('h3');
  node.className = 'panel-title';
  node.textContent = text;
  return node;
}

function note(text) {
  const node = document.createElement('p');
  node.className = 'muted';
  node.textContent = text;
  node.style.fontSize = '12.5px';
  return node;
}
