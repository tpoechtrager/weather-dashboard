#!/usr/bin/env php
<?php

// Global variables
$rtlProcess = null;
$jsonBuffer = "";
$jsonOutputComplete = false;
$udpSocket = null;
$sendUDPHost = '192.168.0.2'; // Replace with desired IP address for sending UDP data
$sendUDPPort = 7777; // Replace with desired Port number for sending UDP data
$timeLimit = 120; // Time limit in seconds for no data received, adjust as needed
$statisticsLastPrinted = 0; // Initialize the last statistics print time to the current time

// Weather stations statistics
$weatherStations = [];

// Initialize UDP socket on startup
initializeUdpSocket();

/**
 * Initialize the UDP socket.
 */
function initializeUdpSocket()
{
    global $udpSocket, $sendUDPHost, $sendUDPPort;

    // Create a UDP socket only if it doesn't exist
    $udpSocket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
}

/**
 * Read data from rtl_433 process output and decode JSON.
 *
 * @return bool True if the JSON buffer output is complete, otherwise false.
 */
function readRtl433()
{
    global $rtlProcess, $jsonOutputComplete, $jsonBuffer;

    $tmp = fread($rtlProcess, 512);

    for ($i = 0; $i < strlen($tmp); ++$i)
    {
        $char = $tmp[$i];

        switch ($char)
        {
            case '{':
                $jsonBuffer = '{';
                $jsonOutputComplete = false;
                break;
            case '}':
                $jsonBuffer .= '}';
                $jsonOutputComplete = true;
                break;
            default:
                $jsonBuffer .= $char;
        }
    }

    if ($jsonOutputComplete)
    {
        $jsonOutputComplete = false;
        return true;
    }

    return false;
}

/**
 * Send JSON data over UDP to the specified host and port.
 *
 * @param string $data JSON data to be sent.
 */
function sendJsonDataOverUdp($data)
{
    global $udpSocket, $sendUDPHost, $sendUDPPort;

    // Send the data to the specified host and port using the global UDP socket
    socket_sendto($udpSocket, $data, strlen($data), 0, $sendUDPHost, $sendUDPPort);
}

/**
 * Process data from rtl_433 process output.
 */
function processRtlData()
{
    global $rtlProcess, $timeLimit, $weatherStations, $jsonBuffer, $statisticsLastPrinted;

    $lastDataReceivedTime = time();
    $jsonBufferComplete = false;

    while (true)
    {
        // Check if rtlProcess is still running
        $rtlProcessMeta = stream_get_meta_data($rtlProcess);
        if ($rtlProcessMeta['eof']) {
            outputMessage("Error: rtl_433 process terminated unexpectedly. Restarting the process...");
            return;
        }

        // Implement stream_select
        $readSockets = array($rtlProcess);
        $writeSockets = [];

        $numStreams = stream_select($readSockets, $writeSockets, $writeSockets, 1); // Wait for 1000 ms (1 second)

        // Read and process rtl_433 output only when rtlProcess is in readSockets (redundant condition)
        if ($numStreams > 0 && in_array($rtlProcess, $readSockets)) {
            $jsonBufferComplete = readRtl433();

            if ($jsonBufferComplete)
            {
                outputMessage($jsonBuffer . "\n"); // Output the JSON buffer
                sendJsonDataOverUdp($jsonBuffer); // Send the JSON buffer using UDP

                // Extract model and id from the JSON data and update weather station count
                $data = json_decode($jsonBuffer, true);
                if (isset($data['model']) && isset($data['id']))
                {
                    $modelId = $data['model'] . '_' . $data['id'];
                    updateWeatherStationCount($modelId);
                }

                $lastDataReceivedTime = time();
            }
        }

        // Check for time limit without data received
        $now = time();
        if ($now - $lastDataReceivedTime >= $timeLimit)
        {
            outputMessage("Error: No data received for $timeLimit seconds. Restarting rtl_433 process...");
            return; // Restart the rtl_433 process
        }

        // Print weather station statistics every minute
        if ($now - $statisticsLastPrinted >= 60)
        {
            printWeatherStationStatistics();
            $statisticsLastPrinted = $now;
        }
    }
}

/**
 * Update weather station count based on model and id.
 *
 * @param string $modelId Combination of model and id of the weather station.
 */
function updateWeatherStationCount($modelId)
{
    global $weatherStations;

    if (isset($weatherStations[$modelId]))
    {
        $weatherStations[$modelId]++;
    }
    else
    {
        $weatherStations[$modelId] = 1;
    }
}

/**
 * Print weather station statistics.
 */
function printWeatherStationStatistics()
{
    global $weatherStations;

    $totalStations = count($weatherStations);
    $totalReceived = array_sum($weatherStations);

    $dateTime = date('Y-m-d H:i:s'); // Get the current date and time in the desired format

    outputMessage("Weather Station Statistics (Last Minute):");
    outputMessage("Total Unique Stations Received: $totalStations");
    outputMessage("Total Messages Received: $totalReceived");
    outputMessage(""); // Add an empty line at the end

    // Clear the weather station count for the next minute
    $weatherStations = [];
}

/**
 * Get the configuration from the current directory or /etc.
 *
 * @param string $fileName The name of the configuration file.
 * @param bool $required Whether the configuration parameter is required or not.
 * @return string|null The content of the file, or null if file not found and $required is false.
 */
function getConfig($fileName, $required = false)
{
    $localPath = "./$fileName";
    $etcPath = "/etc/$fileName";

    $filePath = file_exists($localPath) ? $localPath : (file_exists($etcPath) ? $etcPath : null);

    if (!$filePath && $required)
    {
        outputMessage("Error: Required configuration file $fileName not found. Exiting...");
        exit(1);
    }

    return $filePath ? file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] : null;
}

/**
 * Output the message with the current date and time in the desired format.
 *
 * @param string $message The message to be outputted.
 */
function outputMessage($message)
{
    $dateTime = date('Y-m-d H:i:s');
    echo "[$dateTime] $message\n";
}

// Main loop
while (true)
{
    if ($rtlProcess) {
        if (is_resource($rtlProcess)) {
            $status = proc_get_status($rtlProcess);
            if ($status['running']) {
                printf("KILL!\n");
                posix_kill($status['pid'], SIGKILL);
            }
            proc_close($rtlProcess);
        }
    }

    // Get required configuration parameter
    $serialNumber = getConfig('weather_sdr_sn', true);

    // Get optional configuration parameters
    $frequency = getConfig('weather_sdr_freq');
    $gain = getConfig('weather_sdr_gain');

    // Check if the rtl_433 binary is in /usr/local/bin
    $rtl433Binary = file_exists('/usr/local/bin/rtl_433') ? '/usr/local/bin/rtl_433' : 'rtl_433';

    // Construct rtl_433Command
    $rtl433Command = "$rtl433Binary -M hires -M level";

    if ($frequency !== null && !empty($frequency))
        $rtl433Command .= " -f $frequency";

    if ($gain !== null && !empty($gain))
        $rtl433Command .= " -g $gain";

    $rtl433Command .= " -F json -d :$serialNumber 2>/dev/null";

    outputMessage("Command: $rtl433Command");

    $rtlProcess = popen($rtl433Command, 'r');

    if (!$rtlProcess)
    {
        outputMessage("Error: Failed to open rtl_433 process. Waiting for 5 seconds before retrying...");
    }
    else
    {
        stream_set_blocking($rtlProcess, FALSE);
        processRtlData();
    }

    // Wait for 5 seconds before starting the rtl_433 process again
    sleep(5);
}
