<?php
declare(strict_types=1);
/** Native WordPress ZIP installer on a second isolated container without a theme bind mount. */
if(PHP_SAPI!=='cli'||getenv('AISH_PHASE12_QA')!=='1')exit(1);
$_SERVER['HTTP_HOST']='127.0.0.1:18183';define('FS_METHOD','direct');
require '/var/www/html/wp-load.php';
if(DB_NAME!=='qa_operations84'||get_option('aish_schema_version')!=='1.9.0')exit(2);
require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/misc.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user(1);$cases=[];$checks=[];
function p12_package_fingerprint(): array {global $wpdb;$r=[];foreach($wpdb->get_col("SHOW TABLES LIKE 'wp_aish_%'") as $table){$rows=$wpdb->get_results("SELECT * FROM `$table`",ARRAY_A);if($wpdb->last_error)throw new RuntimeException('fingerprint_failed');$hashes=array_map(static fn($row)=>hash('sha256',wp_json_encode($row)),$rows);sort($hashes);$r[$table]=hash('sha256',implode('',$hashes));}foreach(['posts'=>"SELECT * FROM {$wpdb->posts} WHERE post_type='aish_message'",'meta'=>"SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='aish_message'"] as $k=>$sql){$rows=$wpdb->get_results($sql,ARRAY_A);$hashes=array_map(static fn($row)=>hash('sha256',wp_json_encode($row)),$rows);sort($hashes);$r['inbox_'.$k]=hash('sha256',implode('',$hashes));}return $r;}
$before=p12_package_fingerprint();$package='/repo/release/ai-skills-hub-0.12.0.zip';
try {
 ob_start();$installer=new Theme_Upgrader(new Automatic_Upgrader_Skin());$result=$installer->install('/repo/release/ai-skills-hub-0.11.0.zip',['overwrite_package'=>true]);ob_end_clean();$checks['native_phase11_zip_install']=true===$result;if(is_wp_error($result))throw new RuntimeException($result->get_error_code());
 ob_start();$installer=new Theme_Upgrader(new Automatic_Upgrader_Skin());$result=$installer->install($package,['overwrite_package'=>true]);ob_end_clean();$checks['native_phase12_zip_upgrade']=true===$result;if(is_wp_error($result))throw new RuntimeException($result->get_error_code());
 wp_clean_themes_cache(true);$theme=wp_get_theme('ai-skills-hub');$checks['theme_package_version']='0.12.0'===$theme->get('Version');
 $zip=new ZipArchive();if(true!==$zip->open($package))throw new RuntimeException('zip_unavailable');$files=0;$matches=true;
 for($i=0;$i<$zip->numFiles;++$i){$name=$zip->getNameIndex($i);if(str_ends_with($name,'/'))continue;if(!str_starts_with($name,'ai-skills-hub/'))throw new RuntimeException('package_root');$file=get_theme_root().'/'.$name;if(!is_file($file)||!hash_equals(hash('sha256',$zip->getFromIndex($i)),hash_file('sha256',$file)))$matches=false;++$files;}$zip->close();$checks['installed_file_hashes_match_package']=$matches;$checks['all_33_core_tables_and_private_inbox_unchanged']=$before===p12_package_fingerprint();$checks['schema_1_9_retained']='1.9.0'===get_option('aish_schema_version');
}catch(Throwable $e){while(ob_get_level())ob_end_clean();$checks['unexpected_error']=false;$reason=$e instanceof RuntimeException?$e->getMessage():get_class($e);}
$failed=count(array_filter($checks,static fn($v)=>!$v));echo wp_json_encode(['kind'=>'Real native WordPress Theme_Upgrader baseline ZIP installation then Phase12 overwrite upgrade; isolated volume only','wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->get_var('SELECT VERSION()'),'package_sha256'=>hash_file('sha256',$package),'files'=>$files??0,'checks'=>$checks,'reason'=>$reason??null,'passed'=>count($checks)-$failed,'failed'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($failed?1:0);
