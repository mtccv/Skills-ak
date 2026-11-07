<?php
declare(strict_types=1);
/** Isolated public-proof, transaction and clipboard projection contracts. No real MySQL/WordPress/API calls. */
namespace AISkillsHub\Core\Database {
 final class Migrator {public bool $ready=true;public function isCurrent():bool{return $this->ready;}}
 final class Database {
  public array $skills=array(),$terms=array(),$sources=array(),$options=array(),$events=array(),$queries=array();public bool $failAudit=false;public string $engine='InnoDB';
  public function connection():object{return (object)array('options'=>'wp_options');}
  public function table(string $name):string{return '`wp_aish_'.$name.'`';}
  public function row(string $sql,array $args=array()):?array {
   $this->queries[]=array($sql,$args);
   if(str_contains($sql,'FROM `wp_aish_skills`'))return $this->skills[$args[0]][$args[1]]??null;
   if(str_contains($sql,'FROM `wp_aish_skill_versions`')){$row=$this->sources[$args[1]][$args[0]]??null;if($row&&!str_contains($sql,',v.source_content'))unset($row['source_content']);return $row;}
   throw new \RuntimeException('unexpected row query');
  }
  public function rows(string $sql,array $args=array()):array{$this->queries[]=array($sql,$args);if(str_contains($sql,'FROM `wp_aish_tags`'))return $this->terms[$args[0]]??array();if(str_contains($sql,'FROM `wp_aish_skill_versions`'))return array_values($this->sources[$args[0]]??array());throw new \RuntimeException('unexpected rows query');}
  public function value(string $sql,array $args=array()):mixed{$this->queries[]=array($sql,$args);if(str_contains($sql,'information_schema.TABLES'))return $this->engine;if(str_contains($sql,'SELECT option_value'))return $this->options[$args[0]]??null;throw new \RuntimeException('unexpected scalar query');}
  public function execute(string $sql,array $args=array()):int{$this->queries[]=array($sql,$args);if(str_starts_with($sql,'INSERT INTO `wp_options`')){$this->options[$args[0]]??=$args[1];return 1;}if(str_starts_with($sql,'UPDATE `wp_options`')){$this->options[$args[1]]=$args[0];return 1;}throw new \RuntimeException('unexpected write');}
  public function insert(string $name,array $row):int{if('workflow_events'!==$name)throw new \RuntimeException('unexpected table');if($this->failAudit)throw new \RuntimeException('audit_failure');foreach($this->events as$old)if($old['event_key']===$row['event_key'])throw new \RuntimeException('duplicate_event');$this->events[]=$row;return count($this->events);}
  public function transaction(callable $fn):mixed{$old=array($this->options,$this->events);try{return $fn();}catch(\Throwable $e){[$this->options,$this->events]=$old;throw $e;}}
 }
}
namespace AISkillsHub\Core\Admin {final class Management {public static function translationComplete(array $row):bool{foreach(array('title','slug','summary','content')as$key)if(''===trim(strip_tags(html_entity_decode($row[$key]??''))))return false;return true;}}}
namespace AISkillsHub\Core\Frontend {final class Router {public static string $suffix='';public static function url(string $route,array $args,string $locale):string{return 'https://hub.test/'.('zh_CN'===$locale?'zh':'en').'/skills/'.$args['id'].'/'.$args['slug'].'/'.self::$suffix;}public static function siteUrl(string $path=''):string{return 'https://hub.test'.$path;}}}
namespace {
 define('ABSPATH','/isolated/');define('DAY_IN_SECONDS',86400);$count=0;$GLOBALS['allowed']=true;$GLOBALS['actor']=7;$GLOBALS['hooks']=array();$GLOBALS['renders']=array();$GLOBALS['cache_invalidations']=array();$GLOBALS['is_admin']=false;$_GET=array();
 function current_user_can(string $cap):bool{return $GLOBALS['allowed']&&'aish_review_skills'===$cap;}
 function get_current_user_id():int{return $GLOBALS['actor'];}
 function sanitize_text_field(string $value):string{return trim(preg_replace('/\s+/u',' ',strip_tags($value)));}
 function wp_json_encode(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
 function wp_cache_delete(string $key,string $group):bool{$GLOBALS['cache_invalidations'][]=array($key,$group);return true;}
 function wp_parse_url(string $url):array|false{return parse_url($url);}
 function wp_unslash(string $value):string{return stripslashes($value);}
 function get_user_locale():string{return 'en_US';}
 function esc_html(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
 function esc_attr(string $value):string{return esc_html($value);}
 function esc_textarea(string $value):string{return esc_html($value);}
 function esc_url(string $value):string{return esc_html($value);}
 function wp_die(string $message,string $title='',array $args=array()):never{throw new \RuntimeException('http_'.($args['response']??500));}
 function wp_verify_nonce(string $value,string $action):bool{return 'valid'===$value&&'aish_installation_save'===$action;}
 function add_action(string $name,callable $fn,int $priority=10,int $args=1):void{$GLOBALS['hooks'][$name]=$fn;}
 function add_filter(string $name,callable $fn,int $priority=10,int $args=1):void{add_action($name,$fn,$priority,$args);}
 function is_admin():bool{return $GLOBALS['is_admin'];}
 function get_template_part(string $part,mixed $name,array $args):void{$GLOBALS['renders'][]=array($part,$args);}
 function check(bool $ok,string $why):void{++$GLOBALS['count'];if(!$ok)throw new \RuntimeException($why);}
 function rejects(callable $fn,string $why=''):void{try{$fn();}catch(\Throwable $e){check(''===$why||$e->getMessage()===$why,'unexpected failure '.$e->getMessage().' expected '.$why);return;}check(false,'rejection missing '.$why);}
 $theme=dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/';foreach(array('Security/Input.php','Community/Exception.php','Community/Input.php','Community/Events.php','Admin/ContributionUi.php','Learning/SkillExperience.php','Learning/InstallationAdmin.php')as$file)require $theme.'inc/core/'.$file;
 use AISkillsHub\Core\Database\{Database,Migrator};use AISkillsHub\Core\Learning\{SkillExperience,InstallationAdmin};use AISkillsHub\Core\Frontend\Router;
 function fixture(bool $github=false):array{
  $db=new Database();$migrator=new Migrator();$command='tool --install ./skills/example';
  $skill=array('id'=>41,'repository_id'=>$github?12:null,'source_path'=>$github?'skills/example/SKILL.md':null,'revision'=>3,'reviewed_revision'=>3,'reviewer_id'=>7,'reviewed_at'=>'2026-11-01 10:00:00','status'=>'published','review_status'=>'approved','locale'=>'en_US','title'=>'Example Skill','slug'=>'example','summary'=>'A real test fixture, never public data.','content'=>'<h2>Installation</h2><p>Read the documentation.</p><pre><code>'.esc_html($command).'</code></pre>','owner'=>$github?'example':null,'name'=>$github?'repository':null);
  $db->skills[41]['en_US']=$skill;$db->skills[41]['zh_CN']=array_replace($skill,array('locale'=>'zh_CN','title'=>'示例技能','summary'=>'隔离测试数据，不是公开资源。','slug'=>'example-zh'));
  $db->terms[41]=array(array('id'=>9,'slug'=>'license-mit','name'=>'MIT'),array('id'=>10,'slug'=>'platform-codex','name'=>'Codex'));
  $source="---\nname: example\ndescription: test\n---\n```sh\n$command\n```\nPRIVATE SOURCE NOTE not for public projection\n";
  $db->sources[41][18]=array('id'=>18,'repository_id'=>12,'source_path'=>'skills/example/SKILL.md','commit_sha'=>str_repeat('a',40),'file_sha'=>str_repeat('b',40),'content_hash'=>hash('sha256',$source),'import_commit'=>str_repeat('a',40),'import_file'=>str_repeat('b',40),'source_status'=>'present','change_status'=>'unchanged','findings_json'=>'{"private":"risk evidence"}','metadata_json'=>'{"private":"source metadata"}','source_content'=>$source);
  $payload=array('state'=>'verified','version_id'=>$github?18:0,'commands'=>array(array('command'=>$command,'platform'=>'platform-codex')),'rights_note'=>'MIT quotation and use permission checked by an authorized reviewer.');
  return array($db,$migrator,new SkillExperience($db,$migrator),$payload,$skill);
 }
 check(SkillExperience::command("\nfoo\r\nbar\n")==="foo\nbar",'line endings normalized without hidden execution');
 foreach(array(''," \n ","echo\x1b[2Jhidden","abc\u{202e}def","abc\u{200b}def",str_repeat('a',2001),implode("\n",array_fill(0,17,'line')),array('bad'))as$bad)rejects(fn()=>SkillExperience::command($bad));
 check(SkillExperience::command('echo $(cat /private)')==='echo $(cat /private)','ordinary shell text is not executed or rewritten');
 check(SkillExperience::codeBlocks('<pre><code>echo &lt;literal&gt; &amp; text</code></pre>')===array('echo <literal> & text'),'visible HTML entities decoded exactly');
 check(SkillExperience::codeBlocks('<pre class="wp-block-code"><code class="language-sh">echo hello</code></pre>')===array('echo hello'),'native and language code classes accepted');
 foreach(array('<pre hidden>hidden</pre>','<pre style="color:white">hidden</pre>','<pre class="hidden">hidden</pre>','<div hidden><pre>hidden</pre></div>','<div style="display:none"><pre>hidden</pre></div>','<pre><code onclick="steal()">command</code></pre>','<pre><span>partial</span>command</pre>','<pre><script>execute()</script>visible</pre>','<pre><code>echo'."\u{202e}".'hidden</code></pre>','```shell\nnot html\n```')as$bad)check(SkillExperience::codeBlocks($bad)===array(),'untrusted/hidden/guessed code not eligible');
 check(SkillExperience::sourceContains("before\r\nline one\r\nline two\r\nafter","line one\nline two"),'complete multiline snapshot quote');
 check(!SkillExperience::sourceContains('do-not-copy echo yes','echo yes')&&!SkillExperience::sourceContains('echo yes; extra','echo yes'),'substrings cannot be disguised as standalone instructions');
 rejects(fn()=>SkillExperience::commands(array(array('command'=>'test','platform'=>'platform-none','extra'=>'bad'))),'installation_command_fields');
 rejects(fn()=>SkillExperience::commands(array(array('command'=>'test','platform'=>'platform-../bad'))));
 rejects(fn()=>SkillExperience::commands(array_fill(0,7,array('command'=>'test','platform'=>''))),'installation_commands');
 rejects(fn()=>SkillExperience::commands(array_fill(0,2,array('command'=>'test','platform'=>''))),'installation_duplicate');
 [$db,$m,$service,$payload,$skill]=fixture();$service->register();$admin=new InstallationAdmin($service);$admin->register();
 check(isset($GLOBALS['hooks']['aish_skill_share'],$GLOBALS['hooks']['aish_skill_installation'],$GLOBALS['hooks']['admin_post_aish_installation_save'])&&!isset($GLOBALS['hooks']['admin_post_nopriv_aish_installation_save']),'only authorized native mutation route');
 foreach(array('aish_installation_41_en_US','AISH_INSTALLATION_41_EN_US','aish_installation_future')as$name){check($GLOBALS['hooks']['pre_update_option']('forged',$name,'prior')==='prior','native options cannot bypass reviewed evidence with case variants');foreach(array('add_option','delete_option')as$hook)rejects(fn()=>$GLOBALS['hooks'][$hook]($name),'installation_native_option');}
 check($GLOBALS['hooks']['pre_update_option']('next','ordinary_option','prior')==='next','unrelated native option updates preserved');$GLOBALS['hooks']['add_option']('ordinary_option');$GLOBALS['hooks']['delete_option']('ordinary_option');check(true,'unrelated option lifecycle preserved');
 check($service->installation(41,'en_US')===array('state'=>'unverified','commands'=>array())&&$db->options===array(),'view never invents or persists commands');
 $GLOBALS['allowed']=false;rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_forbidden');rejects(fn()=>$service->editor(41,'en_US'),'installation_forbidden');$_SERVER['REQUEST_METHOD']='POST';$_POST=array('_wpnonce'=>'valid');rejects(fn()=>$admin->handle(),'http_403');$GLOBALS['allowed']=true;
 $_SERVER['REQUEST_METHOD']='GET';rejects(fn()=>$admin->handle(),'http_405');$_SERVER['REQUEST_METHOD']='POST';$_POST['_wpnonce']='bad';rejects(fn()=>$admin->handle(),'http_403');
 $GLOBALS['actor']=0;rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_forbidden');$GLOBALS['actor']=7;
 $m->ready=false;rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_forbidden');check($service->share(41,'en_US')===array(),'unavailable schema fails closed');$m->ready=true;
 rejects(fn()=>$service->save(41,'en_US',$payload,0,3,false),'installation_input');rejects(fn()=>$service->save(41,'de_DE',$payload,0,3,true),'installation_locale');rejects(fn()=>$service->save(41,'en_US',$payload,0,2,true),'installation_skill_changed');
 $db->engine='MyISAM';rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_storage');$db->engine='InnoDB';
 $bad=$payload;$bad['commands'][0]['command']='invented --install';rejects(fn()=>$service->save(41,'en_US',$bad,0,3,true),'installation_public_evidence');
 $bad=$payload;$bad['commands'][0]['platform']='platform-unknown';rejects(fn()=>$service->save(41,'en_US',$bad,0,3,true),'installation_public_evidence');
 $bad=$payload;$bad['rights_note']='short';rejects(fn()=>$service->save(41,'en_US',$bad,0,3,true),'installation_rights_evidence');
 $terms=$db->terms[41];$db->terms[41]=array();rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_license_required');$db->terms[41]=array(array('id'=>9,'slug'=>'license-unknown','name'=>'待核实'));rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_license_unknown');$db->terms[41]=$terms;
 check($db->options===array()&&$db->events===array(),'rejected writes rollback first option initialization');
 $saved=$service->save(41,'en_US',$payload,0,3,true);check($saved['revision']===1&&count($db->events)===1,'explicit manual review commits evidence and audit together');
 $key='aish_installation_41_en_US';check(isset($db->options[$key])&&!isset($db->options['aish_installation_41_zh_CN']),'evidence scoped to stable Skill plus exact language');
 check($service->save(41,'en_US',$payload,0,3,true)===$saved&&count($db->events)===1,'same retry returns same result without duplicate audit');
 $bad=$payload;$bad['rights_note']='A distinct reviewer decision with different evidence.';rejects(fn()=>$service->save(41,'en_US',$bad,0,3,true),'installation_revision_conflict');check(count($db->events)===1,'stale concurrent intent cannot overwrite accepted evidence');
 $public=$service->installation(41,'en_US');check($public['state']==='verified'&&$public['kind']==='editorial'&&$public['source_url']==='https://hub.test/en/skills/41/example/#skill-overview','manual commands accurately labelled as editorial proof');
 check($public['commands']===$payload['commands']&&!isset($public['rights_note'],$public['actor_id'],$public['source_content']),'only allowlisted public fields projected');check(!str_contains(wp_json_encode($public),'permission checked'),'private review rationale is not public');
 check($service->installation(41,'zh_CN')['state']==='unverified','English evidence does not invent Chinese proof');
 check($db->events[0]['action']==='installation_verify'&&$db->events[0]['subject_type']==='skill'&&$db->events[0]['subject_id']===41,'existing workflow ledger uses stable Skill identity');
 check(!str_contains($db->events[0]['metadata_json'],'permission')&&!str_contains($db->events[0]['metadata_json'],'--install'),'audit stores bounded hash, no command or private note');
 check(count(array_filter($db->queries,fn($q)=>str_contains($q[0],'FOR UPDATE')))>=2,'Skill and option use locked reads');check(count(array_filter($db->queries,fn($q)=>str_contains($q[0],"autoload='off'")))===1,'no autoload exposure or repeated write');
 $base=$db->skills[41]['en_US'];foreach(array(array('status'=>'draft'),array('status'=>'pending'),array('status'=>'archived'),array('review_status'=>'pending'),array('reviewed_revision'=>2),array('reviewer_id'=>0),array('reviewed_at'=>null),array('content'=>''),array('summary'=>''))as$change){$db->skills[41]['en_US']=array_replace($base,$change);check($service->installation(41,'en_US')['state']==='unverified'&&$service->share(41,'en_US')===array(),'private/unapproved/incomplete data cannot be shared or copied');}$db->skills[41]['en_US']=$base;
 foreach(array(array('title'=>'changed'),array('content'=>$base['content'].'<p>Changed requirements</p>'),array('revision'=>4,'reviewed_revision'=>4),array('reviewed_at'=>'2026-11-02 00:00:00'))as$change){$db->skills[41]['en_US']=array_replace($base,$change);check($service->installation(41,'en_US')['state']==='unverified','published content/revision change requires new evidence');}$db->skills[41]['en_US']=$base;
 $db->terms[41][0]['name']='Changed license';check($service->installation(41,'en_US')['state']==='unverified','license mutation invalidates proof immediately');$db->terms[41]=$terms;
 $db->terms[41][1]['slug']='platform-another';check($service->installation(41,'en_US')['state']==='unverified','platform mutation invalidates proof immediately');$db->terms[41]=$terms;
 $before=$db->options;$db->failAudit=true;$disabled=array_replace($payload,array('state'=>'disabled'));rejects(fn()=>$service->save(41,'en_US',$disabled,1,3,true),'audit_failure');check($db->options===$before&&count($db->events)===1,'audit failure rolls back evidence, existing proof survives');$db->failAudit=false;
 $service->save(41,'en_US',$disabled,1,3,true);check($service->installation(41,'en_US')['state']==='unverified'&&count($db->events)===2&&$db->events[1]['action']==='installation_disable','manual disable removes copyability without editing/deleting Skill');
 check($db->skills[41]['en_US']===$skill,'evidence workflow preserves all business content and revision IDs');
 foreach(array('{',str_repeat('x',20001),'{}',wp_json_encode(array_replace(SkillExperience::defaults(),array('state'=>'published'))))as$broken){$db->options[$key]=$broken;check($service->installation(41,'en_US')['state']==='unverified','corrupt optional proof fails closed without breaking page');}
 [$db,$m,$service,$payload,$skill]=fixture(true);$source=$db->sources[41][18];
 $bad=$payload;$bad['version_id']=0;rejects(fn()=>$service->save(41,'en_US',$bad,0,3,true),'installation_version_required');$bad['version_id']=999;rejects(fn()=>$service->save(41,'en_US',$bad,0,3,true),'installation_source_changed');
 foreach(array(array('repository_id'=>99),array('source_path'=>'other/SKILL.md'),array('import_commit'=>str_repeat('c',40)),array('import_file'=>str_repeat('c',40)),array('source_status'=>'missing'))as$change){$db->sources[41][18]=array_replace($source,$change);rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_source_changed');}$db->sources[41][18]=$source;
 $db->sources[41][18]['source_content']='changed';rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_source_unbound');$db->sources[41][18]=$source;
 $db->sources[41][18]['source_content']='another command';$db->sources[41][18]['content_hash']=hash('sha256','another command');rejects(fn()=>$service->save(41,'en_US',$payload,0,3,true),'installation_source_evidence');$db->sources[41][18]=$source;
 $service->save(41,'en_US',$payload,0,3,true);$public=$service->installation(41,'en_US');check($public['state']==='verified'&&$public['kind']==='repository'&&$public['commit_sha']===str_repeat('a',40),'matching public and private immutable evidence can be reviewed');
 check($public['source_url']==='https://github.com/example/repository/blob/'.str_repeat('a',40).'/skills/example/SKILL.md','copy evidence pinned to commit, never floating HEAD');
 check(!str_contains(wp_json_encode($public),'PRIVATE')&&!str_contains(wp_json_encode($public),'private'),'source body/findings/private metadata never escape public projection');
 foreach(array(array('import_commit'=>str_repeat('c',40)),array('import_file'=>str_repeat('c',40)),array('source_status'=>'missing'),array('findings_json'=>'{"new":"risk"}'),array('metadata_json'=>'{"new":"dependency"}'),array('change_status'=>'updated'))as$change){$db->sources[41][18]=array_replace($source,$change);check($service->installation(41,'en_US')['state']==='unverified','source or risk update invalidates stale installer proof');}$db->sources[41][18]=$source;
 $share=$service->share(41,'zh_CN');check($share['url']==='https://hub.test/zh/skills/41/example-zh/'&&$share['source_url']==='https://github.com/example/repository/blob/HEAD/skills/example/SKILL.md','share preserves actual requested language and explicit floating repository source');
 check(array_keys($share['social'])===array('X','LinkedIn','Reddit'),'only fixed social providers, no arbitrary share URL');
 foreach($share['social']as$url){parse_str(parse_url($url,PHP_URL_QUERY),$query);check($query['url']===$share['url'],'social payload canonical URL only');}
 foreach(array('?aish_preview=secret','#private')as$suffix){Router::$suffix=$suffix;check($service->share(41,'en_US')===array(),'URL queries and fragments rejected');}Router::$suffix='';
 $db->skills[41]['en_US']['source_path']='../private';check($service->share(41,'en_US')===array()&&$service->installation(41,'en_US')['state']==='unverified','abnormal file paths never make source link');$db->skills[41]['en_US']=$skill;
 $db->skills[41]['en_US']['owner']='github.com@evil';check($service->share(41,'en_US')['source_url']===''&&$service->installation(41,'en_US')['state']==='unverified','repository identity cannot construct arbitrary host');$db->skills[41]['en_US']=$skill;
 foreach(array('aish_preview','preview','aish_home_preview')as$key){$_GET[$key]='secret';$service->renderShare($skill,'en_US');$service->renderInstallation($skill,'en_US');unset($_GET[$key]);}check($GLOBALS['renders']===array(),'private preview hooks do not render sharing or copy controls');
 $service->renderShare($skill,'en_US');$service->renderInstallation($skill,'en_US');check(count($GLOBALS['renders'])===2,'public hook integration available');
 foreach(array('en_US','zh_CN')as$locale){$args=array('installation'=>$public,'locale'=>$locale);ob_start();include $theme.'templates/learning/skill-experience-installation.php';$html=ob_get_clean();check(str_contains($html,'data-copy-target')&&str_contains($html,'role="status"')&&str_contains($html,'tabindex="0"'),'bilingual copy controls with feedback and keyboard code access');check(!str_contains($html,'PRIVATE')&&!str_contains($html,'permission checked'),'template preserves private note boundary');$args=array('share'=>$service->share(41,$locale),'locale'=>$locale);ob_start();include $theme.'templates/learning/skill-experience-share.php';$html=ob_get_clean();check(substr_count($html,'rel="noopener noreferrer"')===3&&str_contains($html,'readonly'),'no third-party SDK and non-JavaScript manual share fallback');}
 [$largeDb,$largeM,$largeService,$largePayload]=fixture();$largePayload['commands']=array();$largeDb->skills[41]['en_US']['content']='<p>Reviewed long quotations, never executed.</p>';
 for($i=0;$i<6;++$i){$cmd='literal '.str_repeat('\\',1800).' '.$i;$largePayload['commands'][]=array('command'=>$cmd,'platform'=>'');$largeDb->skills[41]['en_US']['content'].='<pre><code>'.esc_html($cmd).'</code></pre>';}
 $largeService->save(41,'en_US',$largePayload,0,3,true);check(strlen($largeDb->options['aish_installation_41_en_US'])>20000&&$largeService->installation(41,'en_US')['state']==='verified','valid bounded escaped commands remain readable after serialization');
 echo 'Skill experience isolated assertions: '.$count." passed (stubbed WordPress/storage; no real API or concurrency execution).\n";
}
