<?php

define('QUIQQER_SYSTEM', true);
define('QUIQQER_CONSOLE', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/header.php';

use QUI\RabbitMQServer\Server;
use Namefruits\Juicer\Handler\Tables as JuicerTables;

// run as daemon (forever)
set_time_limit(0);

$Channel         = null;
$CurrentWorker   = null;
$currentPriority = 1;
$currentJobId    = 0;
$minPriority     = 1;
$exit            = false;
$isProcessing    = false;

if (!empty($argv[1])) {
    $minPriority = (int)$argv[1];
}

if ($minPriority < 1) {
    $minPriority = 1;
} elseif ($minPriority > 255) {
    $minPriority = 255;
}

// settings
$Conf            = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig();
$memoryThreshold = (int)$Conf->get('jobchecker', 'critical_memory_threshold');
$workerCount     = (int)$Conf->get('consumer', 'consumer_count');

/**
 * Check memory usage and exit if threshold is exceeded
 *
 * @return void
 */
function memoryCheck()
{
    global $memoryThreshold;
    global $workerCount;

    $currentMemoryUsage = (memory_get_usage() / 1024) / 1024;
    $approxTotalUsage   = $currentMemoryUsage * $workerCount;

    if ($approxTotalUsage > $memoryThreshold) {
        QUI\System\Log::addInfo(
            'RabbitConsumer (pid: ' . getmypid() . ') exiting because total memory usage has'
            . ' reached ' . $approxTotalUsage . 'MB'
        );

        shutdown();
    }
}

/**
 * Requeue current Job
 *
 * @return void
 */
function requeue()
{
    global $CurrentWorker;
    global $currentPriority;
    global $currentJobId;

    /** @var \QUI\QueueManager\QueueWorker $CurrentWorker */
    if (empty($CurrentWorker)) {
        callbackEnd();
        return;
    }

    sleep(1);
    $CurrentWorker->cloneJob($currentPriority);

    QUI\System\Log::addInfo(
        'Re-queueing Job #' . $currentJobId . ' ("' . $CurrentWorker::getClass() . '")'
    );

    callbackEnd();
}

/**
 * Checks if an exception is a Database Exception
 *
 * @param Exception $Exception
 * @return bool
 */
function isDBException(\Exception $Exception)
{
    if (mb_strpos($Exception->getMessage(), 'Error while sending QUERY packet') !== false) {
        return true;
    }

    return false;
}

$errorHandler = function () {
    global $CurrentWorker;

    $error = error_get_last();

    if (is_null($error)) {
        QUI\System\Log::addError(
            'RabbitConsumer shutdown'
        );
    } else {
        QUI\System\Log::addError(
            'RabbitConsumer shutdown :: ' . json_encode($error)
        );
    }

    /** @var \QUI\QueueManager\QueueWorker $CurrentWorker */
    if (empty($CurrentWorker)) {
        return;
    }

    requeue();

    QUI\System\Log::addDebug(
        'Cloning Job "' . $CurrentWorker::getClass() . '" because of error -> ' . $error['type'] . ": " . $error['message']
    );
};

/**
 * Check the QUIQQER database connection and rebuilt
 * it if necessary
 *
 * @return void
 */
function checkDBConnection()
{
    try {
        QUI::getDataBase()->fetch(array(
            'count' => 1,
            'from'  => JuicerTables::getProjectsTable()
        ));
    } catch (\Exception $Exception) {
        QUI\System\Log::addWarning(
            'MySQL connection seems to be lost, reconnecting. Database error that was thrown: '
            . $Exception->getMessage()
        );

        QUI::$DataBase2 = null;
    }
}

register_shutdown_function($errorHandler);

// execute job
function callbackEnd()
{
    global $Channel;
    global $CurrentWorker;
    global $exit;
    global $isProcessing;

    $CurrentWorker = null;
    $isProcessing  = false;

    if ($exit) {
        $Channel->close();
        exit;
    }
}

$callback = function ($msg) {
    global $Channel;
    global $CurrentWorker;
    global $minPriority;
    global $currentPriority;
    global $currentJobId;
    global $isProcessing;

    $isProcessing = true;
    $job          = json_decode($msg->body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: JSON error on job data json_decode ('
            . json_last_error_msg() . ' [code: ' . json_last_error() . ']. Abort process.'
        );

        $Channel->basic_ack($msg->delivery_info['delivery_tag']);
        callbackEnd();
        return;
    }

    // Check priority
    $priority = 1;

    if (!empty($job['priority'])) {
        $priority = (int)$job['priority'];
    }

    if ($priority < $minPriority) {
        $Channel->basic_reject($msg->delivery_info['delivery_tag'], true);
        callbackEnd();
        return;
    } else {
        $Channel->basic_ack($msg->delivery_info['delivery_tag']);
    }

    $currentPriority = $priority;

    // Decode and execute job
    checkDBConnection();

    if (!isset($job['jobId'])
        || !isset($job['jobData'])
        || !isset($job['jobWorker'])
        || !isset($job['jobAttributes'])
    ) {
        if (!empty($job['jobId'])) {
            Server::setJobStatus($job['jobId'], Server::JOB_STATUS_ERROR);
        }

        QUI\System\Log::addError(
            'RabbitConsumer.php :: job information array missing keys. Abort process.'
        );

        callbackEnd();
        return;
    }

    try {
        $jobId        = $job['jobId'];
        $currentJobId = $jobId;
        $jobData      = $job['jobData'];
        $workerClass  = $job['jobWorker'];

        /** @var \QUI\QueueManager\QueueWorker $Worker */
        $Worker        = new $workerClass($jobId, $jobData);
        $CurrentWorker = $Worker;
    } catch (\Exception $Exception) {
        if (isDBException($Exception)) {
            requeue();
            return;
        }

        QUI\System\Log::addError(
            'RabbitConsumer.php (Job #' . $jobId . ') :: Error while initializing Worker class (' . $job['jobWorker'] . ') -> '
            . $Exception->getMessage() . '. Abort process.'
        );

        QUI\System\Log::writeException($Exception);

        callbackEnd();
        return;
    }

    try {
        Server::setJobStatus($jobId, Server::JOB_STATUS_RUNNING);
        Server::setJobResult($jobId, $Worker->execute());

        unset($Worker);

        // start garbage collection manually to try to cleanup any memory leakage
        gc_collect_cycles();

        Server::setJobStatus($jobId, Server::JOB_STATUS_FINISHED);
    } catch (\Exception $Exception) {
        if (isDBException($Exception)) {
            requeue();
            return;
        }

        QUI\System\Log::addError(
            'RabbitConsumer.php (Job #' . $jobId . ') :: Error while executing Worker (' . $job['jobWorker'] . ') -> '
            . $Exception->getMessage() . '. Abort process.'
        );

        QUI\System\Log::writeException($Exception);

        Server::setJobStatus($jobId, Server::JOB_STATUS_ERROR);

        callbackEnd();
        return;
    }

    if (isset($job['jobAttributes']['deleteOnFinish'])
        && $job['jobAttributes']['deleteOnFinish']
    ) {
        try {
            Server::deleteJob($jobId);
        } catch (\Exception $Exception) {
            if (isDBException($Exception)) {
                requeue();
                return;
            }

            QUI\System\Log::addError(
                'RabbitConsumer.php (Job #' . $jobId . ') :: Error while deleteting Job -> '
                . $Exception->getMessage() . '. Abort process.'
            );

            QUI\System\Log::writeException($Exception);

            Server::setJobStatus($jobId, Server::JOB_STATUS_ERROR);
        }
    }

    // Check memory usage
    memoryCheck();

    // end callback
    callbackEnd();
};

/**
 * Shutdown process
 *
 * @return void
 */
function shutdown()
{
    global $Channel;
    global $exit;
    global $isProcessing;

    if (!$isProcessing) {
        if ($Channel) {
            $Channel->close();
        }

        exit;
    }

    $exit = true;
}

declare(ticks=1);

pcntl_signal(SIGINT, "shutdown");
pcntl_signal(SIGTERM, "shutdown");
pcntl_signal(SIGHUP, "shutdown");

/**
 * Get Channel to RabbitMQ server
 *
 * @return \PhpAmqpLib\Channel\AMQPChannel
 */
function getChannel()
{
    global $Channel;

    try {
        $Channel = Server::getChannel(true);
    } catch (\Exception $Exception) {
        QUI\System\Log::addError(
            'Exiting RabbitConsumer because there was a connection error to the RabbitMQ server'
            . ': ' . $Exception->getMessage()
        );

        exit;
    }

    return $Channel;
}

//$Channel->queue_declare('quiqqer_queue', false, true, false, false);
//$Channel->queue_bind('quiqqer_queue', 'quiqqer_exchange');
$Channel = getChannel();
$Channel->basic_qos(null, 1, false);
$Channel->basic_consume(Server::getUniqueQueueName(), '', false, false, false, false, $callback);

while (count($Channel->callbacks)) {
    try {
        if (is_null($Channel) || is_null($Channel->getConnection())) {
            throw new Exception('Channel or Connection is null, fetch new connection');
        }

        $Channel->wait();
    } catch (\Exception $Exception) {
        $Channel = getChannel();
        $Channel->basic_qos(null, 1, false);
        $Channel->basic_consume(Server::getUniqueQueueName(), '', false, false, false, false, $callback);
    }
};
