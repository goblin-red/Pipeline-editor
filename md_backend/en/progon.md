# Goblin: run — how it works, how to lead it and how to carry it out

The main document about the run: engine rules, roles, how to lead a run, failures and recovery.
A run is led by the simple path — section 2. The source of truth is the code in `lib/engine/`, `lib/api/simple.php`
and the checks in `tests/v2/`.

This document does **not replace** the executable role instructions. They are not kept in MD files —
the server **builds them for the folder settings** (`lib/api/instructions.php`): team composition and environment.
An agent gets only its own scenario. The leader gets it whole in the `docs.run` link (separately —
`docs.get&file=leader&folder=N`), the worker gets it as the file `service/tasks/rN/worker.md`, which `begin` writes.
The old MD files are in `instructions/old/simple/` (since 26.09.2026, not used).

Other documents: [backend](backend.md) · [frontend](frontend.md) · [file map](structure.md) · [backup](backup.md) · [for the future](на_будущее.md).

## 1. The main points in a minute

1. **Scheme and run are different data.** A scheme is blocks and arrows. A run is one pass through the scheme:
   attempts at steps and their results.
2. **The run rules live only on the server** (`lib/engine/`). The browser does not know them.
3. **The run is led by the leader agent** — with plain `GET` requests to `api.php` (section 2). The browser only
   shows things: it reads the state, highlights blocks and arrows, keeps the log. It does not start, advance or accept anything.
4. **The leader does not carry out tasks.** Even a small block is done by a worker.
5. **Handing in is not acceptance.** Only an accepted step puts marks on its outgoing arrows.
6. **There is no background daemon.** A run is moved by the leader's commands: `begin`, `done`, `fail`, `again`.
   An open page is not needed for this and adds nothing.
7. **Jev is an optional feature.** A run must work fully with `jev = off`.

## 2. How a run is led now: leader → PHP → JS

**Roles.**

- The **worker** does one block and answers the leader. It does not go to the server: no `curl`, no tokens.
- The **leader** is a postman and a safety net for the run. As a postman, it takes a task from the server and forwards it to the worker,
  and passes the worker's answer (the whole message) to the server. It sees only the frame of the scheme —
  the block number and name, the command; the spec and the data are seen only by the worker. As a safety net: if the run
  goes off course, it sorts things out itself and in time — it decides a decision that Jev did not decide (it sees the condition and the worker's
  answer), asks a worker that has gone silent, reissues a block that the worker failed (`again`, once),
  handles a worker's `ERROR`. It calls the human when the trouble is in the scheme or the spec, when a failure
  repeats, and to close the run. It does not edit the spec, does not check the work, does not do blocks itself.
- **PHP** (`lib/api/simple.php`) builds the task, sets statuses and decides where to go next —
  by the arrows and the conditions of decisions. It has no rules of its own: it calls the same engine, `lib/engine/`.
- **JS** in the browser only shows things: it picks up statuses by itself and colors blocks and arrows.

**Start of a run.** After reading the instruction, the leader calls `begin` **as its first action** — the starter
lights up at once, and the human sees that the run has started. Then it sets the watchdog and **introduces the worker to its role itself**:
the first message to the worker is a briefing, and **the server gives it as a ready string** (`op=invite`); the leader
forwards it as is, inventing nothing, then sends the first task. The human or the orchestrator gives the
leader **only the task link** from the 🔗 button (`docs.run&role=lead&…`; `role=lead` is for the agent,
the server does not need it: because of `&worker=` in the link, a leader once took itself for a worker); the worker
starts from a clean slate.

**The run language is the project language** (`projects.lang`, project properties → "Agents language"): the
instructions, tasks, server replies to the leader and watchdog lines are in it. In an English project the protocol
words are English (`NEXT`, `RUN PASSED`, `task in file`, `WORKER ANSWER:`, `SILENCE` …) — the glossary is in
[перевод.md](перевод.md); reply parsing understands both languages. The examples below are Russian.

**Team composition and run environment** are two folder settings (the "Agents" tab, composition first).
The server builds the agents' instructions from them (`lib/api/instructions.php`: `instrScenario()`,
`leaderDoc()`, `workerDoc()`); an agent learns nothing about other scenarios. The same tab has the
"Give the worker the overall scheme picture" checkbox (`share_scheme`): the worker gets the scheme outline as the file
`service/tasks/rN/схема.txt`.

*Team composition* (`folders.role_scheme`, migration `018`, `ROLE_SCHEMES` in `lib/folders/folders.php`):

| Composition | `role_scheme` | What it means |
|---|---|---|
| Leader does everything alone | `solo` (default) | the leader is alone — both leader and worker, in any environment of its own. It does not start subagents, `invite` is refused with 409, `worker.md` is not written, worker steps are not assigned. The environment choice is hidden, a saved environment has no effect |
| Leader — worker | `leader-worker` | there is a worker; the selected environment applies |
| … — consultant — …, … — auditor — … | `consult-worker`, `consult-audit-worker` | "later": can be selected, work like "Leader — worker" |

*Run environment* (`folders.run_env`, `RUN_ENVS`) — only in a team; there is no "not selected" option,
an empty field means "Subagents" (`folderActiveRunEnv()`):

| Environment | `run_env` | How messages go | Who does the block |
|---|---|---|---|
| Subagents (default) | `subagents` | the workers are the leader's subagents: a new one per task or one per run; the first message is the briefing and the task line, the answer is the final message in full | the leader decides: hands it to a subagent or does it itself; cards have no effect |
| Orca | `orca` | separate terminals, `orca terminal send`; the worker's address is `&worker=term_…` in the link, its own is `$ORCA_TERMINAL_HANDLE` in `invite&reply=`. No address — the leader creates the worker itself (`orca terminal create` from the card: `cli`, `model`, `effort`; `closeAfter`). If it lost the receipt's `requestId`, it does not resend blindly | a worker on the card — the worker; leader or empty — the leader itself |
| SendMessage | `sendmessage` | Claude sessions: the leader finds the worker through `ListAgents` (the name can be given in `&worker=`), sends `SendMessage` | as in Orca |

An agent card is a hint, not a condition for starting: an empty card or a leader on it does not hold up
the pre-check (`folderPrecheck`), nor the issuing of a step (`advanceIssue`, a step without `agent_id`), nor "STUCK"
(`goStuck`). A worker card goes into a step only where cards apply (`folderUsesAgentCards()`:
team, Orca or SendMessage) — both in the engine's move and in a manual `step.open`. The mark
`performer — leader: do it yourself` in the "NEXT" line is set by `goSelf()`: in solo — on every block,
in Orca and SendMessage — on a step without a worker, in Subagents — never (the leader decides). By the same `goSelf()`
the short summary `where&short=1` (`goBrief()`, read by the watchdog) appends `— you do it yourself`
to an open block that has not been handed in yet. The section
"Who is who" in the link appears only where cards apply. Assignments on cards are kept when the
composition or environment changes. The Orca prefix `WORKER ANSWER: ` is stripped by the server in `done`
itself. Tested on 22.09.2026: Grok and Claude passed the "Subagents" environment; Claude did not notice
someone else's "GROK · worker" card on the blocks.

**The leader's round** — plain `GET` requests to `api.php`, the answer is text:

```bash
G="http://localhost/goblin/api.php?project=<key>"

curl "$G&op=begin&folder=12"                      # pre-check → run rN → first thing to do
curl "$G&op=task&run=rN&block=5"                  # "task in file …/5.1.txt"; the block lights up as "in progress"
curl -G "$G&op=done&run=rN" --data-urlencode "result=5.1: ANSWER"   # accepted → next thing to do
```

And so on until the answer "RUN PASSED". The answer to `done` always already contains the next thing to do.

**The leader sees only the frame.** All answers to it (`begin`, `done`, `fail`, `again`, `where`, `wait`) are
one line per thing to do: block number and name, task address, state and command
(`goNextLines()`): `━━ NEXT: block 137 “Counting step” · task 137.3 · issued`. The command under the line depends on
the state: issued — `task → op=task`, in progress with the worker — `waiting for the worker answer · op=wait`, in progress with the
leader itself — `you do it yourself — submit: op=done` (otherwise the leader issued the task a second time). The worker
from the scheme is not in the frame: the worker that the leader called does the work, and a block without a worker
is not let through by the pre-check.
The spec, the input and the answers of past blocks are seen only by the worker — in the task file. Exceptions: a decision that
Jev did not decide (the leader is shown the condition, the worker's answer and Jev's hint, otherwise it cannot choose a branch),
and once at `begin` — the run description from the starter and the frame of the whole scheme "SCHEME OUTLINE" (section 4). A decision, a gateway and "STUCK" are not hidden in the answer. The `&short=1` key on `begin`/`done` is left over
from the old form and changes nothing; on `where` it still gives a one-line status.

What else is in the answer to `done` (and `fail`, `again`), in order:

- confirmation: `ok: block 137 accepted · task 137.3 · answer “a=13 b=3”` — the accepted part is in quotes,
  so that the answer's separators are not mixed up with the line's separators;
- lines about decisions that the engine's move decided right after the command: `decision 138 → no · Jev: no 1,00`
  (`goMoveLines()` — the `decide` events of this move). Without them the leader guessed the branch from the next block;
- `⚠ …` — a soft check (`goAnswerWarnings()`): the answer does not match the `answer` pattern (the `answerFits()` grammar),
  `calls=K` is greater than `paid_calls`, or the numbers do not match the arithmetic of the `expr` block (`exprCheck()`, like
  the formal judge; the server does not name the expected numbers). The answer is accepted anyway — the leader decides;
- for a gateway — the name of the target folder and that the target's run does not start by itself;
- the next thing to do; at the end — `RUN PASSED · acceptances 10: blocks 6, decisions 4` (`goTally()`, the format
  is fixed: decisions are acceptances too, a loop block is accepted as many times as there were rounds).

A worker who realizes after sending that its answer is wrong in substance writes to the leader
`ERROR: task 137.3 — …`. This is not an answer: the leader does not pass it in `done`. If the block is not yet accepted,
the worker redoes it and answers again; if it is already accepted, the leader calls `op=again&block=N` and gives the task
to the worker again.

| Command | What for |
|---|---|
| `scheme&folder=N` | the scheme on one screen |
| `done&block=N&branch=yes\|no` | a decision that Jev did not decide (not sure, or unavailable) |
| `fail&block=N&why=…` | the block failed |
| `again&block=N` | redo the block (see below) |
| `where[&short=1]` | where we are; `short` — in one line: accepted, rounds, what is in progress |
| `wait` | wait for changes, up to 25 s — when there are several workers |
| `stop` | close the run — only on the human's word |

**Task as a file and the round address.** Two reliability mechanisms, working together.

*The task address* — `block.round`, for example `137.3`: block 137, attempt 3 (for a loop, the attempt is the
round). The server sets it (`goAddress()`) in three places: in the first line of the task ("Block 137 "Counting step" · task
137.3"), in the answer pattern ("Answer in one line like: 137.3: a=<number> …" — even
if the block has no `answer` property) and in the name of the task file.

*The task as a file* — `op=task&block=N` (the default; the old `&file=1` changes nothing). The server
writes the task text to a file and answers with one line, "task in file PATH" (`goTaskFile()`). The text
itself is `&text=1`: for the human and for checks, it is not in the leader's instruction. The leader forwards this line to the
worker, and the worker reads the file. The leader writes nothing to disk and invents no names: before, the round
number in the file name was counted in its head by the leader model, which could get it wrong. The path:

```text
<work folder>/service/tasks/r<N>/<block>.<round>.txt     for example …/proba-3/service/tasks/r134/137.3.txt
```

Inside the work folder — on purpose, like the whole service folder of the run (`runServiceDir()`): the worker
is started in the work folder and may be unable to read beyond it, or may get stuck on a permission question.
The tasks are separated from the worker's files by the `service/` folder, and from the browser by its `.htaccess` (`serviceDirMake()`).
A repeated `task` for the same step overwrites the same file. Tasks pile up by run
(`tasks/r140`, `tasks/r141`…) like a journal — on purpose, a scheme reset does not touch them. Auto-cleaning exists, but is
switched off: `tasks_autoclean => true` in `config.php` — and a scheme reset will remove the tasks of past
runs. The same text is also kept
in the run feed ("worker task"), so the folder can be cleaned by hand if needed.

*The round check in `done`.* The worker starts its answer with the address, and the leader passes the answer in full:
`done&result=137.3: a=13 b=3`. The server cuts off the address (`goAnswerAddress()`); only "a=13 b=3" goes to the step and to the "Input"
of the following blocks. The block number is taken from the address, so `&block=` can be left out.

*The block description outranks the spec.* If a block has a description filled in (`elements.description` — a short line
on the card), `goTaskText()` puts it in the task under the header as the line "Description (takes precedence over the spec; if they differ,
follow the description): …". The rule for the worker is in its instruction (`workerDoc()`): the spec can be long and lag behind edits,
the human sees the description on the canvas and edits it first, so when they differ, the description is right.

*Block assets.* Files attached to a block (roles `reference`, `input`, `attachment`, without a run mark)
are listed by `goTaskText()` as the line "Block assets (read before working): in/grok.md
(reference), …" (`goBlockMaterials()`; the path is from the work folder). This way the instruction for a service (`grok.md`)
reaches only the blocks that use it: it is put in `in/` and attached to them, not written into the spec.

*Run files.* The line "Run files (already done): out/rN/…" — everything already in `out/rN/`
(`goRunFiles()`). The "Input" carries only direct predecessors, while a block after a fork also needs the files from further up.

*Repeat by loop.* A back arrow brings the block one mark — from the decision — and previously the "Input" of the second
round lost everything that came by direct arrows (the story topic, the pictures for assembly). Now `goInputs()`
adds the "Input" of the previous accepted attempt of the same block: the block's fresh line replaces the old one,
the rest are marked "(previous input)". Under the header there is the line "Round 2 — returned by decision 44 "…": branch no"
(`goRoundLine()`): the worker knows why it is doing it again. A redo by `again` has no such line — the input is the same.

*Run spending.* The line "Paid calls in the run so far: N" (`goSpent()`) — the sum of `calls=K` from the
accepted answers of blocks with the `paid_calls` limit. The final block does not need to search through other tasks.

*The worker's note before the address.* Through Orca the worker sends one line without line breaks, so it
puts the note before the address: `Note: DSC09524.JPG is missing — 76.4: a=4, …`. A subagent writes
its explanations in lines above the answer — the leader passes the message in full. The address is also looked for inside the text
(after a space); if several match the open round ("answer to task 43.1: …" in an explanation),
the last one at the start of a line is taken. Everything before it, on one line, is the note: a `message` event from the worker
"worker note 76.4: …" in the feed, and the tail "· worker note written to the log: "…"" in the leader's
answer. Only the answer from the address goes to the step and to the "Input".

Further, under the run lock:

| Situation | Server answer | Code |
|---|---|---|
| address = the open round of this block | accepted | 200 |
| address = a past round, it was accepted with the same text | "repeat: task 137.2 already accepted", nothing changes | 200 |
| address = a past round, the text is different, or the round is not open | "The answer to task 137.2 is not accepted: task 137.3 is open now" | 409 |
| address of a different block than `&block=` | "answer to task 140.1, but done is for block 137" | 409 |
| no address | accepted as before, without a round check; only the old repeat protection works (the same text within a minute of `task`) | 200 |

Why: in a loop the same block is at once issued for a new round. Without an address, an old or repeated answer
(the worker sent it twice, delivery was delayed, the leader got confused after memory compaction) would be accepted
as a new round without the worker's work, and the loop count would quietly go wrong. With an address, an answer with the right address
is accepted even if the text matches the past round ("done" every round) — there is no longer any need to guess a repeat
from the text.

Erased numbers are not reused (`stepNextAttempt()`): after `again` a new issue gets
the next number, and the server also rejects a late answer to an erased attempt. The exception is an issue
that was withdrawn before `op=task`: nobody saw the task, the event "issue 109.1 withdrawn" goes without an attempt
number, and the next round gets the same number. Checks: `check-simple.mjs`, section 13.

**Work folder: `in/`, `out/rN/`, `service/`.** Nothing is put in the root of the work folder:
- `in/` — the human's assets (uploads from the "Assets" tab go here); the worker only reads;
- `out/rN/` — the results of run rN. The server creates the folder when the run starts (`runOutDir()`, in
  `goBegin` and `runStart`), runs **pile up** next to each other — nothing is erased or
  carried off to an archive. In the worker's task there is the line "Assets — in/. Put results in out/rN/ — where the task or
  the "Input" says out/…, it means out/rN/" (`goTaskText()`): the spec and the answers of past blocks simply write
  `out/…`. `done` looks for a named file first in `out/rN/`, then at the path as given (`goCatchFiles()`).
  A repeat of a block writes to the same file, so when the task of the repeat is taken, `goKeepTries()` copies the earlier
  version to `out/rN/.попытки/42.1-story.md`, and the record of the past attempt points to the copy. The dot in the name
  hides the folder from the "Run files" line;
- `service/` — the server's service files: tasks (`service/tasks/rN`), step tokens (`service/steps`),
  run tools (for example, the face detector;
  instructions for services like `grok.md` are in `in/` and attached to a block). Visible to the worker, closed to the browser.

**Before a new run** `begin` itself does the same as `run.prepare` with `files` (`runPrepareDo()` under the
lock): the statuses of past runs leave the canvas; everything the run hung on cards (covers, step results)
is removed from the cards; `service/steps` is cleaned. Files that the run handed in outside `out/` move to
`out/rN/` of that run (`resultsOutsideOut()`). The human's assets (`in/`, `pic/`, the spec, its attachments and
covers) are never touched. The old backups `service/archive/out_oldN` (before 22.09.2026) stayed
as they were and are still open to the browser; no new ones appear. The old layout (`pic/`, a flat `out/`)
is not supported and not taken into account: assets only in `in/`, results only in `out/rN/`.

**A step's result is only what the step made.** `done` hangs on the card the files named in the worker's
answer, but only those created or changed after the step was issued (`filemtime` against `opened_at`).
A mentioned source file ("took test.png") does not become a result — otherwise a reset would carry it
to `out/rN/` as the run's work. One file — one author: a file in the same form (path and sha256) that
another block of this run has already handed in is not hung again — even if the next block is issued in the same second.

**The run feed is the whole correspondence.** The leader's run writes to the feed (`run_events`) who received what:
- **leader ⇄ server** — a line for every leader request: `→ done · 137.3: a=11 b=1  ← ok: block 137
  accepted…`; expand it — the full server answer, errors too (`goExchangeLog()` in `plain()`, kind `message`,
  the "correspondence" filter). Status polls (`where&short=1`, `wait`) are not written — the watchdog calls them too;
- **server → worker** — the event `задание worker · 137.3.txt`: expand it — exactly the text the
  worker received, word for word (`goTaskLog()`; the same text is not written again);
- **worker's answer** — `ответ 137.3: …` (the `submit` event).

Also in the feed: `warn` — the "⚠" lines of a `done` answer; `stop` and `cancel` — the stopping of a run and cancelled steps
(a stopped run also has `finish`); `pass` — with the target folder, `start` of the target's run — with `from_run`/`from_step`
of the source; Jev's questions about a decision are attached to the decision's step. The leader's correspondence stores `took_ms` — the server time
per command; the full task text is stored once, in the `package` event.

**Decisions are decided by Jev.** A simple-path run is created with `driver = lead` and `jev = judge`. The condition
formula is **not computed** by the engine for such a run: the worker's answer can be free text ("now a
equals six"), and a formula over it would go wrong silently. Jev receives the decision's condition (`cond`, and if there is none —
the decision's spec) and the answers of the blocks at its input (through other decisions — up to the real block) and says "yes" or
"no" (`advanceLeadRun()`, `advanceBranchQuestion()` in `lib/engine/advance.php`). The confidence threshold for a decision is 0.6 (`JEV_BRANCH_CONFIDENCE` in `lib/engine/judges/jev.php`, a decision
of the human; step acceptance has its own thresholds — 0.90 “accept” and 0.15 “return”, `JEV_JUDGE_DEFAULTS`). A decision with
confidence below the threshold, or without a connection — the decision waits for the leader, and the leader sees the condition, the worker's answer and
Jev's hint. A request to Jev goes over the network, so the simple-path commands do a full `engineAdvance`
(`goMove()`), not `advanceAfter`. For runs of other drivers the engine computes the formula, as before.
Later Jev can be given more context — for now only the condition and the answer.

**Input — verbatim, numbers are not parsed.** In a leader run the server does not pull numbers out of answers:
there is no "Input: a=1" line in the task, only the answers of past blocks as they are ("block 179 → now a
equals 2, frame 2832 × 4240"). The next worker and Jev understand what is `a` and what is a size. Parsing numbers
went wrong silently (a size with a space became a variable) and is no longer needed: decisions are decided by Jev.

**The leader's watchdog is a safety net, not a working round** (`bin/lead-watch.sh <key> <rN> [seconds]`).
Claude does not wake up by itself: if a worker went silent, the block would burn "in progress" forever. Right
after `begin` the leader starts the watchdog as a background command. Every 30 seconds the watchdog reads `op=where&short=1`
and exits — this wakes the leader — only when it is time to step in: `SILENCE` (the status has not changed for
5 minutes, the third argument is the threshold in seconds), `RUN CLOSED` (noticed within half a minute, there is no need to remove the watchdog
by hand — `pkill` gives a false alarm), `NO STATUS` (two times in a row there is no status line:
the server is down or there is no run). The watchdog writes nothing to the run. The leader's reaction
(the leader's instruction, section "Watchdog"): the first silence on a block — ask the worker and set the watchdog
again; the second in a row — tell the human and wait, do not close the run; if the block is the leader's own, do
it. `SILENCE: … block N … — you do it yourself` (the leader's block, see `goBrief()`) — hand in with `done`, if it does not work —
`fail` and tell the human, then set the watchdog again. It is checked by `check-simple.mjs` (section 13) at a 1 s interval.

**Leader failures:**

- the worker's answer is stored in full (`run_steps.result` — `TEXT`, migration `010`); before, an answer longer than
  200 characters broke the whole handing in;
- a repeated `done` (curl resent it): if the block was accepted a minute ago with the same text and the new issue has not
  yet been taken in `task`, the server answers "repeat: already accepted" and does not hand in a new round;
- a block that the engine will not issue by itself (failed, cancelled, no spec, attempts ran out, two
  returns) is shown as "━━ STUCK: block N — reason" with `again` and `stop`, without a false `done`
  (`goStuck()`);
- `task` without a number, when several blocks are open, does not guess but lists them (409);
- `stop` on a closed run changes nothing; `begin` says so if the first thing to do was not issued;
- a file for a card is taken only from inside the work folder: the check is done with a slash, a neighboring folder
  with a similar name (`faces` / `faces-2`) does not pass.

**Work on trust. Nobody reviews.** The worker finishes — and writes the leader "done"
(or a line by the pattern). The leader passes this to `done` without checking, and the server accepts it. Nobody
checks sizes, files, numbers or conformity to the spec: neither the leader nor the server. A run on this path
is created with `judge = human` and `jev = judge`, but Jev decides only decisions: `done` calls `stepAcceptDo`
directly, bypassing the checks and bypassing Jev.

The result is checked by **the next worker** — the one who takes it as input. If something is wrong, it asks the
leader or decides itself what to do. There is no separate control in the round.

The answer pattern (`answer`) is a hint to the worker on which line to answer with, not a check: an answer of another
form is accepted too. Placeholders: `<number>`, `<word>`, `<file>` (the same as a word, but the worker sees
that a path is needed), `<text>`. The server does not parse numbers out of the answer: the whole answer goes verbatim to the "Input"
of the next task and to Jev for a decision. The server looks for file paths only in order to hang a picture
on a card.

**A plan for the future — the auditor-reviewer.** A separate role that does not exist now. The scheme will be like this:

```text
worker → "done" → leader → done → reviewer checks the result → accepted / return to worker
```

The reviewer looks at the result against the block's spec (files, sizes, answer pattern, meaning) and either lets it
pass on or returns it to the worker with a reason. It will be switched on by choice — per scheme or per
block. Ready groundwork in the engine: formal checks (`lib/engine/checks/`), judges
(`lib/engine/judges/`), the block properties `accept`, `reject`, `judge`. For now they are not
called in the simple path, and there is no need to switch them on without a reviewer.

**What the server puts in the task itself**, so that the leader has nothing to add: the block number and name with the task address,
the work folder, the verbatim answers of the predecessor blocks (through
decisions — up to the nearest real block), the paid-call limit (`paid_calls`), the answer pattern
(`answer`) and below that — the human's spec unchanged.

**Files on a card.** The server finds a file path in the worker's answer (`out/cutout.png`) by itself. If the
file lies in the run's work folder, it becomes the step's result, and the first picture becomes the block's cover
on the canvas.

**Redo — `again`.** An accepted block is erased and issued again with the same input. The number of the erased attempt
is never issued again: `again` leaves in the feed the event "attempt 137.3 erased" (`reset`), and the new issue
gets 137.4 (`stepNextAttempt()`). Otherwise a late answer "137.3: …" to the erased attempt would be
accepted by the new one. Everything after the block that is still without work — untaken issues and decisions' results — `again`
removes along with it, the deepest first (`stepResetDo()` → `stepErase()`), and writes "also removed: 109.1
(issue), 108.1 (decision)". Further down the chain, if a block is in progress or already accepted — the refusal "Reset first …"
with the full list of such blocks: the server does not throw away someone else's work by itself. In a loop where a new round has only been
issued, `again` redoes the past handed-in round — the issue goes away along with it, in one command. The `max_attempts` limit
counts the rounds that took place, not the numbers (`stepOverLimit()`): a round erased by `again` does not count. `again` on the open issue or decision itself removes them without reopening;
`where` then issues everything that is ready.

**Highlighting.** `begin` — the first block is issued · `task` — the block is in progress · `done` — the block is accepted, the arrow
is passed, the next one is issued · `fail` — the block failed · `again` — the block is issued again.

**Check:** `node tests/v2/check-simple.mjs` — goes through this whole path on a temporary scheme.

## 3. Glossary

| Word | Meaning |
|---|---|
| Run `runs` | one pass through a folder; the run label `rN` is the number in the project, `id` is the row key |
| Attempt `run_steps` | the execution of an element; the address `14.2` is element No. 14, attempt No. 2 |
| Mark `run_marks` | "you may go along this arrow": put down at acceptance, taken at issue, put back on return, cancellation and failure. `pass` is the round number on the arrow |
| Move | `engineAdvance()`: everything that can be done without a human |
| Driver `driver` | who asks for a move: `lead` — the leader agent of the simple path (all new runs); `manual`, `utility` — for old runs. The rules are common |
| Judge `judge` | who says "accepted": `human` or `formal` |
| Jev `jev` | `off`, `advisor` (advises), `judge` (accepts by meaning); does not depend on `judge` |
| Display pause `show_pause` | N seconds per block and N per arrow, so that a human has time to see; 0 — full speed |
| Version `version` | grows only on a real change; those who wait are woken by it |
| Event cursor | `lastEvent` / `sinceEvent` — the numbers of `run_events.id`; delivery of what was done without losses or duplicates |
| Work folder `work_dir` | the scheme's folder on disk: `in/` — assets, `out/rN/` — the results of run rN, `service/` — service files |
| Service folder | `<work folder>/service/` (`runServiceDir()`; without a work folder — `storage/runs/<id>/service/`): tasks `tasks/r<N>/<block>.<round>.txt` and the overall picture `tasks/r<N>/схема.txt`; closed by `.htaccess` |
| Task address | `137.3` — block 137, round (attempt) 3; it stands in the task, in the file name and at the start of the worker's answer |

States: a run — `running`, `paused`, `stopped`, `done`, `failed`; an attempt — `issued`,
`running` (in progress), `submitted` (handed in), `accepted`, `returned`, `failed`, `cancelled`.

`engine = 2` — all new runs. `engine = 1` — runs created before the engine: they live out their days on the old
accounting, the engine's move does not touch them.

## 4. What a scheme can do

Three types are executed; a group and an area give context to the spec, everything else is not executed.

| Type | How it is executed |
|---|---|
| Block | issued to a worker or done by the leader (section 2, "Team composition and run environment"); a spec is needed (an asset with the role `spec`) |
| Decision | is decided, not issued: an immediately accepted attempt with the branch `yes` or `no` |
| Gateway | `step.pass`: an accepted attempt and the number of the target folder. A child run is not created |

The `props` properties that the engine reads:

| Property | Applies to | Meaning |
|---|---|---|
| `answer` | block | the answer pattern: `a=<number> s=<number>` — the form; `rounds {a}, sum {s}` — the form and the values. Only ordinary characters (letters, digits, space, `,` `:` `=`): with `·`, `—`, `→` the worker model wastes time — it checks the character code and sends the answer again (runs r135, r136) |
| `expr` | block | arithmetic against the input: `a = a + 1; s = s + a`. Without assignments — a mark, not a check |
| `cond` | decision | the condition: `a > 5`, `a >= 3 && s > 10`, `(a == 1 \|\| b == 1) && c == 1`. A verbal one is for a human or Jev |
| `join` | block, decision | `all` (default) — wait for marks from all direct inputs; anything else — one is enough |
| `max_attempts` | block | the limit of issues, including loop rounds and reworks. For a block in a loop without its own limit the server sets 20 (`LOOP_ATTEMPTS`, `graphMaxAttempts()`), and `begin` warns about it — the run does not spin forever |
| `trust_form` | block | accept by pattern without variables |
| `start` | block | **the run starter**: the input of the scheme; no worker is issued, it is accepted at once at start; its spec is the run description for the leader |
| `accept`, `reject` | block | criteria of meaning for Jev: what must and what must not be true |
| `judge = human` | block | close the block to Jev: only a human decides |
| `outputs`, `paid_calls`, `timeout` | block | names of outputs for files on a card; a guideline for paid calls (`calls=K` in the answer — "⚠" when exceeded) and a time limit — hints for the worker, the server does not enforce them |

**One place for one rule.** The answer pattern lives only in `answer`, the decision's condition — in `cond`,
the work — in the spec (the asset `spec`). The element description (`description`) is a short line on the card; it
goes to the worker in the task and outranks the spec (section 2, "The block description outranks the spec"). Do not repeat the pattern or the formula in
the description: when one place is edited, the second starts to lie (this happened with block 139 of scheme 8544).

**Variables.** Numbers from the result (`a=3 s=6`, `rounds 6 · sum 21`) become the step's variables:
the input's variables plus the parsing of its own result. A decision and a gateway carry them over unchanged. Two inputs
of a merge with different values of one variable are a conflict, and a human decides.

A size is not a variable: from `frame 2832×4240` and `1024x1024` we take no numbers at all (`varsParse()`).
Otherwise `кадр=2832` went further down the scheme as the truth, and the second half was lost.

**A back arrow** (`back`) closes a loop and works by itself, independently of `join`.
**Start.** If a folder has a **starter** (a block with the `start` flag), that and only that is the input. With no starter,
the input is found by arrows: a node without direct inputs; if there are several inputs, the simple path does not lead such a scheme,
a starter is needed.

**The run starter** is a special case of a block: a circle on the canvas, with only the number and name inside.
- It marks where the run starts. At start the engine accepts it at once (`stepStarterDo()`:
  an `accepted` attempt with the answer "start", the event "starter N passed"), and the mark leaves along its arrows.
  If the run began after a gateway into this folder — the starter's answer is "gateway from "A" · r12: block N "…" → …"
  (`runArrival()`: the last transition into the folder after its previous run). The first block sees it in the "Input".
  The server makes the `out/…` paths in the source's answer full: the target has its own work folder, and there `out/…`
  would mean its own `out/rN`.
  It is not issued to a worker; a worker, if assigned, is not used.
- The starter's spec (md) is the **run description**: `begin` shows it once to the leader in the block
  "RUN DESCRIPTION". It does not go to the worker.
- After that `begin` once gives the leader the **"SCHEME OUTLINE"** — the frame of the whole scheme in chains, without specs
  (`goSchemeMap()`): `◯190 Start → ▣75 … → ◆77 File exists?`, the branches of a decision on their own lines
  (`◆77 yes → …`), a node already shown — by its number (`▣76`). With the note: only for understanding the
  context, the server leads the run. It exists without a starter too.
- There is one starter in a folder: the canvas clears the checkmark on other blocks, the server refuses the second one
  (`starterGuard()` — when the flag is set and when a block is moved into the folder). When pasting from the clipboard the
  flag is kept if the folder has no starter yet, otherwise it is removed.
- Pre-check: a second starter, an arrow **into** a starter, a starter without an exit, another node without an
  incoming arrow — "SCHEME NOT READY". `again` on a starter is refused.
- Where the rules are: `folderStarters()`, `starterGuard()`, `isStarter()` (`lib/elements/props.php`),
  `graphStarter()` (`graph.php`), `readyList()` does not count other nodes without input as an input,
  `advanceIssue()` passes the starter, `runEntries()` gives it as the input.
**The end** is an accepted block or a passed gateway with no outgoing arrows.
**Links** `link`, marks, tables and frames are not seen by the engine.

## 5. Before starting

The pre-check is done by `op=begin` itself; to look at the scheme — `op=scheme&folder=N`.

The pre-check (`folderPrecheck()`) names everything that gets in the way: the work folder is not set or does not exist on
disk; a block without a spec (the pre-check does not look at the agent card — it is a hint); a decision without the condition `cond` and without a spec; a decision without a "yes" or "no" exit; a gateway without a target; a block
that gathers the branches of one decision but waits for all inputs (`join = any` is needed); a loop without a back
arrow (`folderLoopWithoutBack()`: the block at the start of the loop would wait for both the input and the return, and the run would stall
on the very first round — the server names the arrow to mark as `back`); a scheme without an input. `run.start`
does not call it by itself; `op=begin` calls it and, when there are problems, answers "SCHEME NOT READY" with a list.

Warnings (`folderWarnings()`) do not hold up the run, but are shown in `begin` (the `⚠` lines), `run.check`
(`warnings`) and `docs.run`: a loop block without `max_attempts`; `join=any` on parallel branches — the block
will be issued once for each branch with part of the input, `join=all` is needed.

## 6. How a move goes

A move is made by an explicit `run.advance`, and also by the commands themselves that need a next move: after their own
commit they call `advanceAfter()`. These are `run.start`, `run.resume`, `run.update`, `run.reset`,
`step.open`, `step.accept`, `step.return`, `step.decide`, `step.pass`, `step.submit`, `step.fail`,
`step.reset`. **They do not start a move:** `run.pause`, `run.stop`, `run.finish`, `step.cancel`,
`step.reissue`, `step.note`, `step.job`, `step.take`, `step.state`. In one move, going around, no more than
50 actions and no more than 5 requests to Jev (`ADVANCE_JEV_LIMIT`; the rest on the next move):

1. the run is not `running` — nothing; a human asked to stop — the open steps are cancelled, the run becomes `stopped`;
2. the display pause has not expired — nothing, `wakeIn` in the answer;
3. **acceptance** of what was handed in — decided by the run's judge;
4. **decisions** with a computable `cond` — decided by the input's variables;
5. **issue** of ready blocks;
6. **the end** — if the run is passed, it is closed as `done`.

What the run is waiting for is written in the `runs.wait_for` line — the run bar shows it.

**The engine does not issue a block by itself** and calls a human if: the last attempt failed or was cancelled;
the judge itself returned it twice in a row; there is no spec; `max_attempts` is exhausted.
A gateway always waits for an explicit transition: `step.pass`, and in the simple path — `op=done&block=N` (`stepPassDo()`). Cancelling a step (`cancel`) does not start a move.

**The network only outside the lock.** A move after a command is deterministic and does not wait for the network, so `submit`
answers at once. A request to Jev is made only by a full move (`run.advance`; in the simple path — `goMove()`): a "waiting" mark under the lock → the request
outside the lock → fingerprint check and write under the lock. A request that broke off is counted after 10 seconds as
"Jev unavailable", and the step waits for a human.

### Acceptance

Formal checks are always computed first. Then — by the run's settings:

| `jev` | `judge = human` | `judge = formal` |
|---|---|---|
| `off` | waits for a human; the formal checks' hint next to it | formally correct — accepted; incorrect — returned; no check of meaning — waits for a human |
| any, the block has **no** `accept`/`reject` criteria | as with `off`: waits for a human, Jev is not asked | as with `off`: the formal check decides, Jev is not asked |
| `advisor`, criteria present | waits for a human; Jev's advice is added to the hint | the formal check decides, as with `off` (correct — accepted, incorrect — returned, no substantive check — waits for a human); Jev's advice is added |
| `judge`, criteria present | formally incorrect — returned; otherwise Jev decides: accept, return or "not sure" → human | the same |

Three rules on top of the table:

- The block property `judge = human` is stronger than the run's settings: such a block always waits for a human.
- Jev is asked only after a **valid** formal check: it has either passed or honestly
  said "no check of meaning". Jev does not get around a formula error, a lack of input or a variable conflict —
  the handed-in work waits for a human.
- Jev is unavailable, doubts, or the work is too long — "not sure", the step waits for a human.

A check of meaning is the arithmetic of `expr` with assignments or an `answer` pattern with `{variables}`.
Files and a pattern without variables are only technical validity. Nobody accepts "just in case".

- A wrong **form** of the answer — the refusal `bad_answer` right at hand-in; the worker redoes it by itself.
- A wrong **content** — the state `returned` by the judge's decision. The worker sees the reason without
  the correct numbers ("the arithmetic does not match the input"); the expected value is seen only by the leader.

### The end of a run

A run is passed when all of the following hold at once: there are no open steps; there are no marks lying around; at least one
end of the scheme is accepted; for every block that was opened, the last attempt is accepted. Otherwise the "what it is waiting for" line
names the reason; if there is nothing to open, it is a **deadlock**. A final gateway counts as an end
only after a real `step.pass`; the target folder is not started by this and does not count as passed.

## 7. Pause, stop, reset

To close a run — `op=stop` (only on a human's word), to redo a block — `op=again` (section 2).
There is no pause and no reissue of a token in the simple path — there are no tokens.

A full reset of a run (`run.reset`) with `engine = 1` **moves it to `engine = 2`** — the only exception
to the rule "old runs live out their days on the old code": after the steps are deleted there is nothing left to count the old way.

A human can set any state on a step by hand (`step.state`, only the human role); the marks
are brought into line. So the state must be reread, not remembered.

**Editing a scheme during a run.** While a run with `engine = 2` is `running` or `paused`, the server does not allow
changing the meaning of its scheme, the spec, the inputs and the agents (`conflict`, `active_run`); the styling and other folders
can be changed. To fix the scheme, the run is stopped. A project with a live run cannot be deleted either.

## 8. Failures and recovery

| What happened | What to do |
|---|---|
| the leader crashed or was replaced | the run stands and waits. The new leader calls `op=where&run=rN` and continues from the same place |
| the worker went silent | the watchdog wakes (`SILENCE`): the leader asks the worker once, at the second silence calls a human; the same `op=task` can be given to another worker — the block stays "in progress" as it was |
| the worker botched it (`ERROR: task N.K`), and `done` is already sent | the leader itself: `op=again&block=N` and the task again to the worker |
| the block was failed by the worker ("STUCK: failed") | the leader itself: `op=again&block=N`, once; a second failure — to a human |
| Jev did not decide a decision | the leader itself chooses the branch by the condition and the worker's answer: `op=done&block=N&branch=yes\|no` |
| the block does not work out at all | `op=fail&block=N&why=…` and tell a human |
| `SCHEME NOT READY` on `begin` | the list of problems goes to a human; the leader does not edit the scheme |

Where to look for the cause: where the run is — `op=where`; the feed — `run.log` (the "Run" tab);
the history of scheme edits — `journal.get`; external calls — `run_jobs`; worker tasks —
`<work folder>/service/tasks/rN/`; activity — `pulse`; the utility's watchdog (`bin/watch.php`) —
`data/сторож/ГГГГ-ММ-ДД.log`; the leader's watchdog (`bin/lead-watch.sh`) writes no log, only a line on exit.

## 9. What the browser sees

`public/src/run/paint.js` holds a long `GET run.state` (`since`, `wait`, `full`); on a new version it
reads `run.paint` and redraws: a block glows with the state of its last attempt, an arrow is `lit` (a
mark is on it) or `done` (all taken), the `×N` label shows how many rounds have passed along the arrow. A local timer
only handles showing the pause. The browser itself does not steer the run: from the run's `POST` the person has only
three commands — "Stop run" (`run.stop`), "Reset scheme for run" (`run.prepare`) and the manual step state
(`step.state`); it does not execute the `needsAdvance` field.
Details are in [frontend.md](frontend.md).

## 10. Jev in detail

- The key is in `secrets.php` (`jev_api_key`), the model is pinned: `jev-1.13.0`. The total request timeout is 5 seconds.
- Questions are built from the block's `accept` and `reject` lines; the answer is `accept`, `return` or `unsure`.
  Work that is too long gets an honest "not sure". The reason for a return is the text of the criterion itself.
- The decision is tied to a fingerprint: step, submission, spec, input, work, criteria, mode and model. If any
  of these changed, the old decision does not apply.
- A verbal decision is made by Jev only when `jev = judge`; the fingerprint includes the exact input marks, so
  the decision from a previous round of a loop is not replayed.
- `step.jev` is a separate advisor: it changes nothing, and with `jev = off` it does not go to the network.
- The quality of Jev acceptance has not been confirmed on independent human labeling: the example of six rows
  and the synthetic set of 25 rows test the wiring, not the accuracy. The threshold after which `jev = judge`
  can be recommended: at least 25 rows of one kind of work, zero false acceptances, no more than 10% false
  returns, no more than 20% doubts (`tests/v2/check-jev-dataset.php`).

## 11. Measurements and known limits

Run environment tests on 22.09.2026: 10 runs by the simple path on three CLIs (Claude, Grok, Codex) in three
environments — all of them, except the one stopped by a person, reached the correct result. The best time is 1:09 (Claude,
SendMessage environment). The server spends milliseconds per command; the rest of the time goes to the leader and
worker models and the delivery of messages between them.

What is missing, and this is not unfinished work but a limit of the current version:

- a permanent background daemon and a worker launch queue with process recovery;
- automatic continuation after a leader crash: the run stands still until a new leader calls
  `op=where`; an open page will not continue it;
- a gateway as a nested run: `step.pass` marks the transition, and the target run is started by a person (the input goes through a starter);
- freezing the assignment: the assignment is built from the current scheme, and changing its meaning during a run
  is forbidden by the guard;
- repeating a run on the canvas, a "warm" worker start, statistics of reviewers' decisions.

## 12. Decisions that must not be broken

1. The browser is only an observer: `GET`, long polling and highlighting. The browser does not move the run;
   of the run commands the person has only `run.stop`, `run.prepare` and `step.state`.
2. The run is driven by the leader agent; the server checks commands and stores the state.
3. With `jev = off` the run is fully working, and not a single request goes outside.
4. `judge` and `jev` are independent. Jev does not bypass the person: without criteria, when the block forbids it and in
   case of doubt, the person decides.
5. Runs with `engine = 1` finish on the old path; a full reset (`run.reset`) is the only exception.
6. The event cursor `sinceEvent` is separate from the version `version`.
7. A gateway does not start a child run; a final gateway is a valid end of a folder after `step.pass`.
8. There is no daemon: after a leader crash, continuing is only by an explicit command.
9. The run rule is written once — in `lib/engine/`. Arithmetic, comparisons, files and deadlines
    are checked by code; Jev is asked only about the meaning of text.

## 13. Run checks

```sh
node tests/v2/check-simple.mjs                   # the leader's simple path — the main run path
ENGINE_STAGE=3 node tests/v2/check-engine.mjs    # engine contracts: marks, merging, loops, reset, retries, sessions
php  tests/v2/check-finish-gateway.php           # final gateway
php  tests/v2/check-jev-engine.php               # Jev in the engine: network outside the lock, fingerprint, queue
node tests/v2/check-active-scheme.mjs            # protection of a live run's scheme
```

Each of these checks works on its own temporary project, stops its own runs and deletes
the project. The exception is not run checks but the editor browser checks `check-editor.mjs` and
`check-canvas.mjs`: they go into the real project `30vt4z512`, create their own objects there and erase them;
in `check-canvas` the cleanup is at the end and does not fire after a crash (details are in
[frontend.md](frontend.md)). Scheme 8545 and the user's runs are not touched by any check.
