<?php
/* Один require на всё: ядро, слой API и домены.
   Отдаёт: загруженную библиотеку.
   Не делает: ничего не выполняет — только подключает.

   Порядок важен один раз: сначала ядро и слой API, дальше домены в любом порядке. */

declare(strict_types=1);

$root = __DIR__;

foreach ([
    // Ядро
    'core/config.php', 'core/db.php', 'core/sqlite.php', 'core/schema.php', 'core/ids.php', 'core/i18n.php',
    // Слой API
    'api/http.php', 'api/batch.php', 'api/router.php', 'api/service.php', 'api/simple.php', 'api/instructions.php',
    // Доступ
    'access/tokens.php', 'access/rights.php', 'access/users.php', 'access/admin.php',
    // Домены
    'journal/journal.php', 'journal/changes.php',
    'projects/projects.php', 'projects/guests.php',
    'folders/folders.php', 'folders/scheme.php', 'folders/transfer.php',
    'elements/kinds.php', 'elements/shape.php', 'elements/elements.php',
    'elements/members.php', 'elements/props.php', 'elements/table.php', 'elements/find.php',
    'assets/assets.php', 'assets/links.php', 'assets/files.php',
    'agents/agents.php',
    'ai/deepseek.php', 'ai/jev.php',
    'ai/jev/questions.php', 'ai/jev/state.php', 'ai/jev/verdict.php', 'ai/jev/client.php',
    'ai/access.php', 'ai/calls.php', 'ai/voice.php', 'ai/settings.php', 'ai/context.php', 'ai/compile.php',
    'ai/chat.php', 'ai/jobs.php', 'ai/play.php', 'ai/build.php', 'ai/usage.php',
    // Движок прогона
    'engine/lock.php', 'engine/guard.php',
    'engine/runs.php', 'engine/precheck.php', 'engine/prepare.php',
    'engine/graph.php', 'engine/marks.php', 'engine/legacy.php', 'engine/ready.php',
    'engine/steps.php', 'engine/package.php',
    'engine/vars.php',
    'engine/checks/parser.php', 'engine/checks/cond.php', 'engine/checks/expr.php',
    'engine/checks/form.php', 'engine/checks/files.php',
    'engine/judges/formal.php', 'engine/judges/human.php', 'engine/judges/jev.php', 'engine/judges/review.php',
    'engine/finish.php', 'engine/advance.php', 'engine/state.php', 'engine/paint.php',
    'engine/events.php', 'engine/jobs.php', 'engine/pulse.php', 'engine/timeline.php',
    'templates/templates.php',
    'templates/catalog.php',
] as $file) {
    require_once $root . '/' . $file;
}
