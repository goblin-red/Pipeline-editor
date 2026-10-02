/* Раздел «Проекты»: все проекты установки и карточка проекта —
   название, владелец, настройки, схемы, прогоны, агенты, последние правки, удаление. */

import { el, api, act, fmt, badge, editorUrl, outLink, confirmBox, remember } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, kv, sub, openDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const weekAgo = () => Date.now() - 7 * 86400000;

export async function render(root, route) {
  const { rows } = await api('projects');
  root.append(head(t('admin.w.projects'), t('admin.projects.subtitle')));
  root.append(dataTable({
    name: 'projects',
    rows,
    sort: { key: 'updated', dir: 'desc' },
    search: ['key', 'title', 'owner'],
    columns: [
      { key: 'title', title: t('admin.w.project'), render: (p) => el('div', '', [el('div', '', p.title || t('admin.projects.untitled')), el('div', 'adm-mono adm-faint', p.key)]) },
      { key: 'owner', title: t('admin.w.owner'), render: (p) => el('span', p.owner ? '' : 'adm-faint', p.owner || t('admin.w.guest')) },
      { key: 'folders', title: t('admin.w.schemes_n'), type: 'num' },
      { key: 'elements', title: t('admin.w.elements'), type: 'num' },
      { key: 'runs', title: t('admin.w.runs_n'), type: 'num' },
      { key: 'live', title: t('admin.w.now'), render: (p) => (p.live ? badge('running') : el('span', 'adm-faint', '—')) },
      { key: 'chats', title: t('admin.w.chat_ai'), type: 'num' },
      { key: 'visitors', title: t('admin.projects.guests_n'), type: 'num' },
      { key: 'lastEdit', title: t('admin.w.last_edit'), type: 'date', render: (p) => el('span', 'nowrap', fmt.ago(p.lastEdit || p.updated)) },
      { key: 'created', title: t('admin.w.created'), type: 'date' },
      { key: 'open', title: '', sort: false, render: (p) => outLink('↗', editorUrl(p.key)) },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.projects.f_owned'), test: (p) => !!p.owner },
      { label: t('admin.projects.f_guest'), test: (p) => !p.owner },
      { label: t('admin.projects.f_live'), test: (p) => p.live > 0 },
      { label: t('admin.projects.f_week'), test: (p) => fmt.time(p.lastEdit || p.updated) > weekAgo() },
      { label: t('admin.w.empty_plural'), test: (p) => p.elements === 0 },
    ],
    onRow: (p) => openProject(p.id, route),
  }));
  if (route.id) openProject(route.id, route);
}

async function openProject(id, route) {
  remember('projects', id);
  const d = await api('project', { id });
  const p = d.project;

  // Название и владелец.
  const title = el('input', 'input');
  title.value = p.title;
  const owner = el('select', 'input');
  owner.append(new Option(t('admin.projects.no_owner'), ''));
  for (const u of d.users) owner.append(new Option(u.email, u.id));
  owner.value = p.ownerId || '';
  const save = el('button', 'btn btn-accent', t('common.save'));
  save.onclick = async () => {
    await act('project.update', { id, title: title.value, ownerId: owner.value ? Number(owner.value) : null });
    route.refresh();
  };

  // Настройки — по щелчку сразу.
  const flag = (key, text) => {
    const box = el('input');
    box.type = 'checkbox';
    box.checked = !!p[key];
    box.onchange = () => act('project.update', { id, [key]: box.checked }).catch(() => { box.checked = !box.checked; });
    return el('label', 'adm-switch', [box, el('span', '', text)]);
  };

  const drop = el('button', 'btn btn-danger', t('admin.projects.delete'));
  drop.onclick = async () => {
    const ok = await confirmBox({
      title: t('admin.projects.delete_title'),
      text: t('admin.projects.delete_text', { name: p.title || p.key, folders: p.folders, elements: p.elements, runs: p.runs })
        + (p.live ? t('admin.projects.delete_live', { n: p.live }) : '')
        + t('admin.projects.delete_warn'),
      ok: p.live ? t('admin.projects.stop_delete') : t('common.delete'), danger: true,
    });
    if (!ok) return;
    await act('project.delete', { id, stopRuns: p.live > 0 });
    remember('projects');
    route.refresh();
  };

  const body = el('div', '', [
    el('div', 'adm-drawer-actions', [outLink(t('admin.w.open_editor'), editorUrl(p.key)), drop]),
    kv({
      [t('admin.w.key')]: el('span', 'adm-mono', p.key), [t('admin.w.created')]: fmt.date(p.created), [t('admin.w.last_edit')]: fmt.date(p.lastEdit || p.updated),
      [t('admin.w.schemes_n')]: fmt.num(p.folders), [t('admin.w.elements')]: fmt.num(p.elements), [t('admin.w.runs_n')]: fmt.num(p.runs),
      [t('admin.projects.assets')]: fmt.num(p.assets), [t('admin.projects.agents_n')]: fmt.num(p.agents), [t('admin.w.chat_ai')]: fmt.num(p.chats), [t('admin.projects.guests_n')]: fmt.num(p.visitors),
    }),
    sub(t('admin.projects.settings')),
    el('div', 'adm-form', [el('label', 'field', [el('span', '', t('admin.w.title')), title]), el('label', 'field', [el('span', '', t('admin.w.owner')), owner]), save]),
    el('div', 'adm-rows', [flag('guestWrite', t('admin.projects.guest_write')), flag('aiConfirm', t('admin.projects.ai_confirm')),
      flag('strictChecks', t('admin.projects.strict'))]),
    sub(`${t('admin.w.schemes')} · ${d.schemes.length}`),
    dataTable({
      name: 'project-schemes', compact: true, rows: d.schemes, sort: { key: 'updated', dir: 'desc' },
      columns: [
        { key: 'name', title: t('admin.w.scheme') },
        { key: 'blocks', title: t('admin.w.blocks'), type: 'num' },
        { key: 'decisions', title: t('admin.w.decisions'), type: 'num' },
        { key: 'starter', title: t('admin.w.starter'), render: (s) => (s.starter ? badge('accepted', t('admin.w.has')) : el('span', 'adm-faint', t('admin.w.none'))) },
        { key: 'runs', title: t('admin.w.runs_n'), type: 'num' },
        { key: 'updated', title: t('admin.w.edited'), type: 'date', render: (s) => el('span', 'nowrap', fmt.ago(s.updated)) },
      ],
      onRow: (s) => { location.hash = 'schemes/' + s.id; },
    }),
    sub(`${t('admin.w.runs')} · ${d.runs.length}`),
    dataTable({
      name: 'project-runs', compact: true, rows: d.runs, sort: { key: 'started', dir: 'desc' },
      columns: [
        { key: 'no', title: t('admin.w.run') },
        { key: 'folder', title: t('admin.w.scheme') },
        { key: 'state', title: t('admin.w.state'), render: (r) => badge(r.state) },
        { key: 'accepted', title: t('admin.w.accepted'), type: 'num', render: (r) => `${r.accepted} / ${r.steps}` },
        { key: 'started', title: t('admin.w.started'), type: 'date', render: (r) => el('span', 'nowrap', fmt.ago(r.started)) },
      ],
      onRow: (r) => { location.hash = 'runs/' + r.id; },
      empty: t('admin.w.no_runs'),
    }),
    sub(`${t('admin.w.agents')} · ${d.agents.length}`),
    d.agents.length
      ? el('div', 'adm-rows', d.agents.map((a) => el('div', '', [el('span', 'grow', a.name), el('span', 'adm-faint', a.role),
        el('span', 'adm-faint', [a.cli, a.model].filter(Boolean).join(' · ') || '—')])))
      : el('p', 'adm-muted', t('admin.projects.no_agents')),
    sub(t('admin.projects.recent_edits')),
    d.journal.length
      ? el('div', 'adm-rows', d.journal.map((j) => el('div', '', [el('span', 'adm-faint nowrap', fmt.ago(j.at)),
        el('span', 'grow', j.ops || j.label || '—'), el('span', 'adm-faint', j.who || j.via || '')])))
      : el('p', 'adm-muted', t('admin.projects.no_edits')),
  ]);

  openDrawer({ title: p.title || p.key, text: p.owner ? t('admin.projects.owner_is', { owner: p.owner }) : t('admin.projects.guest_project'), body, onClose: () => remember('projects') });
}
