<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use AISkillsHub\Core\Learning\{Help,HelpContent};
use AISkillsHub\Core\Frontend\Editorial;
$editorial = new Editorial($core->get('public_catalog'),$db);
$help = new Help($db,$core->migrator(),$editorial);
$member = p14_user('help-reader'); $import = [];
$native = $db->connection();
$old = $db->rows("SELECT ID,post_title,post_content,post_status FROM $native->posts WHERE post_type IN ('aish_tutorial','page') ORDER BY ID");
if(!defined('P14_HELP_READ_REGRESSION')){
p14_case('help_uses_native_tutorial_storage_and_explicit_manager_import', function() use($member,$help,$db,$native) {
 wp_set_current_user($member); p14_reject(fn()=>$help->importDrafts(),'Subscriber cannot install editorial drafts'); p14_reject(fn()=>$help->inventory(),'Subscriber cannot read draft inventory');
 p14_assert(count($db->connection()->get_col("SHOW TABLES LIKE 'wp\\_aish\\_%'"))===33,'No second content storage or Core table');
 p14_assert(count(HelpContent::articles())===21,'Twelve learning topics plus nine guideline topics');
});
p14_case('import_failure_rolls_back_native_posts_and_metadata', function() use($admin,$help,$native,$db) {
 wp_set_current_user($admin); $before=(int)$db->value("SELECT COUNT(*) FROM $native->posts"); $beforeMeta=(int)$db->value("SELECT COUNT(*) FROM $native->postmeta"); $inserts=0;
 $fail=static function(string $sql) use($native,&$inserts):string { if(preg_match('/^INSERT INTO `?'.preg_quote($native->posts,'/').'`? /i',$sql) && ++$inserts===2) return 'INSERT INTO aish_phase14_deliberately_missing_table (id) VALUES (1)'; return $sql; };
 add_filter('query',$fail,PHP_INT_MAX); try { p14_reject(fn()=>$help->importDrafts(),'Fault injected second native post insert rolls back'); } finally { remove_filter('query',$fail,PHP_INT_MAX); }
 p14_assert($inserts===2,'Actual native second insert reached fault'); p14_assert((int)$db->value("SELECT COUNT(*) FROM $native->posts")===$before && (int)$db->value("SELECT COUNT(*) FROM $native->postmeta")===$beforeMeta,'No partial draft or metadata remains after rollback');
});
p14_case('explicit_bilingual_drafts_are_atomic_reciprocal_and_not_public', function() use($admin,$help,$editorial,&$import,$old,$native,$db) {
 wp_set_current_user($admin); $import=$help->importDrafts(); p14_assert(count($import['created'])===42,'Explicit importer creates exactly 42 authored draft records');
 foreach($import['created'] as $id) { $p=get_post($id); $other=(int)get_post_meta($id,'_aish_translation_id',true); p14_assert($p->post_type==='aish_tutorial' && $p->post_status==='draft' && strlen($p->post_content)>100,'Real native draft body exists'); p14_assert((int)get_post_meta($other,'_aish_translation_id',true)===$id,'Independent translations reciprocal'); p14_assert($editorial->tutorial($id,get_post_meta($id,'_aish_locale',true))===null,'Draft is not publicly readable'); }
 foreach(['zh_CN','en_US'] as $locale) { p14_assert($help->listing($locale)['total']===0 && $help->topicLinks($locale)===[],'Unpublished learning copy is absent from public help'); }
 foreach($old as $row) p14_assert($db->row("SELECT ID,post_title,post_content,post_status FROM $native->posts WHERE ID=%d",[(int)$row['ID']])===$row,'Existing native page/tutorial untouched');
});
p14_case('repeat_import_never_overwrites_editorial_changes', function() use($help,$editorial,&$import,$run) {
 $id=$import['created'][0]; $marker='Human edited QA title '.$run; wp_update_post(['ID'=>$id,'post_title'=>$marker]); $before=get_post($id)->post_content;
 $again=$help->importDrafts(); p14_assert($again['created']===[] && count($again['existing'])===42,'Repeat import retains same records'); p14_assert(get_post($id)->post_title===$marker && get_post($id)->post_content===$before,'Human title and body preserved');
});
}else{wp_set_current_user($admin);$import=['created'=>array_map('intval',array_column($help->inventory(),'ID'))];}
p14_case('public_help_bilingual_category_search_pagination_and_cache', function() use($help,$editorial,&$import,$wpdb) {
 foreach($import['created'] as $id) wp_update_post(['ID'=>$id,'post_status'=>'publish']); $editorial->clearCache();
 foreach(['zh_CN','en_US'] as $locale) {
  $one=$help->listing($locale,['page'=>1]); $two=$help->listing($locale,['page'=>2]); p14_assert($one['total']===21 && count($one['items'])===12 && count($two['items'])===9,'21 actual published articles split12/9');
  p14_assert(!array_intersect(array_column($one['items'],'id'),array_column($two['items'],'id')),'Pagination no duplicate article'); foreach(array_merge($one['items'],$two['items']) as $item) p14_assert($item['locale']===$locale,'No invented fallback translation');
  $category=$help->listing($locale,['help_category'=>'policies']); p14_assert($category['total']===9,'Guidelines are independently filterable');
  $q=$help->listing($locale,['q'=>'Codex']); p14_assert($q['total']>=1 && $q['total']<21,'Literal bilingual keyword search finds real content');
  p14_assert($help->listing($locale,['q'=>"%_' OR 1=1 --"] )['total']===0,'SQL metacharacters treated as literal search');
  $queries=$wpdb->num_queries; $repeat=$help->listing($locale,['page'=>1]); p14_assert($repeat===$one && $wpdb->num_queries===$queries,'Repeated identical read uses request cache without N+1');
 }
 foreach([['page'=>0],['per_page'=>100],['help_category'=>'unknown'],['q'=>['bad']],['owner'=>1]] as $f) p14_reject(fn()=>$help->listing('en_US',$f),'Invalid help filter safely rejected');
});
p14_case('unpublished_password_and_missing_translation_never_leak', function() use($help,$editorial,&$import) {
 $zh=$import['created'][0]; $en=(int)get_post_meta($zh,'_aish_translation_id',true); $locale=get_post_meta($zh,'_aish_locale',true); p14_assert($locale==='zh_CN','Known first authored record Chinese');
 p14_assert(count($editorial->tutorialAlternatives($zh))===2,'Published bilingual pair has exactly two real alternatives');
 wp_update_post(['ID'=>$en,'post_status'=>'draft']); $editorial->clearCache(); p14_assert($editorial->tutorialAlternatives($zh)===['zh_CN'=>$zh],'Missing English publication does not invent alternate'); p14_assert($help->listing('en_US')['total']===20,'Draft removed from list immediately');
 p14_assert($help->reading(['id'=>$en,'content'=>'PRIVATE_FAKE_CALLER_CONTENT'],'en_US')['content']==='','Caller payload cannot bypass live public tutorial gate');
 wp_update_post(['ID'=>$en,'post_status'=>'publish','post_password'=>'qa-protected']); $editorial->clearCache(); p14_assert($editorial->tutorial($en,'en_US')===null && $help->listing('en_US')['total']===20,'Password protected tutorial not public');
 wp_update_post(['ID'=>$en,'post_password'=>'']); $editorial->clearCache(); p14_assert(count($editorial->tutorialAlternatives($zh))===2,'Actual public translation restored');
});
p14_case('reading_navigation_sanitizes_content_and_does_not_execute_shortcode', function() use($help,$editorial,&$import) {
 $executed=false; add_shortcode('p14_execute_probe',static function()use(&$executed){$executed=true;return 'EXECUTED';});
 $html='<h2 onclick="alert(1)">第一节 &amp; Source</h2><p>[p14_execute_probe]</p><script>window.p14Injected=1</script><h3 id="evil">Second</h3>';
 $out=Help::navigation($html); p14_assert(!$executed,'Reading sanitizer never evaluates shortcode'); p14_assert(count($out['toc'])===2 && array_column($out['toc'],'id')===['help-section-1','evil'],'Generated navigation preserves harmless existing bookmarked anchor'); p14_assert(!str_contains($out['content'],'<script')&&!str_contains($out['content'],'onclick='),'Untrusted execution attributes removed');
 $legacy=Help::navigation('<p>Start</p></div><h2>Later section</h2><p>Preserved final text</p>');p14_assert(str_contains($legacy['content'],'Preserved final text')&&count($legacy['toc'])===1,'Malformed legacy closing div cannot truncate later article content');
 $id=$import['created'][2]; $read=$help->reading(['id'=>$id],get_post_meta($id,'_aish_locale',true)); p14_assert(count($read['toc'])>0 && count($read['related'])<=4,'Real article has reading navigation and bounded related links'); foreach($read['related'] as $item) p14_assert($item['id']!==$id && $item['locale']===get_post_meta($id,'_aish_locale',true),'Related articles exclude self and wrong language'); remove_shortcode('p14_execute_probe');
});
p14_case('related_skill_ids_pass_existing_public_catalog_gate', function() use($editorial,&$import,$core,$management) {
 $public=p14_skill('help-linked-public'); $private=p14_skill('help-linked-private',false); $id=$import['created'][1]; add_post_meta($id,'_aish_related_skill',(string)$public); add_post_meta($id,'_aish_related_skill',(string)$private); $editorial->clearCache();
 $tutorial=$editorial->tutorial($id,'en_US'); $cards=$core->get('public_catalog')->cardsByIds($tutorial['related_skill_ids'],'en_US'); p14_assert(p14_ids($cards)===[$public],'Related rendering excludes unreviewed draft');
 $management->archiveSkill($public,p14_skill_revision($public));$fresh=new AISkillsHub\Core\Frontend\PublicCatalog($GLOBALS['db'],$core->migrator());p14_assert($fresh->cardsByIds($tutorial['related_skill_ids'],'en_US')===[],'Next public request excludes archived related resource without persistent stale cache');
});
p14_report('Real WordPress/MySQL native help import, transactional fault injection, independent bilingual editorial data, publication/privacy gates and query-cache checks; no external API or WPML plugin claim');
