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
if (!$trackerId) {
    Response::validationError(['Tracker ID is required']);
}

try {
    $db = getDB();

    // Get the tracker to find its traccar_id
    $tracker = $db->selectFirst("trackers", ["tracker_id" => $trackerId]);
    if (!$tracker) {
        Response::notFound('Tracker not found');
    }

    // Read directly from the new columns in the trackers table
    if ($tracker['last_lat'] && $tracker['last_lng']) {
        $position = [
            'latitude'      => $tracker['last_lat'],
            'longitude'     => $tracker['last_lng'],
            'speed'         => $tracker['last_speed'],
            'heading'       => $tracker['last_heading'],
            'battery_level' => $tracker['last_battery'],
            'recorded_at'   => $tracker['last_updated_at']
        ];
        Response::success(['position' => $position]);
    } else {
        Response::success(['position' => null], 'No position data found');
    }

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}
