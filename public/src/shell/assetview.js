/* Окна материалов: ТЗ, картинка, файл, ссылка, текст.
   Отдаёт: openSpec(), openAsset(), openWorkfile(), deleteWorkfile(),
           attachFile(), attachLink(), coverAsset(), setCover(),
           isCover(), assetUrl(), isImage(), isVideo(), assetIcon().
   Не делает: не решает, откуда его позвали — окно одно на холст, панель и списки.

   Щёлкнул по значку МД — открылось ТЗ. Щёлкнул по картинке — открылась она же
   крупно, и её тут же можно заменить. Больше ничего окно не умеет. */

import { state, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { toast } from 'goblin/shell/topbar.js';
import { openText, outside, paintCode, setDialogTitle } from 'goblin/shell/dialogs.js';
import { t } from 'goblin/core/i18n.js';
import { localUrl, putFile } from 'goblin/shell/localdir.js';

/* ── Что это за материал ──────────────────────────────────────── */

export function isImage(asset) {
  return asset?.kind === 'image' || /\.(png|jpe?g|gif|webp|bmp|svg|avif)(\?|$)/i.test(asset?.uri || '');
}

export function isVideo(asset) {
  return asset?.kind === 'video' || /\.(mp4|webm|mov|m4v)(\?|$)/i.test(asset?.uri || '');
}

export function isText(asset) {
  return ['spec', 'text', 'code', 'md'].includes(asset?.kind) || asset?.role === 'spec';
}

export function assetIcon(kind) {
  return { image: '🖼', video: '🎬', audio: '🔊', pdf: '📕', text: '📄', spec: '📄', md: '📄',
           code: '⌨', folder: '📁', link: '🔗', table: '▦' }[kind] || '📎';
}

/**
 * Адрес байтов: сервер отдаёт их с проверкой прав, прямых ссылок нет.
 * Файл у агента в вебе (agent) — из подключённой папки схемы; не подключена — null.
 */
export function assetUrl(asset) {
  if (!asset) return null;
  if (asset.agent && asset.uri) return state.folder ? localUrl(state.folder.id, asset.uri) : null;
  if (asset.uri && /^https?:/i.test(asset.uri)) return asset.uri;
  const id = asset.asset || asset.id;
  if (!id) return null;
  return `api.php?op=asset.file&project=${encodeURIComponent(api.projectKey())}&asset=${id}`;
}

/**
 * Заглавная картинка карточки — только выбранная галочкой, человеком или прогоном.
 * Картинка-результат прогона (fromRun) — только у прогона, который сейчас на холсте:
 * результат прошлого к новой картине не липнет, а у старого прогона видна его
 * картинка, даже если следующий прогон разжаловал её в обычное вложение.
 * Иначе — обложка человека. Сняли галочку — на схеме не рисуется ничего.
 */
export function coverAsset(element) {
  const links = element?.assets || [];
  const ofRun = (a) => !!a.fromRun && a.fromRun === state.run?.id;
  return links.find((a) => a.role === 'cover' && ofRun(a))
    || links.find((a) => a.role === 'attachment' && ofRun(a) && isImage(a))
    || links.find((a) => a.role === 'cover' && !a.fromRun)
    || null;
}

/** Эта ли картинка выбрана заглавной. Заглавная у блока одна. */
export function isCover(asset) {
  return asset?.role === 'cover';
}

/**
 * Сделать картинку заглавной для карточки — или снять выбор.
 * Заглавная у блока одна: прежняя сама становится обычным вложением.
 * Снял галочку — карточка снова показывает первую прикреплённую картинку.
 */
export async function setCover(asset, element, on) {
  if (!asset?.link) return;
  await api.post('asset.role', { id: asset.link, role: on ? 'cover' : 'attachment' });
  toast(on ? t('editor.asset.cover_on') : t('editor.asset.cover_off'));
  await refresh(element?.id);
}

/* ── ТЗ ───────────────────────────────────────────────────────── */

/** Окно ТЗ: тот самый md, который уйдёт worker. */
export async function openSpec(element) {
  if (!element) return;
  let spec = null;
  try {
    const answer = await api.get('asset.get', { element: element.id, role: 'spec', text: 1 });
    spec = answer.assets?.[0] || null;
  } catch { return; }

  const was = typeof spec?.text === 'string' ? spec.text : '';
  const text = await openText({
    title: spec ? spec.title : t('editor.clipboard.spec_title', { name: element.title || element.no }),
    titleContext: blockTitle(element),
    value: was,
    hint: t('editor.asset.spec_hint'),
    allowDelete: !!spec,
    code: { lang: 'md' },
  });
  if (text === null || text === was) return;

  if (text === '') {
    if (spec) {
      await api.post('asset.unlink', { id: spec.link });
      toast(t('editor.asset.spec_detached'));
    }
  } else if (spec) {
    await api.post('asset.update', { id: spec.asset || spec.id, text });
    toast(t('editor.asset.spec_saved'));
  } else {
    await api.post('asset.create', { kind: 'spec', title: t('editor.clipboard.spec_title', { name: element.title || element.no }),
      text, link: { element: element.id, role: 'spec' } });
    toast(t('editor.asset.spec_added'));
  }
  await refresh(element.id);
}

/* ── Один материал ────────────────────────────────────────────── */

function blockTitle(element) {
  return `№${element.no} · ${element.title || ''}`.trim();
}

async function linkedBlockTitle(links, element) {
  const ids = [...new Set(links.filter((link) => link.element).map((link) => Number(link.element)))];
  if (!ids.length) return element ? blockTitle(element) : '';
  const blocks = await Promise.all(ids.map(async (id) => {
    const known = state.elements.get(id);
    if (known) return known;
    try { return (await api.get('element.get', { element: id })).element; }
    catch { return null; }
  }));
  return blocks.filter(Boolean).map(blockTitle).join('; ');
}

/** Окно материала: показать, переименовать, заменить, открепить. */
export async function openAsset(asset, element, list = null) {
  if (!asset) return;
  if (asset.role === 'spec' && !list) return openSpec(element);
  if (!list) {
    try {
      const answer = await api.get('asset.get', { scope: 'library', folder: state.folder?.id });
      list = answer.assets || [];
    } catch { list = []; }
  }
  if (!list.length) list = [asset];
  if (!list.some((item) => Number(item.asset || item.id) === Number(asset.asset || asset.id))) {
    list = [asset, ...list];
  }

  // Текст приходит отдельным запросом — в списке его нет.
  let full = asset;
  let titleContext = element ? blockTitle(element) : '';
  try {
    const answer = await api.get('asset.get', { asset: asset.asset || asset.id });
    full = { ...answer.asset, role: asset.role, link: asset.link };
    titleContext = await linkedBlockTitle(answer.links || [], element);
  } catch {}

  const dialog = document.getElementById('dialog-asset');
  const view = document.getElementById('dialog-asset-view');
  const foot = document.getElementById('dialog-asset-foot');
  const heading = document.getElementById('dialog-asset-title');
  heading.hidden = false;
  setDialogTitle(heading, full.title || '', titleContext);

  view.textContent = '';
  foot.textContent = '';
  let content = null;
  if (!isText(full)) {
    content = preview(full);
    view.append(content);
  }

  const name = document.createElement('input');
  name.className = 'input';
  name.value = full.title || '';
  name.oninput = () => setDialogTitle(heading, name.value, titleContext);
  view.append(wrapField(t('editor.asset.name'), name));

  let body = null;
  if (isText(full)) {
    body = document.createElement('textarea');
    body.className = 'dialog-textarea';
    body.value = typeof full.text === 'string' ? full.text : '';
    const holder = wrapField(t('editor.asset.text'), body);
    holder.classList.add('asset-text-field');
    content = holder;
    view.append(holder);
    paintCode(body, { lang: 'md' });
    // У загруженного файла текст лежит в байтах, а не в базе: дочитываем его.
    if (!body.value && full.file) readFileText(full, body, holder);
  }

  // Заглавная картинка: её и показывает карточка на схеме. Галочка стоит
  // в подвале окна, рядом с «Открыть отдельно», — это действие, а не поле.
  let cover = null;
  if (element && full.link && isImage(full) && full.role !== 'spec') {
    cover = checkbox(t('editor.asset.make_cover'), isCover(full));
    cover.querySelector('input').onchange = (event) => setCover(full, element, event.target.checked);
  }

  // «Заменить файл» стоит рядом с адресом: это про одно и то же — где байты.
  const swap = button(t('editor.asset.replace'), 'btn btn-quiet', async () => {
    const file = await pickFile();
    if (!file) return;
    close();
    await replaceFile(full, element, file);
  });

  // Адрес виден всегда: у ссылки его правят, у загруженного файла это адрес,
  // по которому сервер отдаёт байты, — его только читают и копируют.
  let uri = null;
  const address = full.uri || assetUrl(full) || '';
  if (address) {
    uri = document.createElement('input');
    uri.className = 'input';
    uri.value = address;
    uri.readOnly = !full.uri || !!full.file;
    uri.style.flex = '1';
    const line = document.createElement('div');
    line.className = 'row';
    line.append(uri, swap);
    view.append(wrapField(uri.readOnly ? t('editor.asset.where_server') : t('editor.asset.where'), line));
  }

  const close = () => dialog.close();
  const save = async () => {
    const patch = { id: full.asset || full.id };
    if (name.value !== (full.title || '')) patch.title = name.value;
    if (body && !body.readOnly && body.value !== (full.text || '')) patch.text = body.value;
    if (uri && !uri.readOnly && uri.value !== (full.uri || '')) patch.uri = uri.value;
    if (Object.keys(patch).length === 1) return;
    await api.post('asset.update', patch);
    toast(t('editor.asset.saved'));
    await refresh(element?.id);
  };
  // Щелчок мимо окна — это «готово»: правки не теряются.
  dialog.onclick = (event) => { if (outside(dialog, event)) { close(); save(); } };
  dialog.onkeydown = null;
  dialog.onclose = () => { dialog.onclick = null; dialog.onkeydown = null; dialog.onclose = null; };

  if (!address) foot.append(swap);
  foot.append(
    button(t('editor.asset.detach'), 'btn btn-quiet btn-danger', async () => {
      close();
      if (full.link) await api.post('asset.unlink', { id: full.link });
      toast(t('editor.asset.detached'));
      await refresh(element?.id);
    }),
    button(t('common.done'), 'btn btn-accent', () => { close(); save(); }),
  );

  if (cover) foot.prepend(cover);
  const open = assetUrl(full);
  if (open) foot.prepend(link(t('editor.asset.open_separately'), open));

  const index = list.findIndex((item) => Number(item.asset || item.id) === Number(asset.asset || asset.id));
  if (index >= 0) {
    const navigation = document.createElement('div');
    navigation.className = 'row';
    let moving = false;
    const move = async (offset) => {
      const target = list[index + offset];
      if (moving || !target) return;
      moving = true;
      previous.disabled = next.disabled = true;
      try {
        await save();
        if (dialog.open) {
          const owner = element?.assets?.some((item) =>
            Number(item.asset || item.id) === Number(target.asset || target.id)) ? element : null;
          await openAsset(target, owner, list);
          dialog.focus({ preventScroll: true });
        }
      } catch {
        // Ошибку показывает слой API; текущий материал остаётся открытым.
      } finally {
        moving = false;
        previous.disabled = index === 0;
        next.disabled = index === list.length - 1;
      }
    };
    const previous = button('←', 'btn btn-quiet', () => move(-1));
    const next = button('→', 'btn btn-quiet', () => move(1));
    previous.title = t('editor.asset.prev');
    next.title = t('editor.asset.next');
    previous.setAttribute('aria-label', previous.title);
    next.setAttribute('aria-label', next.title);
    previous.disabled = index === 0;
    next.disabled = index === list.length - 1;
    dialog.onkeydown = (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      if (event.target.closest('input,textarea,select,[contenteditable="true"],video,audio')) return;
      if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
      event.preventDefault();
      event.stopPropagation();
      move(event.key === 'ArrowLeft' ? -1 : 1);
    };
    navigation.append(previous, next);
    foot.insertBefore(navigation, foot.querySelector('.btn-danger'));
  }

  dialog.querySelector('#asset-dismiss')?.remove();
  const dismiss = button('×', 'dialog-x', () => { close(); save(); });
  dismiss.id = 'asset-dismiss';
  dismiss.title = t('common.close');
  dismiss.setAttribute('aria-label', t('common.close'));
  dismiss.style.cssText = 'z-index:10;top:4px;right:4px;width:28px;height:28px;font-size:24px;background:var(--surface);border:0';
  dialog.append(dismiss);

  dialog.tabIndex = -1;
  if (!dialog.open) {
    dialog.showModal();
    dialog.focus({ preventScroll: true });
  }
}

/**
 * Окно файла рабочей папки: превью, имя, где лежит, удалить.
 * file — строка asset.workfiles, rel — путь от рабочей папки, url — адрес байтов,
 * onChange — перерисовать список после переименования или удаления.
 */
export function openWorkfile(file, rel, url, folderId, onChange) {
  const dialog = document.getElementById('dialog-asset');
  const view = document.getElementById('dialog-asset-view');
  const foot = document.getElementById('dialog-asset-foot');
  const heading = document.getElementById('dialog-asset-title');
  heading.hidden = false;
  setDialogTitle(heading, file.name, '');
  view.textContent = '';
  foot.textContent = '';

  const holder = document.createElement('div');
  holder.className = 'asset-preview';
  if (file.kind === 'image' || file.kind === 'video') {
    const media = document.createElement(file.kind === 'image' ? 'img' : 'video');
    media.src = url;
    if (file.kind === 'video') media.controls = true;
    holder.append(media);
  } else {
    const line = document.createElement('p');
    line.className = 'muted';
    line.textContent = `${assetIcon(file.kind)} ${file.kind} · ${t('editor.runlog.kbytes', { n: Math.max(1, Math.round(file.bytes / 1024)) })}`;
    holder.append(line);
  }
  view.append(holder);

  const name = document.createElement('input');
  name.className = 'input';
  name.value = file.name;
  name.readOnly = state.viewOnly;
  name.oninput = () => setDialogTitle(heading, name.value, '');
  view.append(wrapField(t('editor.asset.name'), name));

  const where = document.createElement('input');
  where.className = 'input';
  where.value = file.path;
  where.readOnly = true;
  view.append(wrapField(t('editor.asset.where_server'), where));

  const close = () => dialog.close();
  const save = async () => {
    const next = name.value.trim();
    if (state.viewOnly || !next || next === file.name) return;
    await api.post('asset.workfile.rename', { folder: folderId, path: rel, name: next });
    toast(t('editor.asset.renamed'));
    onChange?.();
  };
  dialog.onclick = (event) => { if (outside(dialog, event)) { close(); save(); } };
  dialog.onkeydown = null;
  dialog.onclose = () => { dialog.onclick = null; dialog.onkeydown = null; dialog.onclose = null; };

  foot.append(
    link(t('editor.asset.open_separately'), url),
    button(t('common.delete'), 'btn btn-quiet btn-danger', async () => {
      if (!await deleteWorkfile(file, rel, folderId)) return;
      close();
      onChange?.();
    }),
    button(t('common.done'), 'btn btn-accent', () => { close(); save(); }),
  );

  dialog.querySelector('#asset-dismiss')?.remove();
  const dismiss = button('×', 'dialog-x', () => { close(); save(); });
  dismiss.id = 'asset-dismiss';
  dismiss.title = t('common.close');
  dismiss.setAttribute('aria-label', t('common.close'));
  dismiss.style.cssText = 'z-index:10;top:4px;right:4px;width:28px;height:28px;font-size:24px;background:var(--surface);border:0';
  dialog.append(dismiss);

  dialog.tabIndex = -1;
  if (!dialog.open) {
    dialog.showModal();
    dialog.focus({ preventScroll: true });
  }
}

/** Удалить файл рабочей папки с диска — после вопроса. Отдаёт, удалён ли он. */
export async function deleteWorkfile(file, rel, folderId) {
  if (!confirm(t('editor.asset.delete_file_ask', { name: file.name }))) return false;
  await api.post('asset.workfile.delete', { folder: folderId, path: rel });
  toast(t('editor.asset.file_deleted'));
  return true;
}

/**
 * Текст загруженного файла. В базе его нет — байты отдаёт сервер, поэтому
 * показываем только на чтение: файл меняют кнопкой «Заменить файл».
 * Большие файлы не тянем: окно не читалка.
 */
async function readFileText(asset, field, holder) {
  const url = assetUrl(asset);
  if (!url || (asset.bytes || 0) > 512 * 1024) return;
  field.readOnly = true;
  field.value = t('editor.asset.reading');
  field.oninput?.();
  try {
    const response = await fetch(url);
    if (!response.ok) throw new Error(t('editor.asset.not_served'));
    field.value = await response.text();
    holder.querySelector('span').textContent = t('editor.asset.file_text');
  } catch {
    field.value = '';
    field.readOnly = false;
  } finally {
    field.oninput?.();
  }
}

/** Что видно в окне: картинка, видео или строка о файле. */
function preview(asset) {
  const holder = document.createElement('div');
  holder.className = 'asset-preview';
  const url = assetUrl(asset);

  if (asset.missing || (asset.agent && !url)) {
    const line = document.createElement('p');
    line.className = 'muted';
    // Файл у исполнителя: увидеть — подключить папку схемы (кнопка в шапке, Chrome и Edge).
    line.textContent = asset.missing ? t('editor.asset.file_missing') : t('editor.asset.at_agent', { path: asset.uri });
    holder.append(line);
    return holder;
  }
  if (isImage(asset) && url) {
    const image = document.createElement('img');
    image.src = url;
    image.alt = asset.title || '';
    holder.append(image);
    return holder;
  }
  if (isVideo(asset) && url) {
    const video = document.createElement('video');
    video.src = url;
    video.controls = true;
    holder.append(video);
    return holder;
  }
  const line = document.createElement('p');
  line.className = 'muted';
  line.textContent = `${assetIcon(asset.kind)} ${asset.kind}`
    + (asset.bytes ? ` · ${t('editor.runlog.kbytes', { n: Math.max(1, Math.round(asset.bytes / 1024)) })}` : '')
    + (asset.name ? ` · ${asset.name}` : '');
  holder.append(line);
  return holder;
}

/* ── Прикрепить и заменить ────────────────────────────────────── */

/* Роль привязки берётся из списка сервера (lib/assets/links.php, LINK_ROLES).
   «Всё прочее» там называется attachment: роли file не существует, и с ней
   сервер отвечал «неизвестная роль привязки». */
const PLAIN = 'attachment';

/** Новый файл встаёт на место старого: та же роль, старая связь снимается. */
export async function replaceFile(asset, element, file) {
  if (!element) return;
  await putFile(file, { element: element.id, role: asset.role || PLAIN }, element.folder ?? state.folder?.id);
  if (asset.link) await api.post('asset.unlink', { id: asset.link });
  toast(t('editor.asset.file_replaced'));
  await refresh(element.id);
}

export async function attachFile(element, role = PLAIN) {
  const file = await pickFile();
  if (!file) return;
  await putFile(file, { element: element.id, role }, element.folder ?? state.folder?.id);
  toast(t('editor.asset.file_attached'));
  await refresh(element.id);
}

export async function attachLink(element) {
  const uri = prompt(t('editor.asset.prompt_uri'));
  if (!uri) return;
  await api.post('asset.create', { kind: /^https?:/i.test(uri) ? 'link' : 'file',
    title: uri.split('/').pop() || uri, uri, link: { element: element.id, role: PLAIN } });
  toast(t('editor.asset.attached'));
  await refresh(element.id);
}

/* ── Мелочи ───────────────────────────────────────────────────── */

function pickFile() {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.onchange = () => resolve(input.files?.[0] || null);
    input.click();
  });
}

/** Материалы элемента перечитываются целиком: связей мало, запрос дешёвый. */
async function refresh(id) {
  if (!id) return;
  try {
    const answer = await api.get('element.get', { element: id });
    const element = answer.element;
    element.assets = element.assets || [];
    state.elements.set(element.id, Object.assign(state.elements.get(element.id) || {}, element));
    emit('element', state.elements.get(element.id));
    emit('panel');
  } catch {}
}

/** Галочка: подпись рядом, пояснение — только если оно вправду нужно. */
function checkbox(text, checked, hint) {
  const wrapper = document.createElement('label');
  wrapper.className = 'row';
  wrapper.style.cssText = 'margin:0;gap:6px;white-space:nowrap';
  const box = document.createElement('input');
  box.type = 'checkbox';
  box.checked = !!checked;
  box.disabled = state.viewOnly;
  const caption = document.createElement('span');
  caption.textContent = text;
  wrapper.append(box, caption);
  if (hint) {
    const line = document.createElement('p');
    line.className = 'muted';
    line.style.fontSize = '12.5px';
    line.textContent = hint;
    wrapper.append(line);
  }
  return wrapper;
}

function wrapField(title, control) {
  const field = document.createElement('label');
  field.className = 'field';
  const caption = document.createElement('span');
  caption.textContent = title;
  field.append(caption, control);
  return field;
}

function button(text, cls, onClick) {
  const node = document.createElement('button');
  node.className = cls;
  node.textContent = text;
  node.onclick = onClick;
  node.disabled = state.viewOnly && ![t('common.close'), t('editor.asset.open_separately')].some((word) => text.includes(word));
  return node;
}

function link(text, href) {
  const node = document.createElement('a');
  node.className = 'btn btn-quiet';
  node.href = href;
  node.target = '_blank';
  node.rel = 'noopener';
  node.textContent = text;
  node.style.marginRight = 'auto';
  return node;
}
