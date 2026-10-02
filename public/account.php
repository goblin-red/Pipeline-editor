<?php
/* Кабинет: вход, проекты, агенты, пропуска, расход ИИ, профиль.
   Отдаёт: HTML-страницы кабинета и обработку их форм.
   Не делает: без входа ничего не пишет (кроме login и register);
              config.txt правит только администратор.
   Страницы простые: форма отправляется, страница перерисовывается.
   Запись всегда идёт через те же функции, что и API — мимо проверок никто не пишет. */

declare(strict_types=1);

require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/web/page.php';
require __DIR__ . '/../lib/web/chrome.php';
require __DIR__ . '/../lib/web/account/structure.php';
require __DIR__ . '/../lib/web/account/structure-view.php';

$section = (string) ($_GET['section'] ?? 'projects');
$notice = '';

try {
    if (isset($_GET['logout'])) { userLogout(); header('Location: account.php'); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireSameOrigin();
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'login')    { userLogin((string) ($_POST['login'] ?? $_POST['email'] ?? ''), (string) $_POST['password']); header('Location: account.php'); exit; }
        if ($do === 'register') { userRegister((string) $_POST['email'], (string) $_POST['password'], (string) ($_POST['name'] ?? '')); header('Location: account.php'); exit; }

        // Без входа — только войти или завести учётную запись. Остальное ниже.
        if (!currentUser()) throw new ApiError(t('account.err.need_login'), 'forbidden');

        // config.txt общий на всю установку: правит его только администратор.
        if (($do === 'list-save' || $do === 'list-drop') && !adminIn()) {
            throw new ApiError(t('account.err.lists_admin'), 'forbidden');
        }

        if ($do === 'access-save') { aiAccessSave((int) currentUser()['id'], $_POST); $notice = t('account.access.saved'); }
        if ($do === 'password') { userPassword((int) currentUser()['id'], (string) $_POST['old'], (string) $_POST['new']); $notice = t('account.say.password_changed'); }
        if ($do === 'list-save') {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $_POST['key'])) {
                throw new ApiError(t('account.err.key_format'));
            }
            listWrite((string) $_POST['section'], (string) $_POST['key'], [
                'label' => (string) $_POST['label'],
                'color' => (string) ($_POST['color'] ?? ''),
                'extra' => array_filter(array_map('trim', explode(',', (string) ($_POST['extra'] ?? '')))),
            ]);
            $notice = t('account.say.row_saved');
        }
        if ($do === 'list-drop') {
            listWrite((string) $_POST['section'], (string) $_POST['key'], null);
            $notice = t('account.say.row_deleted');
        }
        if (str_contains($do, '-') && in_array(explode('-', $do)[0], ['project', 'folder', 'element', 'spec'], true)) {
            $notice = structureAct($_POST);
        }
        if ($do === 'template-drop') {
            // То же правило, что у template.delete: своя — хозяину, прочие — только администратору.
            templateDrop(templateRow((string) $_POST['template']), adminIn());
            $notice = t('account.say.template_removed');
        }
    }
} catch (ApiError $error) {
    $notice = $error->getMessage();
}

$user = currentUser();
if (!$user) { accountLogin($notice); exit; }

pageTop(t('account.page.title'), $section);
// «Структура» показывает сообщение сама — в карточке, рядом с формой.
if ($notice && $section !== 'structure') echo '<p class="notice">', h($notice), '</p>';

match ($section) {
    'agents'  => accountAgents($user),
    'templates' => accountTemplates($user),
    'lists'   => accountLists($user),
    'structure' => structureView($user, $notice),
    'tokens'  => accountTokens($user),
    'ai'      => accountAi($user),
    'profile' => accountProfile($user),
    default   => accountProjects($user),
};
pageBottom();

/* ── Экраны ───────────────────────────────────────────────────── */

/** Полка заготовок: что есть, из чего собрано, что своё. */
function accountTemplates(array $user): void
{
    $rows = dbAll('SELECT t.*, u.email AS owner_email FROM templates t
                     LEFT JOIN users u ON u.id = t.owner_id
                    ORDER BY t.family, t.sort, t.id');

    echo '<h1>', h(t('account.nav.templates')), '</h1>',
         '<p class="muted">', t('account.templates.about'), '</p>';

    $families = ['scheme' => t('account.templates.family_scheme'), 'flow' => t('account.templates.family_flow')];
    foreach (TEMPLATE_FAMILIES as $family => $word) {
        $word = $families[$family] ?? $word;
        $mine = array_values(array_filter($rows, static fn($row) => $row['family'] === $family));
        echo '<h2>', h($word), ' · ', count($mine), '</h2>';
        if (!$mine) { echo '<p class="muted">', h(t('account.w.empty')), '</p>'; continue; }

        echo '<table class="acc-table"><thead><tr><th>', h(t('account.w.title')), '</th><th>', h(t('account.templates.category')), '</th><th>', h(t('account.w.elements')), '</th>',
             '<th>', h(t('account.templates.source')), '</th><th></th></tr></thead><tbody>';
        foreach ($mine as $row) {
            $count = count(json_decode((string) $row['body'], true)['elements'] ?? []);
            echo '<tr><td><b>', h($row['title']), '</b><br><small class="muted">', h($row['key']);
            if ($row['about'] !== '') echo ' · ', h($row['about']);
            echo '</small></td><td>', h($row['category']), '</td><td>', $count, '</td><td>';
            echo $row['builtin'] ? '<span class="muted">' . h(t('account.templates.builtin')) . '</span>' : h((string) ($row['owner_email'] ?? t('account.templates.own')));
            echo '</td><td>';
            if (!$row['builtin'] && (!$row['owner_id'] || (int) $row['owner_id'] === (int) $user['id'])) {
                echo '<form method="post"><input type="hidden" name="do" value="template-drop">',
                     '<input type="hidden" name="template" value="', h($row['key']), '">',
                     '<button class="btn btn-quiet btn-danger">', h(t('account.templates.remove')), '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
}

function accountLogin(string $notice): void
{
    pageTop(t('account.login.title'));
    echo '<div class="acc-card"><h1>', h(t('account.login.title')), '</h1>';
    if ($notice) echo '<p class="notice">', h($notice), '</p>';
    // Вход — по почте или по имени.
    echo '<form method="post"><input type="hidden" name="do" value="login">',
         '<label class="field"><span>', h(t('account.w.login')), '</span><input class="input" name="login" autocomplete="username" required></label>',
         '<label class="field"><span>', h(t('account.w.password')), '</span><input class="input" type="password" name="password" required></label>',
         '<button class="btn btn-accent btn-wide">', h(t('account.login.submit')), '</button></form>',
         '<details class="acc-more"><summary>', h(t('account.login.first_time')), '</summary>',
         '<form method="post"><input type="hidden" name="do" value="register">',
         '<label class="field"><span>', h(t('account.w.name')), '</span><input class="input" name="name"></label>',
         '<label class="field"><span>', h(t('account.w.email')), '</span><input class="input" type="email" name="email" required></label>',
         '<label class="field"><span>', h(t('account.w.password')), '</span><input class="input" type="password" name="password" required></label>',
         '<button class="btn btn-wide">', h(t('account.login.register')), '</button></form></details></div>';
    pageBottom();
}

function accountProjects(array $user): void
{
    $projects = userProjects((int) $user['id']);
    echo '<h1>', h(t('account.nav.projects')), '</h1><table class="acc-table"><thead><tr><th>', h(t('account.w.project')), '</th><th>', h(t('account.w.folders')), '</th><th>', h(t('account.w.elements')), '</th><th>', h(t('account.projects.edited')), '</th><th></th></tr></thead><tbody>';
    foreach ($projects as $project) {
        echo '<tr><td><a href="index.php#p=', h($project['url_key']), '">', h($project['title'] ?: $project['url_key']), '</a>',
             (int) $project['mine'] ? ' <span class="tag">' . h(t('account.projects.mine')) . '</span>' : '', '</td>',
             '<td>', (int) $project['folders'], '</td><td>', (int) $project['elements'], '</td>',
             '<td class="muted">', h((string) $project['updated_at']), '</td>',
             '<td><a class="btn btn-quiet" href="index.php?view=1#p=', h($project['url_key']), '">', h(t('account.projects.view')), '</a></td></tr>';
    }
    if (!$projects) echo '<tr><td colspan="5" class="muted">', h(t('account.projects.empty')), '</td></tr>';
    echo '</tbody></table><p><a class="btn btn-accent" href="index.php">', h(t('account.projects.new')), '</a></p>';
}

function accountAgents(array $user): void
{
    $rows = dbAll(
        'SELECT a.*, p.url_key, p.title AS project FROM agents a JOIN projects p ON p.id = a.project_id
          WHERE p.owner_id = ? ORDER BY p.updated_at DESC, a.role, a.name LIMIT 300',
        [$user['id']]
    );
    echo '<h1>', h(t('account.nav.agents')), '</h1><p class="muted">', h(t('account.agents.about')), '</p>',
         '<table class="acc-table"><thead><tr><th>', h(t('account.w.name')), '</th><th>', h(t('account.agents.role')), '</th><th>', h(t('account.agents.cli_model')), '</th><th>', h(t('account.w.project')), '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>', h($row['name']), '</td>',
             '<td><span class="tag ', h($row['role']), '">', $row['role'] === 'lead' ? t('account.agents.leader') : t('account.agents.worker'), '</span></td>',
             '<td class="muted">', h(trim($row['cli'] . ' ' . $row['model'] . ' ' . $row['effort'])), '</td>',
             '<td><a href="index.php#p=', h($row['url_key']), '">', h($row['project'] ?: $row['url_key']), '</a></td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="4" class="muted">', h(t('account.agents.none')), '</td></tr>';
    echo '</tbody></table>';
}

function accountTokens(array $user): void
{
    $rows = dbAll(
        "SELECT t.*, p.url_key, (t.expires_at IS NOT NULL AND t.expires_at <= NOW(3)) AS expired
           FROM tokens t LEFT JOIN projects p ON p.id = t.project_id
          WHERE t.scope IN ('project','run','step') AND (p.owner_id = ? OR t.created_by = ?)
          ORDER BY t.created_at DESC LIMIT 100",
        [$user['id'], $user['id']]
    );
    echo '<h1>', h(t('account.nav.tokens')), '</h1><p class="muted">', h(t('account.tokens.about')), '</p>',
         '<table class="acc-table"><thead><tr><th>', h(t('account.tokens.kind')), '</th><th>', h(t('account.w.project')), '</th><th>', h(t('account.tokens.label')), '</th><th>', h(t('account.tokens.issued')), '</th><th>', h(t('account.w.state')), '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $what = ['project' => t('account.tokens.k_project'), 'run' => t('account.tokens.k_run'), 'step' => t('account.tokens.k_step')][$row['scope']] ?? $row['scope'];
        // Истёк ли — решает база по своим часам: с ними же пропуск сверяет tokenFind().
        $alive = $row['revoked_at'] ? t('account.tokens.revoked') : ((int) $row['expired'] ? t('account.tokens.expired') : t('account.tokens.live'));
        echo '<tr><td>', h($what), '</td><td>', h((string) $row['url_key']), '</td><td>', h((string) $row['label']), '</td>',
             '<td class="muted">', h((string) $row['created_at']), '</td><td>', h($alive), '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5" class="muted">', h(t('account.tokens.none')), '</td></tr>';
    echo '</tbody></table>';
}

function accountAi(array $user): void
{
    $projects = dbAll('SELECT id, url_key, title FROM projects WHERE owner_id = ?', [$user['id']]);
    echo '<h1>', h(t('account.ai.title')), '</h1><p class="muted">', h(t('account.ai.limit')), '</p>';
    accountAccess($user);

    // Все разговоры целиком: в панели схемы видно только пять последних.
    $chats = dbAll(
        'SELECT c.id, c.title, c.state, c.updated_at, p.url_key, p.title AS project
           FROM ai_chats c JOIN projects p ON p.id = c.project_id
          WHERE p.owner_id = ? ORDER BY c.updated_at DESC LIMIT 200',
        [$user['id']]
    );
    echo '<h2>', h(t('account.ai.chats')), '</h2>';
    if (!$chats) {
        echo '<p class="muted">', h(t('account.ai.no_chats')), '</p>';
    } else {
        echo '<table class="acc-table"><thead><tr><th>', h(t('account.ai.when')), '</th><th>', h(t('account.w.project')), '</th><th>', h(t('account.ai.chat')), '</th><th></th></tr></thead><tbody>';
        foreach ($chats as $chat) {
            echo '<tr><td>', h(substr((string) $chat['updated_at'], 0, 16)), '</td>',
                 '<td>', h((string) ($chat['project'] ?: $chat['url_key'])), '</td>',
                 '<td>', h((string) ($chat['title'] ?: t('account.ai.chat_default'))), '</td>',
                 '<td><a href="chat.php?project=', h(rawurlencode((string) $chat['url_key'])), '&chat=', (int) $chat['id'], '" target="_blank">', h(t('account.ai.open_log')), '</a> · ',
                 '<a href="index.php#p=', h((string) $chat['url_key']), '">', h(t('account.ai.open_scheme')), '</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>', h(t('account.ai.usage')), '</h2>';
    foreach ($projects as $project) {
        $usage = aiUsage((int) $project['id'], 14);
        if (!$usage) continue;
        echo '<h2>', h($project['title'] ?: $project['url_key']), '</h2><table class="acc-table"><thead><tr><th>', h(t('account.ai.day')), '</th><th>', h(t('account.ai.requests')), '</th><th>', h(t('account.ai.tokens')), '</th></tr></thead><tbody>';
        foreach ($usage as $day) {
            echo '<tr><td>', h($day['day']), '</td><td>', $day['calls'], '</td><td>', number_format($day['tokens'], 0, lang() === 'ru' ? ',' : '.', lang() === 'ru' ? ' ' : ','), '</td></tr>';
        }
        echo '</tbody></table>';
    }
}

/**
 * Личный доступ к моделям: DeepSeek (помощник, конструктор), голос OpenAI (распознавание, озвучка)
 * и Jev (сигнальщик прогона: работает на доступе владельца проекта).
 * Пустое поле — общий доступ установки; он подсказан в поле серым. Общий ключ не показывается,
 * свой — открытым текстом (решение хозяина 29.09.2026); ключ по умолчанию (копия общего) — звёздочками (30.09.2026).
 */
function accountAccess(array $user): void
{
    $own = aiAccessOwn((int) $user['id']);
    $use = aiAccessOf((int) $user['id']);   // что работает сейчас: общий ключ — только на общий адрес
    $base = config();
    $groups = [
        'account.access.ai'    => ['ai_api_url', 'ai_model', 'ai_api_key'],
        'account.access.voice' => ['voice_api_url', 'voice_model', 'voice_languages', 'voice_tts_model', 'voice_tts_voice', 'voice_api_key'],
        'account.access.jev'   => ['jev_api_url', 'jev_model', 'jev_api_key'],
    ];
    echo '<h2>', h(t('account.access.title')), '</h2><p class="muted">', h(t('account.access.about')), '</p>',
         '<form method="post" class="acc-card acc-access"><input type="hidden" name="do" value="access-save">';
    foreach ($groups as $title => $fields) {
        echo '<h3>', h(t($title)), '</h3>';
        foreach ($fields as $field) {
            $secret = str_ends_with($field, '_key');
            $value = (string) ($own[$field] ?? '');
            if (aiAccessHidden($field, $value)) $value = ACCESS_MASK;
            $shared = (string) ($base[$field] ?? '');
            $hint = $secret ? t($use[$field] !== '' ? 'account.access.shared_key' : 'account.access.no_key') : ($shared !== '' ? $shared : t('account.access.any'));
            echo '<label class="field"><span>', h(t('account.access.f.' . $field)), '</span>',
                 '<input class="input" type="text" name="', h($field), '" value="', h($value), '" placeholder="', h($hint), '"',
                 $secret ? ' autocomplete="off" spellcheck="false"' : '', '></label>';
        }
        $key = $groups[$title][count($fields) - 1];
        $mine = isset($own[$key]) && !aiAccessHidden($key, $own[$key]);
        $now = $use[$key] === '' ? 'account.access.need_key' : ($mine ? 'account.access.using_own' : 'account.access.using_shared');
        echo '<p class="muted">', h(t($now)), '</p>';
    }
    echo '<button class="btn btn-wide">', h(t('account.access.save')), '</button></form>';
}

function accountProfile(array $user): void
{
    echo '<h1>', h(t('account.nav.profile')), '</h1><div class="acc-card">',
         '<p><b>', h($user['name'] ?: t('account.w.unnamed')), '</b><br><span class="muted">', h($user['email']), '</span></p>',
         '<form method="post"><input type="hidden" name="do" value="password">',
         '<label class="field"><span>', h(t('account.profile.old_password')), '</span><input class="input" type="password" name="old" required></label>',
         '<label class="field"><span>', h(t('account.profile.new_password')), '</span><input class="input" type="password" name="new" required></label>',
         '<button class="btn btn-wide">', h(t('account.profile.change')), '</button></form></div>';
}


/** Списки из config.txt: CLI, модели, роли, эффорты, цвета, типы материалов, свойства. */
function accountLists(array $user): void
{
    $data = lists(true)['sections'];
    $titles = [
        'cli' => t('account.lists.t_cli'), 'roles' => t('account.lists.t_roles'), 'effort' => t('account.lists.t_effort'),
        'permission' => t('account.lists.t_permission'), 'sandbox' => t('account.lists.t_sandbox'), 'colors' => t('account.lists.t_colors'),
        'asset_kinds' => t('account.lists.t_asset_kinds'), 'props' => t('account.lists.t_props'),
    ];
    echo '<h1>', h(t('account.nav.lists')), '</h1><p class="muted">', t('account.lists.about', ['file' => '<code>config.txt</code>']), '</p>';

    // Не администратор видит списки, но не правит их.
    if (!adminIn()) {
        echo '<p class="notice">', t('account.lists.admin_only', ['admin' => '<a href="admin.php">' . h(t('account.lists.admin_link')) . '</a>']), '</p>';
        accountListsRead($data, $titles);
        return;
    }

    foreach ($titles as $section => $title) {
        $rows = $data[$section] ?? [];
        echo '<h2>', h($title), '</h2><table class="acc-table"><thead><tr><th>', h(t('account.lists.key')), '</th><th>', h(t('account.w.title')), '</th>',
             '<th>', h(t('account.lists.color')), '</th><th>', h(t('account.lists.extra')), '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $key => $row) {
            echo '<tr><form method="post"><input type="hidden" name="do" value="list-save">',
                 '<input type="hidden" name="section" value="', h($section), '">',
                 '<input type="hidden" name="key" value="', h((string) $key), '">',
                 '<td class="mono">', h((string) $key), '</td>',
                 '<td><input class="input" name="label" value="', h($row['label']), '"></td>',
                 '<td><input class="input" name="color" value="', h($row['color']), '"></td>',
                 '<td><input class="input" name="extra" value="', h(implode(', ', $row['extra'])), '"></td>',
                 '<td class="row"><button class="btn btn-quiet">', h(t('common.save')), '</button></form>',
                 '<form method="post"', confirmAttr(t('account.lists.drop_ask')), '>',
                 '<input type="hidden" name="do" value="list-drop"><input type="hidden" name="section" value="', h($section), '">',
                 '<input type="hidden" name="key" value="', h((string) $key), '">',
                 '<button class="btn btn-quiet btn-danger">×</button></form></td></tr>';
        }
        echo '<tr><form method="post"><input type="hidden" name="do" value="list-save">',
             '<input type="hidden" name="section" value="', h($section), '">',
             '<td><input class="input mono" name="key" placeholder="', h(t('account.lists.new_key')), '"></td>',
             '<td><input class="input" name="label" placeholder="', h(t('account.w.title')), '"></td>',
             '<td><input class="input" name="color" placeholder="', h(t('account.lists.color_hint')), '"></td>',
             '<td><input class="input" name="extra" placeholder="', h(t('account.lists.comma_hint')), '"></td>',
             '<td><button class="btn">', h(t('account.lists.add')), '</button></td></form></tr>';
        echo '</tbody></table>';
    }
}

/** Те же списки только для чтения: без форм и кнопок. */
function accountListsRead(array $data, array $titles): void
{
    foreach ($titles as $section => $title) {
        $rows = $data[$section] ?? [];
        echo '<h2>', h($title), '</h2><table class="acc-table"><thead><tr><th>', h(t('account.lists.key')), '</th><th>', h(t('account.w.title')), '</th>',
             '<th>', h(t('account.lists.color')), '</th><th>', h(t('account.lists.extra')), '</th></tr></thead><tbody>';
        foreach ($rows as $key => $row) {
            echo '<tr><td class="mono">', h((string) $key), '</td>',
                 '<td>', h($row['label']), '</td>',
                 '<td>', h($row['color']), '</td>',
                 '<td>', h(implode(', ', $row['extra'])), '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="4" class="muted">', h(t('account.w.empty')), '</td></tr>';
        echo '</tbody></table>';
    }
}
