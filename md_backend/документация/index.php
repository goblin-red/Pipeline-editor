<?php
/* Чтение документации из documentation_pages. GET ничего не записывает.
   ?page=slug — отдельная рубрика; подразделы открываются по ссылкам.
   documentation_links — направленные связи с собственными id.
   [[ID|текст ссылки]] разворачивается по записи связи, ?link=ID открывает цель.
   HTML в базе — доверенный авторский материал, а не пользовательский ввод.
   Добавление материалов выполняется отдельно от страницы чтения. */
declare(strict_types=1);
require_once __DIR__ . '/graph.php';

$root = dirname(__DIR__, 2);
require_once $root . '/lib/core/config.php';
require_once $root . '/lib/core/db.php';
require_once $root . '/lib/core/i18n.php';

// Переключатель языка страницы: ?lang=ru|en — cookie на год (та же, что в редакторе) и обратно на ту же страницу.
if (in_array($_GET['lang'] ?? '', LANGS, true)) {
    setcookie('goblin_lang', (string) $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
    $back = $_GET;
    unset($back['lang']);
    header('Location: ?' . http_build_query($back), true, 302);
    exit;
}

function docEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function docUrl(string $slug): string
{
    return '?page=' . rawurlencode($slug);
}

function docList(string $json): array
{
    $value = json_decode($json, true);
    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}

/** Родители от корня к странице. Защита от случайного кольца при ручной правке БД. */
function docAncestors(array $page, array $byId): array
{
    $trail = [];
    $seen = [(int) $page['id'] => true];
    while ($page['parent_id'] !== null) {
        $id = (int) $page['parent_id'];
        if (isset($seen[$id]) || !isset($byId[$id])) break;
        $seen[$id] = true;
        $page = $byId[$id];
        array_unshift($trail, $page);
    }
    return $trail;
}

function docTree(int $parent, array $children, string $selected, array $trail, array $seen = []): void
{
    if (empty($children[$parent])) return;
    echo '<ul>';
    foreach ($children[$parent] as $page) {
        $id = (int) $page['id'];
        if (isset($seen[$id])) continue;
        $path = $seen + [$id => true];
        $active = $page['slug'] === $selected;
        echo '<li>';
        $link = '<a href="' . docEscape(docUrl($page['slug'])) . '"'
            . ($active ? ' aria-current="page"' : '') . '>' . docEscape($page['title']) . '</a>';
        if (!empty($children[$id])) {
            echo '<details', ($active || isset($trail[$id]) ? ' open' : ''), '><summary>', $link, '</summary>';
            docTree($id, $children, $selected, $trail, $path);
            echo '</details>';
        } else {
            echo $link;
        }
        echo '</li>';
    }
    echo '</ul>';
}

/** Подзаголовки подробного текста подстраиваются под уровень рубрики. */
function docBody(string $html, int $level): string
{
    return (string) preg_replace_callback('~<(\/?)(h[3-6])\b~i', static function (array $m) use ($level): string {
        $target = max(2, min(6, (int) substr($m[2], 1) + $level - 2));
        return '<' . $m[1] . 'h' . $target;
    }, $html);
}

/** Одна запись связи обслуживает текст, исходящие и входящие ссылки. */
function docReferences(string $html, int $sourceId, array $linksById, array $byId): string
{
    return (string) preg_replace_callback('~<(code|pre)\b[^>]*>.*?</\1\s*>|\[\[([1-9][0-9]*)\|([^\]\r\n]+)\]\]~isu', static function (array $m) use ($sourceId, $linksById, $byId): string {
        // Примеры синтаксиса внутри code/pre остаются примерами.
        if (($m[1] ?? '') !== '') return $m[0];
        $link = $linksById[(int) $m[2]] ?? null;
        if (!$link || (int) $link['source_page_id'] !== $sourceId || !isset($byId[(int) $link['target_page_id']])) {
            return '<span class="missing-link">' . t('docs.link.missing') . '</span>';
        }
        $target = $byId[(int) $link['target_page_id']];
        $label = html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<a href="?link=' . (int) $link['id'] . '" data-link-id="' . (int) $link['id'] . '">' . docEscape($label) . '</a>';
    }, $html);
}

function docLinkGroups(): array
{
    return [
        'php' => t('docs.group.php'), 'html' => 'HTML', 'css' => 'CSS',
        'javascript' => 'JavaScript', 'database' => t('docs.group.database'),
        'related' => t('docs.group.related'),
    ];
}

function docConnections(int $id, array $byId, array $outgoing, array $incoming, int $heading, bool $open): void
{
    $out = $outgoing[$id] ?? [];
    $in = $incoming[$id] ?? [];
    echo '<details class="connections"><summary>', t('docs.connections.summary', ['outgoing' => count($out), 'incoming' => count($in)]), '</summary>';
    echo '<div class="connection-columns">';
    foreach ([t('docs.connections.outgoing') => [$out, 'target_page_id'], t('docs.connections.incoming') => [$in, 'source_page_id']] as $title => [$rows, $field]) {
        echo '<div><h', $heading, '>', $title, '</h', $heading, '>';
        if (!$rows) echo '<p class="empty">' . t('docs.connections.empty') . '</p>';
        else {
            $groups = [];
            if ($field === 'target_page_id') {
                foreach (docLinkGroups() as $type => $groupTitle) {
                    $groupRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['resource_type'] ?? 'related') === $type));
                    if ($groupRows) $groups[$groupTitle] = $groupRows;
                }
            } else {
                $groups[''] = $rows;
            }
            foreach ($groups as $groupTitle => $groupRows) {
                if ($groupTitle !== '') echo '<p class="link-group-title">', docEscape($groupTitle), '</p>';
                echo '<ul>';
                foreach ($groupRows as $link) {
                    $other = $byId[(int) $link[$field]];
                    $url = $field === 'target_page_id' ? '?link=' . (int) $link['id'] : docUrl($other['slug']);
                    echo '<li><a href="', docEscape($url), '" data-link-id="', (int) $link['id'], '">', docEscape($other['title']), '</a></li>';
                }
                echo '</ul>';
            }
        }
        echo '</div>';
    }
    echo '</div><a class="map-link" href="?view=graph&amp;scope=neighbors&amp;focus=', rawurlencode($byId[$id]['slug']), '">', t('docs.connections.map'), '</a></details>';
}

function docTags(array $page): void
{
    $tags = docList($page['keywords']);
    if (!$tags) return;
    echo '<p class="tags"><span>' . t('docs.tags.label') . '</span> ';
    foreach ($tags as $tag) {
        echo '<a class="doc-tag" rel="tag" href="?tag=', rawurlencode($tag), '">', docEscape($tag), '</a> ';
    }
    echo '</p>';
}

/** Врезка хранится один раз; список планов показывает тот же оригинал. */
function docCallout(array $row, array $byId, array $linksById, int $heading, bool $inList = false): void
{
    $page = $byId[(int) $row['page_id']] ?? null;
    if (!$page) return;
    $plan = $row['kind'] === 'future_plan';
    $title = $plan ? t('docs.callout.plan') : t('docs.callout.agent');
    $id = (int) $row['id'];
    echo '<section id="callout-', $id, '" class="section-summary doc-callout ', $plan ? 'callout-plan' : 'callout-agent',
        '" data-callout-id="', $id, '" data-callout-kind="', docEscape($row['kind']), '" data-source-page-id="', (int) $row['page_id'], '">';
    echo '<h', $heading, '>', $title, '</h', $heading, '>';
    if ($inList) echo '<p class="callout-meta">', t('docs.callout.section'), ' <a href="', docEscape(docUrl($page['slug']) . '#callout-' . $id), '">', docEscape($page['title']), '</a></p>';
    echo docReferences($row['body_html'], (int) $row['page_id'], $linksById, $byId);
    $approvalLabels = ['pending'=>t('docs.approval.pending'), 'approved'=>t('docs.approval.approved'), 'rejected'=>t('docs.approval.rejected')];
    echo '<p class="callout-meta">', docEscape($approvalLabels[$row['approval'] ?? 'pending'] ?? t('docs.approval.pending'));
    if (!empty($row['reviewed_by'])) echo ' · ', docEscape($row['reviewed_by']), ' · ', docEscape($row['reviewed_at'] ?? '');
    echo '</p>';
    if ($row['author_name'] !== '') echo '<p class="callout-meta">', t('docs.callout.author'), ' ', docEscape($row['author_name']), '</p>';
    if ($plan && !$inList) echo '<p class="callout-meta"><a href="?page=future-list#callout-', $id, '">', t('docs.callout.plans_list'), '</a></p>';
    echo '</section>';
}

function docArticle(array $page, array $children, array $byId, array $linksById, array $outgoing, array $incoming, int $level = 1, array $seen = []): void
{
    global $calloutsByPage, $allCallouts;
    $id = (int) $page['id'];
    if (isset($seen[$id])) return;
    $seen[$id] = true;
    $heading = min(6, $level);
    $subheading = min(6, $level + 1);
    $slug = $page['slug'];
    echo '<article id="', docEscape($slug), '" class="doc-article" data-document="', docEscape($slug), '">';
    echo '<h', $heading, '>', docEscape($page['title']), '</h', $heading, '>';
    if ($slug === 'introduction') {
        echo docReferences($page['summary_html'], $id, $linksById, $byId), '</article>';
        return;
    }
    $statusLabels = ['proposed'=>t('docs.status.proposed'), 'approved'=>t('docs.status.approved'), 'implemented'=>t('docs.status.implemented')];
    echo '<div class="doc-status">';
    if (isset($statusLabels[$page['status'] ?? ''])) echo '<span>', docEscape($statusLabels[$page['status']]), '</span>';
    if (!empty($page['reviewed_at'])) echo ' · ', t('docs.article.reviewed'), ' ', docEscape($page['reviewed_at']);
    if (!empty($page['source_note'])) echo '<p>', docEscape($page['source_note']), '</p>';
    echo '</div>';
    echo '<div class="section-summary" aria-labelledby="', docEscape($slug), '-summary">';
    echo '<h', $subheading, ' id="', docEscape($slug), '-summary">', t('docs.article.summary'), '</h', $subheading, '>';
    echo docReferences($page['summary_html'], $id, $linksById, $byId), '</div>';
    echo '<div class="section-body" aria-labelledby="', docEscape($slug), '-detail">';
    echo '<h', $subheading, ' id="', docEscape($slug), '-detail">', t('docs.article.detail'), '</h', $subheading, '>';
    echo docReferences(docBody($page['body_html'], $level), $id, $linksById, $byId);
    echo '</div>';
    foreach ($calloutsByPage[$id] ?? [] as $callout) {
        if ($slug === 'future-list' && $callout['kind'] === 'future_plan') continue;
        docCallout($callout, $byId, $linksById, $subheading);
    }
    if ($slug === 'future-list') {
        $plans = array_filter($allCallouts, static fn(array $row): bool => $row['kind'] === 'future_plan');
        if (!$plans) echo '<p class="empty">' . t('docs.article.no_plans') . '</p>';
        foreach ($plans as $callout) docCallout($callout, $byId, $linksById, $subheading, true);
    }
    docConnections($id, $byId, $outgoing, $incoming, min(6, $level + 2), $level === 1);
    docTags($page);
    echo '</article>';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'");

$byId = $bySlug = $children = $linksById = $outgoing = $incoming = [];
$calloutsByPage = $allCallouts = [];
$selected = null;
$error = '';
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'article';
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
$tag = is_string($_GET['tag'] ?? null) ? trim($_GET['tag']) : '';
$filtered = $query !== '' || $tag !== '';
$matches = [];
try {
    // Язык человека; английской версии ещё нет — показываем русскую.
    $docLang = lang() !== 'ru' && dbValue('SELECT 1 FROM documentation_pages WHERE lang = ? LIMIT 1', [lang()]) ? lang() : 'ru';
    foreach (dbAll('SELECT * FROM documentation_pages WHERE lang = ? ORDER BY sort_order, id', [$docLang]) as $page) {
        $byId[(int) $page['id']] = $page;
        $bySlug[$page['slug']] = $page;
        $children[(int) ($page['parent_id'] ?? 0)][] = $page;
    }
    foreach (dbAll('SELECT * FROM documentation_links ORDER BY sort_order, id') as $link) {
        $source = (int) $link['source_page_id'];
        $target = (int) $link['target_page_id'];
        if (!isset($byId[$source], $byId[$target])) continue;
        $linksById[(int) $link['id']] = $link;
        $outgoing[$source][] = $link;
        $incoming[$target][] = $link;
    }
    $allCallouts = array_values(array_filter(dbAll('SELECT * FROM documentation_callouts ORDER BY sort_order, id'),
        static fn(array $row) => isset($byId[(int) $row['page_id']])));
    foreach ($allCallouts as $row) $calloutsByPage[(int) $row['page_id']][] = $row;
    if (isset($_GET['link'])) {
        $linkId = filter_var($_GET['link'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $link = $linkId !== false ? ($linksById[$linkId] ?? null) : null;
        if ($link) {
            header('Location: ' . docUrl($byId[(int) $link['target_page_id']]['slug']), true, 302);
            exit;
        }
        http_response_code(404);
        $error = t('docs.error.link_not_found');
    }
    $asked = $_GET['page'] ?? $_GET['focus'] ?? 'introduction';
    $selected = is_string($asked) ? ($bySlug[$asked] ?? null) : null;
    if (!$selected && $view !== 'graph' && !$filtered && $error === '') {
        http_response_code(404);
        $error = t('docs.error.not_found');
    }
    if ($filtered) {
        foreach ($byId as $id => $page) {
            $tags = array_map(static fn(string $value): string => mb_strtolower(trim($value), 'UTF-8'), docList($page['keywords']));
            if ($tag !== '' && !in_array(mb_strtolower($tag, 'UTF-8'), $tags, true)) continue;
            $text = $page['title'] . ' ' . implode(' ', docList($page['keywords'])) . ' '
                . html_entity_decode(strip_tags($page['summary_html'] . ' ' . $page['body_html']), ENT_QUOTES, 'UTF-8');
            foreach ($calloutsByPage[$id] ?? [] as $row) $text .= ' ' . html_entity_decode(strip_tags($row['body_html']), ENT_QUOTES, 'UTF-8');
            if ($query === '' || mb_stripos($text, $query) !== false) $matches[$id] = $page;
        }
    }
} catch (Throwable $e) {
    error_log('[goblin documentation] ' . $e->getMessage());
    http_response_code(503);
    $error = t('docs.error.unavailable');
}
$ancestors = $selected ? docAncestors($selected, $byId) : [];
$trail = array_fill_keys(array_map(static fn(array $p): int => (int) $p['id'], $ancestors), true);
// Машиночитаемое представление того же графа для агентов.
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($error !== '' ? ['error' => $error] : [
        'pages' => array_values($byId), 'links' => array_values($linksById), 'callouts' => $allCallouts,
        'selected' => $selected ? (int) $selected['id'] : null,
        'matches' => array_keys($matches),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
?>
<!doctype html>
<html lang="<?= docEscape($docLang ?? 'ru') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= docEscape($view === 'graph' ? t('docs.title.map') : ($tag !== '' ? t('docs.title.tag', ['tag' => $tag]) : ($selected ? $selected['title'] . ' · Goblin' : t('docs.title.default')))) ?></title>
  <style><?= file_get_contents(__DIR__ . '/style.css') ?></style>
</head>
<body>
  <a class="skip-link" href="#content"><?= t('docs.skip') ?></a>
  <div class="layout">
    <aside>
      <a class="brand" href="?page=introduction" aria-label="<?= t('docs.brand.label') ?>"><img src="img/logo-docs.svg" alt="<?= t('docs.brand.alt') ?>" width="200" height="82"></a>
      <nav aria-label="<?= t('docs.nav.label') ?>">
        <?php docTree(0, $children, $selected['slug'] ?? '', $trail); ?>
      </nav>
      <form class="doc-search" method="get">
        <label for="doc-query"><?= t('docs.search.label') ?></label>
        <input id="doc-query" type="search" name="q" value="<?= docEscape($query) ?>" placeholder="<?= t('docs.search.placeholder') ?>">
        <button type="submit"><?= t('docs.search.submit') ?></button>
      </form>
      <a class="graph-nav" href="?view=graph"><?= t('docs.graph.nav') ?></a>
      <div class="doc-lang" role="group" aria-label="<?= t('docs.lang.label') ?>">
        <?php foreach (['ru' => 'Русский', 'en' => 'English'] as $code => $name): ?>
          <a href="<?= docEscape('?' . http_build_query(['lang' => $code] + $_GET)) ?>"<?= lang() === $code ? ' aria-current="true"' : '' ?>><?= $name ?></a>
        <?php endforeach; ?>
      </div>
    </aside>
    <main id="content">
      <?php if ($error !== ''): ?>
        <h1><?= t('docs.title.default') ?></h1><p><?= docEscape($error) ?></p>
      <?php elseif ($view === 'graph'): ?>
        <h1><?= t('docs.graph.title') ?></h1>
        <?php
          $focusSlug = is_string($_GET['focus'] ?? null) ? $_GET['focus'] : '';
          $focus = $bySlug[$focusSlug] ?? null;
          $section = is_string($_GET['section'] ?? null) ? $_GET['section'] : '';
          $scope = ($_GET['scope'] ?? '') === 'neighbors' ? 'neighbors' : 'all';
          docGraphControls($byId, $section, $scope, $focusSlug, $query, $tag);
          [$graphPages, $graphLinks] = docGraphSubset($byId, $linksById, $focus ? (int)$focus['id'] : null, $section, $scope);
          echo '<p class="empty">', t('docs.graph.counts', ['articles' => count($graphPages), 'links' => count($graphLinks)]), '</p>';
          docGraph($graphPages, $graphLinks, $focus ? (int)$focus['id'] : null, array_keys($matches), $filtered);
        ?>
        <p class="graph-help"><?= t('docs.graph.help') ?></p>
      <?php elseif ($filtered): ?>
        <h1><?= $tag !== '' ? t('docs.tag.heading', ['tag' => docEscape($tag)]) : t('docs.search.heading') ?></h1>
        <?php if ($query !== ''): ?><p><?= t('docs.search.query', ['query' => docEscape($query)]) ?></p><?php endif; ?>
        <p><?= t('docs.search.found', ['n' => count($matches)]) ?></p>
        <?php foreach ($matches as $page): ?>
          <section class="search-result">
            <h2><a href="<?= docEscape(docUrl($page['slug'])) ?>"><?= docEscape($page['title']) ?></a></h2>
            <?= docReferences($page['summary_html'], (int) $page['id'], $linksById, $byId) ?>
            <?php docTags($page); ?>
            <?php docConnections((int) $page['id'], $byId, $outgoing, $incoming, 3, true); ?>
          </section>
        <?php endforeach; ?>
      <?php elseif ($selected): ?>
        <nav class="breadcrumbs" aria-label="<?= t('docs.breadcrumbs.label') ?>">
          <a href="?page=introduction"><?= t('docs.breadcrumbs.root') ?></a>
          <?php foreach ($ancestors as $ancestor): ?>
            <span aria-hidden="true"> / </span><a href="<?= docEscape(docUrl($ancestor['slug'])) ?>"><?= docEscape($ancestor['title']) ?></a>
          <?php endforeach; ?>
          <span aria-hidden="true"> / </span><span aria-current="page"><?= docEscape($selected['title']) ?></span>
        </nav>
        <?php docArticle($selected, $children, $byId, $linksById, $outgoing, $incoming); ?>
      <?php else: ?>
        <h1><?= t('docs.title.default') ?></h1>
        <p><?= docEscape($error) ?></p>
      <?php endif; ?>
    </main>
  </div>

  <?php if ($view === 'graph' && $error === ''): ?>
    <script nonce="<?= docEscape($nonce) ?>"><?= file_get_contents(__DIR__ . '/graph.js') ?></script>
  <?php endif; ?>
</body>
</html>
