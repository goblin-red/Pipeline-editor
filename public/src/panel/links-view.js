/* Секция «Ярлыки» в панели свойств блока, группы и области.
   Отдаёт: linksSection().
   Не делает: не рисует холст (canvas/link.js) и не проверяет правила концов —
              это edit/links.js и сервер.

   Что видно: ярлыки, которые висят на объекте (→), и те, что ведут к нему (←).
   У каждого — переход, у своих — имя и удаление. Внизу — «Ярлык на…»:
   цель выбирается из любой папки проекта, не уходя с холста. */

import * as api from 'goblin/api/client.js';
import { state } from 'goblin/core/state.js';
import { isLinkEnd, kindOf, iconOf } from 'goblin/core/kinds.js';
import { linksFrom, linksTo, linkName, endName, createLink, renameLink, removeLink } from 'goblin/edit/links.js';
import { followLink, LINK_NAME_MAX } from 'goblin/canvas/link.js';
import { el, wrap, note, pick, small } from 'goblin/panel/parts.js';
import { t } from 'goblin/core/i18n.js';

/* Черновик «Ярлык на…» переживает перерисовку панели: она бывает часто. */
const draft = { owner: null, open: false, folder: null, target: '', title: '' };
const cache = new Map();          // папка → объекты, к которым можно прицепить ярлык

export function linksSection(element) {
  const rows = [
    // Переход шлюза — только бирка на холсте (gateLinks), не ярлык: в панели его нет.
    ...linksFrom(element.id).filter((link) => !link.gate).map((link) => row(link, 'out')),
    ...linksTo(element.id).filter((link) => !link.gate).map((link) => row(link, 'in')),
  ];
  if (draft.owner !== element.id) Object.assign(draft, { owner: element.id, open: false, folder: null, target: '', title: '' });

  return wrap(t('editor.panel.links_section'), [
    ...(rows.length ? rows : [note(t('editor.linksview.none'))]),
    ...(state.viewOnly ? [] : [picker(element)]),
  ], rows.length > 0, rows.length);
}

/* ── Строка ярлыка ───────────────────────────────────────────── */

function row(link, side) {
  const line = el('div', 'link-row ' + side);
  const far = side === 'out' ? link.toFolder : link.fromFolder;

  const mark = el('i', 'link-row-dot');
  mark.title = side === 'out' ? t('editor.linksview.from_here') : t('editor.linksview.to_here');
  line.append(mark);

  const text = el('div', 'link-row-text');
  if (side === 'out' && !state.viewOnly) {
    // Своё имя ярлыка. Пусто — подписью служит название цели.
    const name = el('input', 'input link-row-name');
    name.value = link.title || '';
    name.maxLength = LINK_NAME_MAX;   // бирка на холсте — не длиннее 20 знаков
    name.placeholder = endName(link, 'to').slice(0, LINK_NAME_MAX);
    name.title = t('editor.linksview.name_title', { max: LINK_NAME_MAX });
    name.onchange = () => renameLink(link.id, name.value.trim().slice(0, LINK_NAME_MAX));
    text.append(name);
  } else {
    text.append(el('span', 'link-row-title', side === 'out' ? linkName(link, 'out') : '← ' + endName(link, 'from')));
  }
  const where = far && far !== state.folder?.id ? t('editor.linksview.folder_prefix', { name: folderName(far) }) : '';
  text.append(el('small', 'muted', `${where}${side === 'out' ? '→' : '←'} ${side === 'out' ? endName(link, 'to') : t('editor.linksview.here')} · ${t('editor.links.no', { no: link.no })}`));
  line.append(text);

  const go = small('↗', () => followLink(link, side));
  go.disabled = false;                       // переход можно и в режиме просмотра
  go.title = side === 'out' ? t('editor.linksview.go_target') : t('editor.linksview.go_source');
  line.append(go);

  if (!state.viewOnly) {
    const off = small('✕', () => removeLink(link.id));
    off.title = t('editor.linksview.delete');
    line.append(off);
  }
  return line;
}

/* ── «Ярлык на…» ─────────────────────────────────────────────── */

function picker(element) {
  const box = el('div', 'link-picker');
  if (!draft.open) {
    box.append(small(t('editor.linksview.add'), () => { draft.open = true; draft.folder = state.folder?.id ?? null; redraw(box, element); }));
    return box;
  }
  redraw(box, element);
  return box;
}

/** Форма выбора: папка → объект → имя. Перерисовывает только себя. */
function redraw(box, element) {
  box.textContent = '';

  const folders = pick(state.folders.map((folder) => ({
    key: String(folder.id),
    label: folder.id === state.folder?.id ? t('editor.linksview.this_folder', { name: folder.name }) : folder.name,
  })), String(draft.folder ?? ''), false);
  folders.onchange = () => { draft.folder = Number(folders.value); draft.target = ''; redraw(box, element); };

  const targets = pick([], draft.target, true, t('editor.linksview.pick_object'));
  targets.onchange = () => { draft.target = targets.value; make.disabled = !draft.target; };
  fill(targets, element);

  const name = el('input', 'input');
  name.placeholder = t('editor.linksview.name_placeholder', { max: LINK_NAME_MAX });
  name.maxLength = LINK_NAME_MAX;
  name.value = draft.title;
  name.oninput = () => { draft.title = name.value; };

  const make = small(t('editor.linksview.create'), async () => {
    const far = (cache.get(draft.folder) || []).find((item) => String(item.id) === draft.target);
    const made = await createLink(element.id, Number(draft.target), { title: draft.title.trim(), far });
    if (made) Object.assign(draft, { open: false, target: '', title: '' });
  });
  make.disabled = !draft.target;
  const cancel = small(t('common.cancel'), () => { draft.open = false; box.replaceWith(picker(element)); });

  box.append(label2(t('editor.linksview.folder')), folders, label2(t('editor.linksview.object')), targets, name);
  const buttons = el('div', 'row');
  buttons.append(make, cancel);
  box.append(buttons);
}

/** Объекты папки, к которым можно прицепить ярлык. Чужую папку спрашиваем у сервера один раз. */
async function fill(targets, element) {
  const folder = draft.folder;
  if (folder === null) return;
  let list = folder === state.folder?.id
    ? [...state.elements.values()].filter((item) => item.id > 0)
    : cache.get(folder);
  if (!list) {
    const answer = await api.get('folder.get', { folder }).catch(() => null);
    list = answer ? answer.scheme : [];
  }
  list = list.filter((item) => isLinkEnd(item.type) && item.id !== element.id)
    .sort((a, b) => a.no - b.no);
  cache.set(folder, list);
  if (draft.folder !== folder) return;           // пока ждали, выбрали другую папку
  targets.setRows(list.map((item) => ({
    key: String(item.id),
    label: `${item.no} ${iconOf(item)} ${item.title || kindOf(item).title}`,
  })));
  targets.value = draft.target;
}

function label2(text) {
  return el('small', 'muted link-picker-label', text);
}

function folderName(id) {
  return state.folders.find((folder) => folder.id === id)?.name || t('editor.canvas.folder_n', { id });
}
