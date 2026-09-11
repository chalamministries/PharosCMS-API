<?php

require_once 'config.php';

header('Content-Type: application/json');

$json_input = file_get_contents('php://input');

$data = json_decode($json_input, true);

// Validate that we received data
if (empty($data) || !isset($data['position'])) {
	http_response_code(400);
	echo json_encode(['error' => 'Invalid data format']);
	exit;
}


// Extract the position data
$position = $data['position'];

// Generate a unique ID (you can customize this)
$unique_id = $data['device']['uniqueId'];

// Get device ID
$device_id = isset($position['deviceId']) ? (int)$position['deviceId'] : 0;
	
$db = getDB();

// Determine battery field from tracker model
$battery_field = 'io5'; // Default fallback
if ($device_id > 0) {
    $tracker = $db->selectFirst("trackers", ["traccar_id" => $device_id]);
    if ($tracker && !empty($tracker['device_model'])) {
        $model = $db->selectFirst("tracker_model", ["model_id" => $tracker['device_model']]);
        if ($model && !empty($model['battery_field'])) {
            $battery_field = $model['battery_field'];
        }
    }
}

// Get battery level from attributes
$battery_level = isset($position['attributes'][$battery_field]) ? $position['attributes'][$battery_field] : null;
// If the custom battery field didn't exist, try io5 as a secondary fallback
if ($battery_level === null && $battery_field !== 'io5') {
    $battery_level = isset($position['attributes']['io5']) ? $position['attributes']['io5'] : null;
}

$resp = $db->insert("tracker_data",
    array("tracker_id" => $device_id,
        "latitude" => $position['latitude'],
        "longitude" => $position['longitude'],
        "speed" => $position['speed'],
        "altitude" => $position['altitude'],
        "battery_level" => $battery_level,
        "recorded_at" => date("Y-m-d H:i:s"),
        "json" => json_encode($data))
);

error_log("Response" . $resp);