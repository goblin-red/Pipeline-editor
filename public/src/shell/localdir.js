/* Папка схемы с диска человека. Когда агенты работают на своих компьютерах (Гоблин в вебе), файлы
   результата остаются у них, и браузер показывает их прямо из выбранной папки (решение хозяина 30.09.2026).
   Туда же кладутся материалы человека, если в проекте снята галочка «Хранить материалы на сервере»
   (решение 01.10.2026): файл — в in/ папки схемы, серверу — только путь; агент берёт его там же.
   Chrome и Edge умеют это (File System Access); в остальных браузерах — подпись «файл у исполнителя»,
   а материалы уходят на сервер с лимитами бесплатного аккаунта.
   Отдаёт: localSupported(), localConnect(), localUrl(), localReady(), localSave(), putFile(), initLocalDir().
   Не делает: не шлёт на сервер байты файла, лежащего у человека. Выбранная папка и разрешение
   хранятся в IndexedDB этого браузера, по номеру папки схемы. */

import * as api from 'goblin/api/client.js';
import { state, on, emit } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';
import { toast } from 'goblin/shell/topbar.js';

const DB = 'goblin-localdir';
const dirs = new Map();       // папка схемы → выбранная папка на диске
const urls = new Map();       // «папка:путь» → адрес картинки в памяти браузера
const asked = new Set();      // что уже искали: не спрашиваем диск на каждой перерисовке
let redraw = 0;

/* Значок режима: глобус — Гоблин в вебе, компьютер — всё на этом компьютере. */
const SVG = (path) => `<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.6"
  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
const ICON_WEB = SVG('<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.4 2.3 3.6 5.1 3.6 8.5s-1.2 6.2-3.6 8.5c-2.4-2.3-3.6-5.1-3.6-8.5s1.2-6.2 3.6-8.5z"/>');
const ICON_LOCAL = SVG('<rect x="4" y="5" width="16" height="10.5" rx="1.6"/><path d="M2.5 19h19"/>');

export const localSupported = () => typeof window.showDirectoryPicker === 'function';

/** Кнопка в шапке: видна в веб-режиме и там, где браузер умеет читать папки. Рядом — пометка режима. */
export function initLocalDir() {
  const button = document.getElementById('btn-localdir');
  const chip = document.getElementById('mode-chip');
  if (!button) return;
  const show = async () => {
    if (chip && (state.project || state.remote !== undefined)) {
      const web = state.project ? !!state.project.agentsRemote : !!state.remote;
      chip.hidden = false;
      chip.innerHTML = web ? ICON_WEB : ICON_LOCAL;
      chip.title = t(web ? 'editor.mode.web_tip' : 'editor.mode.local_tip');
      chip.classList.toggle('web', web);
    }
    button.hidden = !(localSupported() && state.project?.agentsRemote && state.folder);
    button.classList.toggle('on', !button.hidden && await localReady(state.folder.id));
  };
  button.onclick = async () => {
    if (!state.folder) return;
    const dir = await localConnect(state.folder.id);
    if (!dir) return;
    // Папка схемы у агента кончается на «-<номер папки>» (agentFolderPath на сервере).
    const own = dir.name.endsWith('-' + state.folder.id);
    toast(t(own ? 'editor.localdir.connected' : 'editor.localdir.other', { name: dir.name, id: state.folder.id }));
    show();
  };
  on('project', show);
  on('projects', show);
  on('folder', show);
}

/* ── Хранилище: IndexedDB умеет держать сами «ручки» папок ── */

function store(mode, work) {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB, 1);
    open.onupgradeneeded = () => open.result.createObjectStore('dirs');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const tx = open.result.transaction('dirs', mode);
      const request = work(tx.objectStore('dirs'));
      tx.oncomplete = () => resolve(request?.result);
      tx.onerror = () => reject(tx.error);
    };
  });
}

/** Папка схемы, если она выбрана и браузер уже разрешил её читать. */
async function dirOf(folderId) {
  let dir = dirs.get(folderId);
  if (!dir) {
    try { dir = await store('readonly', (s) => s.get(folderId)); } catch { dir = null; }
    if (dir) dirs.set(folderId, dir);
  }
  if (!dir) return null;
  try {
    return (await dir.queryPermission({ mode: 'read' })) === 'granted' ? dir : null;
  } catch {
    return null;
  }
}

export async function localReady(folderId) {
  return !!(await dirOf(folderId));
}

/**
 * Подключить папку схемы — только по нажатию: без него браузер не спросит разрешения.
 * Уже выбранную папку сначала просим вернуть одним «Разрешить», иначе — окно выбора.
 */
export async function localConnect(folderId) {
  let dir = dirs.get(folderId);
  try {
    if (!dir) dir = await store('readonly', (s) => s.get(folderId));
    if (dir && (await dir.requestPermission({ mode: 'readwrite' })) !== 'granted') dir = null;
  } catch {
    dir = null;
  }
  if (!dir) {
    try {
      dir = await window.showDirectoryPicker({ id: 'goblin-' + folderId, mode: 'readwrite' });
    } catch {
      return null;                                   // окно закрыли
    }
    try { await store('readwrite', (s) => s.put(dir, folderId)); } catch { /* запомнить не вышло — до перезагрузки */ }
  }
  dirs.set(folderId, dir);
  // Всё, что не нашли до подключения, ищем заново.
  for (const key of [...asked]) if (key.startsWith(folderId + ':')) asked.delete(key);
  redrawSoon();
  return dir;
}

/**
 * Адрес файла из подключённой папки: путь — от папки схемы («out/r5/face.png»).
 * Пока файл не прочитан — null; прочитали — перерисуем схему, и адрес уже будет.
 */
export function localUrl(folderId, rel) {
  const key = folderId + ':' + rel;
  if (urls.has(key)) return urls.get(key);
  if (!asked.has(key)) {
    asked.add(key);
    read(folderId, rel, key);
  }
  return null;
}

async function read(folderId, rel, key) {
  const dir = await dirOf(folderId);
  if (!dir) return;
  try {
    const parts = rel.split('/').filter((one) => one && one !== '.');
    let node = dir;
    for (const part of parts.slice(0, -1)) node = await node.getDirectoryHandle(part);
    const file = await (await node.getFileHandle(parts[parts.length - 1])).getFile();
    urls.set(key, URL.createObjectURL(file));
    redrawSoon();
  } catch {
    // Файла в выбранной папке нет — останется подпись «файл у исполнителя».
  }
}

/** Картинки приходят пачкой — схему перерисовываем один раз. */
function redrawSoon() {
  clearTimeout(redraw);
  redraw = setTimeout(() => { emit('scheme'); emit('panel'); }, 80);
}

/* ── Материалы человека: в in/ папки схемы или на сервер ─────── */

/** Папка схемы с правом записи: уже разрешено — сразу; иначе просим (нужно нажатие человека, может не выйти). */
async function writable(folderId) {
  let dir = dirs.get(folderId);
  try {
    if (!dir) dir = await store('readonly', (s) => s.get(folderId));
    if (!dir) return null;
    if ((await dir.queryPermission({ mode: 'readwrite' })) === 'granted') return dir;
    return (await dir.requestPermission({ mode: 'readwrite' })) === 'granted' ? dir : null;
  } catch {
    return null;
  }
}

/** Свободное имя в папке: «фото.png», занято — «фото-2.png», «фото-3.png»… */
async function freeName(dir, name) {
  const dot = name.lastIndexOf('.');
  const [base, ext] = dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, ''];
  for (let n = 1; n < 1000; n++) {
    const candidate = n === 1 ? name : `${base}-${n}${ext}`;
    try { await dir.getFileHandle(candidate); } catch { return candidate; }
  }
  return `${base}-${Date.now()}${ext}`;
}

/**
 * Положить файл в in/ папки схемы на компьютере человека. Папка не подключена — спросить её
 * (окно выбора); не вышло или браузер не умеет — null. Отдаёт путь от папки схемы: «in/имя.png».
 */
export async function localSave(folderId, file) {
  if (!localSupported() || !folderId) return null;
  const dir = (await writable(folderId)) || (await localConnect(folderId));
  if (!dir) return null;
  try {
    const inDir = await dir.getDirectoryHandle('in', { create: true });
    const name = await freeName(inDir, file.name);
    const out = await (await inDir.getFileHandle(name, { create: true })).createWritable();
    await out.write(file);
    await out.close();
    urls.set(`${folderId}:in/${name}`, URL.createObjectURL(file));   // показать сразу, не читая с диска
    return 'in/' + name;
  } catch {
    return null;
  }
}

/**
 * Материал человека — туда, куда велит проект. В вебе без галочки «Хранить материалы на сервере» —
 * в in/ папки схемы у человека, серверу только путь; не вышло — на сервер. На сервер (веб) — с лимитом
 * размера бесплатного аккаунта: больше — предупреждение и отказ, сервер то же скажет и сам.
 * Локальная установка — как всегда: файл на диск сервера, он и есть компьютер человека.
 */
export async function putFile(file, link, folderId) {
  const project = state.project || {};
  if (project.agentsRemote && !project.filesServer) {
    const rel = await localSave(folderId, file);
    if (rel) {
      return api.post('asset.create', { kind: 'file', title: file.name, uri: rel, bytes: file.size, ...(link ? { link } : {}) });
    }
    toast(t(localSupported() ? 'editor.localdir.to_server_nofolder' : 'editor.localdir.to_server_browser'));
  }
  const kb = project.agentsRemote ? Number(project.filesLimit?.kb) || 0 : 0;
  if (kb && file.size > kb * 1024) {
    toast(t('editor.localdir.over_kb', { name: file.name, kb }), true);
    throw new Error('over the file limit');
  }
  return api.upload(file, link, folderId);
}
