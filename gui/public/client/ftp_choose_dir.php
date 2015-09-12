<?php
/**
 * i-MSCP - internet Multi Server Control Panel
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
 * The Original Code is "VHCS - Virtual Hosting Control System".
 *
 * The Initial Developer of the Original Code is moleSoftware GmbH.
 * Portions created by Initial Developer are Copyright (C) 2001-2006
 * by moleSoftware GmbH. All Rights Reserved.
 *
 * Portions created by the ispCP Team are Copyright (C) 2006-2010 by
 * isp Control Panel. All Rights Reserved.
 *
 * Portions created by the i-MSCP Team are Copyright (C) 2010-2015 by
 * i-MSCP - internet Multi Server Control Panel. All Rights Reserved.
 */

/***********************************************************************************************************************
 * Functions
 */

/**
 * Whether or not the given directory is allowed
 *
 * @param int $domainId Main customer domain unique identifier
 * @param string $directory Directory to check
 * @return bool TRUE if the given directory is allowed, FALSE otherwis
 */
function isAllowedDir($domainId, $directory)
{
	static $mountPoints = array();

	if (empty($mountPoints)) {
		$query = "
			SELECT
				`subdomain_mount` AS `mount_point`
			FROM
				`subdomain`
			WHERE
				`domain_id` = ?
			UNION
			SELECT
				`alias_mount` AS `mount_point`
			FROM
				`domain_aliasses`
			WHERE
				`domain_id` = ?
			UNION
			SELECT
				`subdomain_alias_mount` AS `mount_point`
			FROM
				`subdomain_alias`
			WHERE
				`alias_id` IN(SELECT `alias_id` FROM `domain_aliasses` WHERE `domain_id` = ?)
		";
		$stmt = exec_query($query, array($domainId, $domainId, $domainId));

		if ($stmt->rowCount()) {
			$mountPoints = $stmt->fetchAll(PDO::FETCH_COLUMN);
		}

		$mountPoints[] = '/';
	}

	foreach ($mountPoints as $mountPoint) {
		if (preg_match("%^$mountPoint/?(?:disabled|errors|phptmp|statistics|domain_disable_page)$%", "$directory")) {
			return false;
		}
	}

	return true;
}

/**
 * Generates directories list
 *
 * @param iMSCP_pTemplate $tpl Template engine instance
 * @return void
 */
function client_generateDirectoriesList($tpl)
{
	$path = isset($_GET['cur_dir']) ? clean_input($_GET['cur_dir']) : '';
	$domain = encode_idna($_SESSION['user_logged']);

	$vfs = new iMSCP_VirtualFileSystem($domain);
	$list = $vfs->ls($path);

	if (!$list) {
		set_page_message(tr('Unable to retrieve directories list for your domain. Please contact your reseller.'), 'error');
		$tpl->assign('FTP_CHOOSER', '');
		return;
	}

	$parent = explode('/', $path);
	array_pop($parent);
	$parent = implode('/', $parent);

	$tpl->assign(array(
		'ACTION_LINK' => '',
		'ACTION' => '',
		'ICON' => 'parent',
		'DIR_NAME' => tr('Parent directory'),
		'LINK' => tohtml("ftp_choose_dir.php?cur_dir=$parent", 'htmlAttr')
	));

	$tpl->parse('DIR_ITEM', '.dir_item');

	foreach ($list as $entry) {
		$directory = $path . '/' . $entry['file'];

		if (
			$entry['type'] != iMSCP_VirtualFileSystem::VFS_TYPE_DIR ||
			($entry['file'] == '.' || $entry['file'] == '..') ||
			!isAllowedDir(get_user_domain_id($_SESSION['user_id']), $directory)
		) {
			continue;
		}

		$tpl->assign(array(
			'DIR_NAME' => tohtml($entry['file']),
			'DIRECTORY' => tohtml($directory, 'htmlAttr'),
			'LINK' => tohtml('ftp_choose_dir.php?cur_dir=' . $directory, 'htmlAttr')
		));

		$tpl->parse('ACTION_LINK', 'action_link');
		$tpl->parse('DIR_ITEM', '.dir_item');
	}
}

/***********************************************************************************************************************
 * Main
 */

require_once 'imscp-lib.php';

iMSCP_Events_Aggregator::getInstance()->dispatch(iMSCP_Events::onClientScriptStart);

check_login('user');

if (!customerHasFeature('ftp') && !customerHasFeature('protected_areas')) {
	showBadRequestErrorPage();
}

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(array(
	'partial' => 'client/ftp_choose_dir.tpl',
	'page_message' => 'partial',
	'ftp_chooser' => 'partial',
	'dir_item' => 'ftp_chooser',
	'list_item' => 'dir_item',
	'action_link' => 'list_item'
));

$tpl->assign(array(
	'TR_DIRECTORY_TREE' => tr('Directory tree'),
	'TR_DIRECTORIES' => tr('Directories'),
	'CHOOSE' => tr('Choose')
));

client_generateDirectoriesList($tpl);
generatePageMessage($tpl);

$tpl->parse('PARTIAL', 'partial');
iMSCP_Events_Aggregator::getInstance()->dispatch(iMSCP_Events::onClientScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
