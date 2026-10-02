/* Раздел «ИИ»: вызовы DeepSeek и Jev (журнал api_calls) — итог, по дням, по людям, все подряд с токенами;
   встроенный DeepSeek — задания по дням, по проектам, с ошибками, объём правил. Действие: закрыть зависшие задания. */

import { el, api, act, fmt, badge, editorUrl, outLink } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, kpi, bars, panel, sub } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

export async function render(root, route) {
  const d = await api('ai');
  const sweep = el('button', 'btn btn-quiet', t('admin.ai.sweep'));
  sweep.onclick = async () => { await act('ai.sweep'); route.refresh(); };
  const calls = d.series.reduce((s, x) => s + x.n, 0);
  const tokens = d.series.reduce((s, x) => s + x.tokens, 0);
  const failed = d.jobs.filter((j) => j.state === 'failed').length;

  root.append(head(t('admin.ai.title'), t('admin.ai.model', { model: d.model }) + (d.keySet ? '' : t('admin.ai.no_key')), [sweep]));
  root.append(el('div', 'adm-kpis', [
    kpi(calls, t('admin.ai.k_calls')), kpi(tokens, t('admin.ai.k_tokens')),
    kpi(calls ? Math.round(tokens / calls) : 0, t('admin.ai.k_avg')), kpi(failed, t('admin.ai.k_failed'), '', null, failed > 0),
    kpi(d.keySet ? t('admin.w.has') : t('admin.w.none'), t('admin.ai.k_key')),
  ]));
  drawCalls(root, d);

  root.append(sub(t('admin.ai.by_day')));
  root.append(el('div', 'adm-cols', [
    panel(t('admin.ai.requests'), bars(d.series)),
    panel(t('admin.ai.tokens'), bars(d.series.map((s) => ({ day: s.day, n: s.tokens })))),
  ]));
  root.append(sub(t('admin.ai.by_project')));
  root.append(dataTable({
    name: 'ai-projects', rows: d.byProject, sort: { key: 'last', dir: 'desc' }, search: ['title', 'key'],
    columns: [
      { key: 'title', title: t('admin.w.project'), render: (p) => el('div', '', [el('div', '', p.title || '—'), el('div', 'adm-mono adm-faint', p.key)]) },
      { key: 'calls', title: t('admin.w.requests_n'), type: 'num' },
      { key: 'tokens', title: t('admin.w.tokens_n'), type: 'num' },
      { key: 'failed', title: t('admin.ai.failures_n'), type: 'num' },
      { key: 'last', title: t('admin.w.when_last'), type: 'date', render: (p) => el('span', 'nowrap', fmt.ago(p.last)) },
      { key: 'open', title: '', sort: false, render: (p) => outLink('↗', editorUrl(p.key)) },
    ],
  }));
  root.append(sub(t('admin.ai.jobs')));
  root.append(dataTable({
    name: 'ai-jobs', rows: d.jobs, sort: { key: 'created_at', dir: 'desc' }, search: ['key', 'title', 'error', 'kind'],
    columns: [
      { key: 'created_at', title: t('admin.w.when'), type: 'date' },
      { key: 'key', title: t('admin.ai.project_chat'), render: (j) => el('div', '', [el('div', 'wrap', j.title || '—'), el('div', 'adm-mono adm-faint', j.key || '')]) },
      { key: 'kind', title: t('admin.w.kind'), render: (j) => ({ reply: t('admin.ai.kind_reply'), questionnaire: t('admin.ai.kind_questionnaire'), diagram: t('admin.ai.kind_diagram'), play: t('admin.ai.kind_play') }[j.kind] || j.kind) },
      { key: 'state', title: t('admin.ai.outcome'), render: (j) => badge(j.state) },
      { key: 'tokens_total', title: t('admin.w.tokens_n'), type: 'num' },
      { key: 'seconds', title: t('admin.ai.thinking'), type: 'num', render: (j) => fmt.dur(j.seconds) },
      { key: 'error', title: t('admin.ai.error'), render: (j) => el('span', 'adm-bad wrap', j.error || '') },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.ai.f_failed'), test: (j) => j.state === 'failed' },
      { label: t('admin.ai.f_proposal'), test: (j) => j.state === 'proposal' },
      { label: t('admin.ai.f_builder'), test: (j) => ['questionnaire', 'diagram'].includes(j.kind) },
    ],
  }));
  root.append(sub(t('admin.ai.rules')));
  root.append(el('div', 'adm-rows', [
    ...d.rules.map((r) => el('div', '', [el('span', 'grow', `${r.file} · ${r.name}`), el('span', 'adm-faint', t('admin.ai.chars', { n: fmt.num(r.chars) }))])),
  ]));
}

/* ── Вызовы DeepSeek и Jev: итог, по дням, по людям, все подряд (журнал api_calls) ── */

const WHAT = () => ({ reply: t('admin.ai.kind_reply'), questionnaire: t('admin.ai.kind_questionnaire'), diagram: t('admin.ai.kind_diagram'),
  play: t('admin.ai.kind_play'), step: t('admin.ai.what_step'), branch: t('admin.ai.what_branch') });
const SUMS = () => [
  { key: 'ds_calls', title: t('admin.ai.ds_calls'), type: 'num' },
  { key: 'ds_tokens', title: t('admin.ai.ds_tokens'), type: 'num' },
  { key: 'jev_calls', title: t('admin.ai.jev_calls'), type: 'num' },
  { key: 'jev_tokens', title: t('admin.ai.jev_tokens'), type: 'num' },
  { key: 'failed', title: t('admin.ai.failures_n'), type: 'num' },
];

function drawCalls(root, d) {
  const total = (key) => d.callDays.reduce((s, x) => s + Number(x[key] || 0), 0);
  root.append(sub(t('admin.ai.calls_title')));
  root.append(el('div', 'adm-kpis', [
    kpi(total('ds_calls'), t('admin.ai.ds_calls')), kpi(total('ds_tokens'), t('admin.ai.ds_tokens')),
    kpi(total('jev_calls'), t('admin.ai.jev_calls')), kpi(total('jev_tokens'), t('admin.ai.jev_tokens')),
  ]));

  root.append(sub(t('admin.ai.calls_by_day')));
  root.append(dataTable({
    name: 'ai-call-days', rows: d.callDays, sort: { key: 'day', dir: 'desc' },
    columns: [{ key: 'day', title: t('admin.ai.day'), render: (r) => el('span', 'nowrap', r.day) }, ...SUMS()],
  }));

  root.append(sub(t('admin.ai.calls_by_user')));
  root.append(dataTable({
    name: 'ai-call-users', rows: d.callUsers.map((r) => ({ ...r, who: r.id === null ? t('admin.ai.guest') : (r.name || r.email || '#' + r.id) })),
    sort: { key: 'last', dir: 'desc' }, search: ['who', 'email'],
    columns: [
      { key: 'who', title: t('admin.ai.person'), render: (r) => el('div', '', [el('div', '', r.who), r.email && r.name ? el('div', 'adm-mono adm-faint', r.email) : null]) },
      ...SUMS(),
      { key: 'last', title: t('admin.w.when_last'), type: 'date', render: (r) => el('span', 'nowrap', fmt.ago(r.last)) },
    ],
  }));

  root.append(sub(t('admin.ai.calls_all')));
  const what = WHAT();
  root.append(dataTable({
    name: 'ai-calls', sort: { key: 'created_at', dir: 'desc' }, search: ['who', 'key', 'title', 'model', 'error'],
    rows: d.calls.map((r) => ({ ...r, who: r.user_id === null ? t('admin.ai.guest') : (r.name || r.email || '#' + r.user_id),
      tokens: Number(r.tokens_in) + Number(r.tokens_out) })),
    columns: [
      { key: 'created_at', title: t('admin.w.when'), type: 'date' },
      { key: 'service', title: t('admin.ai.service'), render: (r) => el('b', '', r.service === 'jev' ? 'Jev' : 'DeepSeek') },
      { key: 'who', title: t('admin.ai.person') },
      { key: 'key', title: t('admin.w.project'), render: (r) => el('div', '', [el('div', 'wrap', r.title || '—'), el('div', 'adm-mono adm-faint', r.key || '')]) },
      { key: 'what', title: t('admin.w.kind'), render: (r) => what[r.what] || r.what || '—' },
      { key: 'model', title: t('admin.ai.model_col'), render: (r) => el('span', 'adm-mono', r.model || '—') },
      { key: 'tokens_in', title: t('admin.ai.tokens_in'), type: 'num' },
      { key: 'tokens_out', title: t('admin.ai.tokens_out'), type: 'num' },
      { key: 'ms', title: t('admin.ai.took'), type: 'num', render: (r) => fmt.dur(Number(r.ms) / 1000) },
      { key: 'error', title: t('admin.ai.outcome'), render: (r) => Number(r.ok) ? el('span', 'adm-faint', t('admin.ai.ok')) : el('span', 'adm-bad wrap', r.error || t('admin.ai.failed')) },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: 'DeepSeek', test: (r) => r.service === 'deepseek' },
      { label: 'Jev', test: (r) => r.service === 'jev' },
      { label: t('admin.ai.f_failed'), test: (r) => !Number(r.ok) },
    ],
  }));
}
