<?php

/**
 * Traccar Command Interceptor
 * Receives: phone=7026187610&message=6690000
 * Token via URL: /trackers/command/{token}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Response.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Token validation via route parameter
$token = $_GET['token'] ?? null;
$SECRET_TOKEN = '78fe4a04-ee1b-4c0e-bca4-3c919bd0b217';

// Set up log file early for auth failures
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/traccar-commands.log';

if (!$token || $token !== $SECRET_TOKEN) {
    file_put_contents($log_file, sprintf("[%s] ERROR: Invalid token from %s\n", date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR']), FILE_APPEND | LOCK_EX);
    Response::error('Forbidden', 403);
}

// Traccar sends: application/x-www-form-urlencoded
// Format: phone=7026187610&message=6690000
$phone = $_POST['phone'] ?? null;
$message = $_POST['message'] ?? null;

if (empty($phone) || empty($message)) {
    Response::validationError(['phone and message are required']);
}

// Log the command to file
$log_entry = sprintf(
    "[%s] IP: %s | Phone: %s | Command: %s\n",
    date('Y-m-d H:i:s'),
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $phone,
    $message
);
file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

$db = getDB();

// Get Tracker Record
$tracker = $db->selectFirst('trackers', ['unique_id' => $phone]);

if (!$tracker) {
    Response::error('Tracker not found', 404);
    $log_entry = sprintf(
        "Tracker %s not found\n",
        $phone
    );
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Get Sim Carrier
$simCarrier = $db->selectFirst('sim_carrier', ['carrier_id' => $tracker['sim_carrier']]);

if (!$simCarrier) {
    $log_entry = "SIM carrier not found for this tracker\n" ;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    Response::error('SIM carrier not found for this tracker', 404);
}

// Insert pending log entry
$logId = $db->insert('tracker_sms_log', [
    'tracker_id'   => $tracker['tracker_id'],
    'sim_carrier'  => $tracker['sim_carrier'],
    'device_id'    => $tracker['device_id'],
    'message_sent' => $message,
    'status'       => 'pending'
]);

if ($simCarrier['carrier_name'] == 'SimpleXWireless') {

    // Step 1: Authenticate with SimpleXWireless
    $loginCh = curl_init('https://svcs.simplexwireless.com:2443/v1/user/login');
    curl_setopt_array($loginCh, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: HTTPBot/2026.0.1',
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'username' => $_ENV['SIMPLEX_USERNAME'],
            'password' => $_ENV['SIMPLEX_PASSWORD']
        ]),
        CURLOPT_TIMEOUT        => 15
    ]);

    $loginResponse = curl_exec($loginCh);
    $loginHttpCode = curl_getinfo($loginCh, CURLINFO_HTTP_CODE);
    $loginError = curl_error($loginCh);
    curl_close($loginCh);

    if ($loginError || $loginHttpCode !== 200) {
        $db->update('tracker_sms_log', ['status' => 'failed'], ['log_id' => $logId]);
        file_put_contents($log_file, sprintf("[%s] ERROR: SimpleXWireless login failed: HTTP %s - %s\n", date('Y-m-d H:i:s'), $loginHttpCode, $loginError), FILE_APPEND | LOCK_EX);
        Response::error('Failed to authenticate with SMS carrier', 502);
    }

    $loginData = json_decode($loginResponse, true);
    $apiToken = $loginData['token'] ?? null;

    if (!$apiToken) {
        $db->update('tracker_sms_log', ['status' => 'failed'], ['log_id' => $logId]);
        Response::error('Failed to obtain carrier API token', 502);
    }

    // Step 2: Send SMS via SimpleXWireless
    $smsCh = curl_init('https://svcs.simplexwireless.com:2443/v1/sendSMS');
    curl_setopt_array($smsCh, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: HTTPBot/2026.0.1',
            'Content-Type: application/json',
            'X-Authorization: ' . $apiToken
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'smstext' => $message,
            'iccid'   => $tracker['device_id']
        ]),
        CURLOPT_TIMEOUT        => 15
    ]);

    $smsResponse = curl_exec($smsCh);
    $smsHttpCode = curl_getinfo($smsCh, CURLINFO_HTTP_CODE);
    $smsError = curl_error($smsCh);
    curl_close($smsCh);

    if ($smsError || $smsHttpCode !== 200) {
        $db->update('tracker_sms_log', ['status' => 'failed'], ['log_id' => $logId]);
        file_put_contents($log_file, sprintf("[%s] ERROR: SimpleXWireless sendSMS failed: HTTP %s - %s\n", date('Y-m-d H:i:s'), $smsHttpCode, $smsError), FILE_APPEND | LOCK_EX);
        Response::error('Failed to send SMS via carrier', 502);
    }

    $smsData = json_decode($smsResponse, true);
    $externalMessageId = $smsData['messageid'] ?? null;

    file_put_contents($log_file, json_encode($smsData), FILE_APPEND | LOCK_EX);

    // Update log with message ID and mark as sent
    $db->update('tracker_sms_log', [
        'external_message_id' => $externalMessageId,
        'status'              => 'sent'
    ], ['log_id' => $logId]);

    Response::success([
        'phone'      => $phone,
        'message'    => $message,
        'message_id' => $externalMessageId,
        'carrier'    => 'SimpleXWireless'
    ], 'Command sent successfully');

} else {
    // Unsupported carrier - mark as failed
    $db->update('tracker_sms_log', ['status' => 'failed'], ['log_id' => $logId]);
    Response::error('Unsupported SIM carrier: ' . $simCarrier['name'], 400);
}
