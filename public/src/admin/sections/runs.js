/* Раздел «Прогоны»: все прогоны всех проектов — состояние, шаги, круги, длительность.
   Карточка: шаги по порядку с ответами и ошибками, события по видам, остановка живого. */

import { el, api, act, fmt, badge, editorUrl, outLink, confirmBox, remember } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, kv, sub, openDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const today = () => new Date(new Date().toDateString()).getTime();

export async function render(root, route) {
  const { rows } = await api('runs');
  root.append(head(t('admin.w.runs'), t('admin.runs.subtitle')));
  root.append(dataTable({
    name: 'runs',
    rows,
    sort: { key: 'started', dir: 'desc' },
    search: ['no', 'project', 'folder', 'key', 'lead'],
    columns: [
      { key: 'no', title: t('admin.w.run'), cls: 'adm-mono' },
      { key: 'project', title: t('admin.runs.project_scheme'), render: (r) => el('div', '', [el('div', '', r.folder || '—'), el('div', 'adm-faint', r.project || r.key)]) },
      { key: 'state', title: t('admin.w.state'), render: (r) => badge(r.state) },
      { key: 'accepted', title: t('admin.w.accepted'), type: 'num', render: (r) => `${r.accepted} / ${r.steps}` },
      { key: 'failed', title: t('admin.w.failed_n'), type: 'num' },
      { key: 'returned', title: t('admin.w.returns'), type: 'num' },
      { key: 'rounds', title: t('admin.w.rounds'), type: 'num' },
      { key: 'seconds', title: t('admin.w.duration'), type: 'num', render: (r) => fmt.dur(r.seconds) },
      { key: 'driver', title: t('admin.runs.led_by'), render: (r) => el('span', 'adm-faint', [r.driver, r.jev !== 'off' ? 'Jev' : ''].filter(Boolean).join(' · ')) },
      { key: 'started', title: t('admin.w.started'), type: 'date' },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.runs.f_live'), test: (r) => ['running', 'paused'].includes(r.state) },
      { label: t('admin.runs.f_done'), test: (r) => r.state === 'done' },
      { label: t('admin.runs.f_stopped'), test: (r) => r.state === 'stopped' },
      { label: t('admin.runs.f_failed'), test: (r) => r.state === 'failed' },
      { label: t('admin.runs.f_today'), test: (r) => fmt.time(r.started) >= today() },
    ],
    onRow: (r) => openRun(r.id, route),
  }));
  if (route.id) openRun(route.id, route);
}

async function openRun(id, route) {
  remember('runs', id);
  const d = await api('run', { id });
  const r = d.run;
  const live = ['running', 'paused'].includes(r.state);

  const stop = el('button', 'btn btn-danger', t('admin.runs.stop'));
  stop.hidden = !live;
  stop.onclick = async () => {
    const ok = await confirmBox({ title: t('admin.runs.stop_title', { no: r.no }), text: t('admin.runs.stop_text'), ok: t('admin.runs.stop_ok'), danger: true });
    if (!ok) return;
    await act('run.stop', { id });
    route.refresh();
  };

  const body = el('div', '', [
    el('div', 'adm-drawer-actions', [outLink(t('admin.runs.scheme_editor'), editorUrl(r.key, r.folderId)), stop]),
    kv({
      [t('admin.w.state')]: badge(r.state), [t('admin.runs.waiting_for')]: r.waitFor || undefined, [t('admin.w.project')]: r.project || r.key, [t('admin.w.scheme')]: r.folder || '—',
      [t('admin.w.steps_n')]: t('admin.runs.steps_value', { accepted: r.accepted, steps: r.steps }), [t('admin.w.failed_n')]: fmt.num(r.failed), [t('admin.w.returns')]: fmt.num(r.returned), [t('admin.w.rounds')]: fmt.num(r.rounds),
      [t('admin.w.started')]: fmt.date(r.started), [t('admin.runs.finished')]: r.finished ? fmt.date(r.finished) : '—', [t('admin.w.duration')]: fmt.dur(r.seconds),
      [t('admin.runs.led_by')]: [r.driver, r.lead].filter(Boolean).join(' · ') || '—', [t('admin.runs.judge')]: r.judge, 'Jev': r.jev,
    }),
    sub(`${t('admin.w.steps')} · ${d.steps.length}`),
    dataTable({
      name: 'run-steps', compact: true, rows: d.steps.map((s, i) => ({ ...s, n: i + 1 })), sort: { key: 'n', dir: 'asc' },
      columns: [
        { key: 'n', title: '№', type: 'num' },
        { key: 'element_title', title: t('admin.runs.step'), render: (s) => el('div', '', [el('div', '', `${s.element_no} · ${s.element_title || ''}`),
          s.result ? el('div', 'adm-faint wrap', s.result) : null, s.error ? el('div', 'adm-bad wrap', s.error) : null]) },
        { key: 'attempt', title: t('admin.runs.round'), type: 'num' },
        { key: 'state', title: t('admin.w.state'), render: (s) => badge(s.state) },
        { key: 'agent', title: t('admin.w.who'), render: (s) => el('span', 'adm-faint', s.agent || '—') },
        { key: 'seconds', title: t('admin.runs.time'), type: 'num', render: (s) => fmt.dur(s.seconds) },
      ],
      empty: t('admin.runs.no_steps'),
    }),
    sub(t('admin.runs.events')),
    el('div', 'adm-chips', d.kinds.map((k) => el('span', 'adm-chip', [k.kind + ' ', el('b', '', String(k.n))]))),
    el('div', 'adm-rows', d.events.slice(0, 60).map((e) => el('div', '', [
      el('span', 'adm-faint nowrap', fmt.date(e.at).slice(11)), el('span', 'adm-badge', e.kind),
      el('span', 'grow', `${e.element_no ? e.element_no + (e.attempt ? '.' + e.attempt : '') + ' · ' : ''}${e.title || ''}`),
      el('span', 'adm-faint', e.actor || ''),
    ]))),
  ]);
  openDrawer({ title: t('admin.runs.title', { no: r.no }), text: `${r.project || r.key} / ${r.folder || '—'}`, body, onClose: () => remember('runs') });
}
