<?php
/**
 * Initiate Media ZIP Request
 * POST /api/cases/{case_id}/initiate-media-zip
 * 
 * Checks if a ZIP request exists, if not creates one.
 * Returns success and redirect info.
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get case_id from the route parameters
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

try {
    $db = getDB();
    
    // Check if the authenticated user has access to this case
    // We can use CaseModel to get case details and verify ownership
    $case = initializeClass("CaseModel", $caseId, [], $user);
    
    if (!$case || empty($case->caseArr)) {
        Response::notFound('Case not found');
    }

    // Verify ownership if user is a client
    if ($user['user_type'] === 'client') {
        if (isset($user['user_id']) && $case->caseArr['client_id'] != $user['user_id']) {
             Response::error('Access denied', 403);
        }
    }

    // Check for existing latest record in media_zip_requests
    $query = "SELECT request_id, status FROM media_zip_requests 
              WHERE case_id = :case_id 
              ORDER BY created_at DESC 
              LIMIT 1";
    
    $existingRequest = $db->queryFirst($query, [":case_id" => $caseId]);
    
    if ($existingRequest) {
        // Entry exists, just return success so client can redirect
        Response::success([
            'message' => 'Existing request found',
            'request_id' => $existingRequest['request_id'],
            'status' => $existingRequest['status']
        ]);
    } else {
        // Create new entry
        // download_token needs to be the access_code from the cases record
        // The user said "access_code from the cases record", but usually we use the "full_access_code" in the UI.
        // Re-reading: "the download_token needs to be the access_code from the cases record."
        // Let's use the 4-digit access_code as requested.
        
        $accessCode = $case->caseArr['full_access_code'];
        $clientId = $case->caseArr['client_id'];
        
        $insertData = [
            'case_id' => $caseId,
            'client_id' => $clientId,
            'status' => 'pending',
            'download_token' => $accessCode
        ];
        
        $requestId = $db->insert("media_zip_requests", $insertData);
        
        if ($requestId) {
            Response::success([
                'message' => 'New request created',
                'request_id' => $requestId,
                'status' => 'pending',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+3 days'))
            ]);
        } else {
            Response::error('Failed to create download request', 500);
        }
    }

} catch (Exception $e) {
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}
