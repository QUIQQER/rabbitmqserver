<?php

define('QUIQQER_SYSTEM', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/header.php';

// run as daemon (forever)
set_time_limit(0);

/**
 * Saves pids of RabbitConsumer.php processes and corresponding proc_open resource objects
 */
$consumerProcesses = array();

/**
 * Get specific consumer setting
 *
 * @param $key
 * @return array|string
 */
function getConsumerSetting($key)
{
    $Config = QUI::getPackage('quiqqer/rabbitmqserver')->getConfig();
    return $Config->get('consumer', $key);
}

/**
 * Starts a single RabbitConsumer.php process
 *
 * @return void
 */
function startNewRabbitConsumer()
{
    global $consumerProcesses;

    $process = proc_open(
        'php -d display_errors=stderr ' . dirname(__FILE__) . '/RabbitConsumer.php',
        array(),
        $pipes
    );

    if (!is_resource($process) || !$process) {
        QUI\System\Log::addError(
            'Could not start RabbitConsumer.php :('
        );

        return;
    }

    $processInfo = proc_get_status($process);

    if (!$processInfo
        || !isset($processInfo['pid'])
        || empty($processInfo['pid'])
    ) {
        QUI\System\Log::addError(
            'Could not receive information on RabbitConsumer.php process :('
        );

        proc_close($process);

        return;
    }

    $pid                     = (int)$processInfo['pid'];
    $consumerProcesses[$pid] = $process;

    echo "\nStarted new RabbitConsumer.php process (pid: " . $pid . ')';
}

/**
 * Start all RabbitConsumer.php processes according to the settings
 *
 * @return void
 */
function startRabbitConsumers()
{
    $consumerCount = (int)getConsumerSetting('consumer_count');

    if ($consumerCount < 1) {
        $consumerCount = 1;
    }

    for ($i = 0; $i < $consumerCount; $i++) {
        startNewRabbitConsumer();
    }
}

/**
 * Stops all RabbitConsumer.php processes and subsequent running workers
 *
 * @return void
 */
function stopRabbitConsumers()
{
    global $consumerProcesses;

    foreach ($consumerProcesses as $pid => $process) {
        $status = proc_get_status($process);

        if (!$status
            || !$status['running']
        ) {
            unset($consumerProcesses[$pid]);
            continue;
        }

        proc_close($process);

        echo "Stopped RabbitConsumer.php process (pid: " . $pid . ")";

        unset($consumerProcesses[$pid]);
    }

    exit;
}

function getServerSetting($key)
{
    return QUI::getPackage('quiqqer/rabbitmqserver')->getConfig()->get('server', $key);
}

declare(ticks = 1);

pcntl_signal(SIGINT, "stopRabbitConsumers");
pcntl_signal(SIGTERM, "stopRabbitConsumers");
pcntl_signal(SIGHUP, "stopRabbitConsumers");

startRabbitConsumers();

while (!empty($consumerProcesses)) {
    sleep(10);

    foreach ($consumerProcesses as $pid => $process) {
        $status = proc_get_status($process);

        if ($status
            && $status['running']
        ) {
            continue;
        }

        echo 'RabbitConsumer.php process (pid: ' . $pid . ') seems to have exited on its own. Removing from list.'
            . ' Starting replacement process.';

        unset($consumerProcesses[$pid]);

        startNewRabbitConsumer();
    }
}