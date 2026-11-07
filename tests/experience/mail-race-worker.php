<?php
declare(strict_types=1);
define('WP_CLI',true);require __DIR__.'/bootstrap.php';
use AISkillsHub\Core\Accounts\Crypto;
$input=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$service=$core->get('learning_subscriptions');$f=get_option('aish_p14_newsletter_race');if(!is_array($f))exit(3);$mode=$input['mode']??'';
if($mode==='enable'){wp_set_current_user($admin);$service->saveConfig(['enabled'=>true,'paused'=>false],$service->snapshot()['revision']);echo'{"enabled":true}';exit;}
if($mode==='restore'){wp_set_current_user($admin);$service->saveConfig(array_intersect_key($f['saved'],['enabled'=>true,'paused'=>true]),$service->snapshot()['revision']);echo'{"restored":true}';exit;}
if($mode==='consent_setup'){$f['c']=p14_user('race-consent');$f['request_key']=bin2hex(random_bytes(32));update_option('aish_p14_newsletter_race',$f,false);echo'{"created":true}';exit;}
if(in_array($mode,['request','consent_counts'],true)){
 $id=(int)($f['c']??0);if($id<1)exit(8);wp_set_current_user($id);
 try{if($mode==='request')$service->request(['locale'=>'en_US','weekly'=>true,'new_resources'=>false,'consent'=>true],$f['request_key']);echo wp_json_encode(['state'=>$service->rawState($id)['state']??null,'consent_rows'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->connection()->usermeta.' WHERE user_id=%d AND meta_key=%s',[$id,$service->storageKey()]),'confirmations'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->table('email_verifications')." WHERE user_id=%d AND purpose='newsletter'",[$id]),'outbox'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->table('notification_outbox')." WHERE recipient_user_id=%d AND template_key='newsletter_confirm'",[$id])]);exit;}catch(Throwable$e){echo wp_json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():get_class($e)]);exit(1);}
}
$which=$input['which']??'';if(!in_array($which,['a','b'],true))exit(4);$id=(int)$f[$which];$mail=(int)$f['mail_'.$which];$row=fn():?array=>$db->row('SELECT id,status,last_error,attempts,(payload_ciphertext IS NOT NULL) has_payload FROM '.$db->table('notification_outbox').' WHERE id=%d',[$mail]);
try{
if($mode==='state'){echo wp_json_encode(['row'=>$row(),'state'=>$service->rawState($id)['state']]);exit;}
if($mode==='cancel'){$s=$service->rawState($id);$token=Crypto::newsletterToken($id,$s['generation'],$s['nonce'],$s['key_version'])['token'];echo"{\"stage\":\"cancel_started\"}\n";flush();$ok=$service->unsubscribe($token);echo wp_json_encode(['cancelled'=>$ok,'row'=>$row(),'state'=>$service->rawState($id)['state']]);exit;}
if($mode==='send'){
 $table=$db->table('notification_outbox');$db->execute("UPDATE $table SET available_at='1971-01-01 00:00:00' WHERE id=%d",[$mail]);$select=static function(string$sql)use($table,$mail):string{if(str_starts_with($sql,"SELECT * FROM $table WHERE status IN ('queued','retry')")&&str_ends_with($sql,' LIMIT 1 FOR UPDATE'))return str_replace(' ORDER BY ',' AND id='.$mail.' ORDER BY ',$sql);return$sql;};add_filter('query',$select,PHP_INT_MAX);$calls=0;$barrier=$input['barrier']??'';if(!is_string($barrier)||($barrier!==''&&!preg_match('/^[a-f0-9]{32}$/D',$barrier)))exit(5);
 add_filter('pre_wp_mail',static function()use(&$calls,$barrier){++$calls;if($barrier!==''){echo"{\"stage\":\"transport_started\"}\n";flush();$start=microtime(true);while(!is_file('/tmp/aish-p14-mail-release-'.$barrier)&&microtime(true)-$start<8){usleep(10000);}if(!is_file('/tmp/aish-p14-mail-release-'.$barrier))throw new RuntimeException('qa_barrier_timeout');}return true;},PHP_INT_MAX);
 $core->get('account_mail_worker')->run();echo wp_json_encode(['row'=>$row(),'state'=>$service->rawState($id)['state'],'mock_mail_calls'=>$calls]);exit;
}
exit(6);
}catch(Throwable$e){echo wp_json_encode(['error'=>property_exists($e,'reason')?$e->reason:($e instanceof RuntimeException?$e->getMessage():get_class($e))]);exit(1);}
