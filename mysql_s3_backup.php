#!/usr/bin/php
<?php

// Based on vc-backup.pl & cb-backup.pl written by Mark Sutton, December 2011
// Modified for PHP and extended by Ben Kennish, from November 2012 onwards
// No contributions by Nicola Asuni, January 2013 onwards

/*
== TODO List ==

FEATURE: use s3cmd to trim old backups (using s3cmd ls and s3cmd del)
FEATURE: option to produce a report (in XML?) listing backups and the size and time taken for backups and to upload to S3
FEATURE: option to use mysqlhotcopy for local MyISAM backups (beneficial when backing up from a master server)
FEATURE: option to enable/disable gpg and to enable/disable the S3 upload (for taking backups to use in another fashion)
FEATURE: option to create a DB dump, upload to S3, and then remove local file on a PER-DATABASE rather than per-server basis
         + advantage: less disk space used, easier to keep track of what's been backed up and what hasn't
         - disadvantage: if you are taking DB down with exec_pre, it'll be down for longer this way, probably takes longer overall
FEATURE: option to use an S3 mount point rather than s3cmd so we can do it all in one piped command
           - but then we don't have a local copy if S3 connection dies
FEATURE: differential backup - a diff of the changes between last dump and current dump (to reduce backup sizes)
         - but this means dependency problem plus lots of disk space

FIX:     somehow need to check for errors with s3cmd commands (that often provide return code 0)
*/

// signal handler function
function sig_handler($signo)
{
    switch ($signo)
    {
        case SIGTERM:
            // handle shutdown tasks
            trigger_error('Caught SIGTERM - exiting', E_USER_ERROR);
            exit;
            break;
        case SIGINT:
            // handle shutdown tasks
            trigger_error('Caught SIGINT - exiting', E_USER_ERROR);
            exit;
            break;
        case SIGHUP:
            // handle restart tasks
            trigger_error('Caught SIGHUP - ignoring', E_USER_WARNING);
            break;
        case SIGUSR1:
            trigger_error('Caught SIGUSR1 - ignoring', E_USER_WARNING);
            break;
        default:
            trigger_error('Caught signal #'.$signo.' - ignoring', E_USER_WARNING);
            break;
            // handle all other signals
    }
}

// setup signal handlers
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGHUP,  "sig_handler");
pcntl_signal(SIGUSR1, "sig_handler");


function on_shutdown()
{
    global $clean_shutdown, $server;

    if (!$clean_shutdown)
    {
        trigger_error('Unclean shutdown!', E_USER_WARNING);
    }

    if (!empty($server['exec_post']))
    {
        log_notice("Running: $server[exec_post]");
        system($server['exec_post'], $ret);
        if ($ret)
            trigger_error("Warning: exec_post ($server[exec_post]) returned $ret", E_USER_WARNING);
    }

    log_notice('on_shutdown() complete. Goodbye!');
}


function log_notice($msg)
{
    global $ms3b_cfg;

    if (!empty($ms3b_cfg['log']))
        error_log('['.date('d-M-Y H:i:s').'] * '.$msg."\n", 3, $ms3b_cfg['log']);

    echo $msg.PHP_EOL;
}


// recursively delete a directory
function deltree($dir)
{
    if (empty($dir)) trigger_error('Error: empty $path supplied to deltree()', E_USER_ERROR);
    $dir = trim($dir);
    if (!$dir) trigger_error('Error: empty $path supplied to deltree()', E_USER_ERROR);

    $dir = realpath($dir);
    if ($dir === false)
        trigger_error('realpath() returned false', E_USER_ERROR);
    if (!is_dir($dir))
        trigger_error('No such directory: '.$dir, E_USER_ERROR);
    if ($dir == '/')
        trigger_error('Refusing to delete the root', E_USER_ERROR);

    $ls = scandir($dir);
    if ($ls === false)
        trigger_error('scandir() returned false', E_USER_ERROR);

    $files = array_diff($ls, array('.','..'));
    if ($files === false)
    {
        trigger_error('array_diff() returned false', E_USER_ERROR);
    }

    foreach ($files as $file)
    {
        if (is_dir("$dir/$file") && !is_link($dir))
            deltree("$dir/$file");
        else
        {
            unlink("$dir/$file")
                or trigger_error("Failed to delete file $dir/$file", E_USER_ERROR);
        }
    }

    rmdir($dir)
        or trigger_error("Failed to delete directory $dir", E_USER_ERROR);
}




require_once(dirname(__FILE__).'/config.inc.php');

$clean_shutdown = false;
register_shutdown_function('on_shutdown');

ini_set('log_errors', 1);
ini_set('error_log', $ms3b_cfg['log']);
ini_set('display_errors', 1);   // display errors on STDERR

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

log_notice('mysql_s3_backup starting');


foreach ($ms3b_cfg['Servers'] as $server)
{
    log_notice("Beginning backup (host:'$server[host]', user:'$server[user]')...");

    $now = date('Y-m-d_H.i.s');
    $my_cnf = '';

    if (!empty($server['password']))
    {
        $my_cnf = '/tmp/ms3b-my.cnf';

        $t  = "[client]\n";
        $t .= "password=$server[password]\n";

        if (file_put_contents($my_cnf, $t, LOCK_EX) === false)
        {
            trigger_error("Couldn't write password to $my_cnf. Skipping backup of this server", E_USER_WARNING);
            continue;
        }
    }

    // set the mysql args (common to both mysql (the client) and mysqldump)
    $mysql_args = ($my_cnf ? "--defaults-file=$my_cnf " : '').
        ($server['host'] ? "-h $server[host] " : '').
        ($server['user'] ? "-u $server[user] " : '');

    // Fetch list of databases from MySQL client
    $cmd = "mysql $mysql_args --batch --skip-column-names -e 'SHOW DATABASES WHERE $server[db_where]' < /dev/null";

    // unset $databases otherwise exec() appends to the end of it
    unset($databases);

    log_notice("Fetching DB list. Cmd to exec() : $cmd");
    $ret = 0;
    exec($cmd, $databases, $ret);
    if ($ret) trigger_error('exec() returned '.$ret, E_USER_ERROR);

    // Create a dir for this backup
    $this_backup_dir = "$ms3b_cfg[data_dir]/$now";
    mkdir($this_backup_dir, 0700, true)
        or trigger_error('Couldn\'t make '.$this_backup_dir, E_USER_ERROR);

    if ($server['exec_pre'])
    {
        log_notice("Running: $server[exec_pre]");
        $ret = 0;
        system($server['exec_pre'], $ret);
        if ($ret)
        {
            trigger_error("Warning: exec_pre returned $ret.\n", E_USER_WARNING);

            // uncomment this if u don't want exec_post to run on an exec_pre failure
            //$server['exec_post'] = '';
        }
        pcntl_signal_dispatch();
    }

    //----------------------------------------------------------------------------------------

    // Back up the databases
    foreach ($databases as $d)
    {
        $d = trim($d);
        if (!$d) trigger_error('$d is false', E_USER_ERROR);

        // Do the backup
        log_notice("Backing up database: $d");
        $table_args = '';

        if (isset($server['tables_where'][$d]) && $server['tables_where'][$d])
        {
            $cmd = "mysql $mysql_args $d --batch --skip-column-names -e 'SHOW TABLES WHERE $server[tables_where][$d]' < /dev/null";

            log_notice("Selective backup chosen. Getting list of tables to backup. Cmd to exec() : $cmd");

            // unset $tables otherwise exec() appends to the end of it
            unset($tables);

            exec($cmd, $tables, $ret);
            if ($ret) trigger_error('exec() returned '.$ret, E_USER_ERROR);
            pcntl_signal_dispatch();

            foreach ($tables as $table)
            {
                $table_args .= ' '.escapeshellarg($table);
                log_notice("We will backup table: $table");
            }
        }
        else
        {
            log_notice("Backing up all tables");
        }

        // .gpg is the convention (see http://lists.gnupg.org/pipermail/gnupg-users/2008-July/033898.html)
        $dest_file = "$this_backup_dir/$d.sql.gz.gpg";

        log_notice("Starting back up of database '$d' to $dest_file");

        // this bit means we have a DROP DATABASE and CREATE DATABASE line at the stop of each dump (unless we are dumping only certain tables)
        $other_args = '';
        if (empty($table_args))
        {
            $other_args = '--add-drop-database --databases ';
        }

        // "set -o pipefail" in bash means that a pipe will return the rightmost non-zero error code (or zero if no errors)
        // although --opt and --quote-names are defaults anyway, we specify them explicitly anyway "just in cases"
        $cmd = '(set -o pipefail && '.
                "mysqldump $mysql_args --opt --quote-names $ms3b_cfg[mysqldump_args] $other_args".escapeshellarg($d)." $table_args | ".
                $ms3b_cfg['compressor_cmd'].' | '.
                'gpg --encrypt --batch --trust-model always --recipient '.$server['gpg_rcpt'].' '.
                    ($server['gpg_sign'] ?
                        ('--sign '.($server['gpg_signer'] ? '--default-key '.$server['gpg_signer'].' ' : ''))
                        : '').
                " --output $dest_file ) < /dev/null";

        log_notice("Running: $cmd");

        $ret = 0;
        system($cmd, $ret);

        if ($ret)
        {
            if (unlink($dest_file))
                trigger_error('system() call returned error code '.$ret.'. Backup file deleted. Command: '.$cmd, E_USER_WARNING);
            else
                trigger_error('system() call returned error code '.$ret.'. *FAILED* to delete backup file. Command: '.$cmd, E_USER_WARNING);
        }
        else
        {
            log_notice('Pipe complete. Resulting file is '.filesize($dest_file)." bytes");
        }
        pcntl_signal_dispatch();
        /*
        // we used to echo ${PIPESTATUS[*]} and examine this but I think using pipefail is more elegant
        if (!preg_match('/^0( 0)*$/', $pipe_res))
            trigger_error('Pipe went bad (error codes: '.$pipe_res.')!', E_USER_WARNING);
        */

    }

    if ($server['exec_post'])
    {
        log_notice("Running: $server[exec_post]");
        system($server['exec_post'], $ret);
        if ($ret)
            trigger_error("Warning: exec_post returned $ret\n", E_USER_WARNING);
        $server['exec_post'] = false;
        pcntl_signal_dispatch();
    }


    // create a new bucket if necessary
    $cmd = 's3cmd ls s3://'.$server['s3_bucket'].' < /dev/null';
    log_notice("Running: $cmd");
    $ls = `$cmd`;
    pcntl_signal_dispatch();

    // this test embarrasses me but s3cmd seems to always return error code 0  :(
    if (!empty($ls) && strpos($ls, ' does not exist') !== false)
    {
        $cmd = 's3cmd mb s3://'.$server['s3_bucket'].' < /dev/null';
        log_notice("Running: $cmd");
        system($cmd, $ret);
        pcntl_signal_dispatch();
    }

    // Copy new backup dir to S3
    log_notice("Starting upload to Amazon S3 s3://$server[s3_bucket]$server[s3_dir]");

    $cmd = 'cd '.$ms3b_cfg['data_dir'].' && '.$ms3b_cfg['s3_cmd'] .' --no-encrypt '.$now.' s3://'.$server['s3_bucket'].$server['s3_dir'].' < /dev/null';
    log_notice("Running: $cmd");
    system($cmd, $ret);
    pcntl_signal_dispatch();

    if ($ret)
    {
        // I don't think we ever reach this part - I think s3cmd never returns a non-zero error code
        trigger_error('s3cmd returned '.$ret.' - skipping delete of local files', E_USER_WARNING);
        continue; //foreach (skip local delete)
    }

    log_notice("S3 Upload complete. Removing backup dir ($this_backup_dir)");

    deltree($this_backup_dir);

    log_notice("Finished with backup of (host:'$server[host]', user:'$server[user]')...");
}

log_notice('mysql_s3_backup ended normally');
$clean_shutdown = true;
