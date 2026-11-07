<?php
declare(strict_types=1);
/** Real homepage services with isolated storage/public-projection doubles; not WP/MySQL QA. */
namespace AISkillsHub\Core\Database {
 final class Migrator { public function isCurrent():bool { return true; } }
 final class Database {
  public array $events=[]; public bool $auditFails=false;
  public function connection():object{return (object)['options'=>'wp_options'];}
  public function table(string $name):string{return 'wp_aish_'.$name;}
  public function value(string $sql,array $args=[]):mixed{return str_contains($sql,'ENGINE')?'InnoDB':1;}
  public function row(string $sql,array $args=[]):?array{return false===$GLOBALS['home_option']?null:['option_value'=>$GLOBALS['home_option']];}
  public function rows(string $sql,array $args=[]):array{return array_reverse($this->events);}
  public function execute(string $sql,array $args=[]):int{$GLOBALS['home_option']=$args[1];return 1;}
  public function insert(string $entity,array $row):int{if($this->auditFails){throw new \RuntimeException('simulated audit failure');}foreach($this->events as$e){if($e['event_key']===$row['event_key']){throw new \RuntimeException('duplicate audit key');}}$this->events[]=$row;return count($this->events);}
  public function transaction(callable $callback):mixed{$old=$GLOBALS['home_option'];$events=$this->events;try{return $callback();}catch(\Throwable $e){$GLOBALS['home_option']=$old;$this->events=$events;throw $e;}}
 }
}
namespace AISkillsHub\Core\Frontend {
 final class PublicCatalog {
  public static array $public=['en_US'=>[9,11],'zh_CN'=>[9]];
  public function __construct(mixed ...$args){}
  public function cardsByIds(array $ids,string $locale):array{return array_map(static fn($id)=>['id'=>$id,'title'=>'Public '.$id],array_values(array_intersect($ids,self::$public[$locale])));}
 }
 final class Editorial {}
 final class Router { public static array $ctx=['route'=>'home','locale'=>'en_US','status'=>200]; public static function context():array{return self::$ctx;} }
 final class Seo { public static array $headers=[]; public static function sendHeaders(array $context):void{self::$headers[]=$context;} }
 final class View { public static function url(string $route,array $args=[],string $locale='en_US'):string{return 'https://example.test/'.('zh_CN'===$locale?'zh':'en').'/';} }
}
namespace AISkillsHub\Core\Discovery {
 final class Query {
  public static array $excluded=[];
  public function __construct(mixed ...$args){}
  public static function excludedSkillIds():array{return self::$excluded;}
  public function topicsByIds(string $kind,array $ids,string $locale):array{return array_map(static fn($id)=>['id'=>$id,'title'=>'Public topic '.$id],array_values(array_intersect($ids,['en_US'=>[201,202],'zh_CN'=>[201]][$locale])));}
 }
}
namespace {
 define('ABSPATH','/isolated/');define('DAY_IN_SECONDS',86400);
 $GLOBALS['home_option']=false;$GLOBALS['home_cap']=true;$GLOBALS['home_nonce_calls']=0;$GLOBALS['home_renders']=[];$checks=0;
 function current_user_can(string $cap):bool{return $GLOBALS['home_cap'];}
 function get_current_user_id():int{return 17;}
 function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['home_option'];}
 function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}
 function wp_strip_all_tags(string $value):string{return strip_tags($value);}
 function wp_cache_delete(string $key,string $group):bool{$GLOBALS['home_cache_deleted'][]=$key;return true;}
 function wp_verify_nonce(string $nonce,string $action):bool{++$GLOBALS['home_nonce_calls'];return $nonce==='session:'.$action;}
 function wp_create_nonce(string $action):string{return 'session:'.$action;}
 function wp_unslash(mixed $value):mixed{return $value;}
 function add_query_arg(array $args,string $url):string{return $url.'?'.http_build_query($args);}
 function nocache_headers():void{}
 function esc_html(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 function esc_url(string $value):string{return esc_html($value);}
 function wp_die(string $message,string $title='',array $args=[]):never{throw new RuntimeException('http_'.$args['response']);}
 function get_template_part(string $name,mixed $variant,array $args):void{$GLOBALS['home_renders'][]=$args;}
 function check(bool $ok,string $message):void{++$GLOBALS['checks'];if(!$ok){throw new RuntimeException($message);}}
 function rejects(callable $callback,string $message,?string $code=null):void{try{$callback();}catch(Throwable $error){check(null===$code||$error->getMessage()===$code,$message.' error');return;}check(false,$message);}
 $base=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/';
 foreach(['Community/Exception','Community/Input','Community/Events','Discovery/Settings','Discovery/Frontend','Discovery/Admin']as$file){require $base.$file.'.php';}
 use AISkillsHub\Core\Discovery\Settings;
 use AISkillsHub\Core\Discovery\Frontend;
 use AISkillsHub\Core\Discovery\Admin;
 use AISkillsHub\Core\Discovery\Query;
 use AISkillsHub\Core\Database\Database;
 use AISkillsHub\Core\Database\Migrator;
 use AISkillsHub\Core\Frontend\PublicCatalog;
 use AISkillsHub\Core\Frontend\Editorial;
 $db=new Database();$migrator=new Migrator();$settings=new Settings($db,$migrator);$data=Settings::defaults();
 foreach($data['home']as&$modules){foreach($modules as&$row){$row['enabled']=false;}unset($row);}unset($modules);
 $index=array_search('collections',Settings::MODULES,true);
 $data['home']['en_US'][$index]=array_replace($data['home']['en_US'][$index],['enabled'=>true,'source'=>'skills','selected_ids'=>[11,9],'editorial_reason'=>'Reviewed for deployment workflows.','title'=>'Editorial <script>alert(1)</script>']);
 $valid=Settings::validate($data);
 check($valid['home']['en_US'][$index]['selected_ids']===[11,9],'stable IDs preserve editorial order');
 check(!str_contains($valid['home']['en_US'][$index]['title'],'<script>'),'configuration strips markup');
 foreach(['trending','most_favorited','top_rated','recently_added','recently_updated']as$key){$bad=$data;$i=array_search($key,Settings::MODULES,true);$bad['home']['en_US'][$i]['source']='skills';$bad['home']['en_US'][$i]['selected_ids']=[9];rejects(fn()=>Settings::validate($bad),'natural rankings cannot be manually overwritten');}
 foreach([[9,9],range(1,13),['1 OR 1=1'],[0],[-2],['09']]as$ids){$bad=$data;$bad['home']['en_US'][$index]['selected_ids']=$ids;rejects(fn()=>Settings::validate($bad),'invalid editorial identity rejected');}
 $bad=$data;$bad['home']['en_US'][$index]['editorial_reason']='';rejects(fn()=>Settings::validate($bad),'manual selection requires public explanation');
 foreach(['2026-02-30 12:00:00','2026-01-01T12:00:00Z','2026-01-01 25:00:00','1969-01-01 00:00:00',[]]as$period){$bad=$data;$bad['home']['en_US'][$index]['starts_at']=$period;rejects(fn()=>Settings::validate($bad),'UTC period strictly validated');}
 $module=$valid['home']['en_US'][$index]+['starts_at'=>'','ends_at'=>''];$module['starts_at']='2030-01-01 00:00:00';$module['ends_at']='2030-02-01 00:00:00';
 check(!Settings::visible($module,'2029-12-31 23:59:59'),'before start hidden');check(Settings::visible($module,'2030-01-01 00:00:00'),'start inclusive');check(!Settings::visible($module,'2030-02-01 00:00:00'),'expiry exclusive');
 $bad=$data;$bad['home']['en_US'][$index]['starts_at']='2030-01-01 00:00:00';$bad['home']['en_US'][$index]['ends_at']='2030-01-01 00:00:00';rejects(fn()=>Settings::validate($bad),'inverted or empty time window rejected');
 $state=$settings->save($data,0,'draft');check($state['revision']===1&&Settings::home('en_US')[$index]['source']==='automatic','draft cannot alter live homepage');
 check(count($db->events)===1&&$db->events[0]['actor_user_id']===17&&$db->events[0]['action']==='home_draft_saved','real audit helper records actor revision and action');
 check(!str_contains($db->events[0]['metadata_json'],'deployment'),'audit stores bounded hashes not public/private copy');
 $before=$GLOBALS['home_option'];$db->auditFails=true;rejects(fn()=>$settings->save($data,1,'publish'),'audit failure aborts configuration transaction');check($before===$GLOBALS['home_option']&&count($db->events)===1,'configuration and audit roll back together');$db->auditFails=false;
 $state=$settings->save($data,1,'publish');check($state['published_revision']===2&&Settings::home('en_US')[$index]['selected_ids']===[11,9],'explicit publish uses reviewed configuration');
 $before=$GLOBALS['home_option'];rejects(fn()=>$settings->save($data,1,'publish'),'stale concurrent publication rejected','conflict');check($before===$GLOBALS['home_option']&&count($db->events)===2,'stale replay adds no audit or live change');
 check(in_array(Settings::OPTION,$GLOBALS['home_cache_deleted'],true)&&in_array('alloptions',$GLOBALS['home_cache_deleted'],true),'native option caches invalidated');
 $data['home']['zh_CN'][$index]=$data['home']['en_US'][$index];$issues=$settings->issues($data);check($issues===[['locale'=>'zh_CN','module'=>'collections','code'=>'unavailable','ids'=>[11]]],'missing translation fails current-locale gate');
 $before=$GLOBALS['home_option'];rejects(fn()=>$settings->save($data,2,'publish'),'unavailable translation blocks publication','home_publication_invalid');check($before===$GLOBALS['home_option'],'invalid publication keeps last good config');
 $state=$settings->save($data,2,'draft');check($state['revision']===3,'unavailable choices can be saved as private draft');
 $frontend=new Frontend(new Query(),new PublicCatalog(),new Editorial());
 $_GET=['aish_home_preview'=>'3','_wpnonce'=>'session:aish_home_preview_3'];$_SERVER['REQUEST_METHOD']='GET';\AISkillsHub\Core\Frontend\Router::$ctx['seo_managed']=false;$frontend->previewHome();
 check(\AISkillsHub\Core\Frontend\Seo::$headers[0]['seo_managed']&&\AISkillsHub\Core\Frontend\Seo::$headers[0]['noindex'],'preview reclaims metadata ownership and suppression when normal pages delegate SEO to a plugin');
 $ctx=$frontend->previewContext(['route'=>'home','canonical'=>'https://example.test/en/','alternates'=>['en_US'=>'https://example.test/en/']]);
 check($ctx['noindex']&&$ctx['seo_managed']&&$ctx['canonical']===''&&!$ctx['seo_alternates']&&!$ctx['alternates'],'private preview never outputs canonical or hreflang');
 ob_start();$frontend->home('zh_CN',[]);$banner=ob_get_clean();check(array_column($GLOBALS['home_renders'][0]['items'],'id')===[9],'private draft preview reuses public language gate and hides untranslated Skill');
 check(str_contains($banner,'English preview')&&str_contains($banner,'修订 3'),'preview is labeled and offers both revision-bound language links');
 $GLOBALS['home_renders']=[];ob_start();$frontend->home('en_US',[]);ob_end_clean();check(array_column($GLOBALS['home_renders'][0]['items'],'id')===[11,9],'preview preserves stable manual selection order');
 PublicCatalog::$public['en_US']=[9];$GLOBALS['home_renders']=[];ob_start();$frontend->home('en_US',[]);ob_end_clean();check(array_column($GLOBALS['home_renders'][0]['items'],'id')===[9],'withdrawal disappears on fresh public projection');
 Query::$excluded=[9];$GLOBALS['home_renders']=[];ob_start();$frontend->home('en_US',[]);ob_end_clean();check(!$GLOBALS['home_renders'][0]['items'],'curation cannot reintroduce excluded fixtures');Query::$excluded=[];
 rejects(fn()=>Settings::preview(2,'session:aish_home_preview_2'),'preview revision invalidation','stale_preview');
 rejects(fn()=>Settings::preview(3,'invalid'),'nonce required','forbidden');$GLOBALS['home_cap']=false;$nonceCalls=$GLOBALS['home_nonce_calls'];rejects(fn()=>Settings::preview(3,'session:aish_home_preview_3'),'subscriber cannot read draft config','forbidden');check($nonceCalls===$GLOBALS['home_nonce_calls'],'capability checked before nonce');
 rejects(fn()=>$settings->history(),'audit read requires capability','forbidden');rejects(fn()=>Frontend::previewUrl('en_US',3),'preview link requires capability','forbidden');$GLOBALS['home_cap']=true;
 $_GET=['aish_home_preview'=>'2','_wpnonce'=>'session:aish_home_preview_2'];rejects(fn()=>(new Frontend(new Query(),new PublicCatalog(),new Editorial()))->previewHome(),'stale preview HTTP409','http_409');
 $_GET=['aish_home_preview'=>['3'],'_wpnonce'=>'bad'];rejects(fn()=>(new Frontend(new Query(),new PublicCatalog(),new Editorial()))->previewHome(),'malformed preview HTTP403','http_403');
 $topic=$data;$topic['home']['zh_CN'][$index]['enabled']=false;$topic['home']['en_US'][$index]['source']='selected';$topic['home']['en_US'][$index]['selected_ids']=[201];check(!$settings->issues($topic),'existing topic beyond index first200 is selectable');
 $topic['home']['en_US'][$index]['selected_ids']=[203];check($settings->issues($topic)[0]['ids']===[203],'unpublished or empty topic unavailable');
 $expired=$data;$expired['home']['zh_CN'][$index]['enabled']=false;$expired['home']['en_US'][$index]['ends_at']='2000-01-01 00:00:00';check($settings->issues($expired)[0]['code']==='expired','expired recommendation warning');rejects(fn()=>$settings->save($expired,3,'publish'),'expired publication blocks until disabled','home_publication_invalid');
 $form=['home_format'=>'2','home_form_complete'=>'2','platform_ids'=>'','home'=>[]];foreach(Settings::LOCALES as$locale){foreach(Settings::defaults()['home'][$locale]as$pos=>$row){$key=$row['key'];unset($row['key']);$form['home'][$locale][$key]=$row+['order'=>(string)$pos];}}
 $form['home']['en_US']['collections']+=['source'=>'skills','selected_ids'=>'9, 11','editorial_reason'=>'Manually reviewed','starts_at'=>'2030-01-01 00:00:00','ends_at'=>'2030-02-01 00:00:00'];
 $payload=Admin::settingsPayload($form);check($payload['home']['en_US'][$index]['selected_ids']===[9,11]&&$payload['home']['en_US'][$index]['ends_at']==='2030-02-01 00:00:00','native form parses bounded selection and UTC periods');
 unset($form['home_form_complete']);rejects(fn()=>Admin::settingsPayload($form),'truncated extended form fails closed','incomplete_home_form');
 echo "Homepage operations: $checks isolated assertions passed; no live WordPress/MySQL/browser claims.\n";
}
