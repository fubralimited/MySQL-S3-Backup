#!/usr/bin/php
<?php

// Based on vc-backup.pl & cb-backup.pl written by Mark Sutton, December 2011
// Modified for PHP and extended by Ben Kennish, October 2012

//TODO: if a password is specified, create a temp config file and use 'mysql --defaults-file'
//TODO: use mysqlhotcopy for MyISAM backups?
//TODO: make sure errors go to STDERR and everything else to STDOUT (for cron)

require_once(__DIR__.'/config.inc.php');


if (!$_SERVER['HOME']) trigger_error('$_SERVER[HOME] is blank!', E_USER_ERROR);

// give no perms to group/others on any files/dirs we create
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
        or trigger_error('mkdir('.$ms3b_cfg['data_dir'].', 0700, true) failed', E_USER_ERROR);
}

foreach ($ms3b_cfg['Servers'] as $server)
{
    echo "Beginning backup (host:'$server[host]', user:'$server[user]')...\n";

    $now = date('Y-m-d_H.i.s');

    if ($server['password'])
        trigger_error('Cannot use $server[password] - secure password functionality not implemented yet!', E_USER_ERROR);

    $mysql_args = ($server['host'] ? "-h $server[host] " : '').
        ($server['user']     ? "-u $server[user] "    : '').
        ($server['password'] ? "-p$server[password] " : '');

    // Fetch databases from MySQL client
    $cmd = 'echo "SHOW DATABASES WHERE \\`Database\\` '.$server['db_where'].'" | mysql '.$mysql_args.'--skip-column-names';

    echo "Fetching DB list. Cmd to exec() : $cmd\n";
    exec($cmd, $databases, $ret);
    if ($ret) trigger_error('exec() returned '.$ret, E_USER_ERROR);

    // Create a dir for this backup
    $this_backup_dir = "$ms3b_cfg[data_dir]/$now";
    mkdir($this_backup_dir, 0700, true)
        or trigger_error('Couldn\'t make '.$this_backup_dir, E_USER_ERROR);

    // Back up the databases
    foreach ($databases as $d)
    {
        $d = trim($d);
        if (!$d) trigger_error(E_USER_ERROR, '$d is false');

        // Do the backup
        echo "Backing up database: $d\n";
        // NB: we use -B with --add-drop-database so we put DROP DATABASE, CREATE, USE .. stuff at start
        // --opt and -Q are defaults anyway 
        $cmd = '/usr/bin/mysqldump '.$mysql_args.'--opt -Q -B --add-drop-database '.$d.' | '.
                'bzip2 -zc | '.
                'gpg -e '.($server['gpg_sign'] ? '-s ' : '').'-r '.$server['gpg_rcpt']." > $this_backup_dir/$d.sql.bz2.e;".' echo ${PIPESTATUS[*]}';
        echo "Running: $cmd\n";
        $pipe_res = system($cmd, $ret);

        if ($ret)
            trigger_error('system() call failed for: '.$cmd, E_USER_ERROR);

        if (!preg_match('/^0( 0)*$/', $pipe_res))
            trigger_error('Pipe went bad (error codes: '.$pipe_res.' - aborting!', E_USER_ERROR);

    }


    // create bucket if it doesn't exist
    $cmd = 's3cmd mb s3://'.$server['s3_bucket'];
    echo "Running: $cmd\n";
    system($cmd, $ret);
    if ($ret)
    {
        trigger_error('s3cmd returned '.$ret, E_USER_ERROR);
    }

    // Copy new backup dir to S3
    echo "Copying backup $now to S3 bucket $server[s3_bucket] ($server[s3_dir])...\n";
    $cmd = 'cd '.$ms3b_cfg['data_dir'].' && '.$ms3b_cfg['s3_cmd'] .' '.$now.' s3://'.$server['s3_bucket'].$server['s3_dir'];
    echo "Running: $cmd\n";
    system($cmd, $ret);
    
    if ($ret) 
    {
        trigger_error('s3cmd returned '.$ret, E_USER_WARNING);
        continue; //foreach
    }

    echo "S3 copy done.\n";

    // Remove backups
    echo "Removing backup dir ($this_backup_dir)\n";
    system('rm -rf '.$this_backup_dir, $ret);
    if ($ret) trigger_error('system() call returned '.$ret, E_USER_WARNING);
    echo "All done.\n";
}
