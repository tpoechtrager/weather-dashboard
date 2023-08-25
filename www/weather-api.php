<?php
// Set memory limit to 2048M
ini_set('memory_limit', '2048M');

// Set maximum execution time to 5 minutes (300 seconds)
set_time_limit(300);

// Enable gzip output compression if the browser supports it
if (!strstr($_SERVER['REMOTE_ADDR'], "192.168.0.")) {
    if (substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
        ob_start('ob_gzhandler');
    }
}

$dbhost = 'localhost';
$dbname = 'temperature';
$dbuser = 'root';
$dbpass = 'mysql';

try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    die();
}

// ?weather-station-id=<sid>_<code>_<channel>
// code and and channel may be omitted or set to '?'
// ?weather-station-id=0_?_3
$id = explode('_', isset($_GET['weather-station-id']) ? $_GET['weather-station-id'] : '0');
$sid = (int)$id[0];
$code = isset($id[1]) ? $id[1] : NULL;
$channel = isset($id[2]) ? $id[2] : NULL;

// If the value of code is '?', set it to NULL.
if ($code === '?') {
    $code = NULL;
}

// If the value of channel is '?', set it to NULL.
if ($channel === '?') {
    $channel = NULL;
}

$isDaylight = isset($_GET['is_daylight']) ? $_GET['is_daylight'] : null;

if ($isDaylight !== null) {
    header('Content-Type: application/json');

    // Check if the is_daylight parameter contains a valid timestamp
    $timestamp = $isDaylight !== '' ? (is_numeric($isDaylight) ? (int)$isDaylight : strtotime($isDaylight)) : time();

    if (!$timestamp) {
        echo json_encode(['error' => 'Invalid timestamp format']);
        die();
    }

    // Calculate the timestamp for 5 minutes ago
    $fiveMinutesAgo = $timestamp - (5 * 60);

    try {
        // Prepare the query to get all lux values where sid is 0 and time is within the last 5 minutes
        $sql = "SELECT light FROM data WHERE sid = 0 AND time >= FROM_UNIXTIME(:fiveMinutesAgo) AND time <= FROM_UNIXTIME($timestamp)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['fiveMinutesAgo' => $fiveMinutesAgo]);

        $luxValues = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Check if all lux values are greater than 0
        $allLuxGreaterThanZero = true;

        foreach ($luxValues as $lux) {
            if ($lux <= 0) {
                $allLuxGreaterThanZero = false;
                break; // No need to continue checking, we found a non-positive lux value
            }
        }

        if (count($luxValues) == 0) {
            echo json_encode(['error' => 'No data available']);
            die();
        }

        $result = [];

        $result["datetime"] = $timestamp;
        $result["is_daylight"] = $allLuxGreaterThanZero ? 1 : 0;
        $result["lux"] = (int)end($luxValues);
        $result["5min_avg_lux"] = array_sum($luxValues) / count($luxValues);

        // Echo the result and exit
        echo json_encode($result);
        exit;
    } catch (PDOException $e) {
        // Handle any SQL-related errors
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        die();
    }
}

$startDate = isset($_GET['start-date']) ? $_GET['start-date'] : null;
$endDate = isset($_GET['end-date']) ? $_GET['end-date'] : null;
$lastDays = isset($_GET['last-days']) ? (int)$_GET['last-days'] : null;
$lastWeeks = isset($_GET['last-weeks']) ? (int)$_GET['last-weeks'] : null;
$lastMonths = isset($_GET['last-months']) ? (int)$_GET['last-months'] : null;
$lastHours = isset($_GET['last-hours']) ? (int)$_GET['last-hours'] : null;
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

$filterActive = false; // Initialize the flag for filter activation

$where = "WHERE sid = :sid AND time > '2020-01-01'";
$params = ['sid' => $sid];

if ($code !== NULL) {
    $where .= " AND code = :code";
    $params['code'] = $code;
}

if ($channel !== NULL) {
    $where .= " AND channel = :channel";
    $params['channel'] = $channel;
}

// Path to the JSON file
$jsonFilePath = 'weather-stations.json';

// Define a variable to keep track of whether to ignore humidity or not
$ignoreHumidity = false;

$noImplausibleDataFilter = false;

// Check if the JSON file exists
if (file_exists($jsonFilePath)) {
    // Load weather stations from JSON
    $weatherStationsJson = file_get_contents($jsonFilePath);
    $weatherStations = json_decode($weatherStationsJson, true);

    // Loop through the weather stations to find the one with the matching sid
    foreach ($weatherStations['weatherStations'] as $station) {
        if ($station['sid'] == $sid &&
            (!isset($station['code']) || $station['code'] == $code) && 
            (!isset($station['channel']) || $station['channel'] == $channel)) {
            // If "begin" exists, add the condition
            if (isset($station['begin'])) {
                $where .= " AND time >= :beginDate";
                $params['beginDate'] = $station['begin'];
                $filterActive = true;
            }

            // If "end" exists, add the condition
            if (isset($station['end'])) {
                $where .= " AND time <= :endDate";
                $params['endDate'] = $station['end'];
                $filterActive = true;
            }

            if (isset($station['ignore_humidity']) &&
                ($station['ignore_humidity'] == "1" || $station['ignore_humidity'] === true)) {
                $ignoreHumidity = true; // Mark the flag to ignore humidity
            }

            if (isset($station['disable_implausible_data_filter']) &&
                ($station['disable_implausible_data_filter'] == "1" ||
                 $station['disable_implausible_data_filter'] === true)) {
                $noImplausibleDataFilter = true;
            }

            break; // No need to continue searching, we found the right station
        }
    }
}

if ($startDate) {
    $where .= " AND time >= :startDate";
    $params['startDate'] = $startDate;
    $filterActive = true;
}

if ($endDate) {
    $where .= " AND time <= :endDate";
    $params['endDate'] = $endDate;
    $filterActive = true;
}

if ($lastDays) {
    $where .= " AND time >= DATE_SUB(NOW(), INTERVAL :lastDays DAY)";
    $params['lastDays'] = $lastDays;
    $filterActive = true;
}

if ($lastWeeks) {
    $where .= " AND time >= DATE_SUB(NOW(), INTERVAL :lastWeeks WEEK)";
    $params['lastWeeks'] = $lastWeeks;
    $filterActive = true;
}

if ($lastMonths) {
    $where .= " AND time >= DATE_SUB(NOW(), INTERVAL :lastMonths MONTH)";
    $params['lastMonths'] = $lastMonths;
    $filterActive = true;
}

if ($lastHours) {
    $where .= " AND time >= DATE_SUB(NOW(), INTERVAL :lastHours HOUR)";
    $params['lastHours'] = $lastHours;
    $filterActive = true;
}

if ($year) {
    $where .= " AND YEAR(time) = :year";
    $params['year'] = $year;
    $filterActive = true;
}

if (!$filterActive && false) {
    // If no other filters are active, add the last 24-hour filter
    $where .= " AND time > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
}

$sql = "SELECT COUNT(*) As count FROM data $where";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();

// Set the maximum number of rows to 365 days of 1-minute values
$maxRows = 86400/60 * 365;

if ($totalRows > $maxRows) {
    // Calculate the thinning factor
    $thinningFactor = ceil($totalRows / $maxRows);

    // Update the query to fetch only every n-th row
    $where .= " AND MOD(id, :thinningFactor) = 0";
    $params['thinningFactor'] = $thinningFactor;
}


$needUnixTimestamp = false;

if (!$noImplausibleDataFilter) {
    $needUnixTimestamp = true;
}

$additionalFields = [];

if ($needUnixTimestamp) {
    $additionalFields[] = "UNIX_TIMESTAMP(time) AS unix_time";
}

$additionalFieldsSql = "";

if (!empty($additionalFields)) {
    $additionalFieldsSql = ', '.implode($additionalFields);
}


$sql = "SELECT time, temp, humidity, co2, wind, wind_avg, wind_dir, rain, light, uv $additionalFieldsSql FROM data $where";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function removeNullKeysAcrossAllRows(&$rows) {
    // Check if there's at least one row. If not, return the array as is
    if (empty($rows)) {
        return $rows;
    }
    
    // Create an array to hold the count of NULL values for each key
    $nullCount = [];

    // Initialize the count array with zeroes for each key in the first row
    foreach ($rows[0] as $key => $value) {
        $nullCount[$key] = 0;
    }

    // Count how many times each key has a NULL value across all rows
    foreach ($rows as $row) {
        foreach ($row as $key => $value) {
            if (is_null($value)) {
                $nullCount[$key]++;
            }
        }
    }

    // Identify keys where the count of NULL values equals the number of rows (meaning every value is NULL for that key)
    $keysToRemove = [];
    foreach ($nullCount as $key => $count) {
        if ($count === count($rows)) {
            $keysToRemove[] = $key;
        }
    }

    // Now loop through the rows and remove the identified keys
    foreach ($rows as $index => $row) {
        foreach ($keysToRemove as $key) {
            unset($rows[$index][$key]);
        }
    }

    return $rows;
}

function removeRowsWithImplausibleChanges(&$rows) {
    $reindexRequired = false; // Initialize the flag to check if re-indexing is required
    $previousRow = null;
    $previousTimestamp = null;

    foreach ($rows as $index => $row) {
        $currentTimestamp = $row['unix_time'];

        if ($previousRow !== null) {
            // Check if the previous value is older than 10 minutes
            if (($currentTimestamp - $previousTimestamp) > 600) {
                $previousRow = null;
                $previousTimestamp = null;
            } else {
                // Calculate differences
                $humidityDifference = ($row['humidity'] !== null && $previousRow['humidity'] !== null)
                    ? abs($row['humidity'] - $previousRow['humidity']) : null;
                $temperatureDifference = ($row['temp'] !== null && $previousRow['temp'] !== null)
                    ? abs($row['temp'] - $previousRow['temp']) : null;

                // If the difference is more than 10 for either value, unset the row
                if (($humidityDifference !== null && $humidityDifference > 10) || 
                    ($temperatureDifference !== null && $temperatureDifference > 10)) {
                    unset($rows[$index]);
                    // Set the flag to reindex the array later
                    $reindexRequired = true;
                    continue;
                }
            }
        }

        // Update the previous row and timestamp
        $previousRow = $row;
        $previousTimestamp = $currentTimestamp;
    }

    // Re-index the array to maintain continuous indexing if required
    if ($reindexRequired) {
        $rows = array_values($rows);
    }
}

if (file_exists('auth.php')) {
    include 'auth.php';

    if (!isAuthedClient()) {
        // Unset 'co2' key in all rows here
        foreach ($rows as $index => $row) {
            unset($rows[$index]['co2']);
        }
    }
}

if ($ignoreHumidity) {
    // If ignoreHumidity is true, simply remove the humidity key from each row
    foreach ($rows as $index => $row) {
        unset($rows[$index]['humidity']);
    }
}

removeNullKeysAcrossAllRows($rows);

if (!$noImplausibleDataFilter) {
    removeRowsWithImplausibleChanges($rows);
}

if ($needUnixTimestamp) {
    foreach ($rows as $index => $row) {
        unset($rows[$index]['unix_time']);
    }
}

// Set the content type for the response to be in JSON format
header('Content-Type: application/json');
// Output the result set as JSON
echo json_encode($rows);

?>
