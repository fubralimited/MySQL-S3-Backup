<?php

// Configuration file for mysql_s3_backup.php
// should be named config.inc.php

$ms3b_cfg = array();

// --continue-put is giving "ERROR: no element found: line 1, column 0" problems with current version of s3cmd
$ms3b_cfg['s3_cmd']         = 's3cmd put --recursive --reduced-redundancy --preserve --multipart-chunk-size-mb=1024 --no-guess-mime-type';
$ms3b_cfg['compressor_cmd'] = 'gzip -c';

$ms3b_cfg['data_dir'] = $_SERVER['HOME'].'/.ms3b';
$ms3b_cfg['log'] = '/tmp/mysql_s3_backup.log';
$ms3b_cfg['mysqldump_args'] = '--events --force';

$i = 0;

// server 1
$ms3b_cfg['Servers'][$i]['host']      = '';  // if any of these three are undefined
$ms3b_cfg['Servers'][$i]['user']      = '';	 // mysql and mysqldump will use the values in /etc/my.cnf
$ms3b_cfg['Servers'][$i]['password']  = '';  // and /root/.my.cnf
$ms3b_cfg['Servers'][$i]['db_where']  = '`Database` NOT LIKE "information_schema" AND `Database` NOT LIKE "performance_schema"';
//$ms3b_cfg['Servers'][$i]['tables_where']['database_name'] = '`Tables_in_database_name` NOT LIKE "temp_%"';
$ms3b_cfg['Servers'][$i]['s3_bucket'] = 'ABCDEFGHIJKLMNOP.'.trim(`/bin/hostname`).'/';
$ms3b_cfg['Servers'][$i]['s3_dir']    = 'mysql-backups/';
$ms3b_cfg['Servers'][$i]['gpg_rcpt']  = 'backups@example.com';
$ms3b_cfg['Servers'][$i]['gpg_sign']  = true;
$ms3b_cfg['Servers'][$i]['gpg_signer'] = 'root@db-slave.example.com';
$ms3b_cfg['Servers'][$i]['exec_pre']  = 'echo Backup starting';  // what do we exec() before this server is backed up
$ms3b_cfg['Servers'][$i]['exec_post'] = 'echo Backup finished';  // what do we exec() after this server is backed up
$i++;

// server 2, etc
