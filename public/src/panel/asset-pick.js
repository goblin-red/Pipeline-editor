/* Окно «из хранилища»: взять файл из in/ рабочей папки и прикрепить его к блок-схеме.
   Отдаёт: openFolderAssets().
   Не делает: новых файлов не заводит — для них есть «файл», «ссылка»
   и поле для перетаскивания.

   Две вкладки, как в панели материалов: «Материалы папки» — in/ открытой папки,
   «Материалы проекта» — in/ всех папок схемы разделами (открыт раздел текущей).
   Выбор галочками, выбранное переживает смену вкладки; что уже висит на этой
   блок-схеме — приглушено. Прикрепить — запись материала на файл (есть — та же). */

import { state, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { el, note, escape, escapeAttr } from 'goblin/panel/parts.js';
import { assetIcon } from 'goblin/shell/assetview.js';
import { reloadElement, groupOpen, toggleGroup } from 'goblin/panel/assets-view.js';
import { toast } from 'goblin/shell/topbar.js';
import { t } from 'goblin/core/i18n.js';

const SCOPES = [['folder', t('editor.pick.folder_assets')], ['project', t('editor.pick.project_assets')]];

/** Папки схемы с рабочей папкой — в порядке дерева. */
function workFolders() {
  const out = [];
  const walk = (parent) => state.folders
    .filter((one) => (one.parent || null) === parent)
    .sort((a, b) => a.sort - b.sort || a.id - b.id)
    .forEach((one) => { if (one.workDir) out.push(one); walk(one.id); });
  walk(null);
  return out;
}

/** Файлы из in/ рабочей папки: { root, files }. */
async function inFiles(folder) {
  const answer = await api.get('asset.workfiles', { folder: folder.id, sub: 'in' }).catch(() => null);
  return { root: String(answer?.dir || folder.workDir).replace(/\/$/, ''), files: answer?.files || [] };
}

export async function openFolderAssets(element) {
  const dialog = document.getElementById('dialog-pick-asset');
  const title = document.getElementById('dialog-pick-asset-title');
  const tabs = document.getElementById('dialog-pick-asset-scope');
  const body = document.getElementById('dialog-pick-asset-body');
  const add = document.getElementById('btn-pick-asset');
  if (!dialog) return;

  const chosen = new Map();            // путь файла → файл
  // Что уже на блок-схеме — только помечаем: второй раз крепить незачем.
  const mine = new Set((element.assets || []).map((asset) => asset.uri).filter(Boolean));
  const count = () => {
    add.disabled = !chosen.size;
    add.textContent = chosen.size ? t('editor.pick.attach_n', { n: chosen.size }) : t('editor.dialog.pick_asset');
  };

  const fileUrl = (folderId, root, file) => `api.php?op=asset.workfile&project=${encodeURIComponent(api.projectKey())}`
    + `&folder=${folderId}&path=${encodeURIComponent(file.path.slice(root.length + 1))}`;

  /* Плитка файла. Один файл бывает в нескольких разделах (общая рабочая папка) —
     отметка ставится на всех его плитках сразу. */
  const tileOf = (file, url) => {
    const here = mine.has(file.path);
    const tile = el('button', 'asset-tile asset-pick' + (here ? ' off' : '') + (chosen.has(file.path) ? ' on' : ''));
    tile.dataset.pick = file.path;
    const face = file.kind === 'image'
      ? `<img src="${escapeAttr(url)}" alt="" loading="lazy">`
      : `<i>${assetIcon(file.kind)}</i>`;
    tile.innerHTML = `<span class="asset-face">${face}</span><small>${escape(shortName(file.name))}</small>`;
    if (here) {
      tile.disabled = true;
      tile.title = t('editor.pick.already');
      return tile;
    }
    tile.onclick = () => {
      if (chosen.has(file.path)) chosen.delete(file.path); else chosen.set(file.path, file);
      for (const one of body.querySelectorAll('[data-pick]')) {
        if (one.dataset.pick === file.path) one.classList.toggle('on', chosen.has(file.path));
      }
      count();
    };
    return tile;
  };

  let turn = 0;
  const show = async (scope) => {
    const mineTurn = ++turn;
    title.textContent = SCOPES.find(([value]) => value === scope)[1];
    for (const button of tabs.children) button.classList.toggle('on', button.dataset.scope === scope);
    body.textContent = '';
    body.append(note(t('editor.pick.loading')));

    const folders = scope === 'folder' ? (state.folder?.workDir ? [state.folder] : []) : workFolders();
    const lists = await Promise.all(folders.map(inFiles));
    if (mineTurn !== turn) return;     // пока читали, переключили вкладку

    body.textContent = '';
    if (!folders.length) {
      body.append(note(scope === 'folder' ? t('editor.pick.no_workdir') : t('editor.pick.no_workdir_any')));
      return;
    }
    folders.forEach((folder, i) => {
      const { root, files } = lists[i];
      const content = files.length ? el('div', 'asset-tiles') : note(t('editor.pick.in_empty'));
      for (const file of files) content.append(tileOf(file, fileUrl(folder.id, root, file)));
      if (scope === 'folder') { body.append(content); return; }

      // Проект — разделами: открыт раздел текущей папки, остальные раскрываются щелчком.
      const key = 'f' + folder.id;
      const head = el('button');
      const paint = () => {
        const open = groupOpen(key);
        head.className = 'asset-group-title' + (open ? '' : ' shut');
        head.innerHTML = `<span></span><b>${open ? '▾' : '▸'}</b>`;
        head.querySelector('span').textContent = `${folder.name || t('editor.folders.unnamed')} · ${files.length}`;
        content.hidden = !open;
      };
      head.onclick = () => { toggleGroup(key); paint(); };
      paint();
      body.append(head, content);
    });
  };

  tabs.textContent = '';
  for (const [value, word] of SCOPES) {
    const button = el('button', 'switch-item', word);
    button.dataset.scope = value;
    button.onclick = () => show(value);
    tabs.append(button);
  }

  body.className = '';
  count();
  for (const button of dialog.querySelectorAll('[data-close]')) button.onclick = () => dialog.close();
  dialog.showModal();
  show('folder');                      // всегда сначала — материалы текущей папки

  add.onclick = async () => {
    if (!chosen.size) return;
    add.disabled = true;
    try {
      await api.batch([...chosen.values()].map((file) => ({
        op: 'asset.create', kind: file.kind || 'file', title: file.name, uri: file.path,
        link: { element: element.id, role: 'attachment' },
      })));
    } catch {
      count();
      return;                        // причину уже сказал слой API
    }
    toast(chosen.size === 1 ? t('editor.asset.attached') : t('editor.pick.attached_n', { n: chosen.size }));
    dialog.close();
    await reloadElement(element.id);

    // Первая выбранная картинка сразу становится заглавной блока — как при перетаскивании.
    const image = [...chosen.values()].find((file) => file.kind === 'image');
    const link = image && element.type === 'block'
      && (state.elements.get(element.id)?.assets || []).find((one) => one.uri === image.path);
    if (link && link.role !== 'cover') {
      await api.post('asset.role', { id: link.link, role: 'cover' }).catch(() => null);
      await reloadElement(element.id);
    }
    emit('panel');
  };
}

function shortName(name, max = 18) {
  return name.length > max ? name.slice(0, max - 1) + '…' : name;
}
