<?php
function writeLog($filename, $message) {
    $date = date("Y-m-d H:i:s");
    $logMessage = "[$date] $message" . PHP_EOL;

    // Absolute path to the logs directory
    $logDir = __DIR__; // This already points to /server/logs
    $logPath = $logDir . '/' . $filename;

    file_put_contents($logPath, $logMessage, FILE_APPEND | LOCK_EX);
}
?>
