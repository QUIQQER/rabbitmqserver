<?php

/**
 * This file contains \QUI\Translator\Console
 */

namespace QUI\RabbitMQServer;

use QUI;

/**
 * Creat translation via console
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class Console extends QUI\System\Console\Tool
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->setName('quiqqer:rabbitmqserver')
            ->setDescription('Start/Stop/Status for RabbitMQ Consumer.php');

        $this->addArgument(
            'action',
            'Action to execute: start, stop, status'
        );
    }

    /**
     * (non-PHPdoc)
     *
     * @see \QUI\System\Console\Tool::execute()
     */
    public function execute()
    {
        switch ($this->getArgument('action')) {
            case 'start':
                if ($this->startConsumer()) {
                    $this->writeLn(
                        'php process with RabbitConsumer.php successfully started (pid: '
                        . $this->getPid() . ').'
                    );
                } else {
                    $this->writeLn(
                        'RabbitConsumer.php could not be started.'
                    );
                }
                break;

            case 'stop':
                if ($this->stopConsumer()) {
                    $this->writeLn(
                        'Stopping successful.'
                    );
                } else {
                    $this->writeLn(
                        'Stopping not successful.'
                    );
                }
                break;

            case 'status':
                if ($this->isConsumerRunning()) {
                    $this->writeLn(
                        'RabbitConsumer.php process is running (pid: ' . $this->getPid() . ').'
                    );
                } else {
                    $this->writeLn(
                        'RabbitConsumer.php process is currently not running.'
                    );
                }
                break;

            default:
                $this->writeLn('Unknown action. Please only use start, stop or status');
        }

        $this->writeLn("\nFinished.\n");
    }

    /**
     * Starts RabbitConsumer.php process
     *
     * @return bool - success
     */
    protected function startConsumer()
    {
        if ($this->isConsumerRunning()) {
            $this->writeLn(
                'Cannot start RabbitConsumer.php. Process is already running (pid: '
                . $this->getPid() . ').'
            );

            return false;
        }

        $pipes = array();

//        $process = proc_open(
//            'php -d display_errors=stderr '
//            . dirname(dirname(dirname(__FILE__)))
//            . '/RabbitConsumer.php',
//            array(),
//            $pipes
//        );

        if (!is_resource($process) || !$process) {
            $this->writeLn(
                'Something went wrong while executing the RabbitConsumer.php process.'
            );

            return false;
        }

        $processInfo = proc_get_status($process);

        if (!$processInfo
            || !isset($processInfo['pid'])
            || empty($processInfo['pid'])
        ) {
            $this->writeLn(
                'Could not receive information on RabbitConsumer.php process.'
            );

            return false;
        }

        $this->writePidToPidFile($processInfo['pid']);

        return $this->isConsumerRunning();
    }

    /**
     * Stops RabbitConsumer.php process
     *
     * @return bool - success
     */
    protected function stopConsumer()
    {
        if (!$this->isConsumerRunning()) {
            $this->writeLn(
                'RabbitConsumer.php not running. No stopping necessary.'
            );

            return true;
        }

        $pid = $this->getPid();

        // sending kill signal
        $killed = posix_kill($pid, SIGKILL);

        if (!$killed) {
            $this->writeLn(
                'Something went wrong while sending the kill signal. :('
            );

            return false;
        }

        if ($this->isConsumerRunning()) {
            $this->writeLn(
                'Kill signal was sent, but process seems to be running regardless.'
                . ' Please check and/or kill manually (pid: ' . $pid . ')'
            );

            return false;
        }

        $pidFile = $this->getPidFilePath();

        if (file_exists($pidFile)) {
            unlink($pidFile);
        }

        return true;
    }

    /**
     * Checks if the RabbitConsumer.php process is currently running
     *
     * @return bool
     */
    protected function isConsumerRunning()
    {
        $pid = $this->getPid();

        if (!$pid) {
            return false;
        }

        $output       = array();
        $returnStatus = 0;

        exec('ps -p ' . $pid, $output, $returnStatus);

        return $returnStatus === 0;
    }

    /**
     * Write new RabbitConsumer.php pid to pid file
     *
     * @param integer $pid - RabbitConsumer.php process id
     * @return void
     */
    protected function writePidToPidFile($pid)
    {
        $pidFile = $this->getPidFilePath();
        file_put_contents($pidFile, $pid);
    }

    /**
     * Return pid of RabbitConsumer.php process or false, if no pid found
     *
     * @return false|int
     */
    protected function getPid()
    {
        $pidFile = $this->getPidFilePath();

        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = file_get_contents($pidFile);

        if (empty($pid)) {
            return false;
        }

        return (int)trim($pid);
    }

    /**
     * Get path of file where RabbitConsumer.php PID is saved
     *
     * @return string
     */
    protected function getPidFilePath()
    {
        $varDir = QUI::getPackage('quiqqer/rabbitmqserver')->getVarDir();
        return $varDir . 'RabbitConsumer.pid';
    }
}
