/* Левая плашка: общие мелочи разделов.
   Что делает: значки папки и проекта, порядок списков (по имени / по дате),
   шапка списка плитками, создание узлов и экранирование текста.
   Отдаёт: FOLDER_ICON, PROJECT_ICON, sortMode(), byName(), listHead(), make(), escape().
   Не делает: не рисует сами списки и ничего не грузит. */

import { t, lang } from 'goblin/core/i18n.js';

/* Значок папки рисунком: символы из шрифта на разных машинах выглядят по-разному.
   Он же — папка в виде «Иерархия», чтобы рисунок был один на весь Гоблин. */
export const FOLDER_ICON = '<svg viewBox="0 0 24 20" width="22" height="18" fill="none" stroke="currentColor" stroke-width="1.6">'
  + '<path d="M2 5a2 2 0 0 1 2-2h5l2 2h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/></svg>';

/* Проект — те же папки, но стопкой: уровень выше, и это видно с первого взгляда. */
export const PROJECT_ICON = '<svg viewBox="0 0 24 20" width="22" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
  + '<path d="M5 2.5h4l2 2h6a2 2 0 0 1 2 2V8"/>'
  + '<path d="M2 8a2 2 0 0 1 2-2h5l2 2h8a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/></svg>';

/* Порядок списка. «По дате» — тот, в котором список приходит: у папок это
   их собственный порядок, у проектов — когда их последний раз трогали.
   Выбор помнится для папок и проектов отдельно. */
const SORT_STORE = (who) => 'goblin-sort-' + who;

const SORTS = [
  ['name', t('editor.sort.by_name'), '<path d="M4 7h9M4 12h6M4 17h3M17 5v14M17 19l-2.5-2.5M17 19l2.5-2.5"/>'],
  ['date', t('editor.sort.by_date'), '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8"/>'],
];

export function sortMode(who) {
  try {
    return localStorage.getItem(SORT_STORE(who)) === 'name' ? 'name' : 'date';
  } catch {
    return 'date';
  }
}

export function byName(a, b) {
  // Алфавит — языка интерфейса: у английского свой порядок букв.
  return String(a || '').localeCompare(String(b || ''), lang, { numeric: true, sensitivity: 'base' });
}

/** Два значка порядка: выбранный горит, щелчок перерисовывает список. */
function sortRow(who, redraw) {
  const row = make('div', 'tile-sort');
  row.dataset.sortFor = who;
  const mark = () => {
    for (const button of row.children) button.classList.toggle('on', button.dataset.sort === sortMode(who));
  };

  for (const [mode, title, path] of SORTS) {
    const button = make('button', 'icon-btn tile-sort-btn',
      '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6"'
      + ` stroke-linecap="round" stroke-linejoin="round">${path}</svg>`);
    button.dataset.sort = mode;
    button.title = title;
    button.onclick = () => {
      try { localStorage.setItem(SORT_STORE(who), mode); } catch {}
      mark();
      redraw();
    };
    row.append(button);
  }
  mark();
  return row;
}

/** Шапка списка плитками: слева выход или надпись, черта, справа порядок. */
export function listHead(first, who, redraw) {
  const head = make('div', 'rail-head');
  head.append(first, make('span', 'rail-head-sep'), sortRow(who, redraw));
  return head;
}

/** Узел с классом и разметкой. */
export function make(tag, className = '', html = '') {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (html) node.innerHTML = html;
  return node;
}

export function escape(text) {
  return String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
}
