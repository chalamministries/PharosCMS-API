<?php
/**
 * Get Media ZIP Request status
 * GET /api/cases/{case_id}/media-zip-request
 * 
 * Returns the latest media ZIP request status for a specific case
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

// Authenticate user - Allow access if download_token is provided and matches, OR if user has valid JWT
$downloadToken = isset($_GET['download_token']) ? $_GET['download_token'] : null;
$user = null;

if (!$downloadToken) {
    $user = Auth::authenticate();
    if (!$user) {
        exit(); // Auth::authenticate() already sent error response
    }
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
    
    // If accessing via download_token, we must find a record with that token
    // If authenticated via JWT, we return the last record (no token check needed)
    $query = "SELECT request_id, status, download_token, bunny_path, file_size_bytes, error_message, created_at, completed_at, expires_at 
              FROM media_zip_requests 
              WHERE case_id = :case_id ";
    $params = [":case_id" => $caseId];

    if ($downloadToken) {
        $query .= " AND download_token = :token ";
        $params[":token"] = $downloadToken;
    }

    $query .= " ORDER BY created_at DESC LIMIT 1";
    
    $request = $db->queryFirst($query, $params);
    
    if (!$request) {
        Response::success([
            'found' => false,
            'message' => 'No download request found for this case'
        ]);
    } else {
        Response::success([
            'found' => true,
            'request' => $request
        ]);
    }

} catch (Exception $e) {
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}
