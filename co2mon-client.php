#!/usr/bin/env php
<?php

// Global variables
$co2monProcess = null;
$jsonBuffer = "";
$jsonOutputComplete = false;
$udpSocket = null;
$sendUDPHost = '192.168.0.2'; // Replace with desired IP address for sending UDP data
$sendUDPPort = 7777; // Replace with desired Port number for sending UDP data
$timeLimit = 120; // Time limit in seconds for no data received, adjust as needed

// Initialize UDP socket on startup
initializeUdpSocket();

/**
 * Initialize the UDP socket.
 */
function initializeUdpSocket()
{
    global $udpSocket;

    // Create a UDP socket only if it doesn't exist
    $udpSocket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
}

/**
 * Read data from co2mon process output and process JSON.
 *
 * @return bool True if the JSON buffer output is complete, otherwise false.
 */
function readCo2mon()
{
    global $co2monProcess, $jsonOutputComplete, $jsonBuffer;

    $tmp = fread($co2monProcess, 512);

    for ($i = 0; $i < strlen($tmp); ++$i) {
        $char = $tmp[$i];

        switch ($char) {
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

    if ($jsonOutputComplete) {
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
 * Process data from co2mon process output.
 */
function processCo2monData()
{
    global $co2monProcess, $timeLimit, $jsonBuffer;

    $lastDataReceivedTime = time();
    $jsonBufferComplete = false;

    while (true) {
        // Check if co2monProcess is still running using stream_get_meta_data
        $co2monProcessMeta = stream_get_meta_data($co2monProcess);
        if ($co2monProcessMeta['eof']) {
            outputMessage("Error: co2mon process terminated unexpectedly. Restarting the process...");
            return;
        }

        // Implement stream_select
        $readSockets = array($co2monProcess);
        $writeSockets = [];

        $numStreams = stream_select($readSockets, $writeSockets, $writeSockets, 1); // Wait for 1000 ms (1 second)

        // Read and process co2mon output only when co2monProcess is in readSockets (redundant condition)
        if ($numStreams > 0 && in_array($co2monProcess, $readSockets)) {
            $jsonBufferComplete = readCo2mon();

            if ($jsonBufferComplete) {
                // Decode the JSON data
                $data = json_decode($jsonBuffer, true);

                // Rename the "timestamp" field to "time" if it exists
                if (isset($data['timestamp'])) {
                    $data['time'] = $data['timestamp'];
                    unset($data['timestamp']);
                }

                // Rename the "temperature" field to "temperature_celsius" if it exists
                if (isset($data['temperature_celsius'])) {
                    $data['temperature_C'] = $data['temperature_celsius'];
                    unset($data['temperature_celsius']);
                }

                // Inject the "model" and "id" fields
                $data['model'] = "TFA-Dostmann-AIRCO2NTROL-MINI";
                $data['id'] = -24;

                // Encode the modified data back to JSON
                $modifiedJsonBuffer = json_encode($data);

                // Output the modified JSON data
                outputMessage("Modified JSON Data: $modifiedJsonBuffer");

                // Send the modified JSON data using UDP
                sendJsonDataOverUdp($modifiedJsonBuffer);

                $lastDataReceivedTime = time();
            }
        }

        // Check for time limit without data received
        $now = time();
        if ($now - $lastDataReceivedTime >= $timeLimit) {
            outputMessage("Error: No data received for $timeLimit seconds. Restarting co2mon process...");
            return; // Restart the co2mon process
        }
    }
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
while (true) {
    if ($co2monProcess) {
        pclose($co2monProcess);
    }

    // Construct co2mon command
    $co2monCommand = "co2mon --all"; // Modify this command as needed

    outputMessage("Command: $co2monCommand");

    // Open a process for co2mon
    $co2monProcess = popen($co2monCommand, 'r');

    if (!$co2monProcess) {
        outputMessage("Error: Failed to open co2mon process. Waiting for 5 seconds before retrying...");
    } else {
       // stream_set_blocking($co2monProcess, FALSE);
        processCo2monData();
    }

    // Wait for 5 seconds before starting the co2mon process again
    sleep(5);
}
