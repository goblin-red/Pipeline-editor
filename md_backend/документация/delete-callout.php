<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/lib/core/config.php';require_once dirname(__DIR__,2).'/lib/core/db.php';require_once __DIR__.'/links.php';
$a=getopt('',['id:']);$id=filter_var($a['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id)throw new InvalidArgumentException('Нужен --id');
dbTransaction(static function()use($id):void{docPublicationLock();dbRun('DELETE FROM documentation_callouts WHERE id=?',[$id]);docSyncLinks();});
echo "Врезка удалена, связи обновлены.\n";
