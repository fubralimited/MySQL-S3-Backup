# MySQL-S3-Backup

A PHP script to backup MySQL databases to Amazon S3 using GPG for encryption.

## Pre-requisites
### S3 Account
Log in to AWS and find out your '''Access Key''' and '''Secret Keys''' here https://portal.aws.amazon.com/gp/aws/securityCredentials

### Install S3 Command Tools
As root:

	cd /etc/yum.repos.d
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
	gpg --edit-key 12345678

	Command> trust
	..
	5 = I trust ultimately
	m = back to the main menu

	Your decision? 5
	Do you really want to set this key to ultimate trust? (y/N) y

	Command> save

## Running backup script

Install the git client, and php if necessary

    yum install git php

Add the timezone to php.ini

    date.timezone = 'Europe/London'

Checkout the MySQL-S3-Backup project

    cd ~/
    git clone https://github.com/fubralimited/MySQL-S3-Backup.git

Copy config.template.inc.php to config.inc.php and edit it appropriately. You will need to specify at least your S3 Access key, and your GPG key recipient. You may also wish to list specific databases to backup in the db_where variable.

    cd ~/MySQL-S3-Backup
    cp config.template.inc.php config.inc.php
    vim config.inc.php

Ensure that mysql and mysqldump can be run without a password prompt by editing the user's ~/.my.cnf file

    vim ~/.my.cnf
    
Try running the script!
    

