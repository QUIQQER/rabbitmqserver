<?php

namespace QUI\RabbitMQServer;

use QUI;
use QUI\Cache\Manager as QUICacheManager;

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
        $statistics     = [
            'wait'       => 0,
            'waitIds'    => [],
            'execute'    => 0,
            'executeIds' => []
        ];

        $cacheName = 'quiqqer/rabbitmqserver/job_checker_reported_ids';

        try {
            $reportedIds = json_decode(QUICacheManager::get($cacheName), true);
        } catch (\Exception $Exception) {
            $reportedIds = [
                'waitIds'    => [],
                'executeIds' => []
            ];
        }

        // Special check: all jobs that waited more than 3 hours
        $TimeWait = new \DateTime();
        $TimeWait = $TimeWait->modify('-3 hour');

        $result = $DB->fetch([
            'select' => 'id',
            'from'   => QUI::getDBTableName('queueserver_jobs'),
            'where'  => [
                'status'     => Server::JOB_STATUS_QUEUED,
                'createTime' => [
                    'type'  => '<',
                    'value' => $TimeWait->getTimestamp()
                ]
            ]
        ]);

        if (!empty($result)) {
            self::sendShutDownWarning(count($result));
        }

        // get all jobs that are too long in the queue
        $TimeWait = new \DateTime();
        $TimeWait = $TimeWait->modify('-'.$maxTimeWait.' second');

        $result = $DB->fetch([
            'select' => 'id',
            'from'   => QUI::getDBTableName('queueserver_jobs'),
            'where'  => [
                'status'     => Server::JOB_STATUS_QUEUED,
                'createTime' => [
                    'type'  => '<',
                    'value' => $TimeWait->getTimestamp()
                ]
            ]
        ]);

        $statistics['wait'] = count($result);

        foreach ($result as $row) {
            if (in_array($row['id'], $reportedIds['waitIds'])) {
                continue;
            }

            $statistics['waitIds'][]  = $row['id'];
            $reportedIds['waitIds'][] = $row['id'];
        }

        // get all jobs that are too long in the queue
        $TimeExec = new \DateTime();
        $TimeExec = $TimeExec->modify('-'.$maxTimeExecute.' second');

        $result = $DB->fetch([
            'select' => 'id',
            'from'   => QUI::getDBTableName('queueserver_jobs'),
            'where'  => [
                'status'         => Server::JOB_STATUS_RUNNING,
                'lastUpdateTime' => [
                    'type'  => '<',
                    'value' => $TimeExec->getTimestamp()
                ]
            ]
        ]);

        $statistics['execute'] = count($result);

        foreach ($result as $row) {
            if (in_array($row['id'], $reportedIds['executeIds'])) {
                continue;
            }

            $statistics['executeIds'][]  = $row['id'];
            $reportedIds['executeIds'][] = $row['id'];
        }

        if (!empty($statistics['waitIds']) || !empty($statistics['executeIds'])) {
            self::sendStatisticsMail($statistics);
        }

        try {
            QUICacheManager::set($cacheName, json_encode($reportedIds));
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * Send warning for possible rabbit consumer shutdown
     *
     * @param int $jobCount - Number of jobs that are queued for more than X hours
     * @return void
     */
    protected static function sendShutDownWarning($jobCount)
    {
        $adminMail = QUI::conf('mail', 'admin_mail');

        if (empty($adminMail)) {
            return;
        }

        $Mailer = new \QUI\Mail\Mailer();
        QUI::getLocale()->setCurrent(QUI::conf('globals', 'standardLanguage'));

        $Mailer->setBody(QUI::getLocale()->get(
            'quiqqer/rabbitmqserver',
            'jobchecker.mail.shutdown_warning',
            [
                'checkHours' => 3,
                'jobCount'   => $jobCount
            ]
        ));

        $Mailer->setSubject('quiqqqer/rabbitmqserver - Possible RabbitConsumer shutdown!');
        $Mailer->addRecipient($adminMail);

        try {
            $Mailer->send();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage());
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

        QUI::getLocale()->setCurrent(QUI::conf('globals', 'standardLanguage'));

        $Mailer->setBody(QUI::getLocale()->get(
            'quiqqer/rabbitmqserver',
            'jobchecker.mail.content',
            array_merge(
                $statistics,
                [
                    'maxTimeWait'    => (int)$settings['max_time_wait'],
                    'maxTimeExecute' => (int)$settings['max_time_execute']
                ]
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
     *
     * @throws QUI\Exception
     */
    public static function checkMemoryUsage($workerData)
    {
        $Conf              = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig();
        $memoryThreshold   = (int)$Conf->get('jobchecker', 'critical_memory_threshold');
        $peakUsageMegaByte = (memory_get_peak_usage(true) / 1024) / 1024;

        try {
            $maxPeak = (int)QUI\Cache\Manager::get('quiqqer/rabbitmqserver/memory_max_peak');
        } catch (\Exception $Exception) {
            $maxPeak = 0;
        }

        if ($peakUsageMegaByte > $memoryThreshold && $peakUsageMegaByte > ($maxPeak + 25)) {
            self::sendMemoryWarningMail($peakUsageMegaByte, $workerData);
            QUI\Cache\Manager::set('quiqqer/rabbitmqserver/memory_max_peak', $maxPeak);
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

        QUI::getLocale()->setCurrent(QUI::conf('globals', 'standardLanguage'));

        $Mailer->setBody(QUI::getLocale()->get(
            'quiqqer/rabbitmqserver',
            'jobchecker.mail.memory_warning.content',
            [
                'peakUsage'   => $peakUsage,
                'workerClass' => $workerData['jobWorker'],
                'workerData'  => json_encode($workerData['jobData'])
            ]
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
