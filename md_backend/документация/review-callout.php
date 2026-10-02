<?php
/* Явное решение пользователя: --id=N --decision=approved|rejected|pending --by=Имя. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/lib/core/config.php';require_once dirname(__DIR__,2).'/lib/core/db.php';require_once __DIR__.'/links.php';
$a=getopt('',['id:','decision:','by:']);$id=filter_var($a['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$decision=$a['decision']??'';$by=trim($a['by']??'');
if(!$id||!in_array($decision,['pending','approved','rejected'],true)||$by==='')throw new InvalidArgumentException('Нужны --id, --decision, --by');
dbTransaction(static function()use($id,$decision,$by):void{docPublicationLock();
    if(!dbValue('SELECT id FROM documentation_callouts WHERE id=?',[$id]))throw new RuntimeException('Врезка не найдена');
    dbRun('UPDATE documentation_callouts SET approval=?,reviewed_by=?,reviewed_at=NOW(3) WHERE id=?',[$decision,$by,$id]);
});
echo "Решение сохранено.\n";
