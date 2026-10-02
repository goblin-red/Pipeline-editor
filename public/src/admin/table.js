/* Админка: таблица — сортировка по любому столбцу, поиск, фильтры-метки, страницы, выгрузка CSV.
   Отдаёт: dataTable().
   Не делает: не ходит на сервер — строки ей дают готовыми.

   Столбец: { key, title, type: 'text' | 'num' | 'date', value(row), render(row) → текст или узел,
   sort: false — не сортировать, cls }. Порядок сортировки помнится в браузере по имени таблицы. */

import { el, fmt } from 'goblin/admin/core.js';
import { t } from 'goblin/core/i18n.js';

const PAGE = 50;

/**
 * dataTable({ name, columns, rows, search: [ключи], filters: [{ label, test(row) }],
 *             sort: { key, dir }, onRow(row), empty, tools: [узлы], compact }) → узел
 * compact — без поиска и выгрузки, для списков внутри карточки.
 */
export function dataTable(options) {
  const { name, columns, rows, filters = [], onRow = null, empty = t('admin.w.empty') } = options;
  const saved = readSort(name);
  const state = {
    sort: saved || options.sort || { key: columns[0].key, dir: 'asc' },
    q: '', filter: 0, page: 1,
  };

  const box = el('div', 'adm-table-box' + (options.compact ? ' compact' : ''));
  const search = el('input', 'adm-search');
  search.type = 'search';
  search.placeholder = t('admin.table.search');
  const chips = el('div', 'adm-chips');
  const count = el('span', 'adm-count');
  const csv = el('button', 'btn btn-quiet', 'CSV');
  csv.title = t('admin.table.csv_title');
  const tools = el('div', 'adm-tools', [search, chips, ...(options.tools || []), count, csv]);
  const table = el('table', 'adm-table');
  const wrap = el('div', 'adm-table-wrap', table);
  const pager = el('div', 'adm-pager');
  box.append(tools, wrap, pager);

  const value = (col, row) => (col.value ? col.value(row) : row[col.key]);
  const text = (row) => (options.search || columns.map((c) => c.key))
    .map((key) => { const col = columns.find((c) => c.key === key); return col ? value(col, row) : row[key]; })
    .join(' ').toLowerCase();

  filters.forEach((filter, i) => {
    const chip = el('button', 'adm-chip' + (i === 0 ? ' on' : ''), [filter.label + ' ', el('b', '', String(rows.filter(filter.test).length))]);
    chip.onclick = () => {
      state.filter = i;
      state.page = 1;
      for (const one of chips.children) one.classList.toggle('on', one === chip);
      draw();
    };
    chips.append(chip);
  });

  search.oninput = () => { state.q = search.value.trim().toLowerCase(); state.page = 1; draw(); };

  function visible() {
    let list = rows;
    if (filters[state.filter]) list = list.filter(filters[state.filter].test);
    if (state.q) list = list.filter((row) => text(row).includes(state.q));
    const col = columns.find((c) => c.key === state.sort.key);
    if (col) {
      const dir = state.sort.dir === 'asc' ? 1 : -1;
      const pick = (row) => {
        const v = value(col, row);
        if (col.type === 'num') return v === null || v === undefined || v === '' ? -Infinity : Number(v);
        if (col.type === 'date') return fmt.time(v);
        return String(v ?? '').toLowerCase();
      };
      list = [...list].sort((a, b) => {
        const x = pick(a), y = pick(b);
        return (x < y ? -1 : x > y ? 1 : 0) * dir;
      });
    }
    return list;
  }

  function draw() {
    const list = visible();
    const pages = Math.max(1, Math.ceil(list.length / PAGE));
    state.page = Math.min(state.page, pages);
    const shown = list.slice((state.page - 1) * PAGE, state.page * PAGE);
    count.textContent = list.length === rows.length ? t('admin.table.count', { n: fmt.num(rows.length) }) : t('admin.table.count_of', { shown: fmt.num(list.length), total: fmt.num(rows.length) });

    const head = el('tr');
    for (const col of columns) {
      const th = el('th', [col.type === 'num' ? 'num' : '', col.sort === false ? '' : 'sort',
        state.sort.key === col.key ? state.sort.dir : ''].filter(Boolean).join(' '));
      th.append(col.title || '');
      if (col.sort !== false) {
        th.append(el('span', 'arrow', state.sort.key === col.key && state.sort.dir === 'asc' ? '▲' : '▼'));
        th.onclick = () => {
          state.sort = state.sort.key === col.key
            ? { key: col.key, dir: state.sort.dir === 'asc' ? 'desc' : 'asc' }
            : { key: col.key, dir: col.type === 'text' || !col.type ? 'asc' : 'desc' };
          writeSort(name, state.sort);
          draw();
        };
      }
      head.append(th);
    }
    const body = el('tbody');
    for (const row of shown) {
      const tr = el('tr', onRow ? 'click' : '');
      for (const col of columns) {
        const td = el('td', [col.type === 'num' ? 'num' : '', col.cls || ''].filter(Boolean).join(' '));
        const shownValue = col.render ? col.render(row) : value(col, row);
        if (shownValue instanceof Node) td.append(shownValue);
        else if (col.render) td.textContent = shownValue ?? '—';
        else td.textContent = col.type === 'num' ? fmt.num(shownValue) : col.type === 'date' ? fmt.date(shownValue) : (shownValue ?? '—');
        if (col.type === 'num' && !Number(value(col, row))) td.classList.add('zero');
        tr.append(td);
      }
      if (onRow) tr.onclick = () => onRow(row);
      body.append(tr);
    }
    if (!shown.length) {
      const td = el('td', 'empty', empty);
      td.colSpan = columns.length;
      body.append(el('tr', '', td));
    }
    table.replaceChildren(el('thead', '', head), body);

    pager.textContent = '';
    if (pages > 1) {
      const prev = el('button', 'btn btn-quiet', '‹');
      const next = el('button', 'btn btn-quiet', '›');
      prev.disabled = state.page <= 1;
      next.disabled = state.page >= pages;
      prev.onclick = () => { state.page--; draw(); };
      next.onclick = () => { state.page++; draw(); };
      pager.append(prev, el('span', '', t('admin.table.page', { page: state.page, pages })), next);
    }
  }

  csv.onclick = () => {
    const list = visible();
    const cells = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [columns.filter((c) => c.title).map((c) => cells(c.title)).join(';')]
      .concat(list.map((row) => columns.filter((c) => c.title).map((c) => cells(value(c, row))).join(';')));
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = el('a');
    a.href = URL.createObjectURL(blob);
    a.download = `goblin-${name}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  };

  draw();
  return box;
}

function readSort(name) {
  try { return JSON.parse(localStorage.getItem('goblin-admin-sort') || '{}')[name] || null; } catch { return null; }
}

function writeSort(name, sort) {
  try {
    const all = JSON.parse(localStorage.getItem('goblin-admin-sort') || '{}');
    all[name] = sort;
    localStorage.setItem('goblin-admin-sort', JSON.stringify(all));
  } catch {}
}
