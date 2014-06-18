#!/usr/bin/php
<?php

// Based on vc-backup.pl & cb-backup.pl written by Mark Sutton, December 2011
// Modified for PHP and extended by Ben Kennish, from November 2012
// Awaiting contributions from Nicola Asuni, January 2013, that will sadly never come ;-(

// == TODO List ==

//FEATURE: differential backup - a diff of the changes between last dump and current dump (to reduce backup sizes)
//      but this means dependency problem plus lots of disk space
//FEATURE: if a password is specified in config.inc.php, create a (rwx------) temp config file and use 'mysql --defaults-file'
//FEATURE: option to use mysqlhotcopy for local MyISAM backups
//FEATURE: option to use an S3 mount point rather than s3cmd so we can do it all in one piped command - but then what can we do if S3 connection dies?
//FEATURE: option to produce a report (in XML?) listing backups and the size and time taken for backups and to upload to S3
//FEATURE: option to enable/disable gpg and to enable/disable the S3 upload (for taking backups to use in another fashion)

//FEATURE: to minimise disk space usage, create dump, upload to S3, and then remove local file on a PER-DATABASE rather than per-server basis

//TIDY: make sure errors go to STDERR and everything else to STDOUT (for cron)
//TIDY: better logging and output control in general
//TIDY: don't run exec_post if we haven't run exec_pre?
//TIDY: cat /dev/null | s3cmd    to stop it reading from STDIN? or does PHP do that anyway?



function on_error($errno, $errstr, $errfile, $errline, $errcontext)
{
    // write an entry to the log
    global $ms3b_cfg;

    $error_types = array( E_WARNING => 'Warning',
                          E_NOTICE => 'Notice',
                          E_USER_ERROR => 'User Error',
                          E_USER_WARNING => 'User Warning',
                          E_USER_NOTICE => 'User Notice',
                        );
    $err_name = (isset($error_types[$errno])) ? $error_types[$errno] : "Error type '$errno'";

    error_log('['.date('Y-m-d H:i:s')."] $err_name - $errstr (line $errline in $errfile)\n", 3, $ms3b_cfg['log']);

    return false; //pass through to PHP's default error handler
}



function on_shutdown()
{
    global $clean_shutdown;
    
    if (!$clean_shutdown)
        echo 'Shutdown was not clean!'.PHP_EOL;

    global $server;
    if (!empty($server['exec_post']))
    {
        echo "Running: $server[exec_post]\n";
        system($server['exec_post'], $ret);
        if ($ret)
            fwrite(STDERR, "Warning: exec_post ($server[exec_post]) returned $ret\n");
    }
}



$clean_shutdown = false;
register_shutdown_function('on_shutdown');


require_once(dirname(__FILE__).'/config.inc.php');

set_error_handler('on_error');

// paranoid mode - give no perms to group/others for any files/dirs we create
umask(0077);

// Make sure data_dir exists and is well protected
if (is_dir($ms3b_cfg['data_dir']))
{
    chmod($ms3b_cfg['data_dir'], 0700)
        or trigger_error('chmod('.$ms3b_cfg['data_dir'].', 0700) failed', E_USER_ERROR);
}
else
{
    mkdir($ms3b_cfg['data_dir'], 0700, true)
        or trigger_error('Failed to create data_dir: '.$ms3b_cfg['data_dir'], E_USER_ERROR);
}


if (empty($ms3b_cfg['compressor_cmd']))
    trigger_error('No compressor_cmd set in config', E_USER_ERROR);

error_log('['.date('Y-m-d H:i:s')."] * mysql_s3_backup starting\n", 3, $ms3b_cfg['log']);

foreach ($ms3b_cfg['Servers'] as $server)
{
    echo "Beginning backup (host:'$server[host]', user:'$server[user]')...\n";

    $now = date('Y-m-d_H.i.s');

    // comment the next two lines out if u want to allow passwords to be shown in 'ps' output (NOT RECOMMENDED)
    if (!empty($server['password']))
        trigger_error('Cannot use specified server password - secure password functionality not implemented yet!', E_USER_ERROR);

    // set the mysql args (common to both mysql (the client) and mysqldump)
    $mysql_args = ($server['host'] ? "-h $server[host] " : '').
        ($server['user']     ? "-u $server[user] "    : '').
        ($server['password'] ? "-p$server[password] " : '');

    // Fetch list of databases from MySQL client
    $cmd = "mysql $mysql_args --batch --skip-column-names -e 'SHOW DATABASES WHERE $server[db_where]'";

    // unset $databases otherwise exec() appends to the end of it
    unset($databases);

    echo "Fetching DB list. Cmd to exec() : $cmd\n";
    $ret = 0;
    exec($cmd, $databases, $ret);
    if ($ret) trigger_error('exec() returned '.$ret, E_USER_ERROR);

    // Create a dir for this backup
    $this_backup_dir = "$ms3b_cfg[data_dir]/$now";
    mkdir($this_backup_dir, 0700, true)
        or trigger_error('Couldn\'t make '.$this_backup_dir, E_USER_ERROR);

    if ($server['exec_pre'])
    {
        echo "Running: $server[exec_pre]\n";
        $ret = 0;
        system($server['exec_pre'], $ret);
        if ($ret)
            trigger_error("Warning: exec_pre returned $ret.\n", E_USER_WARNING);
    }

    //----------------------------------------------------------------------------------------

    // Back up the databases
    foreach ($databases as $d)
    {
        $d = trim($d);
        if (!$d) trigger_error('$d is false', E_USER_ERROR);

        // Do the backup
        echo "Backing up database: $d\n";
        $table_args = '';

        if (isset($server['tables_where'][$d]) && $server['tables_where'][$d])
        {
            $cmd = "mysql $mysql_args $d --batch --skip-column-names -e 'SHOW TABLES WHERE $server[tables_where][$d]'";

            echo "Selective backup chosen. Getting list of tables to backup. Cmd to exec() : $cmd\n";

            // unset $tables otherwise exec() appends to the end of it
            unset($tables);

            exec($cmd, $tables, $ret);
            if ($ret) trigger_error('exec() returned '.$ret, E_USER_ERROR);
            
            foreach ($tables as $table)
            {
                $table_args .= ' '.escapeshellarg($table);
                echo "We will backup table: $table\n";
            }
        }
        else
        {
            echo "Backing up all tables.\n";
        }

        //TIDY: .gpg is the convention (see http://lists.gnupg.org/pipermail/gnupg-users/2008-July/033898.html)
        $dest_file = "$this_backup_dir/$d.sql.gz.gpg";

        error_log('['.date('Y-m-d H:i:s')."] Starting back up of database '$d' to $dest_file\n", 3, $ms3b_cfg['log']);

        // this bit means we have a DROP DATABASE and CREATE DATABASE line at the stop of each dump (unless we are dumping only certain tables)
        $other_args = '';
        if (empty($table_args))
        {
            $other_args = '--add-drop-database --databases ';
        }

        // "set -o pipefail" in bash means that a pipe will return the rightmost non-zero error code (or zero if no errors)
        // although --opt and --quote-names are defaults anyway, we specify them explicitly anyway "just in cases"
        $cmd = '(set -o pipefail && mysqldump '.$mysql_args.'--opt --quote-names '.$ms3b_cfg['mysqldump_args'].' '.$other_args.escapeshellarg($d).' '.$table_args.' | '.
                $ms3b_cfg['compressor_cmd'].' | '.
                'gpg -e '.($server['gpg_sign'] ? '-s ' : '').'-r '.$server['gpg_rcpt']." > $dest_file".
                ')';
        echo "Running: $cmd\n";

        
        $ret = 0;
        system($cmd, $ret);

        error_log('['.date('Y-m-d H:i:s').'] Finished. Resulting file is '.filesize($dest_file)." bytes\n", 3, $ms3b_cfg['log']);

        if ($ret)
            trigger_error('system() call returned error code '.$ret.' for: '.$cmd, E_USER_WARNING);

        /*
        // we used to echo ${PIPESTATUS[*]} and examine this but I think using pipefail is more elegant
        if (!preg_match('/^0( 0)*$/', $pipe_res))
            trigger_error('Pipe went bad (error codes: '.$pipe_res.')!', E_USER_WARNING);
        */

    }

    if ($server['exec_post'])
    {
        echo "Running: $server[exec_post]\n";
        system($server['exec_post'], $ret);
        if ($ret)
            trigger_error("Warning: exec_post returned $ret\n", E_USER_WARNING);
    }
    $server['exec_post'] = false;


    // create a new bucket if necessary
    // TODO: test for presence of bucket and only issue this if necessary
    $cmd = 's3cmd mb s3://'.$server['s3_bucket'];
    echo "Running: $cmd\n";
    system($cmd, $ret);


    // Copy new backup dir to S3
    echo "Uploading backup $now to S3 bucket $server[s3_bucket] ($server[s3_dir])...\n";

    error_log('['.date('Y-m-d H:i:s')."] Starting upload to Amazon S3 s3://$server[s3_bucket]$server[s3_dir]\n", 3, $ms3b_cfg['log']);

    $cmd = 'cd '.$ms3b_cfg['data_dir'].' && '.$ms3b_cfg['s3_cmd'] .' --no-encrypt '.$now.' s3://'.$server['s3_bucket'].$server['s3_dir'];
    echo "Running: $cmd\n";
    system($cmd, $ret);

    error_log('['.date('Y-m-d H:i:s')."] S3 Upload complete\n", 3, $ms3b_cfg['log']);
    echo "S3 copy done.\n";
    
    if ($ret)
    {
        trigger_error('s3cmd returned '.$ret, E_USER_WARNING);
        continue; //foreach (skip local delete)
    }

    // Remove local copy of backups
    // disabled until we can work out a way of running s3cmd and get a non-zero error code when some files don't upload

    // TODO: uncomment this or we are going to run out of disk space!!!!

    /*
    echo "Removing backup dir ($this_backup_dir)\n";

    // sanity check
    if (realpath($this_backup_dir) == '/') trigger_error('Refusing to wipe entire filesystem!', E_USER_ERROR);

    system('rm -rf '.$this_backup_dir, $ret);
    if ($ret) trigger_error('system() call returned '.$ret, E_USER_WARNING);
    */

    echo "All done.\n";
}

$clean_shutdown = true;
error_log('['.date('Y-m-d H:i:s')."] * mysql_s3_backup ended normally\n", 3, $ms3b_cfg['log']);
