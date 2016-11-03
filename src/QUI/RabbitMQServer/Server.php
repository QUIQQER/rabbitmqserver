<?php

namespace QUI\RabbitMQServer;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use QUI;
use QUI\QueueManager\Interfaces\IQueueServer;
use QUI\QueueManager\Exceptions\ServerException;
use QUI\QueueManager\QueueJob;

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
     * RabbitMQ queue channel
     *
     * @var AMQPChannel
     */
    protected static $Channel = null;

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
        $Channel = self::getChannel();

        $workerData = array(
            'jobData'   => json_encode($QueueJob->getData()),
            'jobWorker' => $QueueJob->getWorkerClass(),
        );

        $Channel->basic_publish(
            new AMQPMessage(json_encode($workerData))
        );

        // @todo Priorität, deleteOnFinish ?
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
     * Execute the next job in the queue
     *
     * @throws ServerException
     */
    public static function executeNextJob()
    {
        $job = self::fetchNextJob();

        if (!$job) {
            return;
        }

        $jobWorkerClass = $job['jobWorker'];

        if (!class_exists($jobWorkerClass)) {
            throw new ServerException(array(
                'quiqqer/queueserver',
                'exception.queueserver.job.worker.not.found',
                array(
                    'jobWorkerClass' => $jobWorkerClass
                )
            ), 404);
        }

        $jobId = $job['id'];

        /** @var QUI\QueueManager\Interfaces\IQueueWorker $Worker */
        $Worker = new $jobWorkerClass($jobId, json_decode($job['jobData'], true));

        self::setJobStatus($jobId, IQueueServer::JOB_STATUS_RUNNING);

        try {
            $jobResult = $Worker->execute();
        } catch (\Exception $Exception) {
            self::writeJobLogEntry($jobId, $Exception->getMessage());
            self::setJobStatus($jobId, IQueueServer::JOB_STATUS_ERROR);
            return;
        }

        if ($job['deleteOnFinish']) {
            self::deleteJob($jobId);
            return;
        }

        if (self::setJobResult($jobId, $jobResult)) {
            self::setJobStatus($jobId, self::JOB_STATUS_FINISHED);
        } else {
            self::setJobStatus($jobId, self::JOB_STATUS_ERROR);
        }
    }

    /**
     * Checks if there are still jobs in the queue that are not finished
     *
     * @return bool
     */
    public static function hasNextJob()
    {
        $result = QUI::getDataBase()->fetch(array(
            'count' => 1,
            'from'  => 'queueserver_jobs',
            'where' => array(
                'status' => self::JOB_STATUS_QUEUED
            )
        ));

        $count = (int)current(current($result));

        return $count > 0;
    }

    /**
     * Fetch job data for next job in the queue (with highest priority)
     *
     * @return array|false
     */
    protected static function fetchNextJob()
    {
        $result = QUI::getDataBase()->fetch(array(
            'select' => array(
                'id',
                'jobData',
                'jobWorker',
                'deleteOnFinish'
            ),
            'from'   => 'queueserver_jobs',
            'where'  => array(
                'status' => self::JOB_STATUS_QUEUED
            ),
            'limit'  => 1,
            'order'  => array(
                'id'       => 'ASC',
                'priority' => 'DESC'
            )
        ));

        if (empty($result)) {
            return false;
        }

        return current($result);
    }

    /**
     * Get list of jobs
     *
     * @return array
     */
    public static function getJobList($searchParams)
    {
        $Grid       = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);

        $sortOn = 'id';
        $sortBy = 'ASC';

        if (isset($searchParams['sortOn'])
            && !empty($searchParams['sortOn'])
        ) {
            $sortOn = $searchParams['sortOn'];
        }

        if (isset($searchParams['sortBy'])
            && !empty($searchParams['sortBy'])
        ) {
            $sortBy = $searchParams['sortBy'];
        }

        $result = QUI::getDataBase()->fetch(array(
            'select' => array(
                'id',
                'status',
                'jobWorker',
                'priority'
            ),
            'from'   => 'queueserver_jobs',
            'limit'  => $gridParams['limit'],
            'order'  => $sortOn . ' ' . $sortBy
        ));

        $resultCount = QUI::getDataBase()->fetch(array(
            'count' => 1,
            'from'  => 'queueserver_jobs'
        ));

        return $Grid->parseResult(
            $result,
            current(current($resultCount))
        );
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
    protected static function getChannel()
    {
        if (!is_null(self::$Channel)) {
            return self::$Channel;
        }

        // @todo Einstellungen in Settings auslagern
        $Connection = new AMQPStreamConnection(
            'localhost',
            5672,
            'guest',
            'guest'
        );

        self::$Channel = $Connection->channel();
        self::$Channel->queue_declare('quiqqer', false, false, false, false);

        return self::$Channel;
    }
}