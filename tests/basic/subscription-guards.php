<?php
declare(strict_types=1);
/** Isolated native API boundary contracts. No WordPress or MySQL process is used by this file. */
namespace AISkillsHub\Core\Database {
 final class Database {
  public array $users=array(),$userMid=array(),$postMid=array(),$posts=array(),$bulk=array();public bool $fail=false;
  public function connection():object{return(object)array('usermeta'=>'wp_usermeta','posts'=>'wp_posts','postmeta'=>'wp_postmeta');}
  public function rows(string $sql,array $args=array()):array{if($this->fail)throw new \RuntimeException('private database error');if(str_contains($sql,'FROM wp_usermeta'))return $this->users[$args[0]]??array();throw new \RuntimeException('unexpected rows');}
  public function row(string $sql,array $args=array()):?array{if($this->fail)throw new \RuntimeException('private database error');return str_contains($sql,'wp_usermeta')?($this->userMid[$args[0]]??null):($this->postMid[$args[0]]??null);}
  public function value(string $sql,array $args=array()):mixed{if($this->fail)throw new \RuntimeException('private database error');if(str_contains($sql,'INNER JOIN wp_posts'))return !empty($this->bulk[$args[0]])?1:null;if(str_contains($sql,'WHERE p.ID='))return !empty($this->posts[$args[0]])?$args[0]:null;return $this->posts?1:null;}
 }
}
namespace AISkillsHub\Core\Learning {
 final class Subscriptions {public const META='_aish_subscription';public const OPTION='aish_newsletter_settings';}
 final class SubscriptionCampaigns {public const TYPE='aish_mail_issue';public static bool $active=false;public static function writing():bool{return self::$active;}}
}
namespace {
 define('ABSPATH','/isolated/');$checks=0;$GLOBALS['hooks']=array();$GLOBALS['cache']=array();$GLOBALS['can_export']=false;
 final class WP_Query {}
 final class WP_Post {public function __construct(public int $ID,public string $post_type='post'){}}
 final class WP_Error {public function __construct(public string $code,public string $message,public array $data){}}
 function add_action(string $key,callable $fn,int $priority=10,int $argc=1):void{$GLOBALS['hooks'][$key]=$fn;}
 function add_filter(string $key,callable $fn,int $priority=10,int $argc=1):void{add_action($key,$fn,$priority,$argc);}
 function wp_cache_get(int $id,string $group):mixed{return $GLOBALS['cache'][$group][$id]??false;}
 function get_post_type_object(string $type):object{return(object)array('can_export'=>$GLOBALS['can_export']);}
 function wp_die(string $message,string $title='',array $data=array()):never{throw new \RuntimeException('http_'.($data['response']??500));}
 function check(bool $ok,string $why):void{++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException($why);}
 function rejects(callable $fn,string $reason):void{try{$fn();}catch(\Throwable $e){check($e->getMessage()===$reason,'unexpected denial '.$e->getMessage());return;}check(false,'expected rejection '.$reason);}
 require dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/Learning/SubscriptionGuards.php';
 use AISkillsHub\Core\Learning\{SubscriptionGuards,Subscriptions,SubscriptionCampaigns};use AISkillsHub\Core\Database\Database;
 $db=new Database();$guards=new SubscriptionGuards($db,new Subscriptions());$guards->register();$hooks=$GLOBALS['hooks'];
 foreach(array('add_user_metadata','update_user_metadata','delete_user_metadata','get_user_metadata','get_user_metadata_by_mid','update_user_metadata_by_mid','delete_user_metadata_by_mid','get_post_metadata','get_post_metadata_by_mid','wp_insert_post_empty_content','pre_delete_post','pre_trash_post','pre_untrash_post','export_wp','add_option','delete_option','pre_update_option')as$key)check(isset($hooks[$key]),'native guard registered '.$key);
 foreach(array('_aish_subscription','_aish_subscription_site','_aish_subscription_token_hash','_aish_subscription_future','_AISH_SUBSCRIPTION_SITE')as$key){foreach(array('add_user_metadata','update_user_metadata','delete_user_metadata')as$hook)check(false===$hooks[$hook](null,7,$key,'forged',true),'native user meta mutation cannot forge or remove consent');check(''===$hooks['get_user_metadata'](null,7,$key,true)&&array()===$hooks['get_user_metadata'](null,7,$key,false),'native metadata getter hides credential material');check($hooks['is_protected_meta'](false,$key,'user')===true,'protected user field remains protected');}
 check(null===$guards->userMeta(null,7,'nickname','new')&&null===$guards->readUserMeta(null,7,'nickname',true),'ordinary profile APIs preserved');
 $db->users[7]=array(array('meta_key'=>'nickname','meta_value'=>'Existing'),array('meta_key'=>'custom','meta_value'=>'a:1:{i:0;s:5:"value";}'),array('meta_key'=>'custom','meta_value'=>'second'),array('meta_key'=>'_aish_subscription_site','meta_value'=>'private-consent'),array('meta_key'=>'_aish_subscription_token_hash','meta_value'=>'private-token'));
 $safe=$guards->readUserMeta(null,7,'');check($safe===array('nickname'=>array('Existing'),'custom'=>array('a:1:{i:0;s:5:"value";}','second')),'uncached all-meta preserves unrelated raw/serialized multi values');check($guards->readUserMeta(null,7,'',true)===array($safe),'single all-meta accommodates native array unwrap');
 $GLOBALS['cache']['user_meta'][7]=array('nickname'=>array('Cached'),'_aish_subscription_site'=>array('private'),'roles'=>array('a:1:{}'));$before=$GLOBALS['cache'];$safe=$guards->readUserMeta(null,7,'');check($safe===array('nickname'=>array('Cached'),'roles'=>array('a:1:{}'))&&$before===$GLOBALS['cache'],'read filters a copy without corrupting shared user cache');
 check($guards->readUserMeta(array('other'=>array('kept'),'_aish_subscription'=>array('private')),7,'')===array('other'=>array('kept')),'prior all-meta filter preserved except protected keys');
 $db->userMid[1]=array('meta_key'=>'_aish_subscription_token_x');$db->userMid[2]=array('meta_key'=>'nickname');
 foreach(array('get_user_metadata_by_mid','update_user_metadata_by_mid','delete_user_metadata_by_mid')as$hook){check(false===$hooks[$hook](null,1),'protected metadata ID not readable or writable');check(null===$hooks[$hook](null,2),'normal metadata ID remains native');}
 check(false===$guards->userMetaById(null,2,'forged','_aish_subscription_new')&&false===$guards->userMetaById(null,1,'normal','nickname'),'neither rename direction bypasses protected identity');
 $db->posts=array(41=>true,42=>true);$db->postMid[5]=array('post_id'=>41);$db->postMid[6]=array('post_id'=>9);$query=new WP_Query();
 foreach(array(array('post_type'=>'aish_mail_issue'),array('ID'=>41,'post_type'=>'post'),array('post_type'=>'revision','post_parent'=>41))as$post)check($guards->rejectWrite(false,$post),'issue creation/type change/revision blocked in native editor');check(!$guards->rejectWrite(false,array('ID'=>9,'post_type'=>'post')),'ordinary public editor allowed');
 foreach(array('read_post','edit_post','delete_post','do_not_allow')as$cap)check($guards->caps(array('administrator'),$cap,1,array(41))===array('do_not_allow'),'raw issue object capability denied regardless actor role');check($guards->caps(array('read'),'read_post',1,array(9))===array('read'),'ordinary post capability preserved');
 $where=$guards->lists('WHERE 1=1',$query);check(str_contains($where,"post_type<>'aish_mail_issue'")&&str_contains($where,"newsletter_parent.post_type='aish_mail_issue'"),'all native queries exclude issue and its revisions');
 $counts=(object)array('private'=>3,'publish'=>1);$safe=$guards->counts($counts,'aish_mail_issue','');check($safe->private===0&&$safe->publish===0&&$counts->private===3,'native counts do not reveal private campaigns or mutatecache');
 foreach(array('pre_delete_post','pre_trash_post','pre_untrash_post')as$hook){check(false===$hooks[$hook](null,new WP_Post(41)),'native issue lifecycle blocked');check(null===$hooks[$hook](null,new WP_Post(9)),'ordinary post lifecycle preserved');}
 foreach(array('add_post_metadata','update_post_metadata','delete_post_metadata')as$hook)check(false===$hooks[$hook](null,41,'arbitrary','value'),'issue metadata protected for any key');
 check(''===$guards->readPostMeta(null,41,'private',true)&&array()===$guards->readPostMeta(null,41,'')&&null===$guards->readPostMeta(null,9,'safe',true),'issue metadata never exposed by native readers');
 foreach(array('get_post_metadata_by_mid','update_post_metadata_by_mid','delete_post_metadata_by_mid')as$hook){check(false===$hooks[$hook](null,5),'issue metadata by ID protected');check(null===$hooks[$hook](null,6),'ordinary postmeta by ID preserved');}
 $db->bulk['shared-key']=true;check(false===$guards->deletePostMeta(null,0,'shared-key',null,true),'bulk delete cannot touch protected issue metadata by unchecked IDs');check(null===$guards->deletePostMeta(null,0,'other-key',null,true),'unrelated bulk metadata remains native');
 foreach(array('rest_pre_insert_aish_mail_issue','rest_prepare_aish_mail_issue')as$key){$r=$hooks[$key]();check($r instanceof WP_Error&&$r->data['status']===403,'native REST issue disabled');}
 SubscriptionCampaigns::$active=true;check(!$guards->rejectWrite(false,array('post_type'=>'aish_mail_issue'))&&$guards->lists('WHERE 1=1',$query)==='WHERE 1=1'&&null===$guards->postMeta(null,41,'private','state')&&null===$guards->readPostMeta(null,41,'private',true)&&null===$guards->deletePost(null,new WP_Post(41)),'only native transactional campaign writer may update its issue');check(false===$guards->userMeta(null,7,'_aish_subscription'),'campaign writer cannot forge user consent through metadata');SubscriptionCampaigns::$active=false;
 rejects(fn()=>$guards->export(array('content'=>'aish_mail_issue')),'http_403');$guards->export(array('content'=>'all'));$guards->export(array('content'=>'post'));check(true,'can_export false allows normal WXR all/public export');$GLOBALS['can_export']=true;rejects(fn()=>$guards->export(array('content'=>'all')),'http_403');rejects(fn()=>$guards->export(array('content'=>'revision')),'http_403');
 check($hooks['pre_update_option'](array('enabled'=>true),'aish_newsletter_settings',array('enabled'=>false))===array('enabled'=>false),'native options cannot enable marketing');foreach(array('add_option','delete_option')as$hook)rejects(fn()=>$hooks[$hook]('aish_newsletter_settings'),'subscription_native_option');$guards->optionMutation('unrelated_option');check(true,'other option operations preserved');
 check($hooks['pre_update_option']('forged','AISH_NEWSLETTER_SETTINGS','old')==='old'&&$hooks['pre_update_option']('new','other_option','old')==='new','case variants cannot bypass case-insensitive option storage');foreach(array('add_option','delete_option')as$hook)rejects(fn()=>$hooks[$hook]('AISH_NEWSLETTER_SETTINGS'),'subscription_native_option');
 $GLOBALS['cache']['posts'][9]=new WP_Post(9,'post');$db->fail=true;check($guards->readPostMeta(null,9,'normal',true)===null,'ordinary primed post metadata needs no extra type lookup');$db->fail=false;unset($GLOBALS['cache']['posts'][9]);
 $guards->clearPostTypes();$db->fail=true;unset($GLOBALS['cache']['user_meta'][7]);check($guards->readUserMeta(null,7,'')===array()&&false===$guards->userMetaById(null,2)&&$guards->rejectWrite(false,array('ID'=>9))&&false===$guards->deletePost(null,new WP_Post(41)),'unknown storage cannot expose or mutate protected records');
 $db->fail=false;$guards->clearPostTypes();$db->posts[9]=false;check($guards->readPostMeta(null,9,'a',true)===null,'uncached ordinary post classified');$db->fail=true;check($guards->readPostMeta(null,9,'b',true)===null,'second native metadata read uses request-local classification');$db->fail=false;$db->posts[9]=true;$hooks['post_updated']();check($guards->readPostMeta(null,9,'private',true)==='','native post update invalidates previous negative classification');
 $db->posts[9]=false;$hooks['clean_post_cache']();$GLOBALS['cache']['posts'][9]=(object)array('ID'=>9,'post_type'=>'post');$db->fail=true;check($guards->readPostMeta(null,9,'a',true)===null,'native raw stdClass postcache also avoids N+1 lookups');$db->fail=false;
 echo 'Subscription native guards: '.$checks." isolated assertions passed (modeled WordPress/storage; no real integration).\n";
}
