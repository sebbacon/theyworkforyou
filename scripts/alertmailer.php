<?php
/* 
 * Name: alertmailer.php
 * Description: Mailer for email alerts
 * $Id: alertmailer.php,v 1.34 2009-06-23 10:11:10 matthew Exp $
 */

function mlog($message) {
	print $message;
}

include_once '../www/includes/easyparliament/init.php';
ini_set('memory_limit', -1);
include_once INCLUDESPATH . 'easyparliament/member.php';

$global_start = getmicrotime();
$db = new ParlDB;

# Get current value of latest batch
$q = $db->query('SELECT max(indexbatch_id) as max_batch_id FROM indexbatch');
$max_batch_id = $q->field(0, 'max_batch_id');
mlog("max_batch_id: " . $max_batch_id . "\n");

# Last sent is timestamp of last alerts gone out.
# Last batch is the search index batch number last alert went out to.
if (is_file('alerts-lastsent'))
	$lastsent = file('alerts-lastsent');
else
	$lastsent = array('', 0);

$lastupdated = trim($lastsent[0]);
if (!$lastupdated) $lastupdated = strtotime('00:00 today');
$lastbatch = trim($lastsent[1]);
if (!$lastbatch) $lastbatch = 0;
mlog("lastupdated: $lastupdated lastbatch: $lastbatch\n");

# Construct query fragment to select search index batches which
# have been made since last time we ran
$batch_query_fragment = "";
for ($i=$lastbatch + 1; $i <= $max_batch_id; $i++) {
	$batch_query_fragment .= "batch:$i ";
}
$batch_query_fragment = trim($batch_query_fragment);
mlog("batch_query_fragment: " . $batch_query_fragment . "\n");

# For testing purposes, specify nomail on command line to not send out emails
$nomail = false;
$onlyemail = '';
$fromemail = '';
$fromflag = false;
$toemail = '';
$template = 'alert_mailout';
for ($k=1; $k<$argc; $k++) {
	if ($argv[$k] == '--nomail')
		$nomail = true;
	if (preg_match('#^--only=(.*)$#', $argv[$k], $m))
		$onlyemail = $m[1];
	if (preg_match('#^--from=(.*)$#', $argv[$k], $m))
		$fromemail = $m[1];
	if (preg_match('#^--to=(.*)$#', $argv[$k], $m))
		$toemail = $m[1];
	if (preg_match('#^--template=(.*)$#', $argv[$k], $m)) {
		$template = $m[1];
		# Tee hee
		$template = "../../../../../../../../../../home/twfy-live/email-alert-templates/alert_mailout_$template";
	}
}

if (DEVSITE)
	$nomail = true;

if ($nomail) mlog("NOT SENDING EMAIL\n");
if (($fromemail && $onlyemail) || ($toemail && $onlyemail)) {
	mlog("Can't have both from/to and only!\n");
	exit;
}

$active = 0;
$queries = 0;
$unregistered = 0;
$registered = 0;
$sentemails = 0;

$LIVEALERTS = new ALERT;

$current_email = '';
$email_text = '';
$globalsuccess = 1;

# Fetch all confirmed, non-deleted alerts
$confirmed = 1; $deleted = 0;
$alertdata = $LIVEALERTS->fetch($confirmed, $deleted);
$alertdata = $alertdata['data'];

$DEBATELIST = new DEBATELIST; # Nothing debate specific, but has to be one of them

$sects = array('', 'Commons debate', 'Westminster Hall debate', 'Written Answer', 'Written Ministerial Statement', 'Northern Ireland Assembly debate', 'Public Bill committee', 'Scottish Parliament debate', 'Scottish Parliament written answer');
$sects[101] = 'Lords debate';
$sects_gid = array('', 'debate', 'westminhall', 'wrans', 'wms', 'ni', 'pbc', 'sp', 'spwa');
$sects_gid[101] = 'lords';
$sects_search = array('', 'debate', 'westminhall', 'wrans', 'wms', 'ni', 'pbc', 'sp', 'spwrans');
$sects_search[101] = 'lords';
$results = array();

$outof = count($alertdata);
$start_time = time();
foreach ($alertdata as $alertitem) {
	$active++;
	$email = $alertitem['email'];
	if ($onlyemail && $email != $onlyemail) continue;
	if ($fromemail && strtolower($email) == $fromemail) $fromflag = true;
	if ($fromemail && !$fromflag) continue;
	if ($toemail && strtolower($email) >= $toemail) continue;
	$criteria_raw = $alertitem['criteria'];
	if (preg_match('#\bOR\b#', $criteria_raw)) {
		$criteria_raw = "($criteria_raw)";
	}
	$criteria_batch = $criteria_raw . " " . $batch_query_fragment;

	if ($email != $current_email) {
		if ($email_text)
			write_and_send_email($current_email, $user_id, $email_text, $template);
		$current_email = $email;
		$email_text = '';
		$q = $db->query('SELECT user_id FROM users WHERE email = \''.mysql_real_escape_string($email)."'");
		if ($q->rows() > 0) {
			$user_id = $q->field(0, 'user_id');
			$registered++;
		} else {
			$user_id = 0;
			$unregistered++;
		}
		mlog("\nEMAIL: $email, uid $user_id; memory usage : ".memory_get_usage()."\n");
	}

	$data = null;
	if (!isset($results[$criteria_batch])) {
		mlog("  ALERT $active/$outof QUERY $queries : Xapian query '$criteria_batch'");
		$start = getmicrotime();
		$SEARCHENGINE = new SEARCHENGINE($criteria_batch);
		#mlog("query_remade: " . $SEARCHENGINE->query_remade() . "\n");
		$args = array(
			's' => $criteria_raw, # Note: use raw here for URLs, whereas search engine has batch
			'threshold' => $lastupdated, # Return everything added since last time this script was run
			'o' => 'c',
			'num' => 1000, // this is limited to 1000 in hansardlist.php anyway
			'pop' => 1,
			'e' => 1 # Don't escape ampersands
		);
		$data = $DEBATELIST->_get_data_by_search($args);
		# add to cache (but only for speaker queries, which are commonly repeated)
		if (preg_match('#^speaker:\d+$#', $criteria_raw, $m)) {
			mlog(", caching");
			$results[$criteria_batch] = $data;
		}
		#		unset($SEARCHENGINE);
		$total_results = $data['info']['total_results'];
		$queries++;
		mlog(", hits ".$total_results.", time ".(getmicrotime()-$start)."\n");
	} else {
		mlog("  ACTION $active/$outof CACHE HIT : Using cached result for '$criteria_batch'\n");
		$data = $results[$criteria_batch];
	}

	if (isset($data['rows']) && count($data['rows']) > 0) {
		usort($data['rows'], 'sort_by_stuff'); # Sort results into order, by major, then date, then hpos
		$o = array(); $major = 0; $count = array(); $total = 0;
		$any_content = false;
		foreach ($data['rows'] as $row) {
			if ($major != $row['major']) {
				$count[$major] = $total; $total = 0;
				$major = $row['major'];
				$o[$major] = '';
				$k = 3;
			}
			#mlog($row['major'] . " " . $row['gid'] ."\n");
			if ($row['hdate'] < '2009-01-01') continue;
			$q = $db->query('SELECT gid_from FROM gidredirect WHERE gid_to=\'uk.org.publicwhip/' . $sects_gid[$major] . '/' . mysql_real_escape_string($row['gid']) . "'");
			if ($q->rows() > 0) continue;
			--$k;
			if ($k>=0) {
				$any_content = true;
				$parentbody = str_replace(array('<i>', '</i>', '&#8212;', '<span class="hi">', '</span>'), array('', '', '-', '*', '*'), $row['parent']['body']);
				$body = str_replace(array('&#163;','&#8212;','<span class="hi">','</span>'), array("\xa3",'-','*','*'), $row['body']);
				if (isset($row['speaker']) && count($row['speaker'])) $body = member_full_name($row['speaker']['house'], $row['speaker']['title'], $row['speaker']['first_name'], $row['speaker']['last_name'], $row['speaker']['constituency']) . ': ' . $body;

				$body = wordwrap($body, 72);
				$o[$major] .= $parentbody . ' (' . format_date($row['hdate'], SHORTDATEFORMAT) . ")\nhttp://www.theyworkforyou.com" . $row['listurl'] . "\n";
				$o[$major] .= $body . "\n\n";
			}
			$total++;
		}
		$count[$major] = $total;

		if ($any_content) {
			# Add data to email_text
			$desc = trim(html_entity_decode($data['searchdescription']));
			$desc = trim(preg_replace('#\(?B\d+( OR B\d+)*\)?\s*#', '', $desc));
			foreach ($o as $major => $body) {
				if ($body) {
					$heading = $desc . ' : ' . $count[$major] . ' ' . $sects[$major] . ($count[$major]!=1?'s':'');
					$email_text .= "$heading\n".str_repeat('=',strlen($heading))."\n\n";
					if ($count[$major] > 3) {
						$email_text .= "There are more results than we have shown here. See more:\nhttp://www.theyworkforyou.com/search/?s=".urlencode($criteria_raw)."+section:".$sects_search[$major]."&o=d\n\n";
					}
					$email_text .= $body;
				}
			}
			$email_text .= "To cancel your alert for " . $desc . ", please use:\nhttp://www.theyworkforyou.com/D/" . $alertitem['alert_id'] . '-' . $alertitem['registrationtoken'] . "\n\n";
		}
	}
}
if ($email_text)
	write_and_send_email($current_email, $user_id, $email_text, $template);

mlog("\n");

$sss = "Active alerts: $active\nEmail lookups: $registered registered, $unregistered unregistered\nQuery lookups: $queries\nSent emails: $sentemails\n";
if ($globalsuccess) {
	$sss .= 'Everything went swimmingly, in ';
} else {
	$sss .= 'Something went wrong! Total time: ';
}
$sss .= (getmicrotime()-$global_start)."\n\n";
mlog($sss);
if (!$nomail && !$onlyemail) {
	$fp = fopen('alerts-lastsent', 'w');
	fwrite($fp, time() . "\n");
	fwrite($fp, $max_batch_id);
	fclose($fp);
	mail(ALERT_STATS_EMAILS, 'Email alert statistics', $sss, 'From: Email Alerts <fawkes@dracos.co.uk>');
}
mlog(date('r') . "\n");

function sort_by_stuff($a, $b) {
	if ($a['major'] > $b['major']) return 1;
	if ($a['major'] < $b['major']) return -1;

	if ($a['hdate'] < $b['hdate']) return 1;
	if ($a['hdate'] > $b['hdate']) return -1;

	if ($a['hpos'] == $b['hpos']) return 0;
	return ($a['hpos'] > $b['hpos']) ? 1 : -1;
}

function write_and_send_email($email, $user_id, $data, $template) {
	global $globalsuccess, $sentemails, $nomail, $start_time;

	$data .= '===================='."\n\n";
	if ($user_id) {
		$data .= "As a registered user, visit http://www.theyworkforyou.com/user/\nto manage your alerts.\n";
	} else {
		$data .= "If you register on the site, you will be able to manage your\nalerts there as well as write annotations. :)\n";
	}
	$sentemails++;
	mlog("SEND $sentemails : Sending email to $email ... ");
	$d = array('to' => $email, 'template' => $template);
	$m = array('DATA' => $data);
	if (!$nomail) {
		$success = send_template_email($d, $m, true, true); # true = "Precedence: bulk", want bounces
		mlog("sent ... ");
		# sleep if time between sending mails is less than a certain number of seconds on average
		if (((time() - $start_time) / $sentemails) < 0.5 ) { # number of seconds per mail not to be quicker than
			mlog("pausing ... ");
			sleep(1);
		}
	} else {
		mlog($data);
		$success = 1;
	}
	mlog("done\n");
	if (!$success) $globalsuccess = 0;
}

?>
