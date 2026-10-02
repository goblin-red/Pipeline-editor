<?php
declare(strict_types=1);

/** Карта использует те же записи связей, что и входящие/исходящие списки. */
function docGraph(array $pages, array $links, ?int $focusId, array $matchedIds, bool $searching): void
{
    if (!$pages) { echo '<p>' . t('docs.graph.empty') . '</p>'; return; }
    $positions = [];
    $ids = array_keys($pages);
    $count = count($ids);
    $lite = $count > 80 || count($links) > 250;
    $edgeSegments = [];
    $radius = max(280, ($lite ? sqrt($count) * 35 : $count * 30));
    $width = $radius * 2 + 380;
    $height = $radius * 2 + 200;
    $cx = $width / 2;
    $cy = $height / 2;
    // Корневой документ в центре, остальные — вокруг него.
    foreach ($ids as $i => $id) {
        $angle = 2 * M_PI * max(0, $i - 1) / max(1, $count - 1) - M_PI / 2;
        $positions[$id] = $i === 0 ? [$cx, $cy] : [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
    }
    $matches = array_fill_keys($matchedIds, true);
    echo '<div class="graph-controls"><button type="button" data-zoom="in" aria-label="' . t('docs.graph.zoom_in') . '">+</button> <button type="button" data-zoom="out" aria-label="' . t('docs.graph.zoom_out') . '">−</button> <button type="button" data-zoom="reset">' . t('docs.graph.zoom_reset') . '</button> <label><input type="checkbox" id="graph-hierarchy" checked> ' . t('docs.graph.hierarchy') . '</label></div>';
    echo '<div class="graph-frame"><svg id="document-graph" viewBox="0 0 ', $width, ' ', $height, '" data-lite="', $lite ? '1' : '0', '" data-focus="', (int) $focusId, '" data-more="', docEscape(t('docs.graph.more')), '" role="group" aria-label="', t('docs.graph.aria'), '">';
    echo '<defs><marker id="doc-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#707070"/></marker></defs>';
    echo '<g class="hierarchy-edges">';
    foreach ($pages as $id => $page) {
        $parent = (int) $page['parent_id'];
        if (!isset($positions[$parent])) continue;
        [$x1, $y1] = $positions[$parent];
        [$x2, $y2] = $positions[$id];
        echo '<line x1="', $x1, '" y1="', $y1, '" x2="', $x2, '" y2="', $y2, '"/>';
    }
    echo '</g><g class="document-edges">';
    foreach ($links as $link) {
        $source = (int) $link['source_page_id'];
        $target = (int) $link['target_page_id'];
        if (!isset($positions[$source], $positions[$target])) continue;
        [$x1, $y1] = $positions[$source];
        [$x2, $y2] = $positions[$target];
        if ($lite) { $edgeSegments[] = "M $x1 $y1 L $x2 $y2"; continue; }
        $distance = max(1, hypot($x2 - $x1, $y2 - $y1));
        $ux = ($x2 - $x1) / $distance;
        $uy = ($y2 - $y1) / $distance;
        $sx = $x1 + $ux * 22; $sy = $y1 + $uy * 22;
        $tx = $x2 - $ux * 27; $ty = $y2 - $uy * 27;
        $mx = ($x1 + $x2) / 2 - $uy * 35;
        $my = ($y1 + $y2) / 2 + $ux * 35;
        $path = $source === $target
            ? "M $x1 " . ($y1 - 20) . ' c -65 -85 65 -85 8 0'
            : "M $sx $sy Q $mx $my $tx $ty";
        echo '<path class="doc-edge" data-source="', $source, '" data-target="', $target, '" data-link-id="', (int) $link['id'], '" d="', $path, '" marker-end="url(#doc-arrow)"><title>', docEscape($pages[$source]['title'] . ' → ' . $pages[$target]['title']), '</title></path>';
    }
    if ($lite) echo '<path class="overview-edges" d="', implode(' ', $edgeSegments), '"/>';
    echo '</g><g class="document-nodes">';
    foreach ($pages as $id => $page) {
        [$x, $y] = $positions[$id];
        $title = preg_replace('/^\d+\.\s*/u', '', $page['title']);
        $words = preg_split('/\s+/u', $title);
        $lines = [''];
        foreach ($words as $word) {
            $last = count($lines) - 1;
            if ($lines[$last] !== '' && mb_strlen($lines[$last] . ' ' . $word) > 27) $lines[] = $word;
            else $lines[$last] .= ($lines[$last] === '' ? '' : ' ') . $word;
        }
        $classes = 'graph-node' . ($searching && isset($matches[$id]) ? ' search-match' : '') . ($searching && !isset($matches[$id]) ? ' search-other' : '');
        echo '<a class="', $classes, '" data-page-id="', (int) $id, '" href="', docEscape(docUrl($page['slug'])), '" aria-label="', docEscape($page['title']), '"><title>', docEscape($page['title']), '</title><circle cx="', $x, '" cy="', $y, '" r="19"/><text x="', $x, '" y="', $y + 43, '" text-anchor="middle">';
        foreach ($lines as $i => $line) echo '<tspan x="', $x, '" dy="', $i === 0 ? 0 : 19, '">', docEscape($line), '</tspan>';
        echo '</text></a>';
    }
    echo '</g></svg></div>';
}

/** Фильтр раздела включает всех потомков, окружение — обе стороны одного перехода. */
function docGraphSubset(array $pages,array $links,?int $focus,string $section,string $scope):array
{
    $allowed=array_fill_keys(array_keys($pages),true);
    if($section!==''){
        $allowed=[];$root=null;foreach($pages as $id=>$p)if($p['slug']===$section)$root=$id;
        if($root!==null){$allowed[$root]=true;do{$changed=false;foreach($pages as $id=>$p){
            if(!isset($allowed[$id])&&isset($allowed[(int)$p['parent_id']])){$allowed[$id]=true;$changed=true;}
        }}while($changed);}
    }
    if($scope==='neighbors'){
        $near=[];if($focus && isset($pages[$focus])){
            $near[$focus]=true;
            foreach($links as $l){$a=(int)$l['source_page_id'];$b=(int)$l['target_page_id'];if($a===$focus||$b===$focus){$near[$a]=true;$near[$b]=true;}}
        }
        $allowed=array_intersect_key($allowed,$near);
        if($focus && isset($pages[$focus]))$allowed[$focus]=true;
    }
    $subset=array_intersect_key($pages,$allowed);
    $edges=array_filter($links,static fn($l)=>isset($subset[(int)$l['source_page_id']],$subset[(int)$l['target_page_id']]));
    return [$subset,$edges];
}

function docGraphControls(array $pages,string $section,string $scope,string $focus,string $query,string $tag):void
{
    echo '<form method="get" class="graph-filters"><input type="hidden" name="view" value="graph">';
    foreach(['q'=>$query,'tag'=>$tag] as $key=>$value)if($value!=='')echo '<input type="hidden" name="',$key,'" value="',docEscape($value),'">';
    echo '<label>', t('docs.graph.section'), '<select name="section"><option value="">', t('docs.graph.all_sections'), '</option>';
    foreach($pages as $p)if($p['parent_id']===null)echo '<option value="',docEscape($p['slug']),'"', $section===$p['slug']?' selected':'','>',docEscape($p['title']),'</option>';
    echo '</select></label><label>', t('docs.graph.scope'), '<select name="scope"><option value="all"', $scope==='all'?' selected':'','>', t('docs.graph.scope_all'), '</option><option value="neighbors"', $scope==='neighbors'?' selected':'','>', t('docs.graph.scope_neighbors'), '</option></select></label>';
    echo '<label>', t('docs.graph.article'), '<select name="focus"><option value="">', t('docs.graph.pick_article'), '</option>';
    $sorted=array_values($pages);usort($sorted,static fn($a,$b)=>strcmp($a['title'],$b['title']));
    foreach($sorted as $p)echo '<option value="',docEscape($p['slug']),'"',$focus===$p['slug']?' selected':'','>',docEscape($p['title']),' · ',docEscape($p['slug']),'</option>';
    echo '</select></label><button type="submit">', t('docs.graph.show'), '</button></form>';
    if($scope==='neighbors'&&$focus==='')echo '<p class="empty">', t('docs.graph.pick_hint'), '</p>';
}
