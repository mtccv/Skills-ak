<?php
declare(strict_types=1);
/** Isolated preference/auth/render contracts. Does not replace WordPress/MySQL concurrency tests. */
namespace AISkillsHub\Core\Accounts {
 final class AccountService {
  public int $id=17;public bool $allowed=true;public array $requirements=array();
  public function requireOwner(bool $verified=true):\WP_User{$this->requirements[]=$verified;if(!$this->allowed)throw new AccountException('account_suspended');return new \WP_User($this->id);}
 }
 final class Locks {
  public array $users=array();public mixed $before=null;public int $released=0;
  public function withUser(int $id,callable $fn):mixed{$this->users[]=$id;try{if($this->before)($this->before)();return $fn();}finally{++$this->released;}}
 }
 final class Frontend {public static function url(string $page,string $locale):string{return 'https://hub.test/'.('zh_CN'===$locale?'zh':'en').'/account/'.$page.'/';}}
}
namespace AISkillsHub\Core\Database {
 final class Database {
  public array $meta=array(),$queries=array();public int $writes=0;public bool $fail=false;
  public function connection():object{return (object)array('usermeta'=>'wp_usermeta');}
  public function rows(string $sql,array $args=array()):array{$this->queries[]=array($sql,$args);if($this->fail)throw new \RuntimeException('private SQL unavailable');return array_map(fn($v)=>array('meta_value'=>$v),$this->meta[$args[0]]??array());}
  public function value(string $sql,array $args=array()):mixed{$this->queries[]=array($sql,$args);return empty($this->meta[$args[0]])?null:1;}
  public function execute(string $sql,array $args=array()):int{$this->queries[]=array($sql,$args);++$this->writes;if(str_starts_with($sql,'DELETE'))unset($this->meta[$args[0]]);elseif(str_starts_with($sql,'UPDATE'))$this->meta[$args[1]]=array($args[0]);elseif(str_starts_with($sql,'INSERT'))$this->meta[$args[0]]=array($args[2]);else throw new \RuntimeException('unexpected query');if($this->fail)throw new \RuntimeException('private SQL unavailable');return 1;}
  public function transaction(callable $fn):mixed{$old=$this->meta;try{return $fn();}catch(\Throwable $e){$this->meta=$old;throw $e;}}
 }
}
namespace AISkillsHub\Core\Frontend {final class Router {public static function siteUrl(string $path=''):string{return 'https://hub.test'.$path;}}}
namespace {
 define('ABSPATH','/isolated/');$count=0;$GLOBALS['hooks']=array();$GLOBALS['signed']=true;$GLOBALS['admin']=false;$GLOBALS['caps']=array();$GLOBALS['rendered']=array();$GLOBALS['invalidations']=array();
 final class WP_User {public function __construct(public int $ID){}}
 function add_action(string $name,callable $callback,int $priority=10,int $args=1):void{$GLOBALS['hooks'][$name]=$callback;}
 function add_filter(string $name,callable $callback,int $priority=10,int $args=1):void{add_action($name,$callback,$priority,$args);}
 function current_user_can(string $cap):bool{return !empty($GLOBALS['caps'][$cap]);}
 function wp_json_encode(mixed $data):string{return json_encode($data,JSON_THROW_ON_ERROR);}
 function wp_cache_delete(int|string $id,string $group):bool{$GLOBALS['invalidations'][]=array($id,$group);return true;}
 function wp_verify_nonce(string $value,string $action):bool{return 'valid'===$value&&'aish_onboarding'===$action;}
 function wp_parse_url(string $url):array|false{return parse_url($url);}
 function is_user_logged_in():bool{return $GLOBALS['signed'];}
 function is_admin():bool{return $GLOBALS['admin'];}
 function get_template_part(string $part,mixed $name,array $args):void{$GLOBALS['rendered'][]=array($part,$args);}
 function check(bool $value,string $why):void{++$GLOBALS['count'];if(!$value)throw new \RuntimeException($why);}
 function rejects(callable $fn,string $reason):void{try{$fn();}catch(\Throwable $e){check($e->getMessage()===$reason,'unexpected error: '.$e->getMessage());return;}check(false,'expected rejection: '.$reason);}
 $root=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/';require $root.'inc/core/Accounts/AccountException.php';require $root.'inc/core/Learning/Onboarding.php';
 use AISkillsHub\Core\Learning\Onboarding;use AISkillsHub\Core\Accounts\{AccountService,Locks};use AISkillsHub\Core\Database\Database;
 $db=new Database();$accounts=new AccountService();$locks=new Locks();$service=new Onboarding($db,$accounts,$locks);$service->register();
 $empty=array('version'=>1,'completed'=>array());check($service->mine()===$empty,'empty read does not persist');check($db->writes===0,'no automatic completion');
 check(isset($GLOBALS['hooks']['wp_ajax_aish_onboarding'],$GLOBALS['hooks']['admin_post_aish_onboarding'])&&!isset($GLOBALS['hooks']['wp_ajax_nopriv_aish_onboarding']),'only authenticated state endpoint');
 $welcome=$service->complete('welcome');check($welcome===array('version'=>1,'completed'=>array('welcome')),'bounded step saved');$writes=$db->writes;
 check($service->complete('welcome')===$welcome&&$db->writes===$writes,'repeat success is idempotent');
 check($service->complete('favorite')['completed']===array('welcome','favorite'),'later step merges existing state');
 check(count($db->meta[17])===1&&$locks->users===array(17,17,17)&&$locks->released===3,'shared owner lock and one metadata row');
 check(in_array(false,$accounts->requirements,true)&&!in_array(true,$accounts->requirements,true),'basic help does not require verification or change eligibility');
 check(count(array_filter($db->queries,fn($q)=>str_contains($q[0],'FOR UPDATE')))===3,'mutations read fresh locked state');
 check(array_keys(json_decode($db->meta[17][0],true))===array('version','completed')&&!str_contains($db->meta[17][0],'timestamp'),'no identity or behavioral history');
 $before=$db->meta;rejects(fn()=>$service->complete('verified;admin'),'onboarding_input');check($db->meta===$before,'unknown step no write');
 $accounts->id=23;check($service->mine()===$empty,'other current account sees only own state');$service->complete('search');check($db->meta[17]===$before[17],'another account cannot change prior flags');
 $accounts->allowed=false;rejects(fn()=>$service->mine(),'account_suspended');rejects(fn()=>$service->complete('account'),'account_suspended');$accounts->allowed=true;
 $locks->before=function()use($accounts):void{$accounts->id=99;};rejects(fn()=>$service->complete('account'),'onboarding_forbidden');check(!isset($db->meta[99]),'identity rechecked inside shared lock');$locks->before=null;$accounts->id=23;
 $db->fail=true;rejects(fn()=>$service->complete('account'),'private SQL unavailable');check(json_decode($db->meta[23][0],true)['completed']===array('search'),'storage failure preserves prior state');$db->fail=false;
 $_SERVER=array('REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>'https://hub.test');$payload=array('action'=>'aish_onboarding','operation'=>'dismiss','step'=>'review','locale'=>'en_US','_wpnonce'=>'valid');
 check($service->request($payload)['completed']===array('search','review'),'valid self request');
 foreach(array(array('user_id'=>17),array('_wpnonce'=>'invalid'))as$bad)rejects(fn()=>$service->request(array_replace($payload,$bad)),'onboarding_forbidden');
 foreach(array(array('locale'=>'de_DE'),array('operation'=>'publish'),array('step'=>array('review')),array('operation'=>'reset','step'=>'review'))as$bad)rejects(fn()=>$service->request(array_replace($payload,$bad)),'onboarding_input');
 $_SERVER['REQUEST_METHOD']='GET';rejects(fn()=>$service->request($payload),'onboarding_method');$_SERVER['REQUEST_METHOD']='POST';
 foreach(array('https://foreign.test','http://hub.test','https://hub.test:444','https://secret@hub.test','https://hub.test.evil')as$origin){$_SERVER['HTTP_ORIGIN']=$origin;rejects(fn()=>$service->request($payload),'onboarding_forbidden');}$_SERVER['HTTP_ORIGIN']='https://hub.test';
 $reset=$payload;unset($reset['step']);$reset['operation']='reset';check($service->request($reset)===$empty&&!isset($db->meta[23]),'reset removes only current flags');check(isset($db->meta[17]),'reset cannot erase another account');$writes=$db->writes;check($service->reset()===$empty&&$db->writes===$writes,'empty reset idempotent');
 foreach(array('{','{"version":2,"completed":[]}','{"version":1,"completed":["admin"]}','{"version":1,"completed":[],"query":"private"}',str_repeat('x',257))as$bad){$db->meta[23]=array($bad);rejects(fn()=>$service->mine(),'onboarding_storage');}unset($db->meta[23]);
 $db->meta[23]=array(wp_json_encode($empty),wp_json_encode($empty));rejects(fn()=>$service->mine(),'onboarding_storage');unset($db->meta[23]);
 $_GET=array();$service->renderContext('home','en_US');check(end($GLOBALS['rendered'])[1]['step']==='welcome','home optional welcome');
 $service->renderContext('account','en_US',array('message'=>'registration'));check(end($GLOBALS['rendered'])[1]['step']==='registration','trusted registration flash guidance');
 $service->renderContext('account','zh_CN',array('message'=>'verified'));check(end($GLOBALS['rendered'])[1]['step']==='verified','verified feedback only server context');
 $n=count($GLOBALS['rendered']);$service->renderContext('community','en_US',array('message'=>'favorite_removed'));check(count($GLOBALS['rendered'])===$n,'favorite removal is not success guidance');$service->renderContext('community','en_US',array('message'=>'favorite_saved'));check(end($GLOBALS['rendered'])[1]['step']==='favorite','actual signed favorite success guidance');
 $service->renderContext('community','en_US',array('route'=>'review','head'=>null));check(end($GLOBALS['rendered'])[1]['step']==='review','first review explanation');$n=count($GLOBALS['rendered']);$service->renderContext('community','en_US',array('route'=>'review','head'=>array('id'=>1)));check(count($GLOBALS['rendered'])===$n,'edit does not pretend first review');
 $service->renderContext('submissions','zh_CN',array('route'=>'submit'));check(end($GLOBALS['rendered'])[1]['step']==='submission','existing submission workflow guidance');$service->renderContext('account','en_US',array('route'=>'account'));check(end($GLOBALS['rendered'])[1]['step']==='account','account intro');
 $service->complete('welcome');$n=count($GLOBALS['rendered']);$service->renderContext('home','zh_CN');check(count($GLOBALS['rendered'])===$n,'completion suppresses repeat across languages');
 foreach(array(array('error'=>'bad'),array('status'=>403))as$context)$service->renderContext('listing','en_US',$context);$_GET['aish_preview']='private';$service->renderContext('listing','en_US');unset($_GET['aish_preview']);$GLOBALS['admin']=true;$service->renderContext('listing','en_US');$GLOBALS['admin']=false;check(count($GLOBALS['rendered'])===$n,'errors private previews admin do not render');
 $GLOBALS['signed']=false;$writes=$db->writes;$service->renderContext('home','zh_CN');check(end($GLOBALS['rendered'])[1]['signed_in']===false&&$db->writes===$writes,'guest device-only no account persistence');$GLOBALS['signed']=true;
 $export=$GLOBALS['hooks']['aish_account_privacy_export'];$result=array('data'=>array(),'done'=>true);rejects(fn()=>$export($result,new WP_User(17),1),'onboarding_forbidden');$GLOBALS['caps']['export_others_personal_data']=true;
 $result=$export($result,new WP_User(17),1);check($result['data'][0]['data'][0]['value']==='welcome, favorite','native authorized export uses bound request owner');check($export($result,new WP_User(17),2)===$result,'subsequent native export pages do not duplicate');
 $erase=$GLOBALS['hooks']['aish_account_privacy_erase_batch'];check($erase(false,17)===false&&!isset($db->meta[17])&&isset($db->meta[23]),'native cleanup deletes only target metadata preserves batch status');
 foreach(Onboarding::STEPS as$step)foreach(array('zh_CN','en_US')as$locale){$copy=Onboarding::copy($step,$locale);check(count($copy)===2&&strlen($copy[0])>0&&strlen($copy[1])>0,'separate complete bilingual introduction');}
 check(str_contains(Onboarding::copy('registration','en_US')[1],'If this address can be registered'),'registration anti-enumeration maintained');check(str_contains(Onboarding::copy('verified','en_US')[1],'never subscribes'),'verification not marketing consent');
 echo 'Onboarding isolated assertions: '.$count." passed (stubbed WordPress/storage; not real integration).\n";
}
