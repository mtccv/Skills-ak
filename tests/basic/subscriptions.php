<?php
declare(strict_types=1);
/** Isolated cryptographic/input/transport contracts; no WordPress, DB, SMTP or delivery is implied. */
namespace AISkillsHub\Core\Accounts { final class Store {} }
namespace {
 define('ABSPATH','/isolated/'); $checks=0; $GLOBALS['transport_calls']=0; $GLOBALS['accepted']=true; $GLOBALS['definite']=false;
 function check(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new \RuntimeException($label);}
 function rejects(callable $f,string $label):void{try{$f();}catch(\Throwable $e){check(true,$label);return;}check(false,$label);}
 function wp_parse_url(string $s):array|false{return parse_url($s);} function admin_url(string $p):string{return'https://example.invalid/wp-admin/'.$p;}
 function add_action(...$args):void{} function remove_action(...$args):void{} function apply_filters(string $n,mixed $v):mixed{return$n==='aish_account_mail_rejection_is_definite'?$GLOBALS['definite']:$v;}
 function wp_mail(string $to,string $subject,string $body,array $headers):bool{++$GLOBALS['transport_calls'];$GLOBALS['headers']=$headers;return$GLOBALS['accepted'];}
 require __DIR__.'/../../wp-content/themes/ai-skills-hub/inc/core/Learning/Subscriptions.php';
 require __DIR__.'/../../wp-content/themes/ai-skills-hub/inc/core/Accounts/Crypto.php';
 require __DIR__.'/../../wp-content/themes/ai-skills-hub/inc/core/Accounts/Mail.php';
 $prefs=['locale'=>'zh_CN','weekly'=>true,'new_resources'=>false,'consent'=>true]; $class=\AISkillsHub\Core\Learning\Subscriptions::class;
 check($class::preferences($prefs)===['locale'=>'zh_CN','weekly'=>true,'new_resources'=>false],'Consent yields only fixed topic preferences');
 foreach([['consent'=>false],['consent'=>'true'],['weekly'=>1],['weekly'=>false],['locale'=>'fr_FR'],['email'=>'person@example.invalid'],['user_id'=>4],['role'=>'administrator']]as$changes)rejects(fn()=>$class::preferences(array_replace($prefs,$changes)),'Reject implicit consent, identity injection and invalid types');
 check($class::preferences(array_replace($prefs,['locale'=>'en_US','new_resources'=>true]))['locale']==='en_US','English maintained independently');
 check($class::defaults()===['revision'=>0,'enabled'=>false,'paused'=>true],'Disabled and paused until configured');
 check($class::validateConfig(['enabled'=>true,'paused'=>false])===['enabled'=>true,'paused'=>false],'Fixed administrator settings');
 foreach([['enabled'=>1],['automatic_weekly'=>true],['recipient'=>'test@example.invalid']]as$v)rejects(fn()=>$class::validateConfig($v),'No unreviewed automatic issue switch or coercion');
 putenv('AISH_ACCOUNT_MAIL_KEY_VERSION=isolated1');putenv('AISH_ACCOUNT_MAIL_KEY_ISOLATED1='.base64_encode(random_bytes(32)));putenv('AISH_ACCOUNT_MAIL_KEY_ISOLATED2='.base64_encode(random_bytes(32)));
 $crypto=\AISkillsHub\Core\Accounts\Crypto::class;$nonce=bin2hex(random_bytes(32));$a=$crypto::newsletterToken(17,1,$nonce);check((bool)preg_match('/^[a-f0-9]{64}$/D',$a['token']),'Opaque cancellation credential contains no email/ID');
 check($crypto::newsletterToken(17,1,$nonce)===$a,'Old and new emails in same consent generation share cancel capability');
 foreach([[18,1,$nonce],[17,2,$nonce],[17,1,bin2hex(random_bytes(32))]]as$args)check($crypto::newsletterToken(...$args)['token']!==$a['token'],'Identity, generation and randomness are domain separated');
 putenv('AISH_ACCOUNT_MAIL_KEY_VERSION=isolated2');check($crypto::newsletterToken(17,1,$nonce,'isolated1')===$a,'Retained prior key supports cancellation from older messages');check($crypto::newsletterToken(17,1,$nonce)['token']!==$a['token'],'New key version derives distinct tokens');
 putenv('AISH_ACCOUNT_MAIL_KEY_ISOLATED1');rejects(fn()=>$crypto::newsletterToken(17,1,$nonce,'isolated1'),'Missing historical key fails closed');
 foreach([[0,1,$nonce],[17,0,$nonce],[17,1,'bad']]as$args)rejects(fn()=>$crypto::newsletterToken(...$args),'Invalid token context rejected');
 $mail=new \AISkillsHub\Core\Accounts\Mail(new \AISkillsHub\Core\Accounts\Store());$url=admin_url('admin-post.php').'?action=aish_subscription_unsubscribe&token='.str_repeat('a',64);$message=['subject'=>'isolated','body'=>'body','unsubscribe_url'=>$url];
 check($mail->send('recipient@example.invalid',$message)==='accepted','Mock acceptance uses accepted not delivered');check(in_array('List-Unsubscribe: <'.$url.'>',$GLOBALS['headers'],true),'Fixed cancellation header emitted');check(in_array('List-Unsubscribe-Post: List-Unsubscribe=One-Click',$GLOBALS['headers'],true),'HTTPS supports RFC8058 cancel-only handler');
 foreach([str_replace('https://','https://user@',$url),str_replace('example.invalid','attacker.invalid',$url),$url.'&extra=1',str_replace('aish_subscription_unsubscribe','another_action',$url),$url."\r\nBcc: bad@example.invalid",str_replace('admin-post.php','index.php',$url),$url.'#fragment']as$bad){$before=$GLOBALS['transport_calls'];rejects(fn()=>$mail->send('recipient@example.invalid',array_replace($message,['unsubscribe_url'=>$bad])),'Unsafe header/endpoint rejected');check($GLOBALS['transport_calls']===$before,'Invalid cancellation URL never reaches transport');}
 $GLOBALS['accepted']=false;check($mail->send('recipient@example.invalid',$message)==='uncertain','Mock false without trusted classification is not retried as a rejection');$GLOBALS['definite']=true;check($mail->send('recipient@example.invalid',$message)==='rejected','Trusted provider classification permits safe rejection retry');
 echo "PASS: $checks isolated newsletter contracts; no real mail delivery.\n";
}
