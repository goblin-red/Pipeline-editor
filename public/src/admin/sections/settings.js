/* Раздел «Настройки»: всё, что раньше правили руками в config.php, secrets.php и config.txt, — карточками по группам.
   Отдаёт: render(root, route).
   Не делает: не проверяет значения — это сервер (lib/admin/settings.php); поле из переменной окружения
              заперто и не уходит на сервер. Ключи видны открыто — решение хозяина. */

import { el, api, act, toast, confirmBox } from 'goblin/admin/core.js';
import { head, panel } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const HINTS = {
  db: 'admin.settings.g.db_hint',
  paths: 'admin.settings.g.paths_hint',
  voice: 'admin.settings.g.voice_hint',
  jev: 'admin.settings.g.ai_hint',
  guests: 'admin.settings.g.guests_hint',
  files: 'admin.settings.g.files_hint',
};

export async function render(root) {
  const d = await api('settings');
  root.append(head(t('admin.nav.settings'), t('admin.settings.subtitle')));
  const cards = [...d.groups.map((group) => (group.key === 'ai' ? aiCard(group) : groupCard(group))), listsCard(d.lists)];
  root.append(el('div', 'adm-set-grid', cards.map((card) => foldable(card, card.dataset.group))));
}

/* ── Группа: поля, «Сохранить», у базы ещё «Проверить соединение» ── */

function groupCard(group) {
  const inputs = {};
  const rows = group.fields.map((field) => fieldRow(field, inputs));

  // Голос «браузер» — поля OpenAI не нужны: видны, только пока выбран openai. Значения уходят при сохранении как есть.
  const engine = rows.map((row) => row.querySelector('[data-key="voice_engine"]')).find(Boolean);
  if (engine) {
    const openai = rows.filter((row) => !row.contains(engine));
    const show = () => { for (const row of openai) row.hidden = engine.value !== 'openai'; };
    engine.addEventListener('change', show);
    show();
  }

  const save = el('button', 'btn btn-accent', t('admin.settings.save'));
  save.onclick = async () => {
    save.disabled = true;
    try {
      await act('settings.save', { group: group.key, values: valuesOf(inputs) });
      // Новый пароль ушёл хешем — поле пустеет.
      for (const { field, input } of Object.values(inputs)) if (field.type === 'password') input.value = '';
    } catch {
      // Причину показал act().
    }
    save.disabled = false;
  };
  const actions = [save];
  if (group.key === 'db') {
    const test = el('button', 'btn btn-quiet', t('admin.settings.test_db'));
    test.onclick = () => act('settings.testDb', { values: valuesOf(inputs) }).catch(() => {});
    actions.unshift(test);
  }

  const card = panel(t('admin.settings.g.' + group.key), [
    HINTS[group.key] ? el('p', 'adm-muted adm-set-hint', t(HINTS[group.key])) : null,
    el('div', 'adm-set-fields', rows),
    el('div', 'adm-set-foot', actions),
  ].filter(Boolean));
  card.dataset.group = group.key;
  return card;
}

/* ── Встроенный ИИ: рабочее подключение и список подключений; «Добавить» — своё ── */

const CONN_FIELDS = [['name', 'text'], ['url', 'text'], ['model', 'text'], ['timeout', 'int'], ['key', 'secret']];

function aiCard(group) {
  const [active, list] = group.fields;      // ai_connection, ai_connections
  const items = [];                         // { id, inputs, box }
  const boxes = el('div', 'adm-ai-list');

  const pick = el('select', 'input');
  const pickRow = el('div', 'adm-set-row', [
    el('span', 'adm-set-name', t('admin.settings.f.ai_connection')),
    el('div', 'adm-set-input', [pick]),
  ]);
  // Выпадашка — из названий подключений: переименовали или удалили — сразу видно.
  // Выбор помним отдельно: пока список строится, рабочего в нём ещё может не быть.
  let chosen = active.value;
  const refresh = () => {
    pick.replaceChildren(...items.map((item) => {
      const option = el('option', '', item.inputs.conn_name.input.value || item.id);
      option.value = item.id;
      return option;
    }));
    pick.value = items.some((item) => item.id === chosen) ? chosen : items[0]?.id || '';
    for (const item of items) item.sync();
  };
  pick.onchange = () => {
    chosen = pick.value;
    refresh();
  };

  const add = (one) => {
    const item = { id: one.id, inputs: {} };
    const rows = CONN_FIELDS.map(([key, type]) => fieldRow({ key: 'conn_' + key, type, value: one[key] ?? '' }, item.inputs));
    const drop = el('button', 'btn btn-quiet', t('admin.settings.conn_remove'));
    drop.onclick = () => {
      items.splice(items.indexOf(item), 1);
      item.box.remove();
      refresh();
    };
    const label = el('span');
    const note = el('small');
    item.sync = () => {
      label.textContent = item.inputs.conn_name.input.value || item.id;
      note.textContent = [item.inputs.conn_model.input.value, pick.value === item.id ? t('admin.settings.conn_active') : '']
        .filter(Boolean).join(' · ');
    };
    item.box = el('div', 'adm-ai-conn', [el('h3', '', [label, note]), el('div', 'adm-set-fields', rows),
      el('div', 'adm-set-foot', [drop])]);
    foldable(item.box, 'conn:' + item.id);
    item.inputs.conn_name.input.addEventListener('input', refresh);
    item.inputs.conn_model.input.addEventListener('input', item.sync);
    items.push(item);
    boxes.append(item.box);
    refresh();
  };
  for (const one of list.value || []) add(one);

  const more = el('button', 'btn btn-quiet adm-ai-add', t('admin.settings.conn_add'));
  more.onclick = () => {
    let n = 1;
    while (items.some((item) => item.id === 'my' + n)) n++;
    add({ id: 'my' + n, name: t('admin.settings.conn_new', { n }), timeout: 40 });
    items[items.length - 1].box.classList.add('open');
    items[items.length - 1].inputs.conn_name.input.focus();
  };

  const save = el('button', 'btn btn-accent', t('admin.settings.save'));
  save.onclick = async () => {
    save.disabled = true;
    const connections = items.map((item) => {
      const out = { id: item.id };
      for (const [key, { input }] of Object.entries(item.inputs)) out[key.slice(5)] = input.value;
      return out;
    });
    try {
      await act('settings.save', { group: group.key, values: { ai_connection: pick.value, ai_connections: connections } });
    } catch {
      // Причину показал act().
    }
    save.disabled = false;
  };

  const card = panel(t('admin.settings.g.ai'), [
    el('p', 'adm-muted adm-set-hint', t('admin.settings.g.ai_conn_hint')),
    el('div', 'adm-set-fields', [pickRow]),
    boxes,
    el('div', 'adm-set-foot', [more, save]),
  ]);
  card.dataset.group = group.key;
  card.classList.add('adm-set-wide');
  return card;
}

/* ── Раскрывающийся раздел: щелчок или Enter по заголовку; открытые помнит браузер ── */

const OPEN_KEY = 'goblin-admin-settings-open';

function opened() {
  try { return new Set(JSON.parse(localStorage.getItem(OPEN_KEY) || '[]')); } catch { return new Set(); }
}

function foldable(card, key) {
  const title = card.querySelector('h3');
  const set = (open) => {
    card.classList.toggle('open', open);
    title.setAttribute('aria-expanded', String(open));
  };
  const flip = () => {
    const open = !card.classList.contains('open');
    set(open);
    const all = opened();
    if (open) all.add(key); else all.delete(key);
    try { localStorage.setItem(OPEN_KEY, JSON.stringify([...all])); } catch { /* без памяти — тоже работает */ }
  };
  card.classList.add('adm-fold');
  title.tabIndex = 0;
  title.setAttribute('role', 'button');
  title.onclick = flip;
  title.onkeydown = (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    flip();
  };
  set(opened().has(key));
  return card;
}

/* ── Поле: подпись, ввод, «Показать» и «Копировать» у ключа, пояснение под ним ── */

function fieldRow(field, inputs) {
  const input = inputFor(field);
  input.dataset.key = field.key;
  input.disabled = !!field.env;
  if (!field.env) inputs[field.key] = { field, input };

  const line = el('div', 'adm-set-input', [input]);
  if (field.type === 'secret' && field.value) {
    // Ключ скрыт точками, пока не попросят показать (Б14).
    const show = el('button', 'btn btn-quiet', t('admin.settings.show'));
    show.onclick = () => {
      const hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      show.textContent = t(hidden ? 'admin.settings.hide' : 'admin.settings.show');
    };
    const copy = el('button', 'btn btn-quiet', t('admin.settings.copy'));
    copy.onclick = () => { navigator.clipboard?.writeText(input.value); toast(t('admin.settings.copied')); };
    line.append(show, copy);
  }

  const note = field.env ? t('admin.settings.env', { name: field.env })
    : field.type === 'password' ? t(field.set ? 'admin.settings.pass_set' : 'admin.settings.pass_none')
    : field.type === 'lines' ? t('admin.settings.lines_hint')
    : field.now ? t(field.now === 'remote' ? 'admin.settings.where_remote' : 'admin.settings.where_local') : '';

  return el('div', 'adm-set-row' + (field.env ? ' locked' : ''), [
    el('span', 'adm-set-name', t('admin.settings.f.' + field.key)),
    el('div', '', [line, note ? el('small', 'adm-faint', note) : null]),
  ]);
}

function inputFor(field) {
  if (field.options) {
    // Выбор из списка (движок голоса): подписи вариантов — admin.settings.o.<поле>.<значение>.
    const select = el('select', 'input');
    for (const value of field.options) {
      const option = el('option', '', t('admin.settings.o.' + field.key + '.' + value));
      option.value = value;
      select.append(option);
    }
    select.value = String(field.value ?? '');
    return select;
  }
  if (field.type === 'bool') {
    const box = el('input');
    box.type = 'checkbox';
    box.checked = !!field.value;
    return box;
  }
  if (field.type === 'lines') {
    const area = el('textarea', 'input adm-mono');
    area.value = (field.value || []).join('\n');
    area.rows = Math.max(2, (field.value || []).length + 1);
    area.spellcheck = false;
    area.wrap = 'off';                 // путь — одной строкой, длинный листается
    return area;
  }
  const input = el('input', 'input' + (field.type === 'text' ? '' : ' adm-mono'));
  const dots = field.type === 'password' || (field.type === 'secret' && field.value);
  input.type = dots ? 'password' : field.type === 'int' ? 'number' : 'text';
  input.value = field.type === 'password' ? '' : String(field.value ?? '');
  input.autocomplete = dots ? 'new-password' : 'off';
  input.spellcheck = false;
  return input;
}

/** Значения группы для сервера: по типу поля. */
function valuesOf(inputs) {
  const out = {};
  for (const [key, { field, input }] of Object.entries(inputs)) {
    out[key] = field.type === 'bool' ? input.checked
      : field.type === 'lines' ? input.value.split('\n')
      : input.value;
  }
  return out;
}

/* ── config.txt: текст целиком; проверяет сервер, пропавшие ключи — с подтверждением ── */

function listsCard(text) {
  const area = el('textarea', 'input adm-mono adm-set-lists');
  area.value = text;
  area.spellcheck = false;
  area.wrap = 'off';

  const problems = el('div', 'adm-set-problems');
  problems.hidden = true;

  const save = el('button', 'btn btn-accent', t('admin.settings.save'));
  save.onclick = async () => {
    save.disabled = true;
    try {
      await saveLists(area, problems, false);
    } catch {
      // Причину показал act().
    }
    save.disabled = false;
  };

  const card = panel(t('admin.settings.g.lists'), [
    el('p', 'adm-muted adm-set-hint', t('admin.settings.g.lists_hint')),
    area,
    problems,
    el('div', 'adm-set-foot', [save]),
  ]);
  card.dataset.group = 'lists';
  card.classList.add('adm-set-wide');
  return card;
}

/** Сохранить: строки с ошибками — списком под текстом; пропавшие ключи — только после «да». */
async function saveLists(area, problems, confirm) {
  const answer = await act('settings.lists', { text: area.value, confirm });
  problems.hidden = !answer.problems;
  if (answer.problems) {
    problems.replaceChildren(
      el('b', '', t('admin.settings.lists_problems')),
      ...answer.problems.map((one) => problemLink(area, one)),
    );
    toast(answer.problems[0].text, true);
    return;
  }
  if (answer.removed) {
    const yes = await confirmBox({
      title: t('admin.settings.removed_title'),
      text: t('admin.settings.removed_text', { keys: answer.removed.join(', ') }),
      ok: t('admin.settings.removed_ok'),
      danger: true,
    });
    if (yes) await saveLists(area, problems, true);
  }
}

/** Строка с ошибкой: клик выделяет её в тексте. */
function problemLink(area, one) {
  const link = el('button', 'adm-set-problem', one.text);
  link.type = 'button';
  link.onclick = () => selectLine(area, one.line);
  return link;
}

function selectLine(area, no) {
  const lines = area.value.split('\n');
  const start = lines.slice(0, no - 1).reduce((sum, line) => sum + line.length + 1, 0);
  area.focus();
  area.setSelectionRange(start, start + (lines[no - 1] || '').length);
  const height = parseFloat(getComputedStyle(area).lineHeight) || 20;
  area.scrollTop = Math.max(0, (no - 4) * height);
}
