<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('AISH_PHASE11_QA')!=='1')exit(1);
$_SERVER['HTTP_HOST']='127.0.0.1:18183';$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/138.0.0.0 Safari/537.36';
require '/var/www/html/wp-load.php';
if(DB_NAME!=='qa_discovery'||get_option('aish_schema_version')!=='1.9.0')exit(2);
$core=AISkillsHub\Core\Core::boot();$db=$core->get('database');$p=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
try {
if(($p['mode']??'')==='view') {
 wp_set_current_user(0);$views=new AISkillsHub\Core\Discovery\Views($db,$core->migrator(),$core->get('public_catalog'));$m=new ReflectionMethod($views,'persist');
 echo json_encode(['state'=>$m->invoke($views,(int)$p['skill'],$p['locale'],$p['observer'])?'recorded':'already_counted']);
}elseif(($p['mode']??'')==='collection') {
 wp_set_current_user(1);$management=new AISkillsHub\Core\Discovery\Management($db,$core->migrator());$r=$management->save('collection',(int)$p['id'],$p['payload'],(int)$p['revision']);echo json_encode(['state'=>'saved','revision'=>(int)$r['revision']]);
}else exit(3);
}catch(Throwable $e){echo json_encode(['state'=>'rejected','reason'=>$e instanceof DomainException?'conflict':get_class($e)]);}
