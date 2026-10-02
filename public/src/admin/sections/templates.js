/* Раздел «Каталог схем»: разделы с паспортами и схемы каталога.
   Паспорт — знание о схеме для человека и для конструктора: из паспортов сервер собирает
   «Опыт каталога» (lib/templates/catalog.php). Схема попадает в окно «Новая схема» только
   с отметкой «В каталоге». Снять схему с папки или обновить её тело — здесь же. */

import { el, api, act, fmt, badge, confirmBox, remember } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, sub, openDrawer, closeDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

/* Поля паспорта: [ключ, подпись, список ли]. Список правится по строке на пункт. */
const TEMPLATE_PASSPORT = [
  ['for', t('admin.templates.p_for'), false], ['input', t('admin.templates.p_input'), false], ['output', t('admin.templates.p_output'), false],
  ['steps', t('admin.w.steps'), true], ['services', t('admin.templates.p_services'), true],
  ['tune', t('admin.w.tune'), true], ['questions', t('admin.templates.p_questions'), true],
  ['pitfalls', t('admin.templates.p_pitfalls_runs'), true], ['tags', t('admin.templates.p_tags'), true],
];
const CATEGORY_PASSPORT = [
  ['skeleton', t('admin.templates.p_skeleton'), false], ['questions', t('admin.templates.p_ask'), true],
  ['tune', t('admin.w.tune'), true], ['pitfalls', t('admin.w.pitfalls'), true],
];

export async function render(root, route) {
  const d = await api('templates');
  const add = el('button', 'btn btn-accent', t('admin.templates.add'));
  add.onclick = () => fromFolder(d.categories, route);

  root.append(head(t('admin.nav.templates'), t('admin.templates.subtitle'), [add]));

  root.append(sub(`${t('admin.templates.categories')} · ${d.categories.length}`));
  root.append(dataTable({
    name: 'catalog-categories', compact: true, rows: d.categories, sort: { key: 'sort', dir: 'asc' },
    columns: [
      { key: 'title', title: t('admin.w.category'), render: (c) => el('div', '', [el('div', '', c.title), el('div', 'adm-faint', c.about)]) },
      { key: 'key', title: t('admin.w.key'), render: (c) => el('span', 'adm-mono adm-faint', c.key) },
      { key: 'count', title: t('admin.w.schemes_n'), type: 'num' },
      { key: 'passport', title: t('admin.w.passport'), value: (c) => Object.keys(c.passport).length,
        render: (c) => el('span', Object.keys(c.passport).length ? '' : 'adm-faint', Object.keys(c.passport).length ? t('admin.templates.filled') : t('admin.templates.blank')) },
      { key: 'sort', title: t('admin.w.order'), type: 'num' },
      { key: 'en', title: t('admin.templates.en_col'), value: (c) => (c.en.title ? 1 : 0), render: (c) => enMark(c.en.title ? 2 : 0) },
    ],
    onRow: (c) => editCategory(c, route),
  }));

  const title = (key) => d.categories.find((c) => c.key === key)?.title || key || '—';
  root.append(sub(`${t('admin.w.schemes')} · ${d.rows.length}`));
  root.append(dataTable({
    name: 'catalog-templates', rows: d.rows, sort: { key: 'category', dir: 'asc' },
    search: ['title', 'key', 'category', 'about'],
    columns: [
      { key: 'title', title: t('admin.w.scheme'), render: (tpl) => el('div', '', [el('div', '', tpl.title), el('div', 'adm-mono adm-faint', tpl.key)]) },
      { key: 'category', title: t('admin.w.category'), render: (tpl) => el('span', '', title(tpl.category)) },
      { key: 'inCatalog', title: t('admin.templates.catalog'), value: (tpl) => (tpl.inCatalog ? 1 : 0),
        render: (tpl) => (tpl.inCatalog ? badge('accepted', t('admin.w.in_catalog')) : badge('', t('admin.w.hidden'))) },
      { key: 'checked', title: t('admin.templates.checked'), type: 'date', render: (tpl) => el('span', tpl.checked ? '' : 'adm-faint', tpl.checked ? fmt.date(tpl.checked) : t('admin.w.none')) },
      { key: 'steps', title: t('admin.w.steps_n'), type: 'num' },
      { key: 'en', title: t('admin.templates.en_col'), value: (tpl) => enScore(tpl), render: (tpl) => enMark(enScore(tpl)) },
      { key: 'updated', title: t('admin.w.edited'), type: 'date' },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.templates.f_in_catalog'), test: (tpl) => tpl.inCatalog },
      { label: t('admin.templates.f_hidden'), test: (tpl) => !tpl.inCatalog },
    ],
    onRow: (tpl) => editTemplate(tpl, d.categories, route),
  }));
  if (route.id) {
    const open = d.rows.find((tpl) => String(tpl.id) === String(route.id));
    if (open) editTemplate(open, d.categories, route);
  }
}

/* ── Схема ────────────────────────────────────────────────────── */

function editTemplate(tpl, categories, route) {
  remember('templates', tpl.id);
  const holder = el('div');
  const draw = (lang) => holder.replaceChildren(langSwitch(lang, draw), lang === 'ru' ? ruTemplate(tpl, categories, route) : enTemplate(tpl, route, lang));
  draw('ru');
  openDrawer({ title: tpl.title, text: tpl.inCatalog ? t('admin.w.in_catalog') : t('admin.w.hidden'), body: holder, onClose: () => remember('templates') });
}

/** Переключатель языка правки: Русский — основные поля, English — перевод в i18n.en. */
function langSwitch(lang, draw) {
  const row = el('div', 'adm-chips');
  for (const [code, name] of [['ru', 'Русский'], ['en', 'English']]) {
    const chip = el('button', 'adm-chip' + (lang === code ? ' on' : ''), name);
    chip.onclick = () => draw(code);
    row.append(chip);
  }
  return row;
}

/** Есть ли английская версия схемы: 0 — нет, 1 — часть (название или тело), 2 — и название, и тело. */
function enScore(tpl) {
  return (tpl.en.title ? 1 : 0) + (tpl.en.hasBody ? 1 : 0);
}

function enMark(score) {
  if (score >= 2) return badge('accepted', t('admin.templates.en_full'));
  return score ? badge('', t('admin.templates.en_part')) : el('span', 'adm-faint', t('admin.w.none'));
}

/** Английская версия схемы: название, описание, паспорт и тело — в i18n.en. */
function enTemplate(tpl, route, lang) {
  const title = input(tpl.en.title);
  title.placeholder = tpl.title;
  const about = area(tpl.en.about, 2);
  about.placeholder = tpl.about;
  const passport = passportForm(TEMPLATE_PASSPORT, tpl.en.passport);
  const save = el('button', 'btn btn-accent', t('common.save'));
  save.onclick = () => act('template.update', { id: tpl.id, lang, title: title.value, about: about.value, passport: passport.read() })
    .then(() => route.refresh());

  const folder = input('');
  folder.placeholder = t('admin.w.folder_no');
  const refresh = el('button', 'btn btn-quiet', t('admin.templates.refresh_body'));
  refresh.onclick = async () => {
    if (!folder.value.trim()) { folder.focus(); return; }
    const ok = await confirmBox({ title: t('admin.templates.replace_title'), text: t('admin.templates.replace_text_lang'), ok: t('admin.templates.replace') });
    if (!ok) return;
    await act('template.fromFolder', { id: tpl.id, folder: Number(folder.value), lang });
    route.refresh();
  };

  return el('div', '', [
    el('p', 'adm-faint', t('admin.templates.lang_hint')),
    field(t('admin.w.title'), title),
    field(t('admin.w.about_short'), about),
    el('div', 'adm-drawer-actions', [save]),
    sub(t('admin.w.passport')),
    passport.node,
    el('div', 'adm-drawer-actions', [(() => { const b = el('button', 'btn btn-accent', t('admin.templates.save_passport')); b.onclick = save.onclick; return b; })()]),
    sub(t('admin.templates.body')),
    el('p', 'adm-faint', tpl.en.hasBody ? t('admin.templates.en_body_yes') : t('admin.templates.en_body_no')),
    el('div', 'adm-form', [field(t('admin.templates.source_folder'), folder), refresh]),
  ]);
}

function ruTemplate(tpl, categories, route) {
  const title = input(tpl.title);
  const about = area(tpl.about, 2);
  const category = select(categories, tpl.category);
  const sort = input(String(tpl.sort));
  sort.type = 'number';
  const shown = el('input');
  shown.type = 'checkbox';
  shown.checked = tpl.inCatalog;
  const passport = passportForm(TEMPLATE_PASSPORT, tpl.passport);

  const save = (extra = {}) => act('template.update', {
    id: tpl.id, title: title.value, about: about.value, category: category.value, sort: Number(sort.value) || 0,
    inCatalog: shown.checked, passport: passport.read(), ...extra,
  }).then(() => route.refresh());

  const saveBtn = el('button', 'btn btn-accent', t('common.save'));
  saveBtn.onclick = () => save();
  const checkBtn = el('button', 'btn btn-quiet', tpl.checked ? t('admin.templates.checked_again') : t('admin.templates.mark_checked'));
  checkBtn.onclick = () => save({ checked: 'today' });
  const uncheck = el('button', 'btn btn-quiet', t('admin.templates.clear_check'));
  uncheck.onclick = () => save({ checked: 'clear' });

  const folder = input('');
  folder.placeholder = t('admin.w.folder_no');
  const refresh = el('button', 'btn btn-quiet', t('admin.templates.refresh_body'));
  refresh.onclick = async () => {
    if (!folder.value.trim()) { folder.focus(); return; }
    const ok = await confirmBox({ title: t('admin.templates.replace_title'), text: t('admin.templates.replace_text'), ok: t('admin.templates.replace') });
    if (!ok) return;
    await act('template.fromFolder', { id: tpl.id, folder: Number(folder.value) });
    route.refresh();
  };
  const drop = el('button', 'btn btn-danger', t('common.delete'));
  drop.onclick = async () => {
    const ok = await confirmBox({ title: t('admin.templates.delete_title'), text: t('admin.templates.delete_text', { title: tpl.title }), ok: t('common.delete'), danger: true });
    if (!ok) return;
    await act('template.delete', { id: tpl.id });
    closeDrawer(true);
    remember('templates');
    route.refresh();
  };

  return el('div', '', [
    el('div', 'adm-form', [field(t('admin.w.title'), title), field(t('admin.w.category'), category), field(t('admin.w.order'), sort)]),
    field(t('admin.w.about_short'), about),
    el('label', 'adm-switch', [shown, el('span', '', t('admin.templates.in_catalog_switch'))]),
    el('div', 'adm-drawer-actions', [saveBtn, checkBtn, tpl.checked ? uncheck : null]),
    sub(t('admin.w.passport')),
    passport.node,
    el('div', 'adm-drawer-actions', [(() => { const b = el('button', 'btn btn-accent', t('admin.templates.save_passport')); b.onclick = () => save(); return b; })()]),
    sub(t('admin.templates.body')),
    el('p', 'adm-faint', t('admin.templates.body_info', { steps: tpl.steps, kb: fmt.num(Math.round(tpl.size / 1024)), key: tpl.key })),
    el('div', 'adm-form', [field(t('admin.templates.source_folder'), folder), refresh]),
    el('div', 'adm-drawer-actions', [drop]),
  ]);
}

/** Новая схема из папки: снимается скрытой — паспорт заполнить, проверить, включить в каталог. */
function fromFolder(categories, route) {
  const folder = input('');
  folder.placeholder = t('admin.w.folder_no');
  const title = input('');
  title.placeholder = t('admin.templates.by_folder_name');
  const key = input('');
  key.placeholder = t('admin.templates.by_title');
  const category = select(categories, categories[0]?.key);
  const about = area('', 2);
  const make = el('button', 'btn btn-accent', t('admin.templates.take'));
  make.onclick = async () => {
    if (!folder.value.trim()) { folder.focus(); return; }
    const answer = await act('template.fromFolder', { folder: Number(folder.value), title: title.value, key: key.value,
      category: category.value, about: about.value });
    closeDrawer(true);
    if (answer?.id) remember('templates', answer.id);
    route.refresh();
  };
  openDrawer({
    title: t('admin.templates.take_title'), text: t('admin.templates.take_text'),
    body: el('div', '', [el('div', 'adm-form', [field(t('admin.templates.folder'), folder), field(t('admin.w.title'), title), field(t('admin.w.key'), key),
      field(t('admin.w.category'), category)]), field(t('admin.w.about_short'), about), el('div', 'adm-drawer-actions', [make])]),
  });
}

/* ── Раздел ───────────────────────────────────────────────────── */

function editCategory(c, route) {
  const holder = el('div');
  const draw = (lang) => holder.replaceChildren(langSwitch(lang, draw), lang === 'ru' ? ruCategory(c, route) : enCategory(c, route, lang));
  draw('ru');
  openDrawer({ title: c.title, text: t('admin.templates.category_text', { key: c.key, count: c.count }), body: holder });
}

/** Английская версия раздела: название, описание и паспорт — в i18n.en. */
function enCategory(c, route, lang) {
  const title = input(c.en.title);
  title.placeholder = c.title;
  const about = area(c.en.about, 2);
  about.placeholder = c.about;
  const passport = passportForm(CATEGORY_PASSPORT, c.en.passport);
  const save = el('button', 'btn btn-accent', t('common.save'));
  save.onclick = async () => {
    await act('category.save', { key: c.key, lang, title: title.value, about: about.value, passport: passport.read() });
    route.refresh();
  };
  return el('div', '', [el('p', 'adm-faint', t('admin.templates.lang_hint')), field(t('admin.w.title'), title), field(t('admin.templates.description'), about),
    sub(t('admin.templates.category_passport')), passport.node, el('div', 'adm-drawer-actions', [save])]);
}

function ruCategory(c, route) {
  const title = input(c.title);
  const about = area(c.about, 2);
  const sort = input(String(c.sort));
  sort.type = 'number';
  const passport = passportForm(CATEGORY_PASSPORT, c.passport);
  const save = el('button', 'btn btn-accent', t('common.save'));
  save.onclick = async () => {
    await act('category.save', { key: c.key, title: title.value, about: about.value, sort: Number(sort.value) || 0, passport: passport.read() });
    route.refresh();
  };
  return el('div', '', [el('div', 'adm-form', [field(t('admin.w.title'), title), field(t('admin.w.order'), sort)]), field(t('admin.templates.description'), about),
    sub(t('admin.templates.category_passport')), el('p', 'adm-faint', t('admin.templates.category_hint')),
    passport.node, el('div', 'adm-drawer-actions', [save])]);
}

/* ── Мелочи ───────────────────────────────────────────────────── */

/** Форма паспорта: поле на каждый ключ; список — строка на пункт. read() отдаёт объект. */
function passportForm(fields, value) {
  const inputs = fields.map(([name, label, list]) => {
    const current = value?.[name];
    const box = list ? area(Array.isArray(current) ? current.join('\n') : current || '', 3) : area(current || '', 2);
    return { name, list, box, row: field(label + (list ? t('admin.templates.per_line') : ''), box) };
  });
  return {
    node: el('div', 'adm-passport', inputs.map((one) => one.row)),
    read: () => Object.fromEntries(inputs.map(({ name, list, box }) => [name,
      list ? box.value.split('\n').map((line) => line.trim()).filter(Boolean) : box.value.trim()])),
  };
}

function field(label, control) {
  return el('label', 'field', [el('span', '', label), control]);
}

function input(value) {
  const node = el('input', 'input');
  node.value = value ?? '';
  return node;
}

function area(value, rows) {
  const node = el('textarea', 'input');
  node.rows = rows;
  node.value = value ?? '';
  return node;
}

function select(categories, value) {
  const node = el('select', 'input');
  for (const c of categories) node.append(new Option(c.title, c.key));
  node.value = value || categories[0]?.key || '';
  return node;
}
