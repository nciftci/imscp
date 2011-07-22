<?php
/**
 * i-MSCP - internet Multi Server Control Panel
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
 *
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * Portions created by the i-MSCP Team are Copyright (C) 2010-2011 by
 * i-MSCP a internet Multi Server Control Panel. All Rights Reserved.
 */

/************************************************************************************
 * Main script
 */

// include core library
require 'imscp-lib.php';

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onResellerScriptStart);

check_login(__FILE__);

/** @var $cfg iMSCP_Config_Handler_File */
$cfg = iMSCP_Registry::get('config');

$userId = $_SESSION['user_id'];

// Checks if support ticket system is activated and if the reseller can access to it
if (!hasTicketSystem($userId)) {
	redirectTo('index.php');
}

if (isset($_GET['ticket_id'])) {
    $userId = $_SESSION['user_id'];
    $ticketId = (int) $_GET['ticket_id'];
	$status = getTicketStatus($ticketId);
	$ticketLevel = getUserLevel($ticketId);

	if (($ticketLevel == 1 && ($status == 1 || $status == 4)) ||
		($ticketLevel == 2 && ($status == 2))) {
		changeTicketStatus($ticketId, 3);
	}

    if (isset($_POST['uaction'])) {
        if ($_POST['uaction'] == 'close') {
            closeTicket($ticketId);
        } elseif(isset($_POST['user_message'])) {
            if(empty($_POST['user_message'])) {
                set_page_message(tr('Please type your message.'), 'error');
            } else {
                updateTicket($ticketId, $userId, $_POST['urgency'], $_POST['subject'],
                             $_POST['user_message'], 2, 3);
            }
        }

        redirectTo('ticket_system.php');
    }
} else {
    set_page_message(tr('Ticket not found.'), 'error');
    redirectTo('ticket_system.php');
}

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(array(
                          'page' => $cfg->RESELLER_TEMPLATE_PATH . '/ticket_view.tpl',
                         'logged_from' => 'page',
                          'page_message' => 'page',
                          'tickets_item' => 'page'));

$tpl->assign(array(
                  'THEME_CHARSET' => tr('encoding'),
                  'TR_TICKET_PAGE_TITLE' => tr('i-MSCP - Reseller / Support Ticket System / View Ticket'),
                  'THEME_COLOR_PATH' => "../themes/{$cfg->USER_INITIAL_THEME}",
                  'ISP_LOGO' => layout_getUserLogo(),
                  'TR_SUPPORT_SYSTEM' => tr('Support Ticket System'),
                  'TR_OPEN_TICKETS' => tr('Open tickets'),
                  'TR_CLOSED_TICKETS' => tr('Closed tickets'),
                  'TR_VIEW_SUPPORT_TICKET' => tr('View Support Ticket'),
                  'TR_TICKET_INFO' => tr('Ticket information'),
                  'TR_TICKET_URGENCY' => tr('Priority'),
                  'TR_TICKET_SUBJECT' => tr('Subject'),
                  'TR_TICKET_MESSAGES' => tr('Messages'),
                  'TR_TICKET_FROM' => tr('From'),
                  'TR_TICKET_DATE' => tr('Date'),
                  'TR_TICKET_CONTENT' => tr('Message'),
                  'TR_TICKET_NEW_REPLY' => tr('Send new reply'),
                  'TR_TICKET_REPLY' => tr('Send reply')));


gen_reseller_mainmenu($tpl, $cfg->RESELLER_TEMPLATE_PATH . '/main_menu_ticket_system.tpl');
gen_reseller_menu($tpl, $cfg->RESELLER_TEMPLATE_PATH . '/menu_ticket_system.tpl');
gen_logged_from($tpl);
showTicketContent($tpl, $ticketId, $userId);
generatePageMessage($tpl);

$tpl->parse('PAGE', 'page');

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onResellerScriptEnd,
                                              new iMSCP_Events_Response($tpl));

$tpl->prnt();
unsetMessages();
