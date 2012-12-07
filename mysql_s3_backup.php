#!/usr/bin/php
<?php

// Based on vc-backup.pl & cb-backup.pl written by Mark Sutton, December 2011
// Modified for PHP and extended by Ben Kennish, November-December 2012

//FEATURE: if a password is specified, create a temp config file and use 'mysql --defaults-file'
//FEATURE: use mysqlhotcopy for local MyISAM backups?
//TIDY: make sure errors go to STDERR and everything else to STDOUT (for cron)
//TIDY: better logging and output control in general
//TIDY: stop showing the pipe error codes (e.g. 0 0 0)


/*
 * used as an error handler so that we run exec_post for the server before we die
 */
function on_error($errno, $errstr, $errfile, $errline, $errcontext)
{
    if ($errno == E_USER_ERROR)
    {
        global $server;
        if (isset($server['exec_post']) && $server['exec_post'])
        {
            echo "Running: $server[exec_post]\n";
            system($server['exec_post'], $ret);
            if ($ret)
                echo("Warning: exec_post returned $ret\n");
        }
    }
    return false; //pass through to default error handler
}




require_once(__DIR__.'/config.inc.php');

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

error_log('['.date('Y-m-d H:i:s')."] ----- mysql_s3_backup starting\n", 3, $ms3b_cfg['log']);

foreach ($ms3b_cfg['Servers'] as $server)
{
    echo "Beginning backup (host:'$server[host]', user:'$server[user]')...\n";

    $now = date('Y-m-d_H.i.s');

    // comment the next two lines out if u want to allow passwords to be shown in 'ps' output
    if ($server['password'])
        trigger_error('Cannot use specified server password - secure password functionality not implemented yet!', E_USER_ERROR);

    $mysql_args = ($server['host'] ? "-h $server[host] " : '').
        ($server['user']     ? "-u $server[user] "    : '').
        ($server['password'] ? "-p$server[password] " : '');

    // Fetch databases from MySQL client
    $cmd = "echo 'SHOW DATABASES WHERE $server[db_where]' | mysql $mysql_args --skip-column-names";

    echo "Fetching DB list. Cmd to exec() : $cmd\n";
    exec($cmd, $databases, $ret);
    if ($ret) trigger_error('exec() returned '.$ret, E_USER_ERROR);

    // Create a dir for this backup
    $this_backup_dir = "$ms3b_cfg[data_dir]/$now";
    mkdir($this_backup_dir, 0700, true)
        or trigger_error('Couldn\'t make '.$this_backup_dir, E_USER_ERROR);

    if ($server['exec_pre'])
    {
        echo "Running: $server[exec_pre]\n";
        system($server['exec_pre'], $ret);
        if ($ret)
            trigger_error("Warning: exec_pre returned $ret.\n", E_USER_WARNING);
    }


    //----------------------------------------------------------------------------------------

    set_error_handler('on_error');

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
            $cmd = "echo 'SHOW TABLES WHERE ".$server['tables_where'][$d]."' | mysql $mysql_args $d --skip-column-names";

            echo "Selective backup chosen. Getting list of tables to backup. Cmd to exec() : $cmd\n";
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

        error_log('['.date('Y-m-d H:i:s')."] Starting back up of database '$d'\n", 3, $ms3b_cfg['log']);


        $dest_file = "$this_backup_dir/$d.sql.bz2.e";

        // NB: we used to use -B with --add-drop-database so we put DROP DATABASE, CREATE, USE .. stuff at start
        // --opt and -Q are defaults anyway 
        $cmd = 'mysqldump '.$mysql_args.'--opt -Q '.escapeshellarg($d).' '.$table_args.'  | '.
                'bzip2 -zc | '.
                'gpg -e '.($server['gpg_sign'] ? '-s ' : '').'-r '.$server['gpg_rcpt']." > $dest_file".'; echo ${PIPESTATUS[*]}';
        echo "Running: $cmd\n";

        //TODO: change this so the 'echo PIPESTATUS' bit isn't visible?
        $pipe_res = system($cmd, $ret);

        error_log('['.date('Y-m-d H:i:s').'] Finished. Resulting file is '.filesize($dest_file)." bytes\n", 3, $ms3b_cfg['log']);


        if ($ret)
            trigger_error('system() call returned error code '.$ret.' for: '.$cmd, E_USER_ERROR);

        if (!preg_match('/^0( 0)*$/', $pipe_res))
            trigger_error('Pipe went bad (error codes: '.$pipe_res.') - aborting!', E_USER_ERROR);

    }

    // return to default error handler
    restore_error_handler();


    if ($server['exec_post'])
    {
        echo "Running: $server[exec_post]\n";
        system($server['exec_post'], $ret);
        if ($ret)
            trigger_error("Warning: exec_post returned $ret\n", E_USER_WARNING);
    }
    $server['exec_post'] = false;


    // create a new bucket if necessary
    $cmd = 's3cmd mb s3://'.$server['s3_bucket'];
    echo "Running: $cmd\n";
    system($cmd, $ret);
    if ($ret)
    {
        trigger_error('s3cmd returned '.$ret, E_USER_ERROR);
    }

    // Copy new backup dir to S3
    echo "Copying backup $now to S3 bucket $server[s3_bucket] ($server[s3_dir])...\n";

    error_log('['.date('Y-m-d H:i:s')."] Starting upload to Amazon S3 s3://$server[s3_bucket]$server[s3_dir]\n", 3, $ms3b_cfg['log']);

    $cmd = 'cd '.$ms3b_cfg['data_dir'].' && '.$ms3b_cfg['s3_cmd'] .' '.$now.' s3://'.$server['s3_bucket'].$server['s3_dir'];
    echo "Running: $cmd\n";
    system($cmd, $ret);
    
    if ($ret) 
    {
        trigger_error('s3cmd returned '.$ret, E_USER_WARNING);
        continue; //foreach
    }

    error_log('['.date('Y-m-d H:i:s')."] S3 Upload complete\n", 3, $ms3b_cfg['log']);
    echo "S3 copy done.\n";

    // Remove local copy of backups
    echo "Removing backup dir ($this_backup_dir)\n";
    system('rm -rf '.$this_backup_dir, $ret);
    if ($ret) trigger_error('system() call returned '.$ret, E_USER_WARNING);
    echo "All done.\n";
}

error_log('['.date('Y-m-d H:i:s')."] ----- mysql_s3_backup ended\n", 3, $ms3b_cfg['log']);
