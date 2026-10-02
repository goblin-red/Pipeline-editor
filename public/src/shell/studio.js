/* Только оформление «Студии»: контурные значки существующих кнопок.
   Обработчики, данные схемы и размеры элементов остаются у общего редактора. */
import { on } from 'goblin/core/state.js';
import { currentLook, setLook } from 'goblin/canvas/looks.js';
import { t } from 'goblin/core/i18n.js';

const paths = {
  block: '<rect x="3" y="3" width="18" height="18" rx="3"/>',
  decision: '<path d="m12 3 9 9-9 9-9-9z"/>',
  gateway: '<path d="m12 2 9 5v10l-9 5-9-5V7z"/>',
  group: '<path d="m12 3 10 5-10 5L2 8zM2 12l10 5 10-5M2 16l10 5 10-5"/>',
  area: '<rect x="3" y="3" width="18" height="18" rx="3" stroke-dasharray="3 4"/>',
  note: '<path d="M14 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V10zM14 3v7h7"/>',
  table: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M11 3v18"/>',
  combine: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><path d="M14 3h7v7M3 14v7h7M7 14l4 4-4 4M3 18h8"/>',
  ungroup: '<rect x="3" y="3" width="10" height="7" rx="2"/><rect x="11" y="14" width="10" height="7" rx="2"/>',
  trash: '<path d="M3 6h18M8 6V3h8v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
  layers: '<path d="m12 3 10 5-10 5L2 8zM2 12l10 5 10-5M2 16l10 5 10-5"/>',
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h6"/>',
  clip: '<path d="m20 11-8 8a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l8-8"/>',
  plus: '<path d="M5 12h14M12 5v14"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
  edit: '<path d="m15 4 5 5M4 20l1-5L16 4a3 3 0 0 1 4 4L9 19z"/>',
  folder: '<path d="M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
};

function svg(name) {
  return `<svg class="studio-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name]}</svg>`;
}

// Сохраняем исходный значок рядом: переключение обратно — только CSS.
function dress(node, name) {
  if (!node || node.querySelector('.studio-icon')) return;
  const original = document.createElement('span');
  original.className = 'studio-original';
  original.append(...node.childNodes);
  node.append(original);
  node.insertAdjacentHTML('beforeend', svg(name));
}

function decorate() {
  // Без явного summary браузер вставляет собственное «Сведения».
  // У первичных полей макета заголовка нет; остальные секции сворачиваются как раньше.
  for (const section of document.querySelectorAll('.section:not(:has(> summary))')) {
    const summary = document.createElement('summary');
    summary.className = 'studio-empty-summary';
    summary.hidden = true;
    section.prepend(summary);
  }
  for (const node of document.querySelectorAll('#tool-grid [data-type]')) {
    if (paths[node.dataset.type]) dress(node.querySelector('b'), node.dataset.type);
  }
  dress(document.querySelector('#cl-group b'), 'combine');
  dress(document.querySelector('#cl-ungroup b'), 'ungroup');
  dress(document.getElementById('btn-delete'), 'trash');
  dress(document.getElementById('btn-iso'), 'layers');
  dress(document.querySelector('#cl-folder-add > span'), 'plus');
  dress(document.querySelector('#cl-project-add > span'), 'plus');

  // Нативные кнопки в панелях и окнах уже содержат свои действия.
  // Заменяем только декоративный префикс, никогда пользовательский текст.
  const symbols = new Map([['📄', 'file'], ['📎', 'clip'], ['🖼', 'image'], ['✎', 'edit'], ['📁', 'folder']]);
  for (const button of document.querySelectorAll('.panel button, .dialog button, .screen button')) {
    if (button.querySelector('.studio-icon')) continue;
    const text = button.firstChild;
    if (text?.nodeType !== Node.TEXT_NODE) continue;
    const entry = [...symbols].find(([prefix]) => text.textContent.trimStart().startsWith(prefix));
    if (!entry) continue;
    const match = text.textContent.match(/^\s*(?:📄|📎|🖼|✎|📁)\uFE0F?\s*/u);
    if (!match) continue;
    const original = document.createElement('span');
    original.className = 'studio-original';
    original.textContent = match[0];
    text.textContent = text.textContent.slice(match[0].length);
    button.insertBefore(original, text);
    original.insertAdjacentHTML('afterend', svg(entry[1]));
  }
}

export function initStudio() {
  const theme = document.getElementById('btn-theme');
  const themeTitle = theme.title;
  let frame = 0;
  const observer = new MutationObserver(() => {
    if (frame) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      observer.disconnect();
      if (currentLook() !== 'studio') return;
      decorate();
      observe();
    });
  });
  const observe = () => {
    // Холст не наблюдаем: карточки обновляются часто, значки там собственные.
    for (const node of document.querySelectorAll('#rail, #panel, .dialog, .screen')) {
      observer.observe(node, { childList: true, subtree: true });
    }
  };
  const apply = () => {
    observer.disconnect();
    cancelAnimationFrame(frame);
    frame = 0;
    const active = currentLook() === 'studio';
    theme.disabled = active;
    theme.title = active ? t('editor.studio.dark') : themeTitle;
    if (active) { decorate(); observe(); }
    else document.querySelectorAll('.studio-empty-summary').forEach(node => node.remove());
  };
  on('look', apply);
  // Список/иерархия не монтируют холст, а значит не вызывают его setLook.
  // Скин должен восстановиться и при прямом открытии этих видов.
  if (currentLook() === 'studio') setLook('studio');
  else apply();
}
