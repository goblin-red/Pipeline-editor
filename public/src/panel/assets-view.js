/* Материалы в панели: список строками или превьюшками.
   Отдаёт: assetsSection(), assetGallery(), dropZone(), reloadElement(), folderGroups(), assetsTitle(),
           groupOpen(), toggleGroup().
   Не делает: не открывает окна сам — это shell/assetview.js.

   Переключатель «превью / список» — как было в прежнем Гоблине. Выбор живёт
   в настройках, поэтому держится и после обновления страницы и одинаков
   везде: и у материалов элемента, и в библиотеке проекта.

   Владельца может не быть: библиотека проекта показывает те же строки и
   превьюшки, только откреплять и назначать заглавной там нечего. */

import { putFile } from 'goblin/shell/localdir.js';
import { state, emit, setScheme } from 'goblin/core/state.js';
import { settings, setSetting } from 'goblin/core/settings.js';
import * as api from 'goblin/api/client.js';
import { openAsset, openWorkfile, deleteWorkfile, attachFile, attachLink, assetUrl,
         isImage, isVideo, assetIcon, setCover, isCover } from 'goblin/shell/assetview.js';
import { toast } from 'goblin/shell/topbar.js';
import { el, wrap, note, small, escape, escapeAttr } from 'goblin/panel/parts.js';
import { openFolderAssets } from 'goblin/panel/asset-pick.js';
import { ASSET_DATA, WORKFILE_DATA } from 'goblin/edit/place.js';
import { t } from 'goblin/core/i18n.js';

/** Вся секция «Материалы» целиком. */
export function assetsSection(element) {
  // ТЗ в этот список не попадает: оно живёт своей секцией «MD / тех.задание».
  const list = (element.assets || []).filter((asset) => asset.role !== 'spec');
  const holder = settings.assetsView === 'list' ? assetRows(list, element) : assetTiles(list, element);
  // ТЗ отсюда ушло в свою секцию «MD / тех.задание»: это задание, а не вложение.
  const add = el('div', 'row');
  add.append(
    small(t('editor.assetsview.file'), () => attachFile(element)),
    small(t('editor.assetsview.link'), () => attachLink(element)),
    // Материал уже лежит в папке — его не нужно заводить заново.
    small(t('editor.assetsview.storage'), () => openFolderAssets(element)),
  );
  // Поле для перетаскивания — сверху: это первое, что делают с материалами.
  return wrap(assetsTitle(element), [viewSwitch(), dropZone(element), holder, add], false, list.length);
}

/** Заголовок секции: чьи это материалы — «Материалы блока», «…группы», «…таблицы». */
export function assetsTitle(element) {
  const titles = { block: t('editor.assetsview.title_block'), group: t('editor.assetsview.title_group'), table: t('editor.assetsview.title_table'), area: t('editor.assetsview.title_area') };
  return titles[element?.type] || t('editor.mobile.tab_assets');
}

/** Разделы материалов проекта: папки в порядке дерева, в конце — «без папки». */
export function folderGroups() {
  const groups = [];
  const walk = (parent) => state.folders
    .filter((one) => (one.parent || null) === parent)
    .sort((a, b) => a.sort - b.sort || a.id - b.id)
    .forEach((one) => {
      groups.push({ key: 'f' + one.id, title: one.name || t('editor.folders.unnamed'), has: (asset) => (asset.folders || []).includes(one.id) });
      walk(one.id);
    });
  walk(null);
  groups.push({ key: 'none', title: t('editor.assetsview.no_folder'), has: (asset) => !(asset.folders || []).length });
  return groups;
}

/* Разделы материалов проекта: по умолчанию открыт только раздел текущей папки.
   Что человек раскрыл или свернул сам — помним, пока открыта та же папка. */
const groupChoice = new Map();    // ключ раздела → true (открыт) / false (свёрнут)
let choiceFolder = null;

export function groupOpen(key) {
  const here = state.folder?.id ?? null;
  if (choiceFolder !== here) { groupChoice.clear(); choiceFolder = here; }
  return groupChoice.has(key) ? groupChoice.get(key) : key === 'f' + here;
}

export function toggleGroup(key) {
  groupChoice.set(key, !groupOpen(key));
}

const galleryStates = new Map();
let clearGallerySelection = null;
document.addEventListener('pointerdown', (event) => clearGallerySelection?.(event));

/** Материалы папки или проекта: сортировка и выбор в текущем списке.
    groups — разделы [{title, has(asset)}]: материалы проекта по папкам. */
export function assetGallery(list, redraw, folder = null, groups = null) {
  const project = api.projectKey();
  const key = `${project}:${folder || 'project'}`;
  if (!galleryStates.has(key)) galleryStates.set(key, { sort: 'date', reverse: false, selected: new Set() });
  const choice = galleryStates.get(key);
  let ordered = [];
  const ids = new Set(list.map((asset) => asset.id));
  for (const id of choice.selected) if (!ids.has(id)) choice.selected.delete(id);
  const holder = el('div');
  const controls = el('div', 'row asset-sort');
  const sort = el('div', 'switch-small asset-sort-icons');
  sort.setAttribute('role', 'group');
  sort.setAttribute('aria-label', t('editor.assetsview.sort_label'));
  for (const [value, label, icon] of [
    ['name', t('editor.sort.by_name'), `<text x="3" y="17" fill="currentColor" stroke="none" font-size="15" font-family="sans-serif">${t('editor.assetsview.az')}</text>`],
    ['date', t('editor.assetsview.by_added'), '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4m8-4v4M4 11h16M8 15h3m2 0h3m-8 3h3"/>'],
    ['kind', t('editor.assetsview.by_kind'), '<rect x="3" y="3" width="7" height="7" rx="1"/><circle cx="17" cy="6.5" r="3.5"/><path d="m6.5 14 4 7h-8z"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
  ]) {
    const button = el('button', 'switch-item');
    button.dataset.sort = value;
    button.title = label;
    button.setAttribute('aria-label', label);
    button.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icon}</svg>`;
    button.onclick = () => { choice.sort = value; choice.reverse = false; draw(); };
    sort.append(button);
  }
  const direction = el('button', 'btn btn-quiet');
  const content = el('div');
  const actions = el('div', 'row asset-selection');
  const allLabel = el('label', 'row');
  const all = el('input');
  all.type = 'checkbox';
  allLabel.append(all, document.createTextNode(t('editor.assetsview.select_all')));
  const remove = el('button', 'btn btn-quiet btn-danger');
  remove.title = t('editor.assetsview.remove_title');
  actions.append(allLabel, remove);
  const updateSelection = () => {
    all.checked = list.length > 0 && choice.selected.size === list.length;
    all.indeterminate = choice.selected.size > 0 && choice.selected.size < list.length;
    all.disabled = !list.length;
    remove.disabled = !choice.selected.size;
    remove.textContent = t('editor.assetsview.remove_n', { n: choice.selected.size });
    for (const node of content.querySelectorAll('[data-asset-select]')) {
      const picked = choice.selected.has(Number(node.dataset.assetSelect));
      node.classList.toggle('picked', picked);
      node.setAttribute('aria-selected', String(picked));
    }
  };
  const selectable = folder && !state.viewOnly ? (asset, node) => {
    node.dataset.assetSelect = asset.id;
    node.classList.add('asset-pickable');
    node.onclick = (event) => {
      const multiple = event.ctrlKey || event.metaKey;
      const anchor = ordered.findIndex((item) => item.id === choice.anchor);
      const current = ordered.findIndex((item) => item.id === asset.id);
      if (event.shiftKey && anchor >= 0) {
        if (!multiple) choice.selected.clear();
        for (const item of ordered.slice(Math.min(anchor, current), Math.max(anchor, current) + 1)) {
          choice.selected.add(item.id);
        }
      } else {
        if (choice.selected.has(asset.id)) {
          choice.selected.delete(asset.id);
        } else {
          if (!multiple) choice.selected.clear();
          choice.selected.add(asset.id);
        }
        choice.anchor = asset.id;
      }
      updateSelection();
    };
  } : null;
  clearGallerySelection = selectable ? (event) => {
    if (!holder.isConnected || !choice.selected.size) return;
    if (event.target.closest('.asset-pickable,.asset-board,button,input,select,textarea,label,a,dialog')) return;
    choice.selected.clear();
    choice.anchor = null;
    updateSelection();
  } : null;
  const draw = () => {
    for (const button of sort.children) {
      const active = button.dataset.sort === choice.sort;
      button.classList.toggle('on', active);
      button.setAttribute('aria-pressed', String(active));
    }
    const compare = (a, b) => String(a || '').localeCompare(String(b || ''), 'ru', { numeric: true, sensitivity: 'base' });
    ordered = [...list].sort((a, b) => {
      let result = choice.sort === 'date' ? compare(b.createdAt, a.createdAt)
        : choice.sort === 'kind' ? compare(a.kind, b.kind) : compare(a.title || a.name, b.title || b.name);
      result ||= compare(a.title || a.name, b.title || b.name) || a.id - b.id;
      return choice.reverse ? -result : result;
    });
    direction.textContent = choice.reverse ? '↑' : '↓';
    direction.title = choice.sort === 'date'
      ? (choice.reverse ? t('editor.assetsview.old_first') : t('editor.assetsview.new_first'))
      : (choice.reverse ? t('editor.assetsview.desc') : t('editor.assetsview.asc'));
    const view = (items) => (settings.assetsView === 'list'
      ? assetRows(items, null, selectable) : assetTiles(items, null, selectable));
    if (groups) {
      // Раздел на папку: заголовок и её материалы; пустые разделы не показываем.
      // Щелчок по заголовку сворачивает и разворачивает раздел.
      const parts = [];
      for (const group of groups) {
        const items = ordered.filter(group.has);
        if (!items.length) continue;
        const shut = !groupOpen(group.key);
        const head = el('button', 'asset-group-title' + (shut ? ' shut' : ''));
        head.innerHTML = `<span></span><b>${shut ? '▸' : '▾'}</b>`;
        head.querySelector('span').textContent = `${group.title} · ${items.length}`;
        head.onclick = () => { toggleGroup(group.key); draw(); };
        parts.push(head);
        if (!shut) parts.push(view(items));
      }
      content.replaceChildren(...(parts.length ? parts : [view([])]));
    } else {
      content.replaceChildren(view(ordered));
    }
    updateSelection();
  };
  direction.onclick = () => { choice.reverse = !choice.reverse; draw(); };
  all.onchange = () => {
    choice.selected.clear();
    if (all.checked) for (const asset of list) choice.selected.add(asset.id);
    draw();
  };
  remove.onclick = async () => {
    const selected = [...choice.selected];
    if (!selected.length) return;
    remove.disabled = all.disabled = true;
    try {
      await api.batch(selected.map((id) => ({ op: 'asset.unlink', asset: id, folder })), { project });
      choice.selected.clear();
      if (api.projectKey() !== project) return;
      if (state.folder?.id === folder) {
        const answer = await api.get('folder.get', { folder, project });
        if (api.projectKey() === project && state.folder?.id === folder) setScheme(answer.scheme);
      }
      toast(t('editor.assetsview.removed'));
      redraw();
    } catch { updateSelection(); }
  };
  controls.append(sort, direction, viewSwitch(redraw));
  holder.append(dropZone(null, redraw, folder), controls);
  if (selectable) {
    holder.append(actions);
    content.classList.add('asset-board');
    marquee(content, choice, updateSelection);
  }
  holder.append(content);
  draw();
  return holder;
}

/**
 * Рамка выбора — как у агентов: тянут по пустому месту подложки, выбираются
 * материалы, которых рамка коснулась. С Shift, ⌘ или Ctrl — к уже выбранным.
 * Щелчок по пустому месту без протяжки снимает выбор. По материалу — не рамка:
 * его тянут на холст.
 */
function marquee(board, choice, done) {
  board.addEventListener('pointerdown', (event) => {
    if (event.button !== 0 || event.target.closest('.asset-pickable')) return;
    const from = { x: event.clientX, y: event.clientY };
    const add = event.shiftKey || event.metaKey || event.ctrlKey;
    const before = new Set(add ? choice.selected : []);
    let frame = null;

    const move = (now) => {
      if (!frame && Math.abs(now.clientX - from.x) + Math.abs(now.clientY - from.y) <= 4) return;
      if (!frame) {
        frame = el('div', 'asset-marquee');
        document.body.append(frame);
      }
      const box = {
        left: Math.min(from.x, now.clientX), top: Math.min(from.y, now.clientY),
        right: Math.max(from.x, now.clientX), bottom: Math.max(from.y, now.clientY),
      };
      Object.assign(frame.style, {
        left: box.left + 'px', top: box.top + 'px',
        width: (box.right - box.left) + 'px', height: (box.bottom - box.top) + 'px',
      });
      choice.selected.clear();
      for (const id of before) choice.selected.add(id);
      for (const node of board.querySelectorAll('[data-asset-select]')) {
        const r = node.getBoundingClientRect();
        const hit = !(r.right < box.left || r.left > box.right || r.bottom < box.top || r.top > box.bottom);
        if (hit) choice.selected.add(Number(node.dataset.assetSelect));
      }
      done();
    };
    const up = () => {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      if (frame) { frame.remove(); return; }
      if (!add && choice.selected.size) {
        choice.selected.clear();
        choice.anchor = null;
        done();
      }
    };
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
  });
}

/**
 * Поле «перетащи данные сюда»: файлы, картинки и ссылки прямо из системы
 * или из браузера. Есть владелец — материал сразу к нему и прикрепится;
 * нет владельца (библиотека проекта) — просто ляжет в проект.
 */
export function dropZone(element, done = null, folder = null) {
  const zone = el('div', 'drop-zone');
  zone.innerHTML = `<span>${t('editor.assetsview.drop')}</span>`
    + `<small>${t('editor.assetsview.drop_hint')}</small>`;
  if (state.viewOnly) { zone.classList.add('off'); return zone; }

  // «Обзор» — те же файлы, только выбранные в окне системы, а не перетащенные.
  const browse = el('button', 'btn btn-small drop-browse', t('editor.assetsview.browse'));
  browse.onclick = async () => {
    const files = await pickFiles();
    if (files.length) await takeDrop(files, '', element, done, folder);
  };
  zone.append(browse);

  const off = () => zone.classList.remove('over');
  zone.addEventListener('dragover', (event) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
    zone.classList.add('over');
  });
  zone.addEventListener('dragleave', off);
  zone.addEventListener('drop', async (event) => {
    event.preventDefault();
    off();
    const data = event.dataTransfer;
    const text = (data?.getData('text/uri-list') || data?.getData('text/plain') || '').trim();
    await takeDrop([...(data?.files || [])], text, element, done, folder);
  });
  return zone;
}

/** Окно выбора файлов системы: можно несколько; отмена — пустой список. */
function pickFiles() {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.onchange = () => resolve([...(input.files || [])]);
    input.addEventListener('cancel', () => resolve([]));
    input.click();
  });
}

/** Что пришло в поле: сперва файлы, иначе строка — ссылка или путь. */
async function takeDrop(files, text, element, done, folder) {
  const link = element ? { element: element.id, role: 'attachment' }
    : folder ? { folder, role: 'attachment' } : null;

  try {
    if (files.length) {
      for (const file of files) await putFile(file, link, folder ?? state.folder?.id);
      toast(files.length === 1 ? t('editor.asset.file_attached') : t('editor.assetsview.files_attached', { n: files.length }));
    } else if (text) {
      const line = text.split(/\s+/)[0];
      await api.post('asset.create', {
        kind: /^https?:/i.test(line) ? 'link' : 'file',
        title: line.split('/').pop() || line,
        uri: line,
        ...(link ? { link } : {}),
      });
      toast(t('editor.asset.attached'));
    } else {
      toast(t('editor.assetsview.unknown'), true);
      return;
    }
  } catch {
    return;     // причину уже сказал слой API
  }

  if (element) await reloadElement(element.id);
  if (done) done();
}

/** Переключатель вида. По умолчанию перерисовку просим событием — панель
    сама себя соберёт; библиотека проекта передаёт свою перерисовку. */
export function viewSwitch(redraw = () => emit('panel')) {
  // Значками, как сортировка: плитки — превью, строки — список.
  const row = el('div', 'switch-small asset-sort-icons asset-view-icons');
  for (const [value, word, icon] of [
    ['tiles', t('editor.assetsview.preview'), '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>'],
    ['list', t('editor.variants.list'), '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>'],
  ]) {
    const button = el('button', 'switch-item' + (settings.assetsView === value ? ' on' : ''));
    button.title = word;
    button.setAttribute('aria-label', word);
    button.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icon}</svg>`;
    button.onclick = () => { setSetting('assetsView', value); redraw(); };
    row.append(button);
  }
  return row;
}

function assetRows(list, element, selectable = null) {
  const holder = el('div', 'asset-list');
  if (!list.length) { holder.append(note(t('editor.assetsview.empty'))); return holder; }
  for (const asset of list) {
    const row = el('div', 'asset asset-open');
    // Длинные ссылки подрезаем: целиком они разносят строку на пять этажей.
    row.innerHTML = `<span>${assetIcon(asset.kind)}</span>`
      + `<span class="asset-name">${escape(shortName(asset, 44))}</span>`
      + `<span class="role">${escape(asset.role || asset.kind || '')}</span>`;
    // Подсказку не вешаем: имя написано в самой строке, а всплывашка закрывала
    // соседние материалы.
    // Окно материала открывается двойным щелчком или значком: одиночный щелчок
    // по плитке слишком легко задеть, разбирая материалы.
    row.ondblclick = () => openAsset(asset, element, list);
    draggableAsset(row, asset);
    row.append(gearButton(asset, element, list));
    if (selectable) selectable(asset, row);
    if (element) {
      const star = coverButton(asset, element);
      if (star) row.append(star);
      row.append(unlinkButton(asset, element));
    }
    holder.append(row);
  }
  return holder;
}

function assetTiles(list, element, selectable = null) {
  const holder = el('div', 'asset-tiles');
  if (!list.length) { holder.append(note(t('editor.assetsview.empty'))); return holder; }
  for (const asset of list) {
    const tile = el('button', 'asset-tile');
    const url = assetUrl(asset);
    const face = asset.missing ? `<i title="${t('editor.assetsview.missing')}">⚠</i>`
      : isImage(asset) && url
      ? `<img src="${escapeAttr(url)}" alt="" loading="lazy">`
      : `<i>${isVideo(asset) ? '🎬' : assetIcon(asset.kind)}</i>`;
    // Файл у исполнителя (веб) и папка схемы не подключена — имя и подпись вместо картинки.
    const away = asset.agent && !url ? `<small class="muted">${escape(t('editor.asset.at_agent_short'))}</small>` : '';
    tile.innerHTML = `<span class="asset-face">${face}</span>
                      <small>${escape(shortName(asset))}</small>${away}`;
    tile.ondblclick = () => openAsset(asset, element, list);
    tile.querySelector('img')?.setAttribute('draggable', 'false');
    draggableAsset(tile, asset);

    const wrapper = el('div', 'asset-tile-wrap');
    if (isCover(asset)) wrapper.classList.add('is-cover');
    wrapper.append(tile, gearButton(asset, element, list));
    if (selectable) selectable(asset, wrapper);
    if (element) {
      wrapper.append(unlinkButton(asset, element));
      const star = coverButton(asset, element);
      if (star) wrapper.append(star);
    }
    holder.append(wrapper);
  }
  return holder;
}

/**
 * Материал тянут на холст и отпускают на блоке — он прикрепляется к нему
 * (edit/place.js). Номер материала: у материала блока — `asset`, у материала
 * папки — `id`.
 */
function draggableAsset(node, asset) {
  const id = asset.asset ?? asset.id;
  if (state.viewOnly || !id) return;
  node.draggable = true;
  node.addEventListener('dragstart', (event) => {
    event.dataTransfer.setData(ASSET_DATA, String(id));
    event.dataTransfer.setData('text/plain', asset.title || String(id));
    event.dataTransfer.effectAllowed = 'copy';
    document.body.classList.add('dragging-asset');
  });
  node.addEventListener('dragend', () => document.body.classList.remove('dragging-asset'));
}

/**
 * Файлы рабочей папки плитками или строками — как материалы. Двойной щелчок
 * открывает окно свойств файла, перенос на блок делает его материалом блока.
 * files — ответ asset.workfiles (sub=in или out), root — сама рабочая папка; подпись — путь внутри in/ или out/.
 * onChange — перерисовать список после переименования или удаления файла.
 */
export function workFiles(files, root, folderId, onChange = null) {
  const rel = (file) => file.path.slice(root.length + 1);
  const url = (file) => `api.php?op=asset.workfile&project=${encodeURIComponent(api.projectKey())}`
    + `&folder=${folderId}&path=${encodeURIComponent(rel(file))}`;
  const open = (file) => openWorkfile(file, rel(file), url(file), folderId, onChange);
  // Значки как у материалов блока: свойства и удалить.
  const buttons = (node, file) => {
    const gear = el('button', 'asset-gear', '⚙');
    gear.title = t('editor.assetsview.file_props');
    gear.onclick = (event) => { event.stopPropagation(); open(file); };
    node.append(gear);
    if (state.viewOnly) return;
    const off = el('button', 'asset-off', '⊗');
    off.title = t('editor.assetsview.file_delete');
    off.onclick = async (event) => {
      event.stopPropagation();
      if (await deleteWorkfile(file, rel(file), folderId)) onChange?.();
    };
    node.append(off);
  };
  const drag = (node, file) => {
    if (state.viewOnly) return;
    node.draggable = true;
    node.addEventListener('dragstart', (event) => {
      event.dataTransfer.setData(WORKFILE_DATA, JSON.stringify({ path: file.path, name: file.name, kind: file.kind }));
      event.dataTransfer.setData('text/plain', file.name);
      event.dataTransfer.effectAllowed = 'copy';
      document.body.classList.add('dragging-asset');
    });
    node.addEventListener('dragend', () => document.body.classList.remove('dragging-asset'));
  };

  if (settings.assetsView === 'list') {
    const holder = el('div', 'asset-list');
    for (const file of files) {
      const row = el('div', 'asset asset-open');
      row.innerHTML = `<span>${assetIcon(file.kind)}</span><span class="asset-name"></span><span class="role"></span>`;
      row.querySelector('.asset-name').textContent = file.path.slice(root.length + 1).replace(/^(in|out)\//, '');
      row.querySelector('.role').textContent = fileSize(file.bytes);
      row.ondblclick = () => open(file);
      drag(row, file);
      buttons(row, file);
      holder.append(row);
    }
    return holder;
  }
  const holder = el('div', 'asset-tiles');
  for (const file of files) {
    const tile = el('button', 'asset-tile');
    const face = file.kind === 'image'
      ? `<img src="${escapeAttr(url(file))}" alt="" loading="lazy" draggable="false">`
      : `<i>${assetIcon(file.kind)}</i>`;
    tile.innerHTML = `<span class="asset-face">${face}</span><small></small>`;
    tile.querySelector('small').textContent = shortName({ title: file.name });
    tile.ondblclick = () => open(file);
    drag(tile, file);
    const wrapper = el('div', 'asset-tile-wrap');
    wrapper.append(tile);
    buttons(wrapper, file);
    holder.append(wrapper);
  }
  return holder;
}

function fileSize(bytes) {
  if (bytes < 1024) return t('editor.runlog.bytes', { n: bytes });
  if (bytes < 1048576) return t('editor.runlog.kbytes', { n: Math.round(bytes / 1024) });
  return t('editor.runlog.mbytes', { n: (bytes / 1048576).toFixed(1) });
}

/** Значок свойств: то же окно, что и по двойному щелчку. */
function gearButton(asset, element, list) {
  const gear = el('button', 'asset-gear', '⚙');
  gear.title = t('editor.assetsview.asset_props');
  gear.onclick = (event) => {
    event.stopPropagation();
    openAsset(asset, element, list);
  };
  return gear;
}

/** Звёздочка «заглавная картинка блока». Только у картинок и только одна горит. */
function coverButton(asset, element) {
  if (!isImage(asset) || asset.role === 'spec') return null;
  const on = isCover(asset);
  const star = el('button', 'asset-star' + (on ? ' on' : ''), on ? '★' : '☆');
  star.title = on
    ? t('editor.assetsview.cover_off')
    : t('editor.assetsview.cover_on');
  star.disabled = state.viewOnly;
  star.onclick = (event) => {
    event.stopPropagation();
    setCover(asset, element, !on);
  };
  return star;
}

function unlinkButton(asset, element) {
  const off = el('button', 'asset-off', '⊗');
  off.title = t('editor.assetsview.detach');
  off.onclick = (event) => {
    event.stopPropagation();
    api.post('asset.unlink', { id: asset.link }).then(() => reloadElement(element.id));
  };
  return off;
}

function shortName(asset, max = 18) {
  const name = asset.title || asset.name || asset.uri || asset.kind || '';
  return name.length > max ? name.slice(0, max - 1) + '…' : name;
}

/** Перечитать элемент целиком: связей мало, запрос дешёвый. */
export async function reloadElement(id) {
  const answer = await api.get('element.get', { element: id });
  const element = answer.element;
  element.assets = element.assets || [];
  state.elements.set(element.id, Object.assign(state.elements.get(element.id) || {}, element));
  emit('element', element);
}
