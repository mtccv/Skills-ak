<?php
declare(strict_types=1);
/** Run before mutable operations fixtures, against the recorded Phase 11 snapshot. */
if(PHP_SAPI!=='cli'||getenv('AISH_PHASE12_QA')!=='1')exit(1);
require '/var/www/html/wp-load.php';
if(!in_array(DB_NAME,['qa_discovery','qa_operations84'],true))exit(2);
$baseline=json_decode((string)file_get_contents(__DIR__.'/results/baseline-php'.(PHP_VERSION_ID>=80400?'84':'83').'.json'),true,512,JSON_THROW_ON_ERROR);
$core=AISkillsHub\Core\Core::boot();$checks=[];$checks['baseline_schema_1_9_33_tables']=$baseline['schema']==='1.9.0'&&count($baseline['tables'])===33;
try{
 $checks['repeat_current_migration']=true===$core->migrator()->migrate('1.9.0')&&true===$core->migrator()->migrate('1.9.0');
 AISkillsHub\Core\Database\Schema::verify($core->get('database'),'1.9.0');$checks['physical_schema']=true;
 $original=get_option('stylesheet');$fallback=null;foreach(wp_get_themes() as $slug=>$theme){if($slug!==$original&&!$theme->errors()){$fallback=$slug;break;}}
 if(!$fallback)throw new RuntimeException('fallback_theme_missing');
 switch_theme($fallback);$checks['theme_deactivation']=get_option('stylesheet')===$fallback;switch_theme($original);$checks['theme_reactivation']=get_option('stylesheet')===$original;
 ob_start();require __DIR__.'/baseline.php';$after=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
 foreach($baseline['tables'] as $name=>$data){$checks['data_'.$name]=$data['count']===$after['tables'][$name]['count']&&$data['sha256']===$after['tables'][$name]['sha256'];$checks['structure_'.$name]=$data['structure_sha256']===$after['tables'][$name]['structure_sha256'];}
 foreach($baseline['native'] as $name=>$data){$checks['native_'.$name]=$data===$after['native'][$name];}
 $checks['exactly_33_tables']=count($wpdb->get_col("SHOW TABLES LIKE 'wp\\_aish\\_%'"))===33;
 $checks['schema_unchanged']=get_option('aish_schema_version')==='1.9.0';
}catch(Throwable $e){while(ob_get_level())ob_end_clean();$checks['unexpected_error']=false;$reason=$e instanceof RuntimeException?$e->getMessage():get_class($e);}
$failed=count(array_filter($checks,static fn($p)=>!$p));echo wp_json_encode(['kind'=>'Real Phase11 to Phase12 code activation, repeated current migration, theme switch/reactivation and full retained-data fingerprints. No schema upgrade or reset.','environment'=>['wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->get_var('SELECT VERSION()')],'original_core_rows'=>array_sum(array_column($baseline['tables'],'count')),'checks'=>$checks,'reason'=>$reason??null,'passed'=>count($checks)-$failed,'failed'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($failed?1:0);
