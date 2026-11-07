<?php
declare(strict_types=1);
/** Isolated HTTP/UI authorization contracts. No WordPress, database or mail service. */
namespace AISkillsHub\Core\Learning {
 final class Subscriptions {
  public const CAPABILITY='aish_manage_subscriptions'; public array $calls=[];
  public function request(array $p,string $key):void{$this->calls[]=['request',$p,$key];}
  public function accountUnsubscribe():void{$this->calls[]=['stop'];}
  public function accountPreferences():?array{return ['state'=>'active','locale'=>'en_US','weekly'=>true,'new_resources'=>false,'revision'=>2];}
  public function confirm(string $token):string{$this->calls[]=['confirm',$token];return 'active';}
  public function unsubscribe(string $token):bool{$this->calls[]=['unsubscribe',$token];return true;}
  public function saveConfig(array $v,int $r):array{$this->calls[]=['config',$v,$r];return $v;}
  public function setState(int $id,int $r,string $state,string $reason):void{$this->calls[]=['state',$id,$r,$state,$reason];}
  public function markBounce(int $id,int $r,string $reason):void{$this->calls[]=['bounce',$id,$r,$reason];}
  public function queueIssue(string $l,string $k,string $key,string $fingerprint):int{$this->calls[]=['queue',$l,$k,$key,$fingerprint];return 1;}
  public function retryIssue(int $id):void{$this->calls[]=['retry',$id];}
 }
}
namespace AISkillsHub\Core\Accounts {final class AccountService{} final class Frontend{public static function maskEmail(string $s):string{return 'q•••@example.invalid';}public static function loginUrl(string $s,string $l):string{return 'https://example.invalid/en/login/?return_to='.rawurlencode($s);}public static function url(string $r,string $l):string{return 'https://example.invalid/'.($l==='zh_CN'?'zh':'en').'/'.$r.'/';}}}
namespace AISkillsHub\Core\Frontend {final class Router{public static function siteUrl(string $p=''):string{return 'https://example.invalid/base'.$p;}public static function url(string $r,array $p,string $l):string{return self::siteUrl('/'.($l==='zh_CN'?'zh':'en').'/'.$r.'/');}}final class View{public static function icon(string $name):string{return '<svg aria-hidden="true"></svg>';}}}
namespace {
 define('ABSPATH','/test/');$GLOBALS['uid']=7;$GLOBALS['cap']=true;$GLOBALS['logged']=true;$checks=0;
 function a(bool $value,string $label):void{global $checks;++$checks;if(!$value)throw new \RuntimeException($label);}
 function reject(callable $fn,string $label):void{try{$fn();}catch(\Throwable $e){a(true,$label);return;}a(false,$label);}
 function is_ssl():bool{return true;} function wp_parse_url(string $v, int $component=-1):mixed{return parse_url($v,$component);}
 function wp_json_encode(mixed $v):string{return json_encode($v,JSON_THROW_ON_ERROR);}function wp_salt(string $s):string{return 'isolated-'.$s;}
 function get_current_user_id():int{return $GLOBALS['uid'];}function current_user_can(string $c):bool{return $GLOBALS['cap'];}function is_user_logged_in():bool{return $GLOBALS['logged'];}function wp_get_current_user():object{return(object)['user_email'=>'private@example.invalid'];}
 function wp_nonce_field(string $a,string $name='_wpnonce',bool $referer=true,bool $echo=true):string{$s='<input name="'.htmlspecialchars($name).'" value="nonce-'.htmlspecialchars($a).'">';if($echo)echo $s;return $s;}
 function wp_verify_nonce(string $n,string $a):bool{return $n==='nonce-'.$a;}function wp_unslash(mixed $v):mixed{return $v;}
 function esc_attr(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}function esc_html(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}function esc_url(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
 function selected(mixed $a,mixed $b,bool $echo=true):string{$s=$a===$b?'selected="selected"':'';if($echo)echo $s;return$s;}function checked(mixed $a,mixed $b=true,bool $echo=true):string{$s=$a===$b?'checked="checked"':'';if($echo)echo $s;return$s;}
 function nocache_headers():void{} function status_header(int $s):void{} function wp_die(string $s,string $title='',array $args=[]):never{throw new \RuntimeException('wpdie:'.($args['response']??500));}
 function admin_url(string $s=''):string{return'https://example.invalid/base/wp-admin/'.$s;}function add_query_arg(array $args,string $url):string{return$url.'?'.http_build_query($args);}function wp_safe_redirect(string $url,int $code):never{throw new \LogicException('redirect');}
 function get_user_locale():string{return 'en_US';}function bloginfo(string $s):void{echo'UTF-8';}function wp_head():void{}function body_class(string $s):void{echo'class="'.esc_attr($s).'"';}function wp_body_open():void{}function wp_footer():void{}
 require __DIR__.'/../../wp-content/themes/ai-skills-hub/inc/core/Learning/SubscriptionFrontend.php';require __DIR__.'/../../wp-content/themes/ai-skills-hub/inc/core/Learning/SubscriptionAdmin.php';
 $service=new \AISkillsHub\Core\Learning\Subscriptions();$view=new \AISkillsHub\Core\Learning\SubscriptionFrontend($service,new \AISkillsHub\Core\Accounts\AccountService());$admin=new \AISkillsHub\Core\Learning\SubscriptionAdmin($service);
 $context=new \ReflectionProperty($view,'context');$browser=new \ReflectionProperty($view,'browser');$browser->setValue(null,str_repeat('a',64));
 $base=['route'=>'newsletter','locale'=>'en_US','status'=>200,'error'=>'','message'=>'','preferences'=>null,'credential'=>false,'eligible'=>true,'availability'=>['enabled'=>true,'paused'=>false,'mail_ready'=>true],'values'=>[]];$context->setValue(null,$base);
 $post=new \ReflectionMethod($view,'receive');$csrf=fn($action)=>hash_hmac('sha256',$action.'|'.$GLOBALS['uid'].'|'.str_repeat('a',64),wp_salt('nonce'));
 $data=['subscription_action'=>'request','locale'=>'en_US','weekly'=>'1','consent'=>'1','request_key'=>str_repeat('b',64),'_aish_nonce'=>'nonce-aish_subscription_request','subscription_csrf'=>$csrf('request')];
 $_SERVER=['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>'https://example.invalid'];$_POST=$data;$post->invoke($view);a($service->calls[0]===['request',['locale'=>'en_US','weekly'=>true,'new_resources'=>false,'consent'=>true],str_repeat('b',64)],'Only fixed current-user preferences cross the service boundary');
 a(!array_key_exists('email',$service->calls[0][1]),'No arbitrary recipient email parameter');
 foreach (['email'=>'other@example.invalid','user_id'=>'99','role'=>'administrator','token'=>str_repeat('c',64)] as$key=>$value){$_POST=$data+[$key=>$value];reject(fn()=>$post->invoke($view),'Reject identity/token injection '.$key);}
 $_POST=$data;unset($_POST['consent']);reject(fn()=>$post->invoke($view),'Consent cannot be implicit');
 $_POST=$data;unset($_POST['weekly']);reject(fn()=>$post->invoke($view),'At least one topic needed');
 $_POST=$data;$_POST['weekly']=['1'];reject(fn()=>$post->invoke($view),'Array checkbox rejected');
 $_POST=$data;$_POST['_aish_nonce']='bad';reject(fn()=>$post->invoke($view),'Bad nonce rejected');
 $_POST=$data;$_POST['subscription_csrf']='bad';reject(fn()=>$post->invoke($view),'Browser binding required');
 $_POST=$data;$_SERVER['HTTP_ORIGIN']='https://attacker.invalid';reject(fn()=>$post->invoke($view),'Cross-origin form rejected');
 $_SERVER['HTTP_ORIGIN']='https://example.invalid';$_POST=$data;$GLOBALS['uid']=8;reject(fn()=>$post->invoke($view),'A copied form cannot switch account identity');$GLOBALS['uid']=7;
 $context->setValue(null,array_replace($base,['eligible'=>false]));reject(fn()=>$post->invoke($view),'Unverified user cannot request marketing');$context->setValue(null,$base);
 $_POST=['subscription_action'=>'stop','_aish_nonce'=>'nonce-aish_subscription_stop','subscription_csrf'=>$csrf('stop')];$post->invoke($view);a(end($service->calls)===['stop'],'Authenticated cancellation calls self-only service');
 a(\AISkillsHub\Core\Learning\SubscriptionFrontend::url('confirm','en_US')==='https://example.invalid/base/en/newsletter/confirm/','Subdirectory aware clean confirmation URL');
 reject(fn()=>\AISkillsHub\Core\Learning\SubscriptionFrontend::url('../escape','en_US'),'No arbitrary route');reject(fn()=>\AISkillsHub\Core\Learning\SubscriptionFrontend::url('newsletter','fr_FR'),'No invented locale');
 $cookie=new \ReflectionMethod($view,'storeCredential');$read=new \ReflectionMethod($view,'readCredential');$cookie->invoke($view,'confirm',str_repeat('e',64));a($read->invoke($view,'confirm')===str_repeat('e',64),'Signed private browser credential can be read');
 a($read->invoke($view,'unsubscribe')==='','Credentials are purpose separated');$browser->setValue(null,str_repeat('f',64));a($read->invoke($view,'confirm')==='','Credential cannot be replayed in another browser context');$browser->setValue(null,str_repeat('a',64));
 $name='__Host-aish_newsletter_confirm';$_COOKIE[$name].='tamper';a($read->invoke($view,'confirm')==='','Cookie tampering rejected');
 $service->calls=[];$_SERVER=['REQUEST_METHOD'=>'GET'];$_POST=[];reject(fn()=>$admin->receive(),'Admin GET cannot mutate');a(!$service->calls,'GET produces no service writes');
 $_SERVER=['REQUEST_METHOD'=>'POST'];$_POST=['operation'=>'settings','_wpnonce'=>'nonce-aish_subscriptions_admin','confirm'=>'1','revision'=>'2','enabled'=>'1','paused'=>'1'];$GLOBALS['cap']=false;reject(fn()=>$admin->receive(),'Independent campaign capability required');a(!$service->calls,'Capability failure no write');$GLOBALS['cap']=true;
 $_POST['_wpnonce']='bad';reject(fn()=>$admin->receive(),'Admin nonce required');a(!$service->calls,'Nonce failure no write');$_POST['_wpnonce']='nonce-aish_subscriptions_admin';
 try{$admin->receive();}catch(\LogicException $e){}a($service->calls[0]===['config',['enabled'=>true,'paused'=>true],2],'Admin saves only fixed settings with expected revision');
 $service->calls=[];$_POST=['operation'=>'state','_wpnonce'=>'nonce-aish_subscriptions_admin','confirm'=>'1','id'=>'7','revision'=>'2','state'=>'active','reason'=>'Attempt admin opt-in'];try{$admin->receive();}catch(\LogicException $e){}a(!$service->calls,'Admin cannot force active subscription');
 $_POST['state']='bounced';try{$admin->receive();}catch(\LogicException $e){}a($service->calls[0][0]==='bounce','Bounce evidence has dedicated service path');
 $service->calls=[];$_POST=['operation'=>'queue','_wpnonce'=>'nonce-aish_subscriptions_admin','locale'=>'en_US','kind'=>'weekly','request_key'=>str_repeat('b',64),'preview_fingerprint'=>str_repeat('d',64)];try{$admin->receive();}catch(\LogicException $e){}a(!$service->calls,'Queue requires explicit operator confirmation');
 $_POST['confirm']='1';$preview=$_POST['preview_fingerprint'];unset($_POST['preview_fingerprint']);try{$admin->receive();}catch(\LogicException $e){}a(!$service->calls,'Queue requires the reviewed preview fingerprint');$_POST['preview_fingerprint']=$preview;
 $_POST['confirm']='1';try{$admin->receive();}catch(\LogicException $e){}a($service->calls[0]===['queue','en_US','weekly',str_repeat('b',64),str_repeat('d',64)],'Queue uses bounded locale/kind idempotent request key only');
 $_POST=['List-Unsubscribe'=>'wrong'];$_GET=['token'=>str_repeat('a',64)];reject(fn()=>$view->oneClick(),'RFC unsubscribe requires exact body');$_SERVER['REQUEST_METHOD']='GET';reject(fn()=>$view->oneClick(),'GET link scanners cannot unsubscribe through RFC POST endpoint');
 $context->setValue(null,array_replace($base,['eligible'=>false]));$_POST=['subscription_action'=>'stop','_aish_nonce'=>'nonce-aish_subscription_stop','subscription_csrf'=>$csrf('stop')];$post->invoke($view);a(end($service->calls)===['stop'],'Unverified owner may still cancel');
 $flash=new \ReflectionMethod($view,'flash');$readFlash=new \ReflectionMethod($view,'readFlash');$context->setValue(null,$base);$flash->invoke($view,'confirmed');$readFlash->invoke($view);a($context->getValue()['message']==='confirmed','PRG flash displays only a signed success result');$context->setValue(null,$base);$readFlash->invoke($view);a($context->getValue()['message']==='','Flash consumed once, refresh cannot resubmit confirmation');
 $context->setValue(null,array_replace($base,['eligible'=>false]));ob_start();require __DIR__.'/../../wp-content/themes/ai-skills-hub/templates/learning/subscriptions.php';$limited=ob_get_clean();a(str_contains($limited,'name="subscription_action" value="stop"'),'Restricted owner retains cancellation form');a(!str_contains($limited,'name="consent"'),'Restricted owner has no subscription opt-in form');
 $context->setValue(null,$base);$GLOBALS['logged']=true;ob_start();require __DIR__.'/../../wp-content/themes/ai-skills-hub/templates/learning/subscriptions.php';$html=ob_get_clean();a(!str_contains($html,'private@example.invalid'),'Public rendering never exposes full account email');a(!str_contains($html,'name="email"'),'No editable recipient field');a((bool)preg_match('/name="consent" value="1" required/',$html),'Opt-in is required and never prechecked');a(!str_contains($html,str_repeat('e',64)),'Confirmation credential never in HTML');a(str_contains($html,'name="weekly"')&&str_contains($html,'name="new_resources"'),'Separate optional topics');
 $GLOBALS['logged']=false;ob_start();require __DIR__.'/../../wp-content/themes/ai-skills-hub/templates/learning/subscriptions.php';$guest=ob_get_clean();a(!str_contains($guest,'name="consent"'),'Guest gets sign-in guidance instead of guest signup');a(str_contains($guest,'return_to='),'Sign-in retains fixed newsletter return');

 $context->setValue(null,array_replace($base,['route'=>'confirm','eligible'=>false,'credential'=>false]));$GLOBALS['logged']=false;ob_start();require __DIR__.'/../../wp-content/themes/ai-skills-hub/templates/learning/subscriptions.php';$confirmation=ob_get_clean();a(str_contains($confirmation,'Sign in to confirm')&&str_contains($confirmation,'return_to='),'Anonymous confirmation guides owner sign-in with clean return');a(!str_contains($confirmation,'name="subscription_action" value="confirm"'),'Anonymous recipient cannot confirm directly');
 $_POST=['subscription_action'=>'confirm','_aish_nonce'=>'nonce-aish_subscription_confirm','subscription_csrf'=>$csrf('confirm')];reject(fn()=>$post->invoke($view),'Confirmation requires verified current owner before service invocation');
 echo "PASS: $checks subscription UI isolated authorization, privacy and rendering assertions; no real WP/MySQL/email.\n";
}
