<?php
/**
 * Get Unlinked Media for a Case
 * GET /api/media/get_unlinked.php?case_id=X
 * 
 * Returns media for a specific case that is NOT yet linked to an activity or time entry.
 * Requires investigator or admin authentication.
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

// Ensure it's an investigator or admin
if ($user['user_type'] !== 'investigator' && $user['user_type'] !== 'admin') {
    Auth::forbidden('Access denied');
}

// Validate HTTP parameters
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Fetch unlinked media
    // entry_id OR activity_id is NULL
    $query = "SELECT media_id, case_id, activity_id, entry_id, file_path, file_type, 
                     video_thumb, description, is_client_viewable, is_investigator_viewable, uploaded_at
              FROM media 
              WHERE case_id = :case_id 
                AND (entry_id IS NULL AND activity_id IS NULL)
              ORDER BY uploaded_at DESC";
    
    $media = $pdo->query($query, [':case_id' => $caseId]);

    Response::success([
        'media' => $media ?: []
    ]);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
