<?php
/* Список операций: одна строка на операцию. Кто что может — тоже здесь.
   Отдаёт: OPS, dispatch(), opsCatalog(), docsForAgent().
   Не делает: не выполняет операции сам — только зовёт функцию домена.

   Строка: 'имя' => [метод, функция, [роли], режим].
     метод  — GET или POST; «GET|POST» — любой из двух;
     роли   — human (человек и токен проекта), lead (leader), worker (worker), ai;
     режим  — batch (можно пачкой) или single (строго по одной за запрос).

   Команды прогона всегда single: это и есть защита от залпов статусов. */

declare(strict_types=1);

const OPS = [
    // ── Проект ───────────────────────────────────────────────
    'project.get'    => ['GET',  'projectGet',    ['human', 'lead', 'ai'], 'read'],
    'project.update' => ['POST', 'projectUpdate', ['human'],               'batch'],
    'project.delete' => ['POST', 'projectDelete', ['human'],               'batch'],
    'token.create'   => ['POST', 'tokenCreateOp', ['human'],               'single'],
    'token.revoke'   => ['POST', 'tokenRevokeOp', ['human'],               'single'],

    // ── Папки ────────────────────────────────────────────────
    'folder.embed' => ['GET', 'folderEmbed', ['human', 'lead', 'ai'], 'read'],
    'folder.get'    => ['GET',  'folderGet',    ['human', 'lead', 'worker', 'ai'], 'read'],
    'folder.create' => ['POST', 'folderCreate', ['human', 'lead', 'ai'],           'batch'],
    'folder.update' => ['POST', 'folderUpdate', ['human', 'lead', 'ai'],           'batch'],
    'folder.delete' => ['POST', 'folderDelete', ['human', 'lead'],                 'batch'],
    'folder.transfer' => ['POST', 'folderTransfer', ['human'],                    'single'],
    // Открыть рабочую папку в Finder — только локально: сервер и человек за одним Mac.
    'folder.open'   => ['POST', 'folderOpen',   ['human'],                       'single'],
    // Обзор папок на сервере для рабочей папки — только внутри file_roots.
    'dir.list'      => ['GET',  'dirList',      ['human'],                       'read'],

    // ── Элементы ─────────────────────────────────────────────
    'element.get'    => ['GET',  'elementGetOp',  ['human', 'lead', 'ai'], 'read'],
    'element.create' => ['POST', 'elementCreate', ['human', 'lead', 'ai'], 'batch'],
    'element.update' => ['POST', 'elementUpdate', ['human', 'lead', 'ai'], 'batch'],
    'element.delete' => ['POST', 'elementDelete', ['human', 'lead', 'ai'], 'batch'],
    'element.restore' => ['POST', 'elementRestore', ['human', 'lead', 'ai'], 'batch'],

    // ── Материалы ────────────────────────────────────────────
    'asset.get'       => ['GET',  'assetGet',       ['human', 'lead', 'worker', 'ai'], 'read'],
    'asset.file'      => ['GET',  'assetFile',      ['human', 'lead', 'worker'],        'read'],
    'asset.workfiles' => ['GET',  'assetWorkfiles', ['human', 'lead', 'worker'],        'read'],
    'asset.workfile'  => ['GET',  'assetWorkfile',  ['human', 'lead'],                  'read'],
    'asset.workfile.rename' => ['POST', 'assetWorkfileRename', ['human'],              'single'],
    'asset.workfile.delete' => ['POST', 'assetWorkfileDelete', ['human'],              'single'],
    'asset.upload'    => ['POST', 'assetUpload',    ['human', 'lead'],                  'single'],
    'asset.create' => ['POST', 'assetCreate', ['human', 'lead', 'ai'],           'batch'],
    'asset.update' => ['POST', 'assetUpdate', ['human', 'lead', 'ai'],           'batch'],
    'asset.delete' => ['POST', 'assetDelete', ['human', 'lead'],                 'batch'],
    'asset.link'   => ['POST', 'assetLink',   ['human', 'lead', 'ai'],           'batch'],
    'asset.role'   => ['POST', 'assetRole',   ['human', 'lead', 'ai'],           'batch'],
    'asset.unlink' => ['POST', 'assetUnlink', ['human', 'lead', 'ai'],           'batch'],

    // ── Агенты ───────────────────────────────────────────────
    'agent.save'   => ['POST', 'agentSave',   ['human'], 'batch'],
    'agent.delete' => ['POST', 'agentDelete', ['human'], 'batch'],
    'agent.detach' => ['POST', 'agentDetach', ['human'], 'batch'],
    'agent.token'  => ['POST', 'agentToken',  ['human', 'lead'], 'single'],

    // ── Прогон: команды leader. Строго по одной за запрос —
    //    это и есть защита от залпов статусов.
    'run.check'    => ['GET',  'runCheck',    ['human', 'lead'], 'read'],
    'run.prepare'  => ['POST', 'runPrepare',  ['human', 'lead'], 'single'],
    'run.start'    => ['POST', 'runStart',    ['human', 'lead'], 'single'],
    'run.get'      => ['GET',  'runGet',      ['human', 'lead'], 'read'],
    // Движок: снимок с ожиданием, ход, настройка на ходу и картинка для холста.
    'run.state'    => ['GET',  'runState',     ['human', 'lead', 'worker'], 'read'],
    'run.advance'  => ['POST', 'runAdvanceOp', ['human', 'lead'],           'single'],
    'run.update'   => ['POST', 'runUpdate',    ['human', 'lead'],           'single'],
    'run.paint'    => ['GET',  'runPaint',     ['human', 'lead'],           'read'],
    'run.timeline' => ['GET',  'runTimeline', ['human', 'lead'], 'read'],
    'run.log'      => ['GET',  'runLog',      ['human', 'lead'], 'read'],
    'run.event'    => ['POST', 'runEvent',    ['human', 'lead', 'worker'], 'single'],
    'run.report'   => ['GET',  'runReport',   ['human', 'lead'], 'read'],
    'run.pause'    => ['POST', 'runPause',    ['human', 'lead'], 'single'],
    'run.resume'   => ['POST', 'runResume',   ['human', 'lead'], 'single'],
    'run.stop'     => ['POST', 'runStop',     ['human', 'lead'], 'single'],
    // Человек закрывает прогон так же, как leader: из панели и командой
    // с флагом --id. Внутри runOwn() всё равно проверит, что прогон его.
    'run.finish'   => ['POST', 'runFinish',   ['lead', 'human'], 'single'],
    'run.attach'   => ['POST', 'runAttach',   ['human'],         'single'],
    'run.reset'    => ['POST', 'runReset',    ['human', 'lead'], 'single'],

    // ── Шаги: leader открывает и принимает, worker выполняет ─
    // Человек ведёт тот же прогон руками из панели: кнопки «принять», «вернуть»
    // и выбор ветки — те же команды, что у leader.
    'step.open'    => ['POST', 'stepOpen',    ['lead', 'human'], 'single'],
    'step.accept'  => ['POST', 'stepAccept',  ['lead', 'human'], 'single'],
    'step.return'  => ['POST', 'stepReturn',  ['lead', 'human'], 'single'],
    'step.decide'  => ['POST', 'stepDecide',  ['lead', 'human'], 'single'],
    'step.pass'    => ['POST', 'stepPass',    ['lead', 'human'], 'single'],
    'step.cancel'  => ['POST', 'stepCancel',  ['lead', 'human'], 'single'],
    'step.reissue' => ['POST', 'stepReissue', ['lead', 'human'], 'single'],
    'step.reset'   => ['POST', 'stepReset',   ['lead', 'human'], 'single'],
    'step.state'   => ['POST', 'stepState',   ['human'],         'single'],
    // Сигнальщик: смотрит на сдачу или на ромб и ничего не меняет.
    'step.jev'     => ['POST', 'stepJev',     ['lead', 'human'], 'single'],
    // worker берёт работу сам: пропуск шага приходит в step.token.
    'step.take'    => ['POST', 'stepTake',    ['worker'], 'single'],
    'step.mine'    => ['GET',  'stepMine',    ['worker'], 'read'],
    'step.get'     => ['GET',  'stepGet',     ['worker'], 'read'],
    'step.note'    => ['POST', 'stepNote',    ['worker'], 'single'],
    'step.job'     => ['POST', 'stepJob',     ['worker'], 'single'],
    'step.submit'  => ['POST', 'stepSubmit',  ['worker'], 'single'],
    'step.fail'    => ['POST', 'stepFail',    ['worker'], 'single'],

    // ── Изменения и журнал ───────────────────────────────────
    'changes'     => ['GET', 'changesGet', ['human', 'lead', 'ai'], 'read'],
    // Пульс для шапки: кто сейчас работает — worker, круг, ИИ.
    'pulse'       => ['GET', 'pulseGet',   ['human', 'lead'],        'read'],
    'journal.get' => ['GET', 'journalGet', ['human', 'lead'],       'read'],

    // ── Готовые схемы ────────────────────────────────────────
    'template.get'    => ['GET',  'templateGet',    ['human', 'lead', 'ai'], 'read'],
    'template.save'   => ['POST', 'templateSave',   ['human'],               'single'],
    'template.apply'  => ['POST', 'templateApply',  ['human', 'lead'],       'single'],
    'template.delete' => ['POST', 'templateDelete', ['human'],               'single'],
    // Каталог: разделы, карточки, превью (lib/templates/catalog.php).
    'catalog.get'     => ['GET',  'catalogGet',     ['human', 'lead', 'ai'], 'read'],

    // ── Списки и инструкции ──────────────────────────────────
    'config.get'  => ['GET',  'configGet',  ['human', 'lead', 'worker', 'ai'], 'read'],
    // Списки общие для всей установки, а в [cli] лежит строка запуска worker: только админ.
    'config.list' => ['POST', 'configList', ['admin'],                         'single'],
    'docs.get'    => ['GET',  'docsGet',    ['human', 'lead', 'worker', 'ai'], 'read'],
    // Задание leader по папке: одна ссылка, в ней и схема, и порядок запуска.
    'docs.run'    => ['GET',  'docsRun',    ['human', 'lead', 'worker', 'ai'], 'read'],

    // ── Встроенный ИИ ────────────────────────────────────────
    'ai.chat.get'    => ['GET',  'aiChatGet',    ['human'], 'read'],
    'ai.chat.send'   => ['POST', 'aiChatSend',   ['human'], 'single'],
    'ai.chat.apply'  => ['POST', 'aiChatApply',  ['human'], 'single'],
    'ai.chat.cancel' => ['POST', 'aiChatCancel', ['human'], 'single'],
    'ai.chat.delete' => ['POST', 'aiChatDelete', ['human'], 'single'],
    'ai.build.get'    => ['GET',  'aiBuildGet',    ['human'], 'read'],
    'ai.build.start'  => ['POST', 'aiBuildStart',  ['human'], 'single'],
    'ai.build.answer' => ['POST', 'aiBuildAnswer', ['human'], 'single'],
    'ai.build.create' => ['POST', 'aiBuildCreate', ['human'], 'single'],
    // Голос: пропуск на живое распознавание и озвучка ответа — в журнал не пишутся (lib/ai/voice.php).
    'voice.session'   => ['POST', 'voiceSession',  ['human'], 'read'],
    'voice.speak'     => ['POST', 'voiceSpeak',    ['human'], 'read'],
    'ai.play'         => ['POST', 'aiPlay',        ['human'], 'single'],

    /* ── Простой путь leader (lib/api/simple.php) ───────────────
       Обычные GET-адреса, ответ текстом: один curl и никаких пропусков.
       Команды тоже GET — так проще агенту, а журнал их всё равно пишет.
       Сдача (done, fail) — ещё и POST-формой: длинный ответ в адрес не влезает (414). */
    'scheme' => ['GET', 'goScheme', ['human', 'lead'], 'read'],
    'begin'  => ['GET', 'goBegin',  ['human', 'lead'], 'single'],
    'task'   => ['GET', 'goTask',   ['human', 'lead'], 'single'],
    'work'   => ['GET', 'goWork',   ['human', 'lead'], 'single'],
    'done'   => ['GET|POST', 'goDone', ['human', 'lead'], 'single'],
    'fail'   => ['GET|POST', 'goFail', ['human', 'lead'], 'single'],
    'again'  => ['GET', 'goAgain',  ['human', 'lead'], 'single'],
    'where'  => ['GET', 'goWhere',  ['human', 'lead'], 'read'],
    'wait'   => ['GET', 'goWait',   ['human', 'lead'], 'read'],
    'stop'   => ['GET', 'goStop',   ['human', 'lead'], 'single'],
    'invite' => ['GET', 'goInvite', ['human', 'lead'], 'read'],
    // Задание, общая картина и инструкция worker — по ссылке: worker читает их curl-ом, диск сервера ему не нужен.
    'text'   => ['GET', 'goText',   ['human', 'lead'], 'read'],
];

/** Дела хозяина проекта: гостю со ссылкой — только правка схемы (Б1, lib/access/rights.php requireOwner). */
const OWNER_OPS = ['project.update', 'project.delete', 'token.create', 'token.revoke', 'agent.token', 'folder.transfer'];

/** Разобрать запрос, проверить права и позвать нужную функцию. */
function dispatch(): void
{
    requireSameOrigin();   // запись — только со своих страниц (lib/api/http.php)
    $req = request();
    if ($req['method'] === 'OPTIONS') reply();
    // Отвечаем агенту — и ошибки сервера на языке проекта, как весь прогон (lib/core/i18n.php).
    if (simpleOp() || docsForAgent($req['op']) || in_array(callerRole(), ['lead', 'worker'], true)) langForAgent(true);

    $ops = $req['body']['ops'] ?? null;

    // Пачка: несколько операций одним запросом.
    if (is_array($ops)) {
        foreach ($ops as $op) {
            $name = (string) ($op['op'] ?? '');
            $rule = opRule($name);
            if ($rule[3] !== 'batch') throw new ApiError(t('server.api.op_single', ['name' => $name]));
            requireRole($rule[2], $name);
        }
        $project = requireProject(true);
        if (array_intersect(array_column($ops, 'op'), OWNER_OPS)) requireOwner($project);
        runBatch($project, $ops, static function (array $op, array &$ctx) {
            $rule = opRule((string) $op['op']);
            return $rule[1]($op, $ctx);
        });
    }

    $name = $req['op'];
    if ($name === '') throw new ApiError(t('server.api.op_missing'));
    $rule = opRule($name);
    if (!in_array($req['method'], explode('|', $rule[0]), true)) {
        throw new ApiError(t('server.api.op_method', ['name' => $name, 'method' => $rule[0]]));
    }
    requireRole($rule[2], $name);
    // Платный ИИ гостю — только если разрешено в настройках, голос — только вошедшим (lib/projects/guests.php).
    if (in_array($name, GUEST_AI_OPS, true) && !guestAiAllowed($name)) {
        throw new ApiError(t('server.ai.need_login'), 'unauthorized');
    }

    // Чтение: функция отвечает сама.
    if ($rule[3] === 'read') {
        $rule[1]();
        return;
    }
    // Одиночная команда прогона: журнал пишется сам, при ответе.
    if ($rule[3] === 'single') {
        if (in_array($name, OWNER_OPS, true)) requireOwner(requireProject(true));
        journalSingleStart($name, $req['body'] ?: $req['query']);
        $rule[1]();
        return;
    }

    // Одиночная правка: та же пачка, только из одной операции.
    $project = requireProject(true);
    if (in_array($name, OWNER_OPS, true)) requireOwner($project);
    $body = $req['body'];
    $body['op'] = $name;
    runBatch($project, [$body], static function (array $op, array &$ctx) use ($rule) {
        return $rule[1]($op, $ctx);
    });
}

/**
 * Инструкции прогона (docs.run, docs.get) читает агент — ему ошибки на языке проекта.
 * Человек в браузере узнаётся по своим cookie (вход или выбранный язык): ему как раньше.
 */
function docsForAgent(string $op): bool
{
    return in_array($op, ['docs.run', 'docs.get'], true)
        && caller()['scope'] !== 'session' && !isset($_COOKIE['goblin_lang']);
}

function opRule(string $name): array
{
    if (!isset(OPS[$name])) throw new ApiError(t('server.api.op_unknown', ['name' => $name]), 'not_found');
    return OPS[$name];
}

/** Список операций для справки: что есть и кому можно. */
function opsCatalog(): array
{
    $out = [];
    foreach (OPS as $name => $rule) {
        $out[] = ['op' => $name, 'method' => $rule[0], 'roles' => $rule[2], 'mode' => $rule[3]];
    }
    return $out;
}
