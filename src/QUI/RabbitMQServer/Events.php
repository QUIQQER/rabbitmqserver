<?php

/**
 * This file contains \Namefruits\JuicerCreator\QuestionCreator
 */

namespace QUI\RabbitMQServer;

use QUI;

/**
 * Creator for Namefruits juicer question database
 *
 * @package namerobot/wizardQuestions
 * @author www.pcsg.de (Patrick Müller)
 */
class Events
{
    /**
     * On package setup
     *
     * - Create default settings
     *
     * @param QUI\Package\Package $Package
     */
    public static function onPackageSetup(QUI\Package\Package $Package)
    {
        if ($Package->getName() !== 'quiqqer/rabbitmqserver') {
            return;
        }

        // create var dir
        QUI::getPackage('quiqqer/rabbitmqserver')->getVarDir();
    }
}
