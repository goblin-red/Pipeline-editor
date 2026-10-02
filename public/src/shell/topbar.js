/* Шапка: где я, поиск, состояние сохранения, тема и режим показа.
   Отдаёт: initTopbar(), toast().
   Не делает: не правит схему. */

import { state, on, emit } from 'goblin/core/state.js';
import { kindOf, iconOf, TOOLS } from 'goblin/core/kinds.js';
import { toggleIso, camera, flyTo, fitZoom } from 'goblin/canvas/view.js';
import { VARIANTS, setVariant, currentVariant } from 'goblin/core/variants.js';
import { LOOKS, setLook, currentLook } from 'goblin/canvas/looks.js';
import { undo, redo, canUndo, canRedo } from 'goblin/edit/history.js';
import { openSettings } from 'goblin/shell/dialogs.js';
import * as api from 'goblin/api/client.js';
import { t } from 'goblin/core/i18n.js';

export function initTopbar() {
  initPulse();
  on('project', drawAccount);
  on('projects', drawAccount);
  on('left-mode', placeAccount);
  const saveState = document.getElementById('save-state');
  const search = document.getElementById('search');
  const drop = document.getElementById('search-drop');

  // «сохранено» в шапке сейчас закомментировано (views/editor/topbar.html).
  if (saveState) on('dirty', (dirty) => {
    saveState.textContent = dirty ? t('editor.topbar.saving') : t('editor.topbar.saved');
    saveState.classList.toggle('dirty', dirty);
  });

  // Переключатель вида: холст, список, прогон. Данные у всех одни.
  const swap = document.getElementById('ui-switch');
  const drawSwitch = () => {
    swap.textContent = '';
    for (const [name, item] of Object.entries(VARIANTS)) {
      if (item.hidden) continue;          // в прогон заходят из панели
      const button = document.createElement('button');
      button.className = 'switch-item switch-icon-item' + (currentVariant() === name ? ' on' : '');
      button.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
        stroke-linecap="round" stroke-linejoin="round">${item.icon}</svg>`;
      button.title = t('editor.topbar.variant_hint', { title: item.title, about: item.about, key: item.key });
      button.onclick = () => setVariant(name);
      swap.append(button);
    }
  };
  drawSwitch();
  on('variant', drawSwitch);
  document.addEventListener('keydown', onDigit);

  // Вид карточек — одной кнопкой: значок и название текущего скина; по щелчку вниз
  // раскрывается список: значок, название и пояснение каждого. Ряд из девяти
  // значков занимал пол-шапки, а угадать скин по значку было трудно.
  // Список висит в body с position:fixed: шапка и .switch обрезают всё, что
  // выходит за их край (overflow).
  const looks = document.getElementById('look-pick');
  looks.classList.add('look-drop');
  const menu = document.createElement('div');
  menu.className = 'look-menu';
  menu.hidden = true;
  document.body.append(menu);
  const icon = (paths) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths || ''}</svg>`;
  const closeMenu = () => {
    menu.hidden = true;
    looks.querySelector('.look-trigger')?.setAttribute('aria-expanded', 'false');
  };
  const openMenu = () => {
    const box = looks.getBoundingClientRect();
    menu.style.top = Math.round(box.bottom + 6) + 'px';
    menu.style.right = Math.max(8, Math.round(window.innerWidth - box.right)) + 'px';
    menu.hidden = false;
    looks.querySelector('.look-trigger')?.setAttribute('aria-expanded', 'true');
  };
  const drawLooks = () => {
    const now = LOOKS[currentLook()] || LOOKS.work;
    looks.textContent = '';
    const trigger = document.createElement('button');
    trigger.className = 'switch-item switch-icon-item look-trigger';
    trigger.innerHTML = icon(now.icon) + `<span class="look-name">${now.title}</span><i class="look-caret" aria-hidden="true"></i>`;
    trigger.setAttribute('aria-label', t('editor.topbar.skin', { name: now.title }));
    trigger.setAttribute('aria-haspopup', 'menu');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.onclick = (event) => { event.stopPropagation(); menu.hidden ? openMenu() : closeMenu(); };
    looks.append(trigger);

    menu.textContent = '';
    for (const [name, item] of Object.entries(LOOKS)) {
      const option = document.createElement('button');
      option.className = 'look-option' + (currentLook() === name ? ' on' : '');
      option.innerHTML = icon(item.icon) + `<b>${item.title}</b><small>${item.about}</small>`;
      option.dataset.look = name;
      option.setAttribute('aria-pressed', String(currentLook() === name));
      option.onclick = () => { closeMenu(); setLook(name); };
      menu.append(option);
    }
  };
  // Щелчок мимо списка, Esc, прокрутка и смена размера окна — список закрыт.
  document.addEventListener('click', (event) => {
    if (!menu.hidden && !menu.contains(event.target) && !looks.contains(event.target)) closeMenu();
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });
  window.addEventListener('resize', closeMenu);
  /* Вид карточек имеет смысл только на холсте, но прятать его нельзя:
     от этого шапка прыгала при каждом переключении вида работы. Гасим
     на месте — кнопка остаётся, где была; в списке доступна только «Студия». */
  const showLooks = () => {
    const onCanvas = currentVariant() === 'canvas';
    looks.classList.toggle('dimmed', !onCanvas && currentLook() !== 'studio');
    for (const option of menu.querySelectorAll('.look-option')) {
      option.disabled = !onCanvas && currentLook() !== 'studio' && option.dataset.look !== 'studio';
    }
  };
  const redrawLooks = () => { drawLooks(); showLooks(); };
  redrawLooks();
  on('look', redrawLooks);
  on('variant', showLooks);

  /* Настройки: значок нужен там, где его нет. В «Классике» вкладки левой
     плашки уже несут свою шестерёнку, и вторая рядом только путает —
     прячем её, пока эта вкладка видна. Лежит вкладка в слоте всегда, в других
     скинах её скрывают стили — поэтому смотрим видимость, а не наличие. */
  const settings = document.getElementById('btn-settings');
  if (settings) {
    settings.onclick = openSettings;
    /* Смотрим на следующем кадре: вкладки переезжают в шапку позже (initLeft), а новый скин
       применяет стили после события 'look' — сразу видимость ещё прежняя. */
    const showSettings = () => requestAnimationFrame(() => {
      const tab = document.querySelector('#rail-tabs-slot [data-rail="settings"]');
      settings.hidden = !!tab && tab.getClientRects().length > 0;
    });
    showSettings();
    on('look', showSettings);
    on('variant', showSettings);
  }

  // Отмена и возврат: те же действия, что по ⌘Z и ⇧⌘Z.
  const undoButton = document.getElementById('btn-undo');
  const redoButton = document.getElementById('btn-redo');
  undoButton.onclick = () => { const what = undo(); if (what) toast(t('editor.topbar.undone', { what })); };
  redoButton.onclick = () => { const what = redo(); if (what) toast(t('editor.topbar.redone', { what })); };
  const drawHistory = () => {
    undoButton.disabled = state.viewOnly || !canUndo();
    redoButton.disabled = state.viewOnly || !canRedo();
  };
  on('history', drawHistory);
  drawHistory();

  // Настройки холста живут в меню плашки («Настройки холста»), значка в шапке
  // больше нет: там и так тесно.

  // Тема: светлая и тёмная, выбор запоминается в этом браузере.
  // Читаем так же осторожно, как пишем: в приватном окне доступ к хранилищу бросает
  // исключение, а здесь оно оборвало бы весь запуск редактора.
  let theme = null;
  try { theme = localStorage.getItem('goblin-theme'); } catch {}
  if (theme) document.documentElement.dataset.theme = theme;
  document.getElementById('btn-theme').onclick = () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('goblin-theme', next); } catch {}
  };

  // Объёмный вид — тот же, что был: схема ложится на бок, текст остаётся прямым.
  const iso = document.getElementById('btn-iso');
  iso.onclick = () => { toggleIso(); iso.classList.toggle('on', camera.iso); };

  // Режим показа: только холст.
  /* Ссылка-задание для агента: окно с выбором — прогон (role=lead) или
     рисование схемы (role=draw). По ссылке сервер сам соберёт текст задания
     со ссылками на полные инструкции. С Shift — простая ссылка на холст, для человека. */
  const linkBtn = document.getElementById('btn-link');
  const linkBox = document.getElementById('link-box');
  const site = () => location.origin + location.pathname.replace(/[^/]*$/, '');

  const showLinks = (on) => {
    linkBox.hidden = !on;
    if (!on) return;
    // Под кнопкой, но не за правым краем экрана.
    const at = linkBtn.getBoundingClientRect();
    linkBox.style.top = (at.bottom + 6) + 'px';
    linkBox.style.left = Math.max(8, Math.min(at.left, innerWidth - linkBox.offsetWidth - 8)) + 'px';
  };
  const copyTask = (role, said) => {
    navigator.clipboard?.writeText(api.taskOrder(role, state.folder?.id));
    toast(said);
    showLinks(false);
  };

  linkBtn.onclick = (event) => {
    if (event.shiftKey) {
      const plain = site() + '#p=' + api.projectKey() + (state.folder?.id ? '&f=' + state.folder.id : '');
      navigator.clipboard?.writeText(plain);
      toast(t('editor.topbar.canvas_link_copied'));
      return;
    }
    showLinks(linkBox.hidden);
  };
  // role=lead — чтобы агент по одной ссылке понял, что он leader (&worker= его сбивал).
  document.getElementById('link-run').onclick = () => copyTask('lead', t('editor.topbar.run_link_copied'));
  document.getElementById('link-draw').onclick = () => copyTask('draw', t('editor.topbar.draw_link_copied'));

  // Закрыть щелчком мимо и клавишей Esc — как любое всплывающее окно.
  document.addEventListener('pointerdown', (event) => {
    if (linkBox.hidden || event.target.closest('#link-box, #btn-link')) return;
    showLinks(false);
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') showLinks(false); });

  // «Режим показа» из шапки убран: плашки теперь прячутся своими ярлычками
  // по краям экрана (shell/sides.js).

  // Поиск по номеру и названию.
  search.oninput = () => {
    const query = search.value.trim().toLowerCase();
    drop.textContent = '';
    if (!query) { drop.hidden = true; return; }

    const found = [...state.elements.values()].filter((element) =>
      String(element.no).startsWith(query) || (element.title || '').toLowerCase().includes(query)).slice(0, 12);

    for (const element of found) {
      const item = document.createElement('button');
      item.innerHTML = `<b class="mono">${element.no}</b> ${iconOf(element)} ${escape(element.title || kindOf(element).title)}`;
      // Выбрали — на холст (из списка и иерархии тоже), выделяем и плавно подлетаем:
      // найденное оказывается в середине экрана.
      item.onclick = async () => {
        showSearch(false);
        await setVariant('canvas');
        state.selection.clear();
        state.selection.add(element.id);
        emit('selection');
        flyTo(element, fitZoom(element));
      };
      drop.append(item);
    }
    drop.hidden = !found.length;
  };
  /* Лупа открывает и закрывает окошко поиска; закрывается оно и клавишей Esc,
     и щелчком мимо — как любое всплывающее окно в Гоблине. */
  const boxNode = document.getElementById('search-box');
  const searchBtn = document.getElementById('btn-search');
  const showSearch = (on) => {
    boxNode.hidden = !on;
    if (!on) { search.value = ''; drop.hidden = true; return; }
    // Под лупой, но не за правым краем экрана: окошко fixed, как у ссылки 🔗.
    const at = searchBtn.getBoundingClientRect();
    boxNode.style.top = Math.round(at.bottom + 6) + 'px';
    boxNode.style.left = Math.round(Math.max(8, Math.min(at.left, innerWidth - boxNode.offsetWidth - 8))) + 'px';
    search.focus();
  };
  searchBtn.onclick = () => showSearch(boxNode.hidden);
  window.addEventListener('resize', () => { if (!boxNode.hidden) showSearch(false); });
  document.addEventListener('pointerdown', (event) => {
    if (boxNode.hidden) return;
    if (event.target.closest('#search-box, #btn-search')) return;
    showSearch(false);
  });

  search.onkeydown = (event) => { if (event.key === 'Escape') showSearch(false); };
  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'k') { event.preventDefault(); showSearch(true); }
  });
}

/**
 * Цифры — одна карта на весь редактор (решение 30.09.2026): на холсте 1–8 —
 * инструменты рисования (TOOLS), виды — Alt+1…4 везде, простые 1–4 переключают
 * вид только вне холста. Клавиша — по месту (event.code): Option на Mac меняет
 * сам знак, раскладка — нет. Поля ввода, открытое окно, уже обработанное
 * нажатие, Shift, Ctrl, ⌘ и AltGraph не трогаем; preventDefault — только своей
 * команде. Одно нажатие — одно действие.
 */
function onDigit(event) {
  if (event.defaultPrevented || event.repeat || event.ctrlKey || event.metaKey || event.shiftKey) return;
  if (event.getModifierState?.('AltGraph')) return;
  const target = event.target;
  if (/^(input|textarea|select)$/i.test(target.tagName || '') || target.isContentEditable) return;
  if (document.querySelector('dialog[open]')) return;
  const digit = Number((/^(?:Digit|Numpad)([1-9])$/.exec(event.code || '') || [])[1]);
  if (!digit) return;

  if (event.altKey || state.variant !== 'canvas') {
    const view = Object.keys(VARIANTS).find((name) => Number(VARIANTS[name].key) === digit);
    if (!view) return;
    event.preventDefault();
    setVariant(view);
    return;
  }
  const tool = TOOLS[digit - 1];
  if (!tool || state.viewOnly) return;
  event.preventDefault();
  state.tool = tool;
  emit('tool');
}

/** Короткое сообщение внизу: ошибка сервера или подсказка. */
let toastTimer = null;
export function toast(text, bad = false) {
  const node = document.getElementById('toast');
  node.textContent = text;
  node.classList.toggle('bad', bad);
  node.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { node.hidden = true; }, 4200);
}

function escape(text) {
  return String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
}


/* ── Пульс: кто сейчас работает ───────────────────────────────── */

/* Четыре кружка слева от «сохранено». Горит — работа идёт, мигание
   показывает, что она живая; погашенный кружок молчит. Кто именно
   работает и над чем — в подсказке при наведении. */
const PULSE = [
  ['workers', 'worker'],
  ['script',  'leader'],
  ['jev',     t('editor.pulse.jev')],
  ['ai',      t('editor.pulse.assistant')],
];

function initPulse() {
  const holder = document.getElementById('pulse');
  if (!holder) return;

  const dots = {};
  for (const [name, title] of PULSE) {
    const dot = document.createElement('span');
    dot.className = 'pulse-dot pulse-' + name;
    dot.title = t('editor.pulse.quiet', { name: title });
    holder.append(dot);
    dots[name] = dot;
  }

  const draw = (pulse) => {
    const workers = pulse.workers || [];
    set(dots.workers, workers.length > 0, workers.length
      ? t('editor.pulse.worker_busy') + '\n' + workers.map((one) =>
          t('editor.pulse.worker_line', { agent: one.agent, no: one.no, attempt: one.attempt, title: one.title, state: one.state, seconds: one.seconds })).join('\n')
      : t('editor.pulse.worker_idle'));

    const script = pulse.script || {};
    set(dots.script, !!script.live, script.live
      ? t('editor.pulse.leader_busy', { runs: script.runs || 0, title: script.title })
      : (script.runs ? t('editor.pulse.leader_silent', { seconds: script.seconds }) : t('editor.pulse.leader_idle')));

    const jev = pulse.jev || {};
    set(dots.jev, !!jev.live, jev.live ? t('editor.pulse.jev_busy', { title: jev.title })
      : (jev.title ? t('editor.pulse.jev_last', { title: jev.title }) : t('editor.pulse.jev_idle')));

    const ai = pulse.ai || {};
    set(dots.ai, !!ai.live, ai.live ? t('editor.pulse.ai_busy') : t('editor.pulse.ai_idle'));

    // Где идёт прогон — левая плашка подсвечивает значок папки и проекта.
    const folders = (pulse.runFolders || []).map(Number).sort().join(',');
    const projects = (pulse.runProjects || []).map(String).sort().join(',');
    if (folders !== [...state.runFolders].sort().join(',')
        || projects !== [...state.runProjects].sort().join(',')) {
      state.runFolders = new Set(folders ? folders.split(',').map(Number) : []);
      state.runProjects = new Set(projects ? projects.split(',') : []);
      emit('live-runs');
    }
  };

  const set = (dot, live, title) => {
    dot.classList.toggle('on', live);
    dot.title = title;
  };

  const ask = async () => {
    if (document.hidden || !state.project) return;
    const pulse = await api.quietGet('pulse');
    if (pulse) draw(pulse);
  };
  ask();
  setInterval(ask, 3000);
}

/**
 * Значок входа — всегда первым слева. В скинах с вкладками плашки в шапке — в зоне над плашкой;
 * в остальных плашка встаёт на всю высоту и закрывает этот угол — значок встаёт первым сразу за ней.
 */
function placeAccount() {
  const button = document.getElementById('btn-account');
  if (!button) return;
  if (document.body.dataset.left === 'tabs') document.querySelector('.topbar-rail')?.prepend(button);
  else document.querySelector('.topbar-split-rail')?.after(button);
}

/** Значок входа: вошёл — «Мой кабинет» (подсвечен), нет — «Войти». Название — в подсказке; ведут оба в кабинет. */
function drawAccount() {
  const button = document.getElementById('btn-account');
  if (!button) return;
  const word = t(state.me ? 'editor.account.mine' : 'editor.account.login');
  button.classList.toggle('in', !!state.me);
  button.title = state.me?.name ? `${word} · ${state.me.name}` : word;
  button.setAttribute('aria-label', word);
}
