#!/usr/bin/env php
<?php

$weatherStations = [];
$receiveSocket = null;
$infoUDPSocket = null;
$receiveUDPBindAddress = '0.0.0.0';
$receiveUDPPort = 7777;
$infoUDPPort = 7778;
$infoUDPBindAddress = '0.0.0.0';

$databaseConnection = null;
$mysqlHost = 'localhost';
$mysqlDatabase = 'temperature';
$mysqlUsername = 'root';
$mysqlPassword = 'mysql';
$databaseWriteInterval = 60; // 1 minute
$lastDatabaseWriteTime = time(); // Initialize with the current time
$dbWriteMinimumRequiredUpdateCount = 2; // Minimum update count required for writing to the database

$dataFieldNames = [
    'channel',
    'temperature_C',
    'humidity',
    'wind_max_m_s',
    'wind_avg_m_s',
    'wind_dir_deg',
    'rain_mm',
    'co2',
    'light_lux',
    'uv'
];

$weatherStationTypes = [
    "Bresser-7in1" => 0,
    "Bresser-5in1" => 1,
    "LaCrosse-TX35DTHIT" => 2,
    "LaCrosse-TX141THBv2" => 5,
    "Nexus-TH" => 6,
    "TFA-303221" => 7,
    "TFA-Dostmann-AIRCO2NTROL-MINI" => -24
];

$weatherStationTypeUnknown = -32768;

class WeatherStation
{
    private $data;
    private $lastUpdate;
    private $receiverHost;
    private $type;
    private $dataUpdates; // Variable to store the number of data updates

    public function __construct($data, $receiverHost)
    {
        $this->dataUpdates = 0; // Initialize the counter to 0
        $this->updateData($data, $receiverHost);
    }

    public function getData()
    {
        return $this->data;
    }

    public function getLastUpdate()
    {
        return $this->lastUpdate;
    }

    public function getReceiverHost()
    {
        return $this->receiverHost;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getDataUpdates()
    {
        return $this->dataUpdates;
    }

    public function updateData($data, $receiverHost)
    {
        global $weatherStationTypes, $weatherStationTypeUnknown;

        if (isset($data['time']) && !is_numeric($data['time'])) {
            // If 'time' is not a Unix timestamp, convert it using strtotime()
            $data['time'] = strtotime($data['time']);
        }

        $this->data = $data;
        $this->receiverHost = $receiverHost; // Set the client host

        // Set the type based on the model
        $this->type = $weatherStationTypes[$data['model']] ?? $weatherStationTypeUnknown;

        $this->lastUpdate = time();
        $this->dataUpdates++; // Increment the data update counter
    }
}

function createAndSetSocket($ip, $port)
{
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($socket === false) {
        outputMessage("socket_create() failed: " . socket_strerror(socket_last_error()));
        return null;
    }

    if (socket_bind($socket, $ip, $port) === false) {
        outputMessage("socket_bind() failed: " . socket_strerror(socket_last_error($socket)));
        return null;
    }

    if (socket_set_nonblock($socket) === false) {
        outputMessage("socket_set_nonblock() failed: " . socket_strerror(socket_last_error($socket)));
        return null;
    }

    return $socket;
}

function initializeUdpSockets()
{
    global $receiveSocket, $infoUDPSocket,
           $receiveUDPBindAddress, $receiveUDPPort, $infoUDPPort, $infoUDPBindAddress;

    // Create and set UDP sockets for receiving weather station data and info requests
    $receiveSocket = createAndSetSocket($receiveUDPBindAddress, $receiveUDPPort);
    $infoUDPSocket = createAndSetSocket($infoUDPBindAddress, $infoUDPPort);
}

function hasAtLeastOneValidDataField($data)
{
    global $dataFieldNames;

    foreach ($dataFieldNames as $field) {
        if (isset($data[$field])) {
            return true;
        }
    }

    return false;
}

function processWeatherStationData($decodedData, $receiverHost)
{
    global $weatherStations;

    if ($decodedData !== null && isset($decodedData["model"]) && isset($decodedData["id"])) {
        $modelId = $decodedData["model"] . "_" . $decodedData["id"];

        if (isset($weatherStations[$modelId])) {
            $weatherStations[$modelId]->updateData($decodedData, $receiverHost);
        } else if (hasAtLeastOneValidDataField($decodedData)) {
            $weatherStations[$modelId] = new WeatherStation($decodedData, $receiverHost);
        }
    }
}

function handleInfoRequest($data, $clientHost, $clientPort)
{
    global $weatherStations, $infoUDPSocket;

    $responseMessage = '';
    $infoDataBuffer = '';

    if ($data === "+++") {
        // If info request is "+++", send data for all weather stations
        foreach ($weatherStations as $model => $weatherStation) {
            $sensorData = '';
            $sensor = $weatherStation->getData();
            foreach ($sensor as $key => $val) {
                if ($sensorData) $sensorData .= '|';
                $sensorData .= "$key:$val";
            }
            if ($infoDataBuffer) $infoDataBuffer .= "\n";
            $infoDataBuffer .= $sensorData;
        }
    }

    $responseMessage .= $data . "\n" . $infoDataBuffer;
    socket_sendto($infoUDPSocket, $responseMessage, strlen($responseMessage), 0, $clientHost, $clientPort);
}

function removeOrphanedWeatherStations()
{
    global $weatherStations;
    $twentyFourHoursAgo = time() - (24 * 60 * 60);
    foreach ($weatherStations as $key => $weatherStation) {
        if ($weatherStation->getLastUpdate() < $twentyFourHoursAgo) {
            unset($weatherStations[$key]);
        }
    }
}

function initializeDatabaseConnection()
{
    global $databaseConnection, $mysqlHost, $mysqlDatabase, $mysqlUsername, $mysqlPassword;

    try {
        $databaseConnection = new PDO("mysql:host=$mysqlHost;dbname=$mysqlDatabase", $mysqlUsername, $mysqlPassword);
        return true;
    } catch (PDOException $e) {
        outputMessage("MySQL connection failed: " . $e->getMessage());
        return false;
    }
}

function isDatabaseConnectionValid()
{
    global $databaseConnection;
    return $databaseConnection && $databaseConnection->getAttribute(PDO::ATTR_CONNECTION_STATUS) === 'Connection successful';
}

function writeToDatabase()
{
    global $weatherStations, $databaseConnection, $databaseWriteInterval,
           $weatherStationTypeUnknown, $dataFieldNames, $dbWriteMinimumRequiredUpdateCount;

    // Check if the database connection is valid
    if (!isDatabaseConnectionValid()) {
        // Try to initialize the database connection again
        if (!initializeDatabaseConnection()) {
            // If unsuccessful, return
            return;
        }
    }

    $currentTime = time();

    foreach ($weatherStations as $modelId => $weatherStation) {
        if ($weatherStation->getType() == $weatherStationTypeUnknown) {
            continue;
        }

        $data = $weatherStation->getData();

        // If 'id' is not set in the data array, skip this weather station
        if (!isset($data['id'])) {
            continue;
        }

        // If the last update is not within databaseWriteInterval, skip this weather station
        if ($currentTime - $weatherStation->getLastUpdate() > $databaseWriteInterval) {
            continue;
        }

        // Check if the weather station has reached the minimum update requirement
        if ($weatherStation->getDataUpdates() < $dbWriteMinimumRequiredUpdateCount) {
            continue;
        }

        // Begin building our SQL query
        $query = "INSERT INTO data (time, sid, code";

        $values = "FROM_UNIXTIME(?), ?, ?";  // Corresponding to time, sid, and code

        // An array to hold all the parameters to bind
        // code=id
        $parameters = [$weatherStation->getLastUpdate(), $weatherStation->getType(), $data['id']];

        $hasAtLeastOneValidField = false;

        $databaseAliases = [
            "temperature_C" => "temp",
            "wind_max_m_s" => "wind",
            "wind_avg_m_s" => "wind_avg",
            "wind_dir_deg" => "wind_dir",
            "rain_mm" => "rain",
            "light_lux" => "light"
        ];

        // Add each field from $data if it exists
        foreach ($dataFieldNames as $field) {
            if (isset($data[$field])) {
                $query .= ", " . ($databaseAliases[$field] ?? $field);
                $values .= ", ?";
                $parameters[] = $data[$field];
            }
        }

        $query .= ") VALUES ($values)";

        // Prepare the query
        $stmt = $databaseConnection->prepare($query);

        try {
            // Execute the query with our parameters
            $stmt->execute($parameters);
        } catch (PDOException $e) {
            outputMessage("MySQL insertion failed: " . $e->getMessage());
        }
    }
}

function outputMessage($message)
{
    $dateTime = date('Y-m-d H:i:s');
    echo "[$dateTime] $message\n";
}

function receiveDataOverUdp()
{
    global $receiveSocket, $infoUDPSocket, $lastDatabaseWriteTime;

    while (true) {
        $readSockets = array($receiveSocket, $infoUDPSocket);
        $writeSockets = null;
        $errorSockets = null;

        if (socket_select($readSockets, $writeSockets, $errorSockets, 1) > 0) {
            foreach ($readSockets as $socket) {
                $data = '';
                $clientHost = '';
                $clientPort = 0;

                // Receive up to 8192 bytes of data
                $bytesReceived = socket_recvfrom($socket, $data, 8192, 0, $clientHost, $clientPort);

                if ($bytesReceived === false) {
                    // An error occurred while receiving data
                    outputMessage("Error: " . socket_strerror(socket_last_error($socket)));
                    continue; // Skip the rest of the iteration and proceed to the next one
                }

                if ($socket === $receiveSocket) {
                    $decodedData = json_decode($data, true);
                    processWeatherStationData($decodedData, $clientHost);
                } elseif ($socket === $infoUDPSocket) {
                    handleInfoRequest($data, $clientHost, $clientPort);
                }
            }
        }

        removeOrphanedWeatherStations();

        // Check if one minute has elapsed since the last database write
        $currentTime = time();
        $elapsedTimeSinceLastDatabaseWrite = $currentTime - $lastDatabaseWriteTime;

        if ($elapsedTimeSinceLastDatabaseWrite >= 60) {
            // Write data to the database
            writeToDatabase();
            $lastDatabaseWriteTime = $currentTime;
        }
    }
}

function main()
{
    initializeUdpSockets();
    initializeDatabaseConnection();
    receiveDataOverUdp();
}

main();
