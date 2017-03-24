<?php

define('QUIQQER_SYSTEM', true);
define('QUIQQER_CONSOLE', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/header.php';

use QUI\RabbitMQServer\Server;

// run as daemon (forever)
set_time_limit(0);

$Channel = Server::getChannel();

// execute job
$callback = function ($msg) {
    global $Channel;

    $Channel->basic_ack($msg->delivery_info['delivery_tag']);

    $job = json_decode($msg->body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: JSON error on job data json_decode ('
            . json_last_error_msg() . ' [code: ' . json_last_error() . ']. Abort process.'
        );

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

        return;
    }

    try {
        $jobId       = $job['jobId'];
        $jobData     = $job['jobData'];
        $workerClass = $job['jobWorker'];

        /** @var \QUI\QueueManager\QueueWorker $Worker */
        $Worker = new $workerClass($jobId, $jobData);
    } catch (\Exception $Exception) {
        QUI\System\Log::addError(
            'RabbitConsumer.php :: Error while initializing Worker class (' . $job['jobWorker'] . ') -> '
            . $Exception->getMessage() . '. Abort process.'
        );

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
        return;
    }

    if (isset($job['jobAttributes']['deleteOnFinish'])
        && $job['jobAttributes']['deleteOnFinish']
    ) {
        Server::deleteJob($jobId);
    }
};

/**
 * Shutdown process
 *
 * @return void
 */
function shutdown($sig)
{
    global $Channel;
    $Channel->close();

    exit;
}

declare(ticks = 1);

pcntl_signal(SIGINT, "shutdown");
pcntl_signal(SIGTERM, "shutdown");
pcntl_signal(SIGHUP, "shutdown");

//$Channel->queue_declare('quiqqer_queue', false, true, false, false);
//$Channel->queue_bind('quiqqer_queue', 'quiqqer_exchange');
$Channel->basic_qos(null, 1, false);
$Channel->basic_consume('quiqqer_queue', '', false, false, false, false, $callback);

while (count($Channel->callbacks)) {
    if (is_null($Channel->getConnection())) {
        $Channel = Server::getChannel();
    }
    $Channel->wait();
}
