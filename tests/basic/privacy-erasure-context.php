<?php
declare(strict_types=1);
/** Real privacy/operations orchestration over isolated WordPress and SQL doubles. */
namespace AISkillsHub\Core\Database {
 final class Migrator {public function isCurrent():bool{return true;}}
 final class Database {
  public array $events=array(),$queries=array(),$cleanup=array();public bool $pending=false;public bool $failWrite=false;
  public function connection():object{return (object)array('posts'=>'wp_posts','postmeta'=>'wp_postmeta','users'=>'wp_users','usermeta'=>'wp_usermeta');}
  public function table(string $name):string{return '`wp_aish_'.$name.'`';}
  public function execute(string $sql,array $args=array()):int{$this->queries[]=array($sql,$args);if($this->failWrite&&str_starts_with($sql,'UPDATE'))throw new \RuntimeException('isolated_failure');if(str_contains($sql,'UPDATE `wp_aish_workflow_events`')){$count=0;foreach($this->events as&$event){if($event['subject_type']==='user'&&$event['subject_id']===$args[0]&&$count<50){$event['subject_id']=0;$event['actor_user_id']=null;$event['metadata_json']='{}';++$count;}}unset($event);return$count;}return 1;}
  public function value(string $sql,array $args=array()):mixed{$this->queries[]=array($sql,$args);if(str_contains($sql,'workflow_events')){foreach($this->events as$event)if($event['subject_type']==='user'&&$event['subject_id']===$args[0])return 1;return null;}if(str_contains($sql,'user_notifications'))return $this->pending?1:null;return null;}
  public function rows(string $sql,array $args=array()):array{$this->queries[]=array($sql,$args);if(str_contains($sql,"s.meta_value IN ('awaiting_deletion','pending','failed')"))return$this->cleanup;return array();}
 }
}
namespace AISkillsHub\Core\Accounts {
 final class Store {public function revokeVerifications(int $id):void{}public function cancelUserMail(int $id):void{}}
 final class Locks {public array $held=array();public mixed $before=null;public function withUser(int $id,callable $fn):mixed{return$this->with(array(),$id,$fn);}public function with(array $email,int $id,callable $fn):mixed{$this->held[]=$id;try{if($this->before)($this->before)();return$fn();}finally{array_pop($this->held);}}}
 final class AccountService {}
 final class Avatar {public function purgeOwned(int $id,int $limit):bool{return true;}}
}
namespace {
 define('ABSPATH','/isolated/');define('DAY_IN_SECONDS',86400);$checks=0;$GLOBALS['can_erase']=true;$GLOBALS['http']=true;$GLOBALS['hooks']=array();$GLOBALS['users']=array();$GLOBALS['meta']=array();$GLOBALS['postmeta']=array();$GLOBALS['requests']=array();
 final class WP_User {public function __construct(public int $ID,public string $user_email){}}
 final class WP_Session_Tokens {public static function get_instance(int $id):self{return new self();}public function destroy_all():void{}}
 function current_user_can(string $cap):bool{return 'erase_others_personal_data'===$cap&&$GLOBALS['can_erase'];}
 function user_can(WP_User $user,string $cap):bool{return false;}
 function get_userdata(int $id):WP_User|false{return $GLOBALS['users'][$id]??false;}
 function get_user_by(string $field,string $email):WP_User|false{foreach($GLOBALS['users']as$user)if(strtolower($user->user_email)===strtolower($email))return$user;return false;}
 function get_user_meta(int $id,string $key,bool $single=false):mixed{$rows=$GLOBALS['meta'][$id][$key]??array();return $single?($rows[0]??''):$rows;}
 function add_user_meta(int $id,string $key,mixed $value,bool $unique=false):int|false{if($unique&&!empty($GLOBALS['meta'][$id][$key]))return false;$GLOBALS['meta'][$id][$key][]=$value;return 1;}
 function delete_user_meta(int $id,string $key):bool{unset($GLOBALS['meta'][$id][$key]);return true;}
 function wp_cache_delete(int|string $id,string $group):bool{return true;}
 function clean_user_cache(int $id):void{}
 function wp_doing_ajax():bool{return $GLOBALS['http'];}
 function wp_get_user_request(int $id):mixed{return $GLOBALS['requests'][$id]??false;}
 function get_post_meta(int $id,string $key,bool $single=false):mixed{$rows=$GLOBALS['postmeta'][$id][$key]??array();return $single?($rows[0]??''):$rows;}
 function update_post_meta(int $id,string $key,mixed $value):void{$GLOBALS['postmeta'][$id][$key]=array($value);}
 function delete_post_meta(int $id,string $key):void{unset($GLOBALS['postmeta'][$id][$key]);}
 function wp_update_user(array $values):int{return $values['ID'];}
 function wp_update_post(array $values,bool $error=false):int{return $values['ID'];}
 function is_wp_error(mixed $v):bool{return false;}
 function wp_slash(mixed $value):mixed{return$value;}
 function wp_next_scheduled(string $hook):int{return 1;}
 function wp_schedule_single_event(int $time,string $hook):void{}
 function __(string $value,string $domain):string{return$value;}
 function add_filter(string $hook,callable $fn,int $priority=10,int $count=1):void{$GLOBALS['hooks'][$hook][$priority][]=$fn;}
 function do_action(string $hook,mixed ...$args):void{$groups=$GLOBALS['hooks'][$hook]??array();ksort($groups);foreach($groups as$callbacks)foreach($callbacks as$fn)$fn(...$args);}
 function apply_filters(string $hook,mixed $value,mixed ...$args):mixed{$groups=$GLOBALS['hooks'][$hook]??array();ksort($groups);foreach($groups as$callbacks)foreach($callbacks as$fn)$value=$fn($value,...$args);return$value;}
 function check(bool $ok,string $why):void{++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException($why);}
 function rejects(callable $fn,string $reason):void{try{$fn();}catch(\Throwable $e){check($e->getMessage()===$reason,'unexpected '.$e->getMessage().' expected '.$reason);return;}check(false,'expected rejection '.$reason);}
 require dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/Accounts/Privacy.php';require dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/Operations/Users.php';
 use AISkillsHub\Core\Database\{Database,Migrator};use AISkillsHub\Core\Accounts\{Privacy,Store,Locks,AccountService,Avatar};use AISkillsHub\Core\Operations\Users;
 $db=new Database();$locks=new Locks();$privacy=new Privacy($db,new Store(),$locks,new AccountService(),new Avatar());$operations=new Users($db,new Migrator(),new AccountService(),$locks);
 $GLOBALS['users'][4]=new WP_User(4,'owner@example.test');$GLOBALS['users'][5]=new WP_User(5,'other@example.test');
 $GLOBALS['requests'][81]=(object)array('action_name'=>'remove_personal_data','status'=>'request-confirmed','email'=>'owner@example.test');$GLOBALS['postmeta'][81]['_aish_request_user_id']=array('4');$_POST=array('action'=>'wp-privacy-erase-personal-data','id'=>'81');
 add_filter('aish_account_privacy_isolate',function(int $id):void{add_user_meta($id,'_aish_community_erasing','1',true);});
 add_filter('aish_account_privacy_erase_batch',function(bool $done,int $id):bool{if($done)delete_user_meta($id,'_aish_community_erasing');check(Privacy::isErasingAccount($id),'authorized dispatch holds exact identity');return$done;},10,2);
 add_filter('aish_account_privacy_erase_batch',function(bool $done,int $id)use($operations):bool{check('1'!==get_user_meta($id,'_aish_account_deletion_pending',true),'normal erase is not account deletion');if($done)check(''===get_user_meta($id,'_aish_community_erasing',true),'earlier module can complete its marker before Operations');return$operations->erase($done,$id);},30,2);
 $event=static fn(int $id,string $text):array=>array('subject_type'=>'user','subject_id'=>$id,'actor_user_id'=>1,'metadata_json'=>$text);
 $db->events=array($event(4,'private owner note'),$event(5,'other user note'));
 check(!Privacy::isErasingAccount(4)&&!Privacy::isErasingAccount(5),'no ambient erasure authority');rejects(fn()=>$operations->erase(true,4),'users_forbidden');
 add_user_meta(4,'_aish_account_deletion_pending','1');add_user_meta(4,'_aish_community_erasing','1');rejects(fn()=>$operations->erase(true,4),'users_forbidden');delete_user_meta(4,'_aish_account_deletion_pending');delete_user_meta(4,'_aish_community_erasing');
 check($db->events[0]['subject_id']===4,'forged metadata cannot authorize direct erasure');
 $result=$privacy->eraser('owner@example.test');check($result['done']===true&&$db->events[0]['subject_id']===0&&$db->events[0]['actor_user_id']===null&&$db->events[0]['metadata_json']==='{}','confirmed native request completes after sibling marker removal');check($db->events[1]['subject_id']===5&&$db->events[1]['metadata_json']==='other user note','another account record retained');check(!Privacy::isErasingAccount(4)&&$locks->held===array(),'success revokes context and releases locks');
 rejects(fn()=>$operations->erase(true,4),'users_forbidden');rejects(fn()=>$operations->erase(true,99),'users_forbidden');check(true,'deleted identity does not authorize forged extension dispatch');
 // Forged raw hook invocation carries no authority even if all ID arguments look plausible.
 $saved=$GLOBALS['hooks']['aish_account_privacy_erase_batch'];$GLOBALS['hooks']['aish_account_privacy_erase_batch']=array(30=>array([$operations,'erase']));rejects(fn()=>apply_filters('aish_account_privacy_erase_batch',true,4),'users_forbidden');$GLOBALS['hooks']['aish_account_privacy_erase_batch']=$saved;
 add_filter('aish_account_privacy_erase_batch',function(bool $done,int $id)use($operations):bool{rejects(fn()=>$operations->erase(true,5),'users_forbidden');check(!Privacy::isErasingAccount(5),'active owner cannot select another target');return$done;},20,2);
 $db->events=array_fill(0,60,$event(4,'retained until bounded batch'));$first=$privacy->eraser('owner@example.test');check(!$first['done']&&count(array_filter($db->events,fn($r)=>$r['subject_id']===4))===10,'bounded remaining operations records keep native request pending');check(!Privacy::isErasingAccount(4),'incomplete batch does not leave ambient authority');$second=$privacy->eraser('owner@example.test');check($second['done']&&!array_filter($db->events,fn($r)=>$r['subject_id']===4),'next authorized batch resumes and finishes without leaked marker dependency');
 $db->events=array($event(4,'erase this'));$db->pending=true;$result=$privacy->eraser('owner@example.test');check(!$result['done']&&$db->events[0]['subject_id']===0,'upstream pending state retained even when Operations finishes');$db->pending=false;
 $GLOBALS['hooks']['aish_account_privacy_erase_batch'][40]=array(static function(bool $done,int $id):bool{throw new \RuntimeException('downstream_failure');});rejects(fn()=>$privacy->eraser('owner@example.test'),'downstream_failure');check(!Privacy::isErasingAccount(4)&&$locks->held===array(),'downstream failure always revokes context');rejects(fn()=>$operations->erase(true,4),'users_forbidden');unset($GLOBALS['hooks']['aish_account_privacy_erase_batch'][40]);
 $db->failWrite=true;rejects(fn()=>$privacy->eraser('owner@example.test'),'isolated_failure');check(!Privacy::isErasingAccount(4)&&$locks->held===array(),'Operations SQL failure always revokes context');$db->failWrite=false;
 $GLOBALS['can_erase']=false;rejects(fn()=>$privacy->eraser('owner@example.test'),'privacy_forbidden');$GLOBALS['can_erase']=true;$GLOBALS['postmeta'][81]['_aish_request_user_id']=array('5');rejects(fn()=>$privacy->eraser('owner@example.test'),'privacy_identity_changed');$GLOBALS['postmeta'][81]['_aish_request_user_id']=array('4');$GLOBALS['requests'][81]->status='request-pending';rejects(fn()=>$privacy->eraser('owner@example.test'),'privacy_identity_changed');$GLOBALS['requests'][81]->status='request-confirmed';check(!Privacy::isErasingAccount(4),'failed identity or confirmation never activates dispatch');
 // Native deletion resumes from its durable ID-bound cleanup request without a live user.
 $GLOBALS['http']=false;$_POST=array();$GLOBALS['hooks']['aish_account_privacy_erase_batch']=array(30=>array([$operations,'erase']));$db->events=array($event(17,'deleted account note'));$db->cleanup=array(array('ID'=>82,'user_id'=>17));$privacy->tick();check($db->events[0]['subject_id']===0&&get_post_meta(82,'_aish_cleanup_state',true)==='complete','durable native deletion cleanup still completes in trusted batch context');check(!Privacy::isErasingAccount(17)&&$locks->held===array(),'Cron cleanup releases context and locks');
 echo 'Privacy erasure lifecycle: '.$checks." isolated assertions passed (real account/operations classes, modeled WordPress/storage).\n";
}
