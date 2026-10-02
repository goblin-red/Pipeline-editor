<?php
declare(strict_types=1);
require_once __DIR__.'/links.php';

/** Авторская запись только из CLI. Изменённая врезка требует нового утверждения. Врезки — к русским статьям. */
function docSaveCallout(string $slug,string $key,string $kind,string $html,string $author='',int $order=0):int
{
    if(!in_array($kind,['future_plan','agent_correction'],true))throw new InvalidArgumentException('Неизвестный тип врезки');
    if(!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$key))throw new InvalidArgumentException('Нужен постоянный ключ врезки');
    if(trim(strip_tags($html))==='')throw new InvalidArgumentException('Врезка не должна быть пустой');
    return dbTransaction(static function()use($slug,$key,$kind,$html,$author,$order):int{
        docPublicationLock();$ids=array_map('intval',array_column(dbAll("SELECT id,slug FROM documentation_pages WHERE lang='ru'"),'id','slug'));
        if(!isset($ids[$slug]))throw new RuntimeException('Статья не найдена: '.$slug);$id=$ids[$slug];
        $html=docCompileLinks($html,$id,$ids);
        $old=dbRow('SELECT * FROM documentation_callouts WHERE page_id=? AND entry_key=?',[$id,$key]);
        $changed=!$old||$old['kind']!==$kind||$old['body_html']!==$html||$old['author_name']!==$author;
        dbRun('INSERT INTO documentation_callouts(page_id,entry_key,kind,body_html,author_name,sort_order) VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE kind=VALUES(kind),body_html=VALUES(body_html),author_name=VALUES(author_name),sort_order=VALUES(sort_order)',[$id,$key,$kind,$html,$author,$order]);
        $callout=(int)dbValue('SELECT id FROM documentation_callouts WHERE page_id=? AND entry_key=?',[$id,$key]);
        if($changed)dbRun("UPDATE documentation_callouts SET approval='pending',reviewed_by='',reviewed_at=NULL WHERE id=?",[$callout]);
        docSyncLinks();return $callout;
    });
}
