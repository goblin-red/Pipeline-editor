# Goblin: backup and restore

This file describes the backup mechanism that is actually in the current code (`cmdBackup()` in `bin/server.php`).
Other documents: [backend](backend.md) · [frontend](frontend.md) · [run](progon.md) · [file map](structure.md).

The project is not under git: a snapshot is the only way to go back. **A snapshot is made only when
a person orders it.** The agent never makes snapshots on its own, even before a big rework: it may suggest one,
but it does not run it without the person's word.
Commands are run from the project root:

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/goblin
/Applications/XAMPP/xamppfiles/bin/php bin/server.php backup manual
```

Instead of `manual` you can give a short label. The script creates the folder
`backup/YYYY-MM-DD_HH-MM_<label>/` and does not overwrite an existing folder.

## What goes into a snapshot

- `project/` — a copy of the source code, configs without secrets, SQL schemas, migrations and documentation;
- `db/<database-name>.sql` — a dump of the MariaDB tables and data made with `mariadb-dump`;
- `ОПИСЬ.txt` — the date, label, size, number of files and the basic restore commands.

Only the root `/backup/`, `/workspace/`, `/data/files/`, `/archive/`, as well as `.DS_Store` and `secrets.php`,
are left out of the project copy. **The work folders `public/workfiles/` are included in full** — `in/`,
`out/rN/`, `service/tasks/` (this is most of the snapshot's weight, hundreds of megabytes); `storage/runs/`,
`data/сторож/` and `config.txt` are included too. The dump is made with `--single-transaction` and `--default-character-set=utf8mb4`.
The database dump also holds people's personal model keys (`users.access`, in plain text) — keep the snapshot private.

## What to keep separately

- `secrets.php` and other environment secrets;
- binary asset files from `data/files/`;
- old work folders from `workspace/` and external `work_dir` folders outside the project;
- `archive/` — the root one is not included in the snapshot;
- server routines and events: the current command does not add `--routines` and `--events`.

A snapshot cannot be considered a full restore of the environment until this data is saved separately.

## Full snapshot — with the work folders and assets

The standard command does not copy `workspace/`, `data/files/`, `archive/` and `secrets.php`. When you need a
snapshot of "everything as it is" (before a move, a big rework, deleting data), use the recipe below.
It puts into `backup/DATE_TIME/` a `project/` folder with **all** the project files except `backup/` itself,
a dump `db/DATABASE_NAME.sql` and `ОПИСЬ.txt`. The database password is read through `config()` and is not printed.

While the snapshot is being made, do not change the schema or files and do not start runs: the dump and the copy are not
one atomic operation. A folder without `ОПИСЬ.txt` is an unfinished snapshot; do not treat it as ready. Such a snapshot
contains secrets — keep it locked away. External work folders and symbolic link targets outside the project
are saved separately.

```bash
python3 - <<'PY'
import datetime
import json
import os
from pathlib import Path
import subprocess
import tempfile

root = Path('/Applications/XAMPP/xamppfiles/htdocs/goblin')
php = '/Applications/XAMPP/xamppfiles/bin/php'
cfg = json.loads(subprocess.check_output([
    php, '-r',
    'require $argv[1]; echo json_encode(config(), JSON_THROW_ON_ERROR);',
    str(root / 'lib/core/config.php'),
], text=True))

dump = Path(cfg['php_cli']).parent / 'mariadb-dump'
if not dump.is_file():
    dump = Path(cfg['php_cli']).parent / 'mysqldump'

stamp = datetime.datetime.now().astimezone().strftime('%Y-%m-%d_%H-%M-%S-%f')
dest = root / 'backup' / stamp
dest.mkdir(mode=0o700, parents=True, exist_ok=False)
(dest / 'db').mkdir()
sql = dest / 'db' / (cfg['name'] + '.sql')
partial = sql.with_suffix('.sql.partial')

def quote(value):
    value = str(value).replace('\\', '\\\\').replace('"', '\\"')
    return '"' + value.replace('\n', '\\n').replace('\r', '\\r') + '"'

with tempfile.TemporaryDirectory(prefix='goblin-backup-') as tmp:
    credentials = Path(tmp) / 'client.cnf'
    fd = os.open(credentials, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as out:
        out.write('[client]\n')
        for key, value in [('host', cfg['host']), ('port', cfg['port']),
                           ('user', cfg['user']), ('password', cfg['pass'])]:
            out.write(key + '=' + quote(value) + '\n')
    with partial.open('wb') as out:
        subprocess.run([
            str(dump), '--defaults-extra-file=' + str(credentials),
            '--single-transaction', '--skip-routines', '--skip-events',
            '--triggers', '--hex-blob', '--default-character-set=utf8mb4',
            '--databases', cfg['name'], '--add-drop-database',
        ], stdout=out, check=True)
partial.rename(sql)

subprocess.run([
    'rsync', '-a', '--exclude=/backup/',
    str(root) + '/', str(dest / 'project') + '/',
], check=True)

(dest / 'ОПИСЬ.txt').write_text('\n'.join([
    'БЭКАП ГОБЛИНА: ' + stamp,
    'Источник: ' + str(root),
    'project/ — все файлы проекта, кроме корневой backup/.',
    'db/' + sql.name + ' — все таблицы с данными, представления, триггеры.',
    'Процедуры, функции и события планировщика не включены.',
    'Внешние рабочие папки и цели символических ссылок не включены.',
    'Дамп содержит DROP DATABASE; импорт заменяет одноимённую базу.',
    'Конфиги содержат секреты; снимок хранить закрыто.',
    '',
]), encoding='utf-8')
print('Бэкап создан: ' + str(dest))
PY
```

The `--skip-routines --skip-events` flags are there on purpose: on this installation the server refuses to dump
events (error 1577, the scheduler is disabled) and procedures (1558, incompatible `mysql.proc`). The project's tables
are saved in full; the recipe does not reconfigure the server.

## Checking after creation

**Standard snapshot** (`server.php backup`):

1. The command must end with the line `готово: <путь> (<размер>)`.
2. The folder must contain `project/`, `db/*.sql` and `ОПИСЬ.txt`.
3. The SQL file must not be empty; `project/` must not contain `secrets.php`.

**Full snapshot** (the recipe above):

1. The recipe must end with the line `Бэкап создан: <путь>`; without `ОПИСЬ.txt` the snapshot is not finished.
2. `project/` must contain, among other things, `secrets.php`, `data/files/`, `workspace/`, `archive/` and
   `storage/`; `db/` must contain a non-empty `.sql` with no leftover `.sql.partial`.

For a critical snapshot of any kind — restore it into a separate test database and a separate folder,
open the project and check the API.

## Restore

First stop writes to the live system and save the current version separately. Then, from the folder
of the chosen snapshot:

```sh
rsync -a project/ /Applications/XAMPP/xamppfiles/htdocs/goblin/
/Applications/XAMPP/xamppfiles/bin/mariadb -u<user> -p < db/<database-name>.sql
```

The dump contains `DROP DATABASE`: the import replaces the database of the same name entirely.

- **From a standard snapshot**, after this you need to bring back the separately saved `secrets.php`, `data/files/`
  and the work folders — they are not in the snapshot.
- **From a full snapshot** there is nothing to bring back separately: secrets, assets and work folders are already inside
  `project/`. Only the external work folders outside the project are restored separately.

Then check the database settings and run:

```sh
/Applications/XAMPP/xamppfiles/bin/php bin/server.php migrate
/Applications/XAMPP/xamppfiles/bin/php bin/server.php map > /tmp/goblin-code-map.md
```

`map` is a diagnostic: it prints a code map built from the file headers to stdout and creates nothing; compare it
with [structure.md](structure.md). `migrate` without `--apply` only shows pending migrations.
Apply them with a separate command, `migrate --apply`, after checking the chosen snapshot and
the state of the database.

## Other service commands

```sh
# Show pending migrations
/Applications/XAMPP/xamppfiles/bin/php bin/server.php migrate

# Apply them
/Applications/XAMPP/xamppfiles/bin/php bin/server.php migrate --apply

# Show unused asset files; deletes nothing
/Applications/XAMPP/xamppfiles/bin/php bin/server.php files-gc

# Delete only the unused files that were found
/Applications/XAMPP/xamppfiles/bin/php bin/server.php files-gc --apply
```

Before restoring, always check the exact snapshot folder. Do not restore from an
unchecked or partly created backup.
