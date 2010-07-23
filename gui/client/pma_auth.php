<?php
/**
 * ispCP ω (OMEGA) a Virtual Hosting Control System
 *
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
 * The Original Code is "ispCP - ISP Control Panel".
 *
 * The Initial Developer of the Original Code is ispCP Team.
 * Portions created by Initial Developer are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * @package     ispCP
 * @subpackage  client_sql
 * @copyright   2006-2010 by ispCP | http://isp-control.net
 * @author      Laurent Declercq <laurent.declercq@ispcp.net>
 * @since       1.0.6
 * @version     SVN: $Id$
 * @replace     client/sql_auth.php
 * @link        http://isp-control.net ispCP Home Site
 * @license     http://www.mozilla.org/MPL/ MPL 1.1
 */

/***
 * Script short description:
 *
 * This script allows PhpMyAdmin authentication from ispCP
 */

/*******************************************************************************
 * Functions
 */

/**
 * Get database login credentials
 *
 * @author Laurent Declercq <laurent.declercq@ispcp.net>
 * @since  1.0.6
 * @access private
 * @param  int $dbUserId Database user unique identifier
 * @return array Array that contains login credentials or FALSE on failure
 */
function _getLoginCredentials($dbUserId) {

	/**
	 * @var $db ispCP_Database_ResultSet
	 */
	$db = ispCP_Registry::get('Db');

	$query = "
		SELECT
			`sqlu_name`, `sqlu_pass`
		FROM
			`sql_user`
		WHERE
			`sqlu_id` = ?
	";

	$stmt = exec_query($db, $query, $dbUserId);

	if($stmt->rowCount() == 1) {
			return array(
				$stmt->fields['sqlu_name'],
				decrypt_db_password($stmt->fields['sqlu_pass'])
		);
	} else {
		return false;
	}
}

/**
 * Creates all cookies for PhpMyAdmin
 *
 * @author Laurent Declercq <laurent.declercq@ispcp.net>
 * @since  1.0.6
 * @access private
 * @param  array $cookies Array that contains cookies definitions for PMA
 * @return void
 */
function _pmaCreateCookies($cookies) {

	foreach($cookies as $cookie) {
		header("Set-Cookie: $cookie", false);
	}
}

/**
 * PhpMyAdmin authentication
 *
 * @author Laurent Declercq <laurent.declercq@ispcp.net>
 * @since  1.0.6
 * @param  int $dbUserId Database user unique identifier
 * @return bool TRUE on success, FALSE otherwise
 */
function pmaAuth($dbUserId) {

	/**
	 * @var $cfg ispCP_Config_Handler_File
	 */
	$cfg = ispCP_Registry::get('Config');

	$credentials = _getLoginCredentials($dbUserId);

	if($credentials) {
		$data = http_build_query(
			array(
				'pma_username' => $credentials[0],
				'pma_password' => stripcslashes($credentials[1])
			)
		);
	} else {
		set_page_message(tr('Error: Unknown SQL user id!'));

		return false;
	}

	stream_context_get_default(
		array(
			'http' => array(
				'method' => 'POST',
				'header' => "Host: {$_SERVER['SERVER_NAME']}\r\n" .
				"Content-Type: application/x-www-form-urlencoded\r\n" .
					'Content-Length: ' . strlen($data) . "\r\n" ,
					"Connection: Close\r\n\r\n",
					'content' => $data,
				'user_agent' => 'Mozilla/5.0',
				'max_redirects' => 1,
				'ignore_errors' => 1
			)
		)
	);

	// Gets the headers from PhpMyAdmin
	// @todo Other ports such as 8080 should be settable here
	$headers = get_headers(
		"{$cfg->BASE_SERVER_VHOST_PREFIX}{$_SERVER['SERVER_NAME']}/pma/", true
	);

	if(!$headers || !isset($headers['Location'])) {
		set_page_message(tr('Error: An error occured during authentication!'));

		return false;
	} else {
		_pmaCreateCookies($headers['Set-Cookie']);
		header("Location: {$headers['Location']}");
	}

	return true;
}

/*******************************************************************************
 * Main program
 */

// Include all needed libraries and process to the ispCP intialization
require '../include/ispcp-lib.php';

// Check login
check_login(__FILE__);

/**
 *  Dispatches the request
 */
if(isset($_GET['id'])) {
	if(!pmaAuth((int) $_GET['id'])) {
		user_goto('sql_manage.php');
	}
} else {
	user_goto('/index.php');
}