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

namespace iMSCP\Core\Exception;

use Zend\EventManager\EventManager;

/**
 * Class ExceptionHandler
 * @package iMSCP\Core\Exception
 */
class ExceptionHandler
{
    /** @var EventManager */
    protected $em;

    /**
     * @var array Exception writers class names
     */
    protected $writers = [
        'iMSCP_Exception_Writer_Browser',
        'iMSCP_Exception_Writer_Mail'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->em = new EventManager();
        $this->setExceptionHandler();
    }

    /**
     * Sets exception handler
     *
     * @see exceptionHandler()
     * @return void
     */
    public function setExceptionHandler()
    {
        set_exception_handler([$this, 'handleException']);
    }

    /**
     * Add exception writer
     *
     * @param string $className Exception writer class name
     * @return void
     */
    public function addWriter($className)
    {
        $className = (string)$className;

        if (!in_array($className, $this->writers)) {
            $this->writers[] = $className;
        }
    }

    /**
     * Remove exception writer
     *
     * @param string $className Exception writer class name
     * @return void
     */
    public function removeWriter($className)
    {
        $classname = (string)$className;
        unset($this->writers[$classname]);
    }

    /**
     * Unset exception handler
     *
     * @return void
     */
    public function unsetExceptionHandler()
    {
        restore_exception_handler();
    }

    /**
     * Handle uncaught exceptions
     *
     * @param \Exception $exception Uncaught exception
     * @return void
     */
    public function handleException(\Exception $exception)
    {
        try {
            foreach ($this->writers as $writer) {
                $this->em->attach('onUncaughtException', new $writer());
            }

            $this->em->trigger(new ExceptionEvent($exception));
        } catch (\Exception $e) {
            die(sprintf(
                'Unable to handle uncaught exception thrown in file %s at line %s with message: %s',
                $e->getFile(),
                $e->getLine(),
                $e->getMessage()
            ));
        }
    }
}