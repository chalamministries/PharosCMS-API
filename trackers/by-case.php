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

$caseId = $_GET['case_id'] ?? $_GET['id'] ?? null;
if (!$caseId) {
    Response::validationError(['Case ID is required']);
}

try {
    $db = getDB();

    // Get trackers assigned to this case with their last known position
    $query = "
        SELECT t.tracker_id,
               t.name,
               t.unique_id,
               t.device_id,
               t.traccar_id,
               t.sms_phone_number,
               t.status,
               tm.model_name,
               tb.brand_name,
               ta.assigned_at,
               (SELECT td.latitude FROM tracker_data td
                WHERE td.tracker_id = t.traccar_id
                ORDER BY td.recorded_at DESC LIMIT 1) AS last_lat,
               (SELECT td.longitude FROM tracker_data td
                WHERE td.tracker_id = t.traccar_id
                ORDER BY td.recorded_at DESC LIMIT 1) AS last_lng,
               (SELECT td.recorded_at FROM tracker_data td
                WHERE td.tracker_id = t.traccar_id
                ORDER BY td.recorded_at DESC LIMIT 1) AS last_position_at,
               (SELECT td.speed FROM tracker_data td
                WHERE td.tracker_id = t.traccar_id
                ORDER BY td.recorded_at DESC LIMIT 1) AS last_speed,
               (SELECT td.battery_level FROM tracker_data td
                WHERE td.tracker_id = t.traccar_id
                ORDER BY td.recorded_at DESC LIMIT 1) AS last_battery
        FROM trackers t
        INNER JOIN tracker_assignments ta ON t.tracker_id = ta.tracker_id
            AND ta.assigned_at IS NOT NULL
            AND ta.removed_at IS NULL
        LEFT JOIN tracker_model tm ON t.device_model = tm.model_id
        LEFT JOIN tracker_brand tb ON tm.brand_id = tb.brand_id
        WHERE ta.case_id = :case_id
        ORDER BY ta.assigned_at DESC
    ";

    $trackers = $db->query($query, [":case_id" => $caseId]);

    Response::success([
        'trackers' => $trackers ?: []
    ]);

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}