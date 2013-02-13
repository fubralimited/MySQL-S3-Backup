#!/usr/bin/php
<?php

// Based on vc-backup.pl & cb-backup.pl written by Mark Sutton, December 2011
// Modified for PHP and extended by Ben Kennish, from November 2012
// Awaiting contributions from Nicola Asuni, January 2013

// == TODO List ==

//FEATURE: allow selection of gzip or bzip2
//FEATURE: support sending only a diff of the changes between last dump and current dump (to reduce backup sizes)
//FEATURE: if a password is specified in config.inc.php, create a temp config file and use 'mysql --defaults-file'
//FEATURE: option to use mysqlhotcopy for local MyISAM backups
//FEATURE: option to use an S3 mount point rather than s3cmd so we can do it all in one piped command - but then what can we do on failure?
//FEATURE: gracefully handle views with invalid references, e.g. use --force with mysqldump then don't die on non-zero pipe status code
//FEATURE: option to produce a report (in XML?) listing backups and the size and time taken for backups and to upload to S3

//TIDY: make sure errors go to STDERR and everything else to STDOUT (for cron)
//TIDY: better logging and output control in general
//TIDY: stop showing the pipe error codes (e.g. 0 0 0)
//TIDY: should we be using escapeshellarg() more?
//TIDY: don't run exec_post if we haven't run exec_pre ?

/*
 * used as an error handler so that we run exec_post for the server before we die
 */
function on_error($errno, $errstr, $errfile, $errline, $errcontext)
{
    // run exec_post script on a full error
    if ($errno == E_USER_ERROR)
    {
        global $server;
        if (isset($server) && isset($server['exec_post']) && $server['exec_post'])
        {
            echo "Running: $server[exec_post]\n";
            system($server['exec_post'], $ret);
            if ($ret)
                fwrite(STDERR, "Warning: exec_post ($server[exec_post]) returned $ret\n");
        }
    }

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

error_log('['.date('Y-m-d H:i:s')."] * mysql_s3_backup starting\n", 3, $ms3b_cfg['log']);

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

    // unset $databases otherwise exec() appends to the end of it
    unset($databases);

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

        $dest_file = "$this_backup_dir/$d.sql.gz.e";

        error_log('['.date('Y-m-d H:i:s')."] Starting back up of database '$d' to $dest_file\n", 3, $ms3b_cfg['log']);

        // NB: we used to use -B with --add-drop-database so we put DROP DATABASE, CREATE, USE .. stuff at start
        // --opt and -Q are defaults anyway 
        $cmd = 'mysqldump '.$mysql_args.'--opt -Q '.escapeshellarg($d).' '.$table_args.' | '.
                'gzip -c | '.
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

    error_log('['.date('Y-m-d H:i:s')."] S3 Upload complete\n", 3, $ms3b_cfg['log']);
    echo "S3 copy done.\n";
    
    if ($ret) 
    {
        trigger_error('s3cmd returned '.$ret, E_USER_WARNING);
        continue; //foreach (skip local delete)
    }

    // Remove local copy of backups
    echo "Removing backup dir ($this_backup_dir)\n";

    // sanity check
    if (realpath($this_backup_dir) == '/') trigger_error('Refusing to wipe entire filesystem!', E_USER_ERROR);

    system('rm -rf '.$this_backup_dir, $ret);
    if ($ret) trigger_error('system() call returned '.$ret, E_USER_WARNING);
    echo "All done.\n";
}

error_log('['.date('Y-m-d H:i:s')."] * mysql_s3_backup ended normally\n", 3, $ms3b_cfg['log']);
