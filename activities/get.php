<?php
/**
 * Get Specific Activity
 * GET activities/{activity_id}
 *
 * Returns specific activity about a specific case
 * Requires authentication
 */

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

// Validate HTTP parameters
if (!isset($_GET['activity_id'])) {
    Response::error('Activity ID is required', 200);
}

$activityId = intval($_GET['activity_id']);

// Validate basic input
if ($activityId <= 0) {
    Response::error('Invalid Activity ID', 200);
}

try {
    $activityList = initializeClass("ActivityModel", $activityId);

    Response::success([
        'activity' => $activityList->activityArr
    ]);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
