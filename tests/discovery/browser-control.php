<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('AISH_PHASE11_QA')!=='1')exit(1);
$_SERVER['HTTP_HOST']='127.0.0.1:18183';
require '/var/www/html/wp-load.php';
if(DB_NAME!=='qa_discovery'||get_option('aish_schema_version')!=='1.9.0')exit(2);
$core=AISkillsHub\Core\Core::boot();$db=$core->get('database');$input=json_decode((string)stream_get_contents(STDIN),true);$mode=$input['mode']??'fixtures';
$f=get_option('aish_phase11_qa_fixture');
if($mode==='fixtures') {
 wp_set_current_user(1);do_action('admin_init');$settings=new AISkillsHub\Core\Discovery\Settings($db,$core->migrator());$s=$settings::snapshot();$p=$s['published'];$p['tracking']=true;$settings->save($p,$s['revision'],'publish');flush_rewrite_rules();
 $query=$core->get('discovery_query');
 $f['collection']=$query->detail('collection',$f['collection_slug'],'en_US');$f['use_case']=$query->detail('use_case',$f['use_case_slug'],'en_US');
 $f['draft_slug']=$db->value('SELECT slug FROM '.$db->table('skill_translations').' WHERE skill_id=%d AND locale=%s',[$f['skills']['draft'],'en_US']);
 $f['platform_slug']=$query->topics('platform','en_US')[0]['slug']??null;
 unset($f['users']);echo wp_json_encode($f);exit;
}
if($mode==='metrics') {
 $id=(int)$f['skills']['alpha'];$rows=$db->rows('SELECT metric_name,dimension_type,dimension_key,locale,event_day,dedupe_key,policy_version,occurred_at,expires_at FROM '.$db->table('metric_events')." WHERE metric_name='skill_view' AND dimension_key=%s",[(string)$id]);
 $count=$db->value('SELECT metric_count FROM '.$db->table('daily_metrics')." WHERE metric_name='skill_view' AND dimension_key=%s AND stat_day=%s AND locale=''",[(string)$id,gmdate('Y-m-d')]);
 echo wp_json_encode(['events'=>count($rows),'aggregate'=>(int)$count,'columns'=>$rows?array_keys($rows[0]):[],'shared_locale'=>!array_filter($rows,static fn($r)=>$r['locale']!==''),'only_opaque_hashes'=>!array_filter($rows,static fn($r)=>!preg_match('/^[a-f0-9]{64}$/D',$r['dedupe_key']))]);exit;
}
if($mode==='subscriber_cookie') {
 $user=(int)$f['users'][0];wp_set_current_user($user);echo wp_json_encode(['cookie'=>wp_generate_auth_cookie($user,time()+3600,'logged_in'),'name'=>LOGGED_IN_COOKIE]);exit;
}
