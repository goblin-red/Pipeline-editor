/* Единственный модуль, который ходит на сервер.
   Отдаёт: get(), post(), batch(), projectKey(), hasProjectKey(), folderId(), setFolder(), taskLink(), taskOrder(), onError().
   Не делает: не трогает DOM и ничего не решает о данных. */

import { t } from 'goblin/core/i18n.js';

const API = 'api.php';
let errorHandler = () => {};

/** Адрес вида #p=КЛЮЧ&f=ПАПКА[&e=НОМЕР][&run=ID]. */
export function hash() {
  const out = {};
  for (const pair of location.hash.replace(/^#/, '').split('&')) {
    const [key, value] = pair.split('=');
    if (key) out[key] = decodeURIComponent(value || '');
  }
  return out;
}

export function setHash(patch) {
  const next = { ...hash(), ...patch };
  for (const key of Object.keys(next)) if (next[key] === null || next[key] === '') delete next[key];
  const text = Object.entries(next).map(([k, v]) => `${k}=${v}`).join('&');
  history.replaceState(null, '', '#' + text);
}

/** Ключ проекта. Если его нет — придумываем новый: так открывается пустой холст. */
export function projectKey() {
  let key = hash().p;
  // Старый адрес #s=КЛЮЧ&t=N принимается один раз и переписывается на новый.
  if (!key && hash().s) { key = hash().s; setHash({ p: key, s: null, t: null }); }
  if (!key) {
    key = Array.from({ length: 10 }, () => 'abcdefghijklmnopqrstuvwxyz0123456789'[Math.floor(Math.random() * 36)]).join('');
    setHash({ p: key });
  }
  return key;
}

/** Есть ли ключ проекта в адресе. Пока нет — нового не придумываем:
    сначала надо спросить сервер, какой проект правили последним. */
export function hasProjectKey() {
  return Boolean(hash().p || hash().s);
}

export function folderId() {
  const value = Number(hash().f);
  return Number.isFinite(value) && value > 0 ? value : null;
}

export function setFolder(id) { setHash({ f: id }); }

/** Ссылка-задание агенту: по ней сервер соберёт текст задания со ссылками на инструкции.
    role=lead — провести прогон (агент — ведущий), role=draw — нарисовать или поправить схему. */
export function taskLink(role, folder) {
  const site = location.origin + location.pathname.replace(/[^/]*$/, '');
  return site + API + '?op=docs.run&role=' + role + '&project=' + projectKey() + (folder ? '&folder=' + folder : '');
}

/** Что копируется агенту: ссылка прогона — вместе со словами, что это прогон, чтобы агент не спрашивал,
    что с ней делать (решение хозяина 30.09.2026). Ссылка рисования — как есть. */
export function taskOrder(role, folder) {
  const link = taskLink(role, folder);
  return role === 'lead' ? t('editor.start.run_order', { link }) : link;
}

export function onError(fn) { errorHandler = fn; }

async function talk(url, options, quiet = []) {
  let answer;
  try {
    const response = await fetch(url, options);
    answer = await response.json();
  } catch (error) {
    errorHandler(t('editor.api.no_answer'));
    throw error;
  }
  if (!answer.ok) {
    // quiet — коды отказа, которые вызывающий разбирает сам: всплывашка не нужна.
    if (!quiet.includes(answer.code)) errorHandler(answer.error || t('editor.api.refused'), answer);
    throw Object.assign(new Error(answer.error), answer);
  }
  return answer;
}

/* Ключ проекта подставляется сам. Передал свой (пусть и пустой) — берётся он:
   пустым ключом спрашивают то, что к проекту не привязано, например список
   своих проектов. */
export function get(op, params = {}, quiet = []) {
  const fields = clean(params);
  const query = new URLSearchParams({ op, project: fields.project ?? projectKey(), ...fields });
  return talk(`${API}?${query}`, { headers: { Accept: 'application/json' } }, quiet);
}

/* Тихий GET для фоновых опросов (пульс): без всплывашек об ошибке —
   лёг сервер, и опрос раз в 3 с сыпал бы «Сервер не ответил». При сбое — null. */
export async function quietGet(op, params = {}) {
  try {
    const fields = clean(params);
    const query = new URLSearchParams({ op, project: fields.project ?? projectKey(), ...fields });
    const response = await fetch(`${API}?${query}`, { headers: { Accept: 'application/json' } });
    const answer = await response.json();
    return answer.ok ? answer : null;
  } catch {
    return null;
  }
}

export function post(op, body = {}) {
  const fields = clean(body);
  return talk(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ op, project: fields.project ?? projectKey(), ...fields }),
  });
}

/** POST, в ответ — звук (озвучка ответа, voice.speak). Отказ сервер присылает JSON — он и есть ошибка. */
export async function postAudio(op, body = {}) {
  const fields = clean(body);
  const response = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ op, project: fields.project ?? projectKey(), ...fields }),
  });
  if ((response.headers.get('content-type') || '').includes('json')) {
    const answer = await response.json();
    errorHandler(answer.error || t('editor.api.refused'), answer);
    throw Object.assign(new Error(answer.error), answer);
  }
  return response.blob();
}

/** Пачка правок: всё применяется целиком или не применяется вовсе. */
export function batch(ops, extra = {}, keepalive = false) {
  if (!ops.length) return Promise.resolve({ ok: true, results: [] });
  return talk(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ project: projectKey(), ops, ...clean(extra) }),
    // Уход со страницы: без keepalive браузер обрывает запрос вместе с вкладкой.
    keepalive,
  });
}

/** folder — папка схемы, откуда грузят: файл ляжет в её рабочую папку (in/). */
export function upload(file, link, folder = null) {
  const form = new FormData();
  form.append('payload', JSON.stringify({ project: projectKey(), link, ...(folder ? { folder } : {}) }));
  form.append('file[]', file);
  return talk(`${API}?op=asset.upload`, { method: 'POST', body: form });
}

function clean(object) {
  const out = {};
  for (const [key, value] of Object.entries(object)) if (value !== undefined && value !== null) out[key] = value;
  return out;
}
