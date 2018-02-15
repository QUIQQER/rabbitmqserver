<?php

define('QUIQQER_SYSTEM', true);
require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/header.php';

use QUI\RabbitMQServer\Server;

if (!function_exists('pcntl_signal')) {
    echo 'Could not find function "pcntl_signal". Is PHP pcntl extension installed and activated?';
    exit(-1);
}

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
 * @param bool $highPriority (optional) - Start a high priority Consumer [default: false]
 * @return void
 */
function startNewRabbitConsumer($highPriority = false)
{
    global $consumerProcesses;

    $consumerCmd = 'php ' . dirname(__FILE__) . '/RabbitConsumer.php';

    if ($highPriority) {
        $highPriorityThreshold = (int)getConsumerSetting('high_priority_threshold');
        $consumerCmd .= ' ' . $highPriorityThreshold;
    }

    $process = proc_open(
        $consumerCmd,
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
    $consumerProcesses[$pid] = array(
        'process'      => $process,
        'highPriority' => $highPriority
    );

    echo "\nStarted new RabbitConsumer.php process (pid: " . $pid . ')';
}

/**
 * Check if the connection to the RabbitMQ server is still alive
 *
 * @return bool
 */
function checkServerConnection()
{
    try {
        Server::getChannel(true);
    } catch (\Exception $Exception) {
        return false;
    }

    return true;
}

/**
 * Start all RabbitConsumer.php processes according to the settings
 *
 * @return void
 */
function startRabbitConsumers()
{
    $consumerCount             = (int)getConsumerSetting('consumer_count');
    $highPriorityConsumerCount = (int)getConsumerSetting('high_priority_consumer_count');

    if ($consumerCount < 1) {
        $consumerCount = 1;
    }

    if ($highPriorityConsumerCount < 1) {
        $highPriorityConsumerCount = 0;
    } elseif ($highPriorityConsumerCount > $consumerCount) {
        $highPriorityConsumerCount = $consumerCount;
    }

    $consumerCount -= $highPriorityConsumerCount;

    // start regular consumers
    for ($i = 0; $i < $consumerCount; $i++) {
        startNewRabbitConsumer();
    }

    // start high priority consumers
    for ($i = 0; $i < $highPriorityConsumerCount; $i++) {
        startNewRabbitConsumer(true);
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

    foreach ($consumerProcesses as $pid => $data) {
        $process = $data['process'];
        $status  = proc_get_status($process);

        if (!$status
            || !$status['running']
        ) {
            continue;
        }

        // get child php process (because proc_open starts processes via seperate 'sh ...' process)
        exec('pgrep -P ' . $pid, $children);

        foreach ($children as $childPid) {
            try {
                posix_kill($childPid, SIGINT);
            } catch (\Exception $Exception) {
                echo "An error occurred while trying to send INT signal to php process (pid: " . $childPid . ")."
                     . " Please kill manually.";
            }
        }

        proc_close($process);

        echo "Stopped RabbitConsumer.php process (pid: " . $pid . ")";
    }

    exit;
}

function getServerSetting($key)
{
    return QUI::getPackage('quiqqer/rabbitmqserver')->getConfig()->get('server', $key);
}

declare(ticks=1);

pcntl_signal(SIGINT, "stopRabbitConsumers");
pcntl_signal(SIGTERM, "stopRabbitConsumers");
pcntl_signal(SIGHUP, "stopRabbitConsumers");

startRabbitConsumers();

while (!empty($consumerProcesses)) {
    sleep(3);

    foreach ($consumerProcesses as $pid => $data) {
        $process = $data['process'];
        $status  = proc_get_status($process);

        if ($status
            && $status['running']
        ) {
            continue;
        }

        echo 'RabbitConsumer.php process (pid: ' . $pid . ') seems to have exited on its own. Removing from list.'
             . ' Starting replacement process.';

        unset($consumerProcesses[$pid]);

        while (true) {
            if (checkServerConnection()) {
                startNewRabbitConsumer($data['highPriority']);
                break;
            }

            // Wait for 5 seconds and then check server connection again
            sleep(5);
        }
    }
}
