<?php
/* Обвязка простых страниц: шапка, подвал, экранирование.
   Отдаёт: pageTop(), pageBottom(). Экранирование h() живёт в web/page.php.
   Не делает: не содержит данных — только рамка вокруг них. */

declare(strict_types=1);

function pageTop(string $title, string $active = ''): void
{
    $version = editorVersion(dirname(__DIR__, 2));
    header('Content-Type: text/html; charset=utf-8');
    $tabs = ['projects' => t('account.nav.projects'), 'structure' => t('account.nav.structure'), 'agents' => t('account.nav.agents'),
             'templates' => t('account.nav.templates'), 'lists' => t('account.nav.lists'), 'tokens' => t('account.nav.tokens'),
             'ai' => t('account.nav.ai'), 'profile' => t('account.nav.profile')];
    echo '<!doctype html><html lang="', lang(), '" data-theme="dark"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1"><title>', h($title), t('account.page.title_suffix'), '</title>',
         '<link rel="stylesheet" href="css/tokens.css?v=', $version, '">',
         '<link rel="stylesheet" href="css/base.css?v=', $version, '">',
         '<link rel="stylesheet" href="css/account.css?v=', $version, '"></head>',
         '<body class="account" data-section="', h($active), '">',
         '<header class="acc-top"><a class="brand" href="index.php">', t('account.page.brand'), '</a><nav>';
    foreach ($tabs as $key => $label) {
        echo '<a href="account.php?section=', $key, '"', $active === $key ? ' class="on"' : '', '>', h($label), '</a>';
    }
    echo '</nav><a class="acc-out" href="account.php?logout=1">', t('account.page.logout'), '</a></header><main class="acc-main">';
}

/** Атрибут «спросить перед отправкой формы»: текст — в кавычках JS, всё экранировано под HTML. */
function confirmAttr(string $text): string
{
    return ' onsubmit="return confirm(' . h(json_encode($text, JSON_UNESCAPED_UNICODE)) . ')"';
}

function pageBottom(): void
{
    echo '</main></body></html>';
}
