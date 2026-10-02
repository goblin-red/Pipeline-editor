/* Раздел «Журнал правок»: каждая пачка правок схем — когда, в каком проекте, кто и какие
   операции. Постранично с сервера (записей десятки тысяч), фильтры и поиск — тоже на сервере.
   Карточка записи: операции с аргументами, «было» и «стало». Уборка старых записей. */

import { el, api, act, fmt, confirmBox, remember } from 'goblin/admin/core.js';
import { head, kv, sub, openDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const filter = { q: '', via: '', op: '', days: '', page: 1 };

export async function render(root, route) {
  const prune = el('button', 'btn btn-quiet', t('admin.journal.prune'));
  prune.onclick = async () => {
    const days = await confirmBox({ title: t('admin.journal.prune_title'), text: t('admin.journal.prune_text'), input: { label: t('admin.journal.days'), value: '180', type: 'number' }, ok: t('common.delete'), danger: true });
    if (days === false) return;
    await act('journal.prune', { days: Number(days) });
    route.refresh();
  };
  root.append(head(t('admin.nav.journal'), t('admin.journal.subtitle'), [prune]));

  const search = el('input', 'adm-search');
  search.type = 'search';
  search.placeholder = t('admin.journal.search');
  search.value = filter.q;
  const via = el('select', 'input');
  const op = el('select', 'input');
  const days = el('select', 'input');
  for (const [value, text] of [['', t('admin.journal.d_all')], ['1', t('admin.journal.d_day')], ['7', t('admin.journal.d_week')], ['30', t('admin.journal.d_month')]]) days.append(new Option(text, value));
  days.value = filter.days;
  for (const select of [via, op, days]) select.style.width = 'auto';
  const count = el('span', 'adm-count');
  const list = el('div', 'adm-table-wrap');
  const pager = el('div', 'adm-pager');
  root.append(el('div', 'adm-tools', [search, via, op, days, count]), list, pager);

  let first = true;
  const load = async () => {
    const d = await api('journal', { q: filter.q, via: filter.via, op: filter.op, days: filter.days, page: filter.page, size: 50 });
    if (first) {
      via.append(new Option(t('admin.journal.any_who'), ''), ...d.vias.map((v) => new Option(v, v)));
      op.append(new Option(t('admin.journal.any_op'), ''), ...d.ops.map((v) => new Option(v, v)));
      via.value = filter.via;
      op.value = filter.op;
      first = false;
    }
    count.textContent = t('admin.journal.count', { n: fmt.num(d.total) });
    const table = el('table', 'adm-table');
    table.append(el('thead', '', el('tr', '', ['admin.w.when', 'admin.w.project', 'admin.w.who', 'admin.journal.operations', 'admin.w.label'].map((key) => el('th', '', t(key))))));
    const body = el('tbody');
    for (const j of d.rows) {
      const tr = el('tr', 'click', [
        el('td', 'nowrap', fmt.date(j.at)),
        el('td', '', el('div', '', [el('div', '', j.project || '—'), el('div', 'adm-mono adm-faint', j.key || '')])),
        el('td', '', el('span', j.who ? '' : 'adm-faint', j.who || j.via || '—')),
        el('td', 'wrap', `${j.ops || '—'}${j.n > 1 ? ` · ${j.n}` : ''}`),
        el('td', 'wrap adm-faint', j.label || ''),
      ]);
      tr.onclick = () => openEntry(j.id);
      body.append(tr);
    }
    if (!d.rows.length) body.append(el('tr', '', Object.assign(el('td', 'empty', t('admin.w.empty')), { colSpan: 5 })));
    table.append(body);
    list.replaceChildren(table);

    pager.textContent = '';
    const prev = el('button', 'btn btn-quiet', '‹');
    const next = el('button', 'btn btn-quiet', '›');
    prev.disabled = d.page <= 1;
    next.disabled = d.page >= d.pages;
    prev.onclick = () => { filter.page = d.page - 1; load(); };
    next.onclick = () => { filter.page = d.page + 1; load(); };
    pager.append(prev, el('span', '', t('admin.table.page', { page: d.page, pages: d.pages })), next);
  };

  let timer = null;
  search.oninput = () => { clearTimeout(timer); timer = setTimeout(() => { filter.q = search.value.trim(); filter.page = 1; load(); }, 300); };
  for (const [select, key] of [[via, 'via'], [op, 'op'], [days, 'days']]) {
    select.onchange = () => { filter[key] = select.value; filter.page = 1; load(); };
  }
  await load();
  if (route.id) openEntry(route.id);
}

async function openEntry(id) {
  remember('journal', id);
  const d = await api('entry', { id });
  const e = d.entry;
  const json = (value) => el('pre', 'adm-json', JSON.stringify(value, null, 2));
  const body = el('div', '', [
    kv({ [t('admin.w.when')]: fmt.date(e.created_at), [t('admin.w.project')]: e.url_key || '—', [t('admin.journal.via')]: e.via || '—', [t('admin.w.label')]: e.label || '—',
         [t('admin.journal.revision')]: String(e.rev), [t('admin.journal.repeat_key')]: e.operation_id || '—' }),
    ...d.ops.flatMap((o) => [
      sub(`${o.n}. ${o.op}${o.target_no ? t('admin.journal.element', { no: o.target_no }) : ''}`),
      o.args ? el('div', '', [el('div', 'adm-faint', t('admin.journal.args')), json(o.args)]) : null,
      o.prev ? el('div', '', [el('div', 'adm-faint', t('admin.journal.before')), json(o.prev)]) : null,
      o.result ? el('div', '', [el('div', 'adm-faint', t('admin.journal.after')), json(o.result)]) : null,
    ]).filter(Boolean),
  ]);
  openDrawer({ title: t('admin.journal.entry', { id: e.id }), text: e.url_key || '', body, onClose: () => remember('journal') });
}
