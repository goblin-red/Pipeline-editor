# Goblin: карта папок и файлов

Кто где лежит — по живому дереву. Пути — от корня `/Applications/XAMPP/xamppfiles/htdocs/goblin`,
внутри раздела — от его папки. Карту из шапок файлов печатает `php bin/server.php map` (в stdout).
Как это работает — в [бэкенде](backend.md), [фронтенде](frontend.md) и [прогоне](progon.md);
ещё [бэкап](backup.md), [перевод](перевод.md), [на будущее](на_будущее.md).

## Корень

| Путь | Назначение |
|---|---|
| `.htaccess` | web-запросы — в `public/`; `md_backend/документация` → `documentation.php` |
| `readme.md` | вводная для агентов-разработчиков: где что описано |
| `config.php` | база, пути, адреса сервисов; секретов нет |
| `config_admin.php` | настройки, заданные в админке («Настройки»): только отличия от `config.php`; пишет админка; в снимки входит |
| `config_web.php` | только на хостинге: доступ к базе и путь к php-cli, сильнее всех настроек; пишется при выкладке (`md_backend/ru/хостинг.md`) |
| `secrets.php` | пароли и ключи — единственное место; права `0640`; в снимки не входит |
| `config.txt` | списки интерфейса, агенты и CLI, шаблоны команд запуска worker; правится и в админке |
| `bin/` | командная строка |
| `lib/` | сервер |
| `public/` | web-входы, клиент, рабочие папки схем |
| `views/editor/` | HTML-фрагменты редактора |
| `sql/` | схема базы и миграции |
| `instructions/` | тексты агентам и правила встроенного ИИ, по языкам: `ru/`, `en/` |
| `lang/` | словари: `ru/*.json`, `en/*.json` — ключ → текст ([перевод.md](перевод.md)) |
| `tests/v2/` | проверки; запускаются по решению человека |
| `md_backend/` | `ru/` — эти документы, `en/` — их перевод с теми же именами; `документация/` — издатель документации; `тесты/` — отчёты прогонов и аудитов |
| `data/files/` | байты загруженных материалов (`.bin` под внутренними именами) |
| `data/сторож/` | журналы сторожа утилиты |
| `storage/runs/<id>/` | служебное прогона, у папки которого нет рабочей папки; закрыто `.htaccess` |
| `workspace/` | старые рабочие папки (входит в `file_roots`); `_tests/` — мусор проверок |
| `archive/` | прежняя реализация, в работе не участвует |
| `backup/` | снимки файлов и базы — **не читать и не ревизировать** |

## Командная строка — `bin/`

| Файл | Назначение |
|---|---|
| `goblin`, `goblin.php` | утилита агента (про запас): команды leader и worker, таблица `COMMANDS`; код — `lib/cli/` |
| `watch.php` | сторож прогонов утилиты (то же, что `goblin watch`) |
| `lead-watch.sh` | сторож leader простого пути: раз в 30 с `where&short=1`, завершается строкой `ТИШИНА`, `ПРОГОН ЗАКРЫТ` или `НЕТ СТАТУСА` — и этим будит leader; адрес — `GOBLIN_URL` |
| `server.php` | `migrate [--apply]`, `backup [метка]`, `files-gc [--apply]` (общая уборка `filesGc()`), `map`, `ai-job ID` |
| `hosting-push.php` | залить файлы на хостинг по FTP из настроек, в том числе туда, куда FTP не пишет (`md_backend/ru/хостинг.md`) |
| `router.php` | роутер встроенного сервера PHP (`php -S … bin/router.php`) — те же правила, что `.htaccess`; установка — `md_backend/homebrew.md` |

## Web-входы — `public/`

| Файл | Назначение |
|---|---|
| `index.php` | редактор (`lib/web/page.php`) |
| `api.php` | JSON API и простой путь leader: `dispatch()`, ошибки |
| `account.php` | кабинет: вход, проекты, структура, заготовки, пропуска, ИИ, списки |
| `admin.php` | админка: вход, данные разделов `?api=…`, действия; `admin/index.php` — переход сюда |
| `chat.php` | разговор с помощником целиком |
| `embed.php`, `embed.js` | схема на чужой странице, только чтение (`src/embed/render.js`) |
| `documentation.php`, `documentation-file.php` | страница документации и её файлы |
| `utilite/index.php` | переход в «Структуру» кабинета |
| `.htaccess` | кодировка, MIME Markdown, лимиты загрузки |
| `css/`, `fonts/`, `img/`, `vendor/pdf/` | стили, шрифты (`fonts/studio/` с лицензиями), логотипы, `pdf-lib` для экспорта |
| `workfiles/` | рабочие папки схем: `in/` — материалы, `out/rN/` — результаты, `service/` — служебное (закрыто `.htaccess`, кроме старого `service/archive/`). `in/` и `out/` открыты по прямой ссылке — [на_будущее.md](на_будущее.md) |
| `src/` | клиент — ниже |

## Сервер — `lib/`

`boot.php` подключает всё одним списком: новый файл работает только после записи туда. Исключения —
`admin/`, `web/`, `cli/`: их подключают `admin.php`, `account.php` и `bin/*`.

### Ядро, API, доступ

| Файл | Назначение |
|---|---|
| `core/config.php` | `config()`: `config.php` + `config_admin.php` + `secrets.php` (окружение сильнее: `CONFIG_ENV`, `SECRET_ENV`) + `config_web.php` на хостинге; `lists()` и разбор `listsParse()`, `listHas()`, `listWrite()`; границы файлов `pathInsideRoots()` |
| `core/db.php` | PDO (MySQL или SQLite — `dbDriver()`), `dbAll/dbRow/dbValue/dbRun`, `dbTransaction()`, `dbBegin()`/`dbRollback()`; диск после commit — `dbAfterCommit()`, откат диска — `dbOnRollback()`; время базы — `dbNow()`, `dbStamp()`, `dbMoment()` |
| `core/sqlite.php` | SQLite вместо MySQL: `sqliteOpen()` (WAL, функции MySQL), переводчик запросов `sqliteSql()` |
| `core/schema.php` | `schemaInstall()` (таблицы, отметки миграций, каталог), `schemaMigrations()`/`schemaMigrate()` на обе базы, `schemaSqlite()`, `schemaCatalog()` |
| `core/ids.php` | ключ проекта, номера элементов и прогонов, секреты пропусков |
| `install/install.php` | установка: `installChecks()`, `installRun()` — для `public/install.php` и `server.php install` |
| `install/release.php` | папка релиза для GitHub: `releaseBuild()` — белый список, README/LICENSE/.gitignore, поиск утечек |
| `core/i18n.php` | `lang()`/`t()` — язык человека (cookie), при ответе агенту — язык проекта (`langForAgent()`); `langAgents()`/`ta()` — язык проекта; `langFile()`, `langScript()` |
| `api/http.php` | разбор запроса, `reply()`, `ApiError` |
| `api/router.php` | реестр `OPS`: операция → метод, функция, роли, режим; `dispatch()`; `docsForAgent()` |
| `api/batch.php` | `runBatch()`: пачка — транзакция, замок проекта, ревизия; ключ повтора `opId` сверяется под замком |
| `api/service.php` | `config.get`, `config.list`, `docs.get`, `docs.run` (`role` — `lead` или `draw`), пропуска проекта |
| `api/instructions.php` | инструкции leader и worker под состав команды и среду: `instrScenario()`, `leaderDoc()`, `workerDoc()`, `instrPart()` |
| `api/simple.php` | простой путь leader: `scheme`, `begin`, `task`, `work`, `done`, `fail`, `again`, `where`, `wait`, `stop`, `invite`, `text` — ответ текстом; `done`/`fail` принимают и POST; своих правил прогона нет |
| `access/rights.php` | `caller()`, роль, `requireProject()`, `requireWrite()`, `sameProject()` |
| `access/tokens.php` | пропуска: выпустить (срок считает база), найти, погасить; в базе только хеш |
| `access/users.php` | регистрация, вход, выход |
| `access/admin.php` | отдельный вход администратора |

### Схема и материалы

| Файл | Назначение |
|---|---|
| `projects/projects.php` | проект: создать, прочитать, настроить, удалить |
| `projects/guests.php` | гости: список по ключам браузера, переход к вошедшему, уборка старых, платный ИИ гостю (`GUEST_AI_OPS`) |
| `folders/folders.php` | папки; состав команды (`ROLE_SCHEMES`) и среда (`RUN_ENVS`) — под защитой живого прогона; рабочая папка (`workDirFor()`, `workDirIn()` — `in/` после commit) |
| `folders/scheme.php` | схема папки одним ответом; `gateLinks()` — переход шлюза |
| `folders/transfer.php` | перенос папки в другой проект: `folderTransferDo()` — одно ядро для API и кабинета; новые номера блоков, прогонов и адресов истории, надгробия |
| `elements/elements.php` | элементы: создать, изменить, удалить, восстановить; ветка стрелки сверяется с итоговым `from` |
| `elements/kinds.php` | девять типов: что исполняется, кому нужно ТЗ, какие связи и контейнеры |
| `elements/shape.php` | вид элемента в ответе: `work` и `full` |
| `elements/find.php` | один элемент и поиск по свойству |
| `elements/members.php` | состав групп и областей, запрет циклов |
| `elements/props.php` | свойства: `propsOf()`, `runProp()`, стартер — `propOn()`, `isStarter()` |
| `elements/table.php` | форма и проверка содержимого таблицы |
| `assets/assets.php` | материалы: метаданные и содержимое |
| `assets/links.php` | привязки материалов и роли |
| `assets/files.php` | байты: загрузка в `in/`, отдача, список рабочей папки, `filesGc()` |
| `agents/agents.php` | агенты проекта (`agent.save` с `id` меняет только присланное), назначения, долгий пропуск |
| `templates/templates.php` | заготовка: снять с папки, развернуть (`templateDeploy()`), проверить на пробу (`templateCheck()`), наложить разницу (`templateMerge()`), удалить (`templateDrop()`) |
| `templates/catalog.php` | каталог: карточки, паспорта, превью, «Опыт каталога» (`catalogDigest()`), похожие (`catalogPick()`); перевод — `catalogLocal()`: из перевода только тексты и `answer`/`cond`/`accept`/`reject` |
| `journal/journal.php` | журнал «до/после», повтор команды, чистка секретов; `via` — только из ENUM |
| `journal/changes.php` | дельта по ревизии, история, кто сейчас в проекте |

### Движок прогона — `lib/engine/`

| Файл | Назначение |
|---|---|
| `lock.php` | `engineLocked()` — замок проекта; `engineCommand()` — команда с `commandId` и квитанцией; `runTouch()` |
| `guard.php` | защита смысла схемы живого прогона `engine = 2`: папка, потомки, цели шлюзов |
| `runs.php` | прогон: старт, чтение, пауза, стоп, конец, `attach`; переходы — `RUN_MOVES`/`runMove()`; один живой прогон в папке — `runLiveGuard()`; `runStopDo()`; `runServiceDir()`, `runOutDir()`, `runArrival()` |
| `precheck.php` | `run.check`: предпроверка схемы, входы, замечания |
| `prepare.php` | `run.prepare`: снять с холста прошлые статусы; файлы мимо `out/` — в `out/rN/` |
| `graph.php` | граф папки одной загрузкой: узлы, стрелки, правила (`graphRules()`), входы (`graphEntries()`) |
| `marks.php` | жетоны: выбрать, забрать, положить, вернуть, наследники |
| `ready.php` | что можно открыть прямо сейчас |
| `steps.php` | шаги: выдать, взять, принять, вернуть, ромб, шлюз, отмена, сдача, сбросы (`run.reset`); `STEP_RESULT_BYTES` |
| `package.php` | смысл задания `taskMeaning()` (описание, контекст рамок, ТЗ) — общий для задания, пакета и проверяющего; пакет worker |
| `vars.php` | переменные шага: числа из результата и их путь по стрелкам |
| `advance.php` | ход движка: приёмка, ромбы, выдача, конец; Jev — сеть между двумя секциями замка |
| `finish.php` | пройден ли прогон и почему стоит; конечный шлюз |
| `state.php` | `run.state` с ожиданием, `run.advance`, `run.update` |
| `paint.php` | `run.paint`: как горят блоки и стрелки, `epoch` |
| `timeline.php` | `run.timeline` — исполнители, отрезки, ожидания, события; `run.report` |
| `events.php` | лента `run_events`, `run.log` |
| `jobs.php` | заявки во внешние сервисы, лимит платных вызовов |
| `pulse.php` | кто сейчас работает: шапка, `runFolders`, `runProjects` |
| `legacy.php` | учёт прогонов `engine = 1` |
| `checks/` | `parser.php` — грамматика `expr` и `cond`; `expr.php`, `cond.php`, `form.php` (образец `answer`), `files.php` (проверки сдачи) |
| `judges/` | `review.php` — вход к проверяющим; `formal.php`, `human.php`, `jev.php` |

### ИИ — `lib/ai/`

| Файл | Назначение |
|---|---|
| `settings.php` | правила модели по маркерам `rule:*` (`aiRules()`), суточный лимит |
| `deepseek.php` | один вызов модели и разбор ответа; в проверках — заглушка `$GLOBALS['aiFake']` |
| `access.php` | доступ к моделям: общий установки или личный (`users.access`), `aiAccess()`; Jev прогона — владельца проекта `jevAccess()`; копия общих новому человеку `aiAccessDefaults()` |
| `context.php` | что модель знает о схеме |
| `compile.php` | ответ модели → разрешённые операции |
| `chat.php` | чат-помощник: сообщение, «Применить», отмена |
| `jobs.php` | фоновые задания: захват `queued → running` одним `UPDATE`; итог — под замком проекта, «Стоп» не перезаписывается; язык человека — `context.lang` |
| `build.php` | конструктор: опрос → черновик от основы или с нуля → проба и починка → «Создать» (однократно, под замком проекта) |
| `play.php` | тест-прогон на выдуманных исходах, ничего не пишет |
| `voice.php` | голос: `voice.session` (распознавание), `voice.speak` (озвучка) |
| `usage.php` | расход на ИИ |
| `calls.php` | журнал вызовов DeepSeek и Jev (`api_calls`): `apiCallLog()`, наблюдатель Jev-клиента `apiCallJev()`, баланс DeepSeek `apiBalance()` |
| `jev.php`, `jev/*` | Jev: советчик `step.jev`; клиент `jevCall()`, вопросы, отпечаток, решение |

### Админка, кабинет, страницы, утилита

| Файл | Назначение |
|---|---|
| `admin/data.php` | данные разделов админки (одна запись — одной выборкой); пропуска — код `live`/`expired`/`revoked` |
| `admin/actions.php` | действия админки; удаление проекта, уборки и остановка прогона — общими функциями доменов |
| `admin/backup.php` | бэкап кнопкой в «Системе»: дамп базы SQL (gzip, средствами PHP) и zip файлов — для хостинга без командной строки |
| `admin/settings.php` | раздел «Настройки»: схема полей `SETTINGS`, проверка перед записью (база, пути, адреса, порты), запись `config_admin.php`, `secrets.php` и `config.txt` |
| `web/page.php` | сборка редактора, import map, версия ресурсов |
| `web/chrome.php` | обвязка простых страниц |
| `web/account/structure.php`, `structure-view.php` | «Структура» кабинета: дерево и карточка; правки — операциями API, перенос — `folderTransferDo()` |
| `cli/` | утилита `goblin` (про запас): `net.php`, `engine.php`, `lead.php`, `worker.php`, `launch.php`, `watch.php`, `help.php` |

## Клиент — `public/src/`

Устройство — в [frontend.md](frontend.md).

| Каталог | Файлы |
|---|---|
| `main.js` | порядок старта редактора |
| `api/` | `client.js` — единственный, кто ходит на сервер; `sync.js` — загрузка и слежение |
| `core/` | `i18n.js`, `state.js`, `kinds.js`, `settings.js`, `variants.js` (виды), `containers.js`, `tree.js`, `usage.js` |
| `canvas/` | `view.js`, `element.js`, `arrow.js`, `geometry.js`, `routing.js`, `geometry-legacy.js`, `projection.js`, `volume.js`, `looks.js`, `palette.js`, `table.js`, `link.js` |
| `edit/` | `scene.js` — очередь правок; `pointer.js` — мышь и клавиши; `place.js`, `drop.js`, `grouping.js`, `rehome.js`, `align.js`, `inplace.js`, `clipboard.js`, `history.js`, `links.js`, `volume-controls.js` |
| `left/` | `left.js`, `folders.js`, `projects.js`, `runs.js`, `settings.js`, `tools.js`, `colors.js`, `parts.js` |
| `panel/` | `tabs.js`, `panel.js`, `parts.js`, `many.js`, `table-edit.js`, `agents-view.js`, `assets-view.js`, `asset-pick.js`, `links-view.js`, `project-view.js`, `ai.js`, `voice.js`, `embed.js` |
| `shell/` | `topbar.js`, `dialogs.js`, `sides.js`, `tips.js`, `studio.js`, `agentview.js`, `assetview.js`, `projects.js`, `newscheme.js`, `chatpage.js`, `info.js`, `files.js`, `dirpick.js`, `runbar.js`, `runlog.js`, `localdir.js` (папка схемы с диска — веб-режим) |
| `ui/` | виды: `canvas.js`, `list.js`, `hierarchy.js`, `run.js`, `timeline.js` («Лента»); `rich.js` — разметка текста ИИ |
| `run/` | `paint.js` — подсветка по `run.paint`; `follow.js` — «Следить»: холст едет к открытому узлу; `start.js` — окно «Начать прогон»: ссылки-задания агенту |
| `mobile/` | `mobile.js`, `bar.js`, `camera.js`, `drawer.js`, `folds.js`, `tools.js`, `touch.js` |
| `admin/` | `main.js`, `core.js`, `table.js`, `widgets.js`, `sections/` (10 разделов) |
| `embed/` | `render.js` — схема для `embed.php` |

## Разметка и стили

- `views/editor/`: `layout.html`, `topbar.html`, `left.html`, `canvas.html`, `panel.html`, `runbar.html`, `dialogs.html`.
- `public/css/`: `tokens.css` — переменные; `base.css`, `chrome.css` — каркас; `canvas.css`, `elements.css`,
  `panel.css`; `run.css` — полоса прогона и одна таблица подсветки на все скины и темы; `timeline.css` —
  вид «Лента»; `looks.css` — скины; `classic.css`; `ui-variants.css` — список, иерархия, прогон; `studio.css`;
  `projection.css`; `mobile.css` — подключается последним; `account.css`; `admin.css`.

## База — `sql/`

`schema.sql` — схема целиком, снята с базы и совпадает с ней (сверяет `check-schema.php`). Истина о
применённом — таблица `schema_migrations`. Новое изменение — новый файл в `migrations/` и
`php bin/server.php migrate --apply`.

| Миграции | Что добавляют |
|---|---|
| `001`–`008` | основа v2, шаблоны, таблица, пропуск агента, обложки из прогона, лента `run_events`, движок (`run_marks`), ярлык `link` |
| `009`–`013` | водитель `lead`, ответ шага `TEXT`, среда `folders.run_env`, рассуждения ИИ, папка разговора |
| `014`–`017` | документация |
| `018`–`020` | состав команды `role_scheme`, `lead_seen_at`, индекс и FK `run_events` на проект |
| `021`, `024` | каталог схем: разделы, паспорта, перевод `i18n` |
| `022`, `023` | язык статей документации, язык проекта `projects.lang` |
| `025` | личный доступ к моделям `users.access` |
| `026` | FK `run_events.run_id → runs`: лента уходит вместе с прогоном |
| `027` | `run_steps.who` — исполнитель по слову ведущего (`task&who=`) для ленты времени |
| `028` | `run_steps.lead_self` — шаг сделал сам ведущий (факт исполнителя для ленты) |
| `029` | `runs.agent_dir` — папка прогона у агента в веб-режиме |
| `030` | `projects.seen_at` — когда проект открывали (уборка проектов гостей) |
| `031` | `run_steps.delivered_run_id` — посылка шлюза получена прогоном цели |
| `032` | убрана `folders.lead_seen_at` (`019`): стартер мигает только в начале прогона |
| `033` | `api_calls` — журнал вызовов DeepSeek и Jev: кто, проект, для чего, модель, токены, время; прошлое — из `ai_jobs` и ответов Jev |
| `034` | `projects.files_server` — галочка «Хранить материалы на сервере» (по умолчанию нет: материалы у человека) |

## Словари — `lang/`

Одинаковые имена в `ru/` и `en/`. Ключ начинается с имени части: `server.*` — только в `server*.json`.

| Часть | Файлы | Кто читает |
|---|---|---|
| `common` | `common.json` | все, только чтение |
| `editor` | `editor*.json` | редактор |
| `admin` | `admin*.json` | админка |
| `account` | `account*.json` | кабинет |
| `server` | `server*.json` | ошибки и подписи сервера человеку (`t()`) |
| `agents` | `agents*.json` | тексты агентам и модели (`ta()`) |
| `docs` | `docs.json` | страница документации |

Часть можно дробить на файлы (`editor-panel.json`, `server-c.json`): правило ключа то же, повторов между файлами нет.

## Инструкции — `instructions/`

Сервер берёт файл на языке проекта (`langFile()`), нет перевода — русский. Метки `<!-- part:… -->` и
`<!-- rule:… -->` режет код — не удалять и не переименовывать.

| Файл | Кому и зачем |
|---|---|
| `прогон.md` | инструкции leader и worker частями `part:*`; собирает `lib/api/instructions.php` под состав команды и среду. leader — `docs.run`, `docs.get&file=leader&folder=N`; worker — `service/tasks/rN/worker.md` |
| `deepseek.md` | встроенный ИИ: общее (`rule:common`) и режимы `assistant`, `simulation` |
| `рисование.md` | как рисовать схемы и писать ТЗ (`rule:drawing` … `rule:end`); после `rule:end` — отправка схемы агентом `docs.run&role=draw` |
| `конструктор.md` | метод конструктора (`rule:constructor` … `rule:end`); «Опыт каталога» сервер добавляет сам |
| `old/` | запасное утилиты (`lead.md`, `worker.md`, `утилита.md`) и прежний простой путь `simple/`; в прогонах не используется |

## Проверки — `tests/v2/`

Каждая работает на своём временном проекте и убирает его; `check-editor` и `check-canvas` пишут в проект
`30vt4z512` и стирают свои объекты. Браузерные — по одной, в отдельном headless Chrome (`GOBLIN_CDP_PORT`).
Команды запуска — [backend.md](backend.md) и [frontend.md](frontend.md). Число — последний зелёный итог.

| Файл | Что проверяет | Проверок |
|---|---|---|
| `check-simple.mjs` | простой путь leader: круг, задание файлом, вход сквозь ромб, два worker, `again`, сдача POST, ошибки текстом | 229 |
| `check-human.mjs` | схемы «как пишет человек»: только описания, стартер, файл «в рабочей папке» и в `out/`, шлюз; `GOBLIN_API=` — на сайте | 31 |
| `check-golden.mjs` | эталоны ответов ведущему ru/en и инструкций (`golden/`) | 7 |
| `check-api.mjs` | API, права, guard, уборка | 32 |
| `check-engine.mjs` | движок по контрактам (`ENGINE_STAGE=3`) | 151 |
| `check-active-scheme.mjs` | защита схемы живого прогона | 10 |
| `check-finish-gateway.php` | конечный шлюз | 12 |
| `check-timeline.mjs` | `run.timeline`: исполнители, отрезки, ожидания, события | 44 |
| `check-regress-audit.mjs` | приёмка аудита Кодекса A01–A21 (A16 — в браузере) | 44 |
| `check-jev.php` | Jev без сети, разделы правил ИИ; `--live` — живой запрос | 75 |
| `check-jev-engine.php` | Jev в движке: сеть вне замка | 16 |
| `check-jev-dataset.php`, `check-jev-live.mjs` | набор Jev на заглушке; живой Jev — по решению | — |
| `check-cli.sh`, `check-cli-live.mjs` | утилита на заглушке и против настоящего API | 18 / — |
| `check-parallel-live.mjs` | три прогона одновременно | 23 |
| `check-link-api.mjs` | ярлыки на сервере | 36 |
| `check-catalog.php` | каталог: разделы, паспорта, превью, разворот | 17 |
| `check-constructor.php` | конструктор на заглушке модели | 26 |
| `check-schema.php` | `schema.sql` = живая база | 8 |
| `check-i18n.php` | словари ru/en и ключи в коде | — |
| `check-guests.php` | гости: список по ключам браузера, переход к вошедшему, отказ в ИИ, уборка | 19 |
| `check-security.php` | безопасность 30.09: дела хозяина, пути проекта, пропуска, чужие страницы, адрес модели, голос | 30 |
| `check-history.php` | история: удалили узел — прогоны стёрты; посылка шлюза; потери заготовки | 15 |
| `check-web-mode.php` | веб-режим простого пути: папка агента, ссылки, материалы, файлы со слов агента | — |
| `check-admin-settings.php` | настройки в админке: показ, проверки, запись с откатом, `config.txt`; копия доступа ИИ новым людям, Jev владельца | — |
| `check-editor.mjs`, `check-canvas.mjs` | редактор и холст (браузер) | 37 / 83 |
| `check-left.mjs`, `check-tree.mjs` | левая плашка во всех скинах; переезд в иерархии (браузер) | 46 / 12 |
| `check-link-ui.mjs`, `check-projection.mjs` | ярлыки в редакторе; объёмный вид (браузер) | 51 / 29 |
| `check-paint-ui.mjs`, `check-regress-paint.mjs` | подсветка прогона на подставных ответах (браузер) | 44 / 34 |
| `check-run-live-ui.mjs` | наблюдение за настоящим прогоном (браузер) | 10 |
| `check-timeline-ui.mjs` | вид «Лента» (браузер) | 54 |
| `check-mobile.mjs` | мобильный вид (браузер) | 88 |
| `check-regress-ui.mjs` | голос, опрос ответа ИИ, «Тест-прогон», окно «Новая схема» (браузер) | 24 |

Помощники: `cli-worker.mjs`, `cli-stub.mjs`, `jev-stub.php`, `fixtures/`, `golden/`.
