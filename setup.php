<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007 The Cacti Group                                      |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function plugin_dpdiscover_install () {
	api_plugin_register_hook('dpdiscover', 'top_header_tabs', 'dpdiscover_show_tab', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'top_graph_header_tabs', 'dpdiscover_show_tab', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'config_arrays', 'dpdiscover_config_arrays', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'draw_navigation_text', 'dpdiscover_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'config_settings', 'dpdiscover_config_settings', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'poller_bottom', 'dpdiscover_poller_bottom', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'utilities_action', 'dpdiscover_utilities_action', 'setup.php');
	api_plugin_register_hook('dpdiscover', 'utilities_list', 'dpdiscover_utilities_list', 'setup.php');

	api_plugin_register_realm('dpdiscover', 'dpdiscover.php,dpdiscover_template.php', 'View Host DPDiscover', 1);

	dpdiscover_setup_table();
	dpdiscover_copy_settings();
	
}

function plugin_dpdiscover_uninstall () {
	// Do any extra Uninstall stuff here
}

function plugin_dpdiscover_check_config () {
	// Here we will check to ensure everything is configured
	dpdiscover_check_upgrade ();
	return true;
}

function plugin_dpdiscover_upgrade () {
	// Here we will upgrade to the newest version
	dpdiscover_check_upgrade ();
	return false;
}

function dpdiscover_version () {
	return plugin_dpdiscover_version();
}

function dpdiscover_check_upgrade () {
	global $config, $database_default;
	include_once($config["library_path"] . "/database.php");
	include_once($config["library_path"] . "/functions.php");

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'dpdiscover.php', 'dpdiscover_template.php', 'findhosts.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$version = plugin_dpdiscover_version();
	$current = $version['version'];
	$old = read_config_option('plugin_dpdiscover_version');
	if(!isset($old)) {
		$old = 0.0;
	}
	if ($current != $old) {
		cacti_log("DPDiscover check upgrade running\n");
		$dpdiscover_columns = array_rekey(db_fetch_assoc("SHOW COLUMNS FROM plugin_dpdiscover_hosts"), "Field", "Field");
		if (!in_array("snmp_version", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_version tinyint(1) unsigned NOT NULL DEFAULT '1' AFTER community");
		}
		if (!in_array("snmp_username", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_username varchar(50) NULL AFTER snmp_version");
		}
		if (!in_array("snmp_password", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_password varchar(50) NULL AFTER snmp_username");
		}
		if (!in_array("snmp_auth_protocol", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_auth_protocol char(5) DEFAULT '' AFTER snmp_password");
		}
		if (!in_array("snmp_priv_passphrase", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_priv_passphrase varchar(200) DEFAULT '' AFTER snmp_auth_protocol");
		}
		if (!in_array("snmp_priv_protocol", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_priv_protocol char(6) DEFAULT '' AFTER snmp_priv_passphrase");
		}
		if (!in_array("snmp_context", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN snmp_context varchar(64) DEFAULT '' AFTER snmp_priv_protocol");
		}
		if (!in_array("lastseen", $dpdiscover_columns)) {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts ADD COLUMN lastseen timestamp NOT NULL AFTER port");
		}
		$uptime = db_fetch_assoc("SHOW COLUMNS FROM plugin_dpdiscover_hosts WHERE Field='sysUptime'");
		if($uptime[0]['Type'] != 'bigint(64)') {
			db_execute("ALTER TABLE plugin_dpdiscover_hosts MODIFY COLUMN sysUptime bigint(64) NOT NULL DEFAULT 0");
		}

		// Set the new version
		db_execute("UPDATE plugin_config SET " .
				"version='" . $version["version"] . "', " .
				"name='" . $version["longname"] . "', " .
				"author='" . $version["author"] . "', " .
				"webpage='" . $version["url"] . "' " .
				"WHERE directory='" . $version["name"] . "' ");
		db_execute("REPLACE INTO settings (name, value) VALUES ('plugin_dpdiscover_version','".$version['version']."')");
	}
}

function plugin_dpdiscover_version () {
	return array(
		'name'     => 'dpdiscover',
		'version'  => '1.45',
		'longname' => 'DP Discover',
		'author'   => 'Eric Stewart',
		'homepage' => 'http://runningoffatthemouth.com/?p=1067',
		'email'    => 'eric@ericdives.com',
		'url'      => 'http://runningoffatthemouth.com/?p=1067'
	);
}

function dpdiscover_utilities_action ($action) {
	if ($action == 'dpdiscover_clear') {
		db_execute('DELETE FROM plugin_dpdiscover_hosts');

		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	}
	return $action;
}

function dpdiscover_utilities_list () {
	global $colors;

	html_header(array("DPDiscover Results"), 2);
	?>
	<tr bgcolor="#<?php print $colors["form_alternate1"];?>">
		<td class="textArea">
			<a href='utilities.php?action=dpdiscover_clear'>Clear DPDiscover Results</a>
		</td>
		<td class="textArea">
			This will clear the results from the discovery polling.
		</td>
	</tr>
	<?php
}

function dpdiscover_config_settings () {
	global $tabs, $settings, $dpdiscover_poller_frequencies;
	$tabs["misc"] = "Misc";

	if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) != 'settings.php')
		return;

	$temp = array(
		"dpdiscover_header" => array(
			"friendly_name" => "DPDiscover",
			"method" => "spacer",
			),
		"dpdiscover_domain_name" => array(
			"friendly_name" => "Domain Name for DNS",
			"description" => "Either due to equipment configuration or discovery protocol implementation, a hostname reported by/to a device may not be reported/stored in a fully qualified state.  DPDiscover won't attempt DNS lookups without a FQDN, and therefore won't add a device without one.  It is strongly suggested you fill this in with the domain name your network equipment is found in (E.G., 'net.yourcompany.com') without a leading or trailing '.'.  It will be appended to hostnames that do not appear to be fully qualified domain names (FQDN).",
			"method" => "textbox",
			"max_length" => 255,
			"default" => ""
			),
		'dpdiscover_use_parent_snmp' => array(
			'friendly_name' => "Attempt Parent SNMP Values",
			'description' => "As new devices are detected from existing devices (a 'parent'), check this box if the parent's SNMP values should be used in an attempt to pull information from the new device.",
			'method' => 'checkbox',
			),
		"dpdiscover_parent_filter" => array(
			"friendly_name" => "Parent Filter",
			"description" => "If 'Attempt Parent SNMP Values' is checked, this may prevent DPDiscover from adding devices you don't want to add.  If this is a substring of the parent's SNMP Community String or SNMP V3 Password, the parent's SNMP information will be attempted.  Leave blank (with 'Attempt Parent SNMP Values' checked) to always attempt a parent device's SNMP values.",
			"method" => "textbox",
			"max_length" => 255,
			"default" => ""
			),
		"dpdiscover_readstrings" => array(
			"friendly_name" => "SNMP Communities",
			"description" => "Fill in the list of available SNMP Community Names to test for this device. Each Community Name must be separated by a colon ':'. These will be tested sequentially.",
			"method" => "textbox",
			"max_length" => 255,
			"default" => "public"
			),
		"dpdiscover_exclude" => array(
			"friendly_name" => "Exclude Host Filters",
			"description" => "A list of filters to match against 'short' names (non-FQDN) to exclude a device from both being probed for 'children' and from being added automatically to Cacti (hosts can still be added manually and will still show up in reports as detected).  Each filter must be separated by a colon ':'.  This helps the detection run faster since hosts are not needlessly searched.",
			"method" => "textbox",
			"max_length" => 255,
			"default" => ""
			),
		"dpdiscover_collection_timing" => array(
			"friendly_name" => "Poller Frequency",
			"description" => "Choose how often to attempt to find devices on  your network.",
			"method" => "drop_array",
			"default" => "disabled",
			"array" => $dpdiscover_poller_frequencies,
			),
		"dpdiscover_base_time" => array(
			"friendly_name" => "Start Time for Polling",
			"description" => "When would you like the first polling to take place.  All future polling times will be based upon this start time.  A good example would be 12:00AM.",
			"default" => "12:00am",
			"method" => "textbox",
			"max_length" => "10"
			),
		'dpdiscover_use_ip_hostname' => array(
			'friendly_name' => "Use IP For Hostname",
			'description' => "For a device to be added to Cacti, the IP must be resolved via DNS.  When adding the device, do you want to use the detected IP as the Cacti hostname?  Yes will reduce queries against the DNS server for the system and give a greater chance of polling occurring should DNS fail somewhere along the line.  No means the derived FQDN will be used instead.",
			'method' => 'checkbox',
			'default' => 'on',
			),
		'dpdiscover_fix_ip_hostname' => array(
			'friendly_name' => "Fix IP For Hostname",
			'description' => "If using the IP for the hostname in Cacti, should DPDiscover, if it cannot contact a known device via IP, check DNS to see if the IP has changed, and if so, update the IP?",
			'method' => 'checkbox',
			'default' => 'on',
			),
		'dpdiscover_fix_names' => array(
			'friendly_name' => "Fix Names",
			'description' => "While looking at discovery information from a device, DPDiscover looks up the IP for 'unknown' equipment.  If the discovered IP is in Cacti, the device with the IP is renamed to the discovered name.  However, if for some reason the device's reported hostname should not be considered authoritative, this should be unchecked.",
			'method' => 'checkbox',
			'default' => 'on',
			),
		'dpdiscover_use_fqdn_for_description' => array(
			'friendly_name' => "Use FQDN for Description",
			'description' => "If checked, when adding a device, the device's fully qualified domain name (however it was derived) will be used for the Cacti Description.  If unchecked, the 'short' host name (everything before the first '.') will be used instead.",
			'method' => 'checkbox',
			),
		'dpdiscover_use_lldp' => array(
			'friendly_name' => "Check Hosts LLDP",
			'description' => "Check all hosts for LLDP (Link Layer Discovery Protocol) information.  Turning this off will prevent hosts from being searched for LLDP data.  Turn this off only if a different discovery protocol is active and preferred.",
			'method' => 'checkbox',
			'default' => 'on',
			),
		'dpdiscover_use_cdp' => array(
			'friendly_name' => "Check Hosts CDP",
			'description' => "Check all hosts for CDP (Cisco Discovery Protocol) information.  Even if a host has LLDP information, some of the hosts connected to it may not talk LLDP, and may only be found via CDP.  Older Cisco IOS devices may not speak or provide LLDP information via SNMP.  It is advised to turn this off only if you have no Cisco devices on your network, or are satisfied that running both LLDP and CDP would cause unneccessary duplication of effort/the script to run longer than it has to.",
			'method' => 'checkbox',
			'default' => 'on',
			),
		'dpdiscover_use_fdp' => array(
			'friendly_name' => "Check Hosts FDP",
			'description' => "Check all hosts for FDP (Foundry Discovery Protocol) information.  Even if a host has LLDP information, some of the hosts connected to it may not talk LLDP, and may only be found via FDP.  Older Brocade/Foundry devices may not speak or provide LLDP information via SNMP.  It is advised to turn this on only if you have Brocade or Foundry devices on your network.",
			'method' => 'checkbox',
			),
		"dpdiscover_email_report" => array(
			"friendly_name" => "Report Email",
			"description" => "Email address to send report of systems added by the plugin.  Leave blank to not send email.",
			"method" => "textbox",
			"max_length" => 255,
			"default" => ""
			),
		'dpdiscover_include_skipped' => array(
			'friendly_name' => "Include Skipped Hosts",
			'description' => "When emailing the report, include a list of hosts discovered but excluded because of \"Exclude Host Filters\" above.",
			'method' => 'checkbox',
			),
		'dpdiscover_fdp_try_v1' => array(
			'friendly_name' => "Try SNMPv1 for FDP",
			'description' => "There's an issue that causes timeouts when walking some Brocade devices, and they may fail to report their FDP information via SNMP appropriately when using SNMPv2.  If while using SNMPv2c during detection, nothing is detected for LLDP, CDP, and FDP, should DPDiscover try the FDP scan a second time with SNMPv1?",
			'method' => 'checkbox',
			),
	);
	if (isset($settings["misc"]))
		$settings["misc"] = array_merge($settings["misc"], $temp);
	else
		$settings["misc"]=$temp;
}

function dpdiscover_show_tab () {
	global $config, $dpdiscover_tab;
	include_once($config["library_path"] . "/database.php");
	include_once($config["base_path"] . "/plugins/dpdiscover/config.php");
	if (api_user_realm_auth('dpdiscover.php')) {
		if (!substr_count($_SERVER["REQUEST_URI"], "dpdiscover.php")) {
			print '<a href="' . $config['url_path'] . 'plugins/dpdiscover/dpdiscover.php"><img src="' . $config['url_path'] . 'plugins/dpdiscover/images/tab_discover.gif" alt="dpdiscover" align="absmiddle" border="0"></a>';
		}else{
			print '<a href="' . $config['url_path'] . 'plugins/dpdiscover/dpdiscover.php"><img src="' . $config['url_path'] . 'plugins/dpdiscover/images/tab_discover_down.gif" alt="dpdiscover" align="absmiddle" border="0"></a>';
		}
	}
}

function dpdiscover_config_arrays () {
	global $menu, $config, $dpdiscover_tab, $dpdiscover_poller_frequencies;

	include_once($config["base_path"] . "/plugins/dpdiscover/config.php");

	$menu["Templates"]['plugins/dpdiscover/dpdiscover_template.php'] = "DPDiscover Templates";

	if (!$dpdiscover_tab) {
		$temp = $menu["Utilities"]['logout.php'];
		unset($menu["Utilities"]['logout.php']);
		$menu["Utilities"]['plugins/dpdiscover/dpdiscover.php'] = "DPDiscover";
		$menu["Utilities"]['logout.php'] = $temp;
	}

	$dpdiscover_poller_frequencies = array(
		"disabled" => "Disabled",
		"60" => "Every 1 Hour",
		"120" => "Every 2 Hours",
		"240" => "Every 4 Hours",
		"360" => "Every 6 Hours",
		"480" => "Every 8 Hours",
		"720" => "Every 12 Hours",
		"1440" => "Every Day",
		"10080" => "Every Week",
		"20160" => "Every 2 Weeks",
		"40320" => "Every 4 Weeks"
		);

}

function dpdiscover_draw_navigation_text ($nav) {
	$nav["dpdiscover.php:"] = array("title" => "DPDiscover", "mapping" => "", "url" => "dpdiscover.php", "level" => "0");
	$nav["dpdiscover_template.php:"] = array("title" => "DPDiscover Templates", "mapping" => "index.php:", "url" => "dpdiscover_template.php", "level" => "1");
	$nav["dpdiscover_template.php:edit"] = array("title" => "Discover Templates", "mapping" => "index.php:", "url" => "dpdiscover_template.php", "level" => "1");
	$nav["dpdiscover_template.php:actions"] = array("title" => "Discover Templates", "mapping" => "index.php:", "url" => "dpdiscover_template.php", "level" => "1");
	$nav["utilities.php:dpdiscover_clear"] = array("title" => "Clear DPDiscover Results", "mapping" => "index.php:,utilities.php:", "url" => "dpdiscover.php", "level" => "1");
	return $nav;
}

function dpdiscover_setup_table () {
	global $config, $database_default;
	include_once($config["library_path"] . "/database.php");

	$data = array();
	$data['columns'][] = array('name' => 'hostname', 'type' => 'varchar(100)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'ip', 'type' => 'varchar(17)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'snmp_community', 'type' => 'varchar(100)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'snmp_version', 'type' => 'tinyint(1)', 'unsigned' => 'unsigned', 'NULL' => false, 'default' => '1');
	$data['columns'][] = array('name' => 'snmp_username', 'type' => 'varchar(50)', 'NULL' => true);
	$data['columns'][] = array('name' => 'snmp_password', 'type' => 'varchar(50)', 'NULL' => true);
	$data['columns'][] = array('name' => 'snmp_auth_protocol', 'type' => 'char(5)', 'default' =>  '');
	$data['columns'][] = array('name' => 'snmp_priv_passphrase', 'type' => 'varchar(200)', 'default' => '');
	$data['columns'][] = array('name' => 'snmp_priv_protocol', 'type' => 'char(6)', 'default' => '');
	$data['columns'][] = array('name' => 'snmp_context', 'type' => 'varchar(64)', 'default' => '');
	$data['columns'][] = array('name' => 'sysName', 'type' => 'varchar(100)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'sysLocation', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'sysContact', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'sysDescr', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'sysUptime', 'type' => 'int(64)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'os', 'type' => 'varchar(64)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'added', 'type' => 'tinyint(4)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'snmp_status', 'type' => 'tinyint(4)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'time', 'type' => 'int(11)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'protocol', 'type' => 'varchar(11)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'parent', 'type' => 'varchar(100)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'port', 'type' => 'varchar(100)', 'NULL' => false, 'default' => '');
	$data['columns'][] = array('name' => 'lastseen', 'type' => 'timestamp', 'NULL' => false);
	$data['primary'] = 'hostname';
	$data['keys'][] = array('name' => 'hostname', 'columns' => 'hostname');
	$data['type'] = 'MyISAM';
	$data['comment'] = 'Plugin DPDiscover - Table of discovered hosts';
	api_plugin_db_table_create('dpdiscover', 'plugin_dpdiscover_hosts', $data);

	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'int(8)', 'NULL' => false, 'auto_increment' => true);
	$data['columns'][] = array('name' => 'host_template', 'type' => 'int(8)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'sysdescr', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
	$data['primary'] = 'id';
	$data['keys'][] = array();
	$data['type'] = 'MyISAM';
	$data['comment'] = 'Plugin DPDiscover - Templates of SysDesc matches to use to auto-add graphs to devices';
	api_plugin_db_table_create('dpdiscover', 'plugin_dpdiscover_template', $data);
}

function dpdiscover_poller_bottom () {
	global $config;

	include_once($config["library_path"] . "/database.php");

	$now = time();
	if (read_config_option("dpdiscover_collection_timing") == "disabled")
		return;

	$t = read_config_option("dpdiscover_last_poll");

	/* Check for the polling interval, only valid with the Multipoller patch */
	$poller_interval = read_config_option("poller_interval");
	if (!isset($poller_interval)) {
		$poller_interval = 300;
	}

	if ($t != '' && ($now - $t < $poller_interval))
		return;

	$command_string = trim(read_config_option("path_php_binary"));

	// If its not set, just assume its in the path
	if (trim($command_string) == '')
		$command_string = "php";
	$extra_args = ' -q ' . $config['base_path'] . '/plugins/dpdiscover/findhosts.php';

	exec_background($command_string, $extra_args);

	if ($t == "")
		$sql = "insert into settings values ('dpdiscover_last_poll','" . $now . "')";
	else
		$sql = "update settings set value = '" . $now . "' where name = 'dpdiscover_last_poll'";
/*	$result = mysql_query($sql) or die (mysql_error()); */
	db_execute($sql);
}

function dpdiscover_copy_settings() {
	$settings_sql = "SELECT * FROM settings WHERE name LIKE 'dpdiscovery_%'";
	$result = db_fetch_assoc($settings_sql);
	if(is_array($result)) {
		foreach($result as $setting) {
			if(preg_match("/dpdiscovery_(.*)$/", $setting['name'], $actuals) > 0) {
				$newsetting = "dpdiscover_".$actuals[1];
				$delete_sql = "DELETE FROM settings WHERE name='".$setting['name']."'";
				db_execute($delete_sql);
				db_execute("REPLACE INTO settings (name, value) VALUES ('".$newsetting."', '".$setting['value']."')");
			}
		}
		return TRUE;
	}
	return FALSE;
}

?>
