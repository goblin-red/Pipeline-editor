<?php
/* Единственный издатель: <язык>/content.json -> MariaDB. Без аргументов публикует всё по-русски;
   --lang=en — английскую версию (en/content.json), --page=slug — одну статью. Запуск только из CLI. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__,2).'/lib/core/config.php';
require_once dirname(__DIR__,2).'/lib/core/db.php';
require_once __DIR__.'/links.php';
$args=getopt('',['page:','lang:']);$lang=$args['lang']??'ru';
if(!in_array($lang,['ru','en'],true))throw new RuntimeException('Язык — ru или en');
$document=json_decode(file_get_contents(__DIR__.'/'.$lang.'/content.json'),true,512,JSON_THROW_ON_ERROR);
$all=$document['pages'];$selected=$args['page']??null;
$pages=$selected===null?$all:array_values(array_filter($all,static fn($p)=>$p['slug']===$selected));
if(!$pages)throw new RuntimeException('Статья не найдена в content.json');
$result=dbTransaction(static function()use($pages,$all,$lang):array{
    docPublicationLock();
    $ids=array_map('intval',array_column(dbAll('SELECT id,slug FROM documentation_pages WHERE lang=?',[$lang]),'id','slug'));
    $types=[];foreach($all as $p)$types[$p['slug']]=$p['resource_type']??'related';
    foreach($pages as $p){if(!preg_match('/^[a-z][a-z0-9_-]*$/D',$p['slug']))throw new RuntimeException('Неверный slug');
        if(isset($ids[$p['slug']]))continue;
        dbRun('INSERT INTO documentation_pages(lang,slug,title,summary_html,body_html) VALUES(?,?,?,?,?)',[$lang,$p['slug'],$p['title'],'','']);$ids[$p['slug']]=dbId();
    }
    foreach($pages as $p){$id=$ids[$p['slug']];$parent=$p['parent'];
        if($parent!==null && !isset($ids[$parent]))throw new RuntimeException('Не найден родитель: '.$parent);
        $status=$p['status']??null;if($status!==null&&!in_array($status,['proposed','approved','implemented'],true))throw new RuntimeException('Неизвестный статус');
        $summary=docCompileLinks($p['summary_html'],$id,$ids,$types);$body=docCompileLinks($p['body_html'],$id,$ids,$types);
        foreach($p['references'] as $slug){if(!isset($ids[$slug]))throw new RuntimeException('Не найдена ссылка: '.$slug);docEnsureLink($id,$ids[$slug],$types[$slug]??'related');}
        dbRun('UPDATE documentation_pages SET parent_id=?,title=?,summary_html=?,body_html=?,keywords=?,sort_order=?,status=?,reviewed_at=?,source_note=?,references_json=? WHERE id=?',[
            $parent===null?null:$ids[$parent],$p['title'],$summary,$body,json_encode($p['tags'],JSON_UNESCAPED_UNICODE),$p['order'],$status,$p['reviewed_at']??null,$p['source_note']??null,json_encode($p['references'],JSON_UNESCAPED_UNICODE),$id]);
    }
    docSyncLinks();return ['lang'=>$lang,'published'=>count($pages)];
});
echo json_encode($result,JSON_UNESCAPED_UNICODE)."\n";
