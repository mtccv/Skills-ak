<?php
declare(strict_types=1);
/** Independent PHP/MySQL connection for bounded Phase 14 lock checks. */
require __DIR__ . '/bootstrap.php';
use AISkillsHub\Core\Learning\{Onboarding,Help};
use AISkillsHub\Core\Accounts\Locks;
use AISkillsHub\Core\Frontend\Editorial;
$input=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$service=new Onboarding($db,$accounts,new Locks($db));
try {
 if(($input['mode']??'')==='setup') {
  $id=p14_user('parallel-help');update_option('aish_p14_parallel_owner',$id,false);echo wp_json_encode(['created'=>true]);exit;
 }
 $id=(int)get_option('aish_p14_parallel_owner'); if($id<1 || !str_starts_with((string)get_userdata($id)->user_login,'p14_'))exit(3);
 wp_set_current_user($id);
 switch($input['mode']??'') {
  case 'complete': $state=$service->complete((string)($input['step']??''));echo wp_json_encode(['state'=>$state]);break;
  case 'state': echo wp_json_encode(['state'=>$service->mine(),'rows'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->connection()->usermeta.' WHERE user_id=%d AND meta_key=%s',[$id,Onboarding::META]),'verified'=>$accounts->verified()]);break;
  case 'import': wp_set_current_user($admin);$help=new Help($db,$core->migrator(),new Editorial($core->get('public_catalog'),$db));$r=$help->importDrafts();echo wp_json_encode(['created'=>count($r['created']),'existing'=>count($r['existing'])]);break;
  default: exit(4);
 }
} catch(Throwable $e) { echo wp_json_encode(['error'=>property_exists($e,'reason')?$e->reason:($e instanceof RuntimeException?$e->getMessage():get_class($e))]);exit(1); }
