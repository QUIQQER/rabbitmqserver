<?php

namespace QUI\RabbitMQServer;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use QUI;
use QUI\QueueManager\Interfaces\IQueueServer;
use QUI\QueueManager\QueueJob;
use QUI\QueueServer\Server as QUIQServer;
use QUI\Utils\System\File as FileUtils;

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
                [
                    'jobData'        => json_encode($QueueJob->getData()),
                    'jobWorker'      => $QueueJob->getWorkerClass(),
                    'status'         => self::JOB_STATUS_QUEUED,
                    'priority'       => $QueueJob->getAttribute('priority') ?: 1,
                    'deleteOnFinish' => $QueueJob->getAttribute('deleteOnFinish') ? 1 : 0,
                    'createTime'     => time(),
                    'lastUpdateTime' => time()
                ]
            );

            $Channel = self::getChannel();
            $jobId   = QUI::getDataBase()->getPDO()->lastInsertId();

            $workerData = [
                'jobId'         => $jobId,
                'jobData'       => $QueueJob->getData(),
                'jobAttributes' => $QueueJob->getAttributes(),
                'jobWorker'     => $QueueJob->getWorkerClass(),
                'priority'      => $QueueJob->getAttribute('priority') ?: 1
            ];

            $priority = (int)$QueueJob->getAttribute('priority');

            // max. priority limit for rabbit mq queues
            // see: https://www.rabbitmq.com/priority.html [14.11.2016]
            if ($priority > 255) {
                $priority = 255;
            }

            $Channel->basic_publish(
                new AMQPMessage(
                    json_encode($workerData),
                    [
                        'priority'      => $priority,
                        'delivery_mode' => 2 // make message durable
                    ]
                ),
                '',
                self::getUniqueQueueName()
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                self::class.' -> queueJob() :: '.$Exception->getMessage()
            );

            throw new QUI\Exception([
                'quiqqer/rabbitmqserver',
                'exception.rabbitmqserver.job.queue.error'
            ]);
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
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.queued',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;

            case self::JOB_STATUS_RUNNING:
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.running',
                    [
                        'jobId' => $jobId
                    ]
                ]);
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
        $jobStatus = self::getJobStatus($jobId);

        switch ($jobStatus) {
            case self::JOB_STATUS_FINISHED:
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.finished',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;

            case self::JOB_STATUS_ERROR:
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.error',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;
        }

        if (empty($result)) {
            $result = [];
        }

        $result = json_encode($result);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::writeJobLogEntry(
                $jobId,
                QUI::getLocale()->get(
                    'quiqqer/queueserver',
                    'error.json.encode.job.result',
                    [
                        'error' => json_last_error_msg().' (JSON Error code: '.json_last_error().')'
                    ]
                )
            );

            return false;
        }

        try {
            QUI::getDataBase()->update(
                'queueserver_jobs',
                [
                    'resultData' => $result
                ],
                [
                    'id' => $jobId
                ]
            );
        } catch (\Exception $Exception) {
            // @todo Fehlermeldung und Log
            return false;
        }

        return true;
    }

    /**
     * Get Database entry for a job
     *
     * @param $jobId
     * @return array
     *
     * @throws QUI\Exception
     */
    protected static function getJobData($jobId)
    {
        $result = QUI::getDataBase()->fetch([
            'from'  => 'queueserver_jobs',
            'where' => [
                'id' => $jobId
            ]
        ]);

        if (empty($result)) {
            throw new QUI\Exception([
                'quiqqer/queueserver',
                'exception.queueserver.job.not.found',
                [
                    'jobId' => $jobId
                ]
            ], 404);
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
                [
                    'status'         => $status,
                    'lastUpdateTime' => time()
                ],
                [
                    'id' => $jobId
                ]
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
        $jobLog[] = [
            'time' => date('Y.m.d H:i:s'),
            'msg'  => $msg
        ];

        QUI::getDataBase()->update(
            QUI\QueueServer\Server::getJobTable(),
            [
                'jobLog' => json_encode($jobLog)
            ],
            [
                'id' => $jobId
            ]
        );

        return true;
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
            return [];
        }

        return json_decode($jobLog, true);
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
        // currently not possible with RabbitMQ
        // https://discuss.pivotal.io/hc/en-us/community/posts/205630238-How-to-delete-messages-with-an-specific-routing-key-from-a-queue-
        return false;
    }

    /**
     * Clone a job and queue it immediately
     *
     * @param integer $jobId - Job ID
     * @param integer $priority - (new) job priority
     * @return integer
     *
     * @throws QUI\Exception
     */
    public static function cloneJob($jobId, $priority)
    {
        $jobData  = self::getJobData($jobId);
        $priority = (int)$priority;

        if ($priority < 1) {
            $priority = 1;
        }

        $CloneJob = new QueueJob(
            $jobData['jobWorker'],
            json_decode($jobData['jobData'], true),
            [
                'priority'       => $priority,
                'deleteOnFinish' => $jobData['deleteOnFinish']
            ]
        );

        $cloneJobId = $CloneJob->queue(false);

        // Set old job to "CLONED" status
        self::setJobStatus($jobId, self::JOB_STATUS_CLONED);

        return $cloneJobId;
    }

    /**
     * Check if a connection to a RabbitMQ server is currently established
     *
     * @return bool
     */
    public static function isConnected()
    {
        if (is_null(self::$Connection) || is_null(self::$Channel)) {
            return false;
        }

        return self::$Channel->getConnection()->isConnected();
    }

    /**
     * Get RabbitMQ queue channel
     *
     * @param bool $forceReconnect (optional) - Forece reconnection to RabbitMQ server
     * @return AMQPChannel
     *
     * @throws QUI\Exception
     */
    public static function getChannel($forceReconnect = false)
    {
        if (!$forceReconnect
            && !is_null(self::$Channel)
            && self::isConnected()) {
            return self::$Channel;
        }

        try {
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
            self::$Channel->queue_declare(
                self::getUniqueQueueName(),
                false,
                true,
                false,
                false,
                false,
                [
                    'x-max-priority' => ['I', 255]
                ]
            );
//        self::$Channel->queue_bind('quiqqer_queue', 'quiqqer_exchange');
//        self::$Channel->queue_declare('quiqqer', false, true, false, false);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'Something went wrong while trying to establish a connection to the'
                .' RabbitMQ Server :: '.$Exception->getMessage()
            );

            throw new QUI\Exception([
                'quiqqer/rabbitmqserver',
                'exception.server.connection.error',
                [
                    'host' => self::getServerSetting('host'),
                    'port' => self::getServerSetting('port')
                ]
            ]);
        }

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
     * Close server connection
     */
    public static function closeConnection()
    {
        if (!is_null(self::$Channel)) {
            self::$Channel->close();
            self::$Channel = null;
        }

        if (!is_null(self::$Connection)) {
            self::$Connection->close();
            self::$Connection = null;
        }
    }

    /**
     * Get unique queue name for this quiqqer system
     *
     * @return string
     */
    public static function getUniqueQueueName()
    {
        $varDir        = QUI::getPackage('quiqqer/rabbitmqserver')->getVarDir();
        $queueNameFile = $varDir.'queuename';
        $queueName     = FileUtils::getFileContent($queueNameFile);

        if (!empty($queueName)) {
            return $queueName;
        }

        // if queue name has not been generated yet -> generate
        $queueName = uniqid('quiqqer_queue_', true);
        FileUtils::mkfile($queueNameFile);
        file_put_contents($queueNameFile, $queueName);

        return $queueName;
    }
}
