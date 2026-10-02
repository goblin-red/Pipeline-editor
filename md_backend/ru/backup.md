# Goblin: снимки, восстановление, схема базы

Снимки проекта и базы (`cmdBackup()` в `bin/server.php`), восстановление, миграции и файл схемы
`sql/schema.sql`. Остальные документы: [бэкенд](backend.md) · [фронтенд](frontend.md) · [прогон](progon.md) ·
[карта файлов](structure.md).

Проект не под git: снимок — единственный способ вернуться назад. **Снимок — только по распоряжению
человека**: агент может предложить, но сам не запускает, даже перед крупной переделкой.

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/goblin
/Applications/XAMPP/xamppfiles/bin/php bin/server.php backup manual   # manual — метка, можно своя
```

Каталог `backup/YYYY-MM-DD_HH-MM_<метка>/`, существующий не перезаписывается.

**Без командной строки (хостинг)** — админка → «Система» → «Бэкап — скачать» (`lib/admin/backup.php`):
- «Скачать базу» — дамп SQL средствами PHP, gzip; восстановить: `gunzip < файл | mariadb имя_базы`
  (проверки `CHECK` на время загрузки выключены — как у `mysqldump`);
- «Скачать файлы» — zip: `data/files`, `public/workfiles`, `config_admin.php`, `config.txt`. `secrets.php` не входит.

## Штатный снимок

| В снимке | Что |
|---|---|
| `project/` | весь проект, **кроме** корневых `/backup/`, `/workspace/`, `/data/files/`, `/archive/`, `secrets.php`, `.DS_Store` |
| `db/<имя-базы>.sql` | дамп `mariadb-dump --single-transaction --default-character-set=utf8mb4`, без routines и events |
| `ОПИСЬ.txt` | дата, метка, размер, число файлов, команды восстановления |

Рабочие папки `public/workfiles/` входят целиком (`in/`, `out/rN/`, `service/tasks/` — основной вес),
как и `storage/runs/`, `data/сторож/`, `config.txt`, `config_admin.php` (настройки из админки). В дампе — личные
ключи людей к моделям (`users.access`, открытым текстом): снимок хранить закрыто. Ключи и пароли, заданные в админке,
лежат в `secrets.php` — его сохранить отдельно.

Отдельно сохранить то, чего в штатном снимке нет: `secrets.php`, материалы `data/files/`, `workspace/`,
`archive/`, внешние `work_dir` вне проекта. Без них это не полное восстановление окружения.

## Полный снимок — всё как есть

Перед переездом, большой переделкой или удалением данных — рецепт ниже: `project/` со **всеми** файлами,
кроме `backup/`, дамп `db/ИМЯ_БАЗЫ.sql` и `ОПИСЬ.txt`. Пароль базы берётся через `config()` и не
печатается. На время снимка не менять схему и файлы и не вести прогоны: дамп и копия — не одна атомарная
операция. Папка без `ОПИСЬ.txt` — незавершённый снимок. Внутри секреты — хранить закрыто; внешние рабочие
папки и цели символических ссылок сохраняются отдельно.

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

Ключи `--skip-routines --skip-events` стоят не случайно: на этой установке сервер отказывает в выгрузке
событий (ошибка 1577, планировщик отключён) и процедур (1558, несовместимая `mysql.proc`). Таблицы
проекта сохраняются полностью; сервер рецепт не перенастраивает.

## Проверка после создания

**Штатный снимок** (`server.php backup`):

1. Команда должна закончиться строкой `готово: <путь> (<размер>)`.
2. В каталоге должны быть `project/`, `db/*.sql` и `ОПИСЬ.txt`.
3. SQL-файл должен быть непустым; в `project/` не должно быть `secrets.php`.

**Полный снимок** (рецепт выше):

1. Рецепт должен закончиться строкой `Бэкап создан: <путь>`; без `ОПИСЬ.txt` снимок не завершён.
2. В `project/` должны лежать в том числе `secrets.php`, `data/files/`, `workspace/`, `archive/` и
   `storage/`; в `db/` — непустой `.sql` без остатка `.sql.partial`.

Для критического снимка любого вида — восстановить его в отдельную тестовую базу и отдельный каталог,
открыть проект и проверить API.

## Восстановление

Сначала остановить запись в рабочую систему и сохранить текущую версию отдельно. Затем из каталога
выбранного снимка:

```sh
rsync -a project/ /Applications/XAMPP/xamppfiles/htdocs/goblin/
/Applications/XAMPP/xamppfiles/bin/mariadb -u<user> -p < db/<имя-базы>.sql
```

Дамп содержит `DROP DATABASE`: импорт заменяет одноимённую базу целиком.

- **Из штатного снимка** после этого нужно вернуть отдельно сохранённые `secrets.php`, `data/files/`
  и рабочие каталоги — в снимке их нет.
- **Из полного снимка** возвращать отдельно нечего: секреты, материалы и рабочие папки уже внутри
  `project/`. Отдельно восстанавливаются только внешние рабочие папки за пределами проекта.

Затем проверить настройки базы, посмотреть ожидающие миграции (`migrate`, раздел ниже) и сверить карту
кода: `php bin/server.php map` печатает её из шапок файлов в stdout и ничего не создаёт — сравнить с
[structure.md](structure.md). Миграции применять (`migrate --apply`) только после проверки снимка и базы.

Восстанавливать только из проверенного и завершённого снимка — с `ОПИСЬ.txt`.

## Схема базы и миграции

База меняется только миграциями: `sql/migrations/NNN-имя.sql`, применяются по порядку имени. Какие уже
применены — таблица `schema_migrations` (имя, время, `took_ms`).

```sh
PHP=/Applications/XAMPP/xamppfiles/bin/php
$PHP bin/server.php migrate           # список ожидающих, ничего не меняет
$PHP bin/server.php migrate --apply   # применить; каждая миграция — один вызов db()->exec()
$PHP tests/v2/check-schema.php        # schema.sql = живая база
$PHP bin/server.php schema-sqlite     # sql/schema.sqlite.sql из schema.sql (после каждой правки схемы)
$PHP bin/server.php catalog-export    # sql/catalog.json — встроенный каталог схем для новых установок
```

**Две базы** (`lib/core/schema.php`). Источник правды — `schema.sql` (MySQL); `schema.sqlite.sql` собирает `schemaSqlite()`, руками
не правят. Миграция с расхождением синтаксиса — двумя файлами: `035-x.sql` и `035-x.sqlite.sql`; без двойника SQLite её
не применит. Установка с нуля (`schemaInstall()`) — схема по драйверу, отметки миграций и каталог; на MySQL (не MariaDB)
у полей TEXT снимаются значения по умолчанию — MySQL их не принимает.

Если команда миграции упала, ни одна строка `schema_migrations` для неё не пишется — миграцию можно
повторить. Команды внутри файла не в одной транзакции: `ALTER TABLE` в MariaDB фиксируется сразу.

**`sql/schema.sql`** — вся схема одним файлом, для новой установки: `mariadb -uroot goblin_v2 < sql/schema.sql`.
- Каждый `CREATE TABLE` — ровно как отдаёт `SHOW CREATE TABLE` живой базы, без `AUTO_INCREMENT=N`.
- В шапке — диапазон миграций (сейчас 001–028), в конце — `INSERT INTO schema_migrations` со всеми
  применёнными: новая база сразу на их уровне.
- Посев — разделы каталога `template_categories` с паспортом и переводом `i18n`, снимок живой базы.
- **Добавил миграцию — обнови `schema.sql`** (таблицы, шапку, хвост) и прогони `check-schema.php`.

**`tests/v2/check-schema.php`** собирает временную базу `goblin_schema_check` из `schema.sql` (или из
файла, названного аргументом) и сравнивает её с живой через `information_schema`: таблицы, колонки (тип,
NULL, умолчание, доп. свойства, кодировка), порядок колонок, индексы, внешние ключи и их правила,
`CHECK`, хвост `schema_migrations`. Живую базу только читает, временную удаляет. Расхождение — список
«есть в базе, нет в schema.sql» и «в базе …, в schema.sql …».

## Другие служебные команды

```sh
# Неиспользуемые файлы материалов: показать; с --apply — удалить только найденное
/Applications/XAMPP/xamppfiles/bin/php bin/server.php files-gc [--apply]
```
