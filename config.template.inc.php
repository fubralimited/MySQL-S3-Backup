<?php

// Configuration file for mysql_s3_backup.php
// should be named config.inc.php

$ms3b_cfg = array();

$ms3b_cfg['s3_key']   = 'ABCDEFGHIJKLMNOP';
$ms3b_cfg['s3_cmd']   = 's3cmd put -r';
$ms3b_cfg['data_dir'] = $_SERVER['HOME'].'/.ms3b';

$i = 0;

// server 1
$ms3b_cfg['Servers'][$i]['host']      = '';
$ms3b_cfg['Servers'][$i]['user']      = '';				
$ms3b_cfg['Servers'][$i]['password']  = ''; // NOT recommended to put password here as then it will appear in 'ps' listing .. better to specify password in '~/.my.cnf' file
$ms3b_cfg['Servers'][$i]['db_where']  = "NOT LIKE 'information_schema' AND \\`Database\\` NOT LIKE 'performance_schema'";
$ms3b_cfg['Servers'][$i]['s3_bucket'] = $ms3b_cfg['s3_key'].'.'.trim(`/bin/hostname`).'/';
$ms3b_cfg['Servers'][$i]['s3_dir']    = 'mysql-backups/';
$ms3b_cfg['Servers'][$i]['gpg_rcpt']  = 'user@example.com';
$ms3b_cfg['Servers'][$i]['gpg_sign']  = false;
$ms3b_cfg['Servers'][$i]['exec_pre']  = '';  // what do we exec() before this backup
$ms3b_cfg['Servers'][$i]['exec_post'] = '';  // what do we exec() after this backup
$i++;

// server 2, etc

