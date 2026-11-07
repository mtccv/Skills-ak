<?php
declare(strict_types=1);
/** Real disposable WP/MySQL retention checks. All artificial ages belong to identities created here. */
define('WP_CLI',true); require __DIR__.'/bootstrap.php';
use AISkillsHub\Core\Learning\SubscriptionCampaigns;
$service=$core->get('learning_subscriptions');$store=$core->get('account_store');$saved=$service->snapshot();$ownedUsers=[];$ownedIssues=[];
$create=function(string $suffix,bool $confirm)use($service,$store,$db,&$ownedUsers):int{
 $id=p14_user('retention-'.$suffix);$ownedUsers[]=$id;wp_set_current_user($id);$service->request(['locale'=>'en_US','weekly'=>true,'new_resources'=>false,'consent'=>true],bin2hex(random_bytes(32)));
 if($confirm){$r=$db->row('SELECT * FROM '.$db->table('notification_outbox')." WHERE recipient_user_id=%d AND template_key='newsletter_confirm' ORDER BY id DESC LIMIT 1",[$id]);p14_assert($service->confirm($store->decryptPayload($r)['token'])==='active','Owned synthetic recipient explicitly confirms');}return$id;
};
$age=function(int$id,int$days)use($db,$service):void{$state=$service->rawState($id);$state['updated_at']=gmdate('Y-m-d H:i:s',time()-$days*86400);$db->execute('UPDATE '.$db->connection()->usermeta.' SET meta_value=%s WHERE user_id=%d AND meta_key=%s',[wp_json_encode($state),$id,$service->storageKey()]);wp_cache_delete($id,'user_meta');};
$issue=function(int$days,string$label)use($db,$admin,$run,&$ownedIssues):int{
 $state=['version'=>1,'revision'=>1,'locale'=>'en_US','kind'=>'weekly','period'=>'retention-'.$run.'-'.$label,'state'=>'complete','refs'=>[],'approved'=>[],'approved_by'=>$admin,'created_at'=>gmdate('Y-m-d H:i:s',time()-($days+7)*86400),'expires_at'=>gmdate('Y-m-d H:i:s',time()-$days*86400),'cursor'=>0,'upper_user_id'=>0,'queued'=>0,'skipped'=>0,'retries'=>0,'last_error'=>''];
 // Synthetic age fixture only; this bypass is confined to the QA database and never grants consent or queues mail.
 $ok=$db->connection()->insert($db->connection()->posts,['post_type'=>SubscriptionCampaigns::TYPE,'post_status'=>'private','post_author'=>0,'post_name'=>'p14-retention-'.$run.'-'.$label,'post_title'=>'Owned Phase14 retention fixture','post_excerpt'=>'','post_content_filtered'=>'','to_ping'=>'','pinged'=>'','guid'=>'','post_content'=>wp_json_encode($state),'post_date'=>gmdate('Y-m-d H:i:s'),'post_date_gmt'=>gmdate('Y-m-d H:i:s'),'post_modified'=>gmdate('Y-m-d H:i:s'),'post_modified_gmt'=>gmdate('Y-m-d H:i:s'),'comment_status'=>'closed','ping_status'=>'closed']);p14_assert($ok===1,'Owned private expiry fixture inserted');$id=(int)$db->connection()->insert_id;$ownedIssues[]=$id;return$id;
};
try{
 wp_set_current_user($admin);$service->saveConfig(['enabled'=>true,'paused'=>true],$saved['revision']);
 p14_case('real_optin_retention_while_marketing_paused_preserves_accounts_active_consent_and_recent_history',function()use($service,$db,$admin,$create,$age,$issue,&$ownedUsers,&$ownedIssues){
  $pending=$create('pending',false);$age($pending,31);$active=$create('active',true);$age($active,181);$recent=$create('recent',false);
  $suppressed=[];foreach(['unsubscribed','paused','bounced']as$kind){$id=$create($kind,true);wp_set_current_user($admin);$service->setState($id,$service->rawState($id)['revision'],$kind,'Synthetic retention evidence');$age($id,181);$suppressed[]=$id;}
  $oldIssue=$issue(91,'expired');$recentIssue=$issue(1,'recent');wp_set_current_user($admin);
  $n=$db->connection();$source="CASE WHEN JSON_VALID(meta_value) THEN meta_value ELSE '{}' END";$state="JSON_UNQUOTE(JSON_EXTRACT($source,'$.state'))";$updated="JSON_UNQUOTE(JSON_EXTRACT($source,'$.updated_at'))";
  $eligibleUsers=array_map('intval',array_column($db->rows("SELECT DISTINCT user_id FROM {$n->usermeta} WHERE meta_key=%s AND (($state='pending' AND $updated<%s) OR ($state IN ('unsubscribed','bounced','paused') AND $updated<%s))",[$service->storageKey(),gmdate('Y-m-d H:i:s',time()-30*86400),gmdate('Y-m-d H:i:s',time()-180*86400)]),'user_id'));
  $postSource="CASE WHEN JSON_VALID(post_content) THEN post_content ELSE '{}' END";$eligibleIssues=array_map('intval',array_column($db->rows("SELECT ID FROM {$n->posts} WHERE post_type=%s AND post_status='private' AND (JSON_UNQUOTE(JSON_EXTRACT($postSource,'$.expires_at'))<%s OR (JSON_UNQUOTE(JSON_EXTRACT($postSource,'$.expires_at'))<%s AND JSON_UNQUOTE(JSON_EXTRACT($postSource,'$.state'))='queued'))",[SubscriptionCampaigns::TYPE,gmdate('Y-m-d H:i:s',time()-90*86400),gmdate('Y-m-d H:i:s')]),'ID'));
  // Never run a global expiry pass if it would mutate an original/other run's record.
  p14_assert(!array_diff($eligibleUsers,$ownedUsers)&&!array_diff($eligibleIssues,$ownedIssues),'Global cleanup candidates are exclusively this run owned synthetic expiry fixtures');
  $captured=0;$capture=static function()use(&$captured):bool{++$captured;return true;};add_filter('pre_wp_mail',$capture,PHP_INT_MAX);
  try{$service->tick();}finally{remove_filter('pre_wp_mail',$capture,PHP_INT_MAX);}
  p14_assert($captured===0,'Paused retention Cron never invokes transport');
  foreach(array_merge([$pending],$suppressed)as$id){p14_assert($service->rawState($id)===null,'Expired owned preference removed');p14_assert(get_userdata($id)instanceof WP_User,'Native identity is preserved');p14_assert(!(int)$db->value("SELECT COUNT(*) FROM {$n->usermeta} WHERE user_id=%d AND meta_key LIKE %s",[$id,$n->esc_like('_aish_subscription_token_').'%']),'Cancellation lookup removed with expired consent');p14_assert(!(int)$db->value('SELECT COUNT(*) FROM '.$db->table('notification_outbox')." WHERE recipient_user_id=%d AND template_key LIKE 'newsletter_%' AND status IN ('queued','retry','claimed')",[$id]),'Owned pending marketing cancelled before consent cleanup');}
  p14_assert($service->rawState($active)['state']==='active','Active consent is not guessed expired by its age');p14_assert($service->rawState($recent)['state']==='pending','Recent pending request preserved');
  p14_assert(null===$db->value("SELECT ID FROM {$n->posts} WHERE ID=%d",[$oldIssue]),'Owned issue older than ninety days after expiry removed');p14_assert((int)$db->value("SELECT ID FROM {$n->posts} WHERE ID=%d",[$recentIssue])===$recentIssue,'Recent private issue history preserved');
 });
}finally{wp_set_current_user($admin);$service->saveConfig(array_intersect_key($saved,['enabled'=>true,'paused'=>true]),$service->snapshot()['revision']);}
p14_report('Real WP/MySQL owned synthetic 30/180/90-day retention, paused Cron and no transport; preflight refuses cleanup affecting any other run record');
