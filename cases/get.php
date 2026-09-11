<?php
/**
 * Get Single Case Endpoint
 * GET /api/cases/{case_id}
 * 
 * Returns detailed information about a specific case
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
if (!isset($_GET['id'])) {
    Response::error('Case ID is required', 428);
}

$caseId = intval($_GET['id']);

// Validate basic input
if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

$include = isset($_GET['include']) ? $_GET['include'] : [];


if(!is_array($include)) {
    $include = explode(",", $include);
}

try {
    $case = initializeClass("CaseModel", $caseId, $include, $user);
    
    // Optional: Check if user has permission to view this case
    // if (!$case->userHasAccess($user['id'])) {
    //     Response::error('Access denied', 403);
    // }
    
    Response::success($case->caseArr);
    
} catch (OutOfBoundsException $e) {
    // Case doesn't exist
    Response::notFound('Case not found');
    
} catch (InvalidArgumentException | OutOfRangeException $e) {
    // Should rarely happen since we validated above, but just in case
    Response::error($e->getMessage(), 400);
    
} catch (Exception $e) {
    // Server error
    //error_log("Error loading case {$caseId}: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
