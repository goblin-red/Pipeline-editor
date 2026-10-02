# Goblin: backend architecture

This document describes the server as it is in the code. Sources of truth: `public/api.php`,
`lib/`, `sql/migrations/`, `tests/v2/`. Paths are given from the project root
`/Applications/XAMPP/xamppfiles/htdocs/goblin`, the address is `http://localhost/goblin/`.

Other documents: [file map](structure.md) · [frontend](frontend.md) ·
[run: how to lead and execute](progon.md) · [backup](backup.md).

## 1. What Goblin is

An editor for flowcharts and a system that tracks how agents execute them. These are two different kinds of data:

- **scheme** — tasks and the links between them: project → folders → elements;
- **run** — one pass through a scheme: attempts to execute steps and their results.

Stack: PHP without a framework, PDO, MariaDB, Apache (XAMPP). The client is JavaScript ES modules with
no bundler. The leader agent leads a run with plain `GET` requests to `api.php` — the simple path (`lib/api/simple.php`,
section "Leader's simple path"). The `goblin` utility in PHP calls the same API; it is kept in reserve
and is not used in runs right now.

## 2. Request path

```text
public/api.php → lib/boot.php → dispatch() → role check → domain function → PDO → reply() / ApiError
```

- `lib/boot.php` includes all server modules in one list. A new file in `lib/` does not work
  until it is added there.
- The `OPS` registry in `lib/api/router.php` has one line per operation: name, HTTP method, function, roles, mode.
- Reads are `GET`, commands are `POST` with JSON. A file upload is multipart with the fields `payload` and `file`.
- The project is passed with the `project` key, the token with the `X-Goblin-Token` header.
- The response always has one shape: `{ok:true, …data, warnings?}` or `{ok:false, error, code, code2?, …details}`.
  Statuses: 401 `unauthorized`, 403 `scope`, 404 `not_found`, 409 `conflict`, 422 `invalid`;
  anything unexpected is 500 `server` and a line in the Apache `error_log` marked `[goblin]`.
- The account (`public/account.php`) and the admin panel (`public/admin.php`) are separate server pages with
  their own POST handlers and direct SQL. Not every write goes through `dispatch()`.

### Three operation modes

| Mode | What it means |
|---|---|
| `read` | read only; `GET` changes nothing |
| `batch` | scheme edits in a batch: one transaction, one revision, one retry key |
| `single` | strictly one command per request; all run commands are like this |

`single` by itself gives neither a transaction nor protection against a repeat — `engineCommand()` gives them (section 6).

## 3. Roles and tokens

`lib/access/rights.php` identifies the caller: `caller()` and `callerRole()`. The role is taken only
from there; fields sent in the request cannot be trusted.

| Token | Form | Role | What it can do |
|---|---|---|---|
| run | `gbr_…` | `lead` | lead its own run: open, accept, return, resolve decisions |
| step | `gbs_…` | `worker` | one attempt: take the package, milestones, requests, submit or give up |
| agent | `gba_…` | `worker` | long-lived, belongs to an agent in a project: finds and takes its open steps itself, including across several runs |
| project | `gbl_…` | `human` | everything a human is allowed to do in this project, including run commands from the `OPS` registry; not tied to one run (this is not a leader token) |
| administrator | token with scope `admin` | `admin` | any API operation: `roleAllowed()` always lets the `admin` role through |
| cookie `goblin_session` | token with scope `session` | `human` | a signed-in user |
| none | — | `guest` | treated as a human; writing is limited by the project rights (`guest_write`) |

Token scopes (`tokens.scope`): `session`, `admin`, `project`, `run`, `step`, `agent`; the server
assigns the role by scope. For a long-lived agent token on `engine = 2`, work is additionally separated by
the exact run, the step and the worker process session (`run_steps.session`).

The admin panel is a separate sign-in: the `goblin_admin` cookie is read only by `adminIn()` (`lib/access/admin.php`)
on the `public/admin.php` page; `caller()` does not see it, and it gives no role in the API.

Only the hash of the secret is stored in the database. The server shows a step token once; a lost response
is fixed by reissuing (`step.take` again or `step.reissue`), and the old token is revoked at that point.
Ownership is checked by `requireProject()`, `sameProject()`, `runOwn()`, `stepOwn()`,
`workerAuthorityFresh()`. A worker does not get the right to accept its own work.

## 4. Data

| Entities | Tables and meaning |
|---|---|
| Projects, folders | `projects` (agent language `lang`), `folders`; a folder stores the scheme, the work folder path `work_dir`, the team setup `role_scheme` (`solo`, `leader-worker`, …), the run environment `run_env` (`subagents`, `orca`, `sendmessage`; empty means subagents), the overview checkbox `share_scheme`, and display settings |
| Elements | `elements`; `no` is the number inside the project, issued by the server; `id` is the row key |
| Nesting and properties | `members` — the contents of groups and areas; `props` — the execution rules of an element |
| Assets | `assets` — an asset; `asset_links` — the link and role: `spec`, `code`, `input`, `reference`, `attachment`, `cover`, `result`, `preview` |
| Agents and access | `agents`, `users` (personal access to models `access`), `tokens`, `visits` |
| Execution | `runs`, `run_steps`, `run_marks`, `run_jobs`, `run_events` |
| Built-in AI | `ai_chats` — a conversation remembers the folder where it was started (`folder_id`); `ai_messages` — messages, and an AI reply also has its reasoning (`think`); `ai_jobs` |
| Changes | `journal`, `journal_ops`, `deletions` (deletions for the client) |
| Documentation | `documentation_pages` (article language `lang`), `documentation_links`, `documentation_callouts` (migrations `014`–`017`, `022`) |
| Scheme catalog, migrations | `templates` — the scheme body with folder settings, the passport (`passport`), the translation (`i18n`), the catalog flag `in_catalog`, the date of the run check `checked_at`; `template_categories` — sections with passports and translation; `schema_migrations` |

Element types: `block`, `decision`, `gateway`, `arrow`, `group`, `area`, `note`, `table`, `link`.
The type rules are in `lib/elements/kinds.php` and its mirror `public/src/core/kinds.js`. Only
a block, a decision and a gateway are executed; the order is set by arrows, not by coordinates and
numbers. A `link` is a topical link between blocks, groups and areas (including into another folder); it does not take part in a run.

Presentation (coordinates, size, color) lives in the JSON field `style`, execution rules live in `props`.
Keys of these JSON fields need no migrations. The spec of a block is an asset with the role `spec`, not the block's title.

`sql/schema.sql` is the initial schema; later tables and fields were added by migrations `001`–`025` (`009` — the `lead` driver of a leader run, `010` — the step answer as `TEXT`,
`011` — the folder run environment `folders.run_env`, `012` — the built-in AI reasoning `ai_messages.think`,
`013` — the AI conversation folder `ai_chats.folder_id`, `014`–`017` — documentation, `018` — the team setup `folders.role_scheme`, `020` — the index and FK of `run_events`, `021` — the scheme catalog: `template_categories` and the template passport; `022`–`024` — languages: documentation articles, the project language, the catalog translation; `025` — personal access to models; `019` — `folders.lead_seen_at`, removed by `032`: the starter blinks only when a run begins). The comments "draft / not applied" inside old SQL files are out of date:
the truth is the `schema_migrations` table and `SHOW CREATE TABLE`. A new schema change goes in a new file
in `sql/migrations/` and is applied with the command `php bin/server.php migrate --apply`.

## 5. Scheme write rules

1. Scheme edits go through domain operations via `runBatch()` (`lib/api/batch.php`): a transaction,
   a `FOR UPDATE` lock on the project row, one revision per batch.
2. `opId` + `opScope` handle a batch repeat: the same body returns the earlier result, a different one is a conflict.
   `ref` lets you refer to an object created in the same batch.
3. `ifRev` checks the project revision, `ifFolderRev` checks the folder contents. After a change,
   `rev`, `content_rev` and the journal are updated; the client sees a deletion through `deletions`.
4. The server writes the time (`NOW(3)`). Element and run numbers are issued by `lib/core/ids.php`.
   Do not mix up `id` (a DB row), `no` (the number in the project) and the run label `rN`.
5. The response shape is built by `*Shape()` functions. The `work` view removes presentation, the `full` view does not.
6. The journal for a single command is written by `journalSingleWrite()` during `reply()`. Secrets are cleaned out
   recursively (`journalCleanSecrets()`). If the project was deleted while the request was running, the journal is skipped:
   the action itself (or the deletion) is already recorded, and there is nowhere to write its history.

### Protection of an active scheme

While a run with `engine = 2` in the `running` or `paused` state is going in a folder, `lib/engine/guard.php`
forbids changing the **meaning** of its scheme: elements, contents, `props`, specs and input assets, assigned
agents, folders, moving and deleting the project. The protected area is the run folder, its descendants and the gateway targets,
recursively. The refusal is `conflict`, `code2 = active_run`, with the run number and a hint to stop it.

Allowed: presentation (`style`), camera, sorting, unrelated folders, step results and the cover,
which the server sets itself on acceptance. The guard stops nothing — it only refuses.
A consequence for tests and scripts: a project with a live run cannot be deleted, first do `run.stop` with `now=1`.

### Leader's simple path

`lib/api/simple.php` is a thin layer over the engine for an agent in a terminal: plain `GET` addresses,
the response is **plain text**, with no tokens, no JSON, no utility. It has no run rules of its own —
it calls the same `stepAcceptDo()`, `stepDecideDo()`, `advanceAfter()` as the full API.

| Address | What it does |
|---|---|
| `scheme&folder=N` | the scheme on one screen: blocks, arrows, executors |
| `begin&folder=N` | start a run and immediately return the first task |
| `task&block=N[&text=1]` | the task for a worker and **moving the block to "working"**: the server writes the task to `<work folder>/service/tasks/r<N>/<block>.<round>.txt` and replies with the line `task in file …` (`goTaskFile()`); `text` is the text itself, for a human and for checks |
| `work&block=N` | the same lighting up by hand, if the text is already in hand |
| `done[&block=N]&result=…` | accept the work and get the next task |
| `done&block=N&branch=yes\|no` | resolve a decision |
| — | a reply with the address `137.3: …` (`goAnswerAddress()`; the leader passes the reply whole, everything before the address is a remark to the worker in one line, into the journal): the block is taken from the address, a reply to someone else's round is refused with 409, a repeat of an accepted one is a "repeat". Details — [progon.md](progon.md), section 2 |
| `fail&block=N&why=…` | the step failed |
| `again&block=N` | redo a block: the last attempt is erased, the block is issued again (`stepResetDo()`); issues not yet taken and decisions after it are removed along with it (`stepErase()`); a block that is working or already accepted further on is refused with a list |
| `invite&run=rN[&reply=ADDRESS]` | the worker briefing as a ready line (`goInvite()`): the path to the assembled instruction `service/tasks/rN/worker.md` (written by `begin`, `goWorkerFile()`) and how to reply — only for the folder environment (Orca: `reply` is the leader terminal, the line has a ready reply command; SendMessage: `reply` is the leader session name; subagents: the reply is the last line of the final message). In solo it is refused with 409. The leader forwards the line as it is |
| `where[&short=1]`, `wait`, `stop` | where we are (in one line) · wait for an event · close the run |

Four rules of this path:

1. **Honor system.** The worker said "done", the leader passed it on — accepted. Nobody reviews it:
   neither the leader nor the server. Acceptance is decided by neither a judge nor Jev: a run is started with `judge = human`
   (and `jev = judge` — only for decisions), `done` calls `stepAcceptDo` directly. The next worker looks at the result. A result reviewer is
   a plan for the future ([progon.md](progon.md), section 2).
2. **Decisions are resolved by Jev** from the decision condition and the worker reply; the engine does not calculate the formula in a leader run
   (`driver = lead`, migration `009`). If Jev is not sure, the leader decides.
3. **The reply always contains the next task** — and, after `done`/`fail`/`again`, lines about the decisions that
   the move resolved itself (`goMoveLines()`); the total is `RUN PASSED · acceptances N: blocks …, decisions …` (`goTally()`). The leader
   marks a gateway with the same `done&block=N` (`stepPassDo()`, shared with `step.pass`). A separate "what next" is not needed — otherwise the agent
   would guess when to ask.

4. **The leader sees only the skeleton** (`goNextLines()`): the block number and name, the task address, the state,
   the team (there is no executor from the scheme — the worker whom the leader called does the work). The spec and data are only in the task file for the worker. Exceptions: a decision that
   Jev did not resolve (the condition and the worker reply), and the run description from the starter together with the skeleton of the whole scheme
   ("SCHEME OUTLINE", `goSchemeMap()`) — once at `begin` (`goAbout()`), only to understand
   the context. The worker gets the same skeleton as the file `service/tasks/r<N>/схема.txt`, if the folder has
   "Give the worker the overall picture of the scheme" turned on (the "Agents" tab, `share_scheme`, `goSchemeFile()`). The leader is the safety net of the move: a failure of
   transitions it sorts out itself ([progon.md](progon.md), section 2).

What the server puts into the task (`goTaskText()`: "Input" with the pickup from the previous round — `goInputs()`, the round line —
`goRoundLine()`, the spend — `goSpent()`) and which files it attaches to the card (`goCatchFiles()`; the earlier version of
a repeat file goes as a copy into `out/rN/.попытки/`, `goKeepTries()`) — [progon.md](progon.md), section 2.

The server sets the highlight from these same commands: `task` lights the block "working", `done` paints it
accepted and lights the arrow. The browser picks it up by itself, within a fraction of a second.

## 6. Run engine

All the code is in `lib/engine/`. The main rule: **a run rule is written once — on the server**.
The utility and the browser do not repeat it.

| Concept | Meaning |
|---|---|
| Move | `engineAdvance()` does everything that can be done without a human: accepting a checked submission, a computable decision, issuing ready blocks, closing a finished run. No more than 50 actions per move |
| Driver `driver` | who asks for a move: `lead` — the leader of the simple path (all new runs); for the utility — `manual` (`goblin go`) or `utility` (`goblin drive`). On the simple path the leader's `done` makes the move itself. The rules are common to all |
| Judge `judge` | who says "accepted": `human` or `formal` (code) |
| Jev `jev` | `off` / `advisor` / `judge`; independent of `judge` and optional |
| Mark | a `run_marks` row: "you may go along this arrow". It is placed on acceptance, taken on issue, returned on return, cancel and failure |
| Version `version` | grows only on a real change; it is used to wake up waiters |
| Event cursor | `lastEvent` / `sinceEvent` by `run_events.id` — delivery of what was done without loss; separate from the version |
| Picture `run.paint` | the server itself says which block and which arrow are lit and how |

`engine = 1` — runs started before the engine: they finish on the old bookkeeping (`lib/engine/legacy.php`),
an engine move does not touch them. New runs are always `engine = 2`. The only exception: a full
`run.reset` erases the steps and moves an old run to `engine = 2`.

### Lock and command repeat

`engineLocked()` is a transaction under `SELECT … FOR UPDATE` on the project row. `runBatch()` takes the same lock,
so the lock order is the same and there are no deadlocks. Inside the lock you must not call
`reply()`, sleep or use the network.

`engineCommand()` is a whole run command: project, lock, work. With a `commandId` key, the command
is executed once: the receipt is written to the journal **in the same transaction** as the action; the key
is unique by `(project_id, op_scope, operation_id)`. A repeat with the same body returns the earlier outcome,
with a different one — a refusal. A secret is not issued again: `conflict`, `code2 = replay_secret_unavailable` and
a recovery hint (`run.attach` or `step.reissue`).

Commands that need the next move (`run.start`, `run.resume`, `run.update`, `run.reset`,
`step.open`, `step.accept`, `step.return`, `step.decide`, `step.pass`, `step.submit`, `step.fail`,
`step.reset`) call `advanceAfter()` after they are committed — only a deterministic move, with no network.
The others (`run.pause`, `run.stop`, `run.finish`, `step.cancel`, `step.reissue`, `step.note`,
`step.job`, `step.take`, `step.state`) make no move. That is why `step.submit` does not wait for Jev. The request to Jev is made by an explicit `run.advance`: a "waiting" mark
under the lock → network outside the lock → a second fingerprint check and the write under the lock.

### Run operations

| Who | Operations |
|---|---|
| human, leader | `run.check`, `run.prepare`, `run.start`, `run.get`, `run.state`, `run.advance`, `run.update`, `run.paint`, `run.log`, `run.timeline`, `run.report`, `run.pause`, `run.resume`, `run.stop`, `run.finish`, `run.event`, `run.reset`, `run.attach` (human only) |
| human, leader | `step.open`, `step.accept`, `step.return`, `step.decide`, `step.pass`, `step.cancel`, `step.reissue`, `step.reset`, `step.jev`; `step.state` — human only |
| worker | `step.take`, `step.mine`, `step.get`, `step.note`, `step.job`, `step.submit`, `step.fail`; reading `run.state` |

`run.state` can wait: with `since` and `wait` it holds the request until the version grows, no longer than 25 seconds and
no longer than the display pause. The field `needsAdvance` is a signal to the driver "time to call `run.advance`"
(the display pause has expired, there is a resolved submission, a Jev request was interrupted). The browser does not act on this signal.

States: a run — `running`, `paused`, `stopped`, `done`, `failed`; an attempt — `issued`, `running`,
`submitted`, `accepted`, `returned`, `failed`, `cancelled`; a request — `sent`, `done`, `failed`, `rejected`.

Details are in [progon.md](progon.md): section 2 — how a run is led now, then — the engine, failures, Jev.

### Submission checks

- `checks/files.php` — on submission: there is a result, the files exist, are non-empty and lie inside the work
  folder; the `answer` pattern is checked by form (refusal `bad_answer`). The project checkbox "strict checks" turns
  some of the remarks into a refusal.
- `checks/parser.php` — one grammar for the block arithmetic `expr` and the decision condition `cond`, without `eval`.
- `judges/formal.php` accepts only if a **substantive** check passed: arithmetic with
  assignments or a pattern with `{variables}`. Otherwise it is "not sure", and the step waits for a human. Wrong
  content is a return; the expected numbers are seen only by the leader (`verdict`, the `review` field in `run.state`).
- `judges/human.php` is always "not sure" and carries a hint from the formal checks.
- `judges/jev.php` first calls the formal one; Jev only supplements "no substantive check".
  `advisor` only advises; without `accept`/`reject` criteria the decision stays with `runs.judge` —
  a human is not bypassed.

## 7. Built-in AI and Jev

`lib/ai/jobs.php` records a job and starts `bin/server.php ai-job ID` as a separate process.
The model reply goes through `aiCompile()` and the allowed set of operations, and is applied through `runBatch()`.
The rules are assembled by `aiRules()` (`lib/ai/settings.php`) using markers — they must not be removed. Three files:
`instructions/ru/deepseek.md` — common skills (`rule:common` and the mode section), `instructions/ru/рисование.md` —
drawing and specs (`rule:drawing`), `instructions/ru/конструктор.md` — the builder method (`rule:constructor`).
On top of the method, the server adds to the builder the "Опыт каталога" — passports of sections and schemes from the database
(`catalogDigest()`). "Play" simulates a pass and executes nothing.

### Scheme catalog and builder

The catalog is `templates` with `in_catalog = 1`, grouped by `template_categories` sections. The template body is
elements with `ref`, specs and assets, plus the folder settings `folder` (`roleScheme`, `runEnv`, `shareScheme`).
A scheme passport: what it is for, input, output, steps, conditional services, what is configured, questions, pitfalls, tags;
a section passport: the typical skeleton, questions, setup, pitfalls. `catalog.get` returns sections and cards,
`&template=key` — the passport and preview. Deployment is `templateDeploy()` (a new folder with settings from the body);
`template.apply` also replies with pre-check obstacles.

The builder is a conversation `ai_chats.kind = constructor`, with states `questionnaire → building → ready → done`.

| Operation | What it does |
|---|---|
| `ai.build.get` | the session (the latest in the project or `session`), the question history, the current card, the draft, the job |
| `ai.build.start` | the human's goal → a new session and the first questionnaire job |
| `ai.build.answer` | an answer to a card; `build` — "That's enough, build it"; `retry` — repeat a failed one; text in the `ready` state — refining the draft |
| `ai.build.create` | a folder from the draft (`templateDeploy`), the session becomes `done` |

The `questionnaire` and `diagram` jobs are run by `aiBuildRun()` in the background. The questionnaire: one card at a time,
up to 7 questions, the model sets `ready` itself. The build: the server picks similar schemes by the words of the goal
(`catalogPick()`), the model returns the **difference** from the base, `templateMerge()` applies it, `templateCheck()`
deploys the draft as a trial in a transaction with rollback and collects the pre-check obstacles; if there are obstacles —
up to two repair rounds. The draft (the body) is in `ai_jobs.ops`, the preview is in `ai_jobs.preview`.
The model is changed with the settings `ai_api_url` and `ai_model`; in checks it is replaced by `$GLOBALS['aiFake']`
(`lib/ai/deepseek.php`). Chats survive project deletion (`project_id` → `NULL`) for the sake of usage accounting.

Admin panel, the "Scheme catalog" section: sections and their passports (`category.save`), templates — the passport, in the catalog
or not, the check mark (`template.update`), remove or refresh from a folder (`template.fromFolder`), delete.

The model's reasoning for a reply (DeepSeek `reasoning_content`) and the server steps — how many edits are in the reply,
a retry after a refusal, whether edits were applied at once or wait for "Apply" — are written to `ai_messages.think`
(`lib/ai/jobs.php`, migration `012`); in the chat this is a collapsible "Reasoning" block (`panel/ai.js`).

A conversation remembers the folder where it was started (`ai_chats.folder_id`, migration `013`): the chat history in
the editor (`ai.chat.get` with `folder`) shows only conversations of the open folder; without `folder` —
the latest conversations of the whole project. Old conversations got a folder from a migration based on their first job;
for those where none was found, or the folder was deleted, the field is empty — they are not in the editor history.

Jev (TypeSafe): the client `lib/ai/jev/*` — `jevCall()` with a total limit of 5 seconds, questions by the lines
`accept`/`reject`, the request fingerprint, the decision `accept / return / unsure`. The model is pinned to the exact
version `jev_model` (`jev-1.13.0`). `lib/ai/jev.php` is a separate advisor `step.jev` / `goblin jev`:
it changes nothing and checks `runs.jev = off` before the network. With `jev = off`, not a single request leaves the server.

### Voice and personal access to models

The assistant and the builder call DeepSeek, voice calls OpenAI. Access (API address, model, key) is shared
by default: `config.php` (`ai_api_url`, `ai_model`, `voice_api_url`, `voice_model`, `voice_tts_model`,
`voice_tts_voice`) and `secrets.php` (`ai_api_key`, `voice_api_key`). A person can have their own — `users.access`
(migration `025`), edited in the account, the "AI" section; an empty field means the shared one. `aiAccess()` (`lib/ai/access.php`)
returns the effective one: in a request — of the signed-in user, in a background AI job — of the one who asked (`ai_jobs.user_id`,
or, if it is empty, the chat owner `ai_chats.user_id`; `aiAccessUse()`).

| Operation | What it does |
|---|---|
| `voice.session` | a temporary OpenAI Realtime token for live recognition (`realtime/client_secrets`, model `gpt-live-transcribe`, no splitting into messages); the browser connects to OpenAI with it over WebRTC by itself — the key never goes to the browser |
| `voice.speak` | speaks a text aloud (`audio/speech`, `gpt-4o-mini-tts`, voice `marin`) — the reply is an mp3 right away |

Neither operation is written to the journal (mode `read`).

### Languages

Two languages in one request — `lib/core/i18n.php`, the translation rules are in [перевод.md](перевод.md):

| Who reads | Language | Where from | Functions |
|---|---|---|---|
| a person: interface, account, admin panel, their API errors | their choice | cookie `goblin_lang` (the "Language" switch in settings); none — Russian | `t()`, `tn()`; in views `{{t:key}}`; browser — `langScript()` → `window.GOBLIN_I18N`, `public/src/core/i18n.js` |
| agents and the built-in AI: instructions, tasks, replies to the leader, the run feed, model rules, the catalog in the builder | the project language | `projects.lang` (`project.update lang`; a new project gets its creator's language); `requireProject()` sets it itself, a background AI job — by its own project | `ta()`, `tna()`, `langFile()` |

Texts are dictionaries `lang/<language>/<part>*.json`, key `part.place.meaning`; no translation — the Russian text.
Long texts for agents are md files by language: `instructions/<language>/прогон.md` (parts `<!-- part:… -->`,
`instrPart()`), `deepseek.md`, `рисование.md`, `конструктор.md`. Reply parsing understands both languages:
the patterns `<файл>`/`<file>`, the conditions `содержит`/`contains`, `вызовов=`/`calls=`, `ОТВЕТ worker:`/`WORKER ANSWER:`,
the watchdog lines. Catalog: the translation of a scheme and a section is in the `i18n` field, `catalogLocal()` substitutes the language:
the catalog window and deployment (`catalog.get`, `template.apply`) — the person's language; the builder, "Catalog
experience" and picking similar schemes — the project language.
Documentation: articles in `ru/` and `en/content.json`, `publish.php --lang=en`; the page shows the person's language.
The `goblin` utility (`lib/cli/`) is not translated — it is kept in reserve.

## 8. Configuration and secrets

- `config.php` — the database, paths, service addresses; it contains no secrets.
- `secrets.php` — the only place for passwords and keys (DB, DeepSeek, OpenAI for voice, Jev, FTP, the admin
  password hash); permissions `0640`. An environment variable overrides the file: `GOBLIN_DB_PASS`, `GOBLIN_DEEPSEEK_KEY`,
  `GOBLIN_JEV_KEY`, `GOBLIN_FTP_PASSWORD`, `GOBLIN_ADMIN_PASS_HASH` (`SECRET_ENV` in `lib/core/config.php`).
  The voice key `GOBLIN_OPENAI_KEY` is not on this list: it works only if there is no key in `secrets.php`.
- People's personal keys are not in files but in the database (`users.access`, account → "AI").
- the optional `config_web.php` overrides everything on the hosting.
- `config.txt` — interface lists, agents/CLI and launch command templates; it is written under a file
  lock, and from the account only the admin edits it.

Read only through `config()` and `lists()` (`lib/core/config.php`). Keys are not given to the client:
`config.get` reports only the `jev.ready` flag and the model. Do not carry secrets into documentation or the journal.

## 9. Command line

| Entry | Purpose |
|---|---|
| `bin/goblin` → `bin/goblin.php` | the agent utility (in reserve, not used now): leader and worker commands, implemented in `lib/cli/` |
| `bin/watch.php` | the run watchdog as a separate command (the utility path) |
| `bin/lead-watch.sh` | the watchdog of the simple-path leader — a safety net: every 30 s `where&short=1`, wakes the leader with `SILENCE` (5 minutes without changes; the leader's block is marked `— you do it yourself`, `goBrief()`) / `RUN CLOSED` / `NO STATUS` ([progon.md](progon.md), section 2) |
| `bin/server.php` | `migrate [--apply]`, `backup <label>`, `files-gc [--apply]`, `map`, `ai-job ID` |

`server.php map` prints the code map from file headers **to stdout** and creates no files.
The PHP for the command line is `/Applications/XAMPP/xamppfiles/bin/php` (plain `php` in the shell may not be found).

Run service files live **in the work folder**, in `<work_dir>/service/` (the path is given by `runServiceDir()`;
without a work folder — `storage/runs/<id>/service/`): utility packages — `steps/`, simple-path tasks —
`tasks/r<N>/<block>.<round>.txt`. Inside the work folder on purpose: a worker may not be able to read beyond it.
The folder is closed from the browser by the `.htaccess` that `serviceDirMake()` places.

## 10. How to make changes

- **A new API operation:** a domain function → a line in `OPS` → include it in `lib/boot.php` → if
  needed, a utility command and a call in the client.
- **A new column:** a migration → a check on write → `*Shape()` → full load and `changes` → the consumer.
- **A new element type:** server and client `kinds`, the type constraint in the DB, rendering,
  rules for links and containers.
- **A new run rule:** only in `lib/engine/`. If you want to duplicate it in the utility or
  the browser, that is a design error.
- Every file starts with a header "what it does / what it returns / what it does not do"; comments are short,
  in Russian.
- The project is not under git. A snapshot (`php bin/server.php backup <label>`) is made only on the human's order,
  the agent never does it itself; do not read or review the `backup/`
  folder.

## 11. Checks

All are in `tests/v2/`; each works on its own temporary project and cleans it up afterwards, except
`check-editor` and `check-canvas` — they write into the project `30vt4z512` and erase their objects by a marker.

**If you change the simple path (`lib/api/simple.php`) or the engine, run `check-simple.mjs`.** It goes through a
scheme the same way a leader agent does: `begin → task → done`, a loop with a decision, two workers and a merge,
the picture on the card, the `again` rollback and errors as text.

```sh
PHP=/Applications/XAMPP/xamppfiles/bin/php

node tests/v2/check-simple.mjs                   # leader simple path: round, input, again, English project 157
node tests/v2/check-api.mjs                      # API, rights, guard, cleanup               32
ENGINE_STAGE=3 node tests/v2/check-engine.mjs    # engine by contracts                    90
node tests/v2/check-active-scheme.mjs            # protection of the active scheme                   10
$PHP tests/v2/check-finish-gateway.php           # final gateway                           10
$PHP tests/v2/check-jev.php                      # Jev without network, built-in AI rules    75
$PHP tests/v2/check-jev-engine.php               # Jev in the engine, network outside the lock            16
$PHP tests/v2/check-jev-dataset.php tests/v2/fixtures/jev-dataset-example.json   # on a stub
bash tests/v2/check-cli.sh                       # utility: exit codes, exact run     18
node tests/v2/check-cli-live.mjs                 # utility against the real API
node tests/v2/check-parallel-live.mjs            # three runs at once                23
node tests/v2/check-link-api.mjs                 # links, server                          36
$PHP tests/v2/check-catalog.php                  # scheme catalog, passports, deployment   17
$PHP tests/v2/check-constructor.php              # builder on a model stub          26
$PHP tests/v2/check-i18n.php                     # ru/en dictionaries and keys in code
```

`check-jev.php --live`, `check-jev-dataset.php --live` and `check-jev-live.mjs` call the real
Jev service — only by a separate decision. Browser suites are in [frontend.md](frontend.md).

Review of 29.09.2026: everything above is green except the utility (it is kept in reserve). `check-cli.sh` — 17 of 18:
the check expects the words "Круг ведущего" in the `drive1` hint, which now says "Круг leader". `check-cli-live.mjs` fails
(`drive` — code 4, "worker is silent"): the test creates a folder without a team roster (solo), agent cards work only
in Orca and SendMessage, and `goblin work` with the agent token sees no steps.
