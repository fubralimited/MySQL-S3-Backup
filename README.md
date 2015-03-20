# MySQL-S3-Backup

A PHP script to automate the backup of MySQL databases to Amazon S3 using GnuPG (gpg) for encryption.

## Pre-requisites
### An Amazon S3 Account
You need to know your Amazon '''Access Key''' and '''Secret Keys'''.
You can manage access keys here: https://console.aws.amazon.com/iam/home?#security_credential

### Install S3 Command Tools

Amazon S3 Tools: Command Line S3 Client Software and S3 Backup (http://s3tools.org/)

s3cmd is in the EPEL repo (https://fedoraproject.org/wiki/EPEL). Older versions of s3cmd
(< 1.1.0) fail to upload backup files larger than around 5GB in size so we need 1.1.0+.
At time of writing, the regular 'epel' repo has v1.0.1 so we need to enable the 'epel-testing' repo:

    yum install s3cmd --enablerepo='epel-testing'

At time of writing, this repo has s3cmd v1.5.0

Then create an .s3cfg file by running...

    s3cmd --configure

* Specify your Access Key and Secret Key
* Leave encryption password blank (MySQL-S3-Backup does the GPG stuff for you)
* Use HTTPS

You may wish to edit the /root/.s3cfg file to make further changes
e.g. changing 'bucket_location' from 'US' to 'EU'

### Setup GPG ###

 MySQL-S3-Backup needs at minimum a GnuPG public key to encrypt the backup files for the corresponding secret key.
 Import the public key for encrypting with:

    gpg --import /path/to/backups@example.com_public.asc

If you wish to sign the backups (to prove that they came from a trusted source), it will also need access to a secret GnuPG key

Procedure for creating a new public & secret key pair:

#### Option 1 - Generate a new key pair
Run the interactive key generation tool, and follow the on-screen instructions:

    gpg --gen-key

I recommend that you name the new keys '''root@db-slave.example.com''' using whatever user and hostname you are doing the backups from.

At a point in the process, you will be told that the system needs to generate entropy.
To help speed this up, try running the following (from a different terminal) to generate entropy on the server:

    find / -type f | xargs grep ben_rules 2>/dev/null

Check that the keys are installed:

    gpg --list-keys

The key should have ultimate trust by default, but you can check this in the edit key menu:

    gpg --edit-key root@db-slave.example.com

You can export the public key as follows:

    gpg --export --armor root@db-slave.example.com > root@db-slave.example.com_public.asc

You can export the secret key as follows:

    gpg --export-secret-keys --armor root@db-slave.example.com > root@db-slave.example.com_secret.asc

#### Option 2 - Import an existing key pair

Or if you have an already existing backup keypair for signing, you can import the secret key:

    gpg --import /path/to/root@db-slave.example.com_secret.asc

Edit the imported key to ultimate trust:

    gpg --list-keys
    gpg --edit-key root@db-slave.example.com trust

    ...
    4 = I trust fully
    5 = I trust ultimately
    m = back to the main menu

    Your decision? 5
    Do you really want to set this key to ultimate trust? (y/N) y

    Command> q

####

## Running backup script

Install the git client, and php if necessary

    yum install git php-cli

Add the timezone to /etc/php.ini

    date.timezone = 'Europe/London'

Clone the MySQL-S3-Backup project from GitHub

    cd /root/
    git clone https://github.com/fubralimited/MySQL-S3-Backup.git

Copy config.template.inc.php to config.inc.php and edit it appropriately.
As a minimum, you will need to set values for 'gpg_rcpt' (the GPG key recipient), the S3 bucket 
name 's3_bucket', and either set 'gpg_sign' to false or set 'gpg_signer' to the name of the secret key to sign with.
You may also wish to list particular databases to backup in the db_where variable (default is all.)

    cd MySQL-S3-Backup
    cp config.template.inc.php config.inc.php
    vim config.inc.php

Ensure that mysql and mysqldump can be run without a password prompt by editing root's .my.cnf file

    vim /root/.my.cnf
    chmod 600 /root/.my.cnf

You will probably need lines like:

    [client]
    user=root
    password=y0uR_d4t4b453_p455w0rd_H3r3

Try running the script!

    /root/MySQL-S3-Backup/mysql_s3_backup.php

If s3cmd gives "Connection reset by peer" and "Broken Pipe" errors for a newly created bucket, wait a few hours (for DNS changes to propagate) and try again.  See http://www.jacobtomlinson.co.uk/2014/07/31/amazon-s3-s3cmd-put-errno-32-broken-pipe/

If you are happy that it worked, install it on the cron

    cat <<EOF > /etc/cron.d/ms3b
    > MAILTO=you@example.com
    > 42 2 * * * root /root/MySQL-S3-Backup/mysql_s3_backup.php
    > EOF

## Restoring backups

Use s3cmd to download the backup from S3 then decrypt, unzip and import it in to MySQL.  e.g.

    s3cmd get -r s3://ABCDEFGHJKLMN3OP2QRS.db-slave.example.com/mysql-backups/2013-01-21_02.42.01
    cd 2013-01-21_02.42.01
    gpg --decrypt database_name.sql.gz.gpg | gunzip -c | mysql database_name

This will download the specified backup, decrypt and decompress the named database backup and then
import it into the MySQL server. The database should exist already. If it does not, run this beforehand:

    mysqladmin create database_name

If you have any further questions/suggestions/whatever, feel free to email me (ben@catn.com).

Thanks.
Ben
