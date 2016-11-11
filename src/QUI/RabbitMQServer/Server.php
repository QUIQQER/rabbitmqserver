<?php

namespace QUI\RabbitMQServer;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use QUI;
use QUI\QueueManager\Interfaces\IQueueServer;
use QUI\QueueManager\QueueJob;
use QUI\QueueServer\Server as QUIQServer;

/**
 * Class QueueServer
 *
 * RabbitMQ queue server class based on quiqqer/queuemanager
 *
 * @package QUI\QueueServer
 */
class Server implements IQueueServer
{
    /**
     * RabbitMQ Connection object
     *
     * @var AMQPStreamConnection
     */
    protected static $Connection = null;

    /**
     * RabbitMQ queue channel
     *
     * @var AMQPChannel
     */
    protected static $Channel = null;

    /**
     * Config object of package
     *
     * @var QUI\Config
     */
    protected static $Config = null;

    /**
     * Adds a single job to the queue of a server
     *
     * @param QueueJob $QueueJob - The job to add to the queue
     * @return integer - unique Job ID
     *
     * @throws QUI\Exception
     */
    public static function queueJob(QueueJob $QueueJob)
    {
        try {
            QUI::getDataBase()->insert(
                QUIQServer::getJobTable(),
                array(
                    'jobData'        => json_encode($QueueJob->getData()),
                    'jobWorker'      => $QueueJob->getWorkerClass(),
                    'status'         => self::JOB_STATUS_QUEUED,
                    'priority'       => $QueueJob->getAttribute('priority') ?: 1,
                    'deleteOnFinish' => $QueueJob->getAttribute('deleteOnFinish') ? 1 : 0,
                    'createTime'     => time(),
                    'lastUpdateTime' => time()
                )
            );

            $Channel = self::getChannel();
            $jobId   = QUI::getDataBase()->getPDO()->lastInsertId();

            $workerData = array(
                'jobId'         => $jobId,
                'jobData'       => json_encode($QueueJob->getData()),
                'jobAttributes' => $QueueJob->getAttributes(),
                'jobWorker'     => $QueueJob->getWorkerClass()
            );

            $Channel->basic_publish(
                new AMQPMessage(
                    json_encode($workerData),
                    array(
                        'priority'      => $QueueJob->getAttribute('priority') ?: 1,
                        'delivery_mode' => 2 // make message durable
                    )
                ),
                '',
                'quiqqer_queue'
            );

            self::close();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                self::class . ' -> queueJob() :: ' . $Exception->getMessage()
            );

            throw new QUI\Exception(array(
                'quiqqer/rabbitmqserver',
                'exception.rabbitmqserver.job.queue.error'
            ));
        }

        self::setJobStatus($jobId, self::JOB_STATUS_QUEUED);

        return $jobId;
    }

    /**
     * Get result of a specific job
     *
     * @param integer $jobId
     * @param bool $deleteJob (optional) - delete job from queue after return [default: true]
     * @return array
     *
     * @throws QUI\Exception
     */
    public static function getJobResult($jobId, $deleteJob = true)
    {
        switch (self::getJobStatus($jobId)) {
            case self::JOB_STATUS_QUEUED:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.queued',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;

            case self::JOB_STATUS_RUNNING:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.running',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;
        }

        $jobData = self::getJobData($jobId);

        if ($deleteJob) {
            self::deleteJob($jobId);
        }

        return $jobData['resultData'];
    }

    /**
     * Set result of a specific job
     *
     * @param integer $jobId
     * @param array|string $result
     * @return bool - success
     *
     * @throws QUI\Exception
     */
    public static function setJobResult($jobId, $result)
    {
        if (empty($result)) {
            return true;
        }

        $jobStatus = self::getJobStatus($jobId);

        switch ($jobStatus) {
            case self::JOB_STATUS_FINISHED:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.finished',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;

            case self::JOB_STATUS_ERROR:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.error',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;
        }

        $result = json_encode($result);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::writeJobLogEntry(
                $jobId,
                QUI::getLocale()->get(
                    'quiqqer/queueserver',
                    'error.json.encode.job.result',
                    array(
                        'error' => json_last_error_msg() . ' (JSON Error code: ' . json_last_error() . ')'
                    )
                )
            );

            return false;
        }

        try {
            QUI::getDataBase()->update(
                'queueserver_jobs',
                array(
                    'resultData' => $result
                ),
                array(
                    'id' => $jobId
                )
            );
        } catch (\Exception $Exception) {
            // @todo Fehlermeldung und Log
            return false;
        }

        return true;
    }

    /**
     * Cancel a job
     *
     * @param integer - $jobId
     * @return bool - success
     *
     * @throws QUI\Exception
     */
    public static function deleteJob($jobId)
    {
        switch (self::getJobStatus($jobId)) {
            case self::JOB_STATUS_RUNNING:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.cancel.job.running',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;
        }

        QUI::getDataBase()->delete(
            'queueserver_jobs',
            array(
                'id' => $jobId
            )
        );
    }

    /**
     * Get Database entry for a job
     *
     * @param $jobId
     * @return array
     *
     * @throws QUI\Exception
     */
    public static function getJobData($jobId)
    {
        $result = QUI::getDataBase()->fetch(array(
            'from'  => 'queueserver_jobs',
            'where' => array(
                'id' => $jobId
            )
        ));

        if (empty($result)) {
            throw new QUI\Exception(array(
                'quiqqer/queueserver',
                'exception.queueserver.job.not.found',
                array(
                    'jobId' => $jobId
                )
            ), 404);
        }

        return current($result);
    }

    /**
     * Set status of a job
     *
     * @param integer $jobId
     * @param integer $status
     * @return bool - success
     */
    public static function setJobStatus($jobId, $status)
    {
        $jobData = self::getJobData($jobId);

        if ($jobData['status'] == $status) {
            return true;
        }

        try {
            QUI::getDataBase()->update(
                'queueserver_jobs',
                array(
                    'status'         => $status,
                    'lastUpdateTime' => time()
                ),
                array(
                    'id' => $jobId
                )
            );
        } catch (\Exception $Exception) {
            return false;
        }

        return true;
    }

    /**
     * Get status of a job
     *
     * @param integer $jobId
     * @return integer - Status ID
     */
    public static function getJobStatus($jobId)
    {
        $jobEntry = self::getJobData($jobId);
        return $jobEntry['status'];
    }

    /**
     * Write log entry for a job
     *
     * @param integer $jobId
     * @param string $msg
     * @return bool - success
     */
    public static function writeJobLogEntry($jobId, $msg)
    {
        $jobLog   = self::getJobLog($jobId);
        $jobLog[] = array(
            'time' => date('Y.m.d H:i:s'),
            'msg'  => $msg
        );

        QUI::getDataBase()->update(
            'queueserver_jobs',
            array(
                'jobLog' => json_encode($jobLog)
            ),
            array(
                'id' => $jobId
            )
        );
    }

    /**
     * Get event log for specific job
     *
     * @param integer $jobId
     * @return array
     */
    public static function getJobLog($jobId)
    {
        $jobData = self::getJobData($jobId);
        $jobLog  = $jobData['jobLog'];

        if (empty($jobLog)) {
            return array();
        }

        return json_decode($jobLog, true);
    }

    /**
     * Delete all completed or failed jobs that are older than $days days
     *
     * @param integer $days
     * @return bool - success
     */
    public static function cleanJobs($days)
    {
        $seconds = (int)$days * 24 * 60 * 60;
        $seconds = time() - $seconds;

        QUI::getDataBase()->delete(
            'queueserver_jobs',
            array(
                'lastUpdateTime' => array(
                    'type'  => '<=',
                    'value' => $seconds
                ),
                'status'         => array(
                    'type'  => 'IN',
                    'value' => array(self::JOB_STATUS_FINISHED, self::JOB_STATUS_ERROR)
                )
            )
        );
    }

    /**
     * Get RabbitMQ queue channel
     *
     * @return AMQPChannel
     */
    public static function getChannel()
    {
        if (!is_null(self::$Channel)) {
            return self::$Channel;
        }

        self::$Connection = new AMQPStreamConnection(
            self::getServerSetting('host'),
            self::getServerSetting('port'),
            self::getServerSetting('user'),
            self::getServerSetting('password'),
            self::getServerSetting('vhost') ?: '/',
            self::getServerSetting('insist') ? true : false,
            self::getServerSetting('login_method') ?: 'AMQPLAIN',
            null,
            self::getServerSetting('locale') ?: 'en_US',
            self::getServerSetting('connection_timeout') ?: 3.0,
            self::getServerSetting('read_write_timeout') ?: 3.0,
            null,
            self::getServerSetting('keepalive') ? true : false,
            self::getServerSetting('heartbeat') ?: 0
        );

        self::$Channel = self::$Connection->channel();

//        self::$Channel->exchange_declare('quiqqer_exchange', 'direct', false, true, false);
        self::$Channel->queue_declare('quiqqer_queue', false, true, false, false);
//        self::$Channel->queue_bind('quiqqer_queue', 'quiqqer_exchange');
//        self::$Channel->queue_declare('quiqqer', false, true, false, false);

        return self::$Channel;
    }

    /**
     * Get server setting
     *
     * @param string $key - config key
     * @return array|string
     */
    protected static function getServerSetting($key)
    {
        if (self::$Config) {
            return self::$Config->get('server', $key);
        }

        self::$Config = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig();

        return self::$Config->get('server', $key);
    }

    /**
     * Close connection to RabbitMQ server
     *
     * @return void
     */
    public static function close()
    {
        self::$Channel->close();
        self::$Connection->close();

        self::$Channel = null;
    }
}