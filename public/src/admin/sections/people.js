/* Раздел «Люди»: пользователи (завести, имя и почта, пароль, сеансы, удалить) и посетители —
   кто заходил в какие проекты, с какого браузера и сколько раз. */

import { el, api, act, fmt, confirmBox, remember } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, kv, sub, openDrawer } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

const dayAgo = () => Date.now() - 86400000;

export async function render(root, route) {
  const d = await api('people');
  const add = el('button', 'btn btn-accent', t('admin.people.add'));
  add.onclick = () => createUser(route);

  root.append(head(t('admin.nav.people'), t('admin.people.subtitle'), [add]));
  root.append(sub(`${t('admin.people.users')} · ${d.users.length}`));
  root.append(dataTable({
    name: 'users',
    rows: d.users,
    sort: { key: 'lastSeen', dir: 'desc' },
    search: ['email', 'name'],
    columns: [
      { key: 'email', title: t('admin.w.email'), render: (u) => el('div', '', [el('div', '', u.email), u.name ? el('div', 'adm-faint', u.name) : null]) },
      { key: 'projects', title: t('admin.w.projects_n'), type: 'num' },
      { key: 'edits', title: t('admin.w.edits'), type: 'num' },
      { key: 'ai', title: t('admin.people.to_ai'), type: 'num' },
      { key: 'hits', title: t('admin.w.visits'), type: 'num' },
      { key: 'sessions', title: t('admin.people.sessions_n'), type: 'num' },
      { key: 'lastSeen', title: t('admin.people.last_seen'), type: 'date', render: (u) => el('span', 'nowrap', fmt.ago(u.lastSeen || u.lastLogin)) },
      { key: 'created', title: t('admin.w.created'), type: 'date' },
    ],
    onRow: (u) => openUser(u.id, route),
  }));

  root.append(sub(`${t('admin.people.visitors')} · ${d.visitors.length}`));
  root.append(dataTable({
    name: 'visitors',
    rows: d.visitors,
    sort: { key: 'last', dir: 'desc' },
    search: ['visitor', 'email', 'key', 'browser', 'ip'],
    columns: [
      { key: 'visitor', title: t('admin.people.visitor'), cls: 'adm-mono' },
      { key: 'email', title: t('admin.w.who'), render: (v) => el('span', v.email ? '' : 'adm-faint', v.email || t('admin.w.guest')) },
      { key: 'key', title: t('admin.w.project'), cls: 'adm-mono' },
      { key: 'hits', title: t('admin.w.visits'), type: 'num' },
      { key: 'browser', title: t('admin.people.browser') },
      { key: 'ip', title: 'IP', cls: 'adm-mono' },
      { key: 'first', title: t('admin.people.first'), type: 'date' },
      { key: 'last', title: t('admin.people.last_visit'), type: 'date', render: (v) => el('span', 'nowrap', fmt.ago(v.last)) },
    ],
    filters: [
      { label: t('admin.w.all'), test: () => true },
      { label: t('admin.w.guests'), test: (v) => !v.email },
      { label: t('admin.people.f_signed_in'), test: (v) => !!v.email },
      { label: t('admin.people.f_day'), test: (v) => fmt.time(v.last) > dayAgo() },
    ],
  }));
  if (route.id) openUser(route.id, route);
}

async function createUser(route) {
  const email = await confirmBox({ title: t('admin.people.new_user'), input: { label: t('admin.w.email'), placeholder: 'name@example.com' }, ok: t('admin.people.next') });
  if (!email) return;
  const name = await confirmBox({ title: t('admin.w.name'), input: { label: t('admin.people.name_hint') }, ok: t('admin.people.next') });
  if (name === false) return;
  const password = await confirmBox({ title: t('admin.w.password'), input: { label: t('admin.people.empty_generate'), type: 'text' }, ok: t('admin.people.create') });
  if (password === false) return;
  const done = await act('user.create', { email, name, password });
  await confirmBox({ title: t('admin.people.created_title'), text: t('admin.people.created_text', { email }), secret: done.password, ok: t('common.done'), cancel: null });
  route.refresh();
}

async function openUser(id, route) {
  remember('people', id);
  const d = await api('person', { id });
  const u = d.user;

  const email = el('input', 'input');
  email.value = u.email;
  const name = el('input', 'input');
  name.value = u.name;
  const save = el('button', 'btn btn-accent', t('common.save'));
  save.onclick = async () => { await act('user.update', { id, email: email.value, name: name.value }); route.refresh(); };

  const password = el('button', 'btn btn-quiet', t('admin.people.set_password'));
  password.onclick = async () => {
    const value = await confirmBox({ title: t('admin.people.new_password'), input: { label: t('admin.people.empty_generate') }, ok: t('admin.people.set') });
    if (value === false) return;
    const done = await act('user.password', { id, password: value });
    await confirmBox({ title: t('admin.people.password_set'), text: t('admin.people.password_set_text', { email: u.email }), secret: done.password, ok: t('common.done'), cancel: null });
  };
  const logout = el('button', 'btn btn-quiet', t('admin.people.end_sessions'));
  logout.onclick = async () => { await act('user.logout', { id }); route.refresh(); };
  const drop = el('button', 'btn btn-danger', t('common.delete'));
  drop.onclick = async () => {
    const ok = await confirmBox({ title: t('admin.people.delete_title'), text: t('admin.people.delete_text', { email: u.email, projects: u.projects }), ok: t('common.delete'), danger: true });
    if (!ok) return;
    await act('user.delete', { id });
    remember('people');
    route.refresh();
  };

  const body = el('div', '', [
    el('div', 'adm-drawer-actions', [password, logout, drop]),
    kv({
      [t('admin.w.created')]: fmt.date(u.created), [t('admin.people.last_login')]: fmt.date(u.lastLogin), [t('admin.people.last_editor')]: fmt.date(u.lastSeen),
      [t('admin.w.projects_n')]: fmt.num(u.projects), [t('admin.w.edits')]: fmt.num(u.edits), [t('admin.people.ai_requests')]: fmt.num(u.ai), [t('admin.w.visits')]: fmt.num(u.hits),
    }),
    sub(t('admin.people.details')),
    el('div', 'adm-form', [el('label', 'field', [el('span', '', t('admin.w.email')), email]), el('label', 'field', [el('span', '', t('admin.w.name')), name]), save]),
    sub(`${t('admin.w.projects')} · ${d.projects.length}`),
    d.projects.length
      ? el('div', 'adm-rows', d.projects.map((p) => {
        const a = el('a', 'grow', p.title || p.key);
        a.href = '#projects/' + p.id;
        return el('div', '', [a, el('span', 'adm-mono adm-faint', p.key), el('span', 'adm-faint', fmt.ago(p.updated_at))]);
      }))
      : el('p', 'adm-muted', t('admin.people.no_projects')),
    sub(`${t('admin.people.live_sessions')} · ${d.sessions.length}`),
    d.sessions.length
      ? el('div', 'adm-rows', d.sessions.map((s) => el('div', '', [el('span', 'adm-mono', '…' + s.tail),
        el('span', 'grow adm-faint', t('admin.people.session_created', { date: fmt.date(s.created_at) })), el('span', 'adm-faint', t('admin.people.session_used', { when: fmt.ago(s.last_used_at) }))])))
      : el('p', 'adm-muted', t('admin.people.no_sessions')),
  ]);
  openDrawer({ title: u.name || u.email, text: u.email, body, onClose: () => remember('people') });
}
