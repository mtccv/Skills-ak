<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use AISkillsHub\Core\Learning\Onboarding;
use AISkillsHub\Core\Accounts\Locks;
$onboarding = new Onboarding($db, $accounts, new Locks($db));
$member = p14_user('help-owner'); $other = p14_user('help-other'); $pending = p14_user('help-pending', false);
$metaTable = $db->connection()->usermeta;
$read = fn(int $id): array => $db->rows("SELECT meta_key,meta_value FROM $metaTable WHERE user_id=%d AND meta_key=%s", [$id, Onboarding::META]);
p14_case('no_implicit_state_or_subscription_on_native_registration', function() use($member,$other,$pending,$read,$db) {
 foreach([$member,$other,$pending] as $id) { p14_assert($read($id)===[], 'No onboarding state until explicit dismissal'); p14_assert(!$db->value('SELECT COUNT(*) FROM '.$db->connection()->usermeta." WHERE user_id=%d AND meta_key LIKE %s",[$id,'%newsletter%']), 'No implicit newsletter subscription'); }
});
p14_case('current_owner_ordered_whitelist_idempotency_and_locale_independence', function() use($member,$other,$onboarding,$read) {
 wp_set_current_user($member); p14_assert($onboarding->mine()===['version'=>1,'completed'=>[]], 'Initial help state empty');
 $onboarding->complete('search'); $onboarding->complete('welcome'); $before=$read($member); $onboarding->complete('search');
 p14_assert($read($member)===$before && count($before)===1, 'Repeat produces neither duplicate row nor extra data');
 p14_assert($onboarding->mine()===['version'=>1,'completed'=>['welcome','search']], 'Only canonical ordered flags stored');
 p14_assert(array_keys(json_decode($before[0]['meta_value'],true))===['version','completed'], 'No timestamps, query strings or behavioral counters');
 wp_set_current_user($other); p14_assert($onboarding->mine()['completed']===[], 'Other account cannot inherit progress');
 $onboarding->complete('account'); wp_set_current_user($member); p14_assert($onboarding->mine()['completed']===['welcome','search'], 'Other owner update cannot change original');
});
p14_case('invalid_inputs_and_guest_state_fail_closed_without_mutations', function() use($member,$onboarding,$read) {
 wp_set_current_user($member); $before=$read($member);
 foreach(['', 'unknown', '../welcome', 'welcome<script>', str_repeat('x',300)] as $step) p14_reject(fn()=>$onboarding->complete($step),'Invalid step rejected');
 p14_assert($read($member)===$before,'Invalid step leaves existing state unchanged');
 wp_set_current_user(0); p14_reject(fn()=>$onboarding->mine(),'Guest cannot read account state'); p14_reject(fn()=>$onboarding->complete('search'),'Guest cannot create account state');
 p14_assert($read($member)===$before,'Unauthenticated service calls do not mutate known owner');
});
p14_case('pending_can_skip_help_without_verification_and_suspended_is_denied', function() use($pending,$onboarding,$accounts,$db) {
 wp_set_current_user($pending); $onboarding->complete('registration'); p14_assert($accounts->state()==='pending_email','Help cannot verify an account');
 p14_reject(fn()=>$accounts->requireOwner(),'Pending account still fails community write gate');
 update_user_meta($pending,'_aish_account_state','suspended'); p14_reject(fn()=>$onboarding->mine(),'Suspended account cannot read account help state'); p14_reject(fn()=>$onboarding->complete('welcome'),'Suspended account cannot write help state');
 update_user_meta($pending,'_aish_account_state','pending_email');
});
p14_case('nonce_origin_method_and_injected_owner_are_rejected', function() use($member,$other,$onboarding,$read) {
 wp_set_current_user($member); $_SERVER['REQUEST_METHOD']='POST'; unset($_SERVER['HTTP_ORIGIN']);
 $request=['action'=>Onboarding::ACTION,'operation'=>'dismiss','step'=>'review','locale'=>'en_US','_wpnonce'=>wp_create_nonce(Onboarding::ACTION)]; $before=$read($member);
 foreach([['_wpnonce'=>'invalid'],['user_id'=>$other],['step'=>['welcome']],['locale'=>'zz_ZZ'],['operation'=>'reset','step'=>'welcome']] as $patch) p14_reject(fn()=>$onboarding->request(array_replace($request,$patch)),'Invalid request rejected');
 $_SERVER['HTTP_ORIGIN']='https://attacker.invalid'; p14_reject(fn()=>$onboarding->request($request),'Foreign origin rejected'); unset($_SERVER['HTTP_ORIGIN']);
 $_SERVER['REQUEST_METHOD']='GET'; p14_reject(fn()=>$onboarding->request($request),'GET cannot mutate state'); $_SERVER['REQUEST_METHOD']='POST';
 p14_assert($read($member)===$before,'Rejected requests have no durable mutation');
 $onboarding->request($request); $request['locale']='zh_CN'; $onboarding->request($request); p14_assert(count($read($member))===1 && $onboarding->mine()['completed']===['welcome','search','review'],'Both languages share one state');
});
p14_case('settings_replay_resets_only_help_flags', function() use($member,$other,$onboarding,$read,$accounts) {
 wp_set_current_user($member); $otherBefore=$read($other); $state=$accounts->state(); $onboarding->reset();
 p14_assert($onboarding->mine()===['version'=>1,'completed'=>[]] && $read($member)===[], 'Replay reset removes optional flags');
 p14_assert($read($other)===$otherBefore && $accounts->state()===$state,'Reset preserves other owner and verification');
 $onboarding->complete('welcome'); p14_assert($onboarding->mine()['completed']===['welcome'],'Help can be explicitly completed again');
});
p14_case('corrupt_metadata_does_not_guess_or_overwrite', function() use($member,$onboarding,$read) {
 wp_set_current_user($member); $before=$read($member)[0]['meta_value']; update_user_meta($member,Onboarding::META,'{"version":1,"completed":["search"],"extra":"private-query"}');
 p14_reject(fn()=>$onboarding->mine(),'Unexpected shape fails closed'); p14_reject(fn()=>$onboarding->complete('account'),'Corrupt state is not silently replaced');
 p14_assert(str_contains($read($member)[0]['meta_value'],'private-query'),'Failed update preserves existing bytes'); update_user_meta($member,Onboarding::META,$before);
});
p14_case('native_privacy_export_and_erasure_respect_target', function() use($member,$other,$admin,$read) {
 wp_set_current_user($admin); $before=$read($other); $result=apply_filters('aish_account_privacy_export',['data'=>[],'done'=>true],get_userdata($member),1);
 $owned=array_values(array_filter($result['data'],fn($row)=>($row['group_id']??'')==='aish-help-preferences'));
 p14_assert(count($owned)===1 && $owned[0]['item_id']==='aish-help-'.$member,'Verified native export includes minimal owned preferences');
 p14_assert(wp_delete_user($member),'Authorized native WordPress user deletion completes through original isolation hooks');p14_assert($read($member)===[] && $read($other)===$before,'Native account deletion removes only the requested owner help flags');
});
p14_report('Real WordPress/MySQL minimal onboarding preferences, native account ownership, request guards and privacy hooks; no external mail or API');
