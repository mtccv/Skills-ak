<?php
declare(strict_types=1);
/** Isolated help contracts only. Native transactions/KSES/SQL are covered separately by real QA. */
namespace AISkillsHub\Core\Database {
 final class Database {}
 final class Migrator { public bool $ready = true; public function isCurrent(): bool { return $this->ready; } }
}
namespace AISkillsHub\Core\Frontend {
 final class View {
  public static function url(string $route,array $args=[],?string $locale=null):string { return 'https://hub.test/'.($locale==='zh_CN'?'zh':'en').'/'.$route.'/'.($args?'?'.http_build_query($args):''); }
  public static function tutorialUrl(array $card,?string $locale=null):string { return self::url('tutorials/'.$card['id'].'/'.$card['slug'],[],$locale); }
  public static function rich(string $html):string { return \wp_kses_post($html); }
 }
}
namespace AISkillsHub\Core\Learning {
 function header(string $value,bool $replace=true):void{$GLOBALS['headers'][]=$value;}
}
namespace {
 if(PHP_SAPI!=='cli')exit(1); define('ABSPATH','/isolated/');
 $GLOBALS['checks']=0;$GLOBALS['hooks']=[];$GLOBALS['posts']=[];$GLOBALS['meta']=[];$GLOBALS['selected']=[];$GLOBALS['allowed']=true;$GLOBALS['nonce']=true;
 function check(bool $ok,string $why):void{++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException($why);}
 function rejects(callable $fn,string $reason=''):void{try{$fn();}catch(\Throwable $error){check($reason===''||$error->getMessage()===$reason,'Unexpected rejection: '.$error->getMessage());return;}check(false,'Expected rejection '.$reason);}
 function add_filter(string $name,callable $fn,int $priority=10,int $count=1):void{$GLOBALS['hooks'][$name][]=$fn;}
 function add_action(string $name,callable $fn,int $priority=10,int $count=1):void{add_filter($name,$fn,$priority,$count);}
 function add_submenu_page(string $parent,string $title,string $menu,string $cap,string $slug,callable $callback):string{check($cap==='aish_manage_skills'&&$slug==='aish-help','Help admin menu retains exact capability');return 'aish_page_aish-help';}
 function nocache_headers():void{$GLOBALS['headers'][]='Native no-cache';}
 function remove_filter(string $name,callable $fn,int $priority=10):void{$GLOBALS['hooks'][$name]=array_filter($GLOBALS['hooks'][$name]??[],static fn($item)=>$item!==$fn);}
 function apply_filters(string $name,mixed $value,mixed ...$args):mixed{foreach($GLOBALS['hooks'][$name]??[] as $fn)$value=$fn($value,...$args);return $value;}
 function has_filter(string $name):bool{return !empty($GLOBALS['hooks'][$name]);}
 function current_user_can(string $cap):bool{return $GLOBALS['allowed']&&$cap==='aish_manage_skills';}
 function wp_die(string $message,string $title='',array $args=[]):never{throw new \RuntimeException('http_'.($args['response']??500));}
 function check_admin_referer(string $action):void{check($action==='aish_help_import','Distinct import nonce');if(!$GLOBALS['nonce'])throw new \RuntimeException('nonce_failed');}
 function wp_strip_all_tags(string $html):string{return strip_tags(preg_replace('@<(script|style)[^>]*>.*?</\\1>@si','',$html));}
 function wp_kses_post(string $html):string{return preg_replace('/\son[a-z]+="[^"]*"/i','',preg_replace('@<(script|style)[^>]*>.*?</\\1>@si','',$html));}
 function wp_trim_words(string $value,int $length,string $suffix):string{return $value;}
 function esc_html(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
 function esc_url(string $value):string{return esc_html($value);}
 function get_post(int $id):?WP_Post{return $GLOBALS['posts'][$id]??null;}
 function get_post_meta(int $id,string $key,bool $single=true):mixed{return $GLOBALS['meta'][$id][$key]??($single?'':[]);}
 function get_userdata(int $id):object{return (object)['display_name'=>'Editorial author'];}
 function update_meta_cache(string $type,array $ids):void{}
 function update_post_author_caches(array $posts):void{}
 class WP_Post {
  public string $post_type='aish_tutorial',$post_status='publish',$post_password='',$post_title='Published tutorial',$post_content='<h2>Actual reading</h2><p>Useful independent content.</p>',$post_excerpt='Actual summary',$post_name='real-tutorial',$post_modified_gmt='2026-11-07 00:00:00',$post_date_gmt='2026-11-07 00:00:00';public int $post_author=1;
  public function __construct(public int $ID){}
 }
 class WP_Query {
  public static array $calls=[];public array $posts=[];public int $found_posts=0;
  public function __construct(private array $args){$sql=apply_filters('get_meta_sql',['join'=>'','where'=>''],[],'post','qa_posts','ID',$this);self::$calls[]=['args'=>$args,'sql'=>$sql];$this->posts=array_map('get_post',$GLOBALS['selected']);$this->found_posts=count($this->posts);}
  public function get(string $key):mixed{return $this->args[$key]??null;}
 }
 class HelpWpdb {
  public string $posts='qa_posts',$postmeta='qa_postmeta',$last_error='';public bool $suppressed=false;public array $prepared=[];
  public function suppress_errors(bool $value):bool{$old=$this->suppressed;$this->suppressed=$value;return $old;}
  public function esc_like(string $value):string{return addcslashes($value,'_%\\');}
  public function prepare(string $sql,mixed ...$args):string{if(count($args)===1&&is_array($args[0]))$args=$args[0];$this->prepared[]=[$sql,$args];return preg_replace_callback('/%s/',static function()use(&$args):string{return "'".str_replace("'","''",(string)array_shift($args))."'";},$sql);}
 }
 $GLOBALS['wpdb']=new HelpWpdb();
 $theme=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/';
 foreach(['Security/Input.php','Frontend/Editorial.php','Learning/HelpContent.php','Learning/Help.php','Learning/HelpAdmin.php'] as $file)require $theme.'inc/core/'.$file;
 use AISkillsHub\Core\Learning\{Help,HelpContent,HelpAdmin};use AISkillsHub\Core\Frontend\Editorial;use AISkillsHub\Core\Database\{Database,Migrator};
 $articles=HelpContent::articles();
 check(count($articles)===21,'Exactly twelve requested learning and nine policy topics');
 check(count(array_filter($articles,fn($article)=>$article['category']==='policies'))===9,'Nine policy topics are separately discoverable');
 $expected=['what-are-agent-skills','skills-and-prompts','discover-skills','install-skills','claude-code-skills','codex-skills','create-skills','check-sources-and-risks','submit-skills','favorites-and-reviews','licenses-explained','frequently-asked-questions','community-guidelines','submission-guidelines','review-guidelines','author-claim-guidelines','license-copyright','safety-security','privacy-policy','terms','contact-help'];
 check(array_keys($articles)===$expected,'All requested concrete topics exist');
 $sections=0;
 foreach($articles as $topic=>$article){
  check(in_array($article['category'],Editorial::HELP_CATEGORIES,true),'Article category is managed by existing metadata');
  foreach(['zh_CN','en_US'] as $locale){$copy=$article[$locale];$body=HelpContent::body($topic,$locale);$sections+=count($copy['sections']);
   check(strlen($copy['title'])>=3&&strlen($copy['summary'])>30&&strlen(strip_tags($body))>500,'Complete article body/title/summary, not a placeholder: '.$topic.':'.$locale);
   check(count($copy['sections'])>=4&&str_contains($body,'<h2>'),'Readable sections exist');
   check(!str_contains($body,'<script')&&!str_contains($body,'<iframe')&&!str_contains($body,'<form'),'Starter body contains no executable form or script');
   foreach($article['links'] as $link)check(str_starts_with($link,'https://')||in_array($link,['route:skills','route:search','route:submit','route:contact'],true),'Only fixed HTTPS references or approved navigation links');
  }
  check($article['zh_CN']!==$article['en_US']&&$article['zh_CN']['title']!==$article['en_US']['title'],'Independent translation body is present');
 }
 check($sections===170,'Original bilingual library has 170 substantive sections');
 rejects(fn()=>HelpContent::body('private-secret','zh_CN'),'invalid_help_topic');rejects(fn()=>HelpContent::body('terms','de_DE'),'invalid_help_topic');
 check(str_contains(HelpContent::body('discover-skills','en_US'),'https://hub.test/en/'),'English action links use English routes');
 check(str_contains(HelpContent::body('discover-skills','zh_CN'),'https://hub.test/zh/'),'Chinese action links use Chinese routes');
 $editorial=new Editorial();$migrator=new Migrator();$help=new Help(new Database(),$migrator,$editorial);$admin=new HelpAdmin($help);$admin->register();
 check(has_filter('admin_post_aish_help_import')&&!has_filter('admin_post_nopriv_aish_help_import'),'Importer has no anonymous mutation hook');
 foreach($GLOBALS['hooks']['admin_menu'] as $menu)$menu();check(has_filter('load-aish_page_aish-help'),'Private response headers registered on pre-render native admin page load');
 $GLOBALS['headers']=[];foreach($GLOBALS['hooks']['load-aish_page_aish-help'] as $load)$load();check(in_array('Cache-Control: private, no-store, max-age=0',$GLOBALS['headers'],true)&&in_array('X-Robots-Tag: noindex, nofollow, noarchive',$GLOBALS['headers'],true),'Admin privacy headers set before WordPress renders its HTML header');
 $GLOBALS['allowed']=false;rejects(fn()=>$help->importDrafts(),'forbidden');rejects(fn()=>$help->inventory(),'forbidden');rejects(fn()=>$admin->screen(),'http_403');
 rejects(fn()=>$admin->privateHeaders(),'http_403');
 $_SERVER['REQUEST_METHOD']='POST';rejects(fn()=>$admin->import(),'http_403');$GLOBALS['allowed']=true;$_SERVER['REQUEST_METHOD']='GET';rejects(fn()=>$admin->import(),'http_405');
 $_SERVER['REQUEST_METHOD']='POST';$GLOBALS['nonce']=false;rejects(fn()=>$admin->import(),'nonce_failed');$GLOBALS['nonce']=true;
 $migrator->ready=false;rejects(fn()=>$help->importDrafts(),'storage_unavailable');$migrator->ready=true;
 $first=$help->listing('en_US',['q'=>"-source%_'",'help_category'=>'safety','page'=>2]);$call=WP_Query::$calls[0];
 check($call['args']['post_type']==='aish_tutorial'&&$call['args']['post_status']==='publish'&&$call['args']['has_password']===false,'Help uses the existing public native tutorial gate');
 check($call['args']['meta_value']==='en_US'&&$call['args']['paged']===2&&$call['args']['posts_per_page']===12,'Locale/page/size passed to native query');
 check(str_contains($call['sql']['where'],"BINARY hc.meta_key='_aish_help_category'")&&str_contains($call['sql']['where'],"IN ('safety')"),'Help category is independently bound and exact');
 check(str_contains($call['sql']['where'],"LIKE '%-source\\%\\_''%'"),'Wildcard and quote are searched literally');
 check($first['total']===0&&$first['topics']===[],'Empty site invents neither public content nor translated links');
 check(!has_filter('get_meta_sql')&&!$GLOBALS['wpdb']->suppressed,'Scoped native SQL hooks restored');
 $calls=count(WP_Query::$calls);check($help->listing('en_US',['q'=>"-source%_'",'help_category'=>'safety','page'=>2])===$first&&count(WP_Query::$calls)===$calls,'Repeated public Help/sidebar reads do not repeat queries');
 foreach([['help_category'=>"safety' OR 1=1"],['help_category'=>['safety']],['q'=>['x']],['page'=>0],['per_page'=>25],['sort'=>'random'],['user_id'=>1]] as $bad)rejects(fn()=>$help->listing('en_US',$bad));
 rejects(fn()=>$help->listing('de_DE'));rejects(fn()=>$editorial->tutorials('en_US',['help_only'=>'yes']));
 for($id=1;$id<=7;++$id){$GLOBALS['posts'][$id]=new WP_Post($id);$GLOBALS['meta'][$id]=['_aish_locale'=>'en_US','_aish_help_topic'=>'what-are-agent-skills','_aish_help_category'=>'getting-started'];}
 $GLOBALS['posts'][1]->post_status='draft';$GLOBALS['posts'][2]->post_password='secret';$GLOBALS['meta'][3]['_aish_locale']='zh_CN';$GLOBALS['posts'][4]->post_content='';$GLOBALS['posts'][5]->post_type='aish_message';$GLOBALS['meta'][6]['_aish_help_topic']='private-unknown';$GLOBALS['selected']=range(1,7);$editorial->clearCache();
 $links=$help->topicLinks('en_US');check(array_keys($links)===['what-are-agent-skills']&&$links['what-are-agent-skills']['id']===7,'Topic navigation rechecks each payload against live native public/locale rules');
 check($help->topicUrl('terms','en_US')==='https://hub.test/en/help/','Missing published topic goes to real index rather than fake translation');
 foreach([[],array_fill(0,25,'terms'),["terms' OR 1=1"],['../terms']] as $bad)rejects(fn()=>$editorial->helpTopicCards('en_US',$bad));
 $private=$help->reading(['id'=>1,'content'=>'PRIVATE UNTRUSTED CALLER DATA'],'en_US');check($private===['content'=>'','toc'=>[],'related'=>[],'category'=>''],'Private caller content cannot enter reading view');
 $read=$help->reading(['id'=>7,'content'=>'FAKE CALLER CONTENT'],'en_US');check(!str_contains($read['content'],'FAKE')&&str_contains($read['content'],'Actual reading'),'Reading uses stored public tutorial rather than caller body');
 check(array_column($read['related'],'id')===[6],'Related reading excludes self and unpublished/wrong-language records');
 $safe=Help::navigation('<h2 onclick="unsafe()">标题 &amp; Source</h2><p>[not_executed]</p><h3>Details</h3><script>run()</script>');
 check(array_column($safe['toc'],'id')===['help-section-1','help-section-2']&&$safe['toc'][0]['title']==='标题 & Source','TOC handles Unicode and safe generated heading anchors');
 check(str_contains($safe['content'],'[not_executed]')&&!str_contains($safe['content'],'<script')&&!str_contains($safe['content'],'onclick='),'Reading keeps shortcode literal and uses its sanitizer boundary');
 check(count(Help::navigation(str_repeat('<h2>Heading</h2>',30))['toc'])===24,'Long articles have bounded reading navigation');
 $malformed=Help::navigation('<h2>Before</h2></div><p>Text after an unmatched closing div</p><h2>After</h2>');
 check(str_contains($malformed['content'],'Text after an unmatched closing div')&&count($malformed['toc'])===2,'Older malformed editorial markup cannot truncate later approved reading content');
 $existing=Help::navigation('<div id="help-section-1">Notice</div><h2 id="existing-installation">Installation</h2><h3>Requirements</h3>');
 check(array_column($existing['toc'],'id')===['existing-installation','help-section-2']&&str_contains($existing['content'],'id="existing-installation"'),'Existing deep links survive and generated anchors avoid non-heading ID collisions');
 echo 'Help center: '.$GLOBALS['checks']." isolated assertions passed (stubbed WP/SQL; real import, KSES and rendering require integration checks).\n";
}
