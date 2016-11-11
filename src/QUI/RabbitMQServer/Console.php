<?php

/**
 * This file contains \QUI\RabbitMQServer\Console
 */

namespace QUI\RabbitMQServer;

use QUI;

/**
 * Different console options for RabbitMQ module
 *
 * @author Patrick Müller (www.pcsg.de)
 */
class Console extends QUI\System\Console\Tool
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->setName('quiqqer:rabbitmqserver')
            ->setDescription('Operations for RabbitMQ QUIQQER module');

        $this->addArgument(
            'action',
            'Action to execute: connectiontest'
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
            case 'connectiontest':
                $this->connectionTest();
                break;

            default:
                $this->writeLn('Unknown action. Please only use: connectiontest');
        }

        $this->writeLn("\nFinished.\n");
    }

    protected function connectionTest()
    {
        $this->writeLn(
            "Testing connection to RabbitMQ server with current settings..."
        );

        try {
            Server::getChannel();
        } catch (\Exception $Exception) {
            $this->write(" [error] :: " . $Exception->getMessage());
            return;
        }

        $this->write(" [ok]");
    }
}
