<?php
// Database configuration for socket server
// $db_host = '127.0.0.1';
// $db_user = 'root';
// $db_pass = '';
// $db_name = 'delivery_app_db';

$db_host = 'localhost';
$db_user = 'u767890297_testftp';
$db_pass = '$3O4H;NcIHM*aqUk';
$db_name = 'u767890297_testftp';

// Create connection
$socket_conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

// Check connection
if (!$socket_conn) {
    die("Socket Server Connection failed: " . mysqli_connect_error());
}

// Set charset
mysqli_set_charset($socket_conn, "utf8mb4");

// WebSocket Configuration
define('WS_PORT', 8080);
define('WS_HOST', '127.0.0.1');

// SSL Configuration
// define('SSL_CERT_PATH', '/etc/letsencrypt/live/yourdomain.com/fullchain.pem');
// define('SSL_KEY_PATH', '/etc/letsencrypt/live/yourdomain.com/privkey.pem');

// Allow connections from your web domain
define('ALLOWED_ORIGINS', [
    '*',
    'https://techryption.com',
    'https://www.techryption.com',
    'http://localhost',
    'http://127.0.0.1'
]);
