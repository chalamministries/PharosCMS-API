<?php
/**
 * Get Activities List
 * GET /api/cases/{case_id}/activities
 * 
 * Returns activities about a specific case
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
    exit(); // Auth::authenticate() already sent error response
}

// Validate HTTP parameters
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 200);
}

$caseId = intval($_GET['case_id']);

// Validate basic input
if ($caseId <= 0) {
    Response::error('Invalid case ID', 200);
}

try {
    $activityList = initializeClass("ActivityModel");
    
    $activities = $activityList->getActivitiesByCase($caseId, false);

    Response::success([
        'activities' => $activities
    ]);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
