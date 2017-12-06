<?php

define('QUIQQER_SYSTEM', true);
define('QUIQQER_CONSOLE', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/header.php';

use QUI\RabbitMQServer\Server;

// run as daemon (forever)
set_time_limit(0);

$Channel       = Server::getChannel();
$CurrentWorker = null;

$errorHandler = function () {
    global $CurrentWorker;

    $error = error_get_last();

    switch ($error['type']) {
        // FATAL ERROR
        case E_ERROR:
            break;

        default:
            return;
    }

    if (empty($CurrentWorker)) {
        return;
    }

    // re-queue Job on fatal error

    /** @var \QUI\QueueManager\QueueWorker $CurrentWorker */
    $CurrentWorker->cloneJob($CurrentWorker);
};

register_shutdown_function($errorHandler);

// execute job
$callback = function ($msg) {
    global $Channel;
    global $CurrentWorker;

    $Channel->basic_ack($msg->delivery_info['delivery_tag']);

    $job = json_decode($msg->body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: JSON error on job data json_decode ('
            . json_last_error_msg() . ' [code: ' . json_last_error() . ']. Abort process.'
        );

        $CurrentWorker = null;
        return;
    }

    if (!isset($job['jobId'])
        || !isset($job['jobData'])
        || !isset($job['jobWorker'])
        || !isset($job['jobAttributes'])
    ) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: job information array missing keys. Abort process.'
        );

        $CurrentWorker = null;
        return;
    }

    try {
        $jobId       = $job['jobId'];
        $jobData     = $job['jobData'];
        $workerClass = $job['jobWorker'];

        /** @var \QUI\QueueManager\QueueWorker $Worker */
        $Worker        = new $workerClass($jobId, $jobData);
        $CurrentWorker = $Worker;
    } catch (\Exception $Exception) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: Error while initializing Worker class (' . $job['jobWorker'] . ') -> '
            . $Exception->getMessage() . '. Abort process.'
        );

        $CurrentWorker = null;
        return;
    }

    try {
        Server::setJobStatus($jobId, Server::JOB_STATUS_RUNNING);
        Server::setJobResult($jobId, $Worker->execute());
        Server::setJobStatus($jobId, Server::JOB_STATUS_FINISHED);
    } catch (\Exception $Exception) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: Error while executing Worker (' . $job['jobWorker'] . ') -> '
            . $Exception->getMessage() . '. Abort process.'
        );

        Server::setJobStatus($jobId, Server::JOB_STATUS_ERROR);

        $CurrentWorker = null;
        return;
    }

    if (isset($job['jobAttributes']['deleteOnFinish'])
        && $job['jobAttributes']['deleteOnFinish']
    ) {
        Server::deleteJob($jobId);
    }

    $CurrentWorker = null;
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
            shutdown();
        }
    }
    $Channel->wait();
}
