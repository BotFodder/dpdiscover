<?php
/* Modifications are Copyright (C) 2013 Eric Stewart - eric@usf.edu or
 * eric@ericdives.com.
 * Rights to the modifications are hereby transferred to The Cacti Group,
 * per the below "Original Copyright," providing attribution remains.
 *
 * This findhosts.php is a heavily modified version of the original
 * autodiscovery plugin.  Instead of using an IP sweep, we check known
 * devices for LLDP/CDP data via SNMP.  If that data is there, we then attempt
 * to add the device using a hostname and configured SNMP data.  Also, we
 * keep a table of discovered devices so that, if they are not successfully
 * added, an admin can add them manually.
 *
 * All tree and graph creation logic removed.  Suggest you use autom8 to
 * perform those functions.
 *
 * May want to extend this to pull CDP and FDP information, as some equipment
 * may not have LLDP turned on (!).
 * Well ... I'll do what I can to make this easy to extend ...
*/
/* Original Copyright:
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2011 The Cacti Group				 |
 |									 |
 | This program is free software; you can redistribute it and/or	   |
 | modify it under the terms of the GNU General Public License	     |
 | as published by the Free Software Foundation; either version 2	  |
 | of the License, or (at your option) any later version.		  |
 |									 |
 | This program is distributed in the hope that it will be useful,	 |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of	  |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the	   |
 | GNU General Public License for more details.			    |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution		     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/						   |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}

/* let PHP run just as long as it has to */
ini_set("max_execution_time", "0");

error_reporting('E_ALL');
$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}

include("./include/global.php");
include_once($config["base_path"] . '/lib/ping.php');
include_once($config["base_path"] . '/lib/utility.php');
include_once($config["base_path"] . '/lib/api_data_source.php');
include_once($config["base_path"] . '/lib/api_graph.php');
include_once($config["base_path"] . '/lib/snmp.php');
include_once($config["base_path"] . '/lib/data_query.php');
include_once($config["base_path"] . '/lib/api_device.php');

include_once($config["base_path"] . '/lib/sort.php');
include_once($config["base_path"] . '/lib/html_form_template.php');
include_once($config["base_path"] . '/lib/template.php');

include_once($config["base_path"] . '/lib/api_tree.php');
include_once($config["base_path"] . '/lib/tree.php');

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

$debug = FALSE;
$forcerun = FALSE;

foreach($parms as $parameter) {
	@list($arg, $value) = @explode('=', $parameter);

	switch ($arg) {
	case "-r":
		dpdiscover_recreate_tables();
		break;
	case "-d":
		$debug = TRUE;
		break;
	case "-h":
		display_help();
		exit;
	case "-f":
		$forcerun = TRUE;
		break;
	case "-v":
		display_help();
		exit;
	case "--version":
		display_help();
		exit;
	case "--help":
		display_help();
		exit;
	default:
		print "ERROR: Invalid Parameter " . $parameter . "\n\n";
		display_help();
		exit;
	}
}

if (read_config_option("dpdiscover_use_lldp") != "on" &&
    read_config_option("dpdiscover_use_cdp") != "on" &&
    read_config_option("dpdiscover_use_fdp") != "on") {
	print "No discovery protocol active!  Please activate one in the settings.\n";
	display_help();
	exit;
}

if (read_config_option("dpdiscover_collection_timing") == "disabled") {
	dpdiscover_debug("Discovery Polling is set to disabled.\n");
	if(!isset($debug)) {
		exit;
	}
}

dpdiscover_debug("Checking to determine if it's time to run.\n");

$seconds_offset = read_config_option("dpdiscover_collection_timing");
$seconds_offset = $seconds_offset * 60;
$base_start_time = read_config_option("dpdiscover_base_time");
$last_run_time = read_config_option("dpdiscover_last_run_time");
$previous_base_start_time = read_config_option("dpdiscover_prev_base_time");

if ($base_start_time == '') {
	dpdiscover_debug("Base Starting Time is blank, using '12:00am'\n");
	$base_start_time = '12:00am';
}

/* see if the user desires a new start time */
dpdiscover_debug("Checking if user changed the start time\n");
if (!empty($previous_base_start_time)) {
	if ($base_start_time <> $previous_base_start_time) {
		dpdiscover_debug("   User changed the start time from '$previous_base_start_time' to '$base_start_time'\n");
		unset($last_run_time);
		db_execute("DELETE FROM settings WHERE name='dpdiscover_last_run_time'");
	}
}

/* Check for the polling interval, only valid with the Multipoller patch */
$poller_interval = read_config_option("poller_interval");
if (!isset($poller_interval)) {
	$poller_interval = 300;
}

/* set to detect if the user cleared the time between polling cycles */
db_execute("REPLACE INTO settings (name, value) VALUES ('dpdiscover_prev_base_time', '$base_start_time')");

/* determine the next start time */
$current_time = strtotime("now");
if (empty($last_run_time)) {
	if ($current_time > strtotime($base_start_time)) {
		/* if timer expired within a polling interval, then poll */
		if (($current_time - $poller_interval) < strtotime($base_start_time)) {
			$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
		}else{
			$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time) + $seconds_offset;
		}
	}else{
		$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
	}
}else{
	$next_run_time = $last_run_time + $seconds_offset;
}
$time_till_next_run = $next_run_time - $current_time;

if ($time_till_next_run < 0) {
	dpdiscover_debug("The next run time has been determined to be NOW\n");
}else{
	dpdiscover_debug("The next run time has been determined to be at\n   " . date("Y-m-d G:i:s", $next_run_time) . "\n");
}

if ($time_till_next_run > 0 && $forcerun == FALSE) {
	exit;
}

if ($forcerun) {
	dpdiscover_debug("Scanning has been forced\n");
}

if ($forcerun == FALSE) {
	db_execute("REPLACE INTO settings (name, value) VALUES ('dpdiscover_last_run_time', '$current_time')");
}

/* If name data from LLDP doesn't tell us the FQDN or IP, then we'll append
 * this to whatever name we get. */

$domain_name = read_config_option("dpdiscover_domain_name");

/* Do we use the FQDN name as the description? */
$use_fqdn_description = read_config_option("dpdiscover_use_fqdn_description");
/* Do we use the IP for the hostname?  If not, use FQDN */
$use_ip_hostname = read_config_option("dpdiscover_use_ip_hostname");

cacti_log("DP Discover is now running", true, "POLLER");

// Get array of snmp information.
$known_hosts = db_fetch_assoc("SELECT hostname, host_template_id, description, snmp_community, snmp_version, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_port, snmp_timeout, max_oids FROM host");

// Get Oses
$temp = db_fetch_assoc("SELECT plugin_dpdiscover_template.*, host_template.name 
	FROM plugin_dpdiscover_template
	LEFT JOIN host_template 
	ON (plugin_dpdiscover_template.host_template=host_template.id)");

$os = array();
$templates = array();
if (is_array($temp)) {
	foreach ($temp as $d) {
		$os[] = $d;
	}
}
$temp = db_fetch_assoc("SELECT id, name FROM host_template");
if (is_array($temp)) {
	foreach ($temp as $d) {
		$templates[$d['id']] = $d['name'];
	}
}
/* Someday I'll have to set up SNMP v3 options here. */
$cnames = read_config_option("dpdiscover_readstrings");

if ($cnames == '') {
	$cnames = 'public';
}

dpdiscover_debug("Community Names    : $cnames\n");

/* $dpdiscovered['dphost'][$dphost]
 * $dpdiscovered['dphost'][$dphost]['ip'] = $ip;
 * $dpdiscovered['dphost'][$dphost]['hostname'] = $fqdn;
 * $dpdiscovered['dphost'][$dphost]['description'] = $shortname;
 * $dpdiscovered['dphost'][$dphost]['parent'] = $parent;
 * $dpdiscovered['dphost'][$dphost]['port'] = $portfromparent;
 * $dpdiscovered['dphost'][$dphost]['protocol'] = "protocol";
 * $dpdiscovered['dphost'][$dphost]['added'] = 0/1;
 * $dpdiscovered['ip'][$ip] = $dphost;
 * $dpdiscovered['hostname'][$fqdn] = $dphost;
 * $dpdiscovered['description'][$shortname] = $dphost;
*/

$snmp_retries = read_config_option("snmp_retries");

$dpdiscovered = array();

$search = $known_hosts;

// Seed the relevant arrays with known information.
foreach($known_hosts as $host) {
// Might be short, might not.  Make sure and use it as key.
	$dphost = get_shorthost($host['description']);
	$dpdiscovered['dphost'][$dphost] = $host;
// Okay, change what we need to.
	$dpdiscovered['dphost'][$dphost]['protocol'] = "known";
	$dpdiscovered['dphost'][$dphost]['description'][$host['description']] = $dphost;
	$dpdiscovered['dphost'][$dphost]['added'] = 0;
	$dpdiscovered['dphost'][$dphost]['os'] = $templates[$dpdiscovered['dphost'][$dphost]['host_template_id']];
	$dpdiscovered['dphost'][$dphost]['snmp_sysName'] = '';
	$dpdiscovered['dphost'][$dphost]['snmp_sysLocation'] = '';
	$dpdiscovered['dphost'][$dphost]['snmp_sysContact'] = '';
	$dpdiscovered['dphost'][$dphost]['snmp_sysDescr'] = '';
	$dpdiscovered['dphost'][$dphost]['snmp_sysUptime'] = '';
	$dpdiscovered['dphost'][$dphost]['snmp_status'] = 1;
// If hostname is stored as an IP, base FQDN off of description.
	if(is_ipv4($host['hostname']) || is_ipv6($host['hostname'])) {
		$fqdnname = make_fqdn($host['description']);
		$dpdiscovered['dphost'][$dphost]['ip'] = $host['hostname'];
		$dpdiscovered['dphost'][$dphost]['hostname'] = $fqdnname;
		$dpdiscovered['ip'][$host['hostname']] = $dphost;
		$dpdiscovered['hostname'][$fqdnname] = $dphost;
	}else{
// If hostname is stored as an address, check it for FQDN-ness and get the IP
		$fqdnname = make_fqdn($host['hostname']);
		$hostip = get_ip($fqdnname);
		$dpdiscovered['dphost'][$dphost]['hostname'] = $fqdnname;
		$dpdiscovered['dphost'][$dphost]['ip'] = $hostip;
		$dpdiscovered['ip'][$hostip] = $dphost;
		$dpdiscovered['hostname'][$fqdnname] = $dphost;
	}
}

$sysObjectID_OID = ".1.3.6.1.2.1.1.2.0";
$sidx = 0;
while(isset($search[$sidx])) {
	$shortsearch = get_shorthost($search[$sidx]['description']);
	if(check_exclusion($shortsearch)) {
		dpdiscover_debug("$sidx Excluding $shortsearch\n");
		$sidx++;
		continue;
	}
	dpdiscover_debug("$sidx BEGIN: $shortsearch\n");
	if(is_ipv4($dpdiscovered['dphost'][$shortsearch]['ip']) === FALSE &&
	   is_ipv6($dpdiscovered['dphost'][$shortsearch]['ip']) === FALSE) {
		$sidx++;
		continue;
	}
	$sysObjectID = cacti_snmp_get($dpdiscovered['dphost'][$shortsearch]['ip'],
$search[$sidx]['snmp_community'], $sysObjectID_OID,
$search[$sidx]['snmp_version'], $search[$sidx]['snmp_username'], $search[$sidx]['snmp_password'],
$search[$sidx]['snmp_auth_protocol'], $search[$sidx]['snmp_priv_passphrase'],
$search[$sidx]['snmp_priv_protocol'], $search[$sidx]['snmp_context'], $search[$sidx]['snmp_port'],
$search[$sidx]['snmp_timeout'], $snmp_retries, $search[$sidx]['max_oids'], SNMP_POLLER);
	if (!isset($sysObjectID) || $sysObjectID == "") {
		$sidx++;
		continue;
	}
	$dparray = array();
	$use_lldp = read_config_option("dpdiscover_use_lldp");
	$use_cdp = read_config_option("dpdiscover_use_cdp");
	$use_fdp = read_config_option("dpdiscover_use_fdp");
	dpdiscover_debug($use_fdp."\n");
	if ($use_lldp == "on" && FALSE !== ($lldparray = LLDP_Discovery($search[$sidx]))) {
//		print "$sidx We may have things we need to search ".sizeof($dparray)."\n";
		$dparray = array_merge($dparray, $lldparray);
	}
	if($use_cdp == "on" && FALSE !== ($cdparray = CDP_Discovery($search[$sidx]))) {
//		print "$sidx CDP Found stuff instead. ".sizeof($dparray)."\n";
		$dparray = array_merge($dparray, $cdparray);
	}
	if($use_fdp == "on" && FALSE !== ($fdparray = FDP_Discovery($search[$sidx]))) {
		dpdiscover_debug("$sidx FDP Found stuff instead. ".sizeof($dparray)."\n");
		$dparray = array_merge($dparray, $fdparray);
	}
	if(sizeof($dparray) < 1) {
		dpdiscover_debug("$sidx Nothing Found.\n");
		$sidx++;
		continue;
	}
	$snmp_version = read_config_option("snmp_ver");
	$snmp_port    = read_config_option("snmp_port");
	$snmp_timeout = read_config_option("snmp_timeout");
	foreach($dparray as $pdevice) {
		$pdevice['snmp_status'] = 0;
		$pdevice['ip'] = $dpdiscovered['dphost'][$pdevice['description']]['ip'];
		$pdevice['snmp_community'] = '';
		$pdevice['snmp_username'] = '';
		$pdevice['snmp_password'] = '';
		$pdevice['snmp_auth_protocol'] = '';
		$pdevice['snmp_priv_passphrase'] = '';
		$pdevice['snmp_priv_protocol'] = '';
		$pdevice['snmp_context'] = '';
		$pdevice['snmp_readstrings'] = $cnames;
		$pdevice['snmp_version'] = $snmp_version;
		$pdevice['snmp_port'] = $snmp_port;
		$pdevice['snmp_timeout'] = $snmp_timeout;
		$pdevice['snmp_sysObjectID'] = '';
		$pdevice['snmp_sysName'] = '';
		$pdevice['snmp_sysLocation'] = '';
		$pdevice['snmp_sysContact'] = '';
		$pdevice['snmp_sysDescr'] = '';
		$pdevice['snmp_sysUptime'] = '';
		$pdevice['host_template_id'] = '';
		if(!isset($pdevice['max_oids'])) {
			$pdevice['max_oids'] = 10;
		}
		if((is_ipv4($dpdiscovered['dphost'][$pdevice['description']]['ip']) ||
		   is_ipv6($dpdiscovered['dphost'][$pdevice['description']]['ip'])) &&
		   check_exclusion($pdevice['description']) === FALSE) {
			if (dpdiscover_valid_snmp_device($pdevice, $search[$sidx]) === TRUE) {
				dpdiscover_debug("I think we can add ".$pdevice['description']."\n");
				$fos = dpdiscover_find_os($pdevice['snmp_sysDescr']);
				if ($fos !== FALSE) {
					$pdevice['os'] = $fos['name'];
					$pdevice['host_template_id'] = $fos['host_template'];
					$dpdiscovered['dphost'][$pdevice['description']]['os'] = $pdevice['os'];
					$dpdiscovered['dphost'][$pdevice['description']]['host_template_id'] = $pdevice['host_template_id'];
					dpdiscover_debug($pdevice['description']." OS ".$pdevice['os']." ID ".$pdevice['host_template_id']."\n");
					$host_id = dpdiscover_add_device($pdevice);
				}else{
					$dpdiscovered['dphost'][$pdevice['description']]['os'] = "No Match";
				}
				$search[] = $pdevice;
			}
		}
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_status'] = $pdevice['snmp_status'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_community'] = $pdevice['snmp_community'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_username'] = $pdevice['snmp_username'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_password'] = $pdevice['snmp_password'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_version'] = $pdevice['snmp_version'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_sysName'] = $pdevice['snmp_sysName'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_sysLocation'] = $pdevice['snmp_sysLocation'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_sysContact'] = $pdevice['snmp_sysContact'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_sysDescr'] = $pdevice['snmp_sysDescr'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_sysUptime'] = $pdevice['snmp_sysUptime'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_auth_protocol'] = $pdevice['snmp_auth_protocol'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_priv_passphrase'] = $pdevice['snmp_priv_passphrase'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_priv_protocol'] = $pdevice['snmp_priv_protocol'];
		$dpdiscovered['dphost'][$pdevice['description']]['snmp_context'] = $pdevice['snmp_context'];
		if(!isset($dpdiscovered['dphost'][$pdevice['description']]['os'])) {
			$dpdiscovered['dphost'][$pdevice['description']]['os'] = '';
		}
	}
	$sidx++;
}

// DUMP $dpdiscovered to database; send report
$send_report_to = read_config_option("dpdiscover_email_report");
if (isset($dpdiscovered['dphost'])) {
	$message = "REPORT OF DEVICES ADDED BY DPDISCOVERY:\n\n";
	$found = "\nREPORT: Has IP, but not added:\n\n";
	foreach($dpdiscovered['dphost'] as $host => $device) {
		if(!isset($device['parent'])) {
			$device['parent'] = '';
		}
		if(!isset($device['port'])) {
			$device['port'] = '';
		}
		if($device['added'] == 1) {
			$message .= $device['description']." - ".$device['ip']." - ".$device['os']." FOUND VIA: ".$device['parent']." - ".$device['port']."\n";
		}
		if((is_ipv6($device['ip']) || is_ipv4($device['ip'])) &&
		   $device['added'] != 1 && $device['protocol'] != "known") {
			$found .= $device['description']." - ".$device['ip']." FOUND VIA: ".$device['parent']." - ".$device['port']."\n";
		}
		if($debug === FALSE) {
			db_execute("REPLACE INTO plugin_dpdiscover_hosts (hostname, ip, snmp_community, snmp_version, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, sysName, sysLocation, sysContact, sysDescr, sysUptime, os, added, snmp_status, time, protocol, parent, port) VALUES ('"
. sql_sanitize($device['hostname'])."', '"
. sql_sanitize($device['ip'])."', '"
. sql_sanitize($device['snmp_community']) . "', "
. sql_sanitize($device['snmp_version']) . ", '"
. sql_sanitize($device['snmp_username']) . "', '"
. sql_sanitize($device['snmp_password']) . "', '"
. sql_sanitize($device['snmp_auth_protocol']) . "', '"
. sql_sanitize($device['snmp_priv_passphrase']) . "', '"
. sql_sanitize($device['snmp_priv_protocol']) . "', '"
. sql_sanitize($device['snmp_context']) . "', '"
. sql_sanitize($device['snmp_sysName']) . "', '"
. sql_sanitize($device['snmp_sysLocation']) . "', '"
. sql_sanitize($device['snmp_sysContact']) . "', '"
. sql_sanitize($device['snmp_sysDescr']) . "', '"
. sql_sanitize($device['snmp_sysUptime']) . "', '"
. sql_sanitize($device['os']) . "', "
. $device['added'] .", "
. $device['snmp_status'] .", "
. time() .", '"
. sql_sanitize($device['protocol']) ."', '"
. sql_sanitize($device['parent']) ."', '"
. sql_sanitize($device['port']) ."'"
. ")" );
		}else{
			dpdiscover_debug("REPLACE INTO plugin_dpdiscover_hosts (hostname, ip, snmp_community, snmp_version, snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, sysName, sysLocation, sysContact, sysDescr, sysUptime, os, added, snmp_status, time, protocol, parent, port) VALUES ('"
. sql_sanitize($device['hostname'])."', '"
. sql_sanitize($device['ip'])."', '"
. sql_sanitize($device['snmp_community']) . "', "
. sql_sanitize($device['snmp_version']) . ", '"
. sql_sanitize($device['snmp_username']) . "', '"
. sql_sanitize($device['snmp_password']) . "', '"
. sql_sanitize($device['snmp_auth_protocol']) . "', '"
. sql_sanitize($device['snmp_priv_passphrase']) . "', '"
. sql_sanitize($device['snmp_priv_protocol']) . "', '"
. sql_sanitize($device['snmp_context']) . "', '"
. sql_sanitize($device['snmp_sysName']) . "', '"
. sql_sanitize($device['snmp_sysLocation']) . "', '"
. sql_sanitize($device['snmp_sysContact']) . "', '"
. sql_sanitize($device['snmp_sysDescr']) . "', '"
. sql_sanitize($device['snmp_sysUptime']) . "', '"
. sql_sanitize($device['os']) . "', "
. $device['added'] .", "
. $device['snmp_status'] .", "
. time() .", '"
. sql_sanitize($device['protocol']) ."', '"
. sql_sanitize($device['parent']) ."', '"
. sql_sanitize($device['port']) ."'"
. ")\n" );
		}
	}
	if(filter_var($send_report_to, FILTER_VALIDATE_EMAIL)) {
		$subject = "DP Discover Report";
		mail($send_report_to, $subject, $message.$found);
	}else{
		dpdiscover_debug($send_report_to." is not a valid email\n");
	}
	dpdiscover_debug($message.$found."\n");
}

exit;

function LLDP_Discovery($searchme) {
	global $dpdiscovered;
	$lldpLocPortId_OID = ".1.0.8802.1.1.2.1.3.7.1.3";
	$lldpLocPortDesc_OID = ".1.0.8802.1.1.2.1.3.7.1.4";
	$lldpRemSysName_OID = ".1.0.8802.1.1.2.1.4.1.1.9";
	$ifName_OID = ".1.3.6.1.2.1.31.1.1.1.1";

	$snmp_retries = read_config_option("snmp_retries");
	$lldphost = get_shorthost($searchme['description']);
	$lldpnames = cacti_snmp_walk($dpdiscovered['dphost'][$lldphost]['ip'],
$searchme['snmp_community'], $lldpRemSysName_OID, $searchme['snmp_version'],
$searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'],
$searchme['snmp_port'], $searchme['snmp_timeout'], $snmp_retries,
$searchme['max_oids'], SNMP_POLLER);
	if (sizeof($lldpnames) == 0 || sizeof($lldpnames) === FALSE) {
		dpdiscover_debug("No lldpnames found.\n");
		return FALSE;
	}
	DP_setup($lldpnames);
	dpdiscover_debug("LLDP: Walked $lldphost ".$dpdiscovered['dphost'][$lldphost]['ip']."\n");
	$answer = array();
	for ($i=0; $i<sizeof($lldpnames); $i++) {
		if($lldpnames[$i]['description'] == "") {
			continue;
		}
		if(isset($dpdiscovered['dphost'][$lldpnames[$i]['description']]['parent'])) {
			// Already know.
			dpdiscover_debug("We know about ".$lldpnames[$i]['description']." - ".$dpdiscovered['dphost'][$lldpnames[$i]['description']]['parent']."\n");
			continue;
		}
		$lldpport = cacti_snmp_get($dpdiscovered['dphost'][$lldphost]['ip'],
$searchme['snmp_community'], $lldpLocPortId_OID.".".$lldpnames[$i]['portindex'],
$searchme['snmp_version'], $searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'], $searchme['snmp_port'],
$searchme['snmp_timeout'], $snmp_retries, $searchme['max_oids'], SNMP_POLLER);
// I don't like how the Brocades use MACs for PortId, so fall back to desc.
		if (preg_match("/^..:..:..:..:..:..$/",$lldpport) > 0) {
			$lldpport = cacti_snmp_get($dpdiscovered['dphost'][$lldphost]['ip'],
$searchme['snmp_community'], $lldpLocPortDesc_OID.".".$lldpnames[$i]['portindex'],
$searchme['snmp_version'], $searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'], $searchme['snmp_port'],
$searchme['snmp_timeout'], $snmp_retries, $searchme['max_oids'], SNMP_POLLER);
		}
// GD Nexus 7000 doesn't have local port info in LLDP MIB. Try inName
		if(!$lldpport) {
			$lldpport = cacti_snmp_get($dpdiscovered['dphost'][$lldphost]['ip'],
$searchme['snmp_community'], $ifName_OID.".".$lldpnames[$i]['portindex'],
$searchme['snmp_version'], $searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'], $searchme['snmp_port'],
$searchme['snmp_timeout'], $snmp_retries, $searchme['max_oids'], SNMP_POLLER);
			if(!$lldpport) {
				return FALSE;
			}
		}
		if(!isset($dpdiscovered['dphost'][$lldpnames[$i]['description']])) {
			$answer[] = $lldpnames[$i];
			$dpdiscovered['dphost'][$lldpnames[$i]['description']] = $lldpnames[$i];
			$dpdiscovered['dphost'][$lldpnames[$i]['description']]['protocol'] = "LLDP";
			$dpdiscovered['dphost'][$lldpnames[$i]['description']]['parent'] = $lldphost;
			$dpdiscovered['dphost'][$lldpnames[$i]['description']]['port'] = $lldpport;
			$dpdiscovered['dphost'][$lldpnames[$i]['description']]['added'] = 0;
			$dpdiscovered['hostname'][$lldpnames[$i]['hostname']] = $lldpnames[$i]['description'];
			$dpdiscovered['description'][$lldpnames[$i]['description']] = $lldpnames[$i]['description'];
			if(is_ipv4($lldpnames[$i]['ip']) || is_ipv6($lldpnames[$i]['ip'])) {
				$dpdiscovered['ip'][$lldpnames[$i]['ip']] = $lldpnames[$i]['description'];
			}
		}elseif (!isset($dpdiscovered['dphost'][$lldpnames[$i]['description']]['parent'])) {
			dpdiscover_debug("Just adding parent - ".$lldpnames[$i]['description']." $lldphost $lldpport\n");
			$dpdiscovered['dphost'][$lldpnames[$i]['description']]['parent'] = $lldphost;
			$dpdiscovered['dphost'][$lldpnames[$i]['description']]['port'] = $lldpport;
		}
	}
	return $answer;
}

function CDP_Discovery($searchme) {
	global $dpdiscovered;

	$cdpInterfaceName_OID = ".1.3.6.1.4.1.9.9.23.1.1.1.1.6";
	$cdpCacheDeviceId_OID = ".1.3.6.1.4.1.9.9.23.1.2.1.1.6";
	$ifName_OID = ".1.3.6.1.2.1.31.1.1.1.1";

	$snmp_retries = read_config_option("snmp_retries");
	$cdphost = get_shorthost($searchme['description']);
	$cdpnames = cacti_snmp_walk($dpdiscovered['dphost'][$cdphost]['ip'],
$searchme['snmp_community'], $cdpCacheDeviceId_OID, $searchme['snmp_version'],
$searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'],
$searchme['snmp_port'], $searchme['snmp_timeout'], $snmp_retries,
$searchme['max_oids'], SNMP_POLLER);
	if (sizeof($cdpnames) == 0 || sizeof($cdpnames) === FALSE) {
		dpdiscover_debug("No cdpnames found.\n");
		return FALSE;
	}
	DP_setup($cdpnames);
	dpdiscover_debug("CDP: Walked $cdphost ".$dpdiscovered['dphost'][$cdphost]['ip']."\n");
	$answer = array();
	for ($i=0; $i<sizeof($cdpnames); $i++) {
		if($cdpnames[$i]['description'] == "") {
			continue;
		}
		if (isset($dpdiscovered['dphost'][$cdpnames[$i]['description']]['parent'])) {
			// We already know what we want to know.
			dpdiscover_debug("Already know about ".$cdpnames[$i]['description']. " - ".$dpdiscovered['dphost'][$cdpnames[$i]['description']]['parent']."\n");
			continue;
		}
		$cdpport = cacti_snmp_get($dpdiscovered['dphost'][$cdphost]['ip'],
$searchme['snmp_community'], $cdpInterfaceName_OID.".".$cdpnames[$i]['portindex'],
$searchme['snmp_version'], $searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'], $searchme['snmp_port'],
$searchme['snmp_timeout'], $snmp_retries, $searchme['max_oids'], SNMP_POLLER);
// GD Nexus 7000 doesn't have local port info in LLDP MIB. Try inName
		if(!$cdpport) {
			$cdpport = cacti_snmp_get($dpdiscovered['dphost'][$cdphost]['ip'],
$searchme['snmp_community'], $ifName_OID.".".$cdpnames[$i]['portindex'],
$searchme['snmp_version'], $searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'], $searchme['snmp_port'],
$searchme['snmp_timeout'], $snmp_retries, $searchme['max_oids'], SNMP_POLLER);
			if(!$cdpport) {
				return FALSE;
			}
		}
		if(!isset($dpdiscovered['dphost'][$cdpnames[$i]['description']])) {
			$dpdiscovered['dphost'][$cdpnames[$i]['description']] = $cdpnames[$i];
			$dpdiscovered['dphost'][$cdpnames[$i]['description']]['protocol'] = "CDP";
			$dpdiscovered['dphost'][$cdpnames[$i]['description']]['parent'] = $cdphost;
			$dpdiscovered['dphost'][$cdpnames[$i]['description']]['port'] = $cdpport;
			$dpdiscovered['dphost'][$cdpnames[$i]['description']]['added'] = 0;
			$dpdiscovered['hostname'][$cdpnames[$i]['hostname']] = $cdpnames[$i]['description'];
			$dpdiscovered['description'][$cdpnames[$i]['description']] = $cdpnames[$i]['description'];
			if(is_ipv4($cdpnames[$i]['ip']) || is_ipv6($cdpnames[$i]['ip'])) {
				$dpdiscovered['ip'][$cdpnames[$i]['ip']] = $cdpnames[$i]['description'];
			}
			$answer[] = $cdpnames[$i];
		}elseif (!isset($dpdiscovered['dphost'][$cdpnames[$i]['description']]['parent'])) {
			dpdiscover_debug("Just adding parent - ".$cdpnames[$i]['description']." $cdphost $cdpport\n");
			$dpdiscovered['dphost'][$cdpnames[$i]['description']]['parent'] = $cdphost;
			$dpdiscovered['dphost'][$cdpnames[$i]['description']]['port'] = $cdpport;
		}
	}
	return $answer;
}

function FDP_Discovery($searchme) {
	global $dpdiscovered;

	$snFdpCacheDeviceId_OID = ".1.3.6.1.4.1.1991.1.1.3.20.1.2.1.1.3";
	$ifName_OID = ".1.3.6.1.2.1.31.1.1.1.1";

	$snmp_retries = read_config_option("snmp_retries");
	$fdphost = get_shorthost($searchme['description']);
	$fdpnames = cacti_snmp_walk($dpdiscovered['dphost'][$fdphost]['ip'],
$searchme['snmp_community'], $snFdpCacheDeviceId_OID, $searchme['snmp_version'],
$searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'],
$searchme['snmp_port'], $searchme['snmp_timeout'], $snmp_retries,
$searchme['max_oids'], SNMP_POLLER);
	if (sizeof($fdpnames) == 0 || sizeof($fdpnames) === FALSE) {
		dpdiscover_debug("No fdpnames found.\n");
		return FALSE;
	}
	DP_setup($fdpnames);
	dpdiscover_debug("FDP: Walked $fdphost ".$dpdiscovered['dphost'][$fdphost]['ip']."\n");
	$answer = array();
	for ($i=0; $i<sizeof($fdpnames); $i++) {
		if($fdpnames[$i]['description'] == "") {
			continue;
		}
		if (isset($dpdiscovered['dphost'][$fdpnames[$i]['description']]['parent'])) {
			// We already know what we want to know.
			dpdiscover_debug("Already know about ".$fdpnames[$i]['description']. " - ".$dpdiscovered['dphost'][$fdpnames[$i]['description']]['parent']."\n");
			continue;
		}
		$fdpport = cacti_snmp_get($dpdiscovered['dphost'][$fdphost]['ip'],
$searchme['snmp_community'], $ifName_OID.".".$fdpnames[$i]['portindex'],
$searchme['snmp_version'], $searchme['snmp_username'], $searchme['snmp_password'],
$searchme['snmp_auth_protocol'], $searchme['snmp_priv_passphrase'],
$searchme['snmp_priv_protocol'], $searchme['snmp_context'], $searchme['snmp_port'],
$searchme['snmp_timeout'], $snmp_retries, $searchme['max_oids'], SNMP_POLLER);
		if(!$fdpport) {
			return FALSE;
		}
		if(!isset($dpdiscovered['dphost'][$fdpnames[$i]['description']])) {
			$dpdiscovered['dphost'][$fdpnames[$i]['description']] = $fdpnames[$i];
			$dpdiscovered['dphost'][$fdpnames[$i]['description']]['protocol'] = "FDP";
			$dpdiscovered['dphost'][$fdpnames[$i]['description']]['parent'] = $fdphost;
			$dpdiscovered['dphost'][$fdpnames[$i]['description']]['port'] = $fdpport;
			$dpdiscovered['dphost'][$fdpnames[$i]['description']]['added'] = 0;
			$dpdiscovered['hostname'][$fdpnames[$i]['hostname']] = $fdpnames[$i]['description'];
			$dpdiscovered['description'][$fdpnames[$i]['description']] = $fdpnames[$i]['description'];
			if(is_ipv4($fdpnames[$i]['ip']) || is_ipv6($fdpnames[$i]['ip'])) {
				$dpdiscovered['ip'][$fdpnames[$i]['ip']] = $fdpnames[$i]['description'];
			}
			$answer[] = $fdpnames[$i];
		}elseif (!isset($dpdiscovered['dphost'][$fdpnames[$i]['description']]['parent'])) {
			dpdiscover_debug("Just adding parent - ".$fdpnames[$i]['description']." $fdphost $fdpport\n");
			$dpdiscovered['dphost'][$fdpnames[$i]['description']]['parent'] = $fdphost;
			$dpdiscovered['dphost'][$fdpnames[$i]['description']]['port'] = $fdpport;
		}
	}
	return $answer;
}

function dpdiscover_add_device ($device) {
	global $plugins, $config, $dpdiscovered, $debug;

	$use_ip_for_hostname = read_config_option("dpdiscover_use_ip_hostname");
	$use_fqdn_for_descr  = read_config_option("dpdiscover_use_fqdn_for_description");
	$template_id	  = $device['host_template_id'];
	$snmp_sysName	 = preg_split('/[\s.]+/', $device['snmp_sysName'], -1, PREG_SPLIT_NO_EMPTY);
	if($use_fqdn_for_descr == "on") {
		$description = $device['hostname'];
	}else{
		$description = $device['description'];
	}
	if($use_ip_for_hostname == "on") {
		$hostname = $device['ip'];
	}else{
		$hostname = $device['hostname'];
		if(is_ipv6($device['ip'])) {
			$hostname = "udp6:$hostname";
		}
	}
	$community	    = sql_sanitize($device['snmp_community']);
	$snmp_ver	     = sql_sanitize($device['snmp_version']);
	$snmp_username	      = sql_sanitize($device['snmp_username']);
	$snmp_password	      = sql_sanitize($device['snmp_password']);
	$snmp_port	    = sql_sanitize($device['snmp_port']);
	$snmp_timeout	 = sql_sanitize(read_config_option('snmp_timeout'));
	$disable	      = false;
	$availability_method  = sql_sanitize(read_config_option("ping_method"));
	$ping_method	  = sql_sanitize(read_config_option("ping_method"));
	$ping_port	    = sql_sanitize(read_config_option("ping_port"));
	$ping_timeout	 = sql_sanitize(read_config_option("ping_timeout"));
	$ping_retries	 = sql_sanitize(read_config_option("ping_retries"));
	$notes		= 'Added by DPDiscover Plugin';
	$snmp_auth_protocol   = sql_sanitize($device['snmp_auth_protocol']);
	$snmp_priv_passphrase = sql_sanitize($device['snmp_priv_passphrase']);
	$snmp_priv_protocol   = sql_sanitize($device['snmp_priv_protocol']);
	$snmp_context	      = sql_sanitize($device['snmp_context']);
	$device_threads       = 1;
	$max_oids	     = sql_sanitize($device['max_oids']);

	if($debug === FALSE) {
		$host_id = api_device_save(0, $template_id, $description, $hostname,
			$community, $snmp_ver, $snmp_username, $snmp_password,
			$snmp_port, $snmp_timeout, $disable, $availability_method,
			$ping_method, $ping_port, $ping_timeout, $ping_retries,
			$notes, $snmp_auth_protocol, $snmp_priv_passphrase,
			$snmp_priv_protocol, $snmp_context, $max_oids, $device_threads);
	}else{
		dpdiscover_debug("Host not added - debugging: $description $hostname\n");
		$host_id = 1;
	}
	$dpdiscovered['dphost'][$device['description']]['added'] = 1;
/*
	if ($host_id) {
		db_execute("DELETE FROM plugin_dpdiscover_hosts WHERE ip = '$ip' LIMIT 1");
	}
*/
	//api_device_remove($host_id);
	return $host_id;
}

function dpdiscover_recreate_tables () {
	dpdiscover_debug("Request received to recreate the LLDiscover Plugin's tables\n");
	dpdiscover_debug("   Dropping the tables\n");
	db_execute("drop table plugin_dpdiscover_hosts");

	dpdiscover_debug("   Creating the tables\n");
	dpdiscover_setup_table ();
}

function dpdiscover_find_os($text) {
	global $os;
	for ($a = 0; $a < count($os); $a++) {
		if (stristr($text, $os[$a]['sysdescr'])) {
			return $os[$a];
		}
	}
	return false;
}

function dpdiscover_debug($text) {
	global $debug;
	if ($debug)	print $text;
}

function dpdiscover_valid_snmp_device (&$device, $parent) {
	// Do we try our parent's data for SNMP checks?
	$use_parent_snmp = read_config_option("dpdiscover_use_parent_snmp");

	// Should we filter it?
	$use_parent_snmp_filter = read_config_option("dpdiscover_parent_filter");
	/* initialize variable */
	$host_up = FALSE;
	$device["snmp_status"] = 0;

	/* force php to return numeric oid's */
	if (function_exists("snmp_set_oid_numeric_print")) {
		snmp_set_oid_numeric_print(TRUE);
	}

	$snmp_username = read_config_option('snmp_username');
	$snmp_password = read_config_option('snmp_password');
	$snmp_auth_protocol = read_config_option('snmp_auth_protocol');
	$snmp_priv_passphrase = read_config_option('snmp_priv_passphrase');
	$snmp_priv_protocol = read_config_option('snmp_priv_protocol');
	$snmp_context = '';

	$device['snmp_auth_username'] = '';
	$device['snmp_password'] = '';
	$device['snmp_auth_protocol'] = '';
	$device['snmp_priv_passphrase'] = '';
	$device['snmp_priv_protocol'] = '';
	$device['snmp_context'] = '';

	// If we're supposed to try it, then check filter against both the
	// community string and the snmp_password.
	if ($use_parent_snmp == "on" && ($use_parent_snmp_filter == '' ||
	   strpos($parent['snmp_community'], $use_parent_snmp_filter) !== FALSE ||
	   strpos($parent['snmp_password'], $use_parent_snmp_filter) !== FALSE)) {
		dpdiscover_debug("Trying parent SNMP! ".$device['description']." parent ".$parent['description']."\n");
		$snmp_sysObjectID = @cacti_snmp_get($device['ip'], $parent['snmp_community'],
'.1.3.6.1.2.1.1.2.0', $parent['snmp_version'], $parent['snmp_username'], $parent['snmp_password'],
$parent['snmp_auth_protocol'], $parent['snmp_priv_passphrase'], $parent['snmp_priv_protocol'],
$parent['snmp_context'], $parent['snmp_port'], $parent['snmp_timeout']);
		$snmp_sysObjectID = str_replace('enterprises', '.1.3.6.1.4.1', $snmp_sysObjectID);
		$snmp_sysObjectID = str_replace('OID: ', '', $snmp_sysObjectID);
		$snmp_sysObjectID = str_replace('.iso', '.1', $snmp_sysObjectID);
		if ((strlen($snmp_sysObjectID) > 0) && (!substr_count($snmp_sysObjectID, 'No Such Object')) && 
		   (!substr_count($snmp_sysObjectID, 'Error In'))) {
			$snmp_sysObjectID = trim(str_replace('"', '', $snmp_sysObjectID));
			$device['snmp_status'] = 1;
			$device['snmp_community'] = $parent['snmp_community'];
			$device['snmp_version'] = $parent['snmp_version'];
			$device['snmp_username'] = $parent['snmp_username'];
			$device['snmp_password'] = $parent['snmp_password'];
			$device['snmp_auth_protocol'] = $parent['snmp_auth_protocol'];
			$device['snmp_priv_passphrase'] = $parent['snmp_priv_passphrase'];
			$device['snmp_priv_protocol'] = $parent['snmp_priv_protocol'];
			$device['snmp_context'] = $parent['snmp_context'];
			$device['max_oids'] = $parent['max_oids'];
			$host_up = TRUE;
			dpdiscover_debug("IT WORKED! $snmp_sysObjectID\n");
		}
	}
	if($host_up === FALSE) {
		$version = array(2 => '1', 1 => '2');
		if ($snmp_username != '' && $snmp_password != '') {
			$version[0] = '3';
		}
		$version = array_reverse($version);

		if ($device['snmp_readstrings'] != '') {
			/* loop through the default and then other common for the correct answer */
			$read_strings = explode(':', $device['snmp_readstrings']);

			$device['snmp_status'] = 0;
			$host_up = FALSE;
			dpdiscover_debug($device['description'].":\n");

			foreach ($version as $v) {
				dpdiscover_debug(" - checking SNMP V$v");
				if ($v == 3) {
					$device['snmp_username'] = $snmp_username;
					$device['snmp_password'] = $snmp_password;
					$device['snmp_auth_protocol'] = $snmp_auth_protocol;
					$device['snmp_priv_passphrase'] = $snmp_priv_passphrase;
					$device['snmp_priv_protocol'] = $snmp_priv_protocol;
					$device['snmp_context'] = $snmp_context;
					/* Community string is not used for v3 */
					$snmp_sysObjectID = @cacti_snmp_get($device['ip'], '', 	'.1.3.6.1.2.1.1.2.0', $v,
							$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
							$device['snmp_port'], $device['snmp_timeout']);
					$snmp_sysObjectID = str_replace('enterprises', '.1.3.6.1.4.1', $snmp_sysObjectID);
					$snmp_sysObjectID = str_replace('OID: ', '', $snmp_sysObjectID);
					$snmp_sysObjectID = str_replace('.iso', '.1', $snmp_sysObjectID);
				if ((strlen($snmp_sysObjectID) > 0) &&
						(!substr_count($snmp_sysObjectID, 'No Such Object')) && 
						(!substr_count($snmp_sysObjectID, 'Error In'))) {
						$snmp_sysObjectID = trim(str_replace('"', '', $snmp_sysObjectID));
						$device['snmp_community'] = '';
						$device['snmp_status'] = 1;
						$device['snmp_version'] = $v;
					$host_up = TRUE;
					break;
				}
				} else {
					$device['snmp_username'] = '';
					$device['snmp_password'] = '';
					$device['snmp_auth_protocol'] = '';
					$device['snmp_priv_passphrase'] = '';
					$device['snmp_priv_protocol'] = '';
					$device['snmp_context'] = '';

					foreach ($read_strings as $snmp_readstring) {
						dpdiscover_debug(" - checking community $snmp_readstring");
						$snmp_sysObjectID = @cacti_snmp_get($device['ip'], $snmp_readstring, 	'.1.3.6.1.2.1.1.2.0', $v,
$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
$device['snmp_port'], $device['snmp_timeout']);
						if(is_ipv6($device['ip'])) {
							dpdiscover_debug("\n".$device['description']." ".$device['ip']." ".$snmp_sysObjectID."\n");
						}
						$snmp_sysObjectID = str_replace('enterprises', '.1.3.6.1.4.1', $snmp_sysObjectID);
						$snmp_sysObjectID = str_replace('OID: ', '', $snmp_sysObjectID);
						$snmp_sysObjectID = str_replace('.iso', '.1', $snmp_sysObjectID);
						if ((strlen($snmp_sysObjectID) > 0) && 
							(!substr_count($snmp_sysObjectID, 'No Such Object')) && 
							(!substr_count($snmp_sysObjectID, 'Error In'))) {
							$snmp_sysObjectID = trim(str_replace('"', '', $snmp_sysObjectID));
							$device['snmp_community'] = $snmp_readstring;
							$device['snmp_status'] = 1;
							$device['snmp_version'] = $v;
							$host_up = TRUE;
							break;
						}
					}
				}
				if ($host_up == TRUE) {
					break;
				}

			}
			dpdiscover_debug("\n");
		}
	}

	if ($host_up) {
		if($device["snmp_sysObjectID"] == '') {
			$device["snmp_sysObjectID"] = $snmp_sysObjectID;
		}
		/* get system name */
		$snmp_sysName = @cacti_snmp_get($device['ip'], $device['snmp_community'],
					'.1.3.6.1.2.1.1.5.0', $device['snmp_version'],
					$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
					$device['snmp_port'], $device['snmp_timeout']);

		if (strlen($snmp_sysName) > 0) {
			$snmp_sysName = trim(strtr($snmp_sysName,"\""," "));
			$device["snmp_sysName"] = $snmp_sysName;
		}
		/* get system location */
		$snmp_sysLocation = @cacti_snmp_get($device['ip'], $device['snmp_community'],
					'.1.3.6.1.2.1.1.6.0', $device['snmp_version'],
					$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
					$device['snmp_port'], $device['snmp_timeout']);

		if (strlen($snmp_sysLocation) > 0) {
			$snmp_sysLocation = trim(strtr($snmp_sysLocation,"\""," "));
			$device["snmp_sysLocation"] = $snmp_sysLocation;
		}
		/* get system contact */
		$snmp_sysContact = @cacti_snmp_get($device['ip'], $device['snmp_community'],
					'.1.3.6.1.2.1.1.4.0', $device['snmp_version'],
					$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
					$device['snmp_port'], $device['snmp_timeout']);

		if (strlen($snmp_sysContact) > 0) {
			$snmp_sysContact = trim(strtr($snmp_sysContact,"\""," "));
			$device["snmp_sysContact"] = $snmp_sysContact;
		}
		/* get system description */
		$snmp_sysDescr = @cacti_snmp_get($device['ip'], $device['snmp_community'],
					'.1.3.6.1.2.1.1.1.0', $device['snmp_version'],
					$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
					$device['snmp_port'], $device['snmp_timeout']);

		if (strlen($snmp_sysDescr) > 0) {
			$snmp_sysDescr = trim(strtr($snmp_sysDescr,"\""," "));
			$device["snmp_sysDescr"] = $snmp_sysDescr;
		}
		/* get system uptime */
		$snmp_sysUptime = @cacti_snmp_get($device['ip'], $device['snmp_community'],
					'.1.3.6.1.2.1.1.3.0', $device['snmp_version'],
					$device['snmp_username'], $device['snmp_password'], $device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'], $device['snmp_context'],
					$device['snmp_port'], $device['snmp_timeout']);

		if (strlen($snmp_sysUptime) > 0) {
			$snmp_sysUptime = trim(strtr($snmp_sysUptime,"\""," "));
			$device["snmp_sysUptime"] = $snmp_sysUptime;
		}
	}

	return $host_up;
}

function is_ipv4($address) {
	if(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
		return FALSE;
	}else{
		return TRUE;
	}
}

function is_ipv6($address) {
	if(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
		// Check for SNMP specification and brackets
		if(preg_match("/udp6:\[(.*)\]/", $address, $matches) > 0 &&
			filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
			dpdiscover_debug("Is IPv6: $address ".$matches[1]."\n");
			return TRUE;
		}
		return FALSE;
	}else{
		return TRUE;
	}
}

function is_ipv6_raw($address) {
	if(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
		return FALSE;
	}else{
		return TRUE;
	}
}

/* Oh, someday I may need to make this better. */
function is_fqdn($address) {
	if(is_ipv4($address) || is_ipv6($address)) {
		return FALSE;
	}else{
		if(preg_match("/^udp6:(.*)$/", $address, $matches)) {
			$address = $matches[1];
		}
		return (!empty($address) && preg_match('/(?=^.{1,254}$)(^(?:(?!\d|-)[a-z0-9\-]{1,63}(?<!-)\.)+(?:[a-z]{2,})$)/i', $address) > 0);
	}
}

function make_fqdn($address) {
	global $domain_name;
	if(preg_match("/^udp6:(.*)$/", $address, $matches)) {
		$address = $matches[1];
	}
	if(!is_fqdn($address)) {
		$address = $address.".".$domain_name;
	}
	return $address;
}

function get_shorthost($address) {
	if(!is_fqdn($address)) {
		return $address;
	}else{
		return substr($address, 0, strpos($address, "."));
	}
}

function get_ip($address) {
	global $domain_name;
	if(is_ipv4($address)) {
		return $address;
	}
	if(is_ipv6_raw($address)) {
		return "udp6:[$address]";
	}
	if(is_ipv6($address)) {
		return $address;
	}
	if(preg_match("/^udp6:(.*)$/", $address, $matches)) {
		$address = $matches[1];
	}
	if(!is_fqdn($address)) {
		$address = $address.".".$domain_name;
	}
	$dns = dns_get_record($address, DNS_A);
	$ips = array();
	if($dns === FALSE || !isset($dns[0]['ip'])) {
		$dns = dns_get_record($address, DNS_AAAA);
		if($dns === FALSE || !isset($dns[0]['ipv6'])) {
			return "No DNS entry";
		}else{
			foreach($dns as $entry) {
				$ips[] = "udp6:[".$entry['ipv6']."]";
			}
		}
	}else{
		foreach($dns as $entry) {
			$ips[] = $entry['ip'];
		}
	}
	if(count($ips) == 0) {
		dpdiscover_debug("$address = no ips\n");
		return "No DNS entry*";
	}elseif(count($ips) == 1) {
		return $ips[0];
	}else{
		dpdiscover_debug("Multiple IPs\n");
		return "Multi: ".count($ips);
	}
}

/* So far in testing the format of an OID returned as part of the query of
 * LLDP remote names is essentially OID.0.portindex.1, where portindex is the
 * OID index value for the listing of local ports, indicating which port the
 * device should be found on, IE: localportnamesold.portindex = Gi1/1/1.  So,
 * we need not the full "index value", just that number in the middle. */
function DP_setup (&$namesarr) {
	for ($i=0; $i<sizeof($namesarr); $i++) {
		preg_match('/\.(\d+)\.(\d+)$/',$namesarr[$i]['oid'],$matches);
		$namesarr[$i]['portindex'] = $matches[1];
		$namesarr[$i]['value'] = preg_replace('/\.$/', '', $namesarr[$i]['value']);
		$namesarr[$i]['value'] = strtolower($namesarr[$i]['value']);
		if (preg_match('/(.*)\(.*\)/', $namesarr[$i]['value'], $matches) == 1) {
//			print $namesarr[$i]['value']."\n";
			$namesarr[$i]['value'] = $matches[1];
//			print $namesarr[$i]['value']."\n";
		}
		$namesarr[$i]['description'] = get_shorthost($namesarr[$i]['value']);
		$namesarr[$i]['hostname'] = make_fqdn($namesarr[$i]['value']);
		$namesarr[$i]['ip'] = get_ip($namesarr[$i]['hostname']);
	}
}

function check_exclusion($hostname) {
	$excludes_string = read_config_option("dpdiscover_exclude");
	$hostname = strtolower($hostname);
	if($excludes_string == "") {
		return FALSE;
	}
	if(strpos($excludes_string,":") !== FALSE) {
		$excludes = explode(":", $excludes_string);
		foreach($excludes as $excludeme) {
			if(strpos($hostname, $excludeme) !== FALSE) {
				return TRUE;
			}
		}
	}else{
 		if(strpos($hostname, $excludes_string) === FALSE) {
			return FALSE;
		}else{
			return TRUE;
		}
	}
	return FALSE;
}

/*	display_help - displays the usage of the function */
function display_help () {
	print "DP Discover v0.2, Copyright 2013 - Eric Stewart\n\n";
	print "usage: findhosts.php [-d] [-h] [--help] [-v] [--version]\n\n";
	print "-f	    - Force the execution of a discovery process\n";
	print "-d	    - Display verbose output during execution\n";
	print "-r	    - Drop and Recreate the DPDiscover Plugin's tables before running\n";
	print "-v --version  - Display this help message\n";
	print "-h --help     - display this help message\n";
}
?>