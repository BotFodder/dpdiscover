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

 CHANGES FOR DPDiscover (Discovery Protocol Discovery) care of:
 Eric Stewart eric@usf.edu / eric@ericdives.com
*/

$guest_account = true;
chdir('../../');
include("./include/auth.php");

dpdiscover_setup_table();

define("MAX_DISPLAY_PAGES", 21);

$os_arr     = array_rekey(db_fetch_assoc("SELECT DISTINCT os FROM plugin_dpdiscover_hosts"), "os", "os");
$status_arr = array('Down', 'Up');

/* ================= input validation ================= */
input_validate_input_number(get_request_var("page"));
input_validate_input_number(get_request_var("rows"));
/* ==================================================== */

/* clean up status string */
if (isset($_REQUEST["status"])) {
	$_REQUEST["status"] = sanitize_search_string(get_request_var("status"));
}

/* clean up snmp string */
if (isset($_REQUEST["snmp"])) {
	$_REQUEST["snmp"] = sanitize_search_string(get_request_var("snmp"));
}

/* clean up os string */
if (isset($_REQUEST["os"])) {
	$_REQUEST["os"] = sanitize_search_string(get_request_var("os"));
}

/* clean up host string */
if (isset($_REQUEST["host"])) {
	$_REQUEST["host"] = sanitize_search_string(get_request_var("host"));
}

/* clean up ip string */
if (isset($_REQUEST["ip"])) {
	$_REQUEST["ip"] = sanitize_search_string(get_request_var("ip"));
}

/* clean up sort_column */
if (isset($_REQUEST["sort_column"])) {
	$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
}

/* clean up search string */
if (isset($_REQUEST["sort_direction"])) {
	$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
}

/* if the user pushed the 'clear' button */
if (isset($_REQUEST["button_clear_x"])) {
	kill_session_var("sess_dpdiscover_current_page");
	kill_session_var("sess_dpdiscover_status");
	kill_session_var("sess_dpdiscover_snmp");
	kill_session_var("sess_dpdiscover_os");
	kill_session_var("sess_dpdiscover_host");
	kill_session_var("sess_dpdiscover_ip");
	kill_session_var("sess_dpdiscover_rows");
	kill_session_var("sess_dpdiscover_sort_column");
	kill_session_var("sess_dpdiscover_sort_direction");

	unset($_REQUEST["page"]);
	unset($_REQUEST["status"]);
	unset($_REQUEST["snmp"]);
	unset($_REQUEST["os"]);
	unset($_REQUEST["host"]);
	unset($_REQUEST["ip"]);
	unset($_REQUEST["rows"]);
	unset($_REQUEST["sort_column"]);
	unset($_REQUEST["sort_direction"]);
}

/* remember these search fields in session vars so we don't have to keep passing them around */
load_current_session_value("page", "sess_dpdiscover_current_page", "1");
load_current_session_value("status", "sess_dpdiscover_status", "");
load_current_session_value("snmp", "sess_dpdiscover_snmp", "");
load_current_session_value("os", "sess_dpdiscover_os", "");
load_current_session_value("host", "sess_dpdiscover_host", "");
load_current_session_value("ip", "sess_dpdiscover_ip", "");
load_current_session_value("rows", "sess_dpdiscover_rows", "-1");
load_current_session_value("sort_column", "sess_dpdiscover_sort_column", "hostname");
load_current_session_value("sort_direction", "sess_dpdiscover_sort_direction", "ASC");

$sql_where  = '';
$status     = get_request_var_request("status");
$snmp       = get_request_var_request("snmp");
$os         = get_request_var_request("os");
$host       = get_request_var_request("host");
$ip         = get_request_var_request("ip");

if ($status == 'Down') {
	$sql_where .= "WHERE up=0";
}else if ($status == 'Up') {
	$sql_where .= "WHERE up=1";
}

if ($snmp == 'Down') {
	$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") . "snmp=0";
}else if ($snmp == 'Up') {
	$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") . "snmp=1";
}

if ($os != '' && in_array($os, $os_arr)) {
	$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") . "os='$os'";
}

if ($host != '') {
	$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") . "hostname like '%$host%'";
}

if ($ip != '') {
	$sql_where .= (strlen($sql_where) ? " AND ":"WHERE ") . "ip like '%$ip%'";
}

if (isset($_GET['button_export_x'])) {
	$result = db_fetch_assoc("SELECT * FROM plugin_dpdiscover_hosts $sql_where order by hostname");

	header("Content-type: application/csv");
	header("Content-Disposition: attachment; filename=dpdiscover_results.csv");
	print "Host,IP,Community Name,SNMP Name,Location,Contact,Description,OS,Parent,Port,Protocol,Uptime,SNMP\n";

	foreach ($result as $host) {
		if ($host['sysUptime'] != 0) {
			$days = intval($host['sysUptime']/8640000);
			$hours = intval(($host['sysUptime'] - ($days * 8640000)) / 360000);
			$uptime = $days . ' days ' . $hours . ' hours';
		} else {
			$uptime = '';
		}
		foreach($host as $h=>$r) {
			$host['$h'] = str_replace(',','',$r);
		}
		print $host['hostname'] . ",";
		print $host['ip'] . ",";
		print $host['community'] . ",";
		print $host['sysName'] . ",";
		print $host['sysLocation'] . ",";
		print $host['sysContact'] . ",";
		print $host['sysDescr'] . ",";
		print $host['os'] . ",";
		print $host['parent'] . ",";
		print $host['port'] . ",";
		print $host['protocol'] . ",";
		print $uptime . ",";
		print $host['snmp_status'] . "\n";
	}
	exit;
}

include(dirname(__FILE__) . "/general_header.php");

$total_rows = db_fetch_cell("SELECT
	COUNT(*)
	FROM plugin_dpdiscover_hosts
	$sql_where");

$page    = get_request_var_request("page");
if (get_request_var_request("rows") == "-1") {
	$per_row = read_config_option("num_rows_device");
}else{
	$per_row = get_request_var_request("rows");
}

$sortby  = get_request_var_request("sort_column");
if ($sortby=="ip") {
	$sortby = "INET_ATON(ip)";
}

$sql_query = "SELECT *
	FROM plugin_dpdiscover_hosts
	$sql_where
	ORDER BY " . $sortby . " " . get_request_var_request("sort_direction") . "
	LIMIT " . ($per_row*($page-1)) . "," . $per_row;

$result = db_fetch_assoc($sql_query);

?>
<script type="text/javascript">
<!--

function applyFilterChange(objForm) {
	strURL = '?status=' + objForm.status.value;
	strURL = strURL + '&ip=' + objForm.ip.value;
	strURL = strURL + '&snmp=' + objForm.snmp.value;
	strURL = strURL + '&os=' + objForm.os.value;
	strURL = strURL + '&host=' + objForm.host.value;
	strURL = strURL + '&rows=' + objForm.rows.value;
	document.location = strURL;
}

-->
</script>
<?php

// TOP DEVICE SELECTION
html_start_box("<strong>Filters</strong>", "100%", $colors["header"], "3", "center", "");

?>
<tr bgcolor="#<?php print $colors["panel"];?>" class="noprint">
	<td class="noprint">
	<form style="padding:0px;margin:0px;" name="form" method="get" action="<?php print $config['url_path'];?>plugins/dpdiscover/dpdiscover.php">
		<table width="100%" cellpadding="0" cellspacing="0">
			<tr class="noprint">
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Status:&nbsp;
				</td>
				<td width="1">
					<select name="status" onChange="applyFilterChange(document.form)">
						<option value=""<?php if (get_request_var_request("status") == "") {?> selected<?php }?>>Any</option>
						<?php
						if (sizeof($status_arr)) {
						foreach ($status_arr as $st) {
							print "<option value='" . $st . "'"; if (get_request_var_request("status") == $st) { print " selected"; } print ">" . $st . "</option>\n";
						}
						}
						?>
					</select>
				</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;OS:&nbsp;
				</td>
				<td width="1">
					<select name="os" onChange="applyFilterChange(document.form)">
						<option value=""<?php if (get_request_var_request("os") == "") {?> selected<?php }?>>Any</option>
						<?php
						if (sizeof($os_arr)) {
						foreach ($os_arr as $st) {
							print "<option value='" . $st . "'"; if (get_request_var_request("os") == $st) { print " selected"; } print ">" . $st . "</option>\n";
						}
						}
						?>
					</select>
				</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;SNMP:&nbsp;
				</td>
				<td width="1">
					<select name="snmp" onChange="applyFilterChange(document.form)">
						<option value=""<?php if (get_request_var_request("snmp") == "") {?> selected<?php }?>>Any</option>
						<?php
						if (sizeof($status_arr)) {
						foreach ($status_arr as $st) {
							print "<option value='" . $st . "'"; if (get_request_var_request("snmp") == $st) { print " selected"; } print ">" . $st . "</option>\n";
						}
						}
						?>
					</select>
				</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Host:&nbsp;
				</td>
				<td width="1">
					<input type="text" name="host" size="25" value="<?php print get_request_var_request("host");?>">
				</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;IP:&nbsp;
				</td>
				<td width="1">
					<input type="text" name="ip" size="15" value="<?php print get_request_var_request("ip");?>">
				</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Rows:&nbsp;
				</td>
				<td width="1">
					<select name="rows" onChange="applyFilterChange(document.form)">
						<option value="-1"<?php if (get_request_var_request("rows") == "-1") {?> selected<?php }?>>Default</option>
						<?php
						if (sizeof($item_rows) > 0) {
						foreach ($item_rows as $key => $value) {
							print "<option value='" . $key . "'"; if (get_request_var_request("rows") == $key) { print " selected"; } print ">" . $value . "</option>\n";
						}
						}
						?>
					</select>
				</td>
				<td nowrap style='white-space: nowrap;'>
					&nbsp;<input type="submit" value="Go" title="Set/Refresh Filters">
					<input type="submit" name="button_clear_x" value="Clear" title="Reset fields to defaults">
					<input type="submit" name="button_export_x" value="Export" title="Export to a file">
				</td>
			</tr>
		</table>
	</form>
	</td>
</tr>
<?php
html_end_box();

html_start_box("", "100%", $colors["header"], "3", "center", "");

/* generate page list */
$url_page_select = get_page_list($page, MAX_DISPLAY_PAGES, $per_row, $total_rows, "dpdiscover.php?view");

$nav = "<tr bgcolor='#" . $colors["header"] . "'>
		<td colspan='13'>
			<table width='100%' cellspacing='0' cellpadding='0' border='0'>
				<tr>
					<td align='left' class='textHeaderDark'>
						<strong>&lt;&lt; "; if ($page > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("dpdiscover.php?view&status=$status&os=$os&snmp=$snmp&ip=$ip&host=$host" . "&page=" . ($page-1)) . "'>"; } $nav .= "Previous"; if ($page > 1) { $nav .= "</a>"; } $nav .= "</strong>
					</td>\n
					<td align='center' class='textHeaderDark'>
						Showing Rows " . (($per_row*($page-1))+1) . " to " . ((($total_rows < $per_row) || ($total_rows < ($per_row*$page))) ? $total_rows : ($per_row*$page)) . " of $total_rows [$url_page_select]
					</td>\n
					<td align='right' class='textHeaderDark'>
						<strong>"; if (($page * get_request_var_request("host_rows")) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("dpdiscover.php?view&status=$status&os=$os&snmp=$snmp&ip=$ip&host=$host" . "&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * get_request_var_request("host_rows")) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
					</td>\n
				</tr>
			</table>
		</td>
	</tr>\n";

print $nav;

$display_text = array(
	"hostname" => array("Host", "ASC"),
	"ip" => array("IP", "ASC"),
	"sysName" => array("SNMP Name", "ASC"),
	"sysLocation" => array("Location", "ASC"),
	"sysContact" => array("Contact", "ASC"),
	"sysDescr" => array("Description", "ASC"),
	"os" => array("Template", "ASC"),
	"parent" => array("Parent", "ASC"),
	"port" => array("Port", "ASC"),
	"protocol" => array("Protocol", "DESC"),
	"time" => array("Uptime", "DESC"),
	"snmp" => array("SNMP", "DESC"),
//	"up" => array("Status", "ASC"),
	"nosort" => array("", ""));

html_header_sort($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), false);

$snmp_version        = read_config_option("snmp_ver");
$snmp_port           = read_config_option("snmp_port");
$snmp_timeout        = read_config_option("snmp_timeout");
$snmp_username       = read_config_option("snmp_username");
$snmp_password       = read_config_option("snmp_password");
$max_oids            = read_config_option("max_get_size");
$ping_method         = read_config_option("ping_method");
$availability_method = read_config_option("availability_method");

$i=0;
$status = array('<font color=red>Down</font>','<font color=green>Up</font>');
if (sizeof($result)) {
	foreach($result as $row) {
		form_alternate_row_color($colors["alternate"], $colors["light"], $i); $i++;
		if ($row['sysUptime'] != 0) {
			$days = intval($row['sysUptime']/8640000);
			$hours = intval(($row['sysUptime'] - ($days * 8640000)) / 360000);
			$uptime = $days . ' days ' . $hours . ' hours';
		} else {
			$uptime = '';
		}
		if ($row["hostname"] == "") {
			$row["hostname"] = "Not Detected";
		}

		print"<td style='padding: 4px; margin: 4px;'>" . $row['hostname'] . "</td>
			<td>" . $row['ip'] . '</td>
			<td>' . $row['sysName'] . '</td>
			<td>' . $row['sysLocation'] . '</td>
			<td>' . $row['sysContact'] . '</td>
			<td>' . $row['sysDescr'] . '</td>
			<td>' . $row['os'] . '</td>
			<td>' . $row['parent'] . '</td>
			<td>' . $row['port'] . '</td>
			<td>' . $row['protocol'] . '</td>
			<td>' . $uptime . '</td>
			<td>' . $status[$row['snmp_status']] . '</td>
			<td align="right">';
//			<td>' . $status[$row['up']] . '</td>
if ($row['protocol'] != "known" && $row['added'] != 1) {
		print "'<form style=\"padding:0px;margin:0px;\" method=\"post\" action=\"../../host.php\">
			<input type=hidden name=save_component_host value=1>
			<input type=hidden name=host_template_id value=0>
			<input type=hidden name=action value=\"save\">
			<input type=hidden name=hostname value=\"" . $row['ip'] . "\">
			<input type=hidden name=id value=0>
			<input type=hidden name=description value=\"".$row['hostname']."\">
			<input type=hidden name=snmp_community value=\"" . $row['community'] . "\">
			<input type=hidden name=snmp_version value=\"$snmp_version\">
			<input type=hidden name=snmp_username value=\"$snmp_username\">
			<input type=hidden name=snmp_password value=\"$snmp_password\">
			<input type=hidden name=snmp_port value=$snmp_port>
			<input type=hidden name=snmp_timeout value=$snmp_timeout>
			<input type=hidden name=snmp_password_confirm value=\"\">
			<input type=hidden name=availability_method value=\"$availability_method\">
			<input type=hidden name=ping_method value=\"$ping_method\">
			<input type=hidden name=ping_port value=\"\">
			<input type=hidden name=ping_timeout value=\"\">
			<input type=hidden name=ping_retries value=\"\">
			<input type=hidden name=notes value=\"\">
			<input type=hidden name=snmp_auth_protocol value=\"\">
			<input type=hidden name=snmp_priv_passphrase value=\"\">
			<input type=hidden name=snmp_priv_protocol value=\"\">
			<input type=hidden name=snmp_context value=\"\">
			<input type=hidden name=max_oids value=\"$max_oids\">
			<input type=hidden name=device_threads value=\"1\">
			<input type='submit' value='Add' style='text-align:middle;font-size:11px;'>
			</form></td>";
}else{
		print "</td>";
}
	}
}else{
	print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Hosts to display!</center></td></tr>";
}

print $nav;

html_end_box(false);

include_once("./include/bottom_footer.php");

?>
