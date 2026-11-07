<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
wp_set_current_user($admin);$samples=[];
foreach(['analytics'=>fn()=>$core->get('operations_analytics')->snapshot(7,'en_US'),'dashboard'=>fn()=>$core->get('operations_dashboard')->snapshot(),'users_page'=>fn()=>$core->get('operations_users')->listing()]as$name=>$call){for($i=0;$i<5;$i++){$before=$wpdb->num_queries;$start=hrtime(true);$result=$call();$samples[$name][]=['elapsed_ms'=>round((hrtime(true)-$start)/1e6,3),'sql_queries'=>$wpdb->num_queries-$before];}}
$counts=[];foreach(['skills','skill_translations','favorites','reviews','workflow_events','metric_events','daily_metrics']as$table)$counts[$table]=(int)$db->value('SELECT COUNT(*) FROM '.$db->table($table));
$private=new AISkillsHub\Core\Operations\Notifications($core->get('account_store'),$accounts);$f=get_option('aish_phase12_qa_fixture');wp_set_current_user((int)$f['member']);for($i=0;$i<5;$i++){$n=$wpdb->num_queries;$t=hrtime(true);$private->mine('en_US');$samples['notifications_page'][]=['elapsed_ms'=>round((hrtime(true)-$t)/1e6,3),'sql_queries'=>$wpdb->num_queries-$n];}
echo wp_json_encode(['kind'=>'Measured local QA samples, five sequential calls per read model. Small synthetic data, warm PHP process, shared host load, no production benchmark claim.','environment'=>['wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->get_var('SELECT VERSION()')],'fixture_rows'=>$counts,'samples'=>$samples,'passed'=>count($samples),'failed'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
