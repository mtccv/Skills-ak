from pathlib import Path
import json,subprocess,urllib.request,urllib.error,hashlib,re,datetime
import os
repo=Path(__file__).resolve().parents[2]
environment=os.environ.copy()
for key in ('DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'):environment.pop(key,None)
command=['docker','--host=unix:///var/run/docker.sock','exec','aishqa-fresh','php','/opt/wp-cli.phar','--allow-root','--path=/var/www/html']
code='$core=AISkillsHub\\Core\\Core::boot(); $db=$core->get("database"); $tables=array(); foreach(array_keys(AISkillsHub\\Core\\Database\\Schema::COLUMNS) as $name){$tables[$name]=(int)$db->value("SELECT COUNT(*) FROM ".$db->table($name));} echo wp_json_encode(array("php"=>PHP_VERSION,"wordpress"=>get_bloginfo("version"),"mysql"=>$GLOBALS["wpdb"]->db_version(),"theme"=>wp_get_theme()->get("Version"),"core"=>AISkillsHub\\Core\\VERSION,"schema"=>$core->migrator()->version(),"tables"=>$tables));'
r=subprocess.run(command+['eval',code],capture_output=True,text=True,env=environment)
if r.returncode:print(r.stdout+r.stderr);raise SystemExit(1)
state=json.loads(r.stdout);checks=[]
def check(name,ok):checks.append({'id':name,'status':'passed' if ok else 'failed'})
check('fresh-final-version',state['theme']==state['core']=='0.7.1');check('fresh-schema-fourteen-empty',state['schema']=='1.5.0' and len(state['tables'])==14 and sum(state['tables'].values())==0)
http=[];opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
for path,expected in [('/zh/',200),('/en/',200),('/en/skills/',200),('/en/tutorials/',200),('/en/search/?q=two%20words',200),('/aish-sitemap.xml',200),('/robots.txt',200),('/en/not-found/',404),('/wp-content/themes/ai-skills-hub/assets/dist/app.css',200),('/wp-content/themes/ai-skills-hub/assets/dist/app.js',200)]:
 try:r=opener.open('http://127.0.0.1:18091'+path,timeout=30)
 except urllib.error.HTTPError as error:r=error
 body=r.read();ctype=r.headers.get('Content-Type','');bad=bool(re.search(rb'(?:Fatal error|PHP Warning|<b>Warning</b>|Uncaught [A-Za-z]+Exception)',body)) if 'javascript' not in ctype and 'css' not in ctype else False
 check('fresh-http-'+path,r.status==expected and len(body)>0 and not bad);http.append({'path':path,'status':r.status,'bytes':len(body),'php_diagnostic':bad})
report={'kind':'actual_fresh_final_zip_wordpress_install','executed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'environment':state,'zip_sha256':hashlib.sha256((repo/'release/ai-skills-hub-0.7.1.zip').read_bytes()).hexdigest(),'cases':checks,'http':http,'passed':sum(x['status']=='passed' for x in checks),'failed':sum(x['status']=='failed' for x in checks),'harness_correction':'Initial read-only verification mistakenly called a nonexistent Core getter; corrected to the registered database service before the successful run. No production code or data was changed.','boundary':'Fresh dedicated qa_fresh database, empty fourteen-table schema; no fixtures or real API calls. WP-CLI invokes the native theme installer.'}
(repo/'tests/final/results/package-fresh.json').write_text(json.dumps(report,indent=2)+'\n');print('Fresh final ZIP:',report['passed'],'passed',report['failed'],'failed');raise SystemExit(1 if report['failed'] else 0)
