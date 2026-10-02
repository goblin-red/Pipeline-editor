<?php
/* Установка документации; существующие врезки не перезаписываются. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/lib/core/config.php';require_once dirname(__DIR__,2).'/lib/core/db.php';
foreach(['014-documentation.sql','015-documentation-link-types.sql','016-documentation-callouts.sql','017-documentation-workflow.sql'] as $name){
 if(dbValue('SELECT name FROM schema_migrations WHERE name=?',[$name]))continue;
 $started=microtime(true);db()->exec(file_get_contents(dirname(__DIR__,2).'/sql/migrations/'.$name));
 dbRun('INSERT INTO schema_migrations(name,took_ms) VALUES(?,?)',[$name,(int)((microtime(true)-$started)*1000)]);
}
require __DIR__.'/publish.php';require_once __DIR__.'/callouts.php';
foreach([
 ['example-future-plan','future_plan','<p>Пример идеи: сохранять личное расположение панелей.</p><p>Это предложение для обсуждения, не реализованная функция.</p>'],
 ['example-agent-correction','agent_correction','<p>Пример уточнения: расположение панели и её назначение описываются отдельно.</p><p>Это пример оформления исправления агента; он требует утверждения.</p>']
] as [$key,$kind,$html]){
 if(!dbValue('SELECT c.id FROM documentation_callouts c JOIN documentation_pages p ON p.id=c.page_id WHERE p.slug=? AND c.entry_key=?',['frontend',$key]))docSaveCallout('frontend',$key,$kind,$html,'Пример для просмотра');
}
echo "Документация установлена.\n";
