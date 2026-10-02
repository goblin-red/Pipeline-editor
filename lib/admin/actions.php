<?php
/* Админка: действия — всё, что меняет данные.
   Отдаёт: adminAction() — выполнить одно действие по имени, adminApplyOp().
   Не делает: не читает разделы (lib/admin/data.php) и не проверяет вход (public/admin.php).

   Правки схем и проектов идут той же пачкой, что и API (runBatch): журнал, ревизия,
   защита живого прогона. Люди, пропуска и уборка — прямыми запросами. */

declare(strict_types=1);

/** Выполнить действие админки. $in — тело запроса. Отдаёт ['say' => …] и данные. */
function adminAction(string $name, array $in): array
{
    return match ($name) {
        'project.update'  => adminProjectUpdate($in),
        'project.delete'  => adminProjectDelete((int) ($in['id'] ?? 0), !empty($in['stopRuns'])),
        'scheme.rename'   => adminSchemeBatch((int) ($in['id'] ?? 0), ['op' => 'folder.update', 'name' => (string) ($in['name'] ?? '')], t('admin.say.folder_renamed')),
        'scheme.delete'   => adminSchemeBatch((int) ($in['id'] ?? 0), ['op' => 'folder.delete'], t('admin.say.folder_deleted')),
        'run.stop'        => adminRunStop((int) ($in['id'] ?? 0)),
        'user.create'     => adminUserCreate($in),
        'user.update'     => adminUserUpdate($in),
        'user.password'   => adminUserPassword((int) ($in['id'] ?? 0), (string) ($in['password'] ?? '')),
        'user.logout'     => adminUserLogout((int) ($in['id'] ?? 0)),
        'user.delete'     => adminUserDelete((int) ($in['id'] ?? 0)),
        'token.revoke'    => adminTokenRevoke((int) ($in['id'] ?? 0)),
        'tokens.cleanup'  => adminTokensCleanup(),
        'template.delete' => adminTemplateDelete((int) ($in['id'] ?? 0)),
        'template.update' => adminTemplateUpdate($in),
        'template.fromFolder' => adminTemplateFromFolder($in),
        'category.save'   => adminCategorySave($in),
        'ai.sweep'        => adminAiSweep(),
        'journal.prune'   => adminJournalPrune((int) ($in['days'] ?? 0)),
        'files.gc'        => adminFilesGc(),
        'workdir.delete'  => adminOrphanDelete((string) ($in['name'] ?? '')),
        'migrations.apply' => adminMigrationsApply(),
        'settings.save'   => settingsSave((string) ($in['group'] ?? ''), (array) ($in['values'] ?? [])),
        'settings.testDb' => settingsTestDbAction((array) ($in['values'] ?? [])),
        'settings.lists'  => settingsListsSave((string) ($in['text'] ?? ''), !empty($in['confirm'])),
        default           => throw new ApiError(t('admin.err.unknown_action', ['name' => $name]), 'not_found'),
    };
}

/** Применить операцию API из админки — той же функцией, что и dispatch(). */
function adminApplyOp(array $op, array &$ctx): array
{
    $rule = opRule((string) $op['op']);
    return $rule[1]($op, $ctx);
}

function adminProjectRowFor(int $id): array
{
    $row = dbRow('SELECT * FROM projects WHERE id = ?', [$id]);
    if (!$row) throw new ApiError(t('admin.err.no_project'), 'not_found');
    return $row;
}

/* ── Проекты и схемы ──────────────────────────────────────────── */

/** Название, настройки и владелец проекта. */
function adminProjectUpdate(array $in): array
{
    $project = adminProjectRowFor((int) ($in['id'] ?? 0));
    $op = ['op' => 'project.update'];
    if (array_key_exists('title', $in)) $op['title'] = (string) $in['title'];
    foreach (['guestWrite', 'aiConfirm', 'strictChecks'] as $key) {
        if (array_key_exists($key, $in)) $op[$key] = !empty($in[$key]);
    }
    if (count($op) > 1) runBatch($project, [$op], 'adminApplyOp', false);

    // Владельца API не меняет — это решение админа.
    if (array_key_exists('ownerId', $in)) {
        $owner = $in['ownerId'] ? (int) $in['ownerId'] : null;
        if ($owner && !dbValue('SELECT id FROM users WHERE id = ?', [$owner])) throw new ApiError(t('admin.err.no_such_user'));
        dbRun('UPDATE projects SET owner_id = ? WHERE id = ?', [$owner, $project['id']]);
    }
    return ['say' => t('admin.say.project_saved')];
}

/** stopRuns — сначала остановить живые прогоны проекта (иначе отказ). */
function adminProjectDelete(int $id, bool $stopRuns = false): array
{
    $project = adminProjectRowFor($id);
    if ($stopRuns) {
        foreach (dbAll("SELECT id FROM runs WHERE project_id = ? AND state IN ('running', 'paused')", [$id]) as $run) {
            adminRunStop((int) $run['id']);
        }
    }
    // Та же операция, что project.delete в API: защита живого прогона, пустые рабочие папки — после фиксации.
    runBatch($project, [['op' => 'project.delete']], 'adminApplyOp', false);
    return ['say' => t('admin.say.project_deleted', ['name' => $project['title'] ?: $project['url_key']])];
}

/** Правка папки — операцией API в пачке: журнал, ревизия и защита живого прогона. */
function adminSchemeBatch(int $folderId, array $op, string $say): array
{
    $folder = dbRow('SELECT * FROM folders WHERE id = ?', [$folderId]);
    if (!$folder) throw new ApiError(t('admin.err.no_folder'), 'not_found');
    $project = adminProjectRowFor((int) $folder['project_id']);
    runBatch($project, [$op + ['id' => $folderId]], 'adminApplyOp', false);
    return ['say' => $say];
}

/** Остановить прогон сразу: открытые шаги отменяются — как «■ Остановить» в редакторе. */
function adminRunStop(int $runId): array
{
    $run = dbRow('SELECT * FROM runs WHERE id = ?', [$runId]);
    if (!$run) throw new ApiError(t('admin.err.no_run'), 'not_found');
    $projectId = (int) $run['project_id'];
    // Та же остановка, что run.stop now=1 (runStopDo): шаги отменены, в ленте stop и finish.
    $stopped = engineLocked($projectId, static function () use ($runId, $projectId): bool {
        $run = dbRow('SELECT * FROM runs WHERE id = ?', [$runId]);   // состояние — уже под замком
        if (!$run || !in_array($run['state'], ['running', 'paused'], true)) return false;
        dbRun('UPDATE runs SET stop_at = NOW(3), rev = ? WHERE id = ?', [bumpRev($projectId), $runId]);
        runStopDo($run, 'human', t('admin.run.stopped_why'));
        return true;
    });
    return ['say' => $stopped ? t('admin.say.run_stopped', ['no' => $run['no']]) : t('admin.say.run_closed')];
}

/* ── Люди ─────────────────────────────────────────────────────── */

/** Завести пользователя. Сеанс не открываем: админ остаётся собой. */
function adminUserCreate(array $in): array
{
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(t('admin.err.bad_email'));
    if (dbValue('SELECT id FROM users WHERE email = ?', [$email])) throw new ApiError(t('admin.err.email_taken'), 'conflict');
    $password = trim((string) ($in['password'] ?? '')) ?: adminNewPassword();
    if (mb_strlen($password) < 6) throw new ApiError(t('admin.err.short_password'));
    // Как при регистрации: копия общих настроек ИИ.
    dbRun('INSERT INTO users (email, pass_hash, name, access) VALUES (?,?,?,?)',
        [$email, password_hash($password, PASSWORD_DEFAULT), trim((string) ($in['name'] ?? '')), aiAccessDefaults()]);
    return ['say' => t('admin.say.user_created'), 'id' => (int) dbId(), 'password' => $password];
}

function adminUserUpdate(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if (!dbValue('SELECT id FROM users WHERE id = ?', [$id])) throw new ApiError(t('admin.err.no_user'), 'not_found');
    if (array_key_exists('email', $in)) {
        $email = mb_strtolower(trim((string) $in['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(t('admin.err.bad_email'));
        if (dbValue('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) throw new ApiError(t('admin.err.email_taken'), 'conflict');
        dbRun('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
    }
    if (array_key_exists('name', $in)) dbRun('UPDATE users SET name = ? WHERE id = ?', [trim((string) $in['name']), $id]);
    return ['say' => t('admin.say.saved')];
}

/** Задать пароль; пустой — придумать. Новый пароль показываем один раз. */
function adminUserPassword(int $id, string $password): array
{
    if (!dbValue('SELECT id FROM users WHERE id = ?', [$id])) throw new ApiError(t('admin.err.no_user'), 'not_found');
    $password = trim($password) ?: adminNewPassword();
    if (mb_strlen($password) < 6) throw new ApiError(t('admin.err.short_password'));
    dbRun('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    return ['say' => t('admin.say.password_set'), 'password' => $password];
}

function adminUserLogout(int $id): array
{
    dbRun("UPDATE tokens SET revoked_at = NOW(3) WHERE user_id = ? AND scope = 'session' AND revoked_at IS NULL", [$id]);
    return ['say' => t('admin.say.sessions_ended')];
}

/** Удалить пользователя: его проекты остаются, владельца у них не будет. */
function adminUserDelete(int $id): array
{
    $email = dbValue('SELECT email FROM users WHERE id = ?', [$id]);
    if (!$email) throw new ApiError(t('admin.err.no_user'), 'not_found');
    dbRun('DELETE FROM users WHERE id = ?', [$id]);
    return ['say' => t('admin.say.user_deleted', ['email' => $email])];
}

function adminNewPassword(): string
{
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
    return $out;
}

/* ── Пропуска, заготовки, уборка ──────────────────────────────── */

function adminTokenRevoke(int $id): array
{
    tokenRevoke($id);
    return ['say' => t('admin.say.token_revoked')];
}

/** Убрать отозванные и истёкшие пропуска старше месяца: в работе они уже не участвуют. */
function adminTokensCleanup(): array
{
    $gone = dbRun("DELETE FROM tokens WHERE scope <> 'admin'
                     AND ((revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 30 DAY)
                       OR (expires_at IS NOT NULL AND expires_at < NOW() - INTERVAL 30 DAY))");
    return ['say' => t('admin.say.tokens_cleaned', ['n' => $gone])];
}

/* ── Каталог схем ─────────────────────────────────────────────── */

/** Язык правки каталога: ru — основные поля, другой (из LANGS) — перевод в поле i18n. */
function adminCatalogLang(array $in): string
{
    $lang = (string) ($in['lang'] ?? 'ru');
    if (!in_array($lang, LANGS, true)) throw new ApiError(t('admin.err.bad_lang'));
    return $lang;
}

/** Записать поля перевода в i18n[lang] строки каталога; пустое значение убирает поле (тогда действует русское). */
function adminCatalogI18nSave(string $table, string $column, string|int $id, string $json, string $lang, array $fields): void
{
    $all = json_decode($json, true) ?: [];
    $cur = (array) ($all[$lang] ?? []);
    foreach ($fields as $name => $value) {
        if ($value === '' || $value === [] || $value === null) unset($cur[$name]); else $cur[$name] = $value;
    }
    if ($cur) $all[$lang] = $cur; else unset($all[$lang]);
    dbRun("UPDATE $table SET i18n = ? WHERE $column = ?", [json_encode($all ?: new stdClass(), JSON_UNESCAPED_UNICODE), $id]);
}

/** Удалить заготовку: каталог ведёт администратор — встроенную тоже, после подтверждения в админке. */
function adminTemplateDelete(int $id): array
{
    $row = dbRow('SELECT title FROM templates WHERE id = ?', [$id]);
    if (!$row) throw new ApiError(t('admin.err.no_template'), 'not_found');
    dbRun('DELETE FROM templates WHERE id = ?', [$id]);
    return ['say' => t('admin.say.template_deleted', ['title' => $row['title']])];
}

/**
 * Правка заготовки: название, описание, раздел, порядок, «в каталоге», паспорт, отметка проверки
 * (checked: "today" — сегодня, "clear" — снять). Тело меняет adminTemplateFromFolder с id.
 */
function adminTemplateUpdate(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    $row = dbRow('SELECT * FROM templates WHERE id = ?', [$id]);
    if (!$row) throw new ApiError(t('admin.err.no_template'), 'not_found');
    $lang = adminCatalogLang($in);
    if ($lang !== 'ru') {          // перевод: только название, описание и паспорт; раздел, порядок и отметки общие
        adminCatalogI18nSave('templates', 'id', $id, (string) $row['i18n'], $lang, [
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 255),
            'about' => mb_substr(trim((string) ($in['about'] ?? '')), 0, 512),
            'passport' => catalogPassport(json_encode($in['passport'] ?? [], JSON_UNESCAPED_UNICODE), PASSPORT_TEMPLATE),
        ]);
        return ['say' => t('admin.say.scheme_saved', ['title' => trim((string) ($in['title'] ?? '')) ?: $row['title']])];
    }
    $category = (string) ($in['category'] ?? $row['category']);
    if (!dbValue('SELECT 1 FROM template_categories WHERE `key` = ?', [$category])) throw new ApiError(t('admin.err.no_section_named', ['name' => $category]));
    $title = trim((string) ($in['title'] ?? $row['title']));
    if ($title === '') throw new ApiError(t('admin.err.no_title'));

    $passport = array_key_exists('passport', $in)
        ? catalogPassport(json_encode($in['passport'], JSON_UNESCAPED_UNICODE), PASSPORT_TEMPLATE)
        : catalogPassport((string) $row['passport'], PASSPORT_TEMPLATE);
    // Отметка проверки: «сегодня» — часы базы (NOW), как у прочих дат; «снять» — пусто; иначе прежняя.
    dbRun("UPDATE templates SET title = ?, about = ?, category = ?, sort = ?, in_catalog = ?,
                  checked_at = CASE ? WHEN 'today' THEN NOW(3) WHEN 'clear' THEN NULL ELSE checked_at END,
                  passport = ? WHERE id = ?", [
        mb_substr($title, 0, 255), mb_substr(trim((string) ($in['about'] ?? $row['about'])), 0, 512), $category,
        (int) ($in['sort'] ?? $row['sort']), !empty($in['inCatalog'] ?? $row['in_catalog']) ? 1 : 0,
        (string) ($in['checked'] ?? ''),
        json_encode($passport ?: new stdClass(), JSON_UNESCAPED_UNICODE), $id,
    ]);
    return ['say' => t('admin.say.scheme_saved', ['title' => $title])];
}

/**
 * Снять заготовку с папки: новую (id нет) — не в каталоге, пока её не проверят; с id — обновить тело
 * существующей, паспорт и отметки остаются.
 */
function adminTemplateFromFolder(array $in): array
{
    $folder = dbRow('SELECT * FROM folders WHERE id = ?', [(int) ($in['folder'] ?? 0)]);
    if (!$folder) throw new ApiError(t('admin.err.no_folder'), 'not_found');
    $body = templateBodyOfFolder((int) $folder['id']);
    if (!$body['elements']) throw new ApiError(t('admin.err.folder_empty'));
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);

    if ($id = (int) ($in['id'] ?? 0)) {
        $row = dbRow('SELECT id, i18n FROM templates WHERE id = ?', [$id]);
        if (!$row) throw new ApiError(t('admin.err.no_template'), 'not_found');
        $lang = adminCatalogLang($in);
        if ($lang !== 'ru') adminCatalogI18nSave('templates', 'id', $id, (string) $row['i18n'], $lang, ['body' => $body]);   // тело перевода
        else dbRun('UPDATE templates SET body = ? WHERE id = ?', [$json, $id]);
        return ['say' => trim(t('admin.say.body_updated', ['name' => $folder['name'], 'n' => count($body['elements'])]) . ' ' . templateLost((int) $folder['id']))];
    }
    $title = trim((string) ($in['title'] ?? '')) ?: (string) $folder['name'];
    $category = (string) ($in['category'] ?? '');
    if (!dbValue('SELECT 1 FROM template_categories WHERE `key` = ?', [$category])) throw new ApiError(t('admin.err.pick_category'));
    $key = trim((string) ($in['key'] ?? '')) ?: templateFreeKey($title);
    if (dbValue('SELECT 1 FROM templates WHERE `key` = ?', [$key])) throw new ApiError(t('admin.err.key_taken', ['key' => $key]));
    dbRun("INSERT INTO templates (`key`, title, family, category, about, body, sort, builtin)
           VALUES (?, ?, 'scheme', ?, ?, ?, ?, 1)",
        [substr($key, 0, 64), mb_substr($title, 0, 255), $category, mb_substr(trim((string) ($in['about'] ?? '')), 0, 512), $json,
         (int) dbValue('SELECT COALESCE(MAX(sort), 0) + 10 FROM templates WHERE category = ?', [$category])]);
    return ['say' => trim(t('admin.say.scheme_taken', ['title' => $title]) . ' ' . templateLost((int) $folder['id'])), 'id' => dbId()];
}

/** Раздел каталога: название, описание, порядок, паспорт. */
function adminCategorySave(array $in): array
{
    $key = (string) ($in['key'] ?? '');
    $row = dbRow('SELECT * FROM template_categories WHERE `key` = ?', [$key]);
    if (!$row) throw new ApiError(t('admin.err.no_category'), 'not_found');
    $lang = adminCatalogLang($in);
    if ($lang !== 'ru') {
        adminCatalogI18nSave('template_categories', '`key`', $key, (string) $row['i18n'], $lang, [
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 64),
            'about' => mb_substr(trim((string) ($in['about'] ?? '')), 0, 512),
            'passport' => catalogPassport(json_encode($in['passport'] ?? [], JSON_UNESCAPED_UNICODE), PASSPORT_CATEGORY),
        ]);
        return ['say' => t('admin.say.category_saved', ['title' => trim((string) ($in['title'] ?? '')) ?: $row['title']])];
    }
    $passport = catalogPassport(json_encode($in['passport'] ?? [], JSON_UNESCAPED_UNICODE), PASSPORT_CATEGORY);
    dbRun('UPDATE template_categories SET title = ?, about = ?, sort = ?, passport = ? WHERE `key` = ?', [
        mb_substr(trim((string) ($in['title'] ?? $row['title'])) ?: $row['title'], 0, 64),
        mb_substr(trim((string) ($in['about'] ?? $row['about'])), 0, 512), (int) ($in['sort'] ?? $row['sort']),
        json_encode($passport ?: new stdClass(), JSON_UNESCAPED_UNICODE), $key,
    ]);
    return ['say' => t('admin.say.category_saved', ['title' => $in['title'] ?? $row['title']])];
}

/** Зависшие задания ИИ во всей установке — той же уборкой, что у разговора (aiSweep). */
function adminAiSweep(): array
{
    aiSweep();
    return ['say' => t('admin.say.ai_swept')];
}

/** Стереть записи журнала старше N дней (не меньше 30): повтор старых пачек уже не нужен. */
function adminJournalPrune(int $days): array
{
    if ($days < 30) throw new ApiError(t('admin.err.prune_days'));
    $before = (int) dbValue('SELECT COUNT(*) FROM journal');
    dbRun('DELETE FROM journal WHERE created_at < NOW() - INTERVAL ? DAY', [$days]);
    return ['say' => t('admin.say.journal_pruned', ['n' => $before - (int) dbValue('SELECT COUNT(*) FROM journal')])];
}

/** Байты старых материалов в data/files, на которые никто не ссылается, — уборкой filesGc (как files-gc). */
function adminFilesGc(): array
{
    $gone = filesGc(true);
    return ['say' => t('admin.say.files_gc', ['n' => $gone['files'], 'mb' => round($gone['bytes'] / 1048576, 1)])];
}

function adminMigrationsApply(): array
{
    $waiting = schemaMigrations()['waiting'];
    foreach ($waiting as $name) schemaMigrate($name);
    return ['say' => $waiting ? t('admin.say.migrations_applied', ['list' => implode(', ', $waiting)]) : t('admin.say.migrations_none')];
}

/** Удалить рабочую папку-сироту со всеми файлами. Только в workfiles_dir и только если к ней не привязана схема. */
function adminOrphanDelete(string $name): array
{
    if (!in_array($name, array_column(adminOrphanDirs(), 'name'), true)) {
        throw new ApiError(t('admin.err.no_orphan'), 'not_found');
    }
    $dir = realpath((string) config()['workfiles_dir']) . '/' . $name;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($dir);
    return ['say' => t('admin.say.orphan_deleted', ['name' => $name])];
}
