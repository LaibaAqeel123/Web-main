<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/websocket_error.log');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/socket_config.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class LocationServer implements MessageComponentInterface {
    protected $clients;
    protected $locations;
    protected $conn;

    public function __construct($db_connection) {
        $this->clients = new \SplObjectStorage;
        $this->locations = [];
        $this->conn = $db_connection;
        error_log("Location Server Started on " . WS_HOST . ":" . WS_PORT);
        error_log("Server Configuration: " . print_r([
            'host' => WS_HOST,
            'port' => WS_PORT,
            'allowed_origins' => ALLOWED_ORIGINS
        ], true));
        echo "Location Server Started!\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $headers = $conn->httpRequest->getHeaders();
        error_log("New connection attempt. Headers: " . print_r($headers, true));
        
        // Log connection details
        error_log("Connection Details: " . print_r([
            'resourceId' => $conn->resourceId,
            'remoteAddress' => $conn->remoteAddress,
            'headers' => $headers
        ], true));

        $this->clients->attach($conn);
        error_log("Client connected successfully: {$conn->resourceId}");
        
        // Send initial locations
        $locations = $this->getLatestLocations();
        error_log("Sending initial locations to client {$conn->resourceId}");
        
        $conn->send(json_encode([
            'type' => 'initial',
            'locations' => $locations
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg);
        
        if ($data && isset($data->type)) {
            switch ($data->type) {
                case 'update_location':
                    if (isset($data->rider_id, $data->lat, $data->lng)) {
                        $this->updateRiderLocation($data, $from);
                    }
                    break;
                    
                case 'request_locations':
                    $locations = $this->getLatestLocations();
                    $from->send(json_encode([
                        'type' => 'locations_update',
                        'locations' => $locations
                    ]));
                    break;
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        error_log("Client disconnected: {$conn->resourceId}");
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        error_log("Error occurred for client {$conn->resourceId}: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        $conn->close();
    }

    protected function updateRiderLocation($data, $from) {
        $stmt = $this->conn->prepare("
            INSERT INTO RidersLocations (rider_id, lat, lng, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->bind_param("idd", $data->rider_id, $data->lat, $data->lng);
        
        if ($stmt->execute()) {
            $locations = $this->getLatestLocations();
            // Broadcast to all connected clients
            foreach ($this->clients as $client) {
                $client->send(json_encode([
                    'type' => 'locations_update',
                    'locations' => $locations
                ]));
            }
            echo "Location updated for rider {$data->rider_id}\n";
        } else {
            echo "Error updating location: " . $stmt->error . "\n";
        }
        
        $stmt->close();
    }

    protected function getLatestLocations() {
        $query = "
            SELECT DISTINCT 
                rl.*,
                u.name as rider_name,
                COALESCE(rc.company_id, u.company_id) as company_id
            FROM RidersLocations rl
            INNER JOIN (
                SELECT rider_id, MAX(created_at) as latest_location
                FROM RidersLocations
                GROUP BY rider_id
            ) latest ON rl.rider_id = latest.rider_id 
                AND rl.created_at = latest.latest_location
            INNER JOIN Users u ON rl.rider_id = u.id
            LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id 
                AND rc.is_active = 1
            WHERE u.user_type = 'Rider'
            AND u.is_active = 1
        ";
    
        $result = mysqli_query($this->conn, $query);
        if (!$result) {
            error_log("Query error: " . mysqli_error($this->conn));
            return [];
        }
        
        $locations = [];
        while ($row = mysqli_fetch_assoc($result)) {
            // Convert IDs to integers for consistent comparison
            $row['rider_id'] = (int)$row['rider_id'];
            $row['company_id'] = (int)$row['company_id'];
            $locations[] = $row;
        }
        
        return $locations;
    }
}

// Create WebSocket server with SSL
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new LocationServer($socket_conn)
        )
    ),
    WS_PORT,
    WS_HOST
);

echo "WebSocket server starting on " . WS_HOST . ":" . WS_PORT . " with " . 
    (defined('SSL_CERT_PATH') ? "SSL" : "no SSL") . "\n";
$server->run();