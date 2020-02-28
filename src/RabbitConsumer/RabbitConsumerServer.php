<?php

use QUI\RabbitMQServer\Server;

if (!function_exists('pcntl_signal')) {
    echo 'Could not find function "pcntl_signal". Is PHP pcntl extension installed and activated?';
    exit(-1);
}

// run as daemon (forever)
set_time_limit(0);

// Load QUIQQER
define('QUIQQER_SYSTEM', true);

/**
 * Load QUIQQER system
 *
 * First check if database connection is available, then require QUIQQER
 *
 * @return void
 */
function loadQUIQQER()
{
    // Check if database is available
    $quiqqerCfg = \parse_ini_file(dirname(__FILE__, 6).'/etc/conf.ini.php', true);
    $db         = $quiqqerCfg['db'];

    $dsn = $db['driver'].':dbname='.$db['database'].';host='.$db['host'];

    try {
        new \PDO($dsn, $db['user'], $db['password']);
    } catch (\Exception $Exception) {
        echo "\nCould load QUIQQER because database connection failed. Retrying in 60 seconds. Error -> "
             .$Exception->getMessage();

        sleep(60);
        loadQUIQQER();
        return;
    }

    require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))).'/header.php';
}

loadQUIQQER();

/**
 * Saves pids of RabbitConsumer.php processes and corresponding proc_open resource objects
 */
$consumerProcesses = [];

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

    $consumerCmd = 'php '.dirname(__FILE__).'/RabbitConsumer.php';

    if ($highPriority) {
        $highPriorityThreshold = (int)getConsumerSetting('high_priority_threshold');
        $consumerCmd           .= ' '.$highPriorityThreshold;
    }

    $process = proc_open(
        $consumerCmd,
        [],
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
    $consumerProcesses[$pid] = [
        'process'      => $process,
        'highPriority' => $highPriority
    ];

    echo "\nStarted new RabbitConsumer.php process (pid: ".$pid.')';
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
        exec('pgrep -P '.$pid, $children);

        foreach ($children as $childPid) {
            try {
                posix_kill($childPid, SIGINT);
            } catch (\Exception $Exception) {
                echo "An error occurred while trying to send INT signal to php process (pid: ".$childPid.")."
                     ." Please kill manually.";
            }
        }

        proc_close($process);

        echo "Stopped RabbitConsumer.php process (pid: ".$pid.")";
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

$targetCount             = (int)getConsumerSetting('consumer_count');
$targetCountHighPriority = (int)getConsumerSetting('high_priority_consumer_count');

while (true) {
    sleep(3);

    $actualCount             = 0;
    $actualCountHighPriority = 0;
    $deadPids                = [];

    foreach ($consumerProcesses as $pid => $data) {
        $process        = $data['process'];
        $isHighPriority = $data['highPriority'];
        $status         = proc_get_status($process);

        if ($status && $status['running']) {
            $actualCount++;

            if ($isHighPriority) {
                $actualCountHighPriority++;
            }
            continue;
        }

        $deadPids[] = $pid;

        echo 'RabbitConsumer.php process (pid: '.$pid.') seems to have exited on its own. Removing from list.'
             .' Starting replacement process.';

        unset($consumerProcesses[$pid]);
    }

    // If all RabitConsumer.php are running -> every is fine
    if ($actualCount >= $targetCount) {
        continue;
    }

    for ($i = 0; $i < ($targetCount - $actualCount); $i++) {
        while (true) {
            if (checkServerConnection()) {
                if ($actualCountHighPriority < $targetCountHighPriority) {
                    startNewRabbitConsumer(true);
                    $actualCountHighPriority++;
                } else {
                    startNewRabbitConsumer(false);
                }
                break;
            }

            // Wait for 5 seconds and then check server connection again
            sleep(5);
        }
    }
}
