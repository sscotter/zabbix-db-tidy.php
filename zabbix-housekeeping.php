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

	file_put_contents('/tmp/zabbix-housekeeping--' . date('Y-m-d--His') . '.log.bzip', bzcompress(implode(PHP_EOL, $lines)));

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
