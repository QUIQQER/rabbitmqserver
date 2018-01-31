<?php

define('QUIQQER_SYSTEM', true);
define('QUIQQER_CONSOLE', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/header.php';

use QUI\RabbitMQServer\Server;

// run as daemon (forever)
set_time_limit(0);

try {
    $Channel = Server::getChannel();
} catch (\Exception $Exception) {
    QUI\System\Log::writeException($Exception);
    shutdown();
}

$CurrentWorker   = null;
$currentPriority = 1;
$currentJobId    = 0;
$minPriority     = 1;

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

        exit;
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
        return;
    }

    sleep(1);
    $CurrentWorker->cloneJob($currentPriority);

    QUI\System\Log::addInfo(
        'Re-queueing Job #' . $currentJobId . ' ("' . $CurrentWorker::getClass() . '")'
    );
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

    /** @var \QUI\QueueManager\QueueWorker $CurrentWorker */
    if (empty($CurrentWorker)) {
        return;
    }

    $error = error_get_last();

    requeue();

    QUI\System\Log::addDebug(
        'Cloning Job "' . $CurrentWorker::getClass() . '" because of error -> ' . $error['type'] . ": " . $error['message']
    );
};

register_shutdown_function($errorHandler);

// execute job
$callback = function ($msg) {
    global $Channel;
    global $CurrentWorker;
    global $minPriority;
    global $currentPriority;
    global $currentJobId;

    $job = json_decode($msg->body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: JSON error on job data json_decode ('
            . json_last_error_msg() . ' [code: ' . json_last_error() . ']. Abort process.'
        );

        $CurrentWorker = null;
        $Channel->basic_ack($msg->delivery_info['delivery_tag']);
        return;
    }

    $priority = 1;

    if (!empty($job['priority'])) {
        $priority = (int)$job['priority'];
    }

    if ($priority < $minPriority) {
        $Channel->basic_reject($msg->delivery_info['delivery_tag'], true);
        return;
    } else {
        $Channel->basic_ack($msg->delivery_info['delivery_tag']);
    }

    $currentPriority = $priority;

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

        $CurrentWorker = null;
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

        $CurrentWorker = null;
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

        $CurrentWorker = null;
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

    $CurrentWorker = null;

    // Check memory usage
    memoryCheck();
};

/**
 * Shutdown process
 *
 * @return void
 */
function shutdown()
{
    global $Channel;
    $Channel->close();

    exit;
}

declare(ticks=1);

pcntl_signal(SIGINT, "shutdown");
pcntl_signal(SIGTERM, "shutdown");
pcntl_signal(SIGHUP, "shutdown");

//$Channel->queue_declare('quiqqer_queue', false, true, false, false);
//$Channel->queue_bind('quiqqer_queue', 'quiqqer_exchange');
$Channel->basic_qos(null, 1, false);
$Channel->basic_consume(Server::getUniqueQueueName(), '', false, false, false, false, $callback);

while (count($Channel->callbacks)) {
    if (is_null($Channel->getConnection())) {
        try {
            $Channel = Server::getChannel();
            $Channel->basic_qos(null, 1, false);
            $Channel->basic_consume(Server::getUniqueQueueName(), '', false, false, false, false, $callback);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            shutdown();
        }
    }

    $Channel->wait();
}
