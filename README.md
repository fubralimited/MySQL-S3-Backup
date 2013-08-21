# MySQL-S3-Backup

A PHP script to backup MySQL databases to Amazon S3 using GPG for encryption.

## Pre-requisites
### S3 Account
Log in to AWS and find out your '''Access Key''' and '''Secret Keys''' here: https://portal.aws.amazon.com/gp/aws/securityCredentials

### Install S3 Command Tools
As root:

	cd /etc/yum.repos.d

       (For RHEL5.x based OS...)
        wget http://s3tools.org/repo/RHEL_5/s3tools.repo 
       (For RHEL6.x based OS...)
        wget http://s3tools.org/repo/RHEL_6/s3tools.repo 

	yum install s3cmd

Set up .s3cfg

	s3cmd --configure

* Specify your Access Key and Secret Key
* Leave encryption password blank (we will be using GPG later)
* Use HTTPS

### Setup GPG ###
You can either generate a new key pair, or import an existing key pair.
#### Option 1 - Generate a new key pair
Run the interactive key generation tool, and follow the on-screen instructions:

	gpg --gen-key

Whilst this process is running, you may need to run the following (on a different console) to generate entropy on the server:

	find / -type f | xargs grep blahblahblha

Check that the keys are installed:

	gpg --list-keys

The key should have ultimate trust by default, but you can check this in the edit key menu:

	gpg --edit-key 'Key Name'

You can export the keys using the following:

	gpg --export test@example.net > gpg_example_public.key
	gpg --export-secret-keys test@example.net > gpg_example_private.key

#### Option 2 - Import an existing key pair 

Or if you have an already existing keypair, you can import them:

	gpg --import public.key
	gpg --import private.key

View the ID of the public key and edit it to ultimate trust:

	gpg --list-keys
	gpg --edit-key (uid obtained from gpg --list-keys e.g.server@domain.com)

	Command> trust
	..
	5 = I trust ultimately
	m = back to the main menu

	Your decision? 5
	Do you really want to set this key to ultimate trust? (y/N) y

	Command> save

## Running backup script

Install the git client, and php if necessary

    yum install git php-cli

If git is not available install EPEL packages
    
    (For RHEL5.x based OS...)
     rpm -Uvh http://www.mirrorservice.org/sites/dl.fedoraproject.org/pub/epel/5/i386/epel-release-5-4.noarch.rpm
    (For RHEL6.x based OS...)
     rpm -Uvh http://www.mirrorservice.org/sites/dl.fedoraproject.org/pub/epel/6/i386/epel-release-6-8.noarch.rpm

    yum install git

Add the timezone to /etc/php.ini

    date.timezone = 'Europe/London'

Checkout the MySQL-S3-Backup project

    cd /root/
    git clone https://github.com/fubralimited/MySQL-S3-Backup.git

Copy config.template.inc.php to config.inc.php and edit it appropriately. You will need to specify at least your S3 Access key, and your GPG key recipient. You may also wish to list specific databases to backup in the db_where variable.

    cd /root/MySQL-S3-Backup
    cp config.template.inc.php config.inc.php
    vim config.inc.php

Ensure that mysql and mysqldump can be run without a password prompt by editing the users ~/.my.cnf file

    vim /root/.my.cnf
    
You will probably need lines like:

[client]
user=root
password=y0uR_p455w0rd_H3r3

Try running the script!

    chmod +x /root/MySQL-S3-Backup/mysql_s3_backup.php
    /root/MySQL-S3-Backup/mysql_s3_backup.php
    
If you are happy that it worked, install it on the cron

    cat <<'EOF' > /etc/cron.d/mysql_s3_backup
    > MAILTO=your@email.address.com
    > 42 2 * * * root /root/MySQL-S3-Backup/mysql_s3_backup.php
    > EOF

## Restoring backups

Use s3cmd to download the backup from S3 then decrypt, unzip and import it in to MySQL.  e.g.

    s3cmd get -r s3://ABCDEFGHJKLMN3OP2QRS.your.domain.example.com/mysql-backups/2013-01-21_02.42.01
    cd 2013-01-21_02.42.01
    gpg --decrypt database_name.sql.gz.e | gunzip -c | mysql database_name

This will download the specified backup, decrypt and decompress the named database backup and then 
import it into the MySQL server. The database should exist already. If it does not, run this beforehand:

    mysqladmin create database_name

If you have any further questions/suggestions/whatever, feel free to email me (ben@fubra.com).

Thanks.
