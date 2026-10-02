/* Раздел «Обзор»: вся установка одним экраном — числа, активность, здоровье, живые прогоны. */

import { el, api, fmt, badge, editorUrl } from 'goblin/admin/core.js';
import { kpi, bars, panel, head, sub } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

export async function render(root, route) {
  const d = await api('overview');
  const k = d.kpi;
  const go = (hash) => () => { location.hash = hash; };
  const refresh = el('button', 'btn btn-quiet', t('admin.overview.refresh'));
  refresh.onclick = route.refresh;

  root.append(head(t('admin.nav.overview'), t('admin.overview.subtitle'), [refresh]));
  root.append(el('div', 'adm-kpis', [
    kpi(k.projects, t('admin.overview.k_projects'), t('admin.overview.k_projects_week', { n: fmt.num(k.projectsWeek) }), go('projects')),
    kpi(k.schemes, t('admin.overview.k_schemes'), t('admin.overview.k_elements', { n: fmt.num(k.elements) }), go('schemes')),
    kpi(k.runsLive, t('admin.overview.k_runs_live'), t('admin.overview.k_runs_total', { n: fmt.num(k.runs) }), go('runs'), k.runsLive > 0),
    kpi(k.users, t('admin.overview.k_users'), t('admin.overview.k_visitors', { n: fmt.num(k.visitors) }), go('people')),
    kpi(k.edits, t('admin.overview.k_edits'), t('admin.overview.k_edits_hint'), go('journal')),
    kpi(k.aiCalls, t('admin.overview.k_ai'), t('admin.overview.k_ai_tokens', { n: fmt.num(k.aiTokens) }), go('ai')),
    kpi(fmt.mb(k.dbMb), t('admin.overview.k_db'), t('admin.overview.k_db_hint'), go('system')),
  ]));

  // Платные модели: токены за всё время и остаток на счёте (DeepSeek и OpenRouter отдают его по API, Jev — нет).
  const ds = d.balance?.deepseek || {};
  const or = d.balance?.openrouter || {};
  root.append(sub(t('admin.overview.models')));
  root.append(el('div', 'adm-kpis', [
    kpi(k.dsTokens, t('admin.overview.k_ds_tokens'), t('admin.overview.k_calls', { n: fmt.num(k.dsCalls) }), go('ai')),
    kpi(k.jevTokens, t('admin.overview.k_jev_tokens'), t('admin.overview.k_calls_jev', { n: fmt.num(k.jevCalls) }), go('ai')),
    balance(ds, 'admin.overview.k_ds_balance', t(ds.available ? 'admin.overview.balance_live' : 'admin.overview.balance_off')),
    balance(or, 'admin.overview.k_or_balance', t('admin.overview.balance_live_or', { total: or.total, used: or.used })),
    kpi('—', t('admin.overview.k_jev_balance'), t('admin.overview.balance_jev')),
  ]));

  root.append(sub(t('admin.overview.activity')));
  root.append(el('div', 'adm-cols', [
    panel(t('admin.overview.scheme_edits'), bars(d.activity.edits)),
    panel(t('admin.w.runs'), bars(d.activity.runs)),
    panel(t('admin.w.ai_requests'), bars(d.activity.ai)),
  ]));

  root.append(sub(t('admin.w.now')));
  const health = el('div', 'adm-health', d.health.map((h) => el('div', h.level, [el('i'), el('span', '', h.say)])));
  const live = d.live.length
    ? el('div', 'adm-rows', d.live.map((r) => {
      const a = el('a', 'grow', `r${r.no} · ${r.project || r.url_key} / ${r.folder || '—'}`);
      a.href = '#runs/' + r.id;
      return el('div', '', [badge(r.state), a, el('span', 'adm-faint', fmt.ago(r.started_at))]);
    }))
    : el('p', 'adm-muted', t('admin.overview.no_live'));
  const recent = el('div', 'adm-rows', d.recent.map((p) => {
    const a = el('a', 'grow', p.title || p.url_key);
    a.href = '#projects/' + p.id;
    const open = el('a', 'adm-faint', '↗');
    open.href = editorUrl(p.url_key);
    open.target = '_blank';
    open.title = t('admin.overview.open_editor');
    return el('div', '', [a, el('span', 'adm-faint', p.owner || t('admin.w.guest')), el('span', 'adm-faint', fmt.ago(p.updated_at)), open]);
  }));
  root.append(el('div', 'adm-cols', [
    panel(t('admin.overview.health'), health),
    panel(t('admin.overview.live_runs'), live, d.live.length ? String(d.live.length) : ''),
    panel(t('admin.overview.recent'), recent),
  ]));
}

/** Плитка остатка на счёте: сумма и откуда она; меньше одного доллара — подсвечена. */
function balance(one, label, live) {
  const why = one.why === 'no_key' ? 'admin.overview.balance_no_key' : 'admin.overview.balance_silent';
  return kpi(one.amount ? `${one.amount} ${one.currency}` : '—', t(label), one.amount ? live : t(why),
    null, !!one.amount && Number(one.amount) < 1);
}
