<?php

namespace QUI\RabbitMQServer;

use QUI;

/**
 * Class JobChecker
 *
 * Checks if Jobs are being executed correctly
 */
class JobChecker
{
    /**
     * Max detected memory usage for this runtime
     *
     * @var int
     */
    protected static $maxPeak = 0;

    /**
     * Checks Queue Jobs that are too long in queue or execution
     *
     * @return void
     */
    public static function checkJobs()
    {
        $DB             = QUI::getDataBase();
        $settings       = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig()->getSection('jobchecker');
        $maxTimeWait    = (int)$settings['max_time_wait'];
        $maxTimeExecute = (int)$settings['max_time_execute'];
        $statistics     = array(
            'wait'       => 0,
            'waitIds'    => array(),
            'execute'    => 0,
            'executeIds' => array()
        );

        // get all jobs that are too long in the queue
        $TimeWait = new \DateTime();
        $TimeWait = $TimeWait->modify('-' . $maxTimeWait . ' second');

        $result = $DB->fetch(array(
            'select' => 'id',
            'from'   => QUI::getDBTableName('queueserver_jobs'),
            'where'  => array(
                'status'     => Server::JOB_STATUS_QUEUED,
                'createTime' => array(
                    'type'  => '<',
                    'value' => $TimeWait->getTimestamp()
                )
            )
        ));

        $statistics['wait'] = count($result);

        foreach ($result as $row) {
            $statistics['waitIds'][] = $row['id'];
        }

        // get all jobs that are too long in the queue
        $TimeExec = new \DateTime();
        $TimeExec = $TimeExec->modify('-' . $maxTimeExecute . ' second');

        $result = $DB->fetch(array(
            'select' => 'id',
            'from'   => QUI::getDBTableName('queueserver_jobs'),
            'where'  => array(
                'status'         => Server::JOB_STATUS_RUNNING,
                'lastUpdateTime' => array(
                    'type'  => '<',
                    'value' => $TimeExec->getTimestamp()
                )
            )
        ));

        $statistics['execute'] = count($result);

        foreach ($result as $row) {
            $statistics['executeIds'][] = $row['id'];
        }

        if (!empty($statistics['wait']) || !empty($statistics['execute'])) {
            self::sendStatisticsMail($statistics);
        }
    }

    /**
     * Send JobChecker statistics via mail
     *
     * @param array $statistics
     * @return void
     */
    protected static function sendStatisticsMail($statistics)
    {
        $adminMail = QUI::conf('mail', 'admin_mail');

        if (empty($adminMail)) {
            return;
        }

        $Mailer   = new \QUI\Mail\Mailer();
        $settings = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig()->getSection('jobchecker');

        $statistics['waitIds']    = implode(', ', $statistics['waitIds']);
        $statistics['executeIds'] = implode(', ', $statistics['executeIds']);

        $Mailer->setBody(QUI::getLocale()->get(
            'quiqqer/rabbitmqserver',
            'jobchecker.mail.content',
            array_merge(
                $statistics,
                array(
                    'maxTimeWait'    => (int)$settings['max_time_wait'],
                    'maxTimeExecute' => (int)$settings['max_time_execute']
                )
            )
        ));

        $Mailer->setSubject('quiqqqer/rabbitmqserver - JobChecker');
        $Mailer->addRecipient($adminMail);

        try {
            $Mailer->send();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }
    }

    /**
     * Checks current PHP memory usage
     *
     * @param array $workerData - Class of the last Worker that was executed
     * @return void
     */
    public static function checkMemoryUsage($workerData)
    {
        $Conf              = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig();
        $memoryThreshold   = (int)$Conf->get('jobchecker', 'critical_memory_threshold');
        $peakUsageMegaByte = (memory_get_peak_usage(true) / 1024) / 1024;

        if ($peakUsageMegaByte > $memoryThreshold && $peakUsageMegaByte > self::$maxPeak) {
            self::sendMemoryWarningMail($peakUsageMegaByte, $workerData);
            self::$maxPeak = $peakUsageMegaByte;
        }
    }

    /**
     * Send memory usage warning mail
     *
     * @param int $peakUsage - Peak memory usage (MB)
     * @param array $workerData - Class of the last Worker that was executed
     * @return void
     */
    protected static function sendMemoryWarningMail($peakUsage, $workerData)
    {
        $adminMail = QUI::conf('mail', 'admin_mail');

        if (empty($adminMail)) {
            return;
        }

        $Mailer = new \QUI\Mail\Mailer();

        $Mailer->setBody(QUI::getLocale()->get(
            'quiqqer/rabbitmqserver',
            'jobchecker.mail.memory_warning.content',
            array(
                'peakUsage'   => $peakUsage,
                'workerClass' => $workerData['jobWorker'],
                'workerData'  => json_encode($workerData['jobData'])
            )
        ));

        $Mailer->setSubject('quiqqqer/rabbitmqserver - Memory usage Warnung');
        $Mailer->addRecipient($adminMail);

        try {
            $Mailer->send();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
        }
    }
}
