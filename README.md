# zabbix-db-tidy.php
Script to tidy the Zabbix database which can easily spiral out of control if not closely monitored (and relying on Zabbix housekeeping tasks!)

In order to use this script you need a zabbix-db-tidy-inc.php in the same directory as zabbix-db-tidy.php which look like this

    <?php
    $lock_file = "/tmp/zabbix-db-tidy.php.lock";

    $mysql_hostname = 'localhost';
    $mysql_username = 'zabbix';
    $mysql_password = 'some-password';
    $mysql_database = 'zabbix';

    $mail_server = 'localhost';
    $mail_from_addr = 'zabbix-db-tidy@example.com';
    $mail_from_name = 'Zabbix-DB-Tidy on SERVER';

    $mail_to_addr = 'zabbix-db-tidy@example.net';
    $mail_to_name = 'zabbix-db-tidy';
    ?>
