/* Окно агента: кто он и чем работает.
   Отдаёт: openAgent(), newAgent(), cliColor(), cliRows().
   Не делает: не ведёт прогон — здесь только карточка исполнителя.

   Списки CLI, ролей, эффортов, разрешений и песочницы приходят из config.txt:
   правится файл — меняются и выпадающие списки, без правки кода. Модели
   зависят от выбранного CLI, поэтому список моделей перерисовывается сам. */

import { state, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { loadProject, loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { agentUsed } from 'goblin/core/usage.js';
import { pick } from 'goblin/panel/parts.js';
import { t } from 'goblin/core/i18n.js';

let lists = null;     // разделы config.txt: спрашиваем один раз на страницу

/** Списки config.txt: читаются один раз и достаются всем, кому нужны. */
export async function cliRows() {
  if (!lists) {
    try { lists = (await api.get('config.get')).lists || {}; } catch { lists = {}; }
  }
  return lists.cli || [];
}

/** Цвет инструмента агента. Не знаем инструмент — цвет линии. */
export function cliColor(agent) {
  const row = (lists?.cli || []).find((item) => item.key === agent.cli);
  return row?.color ? `var(--c-${row.color})` : 'var(--line)';
}

export function newAgent() { return openAgent(null); }

export async function openAgent(agent) {
  const dialog = document.getElementById('dialog-agent');
  const body = document.getElementById('dialog-agent-body');
  const foot = document.getElementById('dialog-agent-foot');
  document.getElementById('dialog-agent-title').textContent = agent ? (agent.name || t('editor.place.agent')) : t('editor.agent.new');

  if (!lists) {
    try { lists = (await api.get('config.get')).lists || {}; } catch { lists = {}; }
  }
  const now = {
    id: agent?.id ?? null,
    name: agent?.name ?? '',
    role: agent?.role ?? 'worker',
    cli: agent?.cli ?? '',
    model: agent?.model ?? '',
    effort: agent?.effort ?? '',
    permission: agent?.permission ?? '',
    sandbox: agent?.sandbox ?? '',
    closeAfter: agent?.closeAfter ?? 'keep',
  };

  /* Поля идут в два столбика: имя и пояснение о ролях — во всю ширину,
     остальное парами, чтобы окно не тянулось вниз на весь экран. */
  body.textContent = '';
  /* У нового агента поле имени — многострочное: вписал имена столбиком и завёл
     сразу несколько одинаковых по настройкам. У существующего имя одно. */
  const many = !now.id;
  const name = many
    ? area(now.name, t('editor.agent.names_placeholder'))
    : text(now.name, t('editor.agent.name_placeholder'));
  body.append(wide(field(many ? t('editor.agent.name_many') : t('editor.agent.name'), name)));
  if (many) body.append(wide(note(t('editor.agent.names_note'))));

  const role = pick(lists.roles, now.role, false);
  body.append(field(t('editor.agent.role'), role), wide(note(
    t('editor.agent.roles_note'))));

  // У каждого CLI свой цвет из config.txt — он виден и в закрытой плашке,
  // и в раскрытом списке, как было в прежнем Гоблине.
  const cli = pick(lists.cli, now.cli, true);
  const model = pick([], now.model, true, t('editor.agent.default'));
  const effort = pick(lists.effort, now.effort, true);

  // Строка модели в config.txt может нести свой набор усилий: opus-5[low, high].
  const effortsOf = (name) => {
    const row = (lists.cli || []).find((item) => item.key === cli.value);
    const found = (row?.extra || []).find((item) => String(item).split('[')[0].trim() === name);
    const inside = String(found || '').split('[')[1];
    if (!inside) return null;
    return inside.split(']')[0].split(',').map((one) => one.trim()).filter(Boolean);
  };

  const drawEfforts = () => {
    const able = effortsOf(model.value);
    const rows = able
      ? (lists.effort || []).filter((item) => able.includes(item.key))
      : (lists.effort || []);
    effort.setRows(rows);
    if (!rows.some((item) => item.key === effort.value)) effort.value = rows[0]?.key ?? '';
  };

  const drawModels = () => {
    const row = (lists.cli || []).find((item) => item.key === cli.value);
    const known = (row?.extra || []).map((item) => String(item).split('[')[0].trim());
    model.setRows(known.map((item) => ({ key: item, label: item })));
    model.value = known.includes(model.value) ? model.value : '';
    model.disabled = !known.length;
    drawEfforts();
  };
  model.onchange = drawEfforts;
  cli.onchange = drawModels;
  drawModels();
  body.append(field(t('editor.agent.cli'), cli), field(t('editor.agent.model'), model));
  const permission = pick(lists.permission, now.permission, true);
  const sandbox = pick(lists.sandbox, now.sandbox, true);
  body.append(field(t('editor.agent.effort'), effort), field(t('editor.agent.permission'), permission), field(t('editor.agent.sandbox'), sandbox));

  const close = pick([
    { key: 'keep', label: t('editor.agent.keep_open') },
    { key: 'close', label: t('editor.agent.close_after') },
  ], now.closeAfter, false);
  body.append(field(t('editor.agent.window'), close));

  foot.textContent = '';
  const save = button(t('common.save'), 'btn btn-accent', async () => {
    const names = name.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    if (!names.length) { toast(t('editor.agent.need_name'), true); return; }
    const common = {
      role: role.value, cli: cli.value, model: model.value, effort: effort.value,
      permission: permission.value, sandbox: sandbox.value, closeAfter: close.value,
    };
    try {
      if (now.id) {
        await api.post('agent.save', { id: now.id, name: names[0], ...common });
      } else {
        await api.batch(names.map((item) => ({ op: 'agent.save', name: item, ...common })),
          { opId: 'agents-new-' + Date.now() });
      }
      await loadProject();
      emit('agents');                      // вкладка «Агенты» пересоберётся
      toast(names.length > 1 ? t('editor.agent.created_many', { n: names.length }) : t('editor.agent.saved'));
      dialog.close();
    } catch {}
  });
  foot.append(save);

  if (now.id) {
    // Снять со всех блоков: агент остаётся в проекте, уходят только назначения.
    const many = agentUsed(agent);
    const off = button(many ? t('editor.agent.detach_n', { n: many }) : t('editor.agent.detach'), 'btn btn-quiet', async () => {
      const answer = await api.post('agent.detach', { id: now.id });
      await loadProject();
      // Блоки открытой папки лежат в состоянии со старым исполнителем:
      // перечитываем папку, иначе и холст, и счётчик на кирпиче врут до обновления.
      await loadFolder();
      emit('agents');
      toast(answer?.detached ? t('editor.agent.detached', { n: answer.detached }) : t('editor.agent.not_placed'));
      dialog.close();
    });
    off.disabled = state.viewOnly || !many;
    off.title = t('editor.agent.detach_title');

    // Порядок как в панели: сначала мягкое «снять», потом «удалить».
    foot.prepend(button(t('common.delete'), 'btn btn-quiet btn-danger', async () => {
      if (!confirm(t('editor.agent.delete_ask'))) return;
      await api.post('agent.delete', { id: now.id });
      await loadProject();
      emit('agents');
      toast(t('editor.agent.deleted'));
      dialog.close();
    }));
    foot.prepend(off);
  }
  // Закрывает крестик в углу — отдельная кнопка внизу только занимала место.
  for (const x of dialog.querySelectorAll('[data-close]')) x.onclick = () => dialog.close();

  dialog.showModal();
  name.focus();
}

/* ── Мелочи ───────────────────────────────────────────────────── */

/** Поле во всю ширину окна, а не в один столбик. */
function wide(node) {
  node.classList.add('span-2');
  return node;
}

function area(value, placeholder) {
  const node = document.createElement('textarea');
  node.className = 'input';
  node.rows = 3;
  node.value = value;
  node.placeholder = placeholder;
  return node;
}

function text(value, placeholder) {
  const node = document.createElement('input');
  node.className = 'input';
  node.value = value;
  node.placeholder = placeholder;
  return node;
}

function field(title, control) {
  const wrap = document.createElement('label');
  wrap.className = 'field';
  const caption = document.createElement('span');
  caption.textContent = title;
  wrap.append(caption, control);
  return wrap;
}

function note(textValue) {
  const node = document.createElement('p');
  node.className = 'muted';
  node.style.cssText = 'margin:-4px 0 6px;font-size:12.5px';
  node.textContent = textValue;
  return node;
}

function button(label, cls, onClick) {
  const node = document.createElement('button');
  node.className = cls;
  node.textContent = label;
  node.onclick = onClick;
  node.disabled = state.viewOnly && cls.includes('accent');
  return node;
}
