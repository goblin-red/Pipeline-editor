/* Раздел «Пропуска»: сеансы людей, пропуска проектов, прогонов, шагов и агентов —
   живые, истёкшие, отозванные. Отозвать по щелчку, убрать старые. */

import { el, api, act, fmt, badge, confirmBox, tokenState } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const SCOPES = {
  session: t('admin.tokens.s_session'), admin: t('admin.tokens.s_admin'), project: t('admin.tokens.s_project'),
  run: 'leader', step: t('admin.tokens.s_step'), agent: t('admin.tokens.s_agent'),
};

export async function render(root, route) {
  const { rows } = await api('tokens');
  const cleanup = el('button', 'btn btn-quiet', t('admin.tokens.cleanup'));
  cleanup.title = t('admin.tokens.cleanup_title');
  cleanup.onclick = async () => { await act('tokens.cleanup'); route.refresh(); };
  root.append(head(t('admin.nav.tokens'), t('admin.tokens.subtitle'), [cleanup]));
  root.append(dataTable({
    name: 'tokens', rows, sort: { key: 'created', dir: 'desc' }, search: ['label', 'key', 'email', 'tail', 'scope'],
    columns: [
      { key: 'scope', title: t('admin.w.kind'), render: (tk) => SCOPES[tk.scope] || tk.scope },
      { key: 'label', title: t('admin.w.label'), render: (tk) => el('span', 'wrap', tk.label || '—') },
      { key: 'tail', title: t('admin.tokens.tail'), render: (tk) => el('span', 'adm-mono', '…' + tk.tail) },
      { key: 'key', title: t('admin.w.project'), cls: 'adm-mono' },
      { key: 'email', title: t('admin.w.who'), render: (tk) => el('span', tk.email ? '' : 'adm-faint', tk.email || '—') },
      { key: 'state', title: t('admin.w.state'), render: (tk) => badge(tokenState(tk.state)) },
      { key: 'used', title: t('admin.tokens.used'), type: 'date', render: (tk) => el('span', 'nowrap', fmt.ago(tk.used)) },
      { key: 'created', title: t('admin.tokens.issued'), type: 'date' },
      { key: 'expires', title: t('admin.tokens.expires'), type: 'date' },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.tokens.f_live'), test: (tk) => tokenState(tk.state) === 'live' },
      { label: t('admin.tokens.f_sessions'), test: (tk) => tk.scope === 'session' },
      { label: t('admin.w.projects'), test: (tk) => tk.scope === 'project' },
      { label: t('admin.tokens.f_runs_steps'), test: (tk) => ['run', 'step'].includes(tk.scope) },
      { label: t('admin.w.agents'), test: (tk) => tk.scope === 'agent' },
      { label: t('admin.tokens.f_revoked'), test: (tk) => tokenState(tk.state) === 'revoked' },
    ],
    onRow: async (tk) => {
      if (tokenState(tk.state) !== 'live') return;
      const ok = await confirmBox({ title: t('admin.tokens.revoke_title'), text: `${SCOPES[tk.scope] || tk.scope} …${tk.tail}${tk.label ? ' · ' + tk.label : ''}`, ok: t('admin.tokens.revoke'), danger: true });
      if (!ok) return;
      await act('token.revoke', { id: tk.id });
      route.refresh();
    },
  }));
}
