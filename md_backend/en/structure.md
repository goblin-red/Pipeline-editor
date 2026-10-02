# Goblin: map of folders and files

Compiled from the live project tree. Paths are from the root `/Applications/XAMPP/xamppfiles/htdocs/goblin`;
inside a section, names are given relative to its folder. A fresh map from file headers is printed by
`php bin/server.php map` (to stdout, no files created).

Other documents: [backend](backend.md) · [frontend](frontend.md) · [run](progon.md) · [backup](backup.md) · [future work](на_будущее.md).

## Root

| Path | Purpose |
|---|---|
| `.htaccess` | redirects web requests to `public/`; `md_backend/документация` → `documentation.php` |
| `readme.md` | intro for developer agents: where things are described |
| `config.php` | database, paths, service addresses; contains no secrets |
| `secrets.php` | passwords and keys — the only place; permissions `0640`; not copied into snapshots |
| `config.txt` | interface lists, agents/CLI, worker launch command templates |
| `bin/` | command-line entry points |
| `lib/` | server logic; `lib/api/simple.php` — the leader's simple path; `lib/cli/` — the utility (in reserve) |
| `public/` | web entry points, the client and public resources |
| `views/editor/` | HTML fragments of the editor |
| `sql/` | the initial scheme and migrations |
| `instructions/` | executable role instructions and rules of the built-in AI — by language: `ru/`, `en/` |
| `lang/` | dictionaries of the interface and of agent texts: `ru/*.json`, `en/*.json` — key → text ([перевод.md](перевод.md)) |
| `tests/v2/` | checks; run at a person's decision |
| `md_backend/` | `ru/` — these documents, `на_будущее.md` (postponed improvements) and `перевод.md` (translation rules); `en/` — their English version with the same names; `документация/` — the documentation publisher (`ru/content.json`, `en/content.json`, `Публикация.md`, `audits/`, the old `archive-before-unified-publisher/`); `тесты/` — reports of test runs and audits (HTML and md) |
| `data/files/` | bytes of uploaded assets under internal `.bin` names |
| `data/сторож/` | watchdog logs of runs |
| `storage/runs/<run id>/` | service files of a run that has no work folder; closed by `.htaccess` |
| `workspace/` | the former place of work folders (old schemes; part of `file_roots`). New work folders are created in `public/workfiles/<name>/` (`workfiles_dir`). Inside any of them — the run's `service/`: `steps/` (utility packages), `tasks/r<N>/<block>.<round>.txt` (assignments of the simple path), closed by `.htaccess` |
| `archive/` | an outdated implementation, not involved in work (`lib-transfer/` — a one-time transfer from v1) |
| `backup/` | snapshots of files and the database; **do not read and do not audit** |

## Command line — `bin/`

| File | Purpose |
|---|---|
| `goblin` | shell wrapper: finds PHP and launches `goblin.php` |
| `goblin.php` | command parsing and the `COMMANDS` table: name → function and role; implementation — `lib/cli/` |
| `watch.php` | the run watchdog as a separate command (same as `goblin watch`) |
| `lead-watch.sh` | watchdog of the leader on the simple path: reads `where&short=1` every 30 s, exits after 5 minutes of silence (the threshold is the third argument) and thereby wakes the leader |
| `server.php` | `migrate [--apply]`, `backup [label]`, `files-gc [--apply]`, `map`, `ai-job ID` |

## Web entry points — `public/`

| File | Purpose |
|---|---|
| `index.php` | the editor: calls the page assembly `lib/web/page.php` |
| `api.php` | JSON API: `dispatch()` and error handling |
| `account.php` | account: login, projects, structure, lists |
| `admin.php`, `admin/index.php` | installation administration; the second is a redirect. Section data — `lib/admin/data.php`, actions — `lib/admin/actions.php`, screen — `src/admin/`, styles — `css/admin.css` |
| `embed.php`, `embed.js` | embedding a scheme on someone else's page, read-only; drawn by `src/embed/render.js` |
| `chat.php` | the whole conversation with the helper: questions, answers, chain of thought, spending — linked from the chat and from the account |
| `documentation.php`, `documentation-file.php` | the documentation page and serving its files (publisher — `md_backend/документация/`) |
| `utilite/index.php` | redirect to the structure section of the account |
| `timeline/roy.html` | a separate run timeline |
| `.htaccess` | encoding, Markdown MIME type, upload limits |
| `css/`, `fonts/`, `img/` | styles, fonts (including `fonts/studio/` with licenses), logos |
| `vendor/pdf/` | local `pdf-lib` and `fontkit` for PDF export |
| `workfiles/` | work folders of schemes: `in/` — assets, `out/rN/` — run results, `service/` — service files (closed by `.htaccess`; old archives `service/archive/` before 22.09.2026 are open via their own `.htaccess`). `in/` and `out/` are still open by direct link — see `на_будущее.md` |
| `src/` | the client — see below |

## Server — `lib/`

`boot.php` — a single `require` for everything: the core, the API layer and the domains. A new file works only after being added here
(except `admin/`, `web/` and `cli/` — those are included by `admin.php`, `account.php` and `bin/*` themselves).

### Core, API, access

| File | Purpose |
|---|---|
| `core/config.php` | `config()` — `config.php` + `secrets.php` (environment takes precedence) + `config_web.php` (exists only on the hosting); `lists()` and writing `config.txt` |
| `core/db.php` | PDO, `dbRow/dbAll/dbValue/dbRun`, transactions, server time; `dbAfterCommit()` — files on disk only after commit |
| `core/ids.php` | project key, element and run numbers, token secrets |
| `core/i18n.php` | languages: `lang()` — the person's language (cookie `goblin_lang`), `t()`/`tn()` — text for them; `langAgents()` — the project's language, `ta()`/`tna()` — text for agents and the AI; `langFile()` — md by language, `langScript()` — the dictionary into the page |
| `api/http.php` | request parsing, `reply()`, `ApiError`, response codes |
| `api/router.php` | the `OPS` registry: operation → method, function, roles, mode; `dispatch()` |
| `api/batch.php` | `runBatch()`: a batch of edits — transaction, project lock, revision, retry key, `ref` |
| `api/service.php` | `config.get`, `config.list`, `docs.get`, `docs.run`, project tokens |
| `api/instructions.php` | leader and worker instructions, assembled for the team composition and the folder environment: `instrScenario()`, `leaderDoc()`, `workerDoc()` |
| `api/simple.php` | the leader's simple path: `scheme`, `begin`, `task`, `work`, `done`, `fail`, `again`, `where`, `wait`, `stop`, `invite` — plain GETs, the response is text; contains no run rules of its own. It assembles the assignment input itself and attaches worker files to the card; `task` writes the assignment to a file (`&text=1` — return it as text), `done` checks the round address `137.3:` and marks the gateway |
| `access/rights.php` | `caller()`: who came, the role, write rights, project boundaries |
| `access/tokens.php` | tokens: issue, find, revoke; only the hash is in the database |
| `access/users.php` | registration, login, logout |
| `access/admin.php` | a separate administrator login |

### Scheme and assets

| File | Purpose |
|---|---|
| `projects/projects.php` | project: create, read, configure, delete |
| `folders/folders.php` | folders: create, change, move, delete; team composition (`ROLE_SCHEMES`, `folderRoleScheme()`) and run environment (`RUN_ENVS`, `folderRunEnv()`, `folderActiveRunEnv()`, `folderUsesAgentCards()`); work folder (`workDirFor()`, `workDirIn()`) |
| `folders/scheme.php` | the folder's scheme in a single response: elements, composition, properties, assets, agents; `gateLinks()` — a gateway transition via a tag on the gateway and on the target folder's input |
| `folders/transfer.php` | moving a folder to another project: elements, runs with new numbers, step results, tombstones in the source |
| `elements/elements.php` | elements: create, change, delete, restore |
| `elements/kinds.php` | nine types: what gets executed, who needs a spec, which links and containers are allowed |
| `elements/shape.php` | the element's view in a response: working `work` and full `full` |
| `elements/find.php` | reading a single element and searching by property |
| `elements/members.php` | composition of groups and areas, ban on cycles |
| `elements/props.php` | element properties: `propsOf()`, `runProp()` |
| `elements/table.php` | table content form and validation |
| `assets/assets.php` | assets: metadata and content |
| `assets/links.php` | asset bindings and their roles |
| `assets/files.php` | uploading and serving bytes, the work folder listing, worker rights to step files |
| `agents/agents.php` | project agents, assignments, the worker's long-lived token |
| `templates/templates.php` | take a template from a folder, deploy it (`templateDeploy`), dry-run check without writing (`templateCheck`), apply a difference (`templateMerge`) |
| `templates/catalog.php` | scheme catalog: `catalog.get`, cards and passports, previews, "Catalog experience" for the builder (`catalogDigest`), similar-scheme picking (`catalogPick`) |
| `journal/journal.php` | operation journal with "before" and "after" values, command replay, secret cleanup |
| `journal/changes.php` | delta by revision, history, who is in the project right now |

### Run engine — `lib/engine/`

| File | Purpose |
|---|---|
| `lock.php` | `engineLocked()` — project lock; `engineCommand()` — a command with `commandId` and a receipt; `runTouch()` — version |
| `guard.php` | ban on changing the meaning of a live `engine = 2` run's scheme; the scope is the folder, descendants, gateway targets |
| `runs.php` | run: start, read, pause, stop, end, `attach`; `runServiceDir()`, `runOutDir()` — the `out/rN/` results folder; `runArrival()` — the starter's response after a gateway |
| `precheck.php` | `run.check`: pre-flight check of the scheme and inputs; `folderWarnings()` remarks |
| `prepare.php` | `run.prepare`: remove past statuses and run results from the canvas; does not clean `out/`, moves files submitted outside `out/` into the `out/rN/` of their run |
| `graph.php` | the folder graph in one load: nodes, arrows, rules; no links or frames in it |
| `marks.php` | marks: choose, take, put, return, heirs, reconciliation with manual edits |
| `ready.php` | what can be opened right now |
| `legacy.php` | the former mark accounting for `engine = 1` runs |
| `steps.php` | steps: issue, take, accept, return, decision, gateway, cancel, reissue, submission, failure, resets; `stepInputRows()` — answers through decisions and gateways, `runElement()` — the run folder's element |
| `package.php` | the worker's assignment package and the package for the reviewer |
| `vars.php` | step variables: numbers from the result and their path along the arrows |
| `advance.php` | engine advance: acceptance, decisions, issuing, end; Jev — in two lock sections with the network between them |
| `finish.php` | whether the run is done, and if not — why it stands still; the final gateway |
| `state.php` | `run.state` with waiting, `run.advance`, `run.update`, parsing the submission for the leader |
| `paint.php` | `run.paint`: which block and which arrow glow how |
| `events.php` | the `run_events` feed, "what was done" pages by the event cursor; kinds `stop`, `cancel`, `warn`; `runStartEvent`/`runFinishEvent`/`runStopDo` in `runs.php` |
| `jobs.php` | requests to external services and the limit of paid calls |
| `pulse.php` | who is working now — for the header; keys `runFolders`/`runProjects` (where a run is going — the left panel): `pulseRunFolders()`, `pulseRunProjects()` |
| `timeline.php` | timeline data and the run report |
| `checks/parser.php` | one grammar for `expr` and `cond`, no `eval` |
| `checks/expr.php` | block arithmetic against what was submitted |
| `checks/cond.php` | decision condition; a verbal one — the `verbal` flag |
| `checks/form.php` | the `answer` response pattern, including with `{variables}` |
| `checks/files.php` | submission checks and turning result files into assets |
| `judges/review.php` | a single entry to the reviewers, writing the decision to the step |
| `judges/formal.php` | the formal reviewer: decides by code |
| `judges/human.php` | the "person" reviewer: "not sure" plus a hint |
| `judges/jev.php` | the Jev reviewer: after the formal one, by `accept`/`reject` criteria; verbal decision |

### Utility — `lib/cli/` (in reserve, currently unused)

| File | Purpose |
|---|---|
| `net.php` | the utility's HTTP client, environment, flags, output, the step token cache `0600`, `--id` |
| `engine.php` | `state`, `go`, `drive`, `pace`, `history`, `report`, `work` for `engine = 2`; contains no run rules |
| `lead.php` | leader commands and the old `drive1` round for `engine = 1` |
| `worker.php` | `task`, `note`, `job`, `submit`, `fail`, the old `work1` |
| `launch.php` | the assignment package in `<work folder>/service/steps/`, launching someone else's CLI or text for a live terminal |
| `watch.php` | watchdog: waits until the leader is needed |
| `help.php` | short hints `goblin <command> --help` and `goblin help lead\|worker` |

### AI and pages

| File | Purpose |
|---|---|
| `ai/deepseek.php` | a single HTTP call to the model and parsing the response |
| `ai/settings.php` | rules by `rule:*` markers from `instructions/`, the daily limit |
| `ai/context.php` | what the model knows about the scheme and what it may change |
| `ai/compile.php` | model response → operations that the server agrees to apply |
| `ai/chat.php` | chat: message, applying and cancelling a proposal |
| `ai/jobs.php` | background AI jobs: recording, starting the process, execution |
| `ai/access.php` | access to models: the installation-wide one (`config.php`, `secrets.php`) and the user's personal one (`users.access`, account → "AI"); `aiAccess()` — the effective one |
| `ai/voice.php` | voice: `voice.session` — a temporary token for live recognition (OpenAI Realtime, WebRTC), `voice.speak` — voicing an answer (mp3) |
| `ai/build.php` | builder: card survey → draft from a catalog base or from scratch → trial and repair → preview → folder (`ai.build.*`) |
| `ai/play.php` | playing a scheme through made-up outcomes, writes nothing |
| `ai/usage.php` | built-in AI spending |
| `ai/jev.php` | advisor `step.jev` / `goblin jev`: changes nothing |
| `ai/jev/client.php` | `jevCall()`: request to TypeSafe, total timeout, retries, strict response validation |
| `ai/jev/questions.php` | questions by `accept`/`reject` lines and the choice question for a decision |
| `ai/jev/state.php` | request state, length limits, fingerprint |
| `ai/jev/verdict.php` | decision from the answers: accept, return, not sure |
| `web/page.php` | editor HTML assembly, import map, resource version |
| `web/chrome.php` | wrapper for simple server pages |
| `web/account/structure.php`, `structure-view.php` | the "Structure" section of the account (full width, larger font): a tree project → folder → elements without arrows, a card on the right; edits — through API operations; properties are saved only if changed and in their previous type (`structurePropsChanged()`) |

## Client — `public/src/`

The design and rules are in [frontend.md](frontend.md); here — only who is where.

| Directory | Files and roles |
|---|---|
| `main.js` | the editor startup order and shared handlers, nothing more |
| `api/` | `client.js` — the only module that goes to the server, and hash navigation; `sync.js` — loading and watching for changes |
| `core/` | `i18n.js` — `t()`, `tn()`, language switch; `state.js` — state and events; `kinds.js` — types; `settings.js`; `variants.js` — interface views; `containers.js`, `tree.js` — nesting; `usage.js` — agent assignments |
| `canvas/` | `view.js` — camera and DOM; `element.js` — cards; `arrow.js` — arrows and the `×N` label; `geometry.js`, `routing.js`, `geometry-legacy.js` — routes; `projection.js`, `volume.js` — the volumetric view; `looks.js` — skins; `palette.js`, `table.js`, `link.js` |
| `edit/` | `scene.js` — the edit queue; `pointer.js` — mouse and keys; `place.js`, `drop.js`, `grouping.js`, `rehome.js`, `align.js`, `inplace.js`, `clipboard.js`, `history.js`, `links.js`, `volume-controls.js` |
| `left/` | the left panel: `left.js` — the frame; `folders.js`, `projects.js`, `runs.js`, `settings.js`, `tools.js`, `colors.js`, `parts.js` |
| `panel/` | the right panel: `tabs.js`, `panel.js`, `parts.js`, `many.js`, `table-edit.js`, `agents-view.js`, `assets-view.js`, `asset-pick.js`, `links-view.js`, `project-view.js`, `ai.js`, `voice.js` (voice: dictation and voicing), `embed.js` (embed code) |
| `shell/` | the shell: `topbar.js`, `dialogs.js`, `sides.js`, `tips.js`, `studio.js`, `agentview.js`, `assetview.js`, `projects.js`, `newscheme.js` (the "New scheme" window: catalog and builder), `chatpage.js` (the conversation page), `info.js`, `files.js`, `dirpick.js` (folder picker), `runbar.js` (the run bar, read-only), `runlog.js` (feed) |
| `mobile/` | the mobile view: `mobile.js`, `bar.js`, `camera.js`, `drawer.js`, `folds.js`, `tools.js`, `touch.js` |
| `admin/` | the admin panel screen: `main.js`, `core.js`, `table.js`, `widgets.js`, sections in `sections/` (10 files) |
| `embed/` | `render.js` — the scheme for `embed.php`, read-only |
| `ui/` | views of one scheme: `canvas.js`, `list.js`, `hierarchy.js`, `run.js`; `rich.js` — short text markup (AI chat, the "New scheme" window) |
| `run/` | `paint.js` — highlighting by `run.state` and `run.paint`, `GET` only; `follow.js` — “Follow”: the canvas glides to the open node; `start.js` — the run information window |

## Markup and styles

- `views/editor/`: `layout.html` — page assembly; `topbar.html`, `left.html`, `canvas.html`,
  `panel.html`, `runbar.html`, `dialogs.html`.
- `public/css/`: `tokens.css` — variables; `base.css`, `chrome.css` — the frame; `canvas.css`,
  `elements.css`, `panel.css`, `run.css`; `looks.css` — skins; `classic.css` — the left panel with tabs;
  `ui-variants.css` — list, hierarchy, run; `studio.css` — the "Studio" skin; `projection.css` —
  volumetric mode; `mobile.css` — the mobile view, included last; `account.css` — account; `admin.css` — admin panel.

## Database — `sql/`

`schema.sql` — the initial scheme. All migrations are applied, the order is strict:

| File | What it adds |
|---|---|
| `001-v2.sql` | the main v2 database |
| `002-templates.sql` | templates |
| `003-table.sql` | the "table" element type |
| `004-agent-token.sql` | the agent's long-lived token |
| `005-cover-from-run.sql` | origin of results and covers (`made_by_run`) |
| `006-run-events.sql` | the run event feed |
| `007-engine.sql` | `run_marks` marks; run settings in `runs`; `vars` and `verdict` in `run_steps` |
| `008-link.sql` | the `link` element type |
| `009-lead-driver.sql` | the `lead` driver of a leader run |
| `010-result-text.sql` | the step answer `run_steps.result` — `TEXT` |
| `011-run-env.sql` | the folder's run environment `folders.run_env` |
| `012-ai-think.sql` | the built-in AI's reasoning attached to the answer `ai_messages.think` |
| `013-ai-chat-folder.sql` | the AI conversation folder `ai_chats.folder_id`: chat history — by the open folder |
| `014`–`017-documentation*.sql` | documentation assets: sections, link types, callouts, statuses |
| `018-role-scheme.sql` | the folder's team composition `folders.role_scheme` (default `solo`) |
| `019-lead-seen.sql` | `folders.lead_seen_at` — removed by `032-folders-drop-lead-seen.sql`: the starter blinks only when a run begins |
| `020-run-events.sql` | `run_events`: pulse index `(project_id, kind, id)`, FK to the project with cascade, cleanup of rows of deleted projects |
| `021-catalog.sql` | scheme catalog: `template_categories` (10 sections with passports), for `templates` — `passport`, `in_catalog`, `checked_at` |
| `022-docs-lang.sql` | `documentation_pages.lang`: an article in a language, the address is unique within the language |
| `023-project-lang.sql` | `projects.lang`: the language of the project's agents and built-in AI |
| `024-catalog-i18n.sql` | `templates.i18n`, `template_categories.i18n`: translation of a template and a section (`{"en": {…}}`) |
| `025-user-access.sql` | `users.access`: the user's personal access to models (API address, model, DeepSeek and voice keys) |

The truth about what is applied is the `schema_migrations` table and `SHOW CREATE TABLE`, not the comments in the files.
`schema.sql` was dumped from the database up to migration `021`: the fields of `022`–`025` are not in it yet.

## Instructions — `instructions/`

| File | To whom and why |
|---|---|
| `ru/прогон.md` | texts of the leader and worker instructions in parts `<!-- part:name -->`: `lib/api/instructions.php` assembles from them the instruction for the team composition and the folder environment. leader — `docs.run` and `docs.get&file=leader&folder=N`; worker — the file `service/tasks/rN/worker.md` (written by `begin`), `docs.get&file=worker&folder=N` |
| `old/simple/` | the former MDs of the simple path (`leader.md`, `worker.md`, `связь-*.md`) — not used since 26.09.2026 |
| `ru/рисование.md` | rules for drawing schemes and an element's spec for the built-in AI: between the markers `rule:drawing` and `rule:end`. `docs.get&file=рисование` |
| `old/lead.md` | the path through the utility (in reserve); `docs.get&file=legacy-lead` (`file=lead` is already the assembled leader instruction) |
| `old/worker.md` | the path through the utility (in reserve); `docs.get&file=legacy-worker` |
| `old/утилита.md` | the `goblin` utility: commands, launch, worker, measurements — moved from `progon.md` on 22.09.2026 |
| `ru/deepseek.md` | general skills of the built-in AI by `rule:*` markers: how Goblin works, run, helper, playback |
| `ru/конструктор.md` | the builder's method: choosing a base, survey, assembling by difference, services, self-check, repair — between `rule:constructor` and `rule:end`; the server adds the schemes' experience itself from the catalog passports |

English versions are in `en/` with the same names; the server takes the project's language (`langFile()`), and with no translation — the Russian file. The `rule:*` markers are part of loading the rules (`lib/ai/settings.php`) and must not be removed: a section is read from
its marker to the next one. Without the closing `rule:end` in `рисование.md` the built-in AI would get
the rest of the file. The "Leader instructions" button in the "Canvas settings" window opens the instruction assembled for the open folder.

## Checks — `tests/v2/`

| File | What it checks | Browser |
|---|---|---|
| `check-simple.mjs` | the leader's simple path: round, assignment text, input through a decision, two workers, files on the card, `again`, the skeleton of leader answers, the leader watchdog, the assignment as a file and the round address, errors as text | — |
| `check-api.mjs` | API, rights, volleys, submission, guard, cleanup | — |
| `check-engine.mjs` | the engine by contracts (`ENGINE_STAGE=3`) | — |
| `check-active-scheme.mjs` | protection of a live run's scheme | — |
| `check-finish-gateway.php` | the final gateway | — |
| `check-link-api.mjs` | links on the server | — |
| `check-catalog.php` | catalog: sections, cards, passport, preview, "Catalog experience", similar-scheme picking, deployment with folder settings | — |
| `check-i18n.php` | dictionaries `lang/ru` and `lang/en`: same keys and substitutions, every key from the code exists; how many Russian strings are left by part (`--left`, `--part=`) | — |
| `check-constructor.php` | the builder on a model stub: survey, assembly from a base with repair, refinement, folder, model failure, assembly from scratch | — |
| `check-jev.php` | Jev without the network; `--live` — one live request | — |
| `check-jev-engine.php` | Jev in the engine: network outside the lock, fingerprint | — |
| `check-jev-dataset.php` | the Jev set: on the `jev-stub.php` stub; `--live` — the real service | — |
| `check-jev-live.mjs` | live check of Jev in a run | — |
| `check-cli.sh` | the utility on the `cli-stub.mjs` stub (starts it itself) | — |
| `check-cli-live.mjs` | `launch` + `drive`/`work` against the real API | — |
| `check-parallel-live.mjs` | three runs at once; `--llm` — with live workers | — |
| `cli-worker.mjs`, `cli-stub.mjs`, `jev-stub.php` | test bench helpers | — |
| `fixtures/jev-dataset-example.json` | an example Jev set | — |
| `check-editor.mjs`, `check-canvas.mjs` | editor and canvas; write into project `30vt4z512` and erase their own objects | yes |
| `check-left.mjs` | the left panel in all skins | yes |
| `check-link-ui.mjs` | links in the editor | yes |
| `check-tree.mjs` | moving in the hierarchy | yes |
| `check-projection.mjs` | volumetric view: XYZ, sizes, undo, shadows, skins | yes |
| `check-paint-ui.mjs` | run highlighting on mock answers; the browser only reads | yes |
| `check-run-live-ui.mjs` | watching a real run; the browser does not change the version | yes |
| `check-mobile.mjs` | mobile view | yes |

Browser checks go to a separate headless Chrome over CDP (`GOBLIN_CDP_PORT`, default 9377) and
are run **one at a time**: they share a profile and `localStorage`. Do not touch the user's regular Chrome.
