<?php
/**
 * Get Single Tracker Endpoint
 * GET /api/trackers/get.php?id={tracker_id}
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

// Validate input
if (!isset($_GET['id'])) {
    Response::error('Tracker ID is required', 400);
}

$trackerId = intval($_GET['id']);

try {
    $trackerModel = initializeClass("TrackerDeviceModel", $trackerId);
    if (!$trackerModel->trackerArr) {
        Response::error('Tracker not found', 404);
    }
    
    // Also get the current assignment if any
    $assignmentModel = initializeClass("TrackerAssignmentModel");
    $assignment = $assignmentModel->getActiveAssignmentByTracker($trackerId);
    
    if ($assignment) {
        $trackerModel->trackerArr['current_assignment'] = $assignment;
    }

    Response::success($trackerModel->trackerArr);

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}
