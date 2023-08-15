<!DOCTYPE html>
<html>
<head>
    <title>Weather Data</title>
    <meta charset="UTF-8">
    <style>
        /* Custom scrollbar styles */
        /* For Chrome, Edge, and Safari */
        ::-webkit-scrollbar {
            width: 10px;
            background-color: #333;
        }

        ::-webkit-scrollbar-thumb {
            background-color: #555;
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background-color: #777;
        }

        body {
            background-color: #2b2b2b;
            color: #f5f5f5;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        #container {
            width: 100%;
            max-width: 1024px;
        }

        #no-data-message {
            text-align: center;
            font-size: 24px;
            color: #f5f5f5;
            background-color: rgba(0, 0, 0, 0.7);
            padding: 10px 20px;
            border-radius: 4px;
        }

        .chart {
            width: 100%;
            height: 400px;
        }
        #filterBox {
            margin: 20px;
            padding: 15px;
            border: 1px solid #444;
            border-radius: 4px;
            background-color: #333;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }
        #filterBox label, #filterBox select, #filterBox button {
            margin: 5px;
            background-color: #444;
            color: #f5f5f5;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
        }
        #filterBox input {
            margin: 5px;
            background-color: #444;
            color: #f5f5f5;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
            width: 80px; /* Adjust the width as needed */
        }
        #filterBox select {
            width: 80px;
        }
        #filterBox button {
            cursor: pointer;
        }

        /* Additional CSS for the loading element */
        #loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: hidden;
            justify-content: center;
            align-items: center;
        }


        #loading-text {
            color: #f5f5f5;
            font-size: 24px;
        }

        .filter-container {
            display: flex;
            align-items: center;
            margin: 5px;
        }
        .filter-container label {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <!-- Filter boxes -->
    <div id="filterBox">
        <div class="filter-container">
            <label for="weather-station-id">Station:</label>
            <select id="weather-station-id" style='width: 100%'>
            </select>
        </div>

        <div class="filter-container">
            <label for="last-days">Last N days:</label>
            <select id="last-days">
                <option value="">Any</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5" selected="selected">5</option>
                <option value="6">6</option>
                <option value="7">7</option>
            </select>
        </div>

        <div class="filter-container">
            <label for="last-weeks">Last N weeks:</label>
            <select id="last-weeks">
                <option value="">Any</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
            </select>
        </div>

        <div class="filter-container">
            <label for="last-months">Last N months:</label>
            <select id="last-months">
                <option value="">Any</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
                <option value="6">6</option>
            </select>
        </div>

        <div class="filter-container">
            <label for="year">Year:</label>
            <select id="year">
                <option value="">Any</option>
                <option value="2021">2021</option>
                <option value="2022">2022</option>
                <option value="2023">2023</option>
                <!-- Add more years up to the current year -->
            </select>
        </div>

        <script>
            // The JSON data representing weather stations
            // Embedded weather-stations.json
            const weatherStationsJSON = `<?php echo json_encode(json_decode(file_get_contents('weather-stations.json')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>`;
    
            // Parse the JSON data
            const weatherStationsData = JSON.parse(weatherStationsJSON);
    
            // Get the select element
            const selectElement = document.getElementById("weather-station-id");

            // Create and append options based on the JSON data
            weatherStationsData.weatherStations.forEach(station => {
                const option = document.createElement("option");

                // Set the value of the option
                option.value = `${station.sid}_${station.id || '?'}_${station.channel || '?'}`;
                
                // Set the text content of the option
                option.textContent = station.name;

                // Append the option to the select element
                selectElement.appendChild(option);
            });
        </script>
    </div>

    <!-- Loading screen -->
    <div id="loading">
        <p id="loading-text">Loading...</p>
    </div>

    <div id="container">
    </div>

    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <script>
        const chartConfigs = [
            { key: 'temp', name: 'Temperature', units: '°C', color: 'rgb(255, 99, 132)' },
            { key: 'humidity', name: 'Humidity', units: '%', color: 'rgb(54, 162, 235)' },
            { key: 'co2', name: 'co2', units: 'ppm', color: 'rgb(54, 162, 235)' },
            { key: 'light', name: 'Light', units: 'lux', color: 'rgb(255, 206, 86)' },
            { key: 'average_light', name: 'Average Light', units: 'klux', color: 'rgb(255, 230, 128)' },
            { key: 'uv', name: 'UV Index', units: 'index', color: 'rgb(153, 102, 255)' },
            { key: 'length_of_daylight', name: 'Daylight duration', units: 'minutes', color: 'rgb(255, 206, 86)' },
            { key: 'sunrise', name: 'Sunrise Time', units: 'Time', color: 'rgb(0, 255, 0)' },
            { key: 'sunset', name: 'Sunset Time', units: 'Time', color: 'rgb(255, 0, 0)' },
            { key: 'wind', name: 'Wind Gust', units: 'm/s', color: 'rgb(139, 69, 19)' },
            { key: 'wind_avg', name: 'Wind Average', units: 'm/s', color: 'rgb(139, 69, 19)' },
            { key: 'rain', name: 'Rain', units: 'l/m²', color: 'rgb(75, 192, 192)' },
        ];

        let loadingTimeout; // Variable to store the timeout ID for showing the loading screen

        // Function to show the loading screen with a delay of 500 ms
        function showLoadingWithTimeout() {
            loadingTimeout = setTimeout(() => {
                const loadingElement = document.getElementById('loading');
                loadingElement.style.display = 'flex';
            }, 500); // 500 ms delay
        }

        // Function to hide the loading screen
        function hideLoading() {
            clearTimeout(loadingTimeout); // Clear the loading timeout if data is fetched before 1 second
            const loadingElement = document.getElementById('loading');
            loadingElement.style.display = 'none';
        }

        // Function to create a plot
        function createPlot(container, key, times, values, name, units, color, chartNameWithLastValue, layout) {
            const chartDiv = document.createElement('div');
            chartDiv.classList.add('chart');
            chartDiv.id = `${key}Chart`;
            container.appendChild(chartDiv);
            const chartData = [{
                x: times,
                y: values,
                mode: 'dots',
                name: name,
                line: { color: color }
            }];
            Plotly.newPlot(chartDiv.id, chartData, { ...layout, title: chartNameWithLastValue, yaxis: { ...layout.yaxis, title: `${name} (${units})` } });
        }

        function datesEqual(dateA, dateB) {
            return (
                dateA.getDate() === dateB.getDate() &&
                dateA.getMonth() === dateB.getMonth() &&
                dateA.getFullYear() === dateB.getFullYear()
            );
        }

        function checkContinousLuxLevel(data, startIndex, numValues, condition) {
            let endIndex = startIndex + numValues;

            if (startIndex >= data.length || endIndex >= data.length) {
                return false;
            }

            for (let i = startIndex; i < endIndex; i++) {
                if (!condition(data[i].light)) {
                    return false;
                }
            }

            return true;
        }

        function calculateDaylightData(data) {
            let daylightData = [];

            data.forEach((row, index) => {
                if (row.light !== undefined && row.light > 0 && index > 0) {
                    let previousLightValue = data[index-1].light;

                    if (previousLightValue === null || previousLightValue > 0) {
                        return;
                    }

                    let date = new Date(row.time).toISOString().split('T')[0];

                    if (!daylightData.find(item => item.date === date)) {
                        let sunriseTime = new Date(row.time);
                        let dayEndIndex = index + 1;
                        let endOfDaylightFound = false;

                        let totalLight = 0;
                        let lightCount = 0;

                        while (dayEndIndex < data.length) {
                            if (data[dayEndIndex].light <= 0) {
                                if (checkContinousLuxLevel(data, dayEndIndex + 1, 10, light => light <= 0)) {
                                    endOfDaylightFound = true;
                                    break;
                                }
                            }

                            // Accumulate positive light levels for averaging
                            if (data[dayEndIndex].light > 0) {
                                totalLight += parseInt(data[dayEndIndex].light);
                                lightCount++;
                            }

                            dayEndIndex++;
                        }

                        let sunsetTime = null;
                        let daylightLength = null;
                        let averageLightLevel = null;

                        if (endOfDaylightFound) {
                            // Calculate the daylight length in minutes
                            sunsetTime = new Date(data[dayEndIndex - 1].time);
                            daylightLength = ((sunsetTime - sunriseTime) / 1000 / 60).toFixed(0);

                            // Guess a day should have at least 6 hours of daylight
                            if (daylightLength < 6 * 60) {
                                daylightLength = null;
                            } else {
                                // Calculate the average light level
                                averageLightLevel = (totalLight / lightCount / 1000).toFixed(2);
                            }
                        }

                        // Add the data to the array
                        daylightData.push({ date, daylightLength, sunriseTime, sunsetTime, averageLightLevel });
                    }
                }
            });

            return daylightData;
        }

        function changeDateInDatetimeArray(datetimeArray, newDate) {
            const newDatetimeArray = datetimeArray.map((datetime) => {
                const newDatetime = new Date(newDate);
                newDatetime.setHours(datetime.getHours());
                newDatetime.setMinutes(datetime.getMinutes());
                newDatetime.setSeconds(datetime.getSeconds());
                newDatetime.setMilliseconds(datetime.getMilliseconds());
                return newDatetime;
            });

            return newDatetimeArray;
        }

        // Function to apply filters when the "Apply Filters" button is clicked
        function applyFilters() {
            // Show the loading screen with a delay of 1 second
            showLoadingWithTimeout();

            const weatherStationId = document.getElementById('weather-station-id').value;
            const lastDays = document.getElementById('last-days').value;
            const lastWeeks = document.getElementById('last-weeks').value;
            const lastMonths = document.getElementById('last-months').value;
            const year = document.getElementById('year').value || ""; // Get the selected year

            // Fetch data with the applied filters
            fetch(`weather-api.php?weather-station-id=${weatherStationId}&last-days=${lastDays}&last-weeks=${lastWeeks}&last-months=${lastMonths}&year=${year}`)
                .then(response => response.json())
                .then(data => {
                    // Remove existing charts
                    const container = document.getElementById('container');
                    while (container.firstChild) {
                        container.removeChild(container.firstChild);
                    }

                    // Check if data is available
                    if (data.length === 0) {
                        const container = document.getElementById('container');
                        container.innerHTML = `<div id="no-data-message">No data available for selected time frame</div>`; // Display the centered message
                    } else {
                        const dayLightData = calculateDaylightData(data);

                        chartConfigs.forEach(({ key, name, units, color }) => {
                            layout = {
                                plot_bgcolor: "#2b2b2b",
                                paper_bgcolor: "#2b2b2b",
                                font: { color: '#f5f5f5' },
                                xaxis: {
                                    title: 'Time',
                                    showgrid: true,
                                    zeroline: false,
                                    gridcolor: '#000000',
                                    linecolor: '#f5f5f5',
                                    ticks: 'outside',
                                    tickfont: { color: '#f5f5f5' },
                                    tickcolor: '#f5f5f5',
                                },
                                yaxis: {
                                    showline: false,
                                    gridcolor: '#000000',
                                    linecolor: '#f5f5f5',
                                    ticks: 'outside',
                                    tickfont: { color: '#f5f5f5' },
                                    tickcolor: '#f5f5f5',
                                },
                                autosize: true,
                            };

                            let times = [];
                            let values = [];

                            // For sunrise and sunset set all dates to 1970-01-01.
                            // Having different dates messes up the yaxis.

                            if (key === 'average_light') {
                                dayLightData.forEach((row) => {
                                    if (row.averageLightLevel === null) return;
                                    times.push(row.date);
                                    values.push(row.averageLightLevel);
                                });
                            } else if (key === 'length_of_daylight') {
                                dayLightData.forEach((row) => {
                                    if (row.daylightLength === null) return;
                                    times.push(row.date);
                                    values.push(row.daylightLength);
                                });
                            } else if (key === 'sunrise') {
                                layout.yaxis.autorange = 'reversed';
                                layout.yaxis.type = 'date';
                                layout.yaxis.tickformat = '%H:%M';

                                dayLightData.forEach((row) => {
                                    if (row.sunriseTime === null) return;
                                    times.push(row.date);
                                    values.push(row.sunriseTime);
                                });
                                values = changeDateInDatetimeArray(values, '1970-01-01');
                            } else if (key === 'sunset') {
                                layout.yaxis.autorange = 'reversed';
                                layout.yaxis.type = 'date';
                                layout.yaxis.tickformat = '%H:%M';

                                dayLightData.forEach((row) => {
                                    if (row.sunsetTime === null) return;
                                    times.push(row.date);
                                    values.push(row.sunsetTime);
                                });
                                values = changeDateInDatetimeArray(values, '1970-01-01');
                            } else {
                                if (data[0][key] === undefined) {
                                    return;
                                }
                                times = data.map(row => new Date(row.time));
                                values = data.map(row => row[key]);
                            }

                            if (values.some(value => value !== null)) {
                                // If the left weather station ID is 5, skip the humidity chart
                                const stationIdParts = weatherStationId.split('_');
                                if (stationIdParts[0] === '5') {
                                    if (key === 'humidity') {
                                        // Not shown because the data is broken. This sensor is not even supposed to support humidity.
                                        return;
                                    }
                                }

                                // Convert wind speed from m/s to km/h
                                if (units === 'm/s' && (key === 'wind' || key === 'wind_avg')) {
                                    values.forEach((value, index) => {
                                        if (value !== null) {
                                            values[index] = (value * 3.6).toFixed(1); // Convert m/s to km/h
                                        }
                                    });

                                    units = 'km/h';
                                }

                                if (key === 'rain') {
                                    const adjustedValues = [];
                                    let runningTotal = 0;
                                    let firstValue = 0;
                                    let previousValue = 0;

                                    values.forEach((value, index) => {
                                        if (value !== null) {
                                            value = parseFloat(value);
                                            if (firstValue === 0) {
                                                firstValue = value;
                                            }
                                            if (index > 0 && value < previousValue) {
                                                // An overflow occurred, add the offset
                                                runningTotal += previousValue;
                                                console.log(`Rain overflow detected. Offset: ${runningTotal}`);
                                            }
                                            // Substract first value to make the rain always start at 0 mm
                                            adjustedValues.push(((value + runningTotal) - firstValue).toFixed(1));
                                        } else {
                                            // Null value, just push it
                                            adjustedValues.push(value);
                                        }

                                        previousValue = value;
                                    });

                                    // Replace the original rain values with adjusted values
                                    values = adjustedValues;
                                }

                                // Convert Light values from Lux to klux
                                if (units === 'lux' && key === 'light') {
                                    values = values.map(value => {
                                        if (value !== null) {
                                            return (value / 1000).toFixed(3); // Convert Lux to klux
                                        } else {
                                            return value;
                                        }
                                    });

                                    units = 'klux'; // Update the units to 'klux' for the Light chart
                                }

                                // Get the last data value for the current chart
                                let lastValue = values[values.length - 1];

                                if (key === 'sunrise' || key === 'sunset') {
                                    lastValue = lastValue.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                }

                                let chartNameWithLastValue;
                                if (name === 'Temperature' || name === 'Humidity') {
                                    chartNameWithLastValue = `${name} (${lastValue}${units})`;
                                } else if (name === 'UV Index' || units === 'Time') {
                                    chartNameWithLastValue = `${name} (${lastValue})`;
                                } else {
                                    chartNameWithLastValue = `${name} (${lastValue} ${units})`;
                                }

                                createPlot(container, key, times, values, name, units, color, chartNameWithLastValue, layout);
                            }
                        });
                    }

                    // Hide the loading screen
                    hideLoading();
                })
                .catch(error => {
                    console.error('Error fetching data:', error);

                    // Hide the loading screen in case of an error
                    hideLoading();
                });
        }

        // Add event listeners to all filter inputs to call applyFiltersOnChange on change
        document.getElementById('weather-station-id').addEventListener('change', applyFilters);
        document.getElementById('last-days').addEventListener('change', applyFilters);
        document.getElementById('last-weeks').addEventListener('change', applyFilters);
        document.getElementById('last-months').addEventListener('change', applyFilters);
        document.getElementById('year').addEventListener('change', applyFilters);

        // Add event listener to apply filters when the page loads
        window.addEventListener('DOMContentLoaded', applyFilters);

        // Update charts on window resize
        window.onresize = function() {
            chartConfigs.forEach(({ key }) => {
                const chartDiv = document.getElementById(`${key}Chart`);
                if (chartDiv) {
                    Plotly.Plots.resize(chartDiv.id);
                }
            });
        };
    </script>
</body>
</html>
