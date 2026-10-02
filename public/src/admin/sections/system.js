/* Раздел «Система»: версии, ключи, пути, место на диске, миграции, таблицы базы,
   рабочие папки без схемы, журнал ошибок сервера. Действия: миграции, уборка файлов, удаление папки-сироты. */

import { el, api, act, fmt, badge, confirmBox } from 'goblin/admin/core.js';
import { dataTable } from 'goblin/admin/table.js';
import { head, kv, panel, sub } from 'goblin/admin/widgets.js';
import { t } from 'goblin/core/i18n.js';

export async function render(root, route) {
  const d = await api('system');
  const gc = el('button', 'btn btn-quiet', t('admin.system.gc'));
  gc.title = t('admin.system.gc_title');
  gc.onclick = async () => { await act('files.gc'); route.refresh(); };
  const migrate = el('button', 'btn btn-accent', t('admin.system.migrate'));
  migrate.hidden = !d.migrations.waiting.length;
  migrate.onclick = async () => { await act('migrations.apply'); route.refresh(); };

  root.append(head(t('admin.nav.system'), t('admin.system.subtitle'), [migrate, gc]));

  const maxMb = Math.max(1, ...d.disk.map((x) => x.mb || 0));
  root.append(el('div', 'adm-cols', [
    panel(t('admin.system.versions'), kv(d.versions)),
    panel(t('admin.system.keys'), kv(Object.fromEntries(Object.entries(d.keys).map(([k, v]) => [k, v ? badge('done', t('admin.system.key_set')) : badge('failed', t('admin.system.key_missing'))])))),
    panel(t('admin.system.disk'), el('div', 'adm-rows', d.disk.map((x) => el('div', '', [
      el('span', 'grow', x.name), el('div', 'adm-bar', Object.assign(el('i'), { style: `width:${Math.round(((x.mb || 0) / maxMb) * 100)}%` })),
      el('span', 'adm-faint nowrap', fmt.mb(x.mb)),
    ])))),
  ]));
  // Бэкап кнопкой — для хостинга, где нет командной строки (lib/admin/backup.php).
  const backup = el('div', 'adm-set-foot', [
    Object.assign(el('a', 'btn btn-quiet', t('admin.backup.db')), { href: 'admin.php?download=db' }),
    Object.assign(el('a', 'btn btn-quiet', t('admin.backup.files')), { href: 'admin.php?download=files' }),
  ]);
  root.append(el('div', 'adm-cols', [
    panel(t('admin.backup.title'), [el('p', 'adm-muted', t('admin.backup.about')), backup]),
  ]));
  root.append(el('div', 'adm-cols', [
    panel(t('admin.system.paths'), kv(Object.fromEntries(Object.entries(d.paths).map(([k, v]) => [k, el('span', 'adm-mono', v || '—')])))),
    panel(t('admin.system.migrations'), el('div', 'adm-rows', d.migrations.list.map((m) => el('div', '', [
      el('span', 'grow adm-mono', m.name), m.applied ? badge('done', t('admin.system.applied')) : badge('failed', t('admin.system.waiting')),
    ]))), d.migrations.waiting.length ? t('admin.system.waiting_n', { n: d.migrations.waiting.length }) : t('admin.system.all_applied')),
  ]));
  root.append(sub(`${t('admin.system.orphans')} · ${d.orphans.length}`));
  root.append(d.orphans.length ? dataTable({
    name: 'orphans', compact: true, rows: d.orphans, sort: { key: 'changed', dir: 'desc' },
    columns: [
      { key: 'name', title: t('admin.system.orphan_folder'), cls: 'adm-mono' },
      { key: 'files', title: t('admin.w.files_n'), type: 'num' },
      { key: 'mb', title: t('admin.w.mb'), type: 'num', render: (x) => (x.mb ? fmt.num(x.mb) : x.files ? t('admin.system.tiny') : '0') },
      { key: 'changed', title: t('admin.system.changed'), type: 'date' },
      { key: 'drop', title: '', sort: false, render: (x) => {
        const b = el('button', 'btn btn-quiet btn-danger', t('common.delete'));
        b.onclick = async (event) => {
          event.stopPropagation();
          const ok = await confirmBox({
            title: t('admin.system.delete_title'), danger: true, ok: t('common.delete'),
            text: t('admin.system.delete_text', { name: x.name, files: x.files }),
          });
          if (!ok) return;
          await act('workdir.delete', { name: x.name });
          route.refresh();
        };
        return b;
      } },
    ],
  }) : el('p', 'adm-muted', t('admin.system.no_orphans')));

  root.append(sub(t('admin.system.tables')));
  root.append(dataTable({
    name: 'tables', rows: d.tables, sort: { key: 'mb', dir: 'desc' }, search: ['name'],
    columns: [
      { key: 'name', title: t('admin.system.table'), cls: 'adm-mono' },
      { key: 'rows', title: t('admin.system.rows'), type: 'num' },
      { key: 'mb', title: t('admin.w.mb'), type: 'num' },
    ],
  }));
  root.append(sub(`${t('admin.system.errors')} · ${d.errors.length}`));
  root.append(d.errors.length ? el('pre', 'adm-log', d.errors.join('\n')) : el('p', 'adm-muted', t('admin.system.no_errors')));
}
