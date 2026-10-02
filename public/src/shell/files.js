/* Файлы схемы: сохранить и открыть.
   Отдаёт: SAVE_KINDS, OPEN_KINDS, saveAs(), openAs(), openFilesWindow().
   Не делает: ничего не рисует и не хранит — берёт то, что уже открыто,
   и отдаёт браузеру готовый файл.

   Шесть способов сохранить и два открыть — ровно как было в прежнем Гоблине:
   .gbl — весь проект строками базы, .canvas — Obsidian, HTML и PDF — плоский
   вид текущей папки, .md — Mermaid в две стороны. Картинка для HTML и PDF
   рисуется одной и той же сценой, поэтому они всегда совпадают. */

import { state } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { box, arrowPath, pickSides, portPoint } from 'goblin/canvas/geometry.js';
import { loadProject, loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { t, lang } from 'goblin/core/i18n.js';
import { renderScheme, schemeSnapshot } from 'goblin/embed/render.js';

export const SAVE_KINDS = [
  { key: 'html-minimal', icon: '</>', title: t('editor.files.save_html_min') },
  { key: 'gbl',        icon: '⤓',  title: t('editor.files.save_gbl') },
  { key: 'canvas',     icon: '⤓',  title: t('editor.files.save_canvas') },
  { key: 'html',       icon: '</>', title: t('editor.files.save_html') },
  { key: 'pdf',        icon: '📄', title: t('editor.files.save_pdf') },
  { key: 'mermaid-lr', icon: '▭',  title: t('editor.files.save_mermaid_lr') },
  { key: 'mermaid-tb', icon: '▯',  title: t('editor.files.save_mermaid_tb') },
];

export const OPEN_KINDS = [
  { key: 'gbl',    icon: '⤒', title: t('editor.files.open_gbl') },
  { key: 'canvas', icon: '⤒', title: t('editor.files.open_canvas') },
];

/**
 * Окно «Открыть» или «Сохранить»: строка на каждый способ.
 * Списком в плашке это не помещалось, а пояснение к каждому пункту нужно:
 * .gbl — весь проект, остальное — открытая папка.
 */
export function openFilesWindow(what) {
  const save = what === 'save';
  const dialog = document.getElementById('dialog-files');
  const body = document.getElementById('dialog-files-body');
  document.getElementById('dialog-files-title').textContent = save ? t('common.save') : t('editor.files.open');

  body.textContent = '';
  for (const kind of (save ? SAVE_KINDS : OPEN_KINDS)) {
    const row = document.createElement('button');
    row.className = 'file-row';
    row.innerHTML = `<b>${escapeText(kind.icon)}</b><span>${escapeText(kind.title)}</span>`;
    row.onclick = () => { dialog.close(); (save ? saveAs : openAs)(kind.key); };
    body.append(row);
  }
  for (const button of dialog.querySelectorAll('[data-close]')) button.onclick = () => dialog.close();
  dialog.showModal();
}

const escapeText = (text) => String(text ?? '')
  .replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));

/**
 * Схема для файла без сервера: снимок данных и сам renderer — одним куском HTML.
 * renderer без импортов (embed/render.js) и переносится исходником; подписи ему — словарь
 * editor.embed.* этой страницы, как у embed.php?renderer=1. «<» в данных экранирован:
 * название схемы не закроет script.
 */
function schemeSnippet(data) {
  const dict = Object.fromEntries(Object.entries(globalThis.GOBLIN_I18N?.dict || {})
    .filter(([key]) => key.startsWith('editor.embed.')));
  const json = (value) => JSON.stringify(value).replace(/</g, '\\u003c');
  return '<div class="goblin-scheme"></div><script>'
    + `globalThis.GOBLIN_I18N = ${json({ lang, dict })};\n`
    + 'const tr = (key) => globalThis.GOBLIN_I18N?.dict?.[key] ?? key;\n'
    + renderScheme.toString() + '\n'
    + `renderScheme(document.currentScript.previousElementSibling, ${json(data)});`
    + '</scr' + 'ipt>';
}

/* ── Сохранить ────────────────────────────────────────────────── */

export async function saveAs(kind) {
  try {
    if (kind === 'html-minimal') {
      if (!state.folder) throw new Error(t('editor.settings.open_folder_first'));
      const result = await api.get('folder.get', { folder: state.folder.id });
      const data = schemeSnapshot(result.folder, result.scheme);
      return save(name() + '-minimal.html', '<!doctype html><html lang="' + lang + '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' + escapeText(data.title) + '</title><body>' + schemeSnippet(data) + '</body></html>', 'text/html;charset=utf-8');
    }
    if (kind === 'gbl') return await saveGbl();
    if (kind === 'canvas') return save(name() + '.canvas', JSON.stringify(canvasOf(), null, 2), 'application/json');
    if (kind === 'html') return await savePicture('html');
    if (kind === 'pdf') return await savePicture('pdf');
    if (kind === 'mermaid-lr') return save(name() + '.md', mermaidOf('LR'), 'text/markdown;charset=utf-8');
    if (kind === 'mermaid-tb') return save(name() + '.md', mermaidOf('TB'), 'text/markdown;charset=utf-8');
  } catch (error) {
    toast(error.message || t('editor.files.save_failed'), true);
  }
}

async function saveGbl() {
  const answer = await api.get('project.get', { full: 1 });
  save(api.projectKey() + '.gbl', JSON.stringify(answer, null, 2), 'application/json');
}

/** Имя файла — по папке: оно же будет в заголовке HTML и PDF. */
function name() {
  const text = state.folder?.name || t('editor.files.scheme_file');
  return text.replace(/[\\/:*?"<>|\x00-\x1f]/g, '_');
}

function save(fileName, text, mime) {
  download(new Blob([text], { type: mime }), fileName);
  toast(t('editor.files.saved'));
}

function download(blob, fileName) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}

/* ── Obsidian Canvas ──────────────────────────────────────────── */

function canvasOf() {
  const nodes = [];
  const edges = [];
  for (const element of state.elements.values()) {
    if (element.type === 'arrow') {
      const from = state.elements.get(element.from);
      const to = state.elements.get(element.to);
      if (from && to) {
        edges.push({ id: String(element.no), fromNode: String(from.no), toNode: String(to.no),
                     label: element.title || branchWord(element) });
      }
      continue;
    }
    const b = box(element);
    nodes.push({
      id: String(element.no), type: 'text',
      text: (element.title || '') + (element.description ? '\n\n' + element.description : ''),
      x: Math.round(b.x), y: Math.round(b.y), width: Math.round(b.w), height: Math.round(b.h),
      ...(element.style?.color ? { color: String(element.style.color) } : {}),
    });
  }
  return { nodes, edges };
}

const branchWord = (arrow) => (arrow.branch === 'yes' ? t('editor.run.check_yes') : arrow.branch === 'no' ? t('editor.run.check_no') : '');

/* ── Mermaid ──────────────────────────────────────────────────── */

/** Скобки вокруг подписи говорят, какая это фигура. */
const MERMAID_SHAPE = {
  block:    ['["', '"]'],
  decision: ['{"', '"}'],
  gateway:  ['[["', '"]]'],
  group:    ['("', '")'],
  area:     ['("', '")'],
  note:     ['>"', '"]'],
  table:    ['[("', '")]'],
};

function mermaidOf(direction) {
  const lines = [`# ${state.folder?.name || t('editor.files.scheme_title')}`, '', '```mermaid', `flowchart ${direction}`];
  const named = new Map();

  for (const element of state.elements.values()) {
    if (element.type === 'arrow') continue;
    const id = 'n' + element.no;
    named.set(element.id, id);
    const [open, close] = MERMAID_SHAPE[element.type] || MERMAID_SHAPE.block;
    lines.push(`  ${id}${open}${mermaidText(element.no + ' · ' + (element.title || ''))}${close}`);
  }
  for (const element of state.elements.values()) {
    if (element.type !== 'arrow') continue;
    const from = named.get(element.from);
    const to = named.get(element.to);
    if (!from || !to) continue;
    const label = element.title || branchWord(element);
    lines.push(label ? `  ${from} -->|${mermaidText(label)}| ${to}` : `  ${from} --> ${to}`);
  }
  lines.push('```', '');

  // Под картинкой — тексты блоков: в Mermaid длинные описания не помещаются.
  for (const element of [...state.elements.values()].filter((e) => e.type !== 'arrow' && e.description)) {
    lines.push(`## ${element.no} · ${element.title || ''}`.trimEnd(), '', element.description, '');
  }
  return lines.join('\n');
}

const mermaidText = (text) => String(text || '').replace(/"/g, "'").replace(/[\r\n]+/g, ' ').trim();

/* ── Плоская картинка: HTML и PDF ─────────────────────────────── */

/** Форма фигуры путём: то же, что видно на холсте. */
function shapePath(element, b) {
  const { x, y, w, h } = b;
  const shape = element.style?.shape
    || (element.type === 'decision' ? 'diamond' : element.type === 'gateway' ? 'hex' : 'rounded');
  if (shape === 'diamond') return `M ${x + w / 2} ${y} L ${x + w} ${y + h / 2} L ${x + w / 2} ${y + h} L ${x} ${y + h / 2} Z`;
  if (shape === 'hex') return `M ${x + 26} ${y} L ${x + w - 26} ${y} L ${x + w} ${y + h / 2} L ${x + w - 26} ${y + h} L ${x + 26} ${y + h} L ${x} ${y + h / 2} Z`;
  const r = shape === 'rect' ? 4 : Math.min(14, w / 4, h / 4);
  return `M ${x + r} ${y} H ${x + w - r} Q ${x + w} ${y} ${x + w} ${y + r} V ${y + h - r}`
       + ` Q ${x + w} ${y + h} ${x + w - r} ${y + h} H ${x + r} Q ${x} ${y + h} ${x} ${y + h - r} V ${y + r}`
       + ` Q ${x} ${y} ${x + r} ${y} Z`;
}

/** Разбить текст по ширине — мерилом служит шрифт будущего файла. */
function wrap(text, width, size, measure) {
  const out = [];
  for (const paragraph of String(text || '').split(/\r?\n/)) {
    let line = '';
    for (const word of paragraph.split(/\s+/).filter(Boolean)) {
      if (line && measure(line + ' ' + word, size) > width) { out.push(line); line = ''; }
      for (const char of word) {
        if (line && measure(line + char, size) > width) { out.push(line); line = ''; }
        line += char;
      }
      line += ' ';
    }
    out.push(line.trimEnd());
  }
  return out.filter((line) => line !== '');
}

/**
 * Сцена: фигуры, линии и подписи в координатах схемы.
 * Одна на HTML и PDF — поэтому файлы выходят одинаковыми.
 */
function sceneOf(measure) {
  const all = [...state.elements.values()];
  const shapes = [];
  const paths = [];
  const texts = [];
  const addText = (text, x, y, size = 13, color = '#26313c') => texts.push({ text: String(text), x, y, size, color });

  // Сперва подложки: области и группы.
  for (const element of all.filter((e) => e.type === 'area' || e.type === 'group')) {
    const b = box(element);
    shapes.push({ d: shapePath(element, b), fill: '#f3f5f7', stroke: '#9aa3ad', width: 1 });
    addText(element.title || '', b.x + 14, b.y + 24, 13, '#5c6773');
  }

  // Стрелки.
  for (const arrow of all.filter((e) => e.type === 'arrow')) {
    const from = state.elements.get(arrow.from);
    const to = state.elements.get(arrow.to);
    if (!from || !to) continue;
    const sides = pickSides(from, to);
    const d = arrowPath(from, to, { sides, back: !!arrow.back });
    paths.push({ d, stroke: '#657382', width: 2, dash: !!arrow.style?.dash });
    const end = portPoint(to, sides[1]);
    const v = { t: [0, 1], b: [0, -1], l: [1, 0], r: [-1, 0] }[sides[1]] || [0, 1];
    const [dx, dy] = v;
    paths.push({ d: `M ${end.x - dx * 12 - dy * 5} ${end.y - dy * 12 + dx * 5} L ${end.x} ${end.y}`
                  + ` L ${end.x - dx * 12 + dy * 5} ${end.y - dy * 12 - dx * 5}`, stroke: '#657382', width: 2 });
    const label = arrow.title || branchWord(arrow);
    if (label) {
      const a = portPoint(from, sides[0]);
      addText(label, (a.x + end.x) / 2 + 8, (a.y + end.y) / 2 - 8, 11, '#334155');
    }
  }

  // Карточки поверх всего.
  const cards = [];
  for (const element of all.filter((e) => !['arrow', 'area', 'group'].includes(e.type))) {
    const b = box(element);
    cards.push({ d: shapePath(element, b), fill: '#ffffff', stroke: '#66717e', width: 1.6 });
    const inset = element.type === 'decision' ? b.w * 0.24 : 14;
    const inner = Math.max(30, b.w - 2 * inset);
    addText('№ ' + element.no, b.x + inset, b.y + 18, 9, '#8b95a1');
    let y = b.y + (element.type === 'decision' ? b.h * 0.42 : 38);
    const bottom = b.y + b.h - 14;
    const content = [
      ...wrap(element.title || '', inner, 14, measure).map((text) => ({ text, size: 14 })),
      ...wrap(element.description || '', inner, 11, measure).map((text) => ({ text, size: 11 })),
    ];
    for (const line of content) {
      if (y + line.size > bottom) { addText('…', b.x + inset, Math.min(y, bottom), 11); break; }
      addText(line.text, b.x + inset, y, line.size);
      y += line.size + 4;
    }
  }

  const boxes = all.filter((e) => e.type !== 'arrow').map(box);
  if (!boxes.length) throw new Error(t('editor.files.nothing_to_save'));
  let left = Math.min(...boxes.map((b) => b.x)) - 60;
  let top = Math.min(...boxes.map((b) => b.y)) - 60;
  let right = Math.max(...boxes.map((b) => b.x + b.w)) + 60;
  let bottom = Math.max(...boxes.map((b) => b.y + b.h)) + 60;
  for (const path of paths) {
    const numbers = path.d.match(/-?\d+(?:\.\d+)?/g)?.map(Number) || [];
    for (let i = 0; i + 1 < numbers.length; i += 2) {
      left = Math.min(left, numbers[i] - 20); right = Math.max(right, numbers[i] + 20);
      top = Math.min(top, numbers[i + 1] - 20); bottom = Math.max(bottom, numbers[i + 1] + 20);
    }
  }
  return { title: state.folder?.name || t('editor.files.scheme_title'), shapes, paths, cards, texts,
           left, top, width: right - left, height: bottom - top,
           blocks: all.filter((e) => e.type !== 'arrow') };
}

const escape = (value) => String(value ?? '').replace(/[&<>"']/g,
  (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function svgOf(scene) {
  const shape = (s) => `<path d="${escape(s.d)}" fill="${s.fill}" stroke="${s.stroke}" stroke-width="${s.width}"/>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${scene.left} ${scene.top} ${scene.width} ${scene.height}">`
    + `<rect x="${scene.left}" y="${scene.top}" width="${scene.width}" height="${scene.height}" fill="white"/>`
    + scene.shapes.map(shape).join('')
    + scene.paths.map((p) => `<path d="${escape(p.d)}" fill="none" stroke="${p.stroke}" stroke-width="${p.width}"`
        + `${p.dash ? ' stroke-dasharray="7 5"' : ''}/>`).join('')
    + scene.cards.map(shape).join('')
    + scene.texts.map((label) => `<text x="${label.x}" y="${label.y}" font-family="ExportFont,sans-serif" font-size="${label.size}"`
        + ` fill="${label.color}">${escape(label.text)}</text>`).join('')
    + '</svg>';
}

/* Шрифты и библиотеку PDF тянем только тогда, когда о них попросили. */
const loaded = new Map();
function script(path) {
  if (!loaded.has(path)) {
    loaded.set(path, new Promise((resolve, reject) => {
      const node = document.createElement('script');
      node.src = path;
      node.onload = resolve;
      node.onerror = () => { loaded.delete(path); node.remove(); reject(new Error(t('editor.files.pdf_builder'))); };
      document.head.append(node);
    }));
  }
  return loaded.get(path);
}

async function fontBytes(file) {
  const answer = await fetch('fonts/' + file);
  if (!answer.ok) throw new Error(t('editor.files.font_failed'));
  return new Uint8Array(await answer.arrayBuffer());
}

function base64(bytes) {
  let text = '';
  for (let i = 0; i < bytes.length; i += 8192) text += String.fromCharCode(...bytes.subarray(i, i + 8192));
  return btoa(text);
}

async function savePicture(format) {
  await Promise.all([script('vendor/pdf/pdf-lib.min.js'), script('vendor/pdf/fontkit.umd.min.js')]);
  const regular = await fontBytes('NotoSans-Regular.ttf');

  const lib = window.PDFLib;
  const doc = await lib.PDFDocument.create();
  doc.registerFontkit(window.fontkit);
  const font = await doc.embedFont(regular, { subset: true });
  const measure = (text, size) => font.widthOfTextAtSize(String(text), size);
  const scene = sceneOf(measure);

  if (format === 'html') {
    const details = scene.blocks.map((element) =>
      `<article><h2>${escape(element.no)} · ${escape(element.title || '')}</h2>`
      + `<p>${escape(element.description || '')}</p></article>`).join('');
    // Язык страницы — язык интерфейса: подписи и кнопки в ней на нём же.
    const html = `<!doctype html><html lang="${lang}"><head><meta charset="utf-8">`
      + `<meta name="viewport" content="width=device-width,initial-scale=1"><title>${escape(scene.title)}</title>`
      + `<style>@font-face{font-family:ExportFont;src:url(data:font/ttf;base64,${base64(regular)})}`
      + `body{margin:0;font-family:ExportFont,sans-serif;background:#edf0f3;color:#26313c}`
      + `header{padding:14px;position:sticky;top:0;background:#fff;display:flex;align-items:center;gap:12px}`
      + `h1{font-size:18px;margin:0;flex:1}button{padding:6px 12px}main{overflow:auto;padding:20px}`
      + `svg{display:block;width:100%;background:#fff}article{padding:12px;background:#fff;margin:10px 0}`
      + `p{white-space:pre-wrap;overflow-wrap:anywhere}details{padding:20px}`
      + `@media print{header button,details{display:none}header{position:static}body,main{background:#fff;padding:0}}`
      + `</style></head><body><header><h1>${escape(scene.title)}</h1>`
      + `<button onclick="zoom(.8)">−</button><button onclick="zoom(1.25)">+</button>`
      + `<button onclick="scale=100;document.querySelector('svg').style.width='100%'">${t('editor.files.fit')}</button></header>`
      + `<main>${svgOf(scene)}</main><details><summary>${t('editor.files.block_descriptions')}</summary>${details}</details>`
      + `<script>let scale=100;function zoom(f){scale=Math.max(25,Math.min(800,scale*f));`
      + `document.querySelector('svg').style.width=scale+'%'}<\/script></body></html>`;
    save(name() + '.html', html, 'text/html;charset=utf-8');
    return;
  }

  const scale = Math.min(0.75, 14000 / Math.max(scene.width, scene.height));
  const page = doc.addPage([scene.width * scale, scene.height * scale]);
  const rgb = (hex) => {
    const h = /^#[a-f\d]{6}$/i.test(hex) ? hex : '#26313c';
    return lib.rgb(parseInt(h.slice(1, 3), 16) / 255, parseInt(h.slice(3, 5), 16) / 255, parseInt(h.slice(5, 7), 16) / 255);
  };
  const draw = (s) => page.drawSvgPath(s.d, {
    x: -scene.left * scale, y: (scene.top + scene.height) * scale, scale,
    ...(s.fill ? { color: rgb(s.fill) } : {}),
    borderColor: rgb(s.stroke), borderWidth: s.width,
    ...(s.dash ? { borderDashArray: [7, 5] } : {}),
  });
  scene.shapes.forEach(draw);
  scene.paths.forEach(draw);
  scene.cards.forEach(draw);
  for (const label of scene.texts) {
    page.drawText(label.text, { font, size: label.size * scale, x: (label.x - scene.left) * scale,
      y: (scene.top + scene.height - label.y) * scale, color: rgb(label.color) });
  }
  doc.setTitle(scene.title);
  doc.setCreator(t('editor.layout.title'));
  download(new Blob([await doc.save()], { type: 'application/pdf' }), name() + '.pdf');
  toast(t('editor.files.saved'));
}

/* ── Открыть ──────────────────────────────────────────────────── */

export async function openAs(kind) {
  const file = await pickFile(kind === 'gbl' ? '.gbl,application/json' : '.canvas,application/json');
  if (!file) return;
  try {
    const data = JSON.parse(await file.text());
    if (kind === 'gbl') await loadGbl(data);
    else await loadCanvas(data);
  } catch (error) {
    toast(error.message || t('editor.files.open_failed'), true);
  }
}

function pickFile(accept) {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.onchange = () => resolve(input.files?.[0] || null);
    input.click();
  });
}

/** .canvas ложится в открытую папку новыми объектами, ничего не затирая. */
async function loadCanvas(data) {
  if (!state.folder) throw new Error(t('editor.settings.open_folder_first'));
  const nodes = Array.isArray(data.nodes) ? data.nodes : [];
  const edges = Array.isArray(data.edges) ? data.edges : [];
  if (!nodes.length) throw new Error(t('editor.files.no_blocks'));

  const ops = [];
  for (const node of nodes) {
    const [title, ...rest] = String(node.text || node.label || '').split(/\n\n/);
    ops.push({ op: 'element.create', ref: 'c' + node.id, folder: state.folder.id, type: 'block',
      title: (title || '').slice(0, 200), description: rest.join('\n\n'),
      style: { x: Math.round(node.x || 0), y: Math.round(node.y || 0),
               width: Math.round(node.width || 280), height: Math.round(node.height || 180),
               ...(node.color ? { color: String(node.color) } : {}), z: 1 } });
  }
  for (const edge of edges) {
    if (!edge.fromNode || !edge.toNode) continue;
    ops.push({ op: 'element.create', folder: state.folder.id, type: 'arrow',
      from: 'c' + edge.fromNode, to: 'c' + edge.toNode, title: String(edge.label || '').slice(0, 200) });
  }
  await api.batch(ops, { opId: 'canvas-' + Date.now() });
  await loadFolder(state.folder.id);
  toast(t('editor.files.opened_blocks', { n: nodes.length }));
}

/** .gbl раскладывается новыми папками этого же проекта — старое остаётся. */
async function loadGbl(data) {
  const folders = Array.isArray(data.folders) ? data.folders : [];
  const scheme = data.scheme || {};
  if (!folders.length) throw new Error(t('editor.files.no_folders'));

  let blocks = 0;
  for (const folder of folders) {
    const made = await api.post('folder.create', { name: folder.name || t('editor.files.from_file') });
    const folderId = made.results?.[0]?.id;
    if (!folderId) continue;

    const list = scheme[folder.id] || [];
    const ops = [];
    // Стрелки и ярлыки — вторым проходом: им нужны готовые концы.
    for (const element of list.filter((e) => e.type !== 'arrow' && e.type !== 'link')) {
      ops.push({ op: 'element.create', ref: 'g' + element.id, folder: folderId, type: element.type,
        title: element.title || '', description: element.description || '', style: element.style || {} });
      blocks++;
    }
    for (const arrow of list.filter((e) => e.type === 'arrow')) {
      ops.push({ op: 'element.create', folder: folderId, type: 'arrow',
        from: 'g' + arrow.from, to: 'g' + arrow.to, title: arrow.title || '',
        ...(arrow.branch && arrow.branch !== 'flow' ? { branch: arrow.branch } : {}) });
    }
    // Ярлык едет, только если оба конца в этой же папке: ссылку в чужую папку
    // файла не на что повесить — новых папок у неё нет.
    for (const link of list.filter((e) => e.type === 'link' && list.some((x) => x.id === e.to))) {
      ops.push({ op: 'element.create', type: 'link',
        from: 'g' + link.from, to: 'g' + link.to, title: link.title || '' });
    }
    if (ops.length) await api.batch(ops, { opId: 'gbl-' + folderId });
  }
  await loadProject();
  toast(t('editor.files.opened_folders', { folders: folders.length, objects: blocks }));
}
