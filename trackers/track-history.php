<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

$trackerId = $_GET['id'] ?? null;
$startDate = $_GET['start'] ?? null;
$endDate = $_GET['end'] ?? null;

if (!$trackerId) {
    Response::validationError(['Tracker ID is required']);
}
if (!$startDate || !$endDate) {
    Response::validationError(['Start date and end date are required']);
}

try {
    $db = getDB();

    // Get the tracker to find its traccar_id
    $tracker = $db->selectFirst("trackers", ["tracker_id" => $trackerId]);
    if (!$tracker) {
        Response::notFound('Tracker not found');
    }

    $traccarId = $tracker['traccar_id'];
    
    // Query MySQL for history
    $sql = "SELECT latitude, longitude, speed, battery_level, recorded_at 
            FROM tracker_data 
            WHERE tracker_id = ? 
            AND recorded_at BETWEEN ? AND ? 
            ORDER BY recorded_at ASC";
            
    $positions = $db->query($sql, [$traccarId, $startDate, $endDate])->fetchAll();

    Response::success([
        'positions' => $positions ?: [],
        'tracker_name' => $tracker['name']
    ]);

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}
