<?php
declare(strict_types=1);
// Isolated protocol + transactional storage model; no real WordPress, SQL, browser or API.
namespace AISkillsHub\Core\Database {
	final class Migrator { public function isCurrent(): bool { return true; } }
	final class Database {
		public array $events = array(); public array $daily = array(); public array $options = array(); public array $queries = array(); public bool $busy = false; public bool $fail = false;
		public function prefix(): string { return 'commercial_'; }
		public function table( string $name ): string { return 'core_' . $name; }
		public function connection(): object { return new class { public string $options = 'wp_options'; public function esc_like( string $s ): string { return addcslashes( $s, '_%' ); } }; }
		public function transaction( callable $work ): mixed { $snapshot = array( $this->events, $this->daily, $this->options ); try { return $work(); } catch ( \Throwable $error ) { [ $this->events, $this->daily, $this->options ] = $snapshot; throw $error; } }
		public function value( string $sql, array $args = array() ): mixed {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'GET_LOCK' ) ) { return $this->busy ? 0 : 1; }
			if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { return 1; }
			if ( str_contains( $sql, 'SELECT ENGINE' ) ) { return 'InnoDB'; }
			if ( str_contains( $sql, 'SELECT option_value' ) ) { return $this->options[ $args[0] ] ?? null; }
			if ( str_contains( $sql, 'SELECT COALESCE(SUM(metric_count)' ) ) { $n = 0; foreach ( $this->daily as $r ) { if ( in_array( $r['metric_name'], array( 'sponsor_impression','sponsor_click' ), true ) ) { $n += $r['metric_count']; } } return $n; }
			throw new \RuntimeException( 'Unexpected test scalar query' );
		}
		public function row( string $sql, array $args = array() ): ?array {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'FROM wp_options' ) ) { return array( 'option_value' => '{}' ); }
			if ( str_contains( $sql, 'FROM core_skills' ) ) { return array( 'id' => $args[0] ); }
			if ( str_contains( $sql, 'FROM core_metric_events' ) ) { foreach ( $this->events as $r ) { if ( $r['event_day'] === $args[0] && $r['metric_name'] === $args[1] && $r['dedupe_key'] === $args[2] ) { return array( 'id' => 1 ); } } return null; }
			throw new \RuntimeException( 'Unexpected test row query' );
		}
		public function insert( string $entity, array $record ): int { if ( 'metric_events' !== $entity ) { throw new \RuntimeException( 'Unexpected storage entity' ); } $this->events[] = $record; return count( $this->events ); }
		public function execute( string $sql, array $args = array() ): int {
			$this->queries[] = $sql;
			if ( str_contains( $sql, 'INSERT INTO core_daily_metrics' ) ) { if ( $this->fail ) { throw new \RuntimeException( 'simulated database fault with sensitive diagnostic' ); } [ $day,$metric,$key,$locale,$policy ]=$args; $id=join(':',array($day,$metric,$key,$locale)); $this->daily[$id]??=array('metric_name'=>$metric,'metric_count'=>0,'dimension_key'=>$key,'locale'=>$locale,'policy_version'=>$policy); ++$this->daily[$id]['metric_count']; return 1; }
			if ( str_contains( $sql, 'INSERT INTO wp_options' ) ) { if ( str_contains( $sql, 'UPDATE option_name=' ) ) { $this->options[$args[0]]??=$args[1]; } else { $this->options[$args[0]]=$args[1]; } return 1; }
			if ( str_starts_with( $sql, 'DELETE ' ) ) { return 0; }
			throw new \RuntimeException( 'Unexpected test write query' );
		}
		public function rows( string $sql, array $args = array() ): array {
			$this->queries[]=$sql;
			if ( str_starts_with( $sql, 'SELECT option_name' ) ) { return array(); }
			$rows=array(); foreach($this->daily as $r) { if($r['locale']!==$args[1]){continue;} $key=str_contains($sql,'GROUP BY metric_name')?$r['metric_name']:$r['dimension_key'].':'.$r['metric_name']; $rows[$key]??=array('dimension_key'=>$r['dimension_key'],'metric_name'=>$r['metric_name'],'total'=>0); $rows[$key]['total']+=$r['metric_count']; } return array_values($rows);
		}
	}
}
namespace AISkillsHub\Core\Discovery {
	final class Settings { public static bool $tracking=true; public static function tracking():bool{return self::$tracking;} }
	final class Views { public ?string $observer=null; public bool $eligible=true; public function analyticsEligible():bool{return $this->eligible;} public function commercialObserver():?string{return $this->observer;} public function grantCommercialConsent():string{return $this->observer=str_repeat('c',64);} public function withdrawAnalyticsConsent():void{$this->observer=null;} }
}
namespace AISkillsHub\Core\Frontend { final class Router { public static function siteUrl():string{return 'https://directory.example';} } }
namespace AISkillsHub\Core\Commercial { final class Sponsors { public const CAPABILITY='aish_manage_sponsors';public const OPTION='aish_commercial_sponsors'; public ?array $entry=array('id'=>7,'revision'=>3,'placement'=>'home_after_search','locale'=>'en_US','skill_id'=>41); public function publicEntry(int $id,string $locale):?array{return null===$this->entry?null:array_replace($this->entry,array('locale'=>$locale));} } }
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit(1); } define('ABSPATH',__DIR__.'/');define('DAY_IN_SECONDS',86400);define('WP_CLI',true);
	$GLOBALS['caps']=array();$GLOBALS['options']=array();
	function current_user_can(string $cap):bool{return in_array($cap,$GLOBALS['caps'],true);} function wp_salt(string $s):string{return 'isolated-commercial-test-salt';} function wp_parse_url(string $s):array|false{return parse_url($s);} function admin_url(string $s):string{return 'https://directory.example/wp-admin/'.$s;}
	function wp_create_nonce(string $s):string{return 'nonce';}function wp_verify_nonce(string $s,string $a):bool{return 'nonce'===$s&&'aish_commercial_metric'===$a;} function wp_unslash(mixed $v):mixed{return $v;} function wp_cache_delete(string $k,string $g):void{}function nocache_headers():void{}function get_option(string $k,mixed $d=false):mixed{return $GLOBALS['options'][$k]??$d;}function update_option(string $k,mixed $v,bool $a=false):bool{$GLOBALS['options'][$k]=$v;return true;}
	function wp_next_scheduled(string $s):bool{return false;}function wp_schedule_single_event(int $t,string $s):bool{return true;}
	function esc_html(string $s):string{return htmlspecialchars($s,ENT_QUOTES);}function esc_attr(string $s):string{return esc_html($s);}function esc_url(string $s):string{return esc_html($s);}
	final class Reply extends \RuntimeException { public function __construct(public array $data,public int $status,public bool $success){parent::__construct('test JSON transport');} }
	function wp_send_json_error(array $v,int $s):never{throw new Reply($v,$s,false);}function wp_send_json_success(array $v,int $s):never{throw new Reply($v,$s,true);}
	require dirname(__DIR__,2).'/wp-content/themes/ai-skills-hub/inc/core/Commercial/Metrics.php';
	use AISkillsHub\Core\Commercial\Metrics;use AISkillsHub\Core\Commercial\Sponsors;use AISkillsHub\Core\Database\Database;use AISkillsHub\Core\Database\Migrator;use AISkillsHub\Core\Discovery\Views;use AISkillsHub\Core\Discovery\Settings;
	$count=0;function check(bool $v,string $message):void{global $count;++$count;if(!$v){throw new \RuntimeException($message);}}function rejects(callable $f,string $message):void{try{$f();}catch(\Throwable $e){check(true,$message);return;}throw new \RuntimeException($message);}
	$db=new Database();$views=new Views();$sponsors=new Sponsors();$metrics=new Metrics($db,new Migrator(),$views,$sponsors);
	$call=static fn(string $m,mixed ...$args):mixed=>(new \ReflectionMethod($metrics,$m))->invoke($metrics,...$args);
	$proof=$call('proof',$sponsors->entry);$decoded=$call('decode',$proof);check($decoded['id']===7&&$decoded['revision']===3&&strlen($decoded['request'])===32,'signed proof binds only display coordinates');
	check(!str_contains(json_encode($decoded),'url')&&!str_contains(json_encode($decoded),'observer'),'proof omits destination and visitor identity');
	$sign=static function(array $v)use($call):string{$b=rtrim(strtr(base64_encode(json_encode($v)),'+/','-_'),'=');return $b.'.'.$call('mac','proof:'.$b);};
	foreach(array(array('id'=>'7'),array('id'=>0),array('revision'=>0),array('locale'=>'xx'),array('placement'=>'arbitrary'),array('issued'=>time()+2),array('issued'=>time()-1900),array('request'=>'bad'),array('redirect'=>'https://evil.example'))as $change){rejects(fn()=>$call('decode',$sign(array_replace($decoded,$change))),'invalid signed context rejected');}
	rejects(fn()=>$call('decode',str_repeat('a',1201)),'oversized proof rejected');rejects(fn()=>$call('decode',$proof.'a'),'tampered proof rejected');
	foreach(array('https://directory.example','https://directory.example:443')as $origin){$_SERVER['HTTP_ORIGIN']=$origin;check($call('sameOrigin'),'own origin allowed');}
	foreach(array('http://directory.example','https://evil.example','https://directory.example:444','https://directory.example/path','https://name@directory.example','null','')as $origin){$_SERVER['HTTP_ORIGIN']=$origin;check(!$call('sameOrigin'),'foreign and malformed origins rejected');}
	Settings::$tracking=false;check(array()===$metrics->attributes($sponsors->entry),'default tracking off produces no measurement DOM');Settings::$tracking=true;$views->eligible=false;check(array()===$metrics->attributes($sponsors->entry),'excluded observer receives no metric DOM');$views->eligible=true;
	$GLOBALS['caps']=array(Sponsors::CAPABILITY);check(array()===$metrics->attributes($sponsors->entry),'sponsor administrators excluded');$GLOBALS['caps']=array();check(isset($metrics->attributes($sponsors->entry)['data-commercial-proof']),'eligible live display gets proof');
	$req=static function(array $input)use($metrics):Reply{$_POST=$input+array('action'=>Metrics::ACTION,'nonce'=>'nonce');try{$metrics->handle();}catch(Reply $r){return $r;}throw new \RuntimeException('Missing reply');};
	$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']='https://directory.example';$signed=$sign(array_replace($decoded,array('issued'=>time()-2)));
	$r=$req(array('step'=>'start','proof'=>$signed));check($r->success&&$r->data['state']==='consent_required'&&!$db->events,'existing analytics consent alone cannot imply sponsorship consent');
	$r=$req(array('step'=>'record','proof'=>$signed,'event'=>'impression'));check($r->data['state']==='consent_required'&&!$db->events,'direct record without explicit purpose consent persists nothing');
	$r=$req(array('step'=>'consent','proof'=>$signed));check($r->success&&$r->data['state']==='ready'&&!$db->events,'consent creates observer only, no fabricated impression');
	foreach(array(array('nonce'=>'wrong'),array('redirect'=>'https://evil.example'),array('event'=>'skill_view'),array('proof'=>array()),array('step'=>'publish'))as $change){$r=$req(array_replace(array('step'=>'record','proof'=>$signed,'event'=>'impression'),$change));check(!$r->success&&$r->status===400&&!$db->events,'invalid action nonce event or redirect field rejected');}
	$r=$req(array('step'=>'record','proof'=>$signed,'event'=>'impression'));check($r->success&&$r->data['state']==='recorded'&&count($db->events)===2,'first genuine response atomically adds raw and deduped impression');
	$first=$db->daily;$r=$req(array('step'=>'record','proof'=>$signed,'event'=>'impression'));check($r->data['state']==='already_counted'&&$first===$db->daily,'same response retry is idempotent');
	$second=$sign(array_replace($decoded,array('issued'=>time()-2,'request'=>str_repeat('b',32))));$r=$req(array('step'=>'record','proof'=>$second,'event'=>'impression'));check($r->data['state']==='recorded'&&count($db->events)===3,'new response records observation but not second unique observer');
	$r=$req(array('step'=>'record','proof'=>$second,'event'=>'click'));check($r->data['state']==='recorded'&&count($db->events)===5,'click is distinct from display');
	$r=$req(array('step'=>'record','proof'=>$second,'event'=>'click'));check($r->data['state']==='already_counted'&&count($db->events)===5,'repeat click on same response idempotent');
	check(!str_contains(json_encode(array($db->events,$db->daily,$db->options)),str_repeat('c',64))&&!str_contains(json_encode($db->events),'directory.example'),'no raw observer URL or personal identity persisted');
	check(array_unique(array_column($db->events,'policy_version'))===array(Metrics::POLICY)&&!in_array('skill_view',array_column($db->events,'metric_name'),true),'all events belong exclusively to commercial policy');
	$GLOBALS['caps']=array(Metrics::CAPABILITY);$GLOBALS['options'][Metrics::COVERAGE]=gmdate('Y-m-d H:i:s');$summary=$metrics->summary(7,'en_US');check($summary['totals']===array('sponsor_impression'=>2,'sponsor_click'=>1,'sponsor_impression_unique'=>1,'sponsor_click_unique'=>1),'known observations and deduped totals independently recompute');$GLOBALS['caps']=array();
	$before=count($db->queries);rejects(fn()=>$metrics->summary(7,'en_US'),'unauthorized reporting rejected');check($before===count($db->queries),'no unauthorized aggregate queries');
	$before=array($db->events,$db->daily,$db->options);$db->fail=true;$fresh=$sign(array_replace($decoded,array('issued'=>time()-2,'request'=>str_repeat('d',32))));$r=$req(array('step'=>'record','proof'=>$fresh,'event'=>'impression'));check(!$r->success&&$r->status===503&&$before===array($db->events,$db->daily,$db->options),'daily write failure rolls back event quota and coverage');check(!str_contains(json_encode($r->data),'sensitive'),'errors use fixed safe payload');$db->fail=false;
	foreach($db->options as $key=>$value){if(str_starts_with($key,'_transient_aish_commercial_budget_')){$db->options[$key]='100';}}$before=array($db->events,$db->daily,$db->options);$r=$req(array('step'=>'record','proof'=>$fresh,'event'=>'impression'));check(!$r->success&&$r->status===400&&$before===array($db->events,$db->daily,$db->options),'budget exhausted rejects without partial commit');check(isset($GLOBALS['options'][Metrics::HEALTH][gmdate('Y-m-d')]['daily_cap']),'fixed daily cap coverage gap recorded without personal information');
	$db->busy=true;$r=$req(array('step'=>'record','proof'=>$fresh,'event'=>'impression'));check(!$r->success&&$r->status===503,'busy concurrent worker fails without duplicate write');$db->busy=false;
	$sponsors->entry['revision']=4;$r=$req(array('step'=>'record','proof'=>$fresh,'event'=>'impression'));check(!$r->success&&$r->status===400,'revised sponsorship invalidates stale proof');$sponsors->entry=null;$r=$req(array('step'=>'record','proof'=>$fresh,'event'=>'impression'));check(!$r->success&&$r->status===400,'disabled expired or private Skill projection prevents observations');
	$r=$req(array('step'=>'withdraw'));check($r->success&&null===$views->observer,'withdrawal works even when display unavailable');
	$metrics->cleanup();check(count(array_filter($db->queries,static fn($s)=>str_starts_with($s,'DELETE FROM core_')&&str_contains($s,'policy_version=%s')&&str_contains($s,'LIMIT 200')))===2,'retention deletion limited to own policy and bounded batches');
	echo "Commercial metrics isolated: $count passed; mock storage and HTTP, no real service assertions.\n";
}
