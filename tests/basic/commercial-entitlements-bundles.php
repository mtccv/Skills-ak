<?php
declare(strict_types=1);
/** Isolated free-boundary and bundle curation contracts; not WordPress/MySQL integration. */
namespace AISkillsHub\Core\Accounts {
 final class AccountService {
  public bool $owner=true;public bool $verified=true;public array $calls=array();
  public function requireOwner(bool $verified=true):object{$this->calls[]=$verified;if(!$this->owner||($verified&&!$this->verified)){throw new \RuntimeException('private account context');}return (object)array('ID'=>17);}
 }
}
namespace AISkillsHub\Core\Database {
 final class Migrator {public bool $current=true;public function isCurrent():bool{return $this->current;}}
 final class Database {
  public mixed $option=null; public array $events=array();public bool $failAudit=false,$erasing=false,$fail=false;public string $engine='InnoDB';public array $collections=array(1,2);public array $queries=array();
  public function connection():object{return (object)array('options'=>'wp_options','usermeta'=>'wp_usermeta');}
  public function table(string $name):string{return 'wp_aish_'.$name;}
  public function value(string $sql,array $args=array()):mixed{$this->queries[]=array($sql,$args);if($this->fail)throw new \RuntimeException('private sql contents');if(str_contains($sql,'information_schema'))return $this->engine;if(str_contains($sql,'umeta_id'))return $this->erasing?31:null;return $this->option;}
  public function row(string $sql,array $args=array()):?array{return $this->option===null?null:array('option_value'=>$this->option);}
  public function rows(string $sql,array $args=array()):array{$this->queries[]=array($sql,$args);if(str_contains($sql,'editorial_collections'))return array_map(fn($id)=>array('id'=>$id),array_values(array_intersect($args,$this->collections)));return $this->events;}
  public function execute(string $sql,array $args=array()):int{$this->queries[]=array($sql,$args);if(str_starts_with($sql,'INSERT INTO')){$this->option??=$args[1];}elseif(str_starts_with($sql,'UPDATE')){$this->option=$args[0];}return 1;}
  public function insert(string $name,array $data):int{if($this->failAudit)throw new \RuntimeException('audit failure');if($name!=='workflow_events')throw new \RuntimeException('unexpected entity write');$this->events[]=$data;return count($this->events);}
  public function transaction(callable $fn):mixed{$old=$this->option;$events=$this->events;try{return $fn();}catch(\Throwable $e){$this->option=$old;$this->events=$events;throw $e;}}
 }
}
namespace AISkillsHub\Core\Discovery {
 final class Query {
  public array $available=array('en_US'=>array(1),'zh_CN'=>array());public array $calls=array();
  public function topicsByIds(string $kind,array $ids,string $locale):array{$this->calls[]=array($kind,$ids,$locale);return array_map(fn($id)=>array('id'=>$id,'title'=>'<Unsafe> title','description'=>'Actual copy','slug'=>'bundle-'.$id,'count'=>1),array_values(array_intersect($ids,$this->available[$locale]??array())));}
 }
}
namespace {
 define('ABSPATH','/isolated/');define('DAY_IN_SECONDS',86400);$GLOBALS['caps']=array();$count=0;
 function get_current_user_id():int{return 11;}
 function current_user_can(string $cap):bool{return !empty($GLOBALS['caps'][$cap]);}
 function wp_json_encode(mixed $data,int $flags=0):string{return json_encode($data,$flags|JSON_THROW_ON_ERROR);}
 function wp_strip_all_tags(string $s):string{return strip_tags($s);}
 function wp_cache_delete(string|int $key,string $group):bool{return true;}
 function esc_html(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 function get_template_part(string $part,mixed $name,array $args):void{echo '<article>'.esc_html($args['topic']['title']).'</article>';}
 function add_action(string $hook,callable $fn,int $priority=10,int $count=1):void{}
 function wp_unslash(mixed $v):mixed{return is_string($v)?stripslashes($v):$v;}
 function get_user_locale():string{return 'en_US';}
 function wp_verify_nonce(string $value,string $action):bool{return $value==='valid'&&$action==='aish_bundle_save';}
 function wp_die(string $message,string $title='',array $args=array()):never{throw new \RuntimeException('http_'.($args['response']??500));}
 function admin_url(string $path):string{return 'https://example.test/wp-admin/'.$path;}
 function add_query_arg(array $args,string $url):string{return $url.'?'.http_build_query($args);}
 function set_transient(string $key,mixed $value,int $ttl):bool{$GLOBALS['notice']=$value;return true;}
 final class RedirectReached extends \RuntimeException {public function __construct(public string $url,public int $status){parent::__construct('redirect');}}
 function wp_safe_redirect(string $url,int $status=302):never{throw new RedirectReached($url,$status);}
 function check(bool $ok,string $why):void{++$GLOBALS['count'];if(!$ok)throw new \RuntimeException($why);}
 function rejects(callable $fn,string $why):void{try{$fn();}catch(\Throwable $e){check(true,$why);return;}check(false,$why);}
 $base=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/';foreach(array('Community/Exception.php','Community/Input.php','Community/Events.php','Discovery/Settings.php','Commercial/Entitlements.php','Commercial/Bundles.php','Commercial/BundleFrontend.php','Admin/ContributionUi.php','Commercial/BundleAdmin.php')as$file)require $base.$file;
 use AISkillsHub\Core\Accounts\AccountService;use AISkillsHub\Core\Database\Database;use AISkillsHub\Core\Database\Migrator;use AISkillsHub\Core\Discovery\Query;use AISkillsHub\Core\Commercial\Entitlements;use AISkillsHub\Core\Commercial\Bundles;use AISkillsHub\Core\Commercial\BundleFrontend;
 $db=new Database();$account=new AccountService();$entitlements=new Entitlements($account,$db);
 foreach(array('browse','search')as$feature){check($entitlements->eligibility($feature)['eligible'],'public free feature');}
 check($account->calls===array(),'public availability never depends on account subscription or identity');
 foreach(array('favorite','review','submit','profile_edit')as$feature){check($entitlements->eligibility($feature)['eligible'],'verified free feature');check(end($account->calls)===true,'write scope delegates existing verified owner check');}
 foreach(array('profile_read','account_security')as$feature){$account->verified=false;check($entitlements->eligibility($feature)['eligible'],'unverified own recovery remains');check(end($account->calls)===false,'existing self-service recovery boundary retained');}
 foreach(array('favorite','review','submit','profile_edit')as$feature){check(!$entitlements->eligibility($feature)['eligible'],'unverified community denied');}
 $account->verified=true;$account->owner=false;check(!$entitlements->eligibility('profile_read')['eligible'],'no other-user/no-login access');check($entitlements->eligibility('browse')['eligible'],'suspended account does not remove visitor reading');
 $account->owner=true;$db->erasing=true;check(!$entitlements->eligibility('review')['eligible'],'privacy cleanup denied');$db->erasing=false;$db->fail=true;check(!$entitlements->eligibility('review')['eligible'],'storage failure closed');$db->fail=false;
 foreach(array('manage_options','administrator','paid','favorite?user_id=42','review;grant=all')as$feature){check(!$entitlements->eligibility($feature)['eligible'],'no dynamic grant feature');check(!$entitlements->manages($feature),'no arbitrary management capability');}
 $GLOBALS['caps']['aish_manage_discovery']=true;check($entitlements->manages('discovery'),'fixed management area consults WordPress');check(!$entitlements->manages('sponsors'),'discovery cannot manage sponsored content');check($entitlements->eligibility('review')['object_authorization_required']===true,'eligibility never replaces object gate');
 $migrator=new Migrator();$query=new Query();$bundles=new Bundles($db,$migrator,$query);$front=new BundleFrontend($bundles);
 check($bundles->publicEntries('en_US')===array()&&$db->option===null,'defaults no public entries and no persistence');check($query->calls===array(),'default off does not fetch public collections');ob_start();$front->directory('en_US');check(ob_get_clean()==='','no empty public section');
 $data=array('state'=>'enabled','collection_ids'=>array(1),'reason'=>'Reviewed actual collection');
 foreach(array(array('state'=>'paid'),array('price'=>99),array('collection_ids'=>array(1,1)),array('collection_ids'=>range(1,13)),array('collection_ids'=>array('1e1')),array('collection_ids'=>array()),array('reason'=>"bad\0input"))as$bad){rejects(fn()=>Bundles::validate(array_replace($data,$bad)),'bounded whitelist validation');}
 $GLOBALS['caps']=array();rejects(fn()=>$bundles->configuration(),'private configuration permission');rejects(fn()=>$bundles->save($data,0,true),'cannot write without permission');rejects(fn()=>$bundles->history(),'audit permission');$GLOBALS['caps']['aish_manage_discovery']=true;
 rejects(fn()=>$bundles->save($data,0,false),'explicit review required');rejects(fn()=>$bundles->save(array_replace($data,array('reason'=>'')),0,true),'change reason required');
 $r=$bundles->save($data,0,true);check($r['revision']===1&&count($db->events)===1,'first save + audit commit');check($db->events[0]['subject_type']==='bundle_configuration','separate audit type');check($bundles->publicEntries('en_US')[0]['id']===1,'same existing collection identity');check($bundles->publicEntries('zh_CN')===array(),'missing translation not fabricated');
 $again=$bundles->save($data,0,true);check($again===$r&&count($db->events)===1,'replayed unchanged request no duplicate audit');rejects(fn()=>$bundles->save(array_replace($data,array('reason'=>'Another payload')),0,true),'stale revision cannot overwrite');
 ob_start();$front->directory('en_US');$html=ob_get_clean();check(str_contains($html,'Free resource bundles')&&!str_contains($html,'<Unsafe>'),'real collection component escaped');ob_start();$front->notice(1,'en_US');check(str_contains(ob_get_clean(),'commercial redistribution rights'),'bundle does not infer licensing');ob_start();$front->notice(2,'en_US');check(ob_get_clean()==='','unconfigured collection unlabelled');
 $old=$db->option;rejects(fn()=>$bundles->save(array_replace($data,array('collection_ids'=>array(2))),1,true),'unpublished collection cannot enable');check($db->option===$old&&count($db->events)===1,'failed validation rolls back');rejects(fn()=>$bundles->save(array_replace($data,array('collection_ids'=>array(999))),1,true),'missing collection cannot be configured');
 $query->available['en_US']=array();check($bundles->publicEntries('en_US')===array(),'collection/member withdrawal immediately hides entry');ob_start();$front->notice(1,'en_US');check(ob_get_clean()==='','no stale bundle label after withdrawal');$query->available['en_US']=array(1);
 $paused=array_replace($data,array('state'=>'paused','reason'=>'Pause curation'));$db->failAudit=true;rejects(fn()=>$bundles->save($paused,1,true),'audit error rolls back');check($db->option===$old,'audit rollback preserves configuration');$db->failAudit=false;
 $db->engine='MyISAM';rejects(fn()=>$bundles->save($paused,1,true),'nontransactional native options rejected');$db->engine='InnoDB';$r=$bundles->save($paused,1,true);check($r['revision']===2&&$bundles->publicEntries('en_US')===array(),'explicit pause hides entry');
 $migrator->current=false;rejects(fn()=>$bundles->configuration(),'unknown schema denied');check($bundles->publicEntries('en_US')===array(),'unknown schema not published');$migrator->current=true;$db->option='{"state":"enabled","collection_ids":[1]}';check($bundles->publicEntries('en_US')===array(),'corrupt configuration fails closed');
 check(!str_contains(file_get_contents($base.'Commercial/Entitlements.php'),'apply_filters('),'no externally overridable positive entitlement grant');
 $db->option=wp_json_encode($r);$admin=new \AISkillsHub\Core\Commercial\BundleAdmin($bundles);$eventCount=count($db->events);
 $_POST=array('_wpnonce'=>'valid','revision'=>'2','confirmed'=>'1','state'=>'enabled','collection_ids'=>'1','reason'=>'Public collection rechecked');$_SERVER['REQUEST_METHOD']='GET';rejects(fn()=>$admin->handle(),'configuration mutation rejects GET');check(count($db->events)===$eventCount,'GET has no side effects');
 $_SERVER['REQUEST_METHOD']='POST';$_POST['_wpnonce']='invalid';rejects(fn()=>$admin->handle(),'nonce required');check(count($db->events)===$eventCount,'bad nonce no audit or write');$_POST['_wpnonce']='valid';$GLOBALS['caps']=array();rejects(fn()=>$admin->handle(),'valid nonce cannot replace capability');check(count($db->events)===$eventCount,'unauthorized no change');$GLOBALS['caps']['aish_manage_discovery']=true;
 try{$admin->handle();}catch(RedirectReached $redirect){check($redirect->status===303&&!str_contains($redirect->url,'reason'),'fixed safe post-redirect destination');}
 check(($GLOBALS['notice']['success']??0)===1&&count($db->events)===$eventCount+1,'authorized POST uses audited service');
 $_POST['revision']='3';$_POST['collection_ids']='1,2';try{$admin->handle();}catch(RedirectReached $redirect){}check(($GLOBALS['notice']['failed']??0)===1,'unavailable ID reports failure');check(count($db->events)===$eventCount+1,'invalid POST no extra event');
 echo 'Commercial free entitlement/bundle isolated assertions: '.$count." passed\n";
}
