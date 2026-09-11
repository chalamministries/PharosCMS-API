<?php
/**
 * Get Client Media List
 * GET /api/media/client-list.php?case_id=X
 * 
 * Returns media for a specific case that is marked as client-viewable
 * Requires client authentication
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

// Ensure it's a client or admin
if ($user['user_type'] !== 'client' && $user['user_type'] !== 'admin') {
    Auth::forbidden('Access denied');
}

// Validate HTTP parameters
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 200);
}

$caseId = intval($_GET['case_id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 200);
}

try {
    $caseModel = initializeClass("CaseModel", $caseId);
    $caseData = $caseModel->caseArr;

    if (empty($caseData)) {
        Response::error('Case not found', 200);
    }

    // Security: Ensure the client owns this case
    if ($user['user_type'] === 'client' && intval($caseData['client_id']) !== intval($user['user_id'])) {
        Auth::forbidden('Access denied: You do not have permission for this case');
    }

    $mediaModel = initializeClass("MediaModel");
    
    // Use the specialized method for clients
    $media = $mediaModel->getClientViewableMedia($caseId);

    Response::success([
        'media' => $media
    ]);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
