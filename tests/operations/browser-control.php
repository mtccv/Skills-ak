<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$input=json_decode((string)stream_get_contents(STDIN),true);$mode=$input['mode']??'setup';$f=get_option('aish_phase12_qa_fixture');
if(!is_array($f))exit(4);
function p12_cookie(int$id):array{wp_set_current_user($id);$expiration=time()+3600;$token=WP_Session_Tokens::get_instance($id)->create($expiration);$logged=wp_generate_auth_cookie($id,$expiration,'logged_in',$token);$_COOKIE[LOGGED_IN_COOKIE]=$logged;return[['name'=>LOGGED_IN_COOKIE,'value'=>$logged],['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$expiration,'auth',$token)]];}
if($mode==='setup'){
 wp_set_current_user($admin);$original=AISkillsHub\Core\Discovery\Settings::snapshot();update_option('aish_p12_browser_saved_settings',$original['published'],false);$p=$original['published'];$p['tracking']=true;$s=$settings->save($p,$original['revision'],'publish');
 $limited=p12_user('browserlimited');get_userdata($limited)->add_cap('aish_view_operations');get_userdata($limited)->add_cap('aish_manage_submissions');$limitedCookies=p12_cookie($limited);$memberCookies=p12_cookie((int)$f['member']);$adminCookies=p12_cookie($admin);
 $s=AISkillsHub\Core\Discovery\Settings::snapshot();$p=$s['published'];foreach($p['home']as&$modules){foreach($modules as&$m){if($m['key']==='recently_added')$m['title']='Phase12 private draft QA';}unset($m);}unset($modules);$settings->save($p,$s['revision'],'draft');$s=AISkillsHub\Core\Discovery\Settings::snapshot();
 $preview=add_query_arg(['aish_home_preview'=>$s['revision'],'_wpnonce'=>wp_create_nonce('aish_home_preview_'.$s['revision'])],home_url('/en/'));
 $stale=add_query_arg(['aish_home_preview'=>$s['revision']-1,'_wpnonce'=>wp_create_nonce('aish_home_preview_'.($s['revision']-1))],home_url('/en/'));
 $reviewer=p12_user('browserreviewer');$pendingReview=p12_review($reviewer,(int)$f['skill'],3,false);wp_set_current_user($admin);
 $store=$core->get('account_store');$own=$store->notify((int)$f['member'],hash('sha256',$run.':browserown'),'password_changed','en_US');$foreign=$store->notify((int)$f['other'],hash('sha256',$run.':browserforeign'),'email_verified','en_US');$communityNotice=$store->notifyCommunity((int)$f['member'],hash('sha256',$run.':browsercommunity'),'review_approved','en_US','review',(int)$f['review']);update_option('aish_p12_browser_records',['member'=>(int)$f['member'],'other'=>(int)$f['other'],'own_notice'=>$own,'foreign_notice'=>$foreign,'community_notice'=>$communityNotice,'pending_review'=>(int)$pendingReview['id']],false);
 flush_rewrite_rules();echo wp_json_encode(['admin_cookies'=>$adminCookies,'member_cookies'=>$memberCookies,'limited_cookies'=>$limitedCookies,'preview'=>$preview,'stale'=>$stale,'member'=>$f['member'],'own_notice'=>$own,'foreign_notice'=>$foreign,'community_notice'=>$communityNotice,'pending_review'=>['id'=>(int)$pendingReview['id'],'revision_id'=>(int)$pendingReview['pending_revision_id'],'lock_version'=>(int)$pendingReview['lock_version']],'skill_url'=>home_url('/en/skills/'.$f['skill'].'/'.$db->value('SELECT slug FROM '.$db->table('skill_translations').' WHERE skill_id=%d AND locale=%s',[$f['skill'],'en_US']).'/')]);exit;
}
if($mode==='restore'){wp_set_current_user($admin);$s=AISkillsHub\Core\Discovery\Settings::snapshot();$settings->save(get_option('aish_p12_browser_saved_settings'),$s['revision'],'publish');echo '{"restored":true}';exit;}
if($mode==='search_counts'){$d=$db->table('daily_metrics');echo wp_json_encode(['search'=>(int)$db->value("SELECT metric_count FROM $d WHERE stat_day=%s AND metric_name='search' AND dimension_type='site' AND dimension_key='all' AND locale='en_US' AND policy_version='operations-1'",[gmdate('Y-m-d')])]);exit;}

if($mode==='reset_caps'){wp_set_current_user($admin);$role=get_role('administrator');foreach(['aish_view_operations','aish_manage_accounts','aish_manage_notifications','aish_view_analytics']as$cap)$role->remove_cap($cap);delete_option('aish_operations_caps_version');echo '{"reset_only_new_qa_caps":true}';exit;}

if($mode==='security_state'){
 $b=get_option('aish_p12_browser_records');if(!is_array($b))exit(5);$store=$core->get('account_store');$ops=$core->get('operations_users');wp_set_current_user($admin);$u=$ops->detail($b['member']);
 $heads=$db->rows('SELECT id,state,approved_revision_id,pending_revision_id,lock_version FROM '.$db->table('reviews').' WHERE id=%d',[$b['pending_review']]);$revisions=$db->rows('SELECT id,review_id,moderation_state,moderator_id,moderated_at FROM '.$db->table('review_revisions').' WHERE review_id=%d ORDER BY id',[$b['pending_review']]);
 $events=$db->rows('SELECT id,action,subject_revision,from_state,to_state FROM '.$db->table('workflow_events')." WHERE (subject_type='user' AND subject_id=%d) OR (subject_type='review' AND subject_id=%d) OR subject_type IN ('mail_message','mail_queue') ORDER BY id",[$b['member'],$b['pending_review']]);
 $read=static fn(int$id):bool=>null!==$db->value('SELECT read_at FROM '.$db->table('user_notifications').' WHERE id=%d',[$id]);
 echo wp_json_encode(['member_unread'=>$store->unreadCount($b['member']),'foreign_unread'=>$store->unreadCount($b['other']),'own_read'=>$read($b['own_notice']),'foreign_read'=>$read($b['foreign_notice']),'user_operation_notice'=>get_transient('aish_users_notice_'.$admin),'member_state'=>$u['state'],'member_revision'=>$u['revision'],'mail_paused'=>get_option('aish_account_mail_paused','0'),'review_hash'=>hash('sha256',wp_json_encode([$heads,$revisions])),'audit_hash'=>hash('sha256',wp_json_encode($events))]);exit;
}
if($mode==='community_ids'){$b=get_option('aish_p12_browser_records');if(!is_array($b))exit(5);echo wp_json_encode(['ids'=>array_map('intval',array_column($core->get('account_store')->notifications($b['member'],20,0,'community'),'id'))]);exit;}
