# Goblin: frontend architecture

This document is the current map of the browser app and a guide for future changes.
Sources of truth: `public/index.php`, `lib/web/page.php`, `views/editor/`, `public/src/` and the current API.

Other documents: [backend](backend.md) · [run](progon.md) · [file map](structure.md) · [backup](backup.md).

## 1. Purpose and scope of responsibility

The frontend is responsible for:

- choosing a project and a folder;
- editing and displaying the scheme;
- the properties, agents, assets and AI assistant panels;
- the list, hierarchy and log of runs;
- read-only display of the runtime state and highlighting of blocks and arrows.

The frontend **does not run anything**. It does not start, advance, accept or return steps, and it does not decide
decisions. A person has only three manual commands:

- **"■ Stop run"** in the "Run" tab (`panel/tabs.js`) — stops the AI test run and the real run of the folder
  (`run.stop` with `now`: open steps are cancelled, the highlight freezes). The first click
  only asks "Sure?", the second one stops;
- **step state by hand** in the block card, the "Step" section (`panel/panel.js`, `step.state` — a
  right of the person). The server keeps the chips on arrows consistent by itself; "new" erases the attempt;
- **"↺ Reset scheme for run"** in the "Run" tab — `run.prepare` with `files`: the statuses of all blocks go back to
  "not passed", and the pictures and results of the previous run are removed from the cards; result files
  stay in `out/rN/` of their runs; the person's assets (`in/`) are not touched. While a run is going — refused, "Stop"
  first. Also with two clicks. `begin` of the leader does the same on its own.

After a stop, the run is pinned in the address (`&run=`) so the folder does not switch to a previous one,
and it is re-read at once (`refreshRun()` in `run/paint.js`) — even a closed one. After a reset, on the contrary,
the pin is removed (`setHash({run:null})`) and the folder is re-read (`loadFolder()`). Everything else belongs to the
leader agent: it sends `GET` to `api.php` (the simple path, [progon.md](progon.md) section 2), the server
sets the statuses, and the browser only picks them up and paints them. The Goblin CLI is a fallback path. The server
remains the source of truth.

## 2. Page load

```text
public/index.php
  → lib/boot.php
  → lib/web/page.php::renderEditor()
  → views/editor/layout.html + partial templates
  → import map for public/src/*.js
  → public/src/main.js
```

`?view=1` turns on read-only mode. There is no JavaScript bundler: ES modules are imported by the names
`goblin/...`. `lib/web/page.php` builds the import map and adds the asset version based on the last modification time
of files in `public/src`, `public/css` and `views/editor`.

`public/src/main.js` only sets the initialization order: API, state, left and right panels,
top bar, run bar, hints, AI, interface variants and polling. Domain logic
should not be added to it.

## 3. Client layers

```text
public/src/
├── api/       HTTP, hash navigation, loading and sync
├── core/      state, views, types and shared models
├── canvas/    cards, arrows, geometry, camera, styles and highlighting
├── edit/      editor operations and the write queue
├── left/      left rail of projects, folders, tools and settings
├── panel/     right panel and its tabs
├── shell/     top bar, dialogs, log, assets and the common shell
├── ui/        canvas/list/hierarchy/run variants
├── run/       read-only runtime state/paint and the run info window
└── main.js    module composition
```

The HTML shell lives in `views/editor/`, styles in `public/css/`. Do not move the server's business rules
into HTML, CSS or canvas modules.

## 4. Single state and events

`public/src/core/state.js` holds the shared model:

| Field | Purpose |
|---|---|
| `project`, `projects` | the current project and the available projects |
| `folders`, `folder` | the folder tree and the open folder |
| `elements` | executable and decorative elements by `id` |
| `links` | `link` links, separate from canvas elements |
| `agents` | project agents |
| `selection` | selection |
| `run`, `runs` | the selected run and the folder archive |
| `steps` | the latest attempt of a step by element number |
| `runState` | snapshot of `run.state` |
| `paint` | snapshot of `run.paint` |
| `variant` | `canvas`, `list`, `hierarchy` or `run` |
| `look`, `iso` | card look and orthographic mode |
| `dirty` | there are unsent changes |
| `viewOnly` | read-only mode |

Modules are connected through `on()` and `emit()`. A module changes the shared model and publishes a domain
event; direct calls to other modules' internal render functions create tight coupling and are forbidden.

Collections and existing objects are changed in place. Do not replace an object as a whole without need:
panels and the canvas may hold a reference to it.

## 5. API and sync

`public/src/api/client.js` is the shared HTTP client:

- `get(op, params)` — read;
- `post(op, body)` — a single command;
- `batch(ops, extra)` — an atomic batch of editor operations;
- reading and changing the hash `#p=<project>&f=<folder>&ui=<variant>`;
- unified error passing.

`public/src/api/sync.js` does the following:

1. `loadProject()` — project, folders, agents;
2. `loadFolder()` — the open folder and its scheme;
3. loading the archive and the selected run;
4. polling `changes` and applying server changes;
5. dropping responses of a stale project/folder;
6. reconciling the local edit queue with the server revision.

A scheme reset for a new run (the "Reset scheme" button, the leader's `begin`, another tab) changes
the folder's `style.preparedRun` mark and does not touch the runs themselves. So the `changes` poll, on seeing a new
mark for the open folder, re-reads the runs by itself (`loadRuns()`): the old highlight goes out within a second,
without a page refresh.

The generation counter is required for long requests: a response from an already closed folder or project must not
be applied to the new screen. When an atomic batch is rejected, `main.js` gets `resync` and re-reads the folder.

## 6. Scheme editor

Main modules of `public/src/edit/`:

- `scene.js` — operation queue, `dirty`, sending the batch;
- `pointer.js` — selection and gestures;
- `place.js` — how a new object lands on the canvas;
- `drop.js` — determines the destination of a dragged object: the hint on the canvas and writing the container's contents;
- `inplace.js` — in-place text editing;
- `clipboard.js` — copy/paste;
- `history.js` — undo/redo;
- `align.js` — alignment;
- `grouping.js`, `rehome.js` — containers and moving;
- `links.js` — links between folders;
- `volume-controls.js` — X/Y/Z handles: position and three sizes in the 3D view.

Edits are sent through `api.batch()`: the whole batch is committed or rejected. The server checks permissions,
revisions and the active-run guard; hiding a button in the UI is not protection.

**Drawing tool** (`state.tool`, the "Draw with" panel): a double click on an empty canvas
places an element of the selected tool (no selection — a block), and the tool stays selected.
A single click on an empty canvas clears the selection — with a 0.3 s delay, so that the second click
of a double click can cancel it (`toolOff` in `edit/pointer.js`). A click on an empty spot of the left rail or
the right panel also clears the selection (`initToolReset()`); buttons, fields and tiles do not count. Esc does too.

`beforeunload` tries to send the queue and warns about unsaved changes. In `viewOnly`
the editor handlers must not create operations.

## 7. Canvas

`public/src/canvas/` is split by responsibility:

- `view.js` — canvas DOM, camera and partial redraw;
- `element.js` — cards of blocks, decisions, gateways, groups, areas, notes and tables;
- `arrow.js` — lines, directions, branch labels and pass counters;
- `geometry.js`, `routing.js`, `geometry-legacy.js` — geometry and routing;
- `projection.js` — orthographic 3D view;
- `link.js` — links on owners;
- `looks.js` — appearance variants;
- `palette.js`, `volume.js`, `table.js` — specialized display.

An ordinary change redraws the affected element and the related arrows. A full rebuild is needed when
the scheme or the mode changes. The camera, zoom and UI preferences are stored separately from the runtime state.

## 8. Interface variants

`public/src/core/variants.js` connects one of four views to the shared model:

- `canvas` — the graphic editor;
- `list` — a table of elements and links;
- `hierarchy` — nesting levels as columns;
- `run` — a detailed read-only view of the selected run.

Views have no separate copies of data. A new view must use `state`, the API and domain events,
and when disabled it must release its handlers in `unmount()`.

## 9. Panels and tabs

### Top bar

`public/src/shell/topbar.js` serves navigation, undo/redo, view choice, look, view mode and
system actions. The markup is `views/editor/topbar.html`. Search (magnifier, ⌘K) by number and title:
the chosen element is selected, and the canvas smoothly flies to it (`flyTo()` with the `fitZoom()` scale,
`canvas/view.js`).

### Interface language

Labels go by keys: in JS `t('editor.…')` and `tn()` for numbers with a word (`core/i18n.js`), in views
`{{t:editor.…}}` — `lib/web/page.php` fills them in. The page gets the language dictionary at once, before the modules
(`window.GOBLIN_I18N`), so `t()` works at the top level of a module too. The language is chosen in the
"Settings" menu → "Language" (`left/settings.js`): cookie `goblin_lang` and a reload — the page is built
again in the new language. Rules and the term dictionary — [перевод.md](перевод.md).

### Left rail

`public/src/left/` shows projects, the folder tree, settings, drawing tools, colors and
related sections. This is the single current rail; the old `classic/rail` are not entry points.

The "Settings" menu: language, files (scheme catalog, builder, open, save), account, canvas settings,
documentation. "Guide", "Leader instructions" and "Lists" are buttons in the "Canvas settings" window
(`views/editor/dialogs.html`, the actions are the same in `left/settings.js`). "Info" is a block in the
folder properties (right panel, `infoSection()` in `shell/info.js`): what is in the folder and project, the open run and
"Worth a look" — a row leads to the element.

Where a run is going, the folder and project icon is green and pulses (class `live`): the `runFolders` and
`runProjects` lists come from `pulse` every 3 s (`shell/topbar.js`), and the `live-runs` event redraws the rail.

With tabs ("Classic", "Studio") the rail has three tabs: settings, "Projects" and tools. A click always
opens its own tab, and a repeated click does not collapse the rail. "Projects" is always the list of projects: one click makes the
project current (scheme on the canvas, properties on the right), a double click goes to its folders, with "↑ Projects" above them.
The settings icon in the header is hidden while the gear tab is visible (checked on the next frame after a skin change).

### Right panel

`public/src/panel/tabs.js` switches between:

- `props` — properties of the selected object;
- `agents` — project agents; at the top **"Team roster"**, below it (only when not solo) **"Folder run
  environment"** (`panel/agents-view.js`: Subagents — default, Orca, SendMessage; there is no "not selected" option;
  they write `folder.update roleScheme` / `runEnv`), below them — the **"Give the worker the overall scheme picture"** checkbox
  (`folder.update shareScheme`). Each folder has its own: the tab is redrawn when the
  folder changes (`panel/tabs.js`). What they change — [progon.md](progon.md), section 2. The "Leader instruction" button
  in the "Canvas settings" window opens the instruction built for the open folder (`docs.get&file=leader&folder=N`);
- `assets` — assets;
- `run` — the selected run, a short history and a link to the full log;
- `ai` — the assistant, chats and a safe test pass of the scheme.

The active tab is stored in `localStorage` (`goblin-panel-tab`). `panel/panel.js` is responsible for
object properties, specs, links, tables and assignments. An async tab must check the render
generation before inserting a response.

### Run

`public/src/shell/runbar.js` shows the state, waiting, steps and workers. The old HTML markup of
the runtime buttons stays for compatibility, but JS hides it and does not attach handlers.

`public/src/run/start.js` only opens a message: the leader starts the run. The
"Start run" button in the panel does not call `run.start`.

## 10. Log, assets and AI

`public/src/shell/runlog.js` reads `run.log`, builds the short and full feed, filters events and
loads the heavy body only on expand.

**The look of a log line is the same in both feeds** (the short one in the "Run" tab and the full one in the "Run" view).
At the top is the header: `r137 started 22.09.2026 11:00:54` — the full date once. Then one line per event
in two rows: `step no. · mm:ss · icon and element number · who`, and below it — what happened. The step number
runs through the whole run (the filter works on the client so that numbers do not get mixed up); when the hour changes,
the time is written in full. The element type is an icon, without words: ▣ block, ◆ decision, ⬡ gateway, ◯ starter (one set for the whole editor —
`iconOf()` in `core/kinds.js`);
the words "decision 138", "block 137" in the event text are also replaced with icons (`iconify()`). A question and answer
of Jev without an attempt number belong to the decision's attempt that follows (`stepKeys()`).

Assets are read and changed through `asset.*`. An asset can be **dragged onto the canvas**: a tile or a row
from "Folder assets" or from the block's assets is dropped on a block, group or table — it is
attached to them (`asset.link`, role `attachment`; `edit/place.js`, `ASSET_DATA`). Dropped on
a selected one — it goes to the whole selection; one already attached is not attached a second time. While dragging,
the receiving card is highlighted. The spec is a separate asset with the role `spec`; result files
are linked to a step and, if needed, to the element's cover.

**Display delay is a clarity feature, and it is configurable.** Each transition on the screen is held for N seconds
(`public/src/run/paint.js`, the display queue; the duration is `runShowMs()` in `core/settings.js`). In the settings
window there are two rows: "Run: display delay" (on/off, `runShow`) and "how long to hold a status, s"
(0.5–10, 3 by default, `runShowSec`); the choice is stored in the browser (`goblin-settings`). When off,
there is no queue, and the canvas shows the latest server state at once. The delay does not slow the run itself:
it is only the display, and the server pause `show_pause` is something else. The server changes a block's state within a second,
but on the canvas each new state of a block and arrow is held for this duration and blinks the whole time. Whatever arrives during those seconds
goes into the queue and is shown next, in order: no status is lost, and the last one always reaches the screen. Each new pass along an arrow first runs this duration (class `live`), then becomes
passed — even if the server returned it already passed: in the leader's round `done` takes the chip off the
arrow within milliseconds. The first picture of a run (the page was opened, the run was switched) is laid out at once, without
the queue. The log of what was lit is `window.__подсветка`.

**Work pictures on cards.** When a worker names a file in its reply, the server puts the first picture
on the block as a cover (role `cover`). It is not visible in all canvas looks (`public/src/canvas/looks.js`):
the cover is shown by "Classic", "Designer", "Showcase" and "Studio", while "Working" (the default look),
"Developer" and "leader" hide it. If you cannot see the picture, change the look first.
The picture itself loads through `api.php?op=asset.file&asset=N`.

The AI tab (`panel/ai.js`) manages chats and the test playback of the scheme. The test pass uses
made-up outcomes and is not a real runtime run. Short text markup (bold, code,
lists, links) is shared by the chat and the "New scheme" window: `ui/rich.js`.

### Assistant chat: voice, scope, link

- **Voice** (`panel/voice.js`): a microphone next to "Ask". On click the server gives a temporary token
  (`voice.session`), and the browser opens WebRTC to OpenAI; while it connects the button is dimmed, and when it
  turns red you can speak. Words appear in the field as you speak (the `…transcription.delta` events), at the
  caret. The second click hands over the rest of the audio (`input_audio_buffer.commit`), waits for the result; the text
  **stays in the field** — only "Ask" (or Enter) sends it. Editing the field by hand during dictation takes priority: new words go at the
  caret, erased text does not come back (the final text of an edited phrase is not put back). If the microphone is denied —
  a hint shows where to allow it.
- **Speaker** — its own option, independent of the microphone: when on, every new reply of the assistant is read
  aloud (`voice.speak`), however the question was asked; off by default, the choice is remembered by the browser
  (`goblin-ai-read`); turned off — a reply that is playing goes silent. Past replies are not read when the history is opened.
- **Scope** — the "Folder | Project" switch: what the assistant sees, the open folder or the whole project.
- **Link** — the address of the page `chat.php?project=…&chat=…` (the whole conversation: questions, replies, train of thought,
  cost; `shell/chatpage.js`): it is copied and at once opened in a new window. The same page is the "Log"
  of a conversation in the account. A line is drawn by a single `chatLine()` (`ui/rich.js`) in the chat and on the page.

### The "New scheme" window

`shell/newscheme.js`, markup `#dialog-newscheme` in `views/editor/dialogs.html`; it opens
`openNewScheme('catalog' | 'builder', {goal})`. Entry points: the "▦ new scheme" button above the folders, "Scheme catalog" and
"Scheme builder" in the left rail settings, an item of the AI quick menu. The window tab is `body.dataset.tab`
(not a class: the class `ns-catalog` is taken by the catalog grid).

- **Catalog** (`catalog.get`): sections on the left, search, cards; a scheme card has a preview (SVG `miniMap`
  built from the server's `preview`), a passport, the folder name, "Create folder" (`template.apply`) and "Tune in
  the builder" (the builder with a goal taken from this scheme).
- **Builder** (`ai.build.*`): goal and examples → a question card (options, `multi`, own answer,
  "Enough, build it") → waiting (server poll every 1.5 s) → draft: preview, pre-check result, name,
  "Create folder", "Refine" in words. If it fails — "Retry" (`retry`). After creation the window
  closes and opens the new folder.

When narrow (up to 860 points) the catalog sections are a row with scrolling above the cards, and the preview and passport are a
column (`panel.css`); in the mobile skin the window is full screen, and the buttons are 44 points (`mobile.css`).

## 11. Read-only run highlighting

`public/src/run/paint.js`:

1. waits for `run.state` with a long-poll request (`since`, `wait`, `full`);
2. on a new version, reads `run.paint`;
3. puts the snapshots into `state.runState` and `state.paint`;
4. publishes redraw events;
5. stops watching after completion or a run change.

The server passes the states of blocks and arrows, the pass number and the time. The local timer serves only
the visual phases of `show_pause`; it does not call `run.advance` and does not change the DB. For example, `pass: 6`
is shown next to the arrow as `×6`.

For engine 2 the server paint has priority. Legacy engine 1 may use a compatible fallback
by steps, but the client must not mix the two sources.

The browser is allowed the reads `run.get`, `run.state`, `run.paint`, `run.log`, `run.timeline` and ordinary
project sync. Runtime POSTs from the browser are forbidden by the architecture contract — except for the three
human commands: `run.stop` ("Stop"), `run.prepare` ("Reset scheme") and `step.state` (manual
block status).

## 12. Details people trip over

**The path of an edit to the database.** A mouse or panel action calls `edit/scene.js`: the change is visible at once
locally, and new elements temporarily get negative `id`s. The queue collects operations for half a second and
sends a batch; the server returns permanent identifiers and the revision. `changes?since=REV`
is polled once a second; polling is skipped for a hidden tab, a non-empty queue and a project
that is not loaded. The undo/redo history is local and is cleared when moving to another folder. For a new field a
column and an HTML field are not enough: you need writing, output in the scheme, output in the delta and an update of the consumer.

**Camera.** `canvas/view.js` stores the offset and zoom by folder id in `goblin-camera` (the 20 latest
entries). Before leaving the page, hiding the tab and leaving the canvas, the entry is written at once;
`activeCameraKey` does not let the old camera be saved under a new folder. `fit()` is called only when there is no saved
camera or the person asked for it explicitly. `focusOn()` returns to the canvas and puts the object in the middle.

**3D view.** `toggleIso()` syncs `state.iso` and the camera, the choice is kept in `goblin-iso`.
`canvas/projection.js` is the single source of the matrix, the inverse transform, the dimensions and the order of
overlap; the angle is 36°. `style.x/y/z` is the position of the base, `style.width/height/thick` is the length, depth
and height; the top surface is `z + thick`. These are JSON keys, no migrations are needed. `surfaceElement()`
gives arrows the raised surface without changing the model; `projectedBounds()` is needed for hover and selection;
`depthOrder()` takes height and nesting into account. In `edit/volume-controls.js`, before the final edit the original
fields must be restored after the preview, otherwise undo will remember the already changed value.
`canvas/volume.js` draws the faces and the decorative shadow; the volume styles are in `projection.css`, loaded
last and limited to `body.iso`.

**Skins.** `canvas/looks.js` sets the card designs and the look of the left rail (the `left` field: sections or
tabs, folders as rows or as tiles). The difference is only in CSS: `#rail[data-layout="tabs"]` turns on
`classic.css`. "Studio" (`shell/studio.js`, `studio.css`) is limited to `body[data-look="studio"]` and is always
dark. Changing the skin does not change data, sizes or coordinates. The choice is in `goblin-look`, the theme in `goblin-theme`.
The mobile view is the `mobile` skin (`public/src/mobile/`, `mobile.css`, the `check-mobile.mjs` check): vertical,
the canvas is the main thing, the ☰ menu. The `minimal` and `mobile` skins hide link tags (`looks.css`).
"Classic" — the left rail as tabs, 165 px wide (`classic.css`, `--rail-w`).

**Arrows.** The algorithm is chosen by `canvas/geometry.js` (currently `harmonious`), the base geometry is
`geometry-legacy.js`. The label backing is measured by `refreshArrowLabelPlates()` after drawing, a zoom
change and font loading.

**Hierarchy.** Dragging calls `edit/rehome.js`: it changes the contents, moves the object with its contents
and, when needed, expands the target frame. Do not replace this call with a single edit of the contents. With several
frames the parent is the inner one, then the one with the smaller area (`core/tree.js`). The settings are `goblin-tree`
and `goblin-tree-links`.

**Markup small things.** The group collapse button lives inside `.node-title` — when the text is updated it
must be kept. Highlighting of Markdown and JSON in windows is done by `paintCode()` (`shell/dialogs.js`): the
`.code-mirror` layer under a transparent field; the font, padding and scrolling of the layers must match. The panels sit
in explicit columns of the `.workspace` grid, otherwise a hidden left rail collapses the work area.

**Run starter** is a block with the flag `props.start`: the card gets the class `starter` and is drawn as
a circle, with only the number and name inside (`elements.css`). In the block panel there is a "Starter" section with a checkbox;
when it is turned on the block becomes square (so the circle is even). If the folder already has a starter, the checkbox on
other blocks is inactive with the hint "the folder already has a starter — block N". Pasting from the clipboard
keeps the flag if the folder has no starter yet, otherwise it clears it — the copy is placed as an ordinary block
(`edit/clipboard.js`). Details of the rules — [progon.md](progon.md), section 4.

**Links** live in `state.links`, not in `state.elements`; they are drawn by `canvas/link.js` and edited by
`edit/links.js` with its own request (not through the `scene.js` queue), and undo is shared.

A gateway between folders is also a tag: `folder.get` returns it as a link with `gate: true` (`gateLinks()`, the id
is negative) from the gateway to the entrance of the target folder — the starter or the first block. On the gateway the tag is "⬡ number of the
target's entrance", on hover — "· target folder · Start"; on the target's entrance — "⬡ gateway number", on hover — "· folder · gateway";
a click goes there; they are not in the links panel or in the list, and have no record of their own in the database.

A link is a jump from object to object, not a step: by itself it does not stand on the canvas, it attaches to a block,
group or area and moves with it. Its look is a short purple tag at the edge: the icon and the number of the block at the
other end — on the owner at the right a solid "↗ 57", on the target at the left an outlined "↙ 12"; an incoming gateway —
"⬡ N"; a target in another folder — a dashed stub. On a decision and a gateway the tag sits at mid-height.
Hover expands the tag: "· folder · name" (the name is no longer than 20 characters, `LINK_NAME_MAX`; a link's own
title longer than 20 characters is not accepted). A click on the tag is a jump: the camera smoothly flies
to the target at 80% zoom (`flyTo()` in `canvas/view.js`). At small zoom the number is hidden, and the icon stays.

It is placed with the "link" tool (key 8) in two clicks: the owner block (it is highlighted with a dashed line),
then the target — you can go to another folder between the clicks. You can also drag from object to object.
A click with the tool on an empty spot does **not** place a block — it only shows a hint. Esc clears the selection.

## 13. Rules for changes, for people and agents

1. Find the owner of the logic: API, sync, state, edit, canvas, panel, shell, ui or run.
2. Do not put network calls in `canvas/*` and DOM logic in `core/state.js`.
3. Send all server writes through `api.post`/`api.batch`; the server rechecks permissions.
4. After a local change, publish the exact domain event.
5. Guard async responses with the project, folder or render generation.
6. Do not bring back the browser driver and runtime POSTs (except `run.stop`, `run.prepare`, `step.state`).
7. Do not compute the runtime state from color, the DOM or a timer.
8. When the API format changes, check `api/sync.js`, `run/paint.js`, the panel and the needed view together.
9. Check `canvas`, `list`, `hierarchy`, `run` and `viewOnly` in the affected area.
10. Do not take an implementation from `backup/` or from deleted historical documents.

## 14. Frontend checks

The current checks are in `tests/v2/`. Main commands:

```sh
export GOBLIN_CDP_PORT=9377                 # separate headless Chrome with a temporary profile

node tests/v2/check-editor.mjs              # editor                                  37
node tests/v2/check-canvas.mjs              # canvas                                  83
node tests/v2/check-left.mjs                # left rail in all skins                  46
node tests/v2/check-link-ui.mjs             # links                                   51
node tests/v2/check-tree.mjs                # moving in the hierarchy                 12
node tests/v2/check-projection.mjs          # 3D view                                 29
node tests/v2/check-paint-ui.mjs            # run highlighting, read-only             32
node tests/v2/check-run-live-ui.mjs         # watching a real run                     10
node tests/v2/check-mobile.mjs              # mobile view                             88
```

The numbers are the last confirmed green result (review of 29.09.2026). `check-run-live-ui` fails as of 29.09:
the test creates a folder without a team roster (solo), and the worker card goes into a step only in Orca and
SendMessage — the agent token gets no step. Run them **one at a time**: the checks share the browser
profile and `localStorage`. `check-editor` and `check-canvas` write to the real project `30vt4z512` and
erase their objects by the marker "проверка-холста"; `check-canvas` cleans up only at the end,
so after a crash the leftovers must be deleted by hand. The others create a temporary project and delete it.
Do not touch the user's ordinary Chrome profile.

The server side of the same: `node tests/v2/check-link-api.mjs` (links, 36) and
`node tests/v2/check-parallel-live.mjs` (three runs at once, 23) — they do not need a browser.
The full list of checks is in [structure.md](structure.md).

## 15. Quick map of sources of truth

| Task | File/directory |
|---|---|
| HTML/import map build | `lib/web/page.php`, `views/editor/` |
| Client start | `public/src/main.js` |
| HTTP and hash | `public/src/api/client.js` |
| Sync | `public/src/api/sync.js` |
| State/events | `public/src/core/state.js` |
| Views | `public/src/core/variants.js`, `public/src/ui/` |
| Editor | `public/src/edit/` |
| Canvas | `public/src/canvas/` |
| Left/right panels | `public/src/left/`, `public/src/panel/` |
| Shell and log | `public/src/shell/` |
| Scheme catalog and builder | `public/src/shell/newscheme.js`, `lib/templates/catalog.php`, `lib/ai/build.php` |
| Runtime watching | `public/src/run/paint.js`, `public/src/run/follow.js`, `public/src/shell/runbar.js` |
| Server contract | `public/api.php`, `lib/api/router.php` |
