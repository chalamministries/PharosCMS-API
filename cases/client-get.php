<?php
/**
 * Get Client Case Data Endpoint
 * GET /api/cases/client-case/{case_id}
 * 
 * Returns data specifically for the client portal:
 * - Case details
 * - Objectives with investigators
 * - Media
 * 
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

// Get case_id from the route parameters (passed via $_GET by the router)
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

try {
    // We want to include: objectives, investigators, and media
    // CaseModel's getCase handles these when passed in the include array
    $include = ['objectives', 'assignments', 'media', 'messages'];
    
    $case = initializeClass("CaseModel", $caseId, $include, $user);
    
    // Check if the authenticated user has access to this case
    // For clients, we should verify their client_id matches the case's client_id
    if ($user['user_type'] === 'client') {
        // In the JWT token, user_id is the client_id for clients
        if (isset($user['user_id']) && $case->caseArr['client_id'] != $user['user_id']) {
             Response::error('Access denied', 403);
        }
    }

    // Filter media for client viewable only
    $media = $case->caseArr['media'] ?? [];
    $filteredMedia = array_values(array_filter($media, function($item) {
        return isset($item['is_client_viewable']) && $item['is_client_viewable'] == 1;
    }));

    // Format the data for the client portal
    $data = [
        'case_id' => $case->caseArr['case_id'],
        'case_number' => $case->caseArr['case_number'],
        'case_title' => $case->caseArr['case_title'] ?? $case->caseArr['case_number'],
        'status' => $case->caseArr['case_status'],
        'objectives' => $case->caseArr['objectives'] ?? [],
        'media' => $filteredMedia,
        'assignments' => $case->caseArr['assignments'] ?? [],
        'messages' => $case->caseArr['messages'] ?? []
    ];

    // Process investigators to get "First Initial Last Name"
    $investigators = [];
    foreach ($data['assignments'] as $assignment) {
        $firstName = $assignment['first_name'] ?? '';
        $lastName = $assignment['last_name'] ?? '';
        $initial = !empty($firstName) ? substr($firstName, 0, 1) . '. ' : '';
        $investigators[] = [
            'name' => $initial . $lastName,
            'role' => $assignment['role']
        ];
    }
    $data['investigators_formatted'] = $investigators;

    Response::success($data);

} catch (OutOfBoundsException $e) {
    Response::notFound('Case not found');
} catch (Exception $e) {
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}
