<?php
require_once __DIR__ . __FILE__ . '.inc';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function mylogger($line) {
	global $lines;
	$lines[] = $line;
	print $line . PHP_EOL;
}

if (file_exists ($lock_file)) {
	die("Lock file ($lock_file) already exists. Exiting..." . PHP_EOL);
}

try {
        touch($lock_file);
	$limit = 7500;
        $lines = array();
        $queries = array();

        $i = new mysqli($mysql_hostname, $mysql_username, $mysql_password, $mysql_database);

        $items = array();
        $history_tables = array('history', 'history_log', 'history_str', 'history_text', 'history_uint');
	$trend_tables = array('trends', 'trends_uint');

	//
	// Get Histroy and Trend duration for all the items
	//
        if ($res = $i->query("SELECT * FROM items;") ) {
                mylogger(sprintf("[%s] Success. Rows returned %s", date('Y-m-d H:i:s'), $i->affected_rows));
                if ($res->num_rows > 0) {
                        while ($row = $res->fetch_object()) {
                                $items[] = array('itemid' => $row->itemid, 'history' => $row->history, 'trends' => $row->trends);
                        }
                }
                $res->close();
        } else {
                mylogger(sprintf("[%s] Error occured: %s", date('Y-m-d H:i:s'), $i->error));
                die(); // should end the while anyway, but just in case
        }

        foreach ($history_tables as $history_table) {
                foreach ($items as $idx => $item) {
                        // Newer versions of Zabbix store duration in descriptive format (ie 1w or 2d [1 week or 2 days respectively]) instead of in integer days. Need to convert that ourselves here.
                        if (is_numeric($item['history']))
                           $queries[] = sprintf("DELETE FROM $history_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*24*60*60 LIMIT $limit;", $item['itemid'], $item['history']);
                        else {
                           $history_parts = sscanf($item['history'], "%d%s");

                           switch ($history_parts[1]) { // should contain something like 1w, 1d etc
                              case 'w':
                                 $queries[] = sprintf("DELETE FROM $history_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*7*24*60*60 LIMIT $limit; -- History was %s --", $item['itemid'], $history_parts[0], $item['history']);
                                 break;
                              case 'd':
                                 $queries[] = sprintf("DELETE FROM $history_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*24*60*60 LIMIT $limit; -- History was %s --", $item['itemid'], $history_parts[0], $item['history']);
                                 break;
                              case 'h':
                                 $queries[] = sprintf("DELETE FROM $history_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*60*60 LIMIT $limit; -- History was %s --", $item['itemid'], $history_parts[0], $item['history']);
                                 break;
                              case 'm':
                                 $queries[] = sprintf("DELETE FROM $history_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*60 LIMIT $limit; -- History was %s --", $item['itemid'], $history_parts[0], $item['history']);
                                 break;
                              case 's':
                                 $queries[] = sprintf("DELETE FROM $history_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s LIMIT $limit; -- History was %s --", $item['itemid'], $history_parts[0], $item['history']);
                                 break;
                              default:
                                 $queries[] = sprintf("-- Item %s - History was %s - Did not know how to break that down into parts! --", $item['itemid'], $item['history']);
                              }
                           }
                }
        }

        foreach ($trend_tables as $trend_table) {
                foreach ($items as $idx => $item) {
                        // Newer versions of Zabbix store duration in descriptive format (ie 1w or 2d [1 week or 2 days respectively]) instead of in integer days. Need to convert that ourselves here.
                        if (is_numeric($item['trends']))
                           $queries[] = sprintf("DELETE FROM $trend_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*24*60*60 LIMIT $limit;", $item['itemid'], $item['trends']);
                        else {
                           $trend_parts = sscanf($item['trends'], "%d%s");

                           switch ($trend_parts[1]) { // should contain something like 1w, 1d etc
                              case 'w':
                                 $queries[] = sprintf("DELETE FROM $trend_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*7*24*60*60 LIMIT $limit; -- Trend was %s --", $item['itemid'], $trend_parts[0], $item['trends']);
                                 break;
                              case 'd':
                                 $queries[] = sprintf("DELETE FROM $trend_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*24*60*60 LIMIT $limit; -- Trend was %s --", $item['itemid'], $trend_parts[0], $item['trends']);
                                 break;
                              case 'h':
                                 $queries[] = sprintf("DELETE FROM $trend_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*60*60 LIMIT $limit; -- Trend was %s --", $item['itemid'], $trend_parts[0], $item['trends']);
                                 break;
                              case 'm':
                                 $queries[] = sprintf("DELETE FROM $trend_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s*60 LIMIT $limit; -- Trend was %s --", $item['itemid'], $trend_parts[0], $item['trends']);
                                 break;
                              case 's':
                                 $queries[] = sprintf("DELETE FROM $trend_table WHERE itemid=%s and clock<UNIX_TIMESTAMP()-%s LIMIT $limit; -- Trend was %s --", $item['itemid'], $trend_parts[0], $item['trends']);
                                 break;
                              default:
                                 $queries[] = sprintf("-- Item %s - Trend was %s - Did not know how to break that down into parts! --", $item['itemid'], $item['trends']);
                              }
                           }
                }
        }

        // https://github.com/burner1024/zabbix-sql/blob/master/delete-unused-data.sql

	/*
	The following SQL is more about data integrity than housekeeping.
        Useful in it's own right, but slow and time consuming and doesn't necessarily need doing as often as housekeeping.... Maybe move this to a seperate script and run it on a different schedule?

        $queries[] = "DELETE FROM history WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";
        $queries[] = "DELETE FROM history_uint WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";
        $queries[] = "DELETE FROM history_str WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";
        $queries[] = "DELETE FROM history_text WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";
        $queries[] = "DELETE FROM history_log WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";

        $queries[] = "DELETE FROM trends WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";
        $queries[] = "DELETE FROM trends_uint WHERE itemid NOT IN (SELECT itemid FROM items WHERE status='0');";

        // https://github.com/mattiasgeniar/zabbix-orphaned-data-cleanup/blob/master/cleanup.sql

        // Delete orphaned alerts entries
        $queries[] = "DELETE FROM alerts WHERE NOT actionid IN (SELECT actionid FROM actions);";
        $queries[] = "DELETE FROM alerts WHERE NOT eventid IN (SELECT eventid FROM events);";
        $queries[] = "DELETE FROM alerts WHERE NOT userid IN (SELECT userid FROM users);";
        $queries[] = "DELETE FROM alerts WHERE NOT mediatypeid IN (SELECT mediatypeid FROM media_type);";

        // Delete orphaned application entries that no longer map back to a host
        $queries[] = "DELETE FROM applications WHERE NOT hostid IN (SELECT hostid FROM hosts);";

        // Delete orphaned auditlog details (such as logins)
        $queries[] = "DELETE FROM auditlog_details WHERE NOT auditid IN (SELECT auditid FROM auditlog);";
        $queries[] = "DELETE FROM auditlog WHERE NOT userid IN (SELECT userid FROM users);";

        // Delete orphaned conditions
        $queries[] = "DELETE FROM conditions WHERE NOT actionid IN (SELECT actionid FROM actions);";

        // Delete orphaned functions
        $queries[] = "DELETE FROM functions WHERE NOT itemid IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM functions WHERE NOT triggerid IN (SELECT triggerid FROM triggers);";

        // Delete orphaned graph items
        $queries[] = "DELETE FROM graphs_items WHERE NOT graphid IN (SELECT graphid FROM graphs);";
        $queries[] = "DELETE FROM graphs_items WHERE NOT itemid IN (SELECT itemid FROM items);";

        // Delete orphaned host macro's
        $queries[] = "DELETE FROM hostmacro WHERE NOT hostid IN (SELECT hostid FROM hosts);";

        // Delete orphaned item data
        $queries[] = "DELETE FROM items WHERE hostid NOT IN (SELECT hostid FROM hosts);";
        $queries[] = "DELETE FROM items_applications WHERE applicationid NOT IN (SELECT applicationid FROM applications);";
        $queries[] = "DELETE FROM items_applications WHERE itemid NOT IN (SELECT itemid FROM items);";

        // Delete orphaned HTTP check data
        $queries[] = "DELETE FROM httpstep WHERE NOT httptestid IN (SELECT httptestid FROM httptest);";
        $queries[] = "DELETE FROM httpstepitem WHERE NOT httpstepid IN (SELECT httpstepid FROM httpstep);";
        $queries[] = "DELETE FROM httpstepitem WHERE NOT itemid IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM httptest WHERE applicationid NOT IN (SELECT applicationid FROM applications);";

        // Delete orphaned maintenance data
        $queries[] = "DELETE FROM maintenances_groups WHERE maintenanceid NOT IN (SELECT maintenanceid FROM maintenances);";
        $queries[] = "DELETE FROM maintenances_groups WHERE groupid NOT IN (SELECT groupid FROM hosts_groups);";
        $queries[] = "DELETE FROM maintenances_hosts WHERE maintenanceid NOT IN (SELECT maintenanceid FROM maintenances);";
        $queries[] = "DELETE FROM maintenances_hosts WHERE hostid NOT IN (SELECT hostid FROM hosts);";
        $queries[] = "DELETE FROM maintenances_windows WHERE maintenanceid NOT IN (SELECT maintenanceid FROM maintenances);";
        $queries[] = "DELETE FROM maintenances_windows WHERE timeperiodid NOT IN (SELECT timeperiodid FROM timeperiods);";

        // Delete orphaned mappings
        $queries[] = "DELETE FROM mappings WHERE NOT valuemapid IN (SELECT valuemapid FROM valuemaps);";

        // Delete orphaned media items
        $queries[] = "DELETE FROM media WHERE NOT userid IN (SELECT userid FROM users);";
        $queries[] = "DELETE FROM media WHERE NOT mediatypeid IN (SELECT mediatypeid FROM media_type);";
        $queries[] = "DELETE FROM rights WHERE NOT groupid IN (SELECT usrgrpid FROM usrgrp);";
        //$queries[] = "DELETE FROM rights WHERE NOT id IN (SELECT groupid FROM groups);"; // There is no groups table. Unsure how rights table is tied to other tables. Unlikely to be a huge amount of data in rights to purge. Disabling
        $queries[] = "DELETE FROM sessions WHERE NOT userid IN (SELECT userid FROM users);";

        // Screens
        $queries[] = "DELETE FROM screens_items WHERE screenid NOT IN (SELECT screenid FROM screens);";

        // Events & triggers
        $queries[] = "DELETE FROM trigger_depends WHERE triggerid_down NOT IN (SELECT triggerid FROM triggers);";
        $queries[] = "DELETE FROM trigger_depends WHERE triggerid_up NOT IN (SELECT triggerid FROM triggers);";

        // Delete records in the history/trends table where items that no longer exist
        $queries[] = "DELETE FROM history WHERE itemid NOT IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM history_uint WHERE itemid NOT IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM history_log WHERE itemid NOT IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM history_str WHERE itemid NOT IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM history_text WHERE itemid NOT IN (SELECT itemid FROM items);";

        $queries[] = "DELETE FROM trends WHERE itemid NOT IN (SELECT itemid FROM items);";
        $queries[] = "DELETE FROM trends_uint WHERE itemid NOT IN (SELECT itemid FROM items);";

        // Delete records in the events table where triggers/items no longer exist
        $queries[] = "DELETE FROM events WHERE source = 0 AND object = 0 AND objectid NOT IN (SELECT triggerid FROM triggers);";
        $queries[] = "DELETE FROM events WHERE source = 3 AND object = 0 AND objectid NOT IN (SELECT triggerid FROM triggers);";
        $queries[] = "DELETE FROM events WHERE source = 3 AND object = 4 AND objectid NOT IN (SELECT itemid FROM items);";

        // Delete all orphaned acknowledge entries
        $queries[] = "DELETE FROM acknowledges WHERE eventid NOT IN (SELECT eventid FROM events);";
        $queries[] = "DELETE FROM acknowledges WHERE userid NOT IN (SELECT userid FROM users);";
	*/

        $total_affected_rows = 0;


	foreach ($queries as $idx => $query) {
		mylogger(sprintf("[%s] (%s / %s = %s) %s", date('Y-m-d H:i:s'), $idx, count($queries), round($idx/count($queries)*100, 2), $query));
		try {
			// Repeat this loop while the rows deleted equals the limit
			do {
				if ($res = $i->query($query) ) {
					$total_affected_rows  += $i->affected_rows;
					mylogger(sprintf("[%s] Success. Rows affected %s", date('Y-m-d H:i:s'), $i->affected_rows));
				} else {
					mylogger(sprintf("[%s] Error occured: %s", date('Y-m-d H:i:s'), $i->error));
				}
			} while ($i->affected_rows == $limit);
		} catch (mysqli_sql_exception $e) {
			mylogger(sprintf("[%s] EXCEPTION occured: %s", date('Y-m-d H:i:s'), $e->getMessage() ));
		}
	}

        mylogger(sprintf("[%s] Total Row Deleted: %s", date('Y-m-d H:i:s'), $total_affected_rows));

	$lines[] = sprintf("[%s] Total Row Deleted: %s", date('Y-m-d H:i:s'), $total_affected_rows);

	file_put_contents('/tmp/zabbix-db-tidy--' . date('Y-m-d--His') . '.log', implode(PHP_EOL, $lines));

        $mail = new PHPMailer(true);
        try {
		//Server settings
		$mail->SMTPDebug = 0;                                 // Enable verbose debug output
		$mail->isSMTP();                                      // Set mailer to use SMTP
		$mail->Host = $mail_server;                                                                  // Specify main and backup SMTP servers

		//Recipients
		$mail->setFrom($mail_from_addr, $mail_from_name);
		$mail->addAddress($mail_to_addr, $mail_to_name);
		//Content
		$mail->isHTML(true);                                  // Set email format to HTML
		$mail->Subject = 'Zabbix DB Tidy Summary - ' . date("r");
		$mail->Body    = number_format($total_affected_rows) . " records deleted. See attached for more details.";
		$mail->AddStringAttachment(bzcompress(implode(PHP_EOL, $lines)),"output-".time().".txt.bz2", "quoted-printable", "text/plain");
		$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

		$mail->send();

		unlink($lock_file);
	} catch (Exception $e) {
		echo 'Message could not be sent.';
		echo 'Mailer Error: ' . $mail->ErrorInfo;
		unlink($lock_file);
	}

} catch (Exception $e) {
	echo 'Message could not be sent.';
	echo 'Mailer Error: ' . $mail->ErrorInfo;
	unlink($lock_file);
}
?>
