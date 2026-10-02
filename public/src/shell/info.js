/* «Посмотреть»: сведения о схеме (блок в свойствах папки), списки из config.txt,
   код папки и инструкция — как рисовать на холсте.
   Отдаёт: infoSection(), openLists(), openCode(), openHelp().
   Не делает: ничего не правит — это три способа посмотреть, а не поменять.
   Списки правятся в кабинете, там для них отдельный экран. */

import { state, emit } from 'goblin/core/state.js';
import { KINDS, kindOf, STEP_WORDS } from 'goblin/core/kinds.js';
import * as api from 'goblin/api/client.js';
import { openText } from 'goblin/shell/dialogs.js';
import { renderScheme, schemeSnapshot } from 'goblin/embed/render.js';
import { el, button, note, wrap, kv } from 'goblin/panel/parts.js';
import { flyTo, fitZoom } from 'goblin/canvas/view.js';
import { setVariant } from 'goblin/core/variants.js';
import { toast } from 'goblin/shell/topbar.js';
import { t } from 'goblin/core/i18n.js';

/** Сведения: сколько чего в папке и в проекте — блок в свойствах папки (правая панель). */
export function infoSection() {
  const all = [...state.elements.values()];
  const kinds = new Map();
  for (const element of all) kinds.set(element.type, (kinds.get(element.type) || 0) + 1);

  const folder = {};
  for (const [type, rule] of Object.entries(KINDS)) {
    if (kinds.get(type)) folder[`${rule.icon} ${rule.title}`] = kinds.get(type);
  }
  folder[t('editor.info.total')] = all.length;
  folder[t('editor.info.with_spec')] = all.filter((element) => element.hasSpec).length;
  folder[t('editor.info.assets')] = all.reduce((sum, element) => sum + (element.assets?.length || 0), 0);

  const leads = state.agents.filter((a) => a.role === 'lead').length;
  const project = {
    [t('editor.info.project')]: `${state.project?.title || state.project?.key || '—'} (${state.project?.key || ''})`,
    [t('editor.info.revision')]: state.rev,
    [t('editor.info.next_no')]: state.project?.nextNo ?? '—',
    [t('editor.info.folders')]: state.folders.length,
    [t('editor.info.agents')]: state.agents.length + (state.agents.length ? ` (leader ${leads})` : ''),
    [t('editor.info.folder_runs')]: (state.runs || []).length,
  };

  const parts = [el('div', 'info-title', t('editor.info.in_folder')), kv(folder), el('div', 'info-title', t('editor.info.in_project')), kv(project)];

  if (state.run) {
    const marks = { [t('editor.info.run')]: `${state.run.no ? 'r' + state.run.no : state.run.id} · ${state.run.state || ''}` };
    for (const step of state.steps.values()) {
      const word = STEP_WORDS[step.state] || step.state;
      marks[word] = (marks[word] || 0) + 1;
    }
    parts.push(el('div', 'info-title', t('editor.info.open_run')), kv(marks));
  }

  // Что бросается в глаза: блоки без задания (ни ТЗ, ни описания) и без агента, висячие элементы. Щелчок — к элементу.
  const problems = [];
  for (const element of all) {
    if (element.type !== 'block') continue;
    if (!element.hasSpec && !String(element.description || '').trim()) problems.push([element, t('editor.info.no_spec')]);
    else if (!element.agent) problems.push([element, t('editor.info.no_worker')]);
  }
  for (const element of all) {
    if (!['block', 'decision', 'gateway'].includes(element.type)) continue;
    if (!all.some((a) => a.type === 'arrow' && (a.from === element.id || a.to === element.id))) problems.push([element, t('editor.info.no_arrow')]);
  }
  if (problems.length) {
    const list = el('div', 'info-problems');
    for (const [element, what] of problems.slice(0, 40)) {
      const line = el('button', 'info-problem', `⟨${element.no}⟩ ${element.title || t('editor.info.untitled')} — ${what}`);
      line.onclick = () => goTo(element);
      list.append(line);
    }
    if (problems.length > 40) list.append(el('div', 'muted', t('editor.info.and_more', { n: problems.length - 40 })));
    parts.push(el('div', 'info-title', t('editor.info.look_at')), list);
  }
  return wrap(t('editor.info.section'), parts, false, problems.length || null);
}

/** К элементу: из списка и иерархии — сперва на холст (как followLink), потом выделить и подлететь. */
async function goTo(element) {
  await setVariant('canvas');
  state.selection.clear();
  state.selection.add(element.id);
  emit('selection');
  flyTo(element, fitZoom(element));
}

/** Списки из config.txt: CLI, роли, цвета, типы материалов. */
export async function openLists() {
  let lists = {};
  try { lists = (await api.get('config.get')).lists || {}; } catch {}

  const lines = [t('editor.info.lists_head'), '',
    t('editor.info.lists_note'), ''];
  for (const [name, rows] of Object.entries(lists)) {
    lines.push(`## ${name} · ${rows.length}`);
    for (const row of rows) {
      const extra = row.extra?.length ? '  [' + row.extra.join(', ') + ']' : '';
      lines.push(`  ${row.key} — ${row.label || ''}${row.color ? ' · ' + row.color : ''}${extra}`);
    }
    lines.push('');
  }
  openText({ title: t('editor.settings.lists'), value: lines.join('\n'), hint: t('editor.info.readonly') });
}

/** Код папки: та же схема словами, какой её видит сервер. */
export function openCode() {
  const elements = [...state.elements.values()].map((element) => {
    const out = { no: element.no, type: element.type };
    if (element.title) out.title = element.title;
    if (element.description) out.description = element.description;
    if (element.type === 'arrow') {
      out.from = state.elements.get(element.from)?.no ?? null;
      out.to = state.elements.get(element.to)?.no ?? null;
      if (element.branch && element.branch !== 'flow') out.branch = element.branch;
      if (element.back) out.back = true;
    }
    if (element.in?.length) out.in = element.in.map((id) => state.elements.get(id)?.no).filter(Boolean);
    if (element.hasSpec) out.spec = true;
    if (element.agent) out.agent = state.agents.find((a) => a.id === element.agent)?.name || element.agent;
    if (Object.keys(element.props || {}).length) out.props = element.props;
    out.style = element.style || {};
    return out;
  }).sort((a, b) => a.no - b.no);

  const body = {
    project: state.project?.key,
    folder: { id: state.folder?.id, name: state.folder?.name, workDir: state.folder?.workDir || '' },
    elements,
  };
  const code = JSON.stringify(body, null, 2);
  const dialog = el('dialog', 'dialog dialog-wide');
  dialog.style.cssText = 'max-height:90dvh;overflow:auto;width:min(900px,94vw)';
  const title = el('h2', 'dialog-title', t('editor.info.code_title', { name: state.folder?.name || '' }));
  const preview = el('div');
  preview.style.cssText = 'max-height:42vh;overflow:auto;border:1px solid var(--line);border-radius:10px;margin:12px 0';
  const area = el('textarea', 'dialog-textarea');
  area.style.height = '220px';
  area.value = code;
  area.readOnly = true;
  area.spellcheck = false;
  area.setAttribute('aria-label', t('editor.info.code_label'));
  const foot = el('div', 'dialog-foot');
  foot.append(button(t('editor.info.copy_code'), async () => {
    try { await navigator.clipboard.writeText(code); toast(t('editor.info.copied')); }
    catch { area.focus(); area.select(); toast(t('editor.info.selected')); }
  }), button(t('common.close'), () => dialog.close()));
  dialog.append(title, preview,
    note(t('editor.info.preview_note')),
    area, foot);
  document.body.append(dialog);
  dialog.showModal();
  const dispose = renderScheme(preview, schemeSnapshot(state.folder || {}, [...state.elements.values()]));
  dialog.addEventListener('close', () => { dispose(); dialog.remove(); }, { once: true });
}

/** Инструкция: как рисовать на холсте. Жила подсказкой под «Чем рисовать». */
export function openHelp() {
  openText({ title: t('editor.settings.help'), value: t('editor.info.help_text'), hint: t('editor.info.readonly') });
}

/** Подпись типа — чтобы сведения читались человеком, а не кодом. */
export function typeWord(type) {
  return kindOf({ type }).title;
}
