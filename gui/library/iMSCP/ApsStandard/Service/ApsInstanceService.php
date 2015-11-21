<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2015 by Laurent Declercq <l.declercq@nuxwin.com>
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
 */

namespace iMSCP\ApsStandard\Service;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use iMSCP\ApsStandard\Entity\ApsInstance;
use iMSCP\ApsStandard\Entity\ApsPackage;
use iMSCP\ApsStandard\Entity\ApsInstanceSetting;
use iMSCP\Entity\Admin;
use iMSCP_Registry as Registry;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Class ApsInstanceService
 * @package iMSCP\ApsStandard\Service
 */
class ApsInstanceService extends ApsAbstractService
{
	/**
	 * Get all application instances
	 *
	 * @return ApsInstance[]
	 */
	public function getInstances()
	{
		$instance = $this->getEntityManager()->getRepository('Aps:ApsInstance')->findBy(array(
			'owner' => $this->getAuth()->getIdentity()->admin_id
		));
		return $instance;
	}

	/**
	 * Create a new application instance
	 *
	 * @param ApsPackage $package Package which belongs to the newly created application instance
	 * @param ApsInstanceSetting[] $settings APS instance settings
	 * @return ConstraintViolationList
	 */
	public function createInstance($package, array $settings)
	{
		$this->getEventManager()->dispatch('onCreateApsInstance', array(
			'package' => $package, 'settings' => $settings, 'context' => $this
		));

		$instance = new ApsInstance();
		$instance
			->setPackage($package)
			->setOwner($this->getAdminEntity($this->getAuth()->getIdentity()->admin_id))
			->addSettings($settings)
			->setStatus('toadd');

		/** @var ConstraintViolationList $violations */
		$violations = $this->getValidator()->validate($instance);

		if (count($violations)) {
			return $violations;
		}

		if ($instance->hasSetting('__db_name__')) {
			$violations->addAll($this->validateDatabaseSettings(
				$instance->getSetting('__db_name__')->getValue(),
				$instance->getSetting('__db_user__')->getValue(),
				$instance->getSetting('__db_pwd__')->getValue(),
				$instance->hasSetting('__db_table_prefix__') ? $instance->getSetting('__db_table_prefix__')->getValue() : ''
			));
		}

		if (!count($violations)) {
			$entityManager = $this->getEntityManager();
			$entityManager->persist($instance);
			$entityManager->flush($instance);
			//send_request();
		}

		return $violations;
	}

	/**
	 * Reinstall the given application instance
	 *
	 * @throws \Exception
	 * @param int $id Instance identifier
	 * @return void
	 */
	public function reinstallInstance($id)
	{
		$this->getEventManager()->dispatch('onReinstallApsInstance', array('id' => $id, 'context' => $this));
		$entityManager = $this->getEntityManager();

		/** @var ApsInstance $instance */
		$instance = $entityManager->getRepository('Aps:Package')->findOneBy(array(
			'id' => $id, 'owner' => $this->getAuth()->getIdentity()->admin_id
		));

		if (!$instance) {
			throw new \Exception(tr('Application instance not found.'), 404);
		}

		$instance->setStatus('tochange');
		$entityManager->flush($instance);
		send_request();
	}

	/**
	 * Delete the given application instance
	 *
	 * @throws \Exception
	 * @param int $id Instance identifier
	 * @return void
	 */
	public function deleteInstance($id)
	{
		$this->getEventManager()->dispatch('onDeleteApsInstance', array('id' => $id, 'context' => $this));
		$entityManager = $this->getEntityManager();

		/** @var ApsInstance $instance */
		$instance = $entityManager->getRepository('Aps:Instance')->findOneBy(array(
			'id' => $id, 'owner' => $this->getAuth()->getIdentity()->admin_id
		));

		if (!$instance) {
			throw new \Exception(tr('Application instance not found.'), 404);
		}

		$instance->setStatus('todelete');
		$entityManager->flush($instance);
		send_request();
	}

	/**
	 * Get package service
	 *
	 * @return ApsPackageService
	 */
	protected function getPackageService()
	{
		return $this->getServiceLocator()->get('PackageService');
	}

	/**
	 * Get admin entity
	 *
	 * @param int $id Admin identifier
	 * @return Admin
	 */
	protected function getAdminEntity($id)
	{
		return $this->getEntityManager()->getRepository('Core:Admin')->find($id);
	}

	/**
	 * Validate the given database settings
	 *
	 * @throws DBALException
	 * @param string $dbName Database name
	 * @param string $dbUser Database user
	 * @param string $dbPwd Database user password
	 * @param string $dbTablePrefix Database table prefix if any
	 * @return ConstraintViolationList
	 */
	protected function validateDatabaseSettings($dbName, $dbUser, $dbPwd, $dbTablePrefix = '')
	{
		// Check that the given database exists and is owned by the user
		$domainId = get_user_domain_id($this->getAuth()->getIdentity()->admin_id);
		$stmt = $this->getEntityManager()->getConnection()->prepare(
			'SELECT sqld_name FROM sql_database WHERE domain_id = ? AND sqld_name = ?'
		);
		$stmt->execute(array($domainId, $dbName));

		$violationList = new ConstraintViolationList();

		if (!$stmt->rowCount()) {
			$violationList->add(new ConstraintViolation(tr('Database not found.'), '', '', array(), '', ''));
		} else {
			// Check that we can connect to the database using the given user/password
			try {
				$config = Registry::get('config');
				$connection = DriverManager::getConnection(array(
					'driver' => 'pdo_mysql',
					'dbname' => $dbName,
					'host' => $config['DATABASE_USER_HOST'],
					'port' => $config['DATABASE_PORT'],
					'user' => $dbUser,
					'password' => $dbPwd
				));

				// Check that the database can be used
				if (count($tableNames = $connection->getSchemaManager()->listTableNames())) {
					if ($dbTablePrefix === '') {
						$violationList->add(new ConstraintViolation(
							tr('The database is not empty. Be sure to start with a new database.'), '', '', array(), '', ''
						));
					} else {
						// We need to check all tables to be sure that the prefix is not already used
						foreach ($tableNames as $tableName) {
							if (strpos($tableName, $dbTablePrefix) === 0) {
								$violationList->add(new ConstraintViolation(
									tr('The table prefix is already used in the database. Please choose another prefix.'), '', '', array(), '', ''
								));
							}
						}
					}
				}
			} catch (DBALException $e) {
				$violationList->add(new ConstraintViolation(
					tr('Could not connect to the database using the given database user/password.'), '', '', array(), '', ''
				));
			}
		}

		return $violationList;
	}
}
