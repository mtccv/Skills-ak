<?php
declare(strict_types=1);
/** Integration of real routing/SEO helpers with isolated editorial data; no WP/MySQL/plugin execution. */
namespace AISkillsHub\Core {
 final class Core { public static function boot():self{return new self();} public function get(string $name):object{if($name!=='learning_help')throw new \RuntimeException('unexpected_service');return $GLOBALS['help_service'];} }
}
namespace AISkillsHub\Core\Frontend {
 final class PublicCatalog {}
 final class Editorial {
  public function tutorials(string $locale,array $filters=[]):array{if($GLOBALS['storage_failure'])throw new \RuntimeException('private database detail');return ['items'=>$GLOBALS['published'][$locale]??[]];}
  public function page(string $route,string $locale):?array{return null;}
 }
 function header(string $value,bool $replace=true):void{$GLOBALS['headers'][explode(':',$value,2)[0]]=$value;}
 function headers_sent():bool{return false;}
}
namespace {
 if(PHP_SAPI!=='cli')exit(1);define('ABSPATH','/isolated/');$checks=0;
 final class WP{public string $request='';public array $query_vars=['p'=>99,'post_type'=>'aish_message'];}
 final class Wpdb {public string $last_error='';public bool $suppressed=false;public function suppress_errors(bool $value):bool{$old=$this->suppressed;$this->suppressed=$value;return $old;}}
 final class HelpFixture {public function listing(string $locale,array $filters=[]):array{if($GLOBALS['storage_failure'])throw new \RuntimeException('private database detail');$items=$filters['page']>2?[]:($GLOBALS['published'][$locale]??[]);return ['items'=>$items,'total'=>count($items)?21:0,'page'=>$filters['page'],'per_page'=>12,'filters'=>$filters];}}
 function check(bool $ok,string $why):void{++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException($why);}
 function is_admin():bool{return false;}
 function get_option(string $name,mixed $default=false):mixed{return $GLOBALS['options'][$name]??$default;}
 function home_url(string $path=''):string{return 'https://hub.test/site/en'.$path;}
 function wp_parse_url(string $value,int $component=-1):mixed{return parse_url($value,$component);}
 function wp_unslash(mixed $value):mixed{return $value;}
 function wp_strip_all_tags(string $value):string{return strip_tags($value);}
 function add_query_arg(array $args,string $url):string{return $url.'?'.implode('&',array_map(fn($key)=>$key.'='.$args[$key],array_keys($args)));}
 function apply_filters(string $name,mixed $value,mixed ...$args):mixed{foreach($GLOBALS['hooks'][$name]??[] as $fn)$value=$fn($value,...$args);return $value;}
 function add_filter(string $name,callable $fn,int $priority=10,int $accepted=1):void{$GLOBALS['hooks'][$name][]=$fn;}
 function add_action(string $name,callable $fn,int $priority=10,int $accepted=1):void{}
 function _wp_render_title_tag():void{}
 function has_action(string $name):bool{return false;}
 function remove_action(mixed ...$args):void{}
 function remove_all_actions(mixed ...$args):void{}
 function status_header(int $status):void{$GLOBALS['status']=$status;}
 function nocache_headers():void{$GLOBALS['uncached']=true;}
 function esc_html(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
 function esc_attr(string $value):string{return esc_html($value);}
 function esc_url(string $value):string{return esc_html($value);}
 function wp_json_encode(mixed $value,int $flags=0):string{return json_encode($value,$flags|JSON_THROW_ON_ERROR);}
 function get_post_meta(int $id,string $key,bool $single=true):string{return '';}
 function wp_cache_delete(mixed ...$args):void{}
 $theme=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/';
 foreach(['Security/Input.php','Frontend/Strings.php','Frontend/Router.php','Frontend/SeoSettings.php','Frontend/Seo.php','Frontend/View.php','Frontend/Sitemap.php'] as $file)require $theme.$file;
 use AISkillsHub\Core\Frontend\{Router,Seo,SeoSettings,View,Sitemap,Editorial,PublicCatalog};
 function resetFixture():void{$GLOBALS['options']=['home'=>'https://hub.test/site','blog_public'=>'1'];$GLOBALS['published']=['zh_CN'=>[['id'=>1]],'en_US'=>[['id'=>2]]];$GLOBALS['hooks']=[];$GLOBALS['headers']=[];$GLOBALS['storage_failure']=false;$GLOBALS['uncached']=false;$GLOBALS['wpdb']=new Wpdb();$GLOBALS['help_service']=new HelpFixture();$GLOBALS['wp_query']=(object)['is_404'=>false,'is_home'=>false];(new \ReflectionProperty(Router::class,'adapterFailed'))->setValue(null,false);}
 function requestHelp(string $language='en',array $query=[]):array{$router=new Router(new PublicCatalog(),new Editorial());$_GET=$query;$_SERVER['REQUEST_URI']='/site/'.$language.'/help/'.($query?'?'.http_build_query($query):'');$wp=new WP();$router->parse($wp);check(array_keys($wp->query_vars)===['aish_route','aish_language'],'Help route owns native query without leaking legacy post selectors');$router->resolve();return Router::context();}
 resetFixture();$c=requestHelp();
 check($c['route']==='help'&&$c['status']===200&&$c['locale']==='en_US','Help resolves through existing SSR route');
 check($c['canonical']==='https://hub.test/site/en/help/'&&!$c['noindex'],'Nonempty Help self canonical has correct subdirectory and locale');
 check($c['seo_alternates']===['zh_CN'=>'https://hub.test/site/zh/help/','en_US'=>'https://hub.test/site/en/help/']&&$c['x_default']==='https://hub.test/site/zh/help/','Both real published languages and default are advertised');
 check($GLOBALS['uncached']&&str_contains($GLOBALS['headers']['Cache-Control'],'private, no-store'),'Public Help responds uncached so unpublication takes effect');
 check(str_contains(View::breadcrumbs(),'Help center')&&!str_contains(View::breadcrumbs(),'page.help.title'),'Existing breadcrumb has actual localized help label');
 $GLOBALS['options']['aish_seo_settings']=['en_US:help:0'=>['title'=>'Editorial Help','description'=>'Reviewed help description','image'=>'https://hub.test/share.png']];$c=requestHelp();
 check($c['title']==='Editorial Help'&&$c['description']==='Reviewed help description'&&$c['image']==='https://hub.test/share.png','Independent existing SEO option supports Help title/description/social image');
 $zh=requestHelp('zh');check($zh['title']!=='Editorial Help'&&$zh['canonical']==='https://hub.test/site/zh/help/','English configuration does not overwrite Chinese title or canonical');
 resetFixture();$query=['q'=>str_repeat('中',100),'help_category'=>'safety','page'=>'2'];$c=requestHelp('zh',$query);
 check($c['status']===200&&$c['data']['filters']['q']===$query['q'],'100-character Chinese query is accepted rather than truncated by byte limit');
 parse_str((string)parse_url($c['canonical'],PHP_URL_QUERY),$canonicalQuery);check($canonicalQuery==$query,'Combined search/category/page remain in self canonical');
 check($c['noindex']&&$c['seo_alternates']===[]&&str_contains($c['title'],'第 2 页'),'Filter/search variants are noindex with no guessed translation page');
 $switched=View::switchUrl('en_US');parse_str((string)parse_url($switched,PHP_URL_QUERY),$switchQuery);check($switchQuery===['q'=>$query['q'],'help_category'=>'safety']&&str_starts_with($switched,'https://hub.test/site/en/help/'),'Language switch retains search/category and resets page');
 $pagination=html_entity_decode(View::pagination(36,2,12,$c['data']['filters']),ENT_QUOTES,'UTF-8');check(str_contains($pagination,'help_category=safety')&&str_contains($pagination,'page=3')&&str_contains($pagination,'/zh/help/?'),'Pagination retains relevant filters and current language');
 foreach([['q'=>str_repeat('中',101)],['q'=>['invalid']],['page'=>'0'],['page'=>'10001']] as $invalid){$bad=requestHelp('zh',$invalid);check($bad['status']===404&&$bad['canonical']===''&&$bad['noindex'],'Invalid Help query is a nonindexable controlled404');}
 resetFixture();$c=requestHelp('en',['page'=>'2']);check($c['status']===200&&!$c['noindex']&&str_ends_with($c['canonical'],'?page=2')&&$c['seo_alternates']===[],'Unfiltered meaningful second page remains self canonical without invented hreflang');
 $c=requestHelp('en',['page'=>'3']);check($c['status']===404&&$c['canonical']===''&&empty($c['data']['help']),'Out-of-range help pagination is404 without stale items');
 resetFixture();$GLOBALS['published']['zh_CN']=[];$c=requestHelp();check(array_keys($c['seo_alternates'])===['en_US']&&$c['x_default']===$c['canonical'],'Missing Chinese publication does not emit invalid hreflang/x-default');
 $zh=requestHelp('zh');check($zh['status']===200&&$zh['noindex']&&$zh['seo_alternates']===[],'Unpublished language has honest empty noindex index');
 $site=new Sitemap(new PublicCatalog(),new Editorial());$en=array_column($site->urlEntries('pages','en_US',1),'loc');$zh=array_column($site->urlEntries('pages','zh_CN',1),'loc');
 check(in_array('https://hub.test/site/en/help/',$en,true)&&!in_array('https://hub.test/site/zh/help/',$zh,true),'Sitemap only includes actual published-language Help index');
 $GLOBALS['options']['aish_seo_settings']=['en_US:help:0'=>['noindex'=>true]];$site=new Sitemap(new PublicCatalog(),new Editorial());check(!in_array('https://hub.test/site/en/help/',array_column($site->urlEntries('pages','en_US',1),'loc'),true),'Configured noindex Help is excluded from Sitemap');
 $GLOBALS['options']['blog_public']='0';check($site->urlEntries('pages','en_US',1)===[],'Site privacy overrides all Help sitemap entries');
 resetFixture();$GLOBALS['storage_failure']=true;$c=requestHelp();check($c['status']===503&&$c['data']===[]&&$c['canonical']===''&&$c['noindex'],'Storage failure is503 without private detail or fake complete content');
 resetFixture();add_filter('aish_frontend_url',static function(string $url):string{throw new \RuntimeException('sensitive adapter detail');});$c=requestHelp();check($c['status']===503&&$c['canonical']===''&&$c['seo_alternates']===[],'Adapter exception fails closed before canonical/hreflang publication');
 check(View::url('help',[],'zh_CN')==='https://hub.test/site/zh/help/','Error page navigation can use safe built-in URL after failed adapter');
 resetFixture();add_filter('aish_frontend_url',static fn(string $url):string=>'javascript:alert(1)');$c=requestHelp();check($c['status']===503&&!str_contains(json_encode($c),'javascript:'),'Unsafe adapter URL never enters Help response metadata');
 resetFixture();$c=requestHelp();$seo=new Seo();ob_start();$seo->head();$html=ob_get_clean();
 check(substr_count($html,'rel="canonical"')===1&&substr_count($html,'property="og:title"')===1&&substr_count($html,'name="twitter:title"')===1,'Help metadata has one owner for canonical and social tags');
 check(str_contains($html,'BreadcrumbList')&&str_contains($html,'Help center'),'Help structured data reuses real localized breadcrumb');
 echo 'Help routing: '.$checks." isolated assertions passed (real PHP routing/SEO helpers; stubbed data, no real WPML/SEO plugin).\n";
}
