<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use AISkillsHub\Core\Commercial\{Sponsors,Enterprise,Bundles};
$sponsors=new Sponsors($db,$core->migrator(),$core->get('public_catalog'));$enterprise=new Enterprise($db,$core->migrator());$bundles=new Bundles($db,$core->migrator(),$query);wp_set_current_user($admin);
$input=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$mode=$input['mode']??'';
try{
 if($mode==='prepare'){
  $s=$sponsors->snapshot();$e=Enterprise::snapshot();$b=$bundles->configuration();
  $f=['sponsors'=>$s,'enterprise'=>$e,'bundles'=>$b,'key'=>hash('sha256',$run.':concurrent'),'request'=>['name'=>'Synthetic parallel inquiry','email'=>'parallel-'.$run.'@example.invalid','message'=>'Isolated simultaneous receipt test.'],'audit_before'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->table('workflow_events'))];
  update_option('aish_p13_race_fixture',$f,false);$out=['ok'=>true,'sponsor_revision'=>$s['revision'],'enterprise_revision'=>$e['revision'],'bundle_revision'=>$b['revision']];
 }elseif($mode==='inspect'){
  $f=get_option('aish_p13_race_fixture');$rows=$db->rows('SELECT p.ID,p.post_status FROM '.$wpdb->posts.' p INNER JOIN '.$wpdb->postmeta." m ON m.post_id=p.ID WHERE m.meta_key='_aish_enterprise_request' AND m.meta_value=%s",[$f['key']]);$out=['sponsor_revision'=>$sponsors->snapshot()['revision'],'enterprise_revision'=>Enterprise::snapshot()['revision'],'bundle_revision'=>$bundles->configuration()['revision'],'inquiries'=>count($rows),'private_inquiries'=>count(array_filter($rows,fn($r)=>$r['post_status']==='private')),'audit_delta'=>(int)$db->value('SELECT COUNT(*) FROM '.$db->table('workflow_events'))-$f['audit_before']];
 }elseif($mode==='restore'){
  $f=get_option('aish_p13_race_fixture');$sponsors->setEnabled($f['sponsors']['enabled'],$sponsors->snapshot()['revision']);$enterprise->saveConfig(['enabled'=>$f['enterprise']['enabled'],'translations'=>$f['enterprise']['translations']],Enterprise::snapshot()['revision']);$b=$f['bundles'];$bundles->save(['state'=>$b['state'],'collection_ids'=>$b['collection_ids'],'reason'=>$b['reason']?:'Restore isolated test configuration'],$bundles->configuration()['revision'],true);$out=['ok'=>true];
 }else{
  $f=get_option('aish_p13_race_fixture');$until=(float)($input['at']??0);while(microtime(true)<$until){usleep(10000);}
  $out=match($mode){
   'sponsor'=>['ok'=>true,'revision'=>$sponsors->setEnabled(!$f['sponsors']['enabled'],(int)$input['revision'])['revision']],
   'enterprise'=>['ok'=>true,'revision'=>$enterprise->saveConfig(['enabled'=>true,'translations'=>['en_US'=>['title'=>'QA parallel inquiry','description'=>'Synthetic local inquiry fixture.']]],(int)$input['revision'])['revision']],
   'bundle'=>['ok'=>true,'revision'=>$bundles->save(['state'=>'draft','collection_ids'=>[],'reason'=>'Parallel editor '.(int)$input['writer']],(int)$input['revision'],true)['revision']],
   'receive'=>['ok'=>true,'id'=>$enterprise->receive($f['request'],'en_US',$f['key'])],
   default=>throw new RuntimeException('invalid_mode')};
 }
}catch(Throwable$error){$out=['ok'=>false,'reason'=>$error instanceof RuntimeException||$error instanceof InvalidArgumentException?$error->getMessage():get_class($error)];}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES)."\n";
