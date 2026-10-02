<?php
declare(strict_types=1);

/** Стабильный ID направленной связи. Все вызовы — внутри транзакции публикации. */
function docEnsureLink(int $source, int $target, string $type = 'related'): int
{
    dbRun('INSERT INTO documentation_links (source_page_id,target_page_id,resource_type) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE resource_type=VALUES(resource_type)', [$source,$target,$type]);
    return (int) dbValue('SELECT id FROM documentation_links WHERE source_page_id=? AND target_page_id=?', [$source,$target]);
}

/** Маркеры в примерах code/pre не создают связей. Числовые ID проверяются по владельцу. */
function docCompileLinks(string $html, int $source, array $ids, array $types = []): string
{
    return (string) preg_replace_callback('~(?i:<(code|pre)\b[^>]*>.*?</\1\s*>)|\[\[([a-z0-9][a-z0-9_-]*)\|([^\]\r\n]+)\]\]~su',
        static function(array $m) use($source,$ids,$types): string {
            if (($m[1] ?? '') !== '') return $m[0];
            $key=$m[2];
            if (ctype_digit($key)) {
                if (!dbValue('SELECT id FROM documentation_links WHERE id=? AND source_page_id=?',[(int)$key,$source])) {
                    throw new RuntimeException('Ссылка не принадлежит статье: '.$key);
                }
                return $m[0];
            }
            if (!isset($ids[$key])) throw new RuntimeException('Не найдена статья: '.$key);
            return '[['.docEnsureLink($source,$ids[$key],$types[$key]??'related').'|'.$m[3].']]';
        }, $html);
}

/** Пересобрать связи по действующим статьям, техническим ссылкам и врезкам.
 * Не удаляет статьи. Сохраняет ID всех связей, которые ещё используются.
 * Вызывать внутри транзакции, захватив docPublicationLock().
 */
function docSyncLinks(): void
{
    // Адреса статей уникальны внутри языка: ссылки и врезки ищут цель в языке своей статьи.
    $pages=dbAll('SELECT id,lang,slug,summary_html,body_html,references_json FROM documentation_pages');
    $ids=[];$langOf=[];
    foreach($pages as $p){$ids[$p['lang']][$p['slug']]=(int)$p['id'];$langOf[(int)$p['id']]=$p['lang'];}
    $links=array_column(dbAll('SELECT * FROM documentation_links'),null,'id');
    $wanted=[];
    $mark=static function(int $from,int $to) use(&$wanted): void { $wanted[$from.':'.$to]=true; };
    $scan=static function(string $html,int $from)use($links,$mark):void {
        $html=preg_replace('~<(code|pre)\b[^>]*>.*?</\1\s*>~isu','',$html);
        preg_match_all('/\[\[([0-9]+)\|[^\]\r\n]+\]\]/u',$html,$matches);
        foreach($matches[1] as $id){$link=$links[(int)$id]??null;
            if(!$link || (int)$link['source_page_id']!==$from) throw new RuntimeException('Неверная связь в статье/врезке: '.$id);
            $mark($from,(int)$link['target_page_id']);
        }
    };
    foreach($pages as $p){$id=(int)$p['id'];$scan($p['summary_html'].' '.$p['body_html'],$id);
        if($p['references_json']===null){foreach($links as $l)if((int)$l['source_page_id']===$id)$mark($id,(int)$l['target_page_id']);continue;}
        foreach(json_decode($p['references_json'],true,512,JSON_THROW_ON_ERROR) as $slug){
            if(!isset($ids[$p['lang']][$slug]))throw new RuntimeException('Не найдена техническая ссылка: '.$slug);
            $mark($id,$ids[$p['lang']][$slug]);
        }
    }
    foreach(dbAll('SELECT * FROM documentation_callouts') as $c){$from=(int)$c['page_id'];$scan($c['body_html'],$from);
        $list=$ids[$langOf[$from]??'ru']['future-list']??null;
        if($c['kind']==='future_plan' && $list && $from!==$list){
            $mark($from,$list);$mark($list,$from);
        }
    }
    $existing=[];
    foreach($links as $l){$key=$l['source_page_id'].':'.$l['target_page_id'];$existing[$key]=true;
        if(!isset($wanted[$key])) dbRun('DELETE FROM documentation_links WHERE id=?',[$l['id']]);
    }
    foreach($wanted as $key=>$_)if(!isset($existing[$key])){[$from,$to]=array_map('intval',explode(':',$key));docEnsureLink($from,$to);}
}

function docPublicationLock(): void
{
    // Общий порядок для издателя и врезок; сохраняет целостность графа при параллельной записи.
    dbAll('SELECT id FROM documentation_pages ORDER BY id FOR UPDATE');
}
