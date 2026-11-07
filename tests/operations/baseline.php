<?php
declare(strict_types=1);
/** Read-only current-schema preservation fingerprint; isolated QA DBs only. */
if ( PHP_SAPI !== 'cli' || getenv('AISH_PHASE12_QA') !== '1' ) { exit(1); }
require_once '/var/www/html/wp-load.php';
if (!in_array(DB_NAME,['qa_discovery','qa_operations84'],true) || get_option('aish_schema_version')!=='1.9.0') { exit(2); }
function p12_fingerprint(string $sql):array {
 global $wpdb;
 $rows=$wpdb->get_results($sql,ARRAY_A);
 if($wpdb->last_error || !is_array($rows)){throw new RuntimeException('qa_fingerprint_unavailable');}
 $hashes=array_map(static fn(array $r):string=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR)),$rows);sort($hashes);
 return ['count'=>count($rows),'sha256'=>hash('sha256',implode('',$hashes))];
}
$entities=array_keys(AISkillsHub\Core\Database\Schema::COLUMNS);
if(count($entities)!==33){throw new RuntimeException('expected_33_tables');}
$data=['kind'=>'Real WordPress/MySQL read-only preservation baseline; no raw user records','schema'=>get_option('aish_schema_version'),'environment'=>['wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'mysql'=>$wpdb->get_var('SELECT VERSION()')],'tables'=>[],'native'=>[]];
foreach($entities as $entity){$table=$wpdb->prefix.'aish_'.$entity;$data['tables'][$entity]=p12_fingerprint("SELECT * FROM `$table`");$ddl=$wpdb->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);if(!is_array($ddl)||!isset($ddl[1])||$wpdb->last_error){throw new RuntimeException('qa_structure_unavailable');}$data['tables'][$entity]['structure_sha256']=hash('sha256',preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl[1]));}
$data['native']['users']=p12_fingerprint("SELECT * FROM {$wpdb->users}");
$data['native']['usermeta']=p12_fingerprint("SELECT * FROM {$wpdb->usermeta}");
$data['native']['inbox']=p12_fingerprint("SELECT * FROM {$wpdb->posts} WHERE post_type='aish_message'");
$data['native']['inbox_meta']=p12_fingerprint("SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='aish_message'");
$data['native']['revisions']=p12_fingerprint("SELECT r.* FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} p ON p.ID=r.post_parent WHERE p.post_type='aish_message' AND r.post_type='revision'");
$data['native']['revision_meta']=p12_fingerprint("SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} r ON r.ID=m.post_id INNER JOIN {$wpdb->posts} p ON p.ID=r.post_parent WHERE p.post_type='aish_message' AND r.post_type='revision'");
echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
