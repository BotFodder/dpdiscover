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

chdir('../../');

include("./include/auth.php");
include_once("./lib/utility.php");

$host_actions = array(
	1 => "Delete"
	);

/* set default action */
if (!isset($_REQUEST["action"])) { $_REQUEST["action"] = ""; }

switch ($_REQUEST["action"]) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'edit':
		include_once("./include/top_header.php");

		template_edit();

		include_once("./include/bottom_footer.php");
		break;
	default:
		include_once("./include/top_header.php");

		template();

		include_once("./include/bottom_footer.php");
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	if (isset($_POST["save_component_template"])) {
		$redirect_back = false;

		$save["id"] = $_POST["id"];
		$save["host_template"] = form_input_validate($_POST["host_template"], "host_template", "", false, 3);
//		$save["snmp_version"] = form_input_validate($_POST["snmp_version"], "snmp_version", "", false, 3);
//		$save["tree"] = form_input_validate($_POST["tree"], "tree", "", false, 3);
		$save["sysdescr"] = sql_sanitize($_POST["sysdescr"]);

		if (!is_error_message()) {
			$dpdiscover_template_id = sql_save($save, "plugin_dpdiscover_template");

			if ($dpdiscover_template_id) {
				raise_message(1);
			}else{
				raise_message(2);
			}
		}

		if (is_error_message() || empty($_POST["id"])) {
			header("Location: dpdiscover_template.php?id=" . (empty($dpdiscover_template_id) ? $_POST["id"] : $dpdiscover_template_id));
		}else{
			header("Location: dpdiscover_template.php");
		}
	}
}

/* ------------------------
    The "actions" function
   ------------------------ */

function form_actions() {
	global $colors, $config, $host_actions;

	/* if we are to save this form, instead of display it */
	if (isset($_POST["selected_items"])) {
		$selected_items = unserialize(stripslashes($_POST["selected_items"]));

		if ($_POST["drp_action"] == "1") { /* delete */
			db_execute("delete from plugin_dpdiscover_template where " . array_to_sql_or($selected_items, "id"));
		}

		header("Location: dpdiscover_template.php");
		exit;
	}

	/* setup some variables */
	$host_list = ""; $host_array = array();

	/* loop through each of the discovery templates selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (ereg("^chk_([0-9]+)$", $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$host_list .= "<li>" . db_fetch_cell("select host_template.name from host_template LEFT JOIN plugin_dpdiscover_template ON (plugin_dpdiscover_template.host_template = host_template.id) where plugin_dpdiscover_template.id=" . $matches[1]) . "</li>";
			$host_array[] = $matches[1];
		}
	}

	include_once("./include/top_header.php");

	html_start_box("<strong>" . $host_actions{$_POST["drp_action"]} . "</strong>", "60%", $colors["header_panel"], "3", "center", "");

	print "<form action='dpdiscover_template.php' method='post'>\n";

	if (sizeof($host_array)) {
		if ($_POST["drp_action"] == "1") { /* delete */
			print "	<tr>
					<td class='textArea' bgcolor='#" . $colors["form_alternate1"]. "'>
						<p>When you click 'Continue', the following DPDiscover Template(s) will be deleted.</p>
						<p><ul>$host_list</ul></p>
					</td>
				</tr>\n
				";
		}

		$save_html = "<input type='button' value='Cancel' onClick='window.history.back()'>&nbsp;<input type='submit' value='Continue' title='Place Device(s) on Tree'>";
	}else{
		print "<tr><td bgcolor='#" . $colors["form_alternate1"]. "'><span class='textError'>You must select at least one DPDiscover Template.</span></td></tr>\n";
		$save_html = "<input type='button' value='Return' onClick='window.history.back()'>";
	}

	print "	<tr>
			<td align='right' bgcolor='#eaeaea'>
				<input type='hidden' name='action' value='actions'>
				<input type='hidden' name='selected_items' value='" . (isset($host_array) ? serialize($host_array) : '') . "'>
				<input type='hidden' name='drp_action' value='" . $_POST["drp_action"] . "'>
				$save_html
			</td>
		</tr>
		";

	html_end_box();

	include_once("./include/bottom_footer.php");
}

/* ---------------------
    Template Functions
   --------------------- */

function dpdiscover_get_tree_headers() {
	$headers = array();
	$trees = db_fetch_assoc("SELECT id, name FROM graph_tree ORDER by ID");
	foreach ($trees as $tree) {
		$headers[($tree['id'] + 1000000)] = $tree['name'];
		$items = db_fetch_assoc("SELECT id, title, order_key FROM graph_tree_items WHERE graph_tree_id = " . $tree['id'] . " AND host_id = 0 ORDER BY order_key");
		foreach ($items as $item) {
			$order_key = $item['order_key'];
			$len = strlen($order_key);
			$spaces = '';
			for ($a = 0; $a < $len; $a=$a+3) {
				$n = substr($order_key, $a, 3);
				if ($n != '000') {
					$spaces .= '--';
				} else {
					$a = $len;
				}
			}

			$headers[$item['id']] = $spaces . $item['title'];
		}
	}
	return $headers;
}

function template_edit() {
	global $colors, $snmp_versions;

	$host_template_names = db_fetch_assoc("SELECT id, name FROM host_template");
	$template_names = array();

	if (sizeof($host_template_names) > 0) {
		foreach ($host_template_names as $ht) {
			$template_names[$ht['id']] = $ht['name'];
		}
	}

	$fields_dpdiscover_template_edit = array(
		"host_template" => array(
			"method" => "drop_array",
			"friendly_name" => "Host Template",
			"description" => "Select a Host Template that Devices will be matched to.",
			"value" => "|arg1:host_template|",
			"array" => $template_names,
			),
/*		"snmp_version" => array(
			"method" => "drop_array",
			"friendly_name" => "SNMP Version",
			"description" => "Choose the SNMP version for this host.",
			"value" => "|arg1:snmp_version|",
			"default" => read_config_option("snmp_ver"),
			"array" => $snmp_versions,
			),
*/
		"sysdescr" => array(
			"method" => "textbox",
			"friendly_name" => "System Description",
			"description" => "This is a unique string that will be matched to a devices sysdescr string to pair it to this DPDiscover Template.",
			"value" => "|arg1:sysdescr|",
			"max_length" => "255",
			),
		"id" => array(
			"method" => "hidden_zero",
			"value" => "|arg1:id|"
			),
		"save_component_template" => array(
			"method" => "hidden",
			"value" => "1"
			)
		);

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

	display_output_messages();

	if (!empty($_GET["id"])) {
		$host_template = db_fetch_row("select * from plugin_dpdiscover_template where id=" . $_GET["id"]);
		$header_label = "[edit: " . $template_names[$host_template['host_template']] . "]";
	}else{
		$header_label = "[new]";
		$_GET["id"] = 0;
	}

	html_start_box("<strong>DPDiscover Templates</strong> $header_label", "100%", $colors["header"], "3", "center", "");

	draw_edit_form(array(
		"config" => array(),
		"fields" => inject_form_variables($fields_dpdiscover_template_edit, (isset($host_template) ? $host_template : array()))
		));

	html_end_box();

	form_save_button("dpdiscover_template.php");
}

function template() {
	global $colors, $host_actions;

	/* clean up sort_column */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up search string */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("sort_column", "sess_dpdiscover_template_column", "name");
	load_current_session_value("sort_direction", "sess_dpdiscover_template_sort_direction", "ASC");

	display_output_messages();

	html_start_box("<strong>Discovery Protocol Discover Templates</strong>", "100%", $colors["header"], "3", "center", "dpdiscover_template.php?action=edit");

	$display_text = array(
		"name" => array("Template Title", "ASC"),
		"sysdescr" => array("System Description", "ASC"));

	html_header_sort_checkbox($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

	$dts = db_fetch_assoc("SELECT plugin_dpdiscover_template.*, host_template.name
		FROM plugin_dpdiscover_template LEFT JOIN host_template on (host_template.id = plugin_dpdiscover_template.host_template)
		ORDER BY " . $_REQUEST['sort_column'] . " " . $_REQUEST['sort_direction']);

	$i = 0;
	if (sizeof($dts)) {
		foreach ($dts as $dt) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i, "line" . $dt["id"]); $i++;
			form_selectable_cell('<a class="linkEditMain" href="dpdiscover_template.php?action=edit&id=' . $dt["id"] . '">' . $dt['name'] . '</a>', $dt["id"]);
			form_selectable_cell($dt["sysdescr"], $dt["id"]);
			form_checkbox_cell($dt["sysdescr"], $dt["id"]);
			form_end_row();
		}
	}else{
		print "<tr><td><em>No Templates</em></td></tr>\n";
	}
	html_end_box(false);

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($host_actions);

	print "</form>\n";
}
?>
