/* Раздел «Схемы»: все папки со схемами по всем проектам — состав, стартер, готовность
   к прогону (точная предпроверка сервера), прогоны. Карточка: помехи, элементы, прогоны,
   переименовать, удалить. */

import { el, api, act, fmt, badge, editorUrl, outLink, confirmBox, remember } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, kv, sub, openDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const ICON = { block: '▣', decision: '◆', gateway: '⬡' };

export async function render(root, route) {
  const { rows } = await api('schemes', { check: 1 });
  root.append(head(t('admin.w.schemes'), t('admin.schemes.subtitle')));
  root.append(dataTable({
    name: 'schemes',
    rows,
    sort: { key: 'updated', dir: 'desc' },
    search: ['name', 'project', 'key'],
    columns: [
      { key: 'name', title: t('admin.w.scheme'), render: (s) => el('div', '', [el('div', '', s.name || t('admin.w.unnamed')), el('div', 'adm-faint', s.project || s.key)]) },
      { key: 'blocks', title: t('admin.w.blocks'), type: 'num' },
      { key: 'decisions', title: t('admin.w.decisions'), type: 'num' },
      { key: 'frames', title: t('admin.schemes.frames'), type: 'num' },
      { key: 'links', title: t('admin.schemes.links'), type: 'num' },
      { key: 'starter', title: t('admin.w.starter'), value: (s) => (s.starter ? 1 : 0), render: (s) => (s.starter ? badge('accepted', t('admin.w.has')) : el('span', 'adm-faint', t('admin.w.none'))) },
      { key: 'problems', title: t('admin.schemes.to_run'), value: (s) => s.problems.length,
        render: (s) => (s.problems.length ? badge('returned', t('admin.schemes.problems_n', { n: s.problems.length })) : badge('done', t('admin.schemes.ready'))) },
      { key: 'runs', title: t('admin.w.runs_n'), type: 'num' },
      { key: 'lastRun', title: t('admin.schemes.last_run'), render: (s) => (s.lastRun ? badge(s.lastRun) : el('span', 'adm-faint', '—')) },
      { key: 'updated', title: t('admin.w.edited'), type: 'date', render: (s) => el('span', 'nowrap', fmt.ago(s.updated)) },
      { key: 'open', title: '', sort: false, render: (s) => outLink('↗', editorUrl(s.key, s.id)) },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.schemes.f_ready'), test: (s) => !s.problems.length },
      { label: t('admin.schemes.f_issues'), test: (s) => s.problems.length > 0 },
      { label: t('admin.schemes.f_no_starter'), test: (s) => !s.starter && s.blocks > 0 },
      { label: t('admin.schemes.f_never'), test: (s) => s.runs === 0 },
      { label: t('admin.w.empty_plural'), test: (s) => s.blocks + s.decisions === 0 },
    ],
    onRow: (s) => openScheme(s.id, route),
  }));
  if (route.id) openScheme(route.id, route);
}

async function openScheme(id, route) {
  remember('schemes', id);
  const d = await api('scheme', { id });
  const s = d.scheme;

  const rename = el('button', 'btn btn-quiet', t('admin.schemes.rename'));
  rename.onclick = async () => {
    const name = await confirmBox({ title: t('admin.schemes.rename_title'), input: { label: t('admin.w.name'), value: s.name }, ok: t('common.save') });
    if (name === false) return;
    await act('scheme.rename', { id, name });
    route.refresh();
  };
  const drop = el('button', 'btn btn-danger', t('admin.schemes.delete'));
  drop.onclick = async () => {
    const ok = await confirmBox({ title: t('admin.schemes.delete_title'), text: t('admin.schemes.delete_text', { name: s.name, project: s.project }), ok: t('common.delete'), danger: true });
    if (!ok) return;
    await act('scheme.delete', { id });
    remember('schemes');
    route.refresh();
  };

  const body = el('div', '', [
    el('div', 'adm-drawer-actions', [outLink(t('admin.w.open_editor'), editorUrl(s.key, s.id)), rename, drop]),
    kv({
      [t('admin.w.project')]: el('a', 'adm-link', s.project || s.key), [t('admin.schemes.work_folder')]: el('span', 'adm-mono', s.workDir || '—'),
      [t('admin.w.blocks')]: fmt.num(s.blocks), [t('admin.w.decisions')]: fmt.num(s.decisions), [t('admin.schemes.gateways')]: fmt.num(s.gateways), [t('admin.schemes.arrows')]: fmt.num(s.arrows),
      [t('admin.schemes.frames')]: fmt.num(s.frames), [t('admin.schemes.links')]: fmt.num(s.links), [t('admin.w.starter')]: s.starter ? t('admin.w.has') : t('admin.w.none'),
      [t('admin.w.created_f')]: fmt.date(s.created), [t('admin.w.edited')]: fmt.date(s.updated),
    }),
    sub(t('admin.schemes.readiness')),
    s.problems.length ? el('ul', 'adm-problems', s.problems.map((p) => el('li', '', p))) : el('p', '', [badge('done', t('admin.schemes.no_issues')), t('admin.schemes.precheck_clean')]),
    sub(`${t('admin.schemes.runnable')} · ${d.elements.length}`),
    d.elements.length
      ? el('div', 'adm-rows', d.elements.map((e) => el('div', '', [
        el('span', 'adm-mono adm-faint', String(e.no)), el('span', '', Number(e.starter) ? '◯' : ICON[e.type] || '•'),
        el('span', 'grow', e.title || '—'), e.agent ? el('span', 'adm-faint', e.agent) : null,
        Number(e.spec) ? badge('accepted', t('admin.schemes.spec'))
          : (e.type === 'block' && !Number(e.starter) && !Number(e.about) ? badge('failed', t('admin.schemes.no_spec')) : null),
      ])))
      : el('p', 'adm-muted', t('admin.w.empty')),
    sub(`${t('admin.w.runs')} · ${d.runs.length}`),
    dataTable({
      name: 'scheme-runs', compact: true, rows: d.runs, sort: { key: 'started', dir: 'desc' },
      columns: [
        { key: 'no', title: t('admin.w.run') },
        { key: 'state', title: t('admin.w.state'), render: (r) => badge(r.state) },
        { key: 'accepted', title: t('admin.w.accepted'), render: (r) => `${r.accepted} / ${r.steps}` },
        { key: 'seconds', title: t('admin.w.duration'), type: 'num', render: (r) => fmt.dur(r.seconds) },
        { key: 'started', title: t('admin.w.started'), type: 'date', render: (r) => el('span', 'nowrap', fmt.ago(r.started)) },
      ],
      onRow: (r) => { location.hash = 'runs/' + r.id; },
      empty: t('admin.w.no_runs'),
    }),
  ]);
  body.querySelector('.adm-kv a').onclick = () => { location.hash = 'projects/' + s.projectId; };
  openDrawer({ title: s.name || t('admin.w.unnamed'), text: s.project, body, onClose: () => remember('schemes') });
}
