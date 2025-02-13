# zabbix-db-tidy.php
Scripts to perform housekeeping and data integrity of the Zabbix database which can easily spiral out of control if not closely monitored (and relying on in built Zabbix housekeeping tasks!)

In order to use this script you need a `zabbix-housekeeping.php.inc` and `zabbix-data-integrity.php.inc` same directory as `zabbix-housekeeping.php` and `zabbix-data-integrity.php`. The contents should look like this :-

    <?php
    $lock_file = __DIR__ . "/zabbix-housekeeping.php.lock";
    $log_directory = __DIR__ . "/logs";

    $mysql_hostname = 'localhost';
    $mysql_username = 'zabbix';
    $mysql_password = 'password';
    $mysql_database = 'zabbixdb';

    $mail_server = 'localhost';
    $mail_from_addr = 'zabbix-data-integrity@example.com';
    $mail_from_name = 'zabbix-data-integrity on EXAMPLE';

    $mail_to_addr = 'firstname.lastname@example.com';
    $mail_to_name = 'Firstname Lastname';
    ?>
