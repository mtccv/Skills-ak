<?php
declare(strict_types=1);
/** CLI-only synthetic fixtures. Cookies and opaque credentials stay in private process pipes. */
require __DIR__ . '/bootstrap.php';
use AISkillsHub\Core\Accounts\Crypto;
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Learning\{Onboarding,Subscriptions};
$input = json_decode((string)stream_get_contents(STDIN),true); $mode=$input['mode']??'';
$subscriptions=$core->get('learning_subscriptions');$onboarding=$core->get('learning_onboarding');
$f=get_option('aish_p14_browser_fixture',[]);wp_set_current_user($admin);
function p14_browser_cookies(int $id):array{wp_set_current_user($id);$expires=time()+3600;$session=WP_Session_Tokens::get_instance($id)->create($expires);$cookie=wp_generate_auth_cookie($id,$expires,'logged_in',$session);$_COOKIE[LOGGED_IN_COOKIE]=$cookie;return [['name'=>LOGGED_IN_COOKIE,'value'=>$cookie],['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$expires,'auth',$session)]];}
if($mode==='setup'){
 $f=['manages_newsletter'=>!empty($input['newsletter']),'qa_admin'=>p14_user('browser-admin'),'member'=>p14_user('browser-member'),'other'=>p14_user('browser-other'),'unverified'=>p14_user('browser-unverified',false),'saved'=>$subscriptions->snapshot()];
 wp_set_current_user($admin);get_userdata($f['qa_admin'])->set_role('administrator');
 wp_set_current_user($f['member']);$f['developer']='qa-p14-'.$run;
 $profile=$developers->saveProfile(['handle'=>$f['developer'],'opt_in'=>true,'public_avatar'=>false,'show_contributions'=>true,'translations'=>['en_US'=>['display_name'=>'Synthetic QA developer','bio'=>'Original public QA profile approved only in this isolated environment.'],'zh_CN'=>['display_name'=>'隔离测试开发者','bio'=>'仅在隔离测试环境审核公开的合成开发者资料。']]],0,bin2hex(random_bytes(16)));wp_set_current_user($admin);$developers->moderateProfile($f['member'],$profile['revision'],'approve','Synthetic QA approval','PRIVATE_BROWSER_PROFILE_NOTE',bin2hex(random_bytes(16)));
 wp_set_current_user($admin);$license=$management->saveTerm('tag',null,['name'=>'MIT','slug'=>'license-mit-browser-'.$run]);$platform=$management->saveTerm('tag',null,['name'=>'Copy-only QA platform','slug'=>'platform-browser-'.$run]);
 $command="printf '%s\\n' 'QA copy-only example'";$translations=[];
 foreach(['en_US','zh_CN']as$locale)$translations[$locale]=['title'=>'Browser QA '.$locale,'slug'=>'experience-browser-'.$run,'summary'=>'Synthetic Phase 14 browser fixture','content'=>'<h2>Reviewed copy-only example</h2><p>Never execute this QA example automatically.</p><pre><code>'.esc_html($command).'</code></pre>'];
 $repository=$management->saveRepository(null,'qa-'.$run,'phase14-browser');$f['skill']=$management->saveSkill(null,['save_mode'=>'pending','repository_id'=>$repository,'source_path'=>'skills/browser/SKILL.md','translations'=>$translations,'tag_ids'=>[$license,$platform]]);$management->review($f['skill'],'approved','unknown','Synthetic browser approval',p14_skill_revision($f['skill']));
 $source="---\nname: browser-example\ndescription: Synthetic QA only.\n---\n```sh\n$command\n```\n";$commit=hash('sha1',$run);$file=hash('sha1',$source);$now=gmdate('Y-m-d H:i:s');
 $version=$db->insert('skill_versions',['skill_id'=>$f['skill'],'repository_id'=>$repository,'source_path'=>'skills/browser/SKILL.md','commit_sha'=>$commit,'file_sha'=>$file,'content_hash'=>hash('sha256',$source),'source_content'=>$source,'created_at'=>$now]);
 $db->insert('imports',['skill_id'=>$f['skill'],'repository_id'=>$repository,'source_path'=>'skills/browser/SKILL.md','commit_sha'=>$commit,'file_sha'=>$file,'metadata_json'=>'{}','findings_json'=>'{}','source_status'=>'present','change_status'=>'new','created_at'=>$now,'updated_at'=>$now]);
 foreach(['en_US','zh_CN']as$locale)$core->get('learning_skill_experience')->save($f['skill'],$locale,['state'=>'verified','version_id'=>$version,'commands'=>[['command'=>$command,'platform'=>'platform-browser-'.$run]],'rights_note'=>'Synthetic QA MIT source quotation review'],0,p14_skill_revision($f['skill']),true);
 if($f['manages_newsletter'])$subscriptions->saveConfig(['enabled'=>true,'paused'=>false],$subscriptions->snapshot()['revision']);
 update_option('aish_p14_browser_fixture',$f,false);$cookies=[];foreach(['member','other','unverified']as$key)$cookies[$key]=p14_browser_cookies($f[$key]);$cookies['admin']=p14_browser_cookies($f['qa_admin']);
 $urls=[];$help=[];foreach(['en_US','zh_CN']as$locale){$slug=$db->value('SELECT slug FROM '.$db->table('skill_translations').' WHERE skill_id=%d AND locale=%s',[$f['skill'],$locale]);$urls[$locale]=Router::url('skill',['id'=>$f['skill'],'slug'=>$slug],$locale);$help[$locale]=$core->get('learning_help')->topicLinks($locale);}
 flush_rewrite_rules(false);echo wp_json_encode(['cookies'=>$cookies,'skill'=>$f['skill'],'urls'=>$urls,'help'=>$help,'command'=>$command,'developer'=>$f['developer']]);exit;
}
if(!$f)exit(4);
if($mode==='auth'){if(empty($f['qa_admin']))exit(4);echo wp_json_encode(['cookies'=>p14_browser_cookies($f['qa_admin'])]);exit;}
if($mode==='state'){$states=[];foreach(['member','other','unverified']as$key){wp_set_current_user($f[$key]);$states[$key]=['subscription'=>$subscriptions->accountPreferences(),'onboarding'=>$onboarding->mine()];}echo wp_json_encode($states);exit;}
if($mode==='tokens'){
 // Only this harness-created member and its latest newsletter outbox are readable here.
 $row=$db->row('SELECT * FROM '.$db->table('notification_outbox')." WHERE recipient_user_id=%d AND template_key='newsletter_confirm' ORDER BY id DESC LIMIT 1",[$f['member']]);if(!$row||!$row['payload_ciphertext'])exit(5);
 $payload=Crypto::decrypt($row['payload_ciphertext'],$row['key_version'],'outbox:'.$row['event_key']);$s=$subscriptions->rawState($f['member']);$cancel=Crypto::newsletterToken($f['member'],$s['generation'],$s['nonce'],$s['key_version']);echo wp_json_encode(['confirm'=>$payload['token'],'unsubscribe'=>$cancel['token']]);exit;
}
if($mode==='pause'){wp_set_current_user($admin);$s=$subscriptions->snapshot();$subscriptions->saveConfig(['enabled'=>$s['enabled'],'paused'=>true],$s['revision']);echo '{"paused":true}';exit;}
if($mode==='restore'){if(empty($f['manages_newsletter'])){echo '{"restored":true,"configuration_untouched":true}';exit;}wp_set_current_user($admin);foreach(['member','other','unverified']as$key){wp_set_current_user($f[$key]);$subscriptions->accountUnsubscribe();}wp_set_current_user($admin);$s=$f['saved'];unset($s['revision']);$subscriptions->saveConfig($s,$subscriptions->snapshot()['revision']);echo '{"restored":true}';exit;}
exit(6);
