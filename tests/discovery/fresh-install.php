<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Database\Schema;
$prefix='qa_fresh_'.substr($run,0,8).'_';$option='qa_schema_'.$run;$fresh=new Database($wpdb,$prefix);$migration=new Migrator($fresh,$option);
p11_case('fresh_33_tables',function()use($migration,$fresh){p11_assert(true===$migration->migrate(),'Fresh current install');p11_assert($migration->version()==='1.9.0','Correct version');Schema::verify($fresh,'1.9.0');p11_assert(count($fresh->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s',[$fresh->prefix().'aish_%']))===33,'Exactly33 isolated fresh tables');});
p11_case('repeat_fresh_install_and_downgrade_refused',function()use($migration,$fresh){p11_assert(true===$migration->migrate(),'Repeat install no error');Schema::verify($fresh,'1.9.0');p11_assert(is_wp_error($migration->migrate('1.8.0')),'Unsafe downgrade refused');p11_assert($migration->version()==='1.9.0','Version retained');});
p11_report('Real MySQL fresh schema under an isolated new table prefix; no existing table modified');
