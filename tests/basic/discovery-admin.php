<?php
declare(strict_types=1);
/** Isolated settings/CAS and native admin boundary contracts, not real WordPress/MySQL. */
namespace AISkillsHub\Core\Database {
 final class Migrator { public bool $current=true; public function isCurrent(): bool { return $this->current; } }
 final class Database {
  public bool $fail=false; public array $events=array(); public array $tags=array(7=>array('slug'=>'platform-cli','is_archived'=>0),8=>array('slug'=>'general','is_archived'=>0),9=>array('slug'=>'platform-old','is_archived'=>1)); public array $queries=array();
  public function connection(): object { return (object)array('options'=>'wp_options'); }
  public function table(string $entity): string { return 'wp_aish_'.$entity; }
  public function value(string $sql,array $values=array()): mixed { $this->queries[]=$sql; return str_contains($sql,'ENGINE')?'InnoDB':1; }
  public function row(string $sql,array $values=array()): ?array { $this->queries[]=$sql; return str_contains($sql,'option_value')?(false===$GLOBALS['option']?null:array('option_value'=>$GLOBALS['option'])):($this->tags[$values[0]]??null); }
  public function rows(string $sql,array $values=array()): array { return array(); }
  public function execute(string $sql,array $values=array()): int { $this->queries[]=$sql; if($this->fail){throw new \RuntimeException('simulated secret database error');} $GLOBALS['option']=$values[1];return 1; }
  public function insert(string $entity,array $row):int{$this->events[]=$row;return count($this->events);}
  public function transaction(callable $callback): mixed { $old=$GLOBALS['option'];try{return $callback();}catch(\Throwable $e){$GLOBALS['option']=$old;throw $e;} }
 }
}
namespace AISkillsHub\Core\Discovery {
 final class Management {
  public array $calls=array(); public array $listed=array('rows'=>array(array('id'=>3,'status'=>'draft','revision'=>4,'sort_order'=>0,'translations'=>array('en_US'=>array('title'=>'<script>x</script>')))),'total'=>1);
  public static function termDigest(array $rows): string { return hash('sha256',json_encode($rows)); }
  public function list(string $kind,array $filters): array {$this->calls[]=array('list',$kind,$filters);return $this->listed;}
  public function detail(string $kind,int $id): array {return $this->listed['rows'][0]+array('skills'=>array());}
  public function termTranslations(string $kind,int $id): array {return array('en_US'=>array('name'=>'Tools','description'=>'Original'));}
  public function saveTerm(string $kind,int $id,array $translations,string $digest): void {$this->calls[]=array('term',$kind,$id,$translations,$digest);}
  public function save(string $kind,int $id,array $payload,int $revision): array {$this->calls[]=array('save',$kind,$id,$payload,$revision);return array('id'=>3);}
  public function archive(string $kind,int $id,int $revision): array {$this->calls[]=array('archive',$kind,$id,$revision);return array('id'=>$id);}
 }
}
namespace {
 define('ABSPATH','/isolated-wordpress/'); define('DAY_IN_SECONDS',86400); $GLOBALS['caps']=array();$GLOBALS['option']=false;$GLOBALS['hooks']=array();$GLOBALS['menus']=array();$GLOBALS['notice']='';$count=0;
 function current_user_can(string $cap):bool{return !empty($GLOBALS['caps'][$cap]);}
 function get_current_user_id():int{return 31;}
 function get_user_locale():string{return 'en_US';}
 function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['option'];}
 function wp_strip_all_tags(string $v):string{return strip_tags($v);}
 function wp_parse_url(string $v):array|false{return parse_url($v);}
 function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
 function wp_cache_delete(string $key,string $group):bool{return true;}
 function wp_unslash(mixed $v):mixed{return is_array($v)?array_map('wp_unslash',$v):(is_string($v)?stripslashes($v):$v);}
 function esc_html(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 function esc_attr(string $v):string{return esc_html($v);}
 function esc_textarea(string $v):string{return esc_html($v);}
 function esc_url(string $v):string{return esc_html($v);}
 function selected(mixed $a,mixed $b,bool $echo=false):string{return $a===$b?' selected':'';}
 function checked(mixed $a,mixed $b,bool $echo=false):string{return $a===$b?' checked':'';}
 function add_action(string $name,callable $fn):void{$GLOBALS['hooks'][$name][]=$fn;}
 function add_submenu_page(string $parent,string $title,string $menu,string $cap,string $slug,callable $fn):void{$GLOBALS['menus'][]=compact('parent','cap','slug');}
 function admin_url(string $path):string{return 'https://example.test/wp-admin/'.$path;}
 function add_query_arg(array $args,string $url):string{return $url.'?'.http_build_query($args);}
 function wp_nonce_field(string $action):void{echo '<input name="_wpnonce" value="fixture">';}
 function check_admin_referer(string $action):void{if('fixture'!==($_POST['_wpnonce']??'')){throw new RuntimeException('nonce');}}
 function wp_die(string $message,string $title='',array $args=array()):never{throw new RuntimeException('http_'.($args['response']??500));}
 function get_transient(string $key):bool{return false;}
 function delete_transient(string $key):bool{return true;}
 function set_transient(string $key,mixed $value,int $expiry):bool{$GLOBALS['notice']=$value;return true;}
 final class RedirectReached extends RuntimeException {public function __construct(public string $url,public int $status){parent::__construct('redirect');}}
 function wp_safe_redirect(string $url,int $status=302):never{throw new RedirectReached($url,$status);}
 function check(bool $ok,string $message):void{++$GLOBALS['count'];if(!$ok){throw new RuntimeException($message);}}
 function rejects(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){check(true,$message);return;}check(false,$message);}
 function dispatch(object $admin,array $post):void{$_POST=$post+array('_wpnonce'=>'fixture','confirm'=>'1','revision'=>'0','id'=>'0','operation'=>'save');try{$admin->handle();}catch(RedirectReached $r){check($r->status===303,'POST redirects with303');}}
 $base=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/';require $base.'Admin/ContributionUi.php';require $base.'Community/Exception.php';require $base.'Community/Input.php';require $base.'Community/Events.php';require $base.'Discovery/Settings.php';require $base.'Discovery/Admin.php';
 use AISkillsHub\Core\Discovery\Settings;use AISkillsHub\Core\Discovery\Admin;use AISkillsHub\Core\Discovery\Management;use AISkillsHub\Core\Database\Database;use AISkillsHub\Core\Database\Migrator;
 $db=new Database();$migrator=new Migrator();$settings=new Settings($db,$migrator);$management=new Management();$admin=new Admin($management,$settings,$db);$defaults=Settings::defaults();
 check(count(Settings::home('en_US'))===10&&!Settings::tracking(),'bounded real-data modules and tracking off by default');
 rejects(fn()=>Settings::home('fr_FR'),'unsupportedlocale');
 $bad=$defaults;$bad['home']['zh_CN'][0]['key']='sql';rejects(fn()=>Settings::validate($bad),'arbitrary module rejected');
 $bad=$defaults;$bad['home']['en_US'][1]['key']='trending';rejects(fn()=>Settings::validate($bad),'duplicate module rejected');
 $bad=$defaults;$bad['home']['en_US'][0]['limit']=13;rejects(fn()=>Settings::validate($bad),'unbounded limit rejected');
 $bad=$defaults;$bad['home']['en_US'][0]['views']=123;rejects(fn()=>Settings::validate($bad),'fabricatedcounter rejected');
 foreach(array('1e1','-1','01',array(1),'9999999999999999999999')as$v){rejects(fn()=>Settings::integer($v,0,12),'strictnumeric boundary');}
 rejects(fn()=>Settings::text("bad\0text",20),'controlbytes rejected');rejects(fn()=>Settings::text("\xFF",20),'invalidUTF8 rejected');
 $platform=array('enabled'=>true,'verified'=>true,'evidence_url'=>'https://docs.example/cli','translations'=>array('en_US'=>array('title'=>'CLI','description'=>'Manually reviewed CLI docs')));$data=$defaults;$data['platforms'][7]=$platform;
 check(Settings::validate($data)['platforms'][7]['translations']['zh_CN']['title']==='','missingChinese staysmissing');
 foreach(array('http://docs.example/','https://user:password@docs.example/','https://127.0.0.1/','https://[::1]/','https://docs.example/?%74oken=SECRET')as$url){$bad=$data;$bad['platforms'][7]['evidence_url']=$url;rejects(fn()=>Settings::validate($bad),'unsafeevidence rejected');}
 $bad=$data;$bad['platforms'][7]['verified']=false;rejects(fn()=>Settings::validate($bad),'explicitmanual evidenceverification');
 rejects(fn()=>$settings->save($data,0,'publish'),'settingscap required');$GLOBALS['caps']=[Settings::CAPABILITY=>true];
 $migrator->current=false;rejects(fn()=>$settings->save($data,0,'publish'),'schema gate');$migrator->current=true;
 $state=$settings->save($data,0,'draft');check($state['revision']===1&&Settings::platforms()===array(),'draft remainsprivate');
 $before=$GLOBALS['option'];rejects(fn()=>$settings->save($defaults,0,'publish'),'staleCAS rejected');check($before===$GLOBALS['option'],'stale save cannot overwrite');
 $state=$settings->save($data,1,'publish');check($state['published_revision']===2&&Settings::platforms()[7]['enabled'],'explicitpublish accepted');
 $bad=$data;$bad['platforms']=[8=>$platform];rejects(fn()=>$settings->save($bad,2,'publish'),'existingnonplatformtag rejected');
 $bad=$data;$bad['platforms']=[9=>$platform];rejects(fn()=>$settings->save($bad,2,'publish'),'archivedplatform rejected');
 $before=$GLOBALS['option'];$db->fail=true;rejects(fn()=>$settings->save($defaults,2,'publish'),'writefail closed');check($GLOBALS['option']===$before,'failedwrite preservespublishedstate');$db->fail=false;
 $form=['home'=>[],'platform_ids'=>''];foreach(Settings::LOCALES as$locale){foreach($defaults['home'][$locale]as$pos=>$row){$key=$row['key'];unset($row['key']);$form['home'][$locale][$key]=$row+['order'=>(string)(9-$pos)];}}
 check(Admin::settingsPayload($form)['home']['en_US'][0]['key']==='tutorials','boundedmodule ordering honored');
 $bad=$form;unset($bad['home']['en_US']['trending']['description']);rejects(fn()=>Admin::settingsPayload($bad),'truncatedhome failsclosed');
 $bad=$form;$bad['platform_ids']='7';rejects(fn()=>Admin::settingsPayload($bad),'truncatedplatform IDs rejected');
 $bad=$form;$bad['platform_ids']='7';$bad['platforms'][7]=$platform;rejects(fn()=>Admin::settingsPayload($bad),'truncated bilingualplatform fields rejected');
 $admin->register();$GLOBALS['hooks']['admin_menu'][0]();check(count($GLOBALS['menus'])===4,'fourdistinctnativeadminmenus');check(count(array_filter($GLOBALS['menus'],fn($r)=>$r['cap']===Settings::CAPABILITY))===4,'dedicatedcapallmenus');check(!isset($GLOBALS['hooks']['admin_post_nopriv_aish_discovery_admin']),'noanonymousmutations');
 $GLOBALS['caps']=[];rejects(fn()=>$admin->screen(),'adminreadcap');$_SERVER['REQUEST_METHOD']='POST';rejects(fn()=>$admin->handle(),'adminwritecap');$GLOBALS['caps']=[Settings::CAPABILITY=>true];
 $_SERVER['REQUEST_METHOD']='GET';rejects(fn()=>$admin->handle(),'GETmutation denied');$_SERVER['REQUEST_METHOD']='POST';$_POST=['_wpnonce'=>['fixture']];rejects(fn()=>$admin->handle(),'arraynonce denied');$_POST=['_wpnonce'=>'bad'];rejects(fn()=>$admin->handle(),'invalidnonce denied');
 dispatch($admin,['kind'=>'collection','confirm'=>'0']);check(!$management->calls&&$GLOBALS['notice']==='failed','explicitconfirmation required');
 dispatch($admin,['kind'=>'collection','operation'=>'archive','id'=>'3','revision'=>'4']);check($management->calls[0]===['archive','collection',3,4],'archiveforwards CASandretainsrecord');
 dispatch($admin,['kind'=>'terms','id'=>'9','term_type'=>'category','translations'=>['en_US'=>['name'=>'Tools','description'=>'Changed']],'digest'=>str_repeat('a',64)]);check(end($management->calls)[4]===str_repeat('a',64),'termexpected digest forwarded');
 $_GET=[];ob_start();$admin->screen('collection');$html=ob_get_clean();check(str_contains($html,'&lt;script&gt;x&lt;/script&gt;')&&!str_contains($html,'<script>'),'listreadsrows contractandescapesstoredHTML');
 $_GET=['term_id'=>'9'];ob_start();$admin->screen('terms');$html=ob_get_clean();check(str_contains($html,'name="digest"')&&str_contains($html,'name="_wpnonce"'),'termformbinds originaldigestandnonce');
 $GLOBALS['option']='malformed';check(Settings::tracking()===false,'malformedoptionsdisabletracking');rejects(fn()=>Settings::snapshot(),'malformedoptions do notsilently overwrite');
 echo "Discovery admin/settings: {$GLOBALS['count']} isolated assertions passed.\n";
}
