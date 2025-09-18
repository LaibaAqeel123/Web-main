<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'delivery_app_db';

// UK Bounding Box Coordinates
$uk_bounds = [
    'min_lat' => 50.0,
    'max_lat' => 60.0,
    'min_lng' => -8.0,
    'max_lng' => 2.0
];

function generateUKCoordinates($bounds) {
    $lat = $bounds['min_lat'] + mt_rand() / mt_getrandmax() * ($bounds['max_lat'] - $bounds['min_lat']);
    $lng = $bounds['min_lng'] + mt_rand() / mt_getrandmax() * ($bounds['max_lng'] - $bounds['min_lng']);
    return [
        'lat' => round($lat, 6),
        'lng' => round($lng, 6)
    ];
}

// Handle API requests
if (isset($_POST['action'])) {
    $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    $rider_ids = [15, 16, 18];
    $response = [];

    if ($_POST['action'] === 'start') {
        foreach ($rider_ids as $rider_id) {
            $location = generateUKCoordinates($uk_bounds);
            
            $query = "INSERT INTO RidersLocations (rider_id, lat, lng, created_at) 
                     VALUES (?, ?, ?, NOW())";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "idd", $rider_id, $location['lat'], $location['lng']);
            
            if (mysqli_stmt_execute($stmt)) {
                $response[] = [
                    'rider_id' => $rider_id,
                    'lat' => $location['lat'],
                    'lng' => $location['lng']
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Simulation started',
            'locations' => $response
        ]);
    }

    mysqli_close($conn);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rider Location Simulator</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .control-panel {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
        }
        #toggleBtn {
            padding: 10px 20px;
            font-size: 16px;
            margin: 20px 0;
            background: #4F46E5;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        #toggleBtn:hover {
            background: #4338CA;
        }
        #locationDisplay {
            background-color: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            min-height: 200px;
        }
        #connectionStatus {
            margin-bottom: 10px;
            padding: 10px;
            border-radius: 4px;
        }
        .connected {
            background: #dcfce7;
            color: #166534;
        }
        .disconnected {
            background: #fee2e2;
            color: #991b1b;
        }
        .rider-entry {
            background: white;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <h1>Rider Location Simulator</h1>
    
    <div class="container">
        <div class="control-panel">
            <div id="connectionStatus">WebSocket Status: Connecting...</div>
            <button id="toggleBtn">Start Simulation</button>
            <div id="locationDisplay">
                Waiting for simulation to start...
            </div>
        </div>
        <div id="logPanel">
            <h3>Simulation Log</h3>
            <div id="logDisplay" style="height: 300px; overflow-y: auto; padding: 10px; background: #f4f4f4; border-radius: 4px;">
            </div>
        </div>
    </div>

    <script>
    let socket;
    let simulationInterval;
    const toggleBtn = document.getElementById('toggleBtn');
    const locationDisplay = document.getElementById('locationDisplay');
    const connectionStatus = document.getElementById('connectionStatus');
    const logDisplay = document.getElementById('logDisplay');
    let isSimulating = false;

    function initWebSocket() {
        const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        socket = new WebSocket(`${wsProtocol}//${window.location.hostname}:8080`);

        socket.onopen = function() {
            connectionStatus.textContent = '🟢 Connected to WebSocket Server';
            connectionStatus.className = 'connected';
            addLog('Connected to WebSocket server');
        };

        socket.onclose = function() {
            connectionStatus.textContent = '🔴 Disconnected from WebSocket Server';
            connectionStatus.className = 'disconnected';
            addLog('Disconnected from WebSocket server');
            // Try to reconnect after 5 seconds
            setTimeout(initWebSocket, 5000);
        };

        socket.onerror = function(error) {
            addLog('WebSocket Error: ' + error.message);
        };

        socket.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                if (data.type === 'locations_update') {
                    updateLocationDisplay(data.locations);
                }
            } catch (error) {
                addLog('Error processing message: ' + error.message);
            }
        };
    }

    function addLog(message) {
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = document.createElement('div');
        logEntry.textContent = `[${timestamp}] ${message}`;
        logDisplay.appendChild(logEntry);
        logDisplay.scrollTop = logDisplay.scrollHeight;
    }

    toggleBtn.addEventListener('click', function() {
        if (!isSimulating) {
            startSimulation();
            toggleBtn.textContent = 'Stop Simulation';
            isSimulating = true;
            addLog('Simulation started');
        } else {
            stopSimulation();
            toggleBtn.textContent = 'Start Simulation';
            isSimulating = false;
            addLog('Simulation stopped');
        }
    });

    function startSimulation() {
        updateLocations();
        simulationInterval = setInterval(updateLocations, 3000); // Update every 3 seconds
    }

    function stopSimulation() {
        clearInterval(simulationInterval);
        locationDisplay.innerHTML = 'Simulation Stopped';
    }

    function updateLocations() {
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=start'
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success' && data.locations) {
                let displayText = '<h3>Simulated Rider Locations:</h3>';
                data.locations.forEach(location => {
                    displayText += `
                        <div class="rider-entry">
                            <strong>Rider ${location.rider_id}</strong><br>
                            Latitude: ${location.lat}<br>
                            Longitude: ${location.lng}
                        </div>
                    `;
                });
                locationDisplay.innerHTML = displayText;
                
                // Send locations to WebSocket server if connected
                if (socket && socket.readyState === WebSocket.OPEN) {
                    data.locations.forEach(location => {
                        socket.send(JSON.stringify({
                            type: 'update_location',
                            rider_id: location.rider_id,
                            lat: location.lat,
                            lng: location.lng
                        }));
                    });
                    addLog(`Sent ${data.locations.length} location updates`);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            addLog('Error updating locations: ' + error.message);
            locationDisplay.innerHTML = 'Error updating locations';
        });
    }

    function updateLocationDisplay(locations) {
        let displayText = '<h3>Current Rider Locations:</h3>';
        locations.forEach(location => {
            displayText += `
                <div class="rider-entry">
                    <strong>Rider ${location.rider_id}</strong><br>
                    Latitude: ${location.lat}<br>
                    Longitude: ${location.lng}
                </div>
            `;
        });
        locationDisplay.innerHTML = displayText;
    }

    // Initialize WebSocket connection
    initWebSocket();
    </script>
</body>
</html>