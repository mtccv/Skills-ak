<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use AISkillsHub\Core\Discovery\Views;
if(!defined('WP_CLI'))define('WP_CLI',true);
$views=new Views($db,$core->migrator(),$core->get('public_catalog'));
$eventTable=$db->table('metric_events');
// Finish any expired synthetic backlog from an interrupted QA attempt before making fresh fixtures.
for($i=0;$i<20 && (int)$db->value("SELECT COUNT(*) FROM $eventTable WHERE metric_name='skill_view' AND expires_at<%s",[gmdate('Y-m-d H:i:s')])>0;++$i){$views->cleanup();}
wp_clear_scheduled_hook('aish_discovery_retention');$options=$wpdb->options;$old=gmdate('Y-m-d H:i:s',time()-20*DAY_IN_SECONDS);$past=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS);$future=gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS);$ids=[];$keys=[];
foreach(range(1,201) as $i){$hash=hash('sha256',$run.':'.$i);$keys[]=$hash;$ids[]=$db->insert('metric_events',['metric_name'=>'skill_view','dimension_type'=>'skill','dimension_key'=>'1','locale'=>'','event_day'=>substr($old,0,10),'dedupe_key'=>$hash,'policy_version'=>Views::POLICY,'occurred_at'=>$old,'expires_at'=>$past]);$db->execute("INSERT INTO $options(option_name,option_value,autoload) VALUES (%s,%s,'no'),(%s,%s,'no')",['_transient_timeout_aish_view_budget_'.$hash,(string)(time()-3600),'_transient_aish_view_budget_'.$hash,'1']);}
$healthy=$db->insert('metric_events',['metric_name'=>'skill_view','dimension_type'=>'skill','dimension_key'=>'1','locale'=>'','event_day'=>gmdate('Y-m-d'),'dedupe_key'=>hash('sha256',$run.':healthy'),'policy_version'=>Views::POLICY,'occurred_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>$future]);
$phase9=$db->insert('metric_events',['metric_name'=>'favorite_add','dimension_type'=>'skill','dimension_key'=>'1','locale'=>'','event_day'=>substr($old,0,10),'dedupe_key'=>hash('sha256',$run.':phase9'),'policy_version'=>'community-1','occurred_at'=>$old,'expires_at'=>$past]);
$count=fn()=>(int)$db->value("SELECT COUNT(*) FROM $eventTable WHERE id IN (".implode(',',$ids).')');
$budgets=fn()=>(int)$db->value("SELECT COUNT(*) FROM $options WHERE option_name IN (".implode(',',array_fill(0,count($keys),'%s')).')',array_map(static fn($k)=>'_transient_timeout_aish_view_budget_'.$k,$keys));
p11_case('bounded_backlog_retention_continuation',function()use($views,$count,$budgets){$views->cleanup();p11_assert($count()===101&&$budgets()===101,'First batch100 event/budget pairs');p11_assert(false!==wp_next_scheduled('aish_discovery_retention'),'Catch-up scheduled');$views->cleanup();p11_assert($count()===1&&$budgets()===1,'Second batch bounded100');$views->cleanup();p11_assert($count()===0&&$budgets()===0,'Third batch clears backlog');});
p11_case('retention_preserves_healthy_and_other_module_events',function()use($db,$healthy,$phase9,$eventTable){p11_assert((int)$db->value("SELECT COUNT(*) FROM $eventTable WHERE id IN (%d,%d)",[$healthy,$phase9])===2,'Healthy view and expired Phase9 fact untouched');});
p11_report('Real WordPress/MySQL bounded discovery-retention cleanup; synthetic expired data only');
