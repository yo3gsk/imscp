<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @category    iMSCP
 * @package     iMSCP_Core
 * @subpackage  Client
 * @copyright   2010-2013 by i-MSCP team
 * @author      Laurent Declercq <l.declercq@nuxwin.com>
 * @author      iMSCP Team
 * @link        http://www.i-mscp.net i-MSCP Home Site
 * @license     http://www.gnu.org/licenses/gpl-2.0.txt GPL v2
 */

/***********************************************************************************************************************
 * Functions
 */

/**
 * Validate the given DNS MX record
 *
 * @access private
 * @param string &$name Name
 * @param int $priority MX preference
 * @param string $host Mail host
 * @param $verifiedData
 * @return bool TRUE if the given MX DNS record is valid, FALSE otherwise
 */
function _client_validateDnsMxRecord($name, $priority, $host, $verifiedData)
{
    $validator = iMSCP_Validate::getInstance();

    // Should never occurs since we are using options stack in HTML form
    $nameTmp = (strpos($name, '*') === 0) ? substr($name, 2) : $name; // strip out the wildcard part (*.) in any
    if (!$validator->assertEquals($verifiedData['item_name'], $nameTmp, tr('Invalid type detected.'))) {
        set_page_message($validator->getLastValidationMessages(), 'error');
        return false;
    }

    //  // Should never occurs since we are using options stack in HTML form
    if (!$validator->assertContains($priority, array('10', '15', '20', '25', '30'), tr('Invalid MX priority.'))) {
        set_page_message($validator->getLastValidationMessages(), 'error');
        return false;
    }

    // Mail host must not be equal to the domain for which it's added
    // Mail host must be a fully qualified hostname (IP are not allowed)
    if (
        !$validator->assertNotEquals(
            $verifiedData['item_name'], encode_idna($host), tr('The mail host must not be equal to the domain name for which you add it.')
        ) ||
        !$validator->hostname($host, array('tld' => false))
    ) {
        set_page_message($validator->getLastValidationMessages(), 'error');
        return false;
    }

    return true;
}

/**
 * Returns verified data
 *
 * @access private
 * @param int $itemId Item id (Domain ID or domain alias id)
 * @param string $itemType Item type (normal or alias
 * @return array An array that holds verified data (main domain id and item name)
 */
function _client_getVerifiedData($itemId, $itemType)
{
    $domainProps = get_domain_default_props($_SESSION['user_id']);
    $domainId = $domainProps['domain_id'];

    if ($itemType == 'normal') {
        $query = 'SELECT `domain_id`, `domain_name` AS `name`, `external_mail_dns_ids` FROM `domain` WHERE `domain_id` = ?';
        $stmt = exec_query($query, $domainId);

        if (!$stmt->rowCount() || $stmt->fields['domain_id'] !== $itemId) {
            set_page_message(tr('Your are not the owner of this domain.'), 'error');
            redirectTo('mail_external.php');
        }
    } elseif ($itemType == 'alias') {
        $query = '
            SELECT
                `domain_id`, `alias_name` AS `name`, `external_mail_dns_ids`
            FROM
                `domain_aliasses`
            WHERE
                `alias_id` = ?
            AND
                `domain_id` = ?
        ';
        $stmt = exec_query($query, array($itemId, $domainId));

        if (!$stmt->rowCount()) {
            set_page_message(tr('Your are not the owner of this domain alias.'), 'error');
            redirectTo('mail_external.php');
        }
    } else {
        set_page_message(tr('Wrong request.'), 'error');
        redirectTo('mail_external.php');
        exit;
    }

    return array(
        'domain_id' => $domainId,
        'item_id' => $itemId,
        'item_name' => $stmt->fields['name'],
        'item_type' => $itemType,
        'external_mail_dns_ids' => explode(',', $stmt->fields['external_mail_dns_ids'])
    );
}

/**
 * Update external mail server entries
 *
 * Note: In case all entries are marked as to be deleted, the external mail server is deactivated
 *
 * @throws iMSCP_Exception_Database
 * @param array $item Item data (item id and item type)
 * @return void
 */
function client_editExternalMailServerEntries($item)
{
    $verifiedData = _client_getVerifiedData($item[0], $item[1]);

    if (!empty($_POST)) {
        // Preparing entries stack
        $data['to_update'] = (isset($_POST['to_update'])) ? $_POST['to_update'] : array();
        $data['to_delete'] = (isset($_POST['to_delete'])) ? $_POST['to_delete'] : array();
        $data['name'] = (isset($_POST['name'])) ? $_POST['name'] : array();
        $data['priority'] = (isset($_POST['priority'])) ? $_POST['priority'] : array();
        $data['host'] = (isset($_POST['host'])) ? $_POST['host'] : array();

        iMSCP_Events_Manager::getInstance()->dispatch(
            iMSCP_Events::onBeforeAddExternalMailServer, array('externalMailServerEntries' => &$data)
        );

        $entriesCount = count($data['name']);
        $error = false;

        // Validate all entries
        for ($index = 0; $index < $entriesCount; $index++) {
            if (isset($data['name'][$index]) && isset($data['priority'][$index]) && isset($data['host'][$index])) {
                $data['host'][$index] = strtolower(rtrim($data['host'][$index], '.'));

                if (empty($data['to_delete'][$index]) && !_client_validateDnsMxRecord(
                    $data['name'][$index], $data['priority'][$index], $data['host'][$index], $verifiedData)
                ) {
                    $error = true;
                }
            } else { // Not all expected data were received
                set_page_message(tr('Wrong request.'), 'error');
                redirectTo('mail_external.php');
            }
        }

        // Add entries into database
        if (!$error) {
            /** @var $db iMSCP_Database */
            $db = iMSCP_Registry::get('db');
            $db->beginTransaction(); // All successfully inserted or nothing

            try {
                $dnsEntriesIds = '';
                $wildcardMxOnly = true;

                for ($index = 0; $index < $entriesCount; $index++) {
                    // Entry to delete
                    if (!empty($data['to_delete'][$index]) &&
                        in_array($data['to_delete'][$index], $verifiedData['external_mail_dns_ids'])
                    ) {
                        $query = 'DELETE FROM `domain_dns` WHERE `domain_dns_id` = ?';
                        exec_query($query, $data['to_delete'][$index]);

                        //  Entry to update
                    } elseif (!empty($data['to_update'][$index]) &&
                        in_array($data['to_update'][$index], $verifiedData['external_mail_dns_ids'])
                    ) {
                        if(strpos($data['name'][$index], '*') === false) {
                            $wildcardMxOnly = false;
                        }

                        $query = 'UPDATE `domain_dns` SET `domain_dns` = ?, `domain_text` = ? WHERE `domain_dns_id` = ?';
                        exec_query(
                            $query,
                            array(
                                $data['name'][$index] . '.',
                                "{$data['priority'][$index]}\t" . encode_idna($data['host'][$index]) . '.',
                                $data['to_update'][$index],
                            )
                        );

                        $dnsEntriesIds .= ',' . $data['to_update'][$index];

                        // Entry to add
                    } else {
                        if(strpos($data['name'][$index], '*') === false) {
                            $wildcardMxOnly = false;
                        }

                        // Try to insert MX record into the domain_dns database table
                        $query = '
                          INSERT INTO `domain_dns` (
                            `domain_id`, `alias_id`, `domain_dns`, `domain_class`, `domain_type`, `domain_text`, `protected`
                          ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?
                          )
                        ';
                        exec_query(
                            $query,
                            array(
                                $verifiedData['domain_id'],
                                ($verifiedData['item_type'] == 'alias') ? $verifiedData['item_id'] : 0,
                                $data['name'][$index] . '.',
                                'IN',
                                'MX',
                                "{$data['priority'][$index]}\t" . encode_idna($data['host'][$index]) . '.',
                                'yes' // Protect the entry against deletion from the custom DNS interface
                            )
                        );

                        $dnsEntriesIds .= ',' . $db->insertId();
                    }
                }

                /** @var $cfg iMSCP_Config_Handler_File */
                $cfg = iMSCP_Registry::get('config');
                $externalMailServer = ($dnsEntriesIds != '') ? (($wildcardMxOnly) ? 'wildcard' : 'on') : 'off';

                if ($verifiedData['item_type'] == 'normal') {
                    $query = '
                      UPDATE
                        `domain` SET `external_mail` = ?, `domain_status` = ?, `external_mail_dns_ids` = ?
                      WHERE
                        `domain_id` = ?
                    ';
                    exec_query(
                        $query,
                        array(
                            $externalMailServer,
                            $cfg->ITEM_DNSCHANGE_STATUS,
                            ltrim($dnsEntriesIds, ','),
                            $verifiedData['item_id']
                        )
                    );
                } else {
                    $query = '
                      UPDATE
                        `domain_aliasses` SET `external_mail` = ?, `alias_status` = ?, `external_mail_dns_ids` = ?
                      WHERE
                        `alias_id` = ?
                    ';
                    exec_query(
                        $query,
                        array(
                            $externalMailServer,
                            $cfg->ITEM_DNSCHANGE_STATUS,
                            ltrim($dnsEntriesIds, ','),
                            $verifiedData['item_id']
                        )
                    );
                }

                $db->commit(); // Commit the transaction - All data will be now added into the database

                iMSCP_Events_Manager::getInstance()->dispatch(
                    iMSCP_Events::onAfterAddExternalMailServer, array('externalMailServerEntries' => &$data)
                );

                send_request(); // Ask the daemon to trigger backend dispatcher
                if ($externalMailServer == 'on' || $externalMailServer == 'wildcard') {
                    set_page_message(tr('External mail server entries scheduled for modification.'), 'success');
                } else {
                    set_page_message(tr('External mail server entries successfully scheduled for deletion.'), 'success');
                }
                redirectTo('mail_external.php');
            } catch (iMSCP_Exception_Database $e) {
                $db->rollBack();

                if ($e->getCode() === 23000) { // Entry already exists in domain_dns table or is defined twice in entries stack?
                    set_page_message(
                        tr(
                            'The entry %s already exists or is defined twice below.',
                            "<strong>{$data['name'][$index]} IN MX {$data['priority'][$index]} {$data['host'][$index]}</strong>"
                        ),
                        'error'
                    );
                } else { // Another error?
                    throw new iMSCP_Exception_Database($e->getMessage(), $e->getQuery(), $e->getCode(), $e);
                }
            }
        }
    } else {
        if (!empty($verifiedData['external_mail_dns_ids'])) {
            $query = 'SELECT * FROM `domain_dns` WHERE `domain_dns_id` IN(' . implode(',', $verifiedData['external_mail_dns_ids']) . ')';
            $stmt = exec_query($query);

            if ($stmt->rowCount()) {
                $data = array();
                while (!$stmt->EOF) {
                    $data['to_update'][] = $stmt->fields['domain_dns_id'];
                    $data['name'][] = rtrim($stmt->fields['domain_dns'], '.');
                    list($priority, $host) = explode("\t", $stmt->fields['domain_text'], 2);
                    $data['priority'][] = trim($priority);
                    $data['host'][] = trim($host);

                    $stmt->moveNext();
                }
            } else { // DNS entries pointed by domain or domain alias were not found (should never occurs)
                if ($verifiedData['item_type'] == 'normal') {
                    $query = "UPDATE `domain` SET `domain_status` = ?,  `external_mail` = ?, `external_mail_dns_ids` = ? WHERE domain_id = ?";
                } else {
                    $query = "UPDATE `domain_aliasses` SET `alias_status` = ?, `external_mail` = ?, `external_mail_dns_ids` = ? WHERE alias_id = ?";
                }

                /** @var $cfg iMSCP_Config_Handler_File */
                $cfg = iMSCP_Registry::get('config');
                exec_query($query, array($cfg->ITEM_DNSCHANGE_STATUS, 'off', null, $verifiedData['item_id']));
                send_request();

                set_page_message(tr('Entries associated to your external mail servers were not found. A Resynchronization has been scheduled.'), 'warning');
                redirectTo('mail_external.php');
                exit; // Only to make some IDE happy
            }
        } else {
            set_page_message('An unexpected error occurred.', 'error');
            redirectTo('mail_external.php'); // No domain or domain alias data found (should never occurs)
            exit; // Only to make some IDE happy
        }
    }

    client_generateView($verifiedData, $data);
}

/**
 * Generate view
 *
 * @param array $verifiedData Verified data
 * @param array $data Page data
 * @return void
 */
function client_generateView($verifiedData, $data)
{
    /** @var $tpl iMSCP_pTemplate */
    $tpl = iMSCP_Registry::get('templateEngine');

    /** @var $cfg iMSCP_Config_Handler_File */
    $cfg = iMSCP_Registry::get('config');
    $selectedOption = $cfg->HTML_SELECTED;
    $idnItemName = rtrim($verifiedData['item_name'], '.');
    $entriesCount = isset($data['name']) ? count($data['name']) : 0;
    $domainMx = tr('Domain');
    $wildcardMx = tr('Wildcard');

    $tpl->assign(
        array(
            'TR_PAGE_TITLE' => tr('i-MSCP - Client / Mail Account / Add external mail server'),
            'THEME_CHARSET' => tr('encoding'),
            'ISP_LOGO' => layout_getUserLogo(),
            'TR_TITLE_RELAY_MAIL_USERS' => tr('Add external mail server entry'),
            'TR_MX_TYPE' => tr('Type'),
            'TR_DOMAIN' => tr('Domain'),
            'DOMAIN_UTF8' => decode_idna($idnItemName),
            'DOMAIN' => $idnItemName,
            'TR_WILDCARD' => tr('Wildcard'),
            'WILDCARD' => "*.$idnItemName",
            'TR_PRIORITY' => tr('Priority'),
            'TR_HOST' => tr('External Mail Host'),
            'TR_SELECT_ENTRY_MESSAGE' => tr('Select this entry for deletion.'),
            'TR_SELECT_ALL_ENTRIES_MESSAGE' => tr('Select all entries for deletion.'),
            'TR_SELECT_ALL_ENTRIES_ALERT' => tr('Selecting all entries for deletion will cause deactivation of external mail server.'),
            'TR_ADD_NEW_ENTRY' => tr('Add new entry'),
            'TR_REMOVE_LAST_ENTRY' => tr('Remove last entry'),
            'TR_TRIGGER_REMOVE_ALERT' => tr('You cannot remove this entry.'),
            'TR_CANCEL' => tr('Cancel'),
            'TR_UPDATE' => tr('Update'),
            'TR_MX_TYPE_TOOLTIP' =>
            tr('Domain: Setup an MX record to relay mail of your entire domain (including subdomains) to an external mail server.') .
                htmlentities('<br /><br />') .
                tr('Wildcard: Setup an MX record for inexistent subdomains, for which an external mail server can handle mail.') .
                htmlentities('<br /><br />') .
                tr('Note: You can mix these options.'),
            'ITEM' => $verifiedData['item_id'] . ';' . $verifiedData['item_type']
        )
    );

    for ($index = 0; $index < $entriesCount; $index++) {
        // Generates html option elements for the name
        foreach (array($domainMx => $idnItemName, $wildcardMx => "*.$idnItemName") as $optionName => $optionValue) {
            $tpl->assign(
                array(
                    'INDEX' => $index,
                    'OPTION_VALUE' => $optionValue,
                    'SELECTED' => ($optionValue == $data['name'][$index]) ? $selectedOption : '',
                    'OPTION_NAME' => $optionName
                )
            );
            $tpl->parse('NAME_OPTIONS', '.name_options');
        }

        // Generates html option elements for the MX priority
        foreach (array('10', '15', '20', '25', '30') as $option) {
            $tpl->assign(
                array(
                    'INDEX' => $index,
                    'OPTION_VALUE' => $option,
                    'SELECTED' => ($option == $data['priority'][$index]) ? $selectedOption : '',
                    'OPTION_NAME' => $option
                )
            );
            $tpl->parse('PRIORITY_OPTIONS', '.priority_options');
        }

        $tpl->assign(
            array(
                'INDEX' => $index,
                'HOST' => $data['host'][$index],
                'ENTRY_ID' => (isset($data['to_update'][$index])) ? $data['to_update'][$index] : '',
            )
        );

        $tpl->parse('ITEM_ENTRIES', '.item_entries');
        $tpl->assign('NAME_OPTIONS', ''); // Reset name options stack for next record
        $tpl->assign('PRIORITY_OPTIONS', ''); // Reset priority options stack for next record
    }
}

/***********************************************************************************************************************
 * Main
 */
// Include core library
require_once 'imscp-lib.php';

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onClientScriptStart);
check_login('user');

if (customerHasFeature(array('mail', 'external_mail'))) {
    if (isset($_REQUEST['item']) && count($item = explode(';', $_REQUEST['item'], 2)) == 2) {
        $tpl = iMSCP_Registry::set('templateEngine', new iMSCP_pTemplate());
        $tpl->define_dynamic(
            array(
                'layout' => 'shared/layouts/ui.tpl',
                'page' => 'client/mail_external_edit.tpl',
                'page_message' => 'layout',
                'item_entries' => 'page',
                'name_options' => 'item_entries',
                'priority_options' => 'page',
            )
        );

        generateNavigation($tpl);
        client_editExternalMailServerEntries($item);
        generatePageMessage($tpl);
        $tpl->parse('LAYOUT_CONTENT', 'page');
        iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onClientScriptEnd, array('templateEngine' => $tpl));
        $tpl->prnt();
        unsetMessages();

    } else {
        set_page_message(tr('Wrong request.'), 'error');
        redirectTo('mail_external.php');
    }
} else {
    redirectTo('index.php');
}
