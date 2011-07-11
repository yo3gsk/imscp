<?php
/**
 * i-MSCP a internet Multi Server Control Panel
 *
 * @copyright 	2001-2006 by moleSoftware GmbH
 * @copyright 	2006-2010 by ispCP | http://isp-control.net
 * @copyright 	2010 by i-msCP | http://i-mscp.net
 * @version 	SVN: $Id$
 * @link 		http://i-mscp.net
 * @author 		ispCP Team
 * @author 		i-MSCP Team
 *
 * @license
 * The contents of this file are subject to the Mozilla Public License
 * Version 1.1 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS"
 * basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 * License for the specific language governing rights and limitations
 * under the License.
 *
 * The Original Code is "VHCS - Virtual Hosting Control System".
 *
 * The Initial Developer of the Original Code is moleSoftware GmbH.
 * Portions created by Initial Developer are Copyright (C) 2001-2006
 * by moleSoftware GmbH. All Rights Reserved.
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 * Portions created by the i-MSCP Team are Copyright (C) 2010 by
 * i-MSCP a internet Multi Server Control Panel. All Rights Reserved.
 */

require 'include/imscp-lib.php';

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onResellerScriptStart);

$cfg = iMSCP_Registry::get('config');

check_login(__FILE__, $cfg->PREVENT_EXTERNAL_LOGIN_RESELLER);

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic('page', $cfg->RESELLER_TEMPLATE_PATH . '/index.tpl');
$tpl->define_dynamic('def_language', 'page');
$tpl->define_dynamic('def_layout', 'page');
$tpl->define_dynamic('no_messages', 'page');
$tpl->define_dynamic('msg_entry', 'page');
$tpl->define_dynamic('traff_warn', 'page');
$tpl->define_dynamic('layout', 'page');
$tpl->define_dynamic('logged_from', 'page');
$tpl->define_dynamic('traff_warn', 'page');
$tpl->define_dynamic('t_software_support', 'page');

// page functions.
function gen_system_message($tpl) {
	$user_id = $_SESSION['user_id'];

	$query = "
		SELECT
			COUNT(`ticket_id`) AS cnum
		FROM
			`tickets`
		WHERE
			(`ticket_to` = ? OR `ticket_from` = ?)
		AND
			(`ticket_status` IN ('1', '4')
			AND
			`ticket_level` = 1) OR
			(`ticket_status` IN ('2')
			AND
			`ticket_level` = 2)
		AND
			`ticket_reply` = 0
	";

	$rs = exec_query($query, array($user_id, $user_id));

	$num_question = $rs->fields('cnum');

	if ($num_question == 0) {
		$tpl->assign(array('MSG_ENTRY' => ''));
	} else {
		$tpl->assign(
			array(
				'TR_NEW_MSGS' => tr('You have <b>%d</b> new support questions', $num_question),
				'TR_VIEW' => tr('View')
			)
		);

		$tpl->parse('MSG_ENTRY', 'msg_entry');
	}
}


function gen_traff_usage($tpl, $usage, $max_usage, $bars_max) {
	if (0 !== $max_usage) {
		list($percent, $bars) = calc_bars($usage, $max_usage, $bars_max);
		$traffic_usage_data = tr('%1$s%% [%2$s of %3$s]', $percent, sizeit($usage), sizeit($max_usage));
	} else {
		$percent = 0;
		$bars = 0;
		$traffic_usage_data = tr('%1$s%% [%2$s of unlimited]', $percent, sizeit($usage), sizeit($max_usage));
	}

	$tpl->assign(
		array(
			'TRAFFIC_USAGE_DATA' => $traffic_usage_data,
			'TRAFFIC_BARS'       => $bars,
			'TRAFFIC_PERCENT'    => $percent > 100 ? 100 : $percent,
		)
	);
}

function gen_disk_usage($tpl, $usage, $max_usage, $bars_max) {
	if (0 !== $max_usage) {
		list($percent, $bars) = calc_bars($usage, $max_usage, $bars_max);
		$traffic_usage_data = tr('%1$s%% [%2$s of %3$s]', $percent, sizeit($usage), sizeit($max_usage));
	} else {
		$percent = 0;
		$bars = 0;
		$traffic_usage_data = tr('%1$s%% [%2$s of unlimited]', $percent, sizeit($usage));
	}

	$tpl->assign(
		array(
			'DISK_USAGE_DATA' => $traffic_usage_data,
			'DISK_BARS'       => $bars,
			'DISK_PERCENT'    => $percent > 100 ? 100 : $percent,
		)
	);
}

function generate_page_data($tpl, $reseller_id, $reseller_name) {
	global $crnt_month, $crnt_year;

	$crnt_month = date("m");
	$crnt_year = date("Y");

	// global
	$tmpArr = get_reseller_default_props($reseller_id);

	if ($tmpArr != NULL) { // there are data in db
		list($rdmn_current, $rdmn_max,
			$rsub_current, $rsub_max,
			$rals_current, $rals_max,
			$rmail_current, $rmail_max,
			$rftp_current, $rftp_max,
			$rsql_db_current, $rsql_db_max,
			$rsql_user_current, $rsql_user_max,
			$rtraff_current, $rtraff_max,
			$rdisk_current, $rdisk_max
		) = $tmpArr;
	} else {
		list($rdmn_current, $rdmn_max,
			$rsub_current, $rsub_max,
			$rals_current, $rals_max,
			$rmail_current, $rmail_max,
			$rftp_current, $rftp_max,
			$rsql_db_current, $rsql_db_max,
			$rsql_user_current, $rsql_user_max,
			$rtraff_current, $rtraff_max,
			$rdisk_current, $rdisk_max
		) = array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
	}

	list($udmn_current,,,$usub_current,,,$uals_current,,,$umail_current,,,
		$uftp_current,,,$usql_db_current,,,$usql_user_current,,,$utraff_current,
		$utraff_max,,$udisk_current, $udisk_max
	) = generate_reseller_user_props($reseller_id);

	// Convert into MB values
	$rtraff_max = $rtraff_max * 1024 * 1024;

	$rtraff_current = $rtraff_current * 1024 * 1024;

	$rdisk_max = $rdisk_max * 1024 * 1024;

	$rdisk_current = $rdisk_current * 1024 * 1024;

	$utraff_max = $utraff_max * 1024 * 1024;

	$udisk_max = $udisk_max * 1024 * 1024;

	list($traff_percent, $traff_red, $traff_green) = make_usage_vals($utraff_current, $rtraff_max);

	gen_traff_usage($tpl, $utraff_current, $rtraff_max, 400);

	gen_disk_usage($tpl, $udisk_current, $rdisk_max, 400);

	if ($rtraff_max > 0) {
		if ($utraff_current > $rtraff_max) {
			$tpl->assign('TR_TRAFFIC_WARNING', tr('You are exceeding your traffic limit!'));
		} else {
			$tpl->assign('TRAFF_WARN', '');
		}
	} else {
		$tpl->assign('TRAFF_WARN', '');
	}
	// warning HDD Usage
	if ($rdisk_max > 0) {
		if ($udisk_current > $rdisk_max) {
			$tpl->assign('TR_DISK_WARNING', tr('You are exceeding your disk limit!')
				);
		} else {
			$tpl->assign('DISK_WARN', '');
		}
	} else {
		$tpl->assign('DISK_WARN', '');
	}

	$tpl->assign(
		array(
			"ACCOUNT_NAME" => tr("Account name"),
			"GENERAL_INFO" => tr("General information"),
			"DOMAINS" => tr("User accounts"),
			"SUBDOMAINS" => tr("Subdomains"),
			"ALIASES" => tr("Aliases"),
			"MAIL_ACCOUNTS" => tr("Mail account"),
			"TR_FTP_ACCOUNTS" => tr("FTP account"),
			"SQL_DATABASES" => tr("SQL databases"),
			"SQL_USERS" => tr("SQL users"),
			"TRAFFIC" => tr("Traffic"),
			"DISK" => tr("Disk"),
			"TR_EXTRAS" => tr("Extras")
		)
	);

	$tpl->assign(
		array(
			'RESELLER_NAME' => tohtml($reseller_name),

			'TRAFF_RED' => $traff_red * 3,
			'TRAFF_GREEN' => $traff_green * 3,
			'TRAFF_PERCENT' => $traff_percent > 100 ? 100 : $traff_percent,

			'TRAFF_MSG' => ($rtraff_max)
				? tr('%1$s / %2$s of <b>%3$s</b>', sizeit($utraff_current), sizeit($rtraff_current), sizeit($rtraff_max))
				: tr('%1$s / %2$s of <b>unlimited</b>', sizeit($utraff_current), sizeit($rtraff_current)),

			'DISK_MSG' => ($rdisk_max)
				? tr('%1$s / %2$s of <b>%3$s</b>', sizeit($udisk_current), sizeit($rdisk_current), sizeit($rdisk_max))
				: tr('%1$s / %2$s of <b>unlimited</b>', sizeit($udisk_current), sizeit($rdisk_current)),

			'DMN_MSG' => ($rdmn_max)
				? tr('%1$d / %2$d of <b>%3$d</b>', $udmn_current, $rdmn_current, $rdmn_max)
				: tr('%1$d / %2$d of <b>unlimited</b>', $udmn_current, $rdmn_current),

			'SUB_MSG' => ($rsub_max > 0)
				? tr('%1$d / %2$d of <b>%3$d</b>', $usub_current, $rsub_current, $rsub_max)
				: (($rsub_max === "-1") ? tr('<b>disabled</b>') : tr('%1$d / %2$d of <b>unlimited</b>', $usub_current, $rsub_current)),

			'ALS_MSG' => ($rals_max > 0)
				? tr('%1$d / %2$d of <b>%3$d</b>', $uals_current, $rals_current, $rals_max)
				: (($rals_max === "-1") ? tr('<b>disabled</b>') : tr('%1$d / %2$d of <b>unlimited</b>', $uals_current, $rals_current)),

			'MAIL_MSG' => ($rmail_max > 0)
				? tr('%1$d / %2$d of <b>%3$d</b>', $umail_current, $rmail_current, $rmail_max)
				: (($rmail_max === "-1") ? tr('<b>disabled</b>') : tr('%1$d / %2$d of <b>unlimited</b>', $umail_current, $rmail_current)),

			'FTP_MSG' => ($rftp_max > 0)
				? tr('%1$d / %2$d of <b>%3$d</b>', $uftp_current, $rftp_current, $rftp_max)
				: (($rftp_max === "-1") ? tr('<b>disabled</b>') : tr('%1$d / %2$d of <b>unlimited</b>', $uftp_current, $rftp_current)),

			'SQL_DB_MSG' => ($rsql_db_max > 0)
				? tr('%1$d / %2$d of <b>%3$d</b>', $usql_db_current, $rsql_db_current, $rsql_db_max)
				: (($rsql_db_max === "-1") ? tr('<b>disabled</b>') : tr('%1$d / %2$d of <b>unlimited</b>', $usql_db_current, $rsql_db_current)),

			'SQL_USER_MSG' => ($rsql_user_max > 0)
				? tr('%1$d / %2$d of <b>%3$d</b>', $usql_user_current, $rsql_user_current, $rsql_user_max)
				: (($rsql_user_max === "-1") ? tr('<b>disabled</b>') : tr('%1$d / %2$d of <b>unlimited</b>', $usql_user_current, $rsql_user_current)),

			'EXTRAS' => ''
		)
	);
}

function gen_messages_table($tpl, $admin_id) {

	$query = "
		SELECT
			`ticket_id`
		FROM
			`tickets`
		WHERE
			(`ticket_from` = ? OR `ticket_to` = ?)
		AND
			`ticket_status` IN ('1', '4')
		AND
			`ticket_reply` = '0'
	;";
	$res = exec_query($query, array($admin_id, $admin_id));

	$questions = $res->rowCount();

	if ($questions == 0) {
		$tpl->assign(
			array(
				'TR_NO_NEW_MESSAGES' => tr('You have no new support questions!'),
				'MSG_ENTRY' => ''
			)
		);
	} else {
		$tpl->assign(
			array(
				'TR_NEW_MSGS' => tr('You have <b>%d</b> new support questions', $questions),
				'NO_MESSAGES' => '',
				'TR_VIEW' => tr('View')
			)
		);

		$tpl->parse('MSG_ENTRY', '.msg_entry');
	}
}
// common page data.

$tpl->assign(
	array(
		'TR_RESELLER_MAIN_INDEX_PAGE_TITLE' => tr('i-MSCP - Reseller/Main Index'),
		'TR_SAVE' => tr('Save'),
		'TR_MESSAGES' => tr('Messages'),
		'TR_LANGUAGE' => tr('Language'),
		'TR_CHOOSE_DEFAULT_LANGUAGE' => tr('Choose default language'),
		'TR_CHOOSE_DEFAULT_LAYOUT' => tr('Choose default layout'),
		'TR_LAYOUT' => tr('Layout'),
		'TR_TRAFFIC_USAGE' => tr('Traffic usage'),
		'TR_DISK_USAGE' => tr ('Disk usage'),
		'THEME_COLOR_PATH' => "../themes/{$cfg->USER_INITIAL_THEME}",
		'THEME_CHARSET' => tr('encoding'),
		'ISP_LOGO' => get_logo($_SESSION['user_id'])
	)
);

// dynamic page data.

generate_page_data($tpl, $_SESSION['user_id'], $_SESSION['user_logged']);

// Makes sure that the language selected is the reseller's language
if (!isset($_SESSION['logged_from']) && !isset($_SESSION['logged_from_id'])) {
	list($user_def_lang, $user_def_layout) = get_user_gui_props($_SESSION['user_id']);
} else {
	$user_def_layout = $_SESSION['user_theme'];
	$user_def_lang = $_SESSION['user_def_lang'];
}

gen_messages_table($tpl, $_SESSION['user_id']);

gen_logged_from($tpl);

gen_def_language($tpl, $user_def_lang);

gen_def_layout($tpl, $user_def_layout);

gen_reseller_mainmenu($tpl, $cfg->RESELLER_TEMPLATE_PATH . '/main_menu_general_information.tpl');
gen_reseller_menu($tpl, $cfg->RESELLER_TEMPLATE_PATH . '/menu_general_information.tpl');
get_reseller_software_permission ($tpl, $_SESSION['user_id']);

gen_system_message($tpl);

// static page messages.

generatePageMessage($tpl);

$tpl->assign('LAYOUT', '');
$tpl->parse('PAGE', 'page');

iMSCP_Events_Manager::getInstance()->dispatch(
    iMSCP_Events::onResellerScriptEnd, new iMSCP_Events_Response($tpl));

$tpl->prnt();

unsetMessages();