<?php

// Configuration file for mysql_s3_backup.php
// should be named config.inc.php

$ms3b_cfg = array();

$ms3b_cfg['s3_key']   = 'ABCDEFGHIJKLMNOP';
$ms3b_cfg['s3_cmd']   = 's3cmd put -r';
$ms3b_cfg['data_dir'] = $_SERVER['HOME'].'/.ms3b';
$ms3b_cfg['log'] = '/tmp/mysql_s3_backup.log';
$ms3b_cfg['mysqldump_args'] = '--events';

$i = 0;

// server 1
$ms3b_cfg['Servers'][$i]['host']      = '';
$ms3b_cfg['Servers'][$i]['user']      = '';				
$ms3b_cfg['Servers'][$i]['password']  = ''; // NOT recommended to put password here as then it will appear in 'ps' listing .. better to specify password in '~/.my.cnf' file
$ms3b_cfg['Servers'][$i]['db_where']  = '`Database` NOT LIKE "information_schema" AND `Database` NOT LIKE "performance_schema"';
//$ms3b_cfg['Servers'][$i]['tables_where']['database_name'] = '`Tables_in_database_name` NOT LIKE "temp_%"';
$ms3b_cfg['Servers'][$i]['s3_bucket'] = $ms3b_cfg['s3_key'].'.'.trim(`/bin/hostname`).'/';
$ms3b_cfg['Servers'][$i]['s3_dir']    = 'mysql-backups/';
$ms3b_cfg['Servers'][$i]['gpg_rcpt']  = 'user@example.com';
$ms3b_cfg['Servers'][$i]['gpg_sign']  = true;
$ms3b_cfg['Servers'][$i]['exec_pre']  = 'echo Pre-script goes here';  // what do we exec() before this server is backed up
$ms3b_cfg['Servers'][$i]['exec_post'] = 'echo Post-script goes here';  // what do we exec() after this server is backed up
$i++;

// server 2, etc
