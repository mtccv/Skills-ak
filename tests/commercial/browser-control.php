<?php
declare(strict_types=1);
/** CLI-only synthetic browser fixtures. Cookies/nonces stay in process memory, never reports. */
require __DIR__ . '/bootstrap.php';
use AISkillsHub\Core\Commercial\{Sponsors,Enterprise};
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Frontend\Router;
$input=json_decode((string)stream_get_contents(STDIN),true);$mode=$input['mode']??'';
$sponsors=$core->get('commercial_sponsors');$enterprise=$core->get('commercial_enterprise');wp_set_current_user($admin);
$f=get_option('aish_p13_browser_fixture',[]);
function p13_browser_cookie(int $id):array{wp_set_current_user($id);$expires=time()+3600;$session=WP_Session_Tokens::get_instance($id)->create($expires);$cookie=wp_generate_auth_cookie($id,$expires,'logged_in',$session);$_COOKIE[LOGGED_IN_COOKIE]=$cookie;return[['name'=>LOGGED_IN_COOKIE,'value'=>$cookie],['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($id,$expires,'auth',$session)]];}
function p13_browser_save(int$id,array$data):void{global$sponsors;$sponsors->saveEntry($id,$sponsors->snapshot()['revision'],$data,true);}
function p13_browser_tracking(bool$enabled):void{global$settings;$s=Settings::snapshot();$p=$s['published'];$p['tracking']=$enabled;$settings->save($p,$s['revision'],'publish');}
function p13_browser_payload(array$entry):array{return array_intersect_key($entry,Sponsors::blank());}
function p13_browser_entry(int$id):array{global$sponsors;foreach($sponsors->snapshot()['entries']as$row){if($row['id']===$id)return p13_browser_payload($row);}throw new RuntimeException('QA sponsor missing');}
function p13_browser_inquiries():array{global$db;$f=get_option('aish_p13_browser_fixture',[]);$w=$db->connection();return$db->rows("SELECT p.ID,p.post_status,p.post_author,p.post_content,s.meta_value AS state,r.meta_value AS revision FROM {$w->posts} p INNER JOIN {$w->postmeta} e ON e.post_id=p.ID AND e.meta_key='_aish_sender_email' INNER JOIN {$w->postmeta} k ON k.post_id=p.ID AND k.meta_key='_aish_message_kind' AND k.meta_value='enterprise' INNER JOIN {$w->postmeta} s ON s.post_id=p.ID AND s.meta_key='_aish_message_state' INNER JOIN {$w->postmeta} r ON r.post_id=p.ID AND r.meta_key='_aish_enterprise_revision' WHERE e.meta_value=%s ORDER BY p.ID",[$f['email']??'']);}
if($mode==='setup'){
 if(!isset($f['member'])){$f=['member'=>p13_user('browsermember'),'sponsor_user'=>p13_user('browsersponsor'),'metric_user'=>p13_user('browsermetrics'),'enterprise_user'=>p13_user('browserenterprise'),'submission_user'=>p13_user('browsersubmission'),'skill'=>p13_skill('browser-resource'),'marker'=>'Private QA browser inquiry '.bin2hex(random_bytes(6)),'email'=>'browser-'.bin2hex(random_bytes(6)).'@example.invalid'];}
 wp_set_current_user($admin);foreach(['sponsor_user'=>Sponsors::CAPABILITY,'metric_user'=>'aish_view_commercial_metrics','enterprise_user'=>Enterprise::CAPABILITY,'submission_user'=>'aish_manage_submissions']as$key=>$cap){get_userdata($f[$key])->add_cap($cap);}
 if('published'!==$db->value('SELECT status FROM '.$db->table('skills').' WHERE id=%d',[$f['skill']])){$management->changeStatus($f['skill'],'pending',p13_skill_revision($f['skill']));$management->review($f['skill'],'approved','unknown','Synthetic browser fixture restored',p13_skill_revision($f['skill']));}
 if(!isset($f['saved'])){$f['saved']=['sponsors'=>$sponsors->snapshot(),'enterprise'=>Enterprise::snapshot(),'discovery'=>Settings::snapshot()['published']];}
 // Keep only the two owned placements live during deterministic browser observations.
 foreach($sponsors->snapshot()['entries']as$row){if(Sponsors::displayState($row)==='enabled'&&($sponsors->preview($row['id'],'en_US')||$sponsors->preview($row['id'],'zh_CN'))&&!in_array($row['id'],[$f['home']??0,$f['footer']??0],true)){$p=p13_browser_payload($row);$p['status']='paused';p13_browser_save($row['id'],$p);}}
 foreach(['home'=>'home_after_search','footer'=>'skill_detail_footer']as$key=>$placement){$p=Sponsors::blank();$p['name']='Synthetic QA sponsor';$p['status']='enabled';$p['placement']=$placement;$p['ends_at']=gmdate('Y-m-d H:i:s',time()+7200);$p['review_note']='Private QA review marker; never public';$p['translations']=['en_US'=>['title'=>'Synthetic sponsor: technical resources','description'=>'QA only: original directory layout & clear disclosure.'],'zh_CN'=>['title'=>'隔离测试赞助：技术资源','description'=>'仅用于测试目录布局与明确的赞助标识。']];if($key==='home')$p['url']='https://example.com/phase13-browser?qa=1';else$p['skill_id']=$f['skill'];$s=$sponsors->saveEntry($f[$key]??0,$sponsors->snapshot()['revision'],$p,true);if(!isset($f[$key]))$f[$key]=$s['next_id']-1;}
 $sponsors->setEnabled(false,$sponsors->snapshot()['revision']);p13_browser_tracking(false);
 $e=Enterprise::snapshot();$enterprise->saveConfig(['enabled'=>true,'translations'=>['en_US'=>['title'=>'Synthetic QA enterprise contact','description'=>'Isolated test of a private manual inquiry. No service is being sold.'],'zh_CN'=>['title'=>'隔离测试企业联系','description'=>'测试私有人工咨询流程，不销售服务。']]],$e['revision']);
 update_option('aish_p13_browser_fixture',$f,false);$cookies=[];foreach(['member','sponsor_user','metric_user','enterprise_user','submission_user']as$key)$cookies[$key]=p13_browser_cookie($f[$key]);$cookies['admin']=p13_browser_cookie($admin);
 $urls=[];foreach(['en_US','zh_CN']as$locale){$slug=$db->value('SELECT slug FROM '.$db->table('skill_translations').' WHERE skill_id=%d AND locale=%s',[$f['skill'],$locale]);$urls[$locale]=Router::url('skill',['id'=>$f['skill'],'slug'=>$slug],$locale);}
 flush_rewrite_rules(false);echo wp_json_encode(['cookies'=>$cookies,'home'=>$f['home'],'footer'=>$f['footer'],'skill'=>$f['skill'],'urls'=>$urls,'marker'=>$f['marker'],'email'=>$f['email']]);exit;
}
if(!$f)exit(4);
if($mode==='auth'){echo wp_json_encode(['cookies'=>p13_browser_cookie($admin),'home'=>$f['home'],'footer'=>$f['footer']]);exit;}
if($mode==='enable'){$sponsors->setEnabled(true,$sponsors->snapshot()['revision']);echo'{"enabled":true}';exit;}
if($mode==='tracking'){p13_browser_tracking((bool)($input['enabled']??false));echo'{"changed":true}';exit;}
if($mode==='reset_caps'){$role=get_role('administrator');foreach([Sponsors::CAPABILITY,'aish_view_commercial_metrics',Enterprise::CAPABILITY]as$cap)$role->remove_cap($cap);delete_option('aish_commercial_caps_version');echo'{"reset_only_three_qa_caps":true}';exit;}
if($mode==='sponsor_state'){$state=$input['state']??'';$p=p13_browser_entry($f['home']);$p['status']='enabled';$p['starts_at']='';$p['ends_at']=gmdate('Y-m-d H:i:s',time()+7200);if($state==='paused')$p['status']='paused';elseif($state==='future')$p['starts_at']=gmdate('Y-m-d H:i:s',time()+3600);elseif($state==='expiring')$p['ends_at']=gmdate('Y-m-d H:i:s',time()+2);elseif($state!=='enabled')exit(5);p13_browser_save($f['home'],$p);echo'{"changed":true}';exit;}
if($mode==='hostile_copy'){$p=p13_browser_entry($f['home']);$p['name']='Synthetic QA <img src=x onerror=window.aishSponsorInjected=1> sponsor';foreach(['en_US','zh_CN']as$locale){$p['translations'][$locale]['description']='QA <script>window.aishSponsorInjected=2</script> harmless content & details <svg onload=window.aishSponsorInjected=3></svg>';}p13_browser_save($f['home'],$p);echo'{"saved_through_validated_service":true}';exit;}
if($mode==='withdraw_skill'){$management->archiveSkill($f['skill'],p13_skill_revision($f['skill']));echo'{"archived":true}';exit;}
if($mode==='security_state'){$rows=p13_browser_inquiries();$w=$db->connection();$audit=$db->rows('SELECT id,subject_type,subject_revision,action FROM '.$db->table('workflow_events')." WHERE subject_type IN ('sponsor_configuration','enterprise_configuration','enterprise_inquiry') ORDER BY id");echo wp_json_encode(['sponsor_hash'=>hash('sha256',wp_json_encode($sponsors->snapshot())),'enterprise_hash'=>hash('sha256',wp_json_encode(Enterprise::snapshot())),'inquiry_hash'=>hash('sha256',wp_json_encode($rows)),'audit_hash'=>hash('sha256',wp_json_encode($audit)),'inquiry_count'=>count($rows),'inquiry_ids'=>array_map('intval',array_column($rows,'ID')),'private'=>count(array_filter($rows,fn($r)=>$r['post_status']!=='private'||(int)$r['post_author']!==0))===0,'mail_rows'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->table('notification_outbox'))]);exit;}
if($mode==='counts'){$d=$db->table('daily_metrics');$counts=[];foreach(['sponsor_impression','sponsor_click','sponsor_impression_unique','sponsor_click_unique']as$metric)$counts[$metric]=(int)$db->value("SELECT COALESCE(SUM(metric_count),0) FROM $d WHERE stat_day=%s AND metric_name=%s AND dimension_type='sponsor' AND dimension_key=%s AND locale='en_US' AND policy_version='commercial-1'",[gmdate('Y-m-d'),$metric,$f['home'].':home_after_search']);$counts['natural']=(int)$db->value("SELECT COUNT(*) FROM ".$db->table('metric_events')." WHERE metric_name='skill_view' AND dimension_key=%s",[(string)$f['skill']]);echo wp_json_encode($counts);exit;}
if($mode==='restore'){
 $skipped=[];$saved=$f['saved']??null;$failure=null;
 if($saved){
  try{
   $sponsors->setEnabled(false,$sponsors->snapshot()['revision']);
   foreach([$f['home'],$f['footer']]as$id){$p=p13_browser_entry($id);$p['status']='paused';p13_browser_save($id,$p);}
   foreach($saved['sponsors']['entries']as$row){$p=p13_browser_payload($row);if($p['status']==='enabled'&&$p['ends_at']<=gmdate('Y-m-d H:i:s'))continue;try{p13_browser_save($row['id'],$p);}catch(InvalidArgumentException $error){if($error->getMessage()!=='skill_unavailable')throw$error;$skipped[]=$row['id'];}}
   $sponsors->setEnabled($saved['sponsors']['enabled'],$sponsors->snapshot()['revision']);
  }catch(Throwable$error){$failure=$error;}
  // Independent restoration still runs if one optional placement cannot be restored.
  try{$enterprise->saveConfig(array_intersect_key($saved['enterprise'],['enabled'=>true,'translations'=>true]),Enterprise::snapshot()['revision']);}catch(Throwable$error){$failure??=$error;}
  try{$settings->save($saved['discovery'],Settings::snapshot()['revision'],'publish');}catch(Throwable$error){$failure??=$error;}
  if($failure)throw$failure;
  unset($f['saved']);update_option('aish_p13_browser_fixture',$f,false);
 }
 echo wp_json_encode(['restored'=>true,'retained_paused_unavailable_synthetic_ids'=>$skipped]);exit;
}
exit(6);
