<?php
/* Кто пришёл и что ему можно. Одно место на все разрешения.
   Отдаёт: caller(), callerRole(), requireRole(), requireProject(), requireOwner(), sameProject().
   Не делает: не выпускает пропуска (tokens.php) и не знает про отдельные операции
              больше, чем написано в lib/api/router.php.

   Роль НИКОГДА не приходит из тела запроса — только из предъявленного пропуска.
   Поэтому полей actor и executor в API v2 нет. */

declare(strict_types=1);

const SESSION_COOKIE = 'goblin_session';

/**
 * Кто вызывает.
 *   role: human | lead | worker | admin | guest
 *   плюс user_id, project_id, run_id, step_id, agent_id, token_id.
 */
function caller(): array
{
    static $who = null;
    if ($who !== null) return $who;

    $who = ['role' => 'guest', 'user_id' => null, 'project_id' => null,
            'run_id' => null, 'step_id' => null, 'agent_id' => null, 'token_id' => null];

    $req = request();

    // 1. Пропуск агента в заголовке.
    if ($req['token'] !== '') {
        $token = tokenFind($req['token']);
        if (!$token) throw new ApiError(t('server.token.invalid'), 'unauthorized');
        tokenTouch((int) $token['id']);

        $who['token_id']   = (int) $token['id'];
        $who['user_id']    = $token['user_id'] ? (int) $token['user_id'] : null;
        $who['project_id'] = $token['project_id'] ? (int) $token['project_id'] : null;
        $who['run_id']     = $token['run_id'] ? (int) $token['run_id'] : null;
        $who['step_id']    = $token['step_id'] ? (int) $token['step_id'] : null;
        $who['agent_id']   = $token['agent_id'] ? (int) $token['agent_id'] : null;
        $who['role']       = match ($token['scope']) {
            'run'     => 'lead',
            'step'    => 'worker',
            // Пропуск агента: тот же worker, только живёт весь прогон и сам
            // находит свой открытый шаг — переписка с leader не нужна.
            'agent'   => 'worker',
            'admin'   => 'admin',
            'project' => 'human',   // токен проекта = права человека в проекте; к одному прогону не привязан
            default   => 'human',
        };
        $who['scope'] = $token['scope'];
        return $who;
    }

    // 2. Человек в браузере.
    $cookie = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    if ($cookie !== '') {
        $token = tokenFind($cookie);
        if ($token && $token['scope'] === 'session') {
            tokenTouch((int) $token['id']);
            $who['role']     = 'human';
            $who['scope']    = 'session';
            $who['user_id']  = (int) $token['user_id'];
            $who['token_id'] = (int) $token['id'];
            return $who;
        }
    }

    // 3. Гость: читает всё, пишет только в открытые проекты.
    $who['scope'] = 'guest';
    return $who;
}

function callerRole(): string
{
    return caller()['role'];
}

/** Разрешена ли роль в списке операции. Гость приравнивается к человеку. */
function roleAllowed(array $allowed): bool
{
    $role = callerRole();
    if ($role === 'guest') $role = 'human';
    if ($role === 'admin') return true;
    return in_array($role, $allowed, true);
}

function requireRole(array $allowed, string $op): void
{
    if (!roleAllowed($allowed)) {
        throw new ApiError(t('server.token.wrong_op', ['op' => $op]), 'scope');
    }
}

/**
 * Проект, в котором идёт работа. Правило одно на всю базу:
 * любая выборка и запись — только внутри своего проекта.
 * Чужой объект отвечает «не найдено», а не «нельзя»: существование чужого
 * проекта подтверждать незачем.
 */
function requireProject(bool $forWrite): array
{
    $who = caller();
    $key = trim((string) request()['project']);

    if ($who['project_id']) {
        $project = dbRow('SELECT * FROM projects WHERE id = ?', [$who['project_id']]);
        if (!$project) throw new ApiError(t('server.token.project_gone'), 'not_found');
        if ($key !== '' && $key !== $project['url_key']) {
            throw new ApiError(t('server.token.other_project'), 'scope');
        }
    } else {
        if ($key === '') throw new ApiError(t('server.project.not_given'), 'not_found');
        if (!validProjectKey($key)) throw new ApiError(t('server.project.bad_key'), 'not_found');
        $project = dbRow('SELECT * FROM projects WHERE url_key = ?', [$key]);
        // Ключ, в который ещё не писали, — это новый проект: он заводится
        // первой же записью, как и в прежнем Гоблине.
        if (!$project && $forWrite) $project = projectOpen($key);
        if (!$project) throw new ApiError(t('server.project.not_found'), 'not_found');
    }

    if ($forWrite) requireWrite($project);
    journalSingleProject($project);
    langAgents((string) ($project['lang'] ?? 'ru'));   // тексты прогона и ИИ — на языке проекта
    aiAccessProject($project);                          // Jev прогона — на доступе владельца проекта
    return $project;
}

/** Писать можно владельцу, гостю в открытом проекте и любому пропуску проекта. */
function requireWrite(array $project): void
{
    $who = caller();
    if ($who['token_id'] && $who['project_id'] === (int) $project['id']) return;
    if ($who['user_id'] && (int) $project['owner_id'] === $who['user_id']) return;
    if ((int) $project['guest_write'] === 1 && !$project['owner_id']) return;
    if ((int) $project['guest_write'] === 1) return;
    throw new ApiError(t('server.project.read_only'), 'forbidden');
}

/**
 * Дела хозяина (OWNER_OPS в lib/api/router.php): удалить проект, закрыть запись, пропуска, перенос папки.
 * Можно владельцу, пропуску этого проекта и в проекте без владельца — там ключ проекта и есть пароль
 * (решение хозяина 30.09.2026). Гость со ссылкой на чужой проект правит только схему (Б1).
 */
function requireOwner(array $project): void
{
    $who = caller();
    if ($who['role'] === 'admin' || !$project['owner_id']) return;
    if ($who['token_id'] && $who['project_id'] === (int) $project['id']) return;
    if ($who['user_id'] && (int) $project['owner_id'] === $who['user_id']) return;
    throw new ApiError(t('server.project.owner_only'), 'forbidden');
}

/** Объект принадлежит этому проекту? Иначе — «не найдено». */
function sameProject(?array $row, int $projectId, string $what = 'object'): array
{
    if (!$row || (int) ($row['project_id'] ?? 0) !== $projectId) {
        throw new ApiError(notFoundText($what), 'not_found');
    }
    return $row;
}

/** «… не найден» целой фразой на сущность: род слова не склеиваем. $what — код ('folder') или прежнее русское слово ('Папка'). */
function notFoundText(string $what): string
{
    return match ($what) {
        'agent', 'Агент' => t('server.not_found.agent'),
        'element', 'Элемент' => t('server.not_found.element'),
        'folder', 'Папка' => t('server.not_found.folder'),
        'link', 'Привязка' => t('server.not_found.link'),
        'run', 'Прогон' => t('server.not_found.run'),
        'step', 'Шаг' => t('server.not_found.step'),
        'asset', 'Материал' => t('server.not_found.asset'),
        'container', 'Контейнер' => t('server.not_found.container'),
        'arrow_start', 'Начало стрелки' => t('server.not_found.arrow_start'),
        'arrow_end', 'Конец стрелки' => t('server.not_found.arrow_end'),
        'end', 'Конец' => t('server.not_found.end'),
        'link_owner', 'Владелец ярлыка' => t('server.not_found.link_owner'),
        'link_target', 'Цель ярлыка' => t('server.not_found.link_target'),
        'target_folder', 'Папка-цель' => t('server.not_found.target_folder'),
        'object', 'Объект' => t('server.not_found.object'),
        default => t('server.not_found.other', ['what' => $what]),
    };
}

/** Название сущности для сообщений вида «Начало стрелки: …»: код или прежнее русское слово. */
function entityLabel(string $what): string
{
    return match ($what) {
        'agent', 'Агент' => t('server.label.agent'),
        'role', 'Роль' => t('server.label.role'),
        'cli', 'CLI' => t('server.label.cli'),
        'effort', 'Эффорт' => t('server.label.effort'),
        'permission', 'Разрешения' => t('server.label.permission'),
        'sandbox', 'Песочница' => t('server.label.sandbox'),
        'container', 'Контейнер' => t('server.label.container'),
        'arrow_start', 'Начало стрелки' => t('server.label.arrow_start'),
        'arrow_end', 'Конец стрелки' => t('server.label.arrow_end'),
        'end', 'Конец' => t('server.label.end'),
        'link_owner', 'Владелец ярлыка' => t('server.label.link_owner'),
        'link_target', 'Цель ярлыка' => t('server.label.link_target'),
        'element', 'Элемент' => t('server.label.element'),
        'folder', 'Папка' => t('server.label.folder'),
        'work_dir', 'Рабочая папка' => t('server.label.work_dir'),
        'asset_file', 'Файл материала' => t('server.label.asset_file'),
        'asset_path', 'Путь материала' => t('server.label.asset_path'),
        default => $what,
    };
}
