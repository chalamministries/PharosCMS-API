<?php
/**
 * List Participants for a Case
 * GET /api/cases/{case_id}/participants
 *
 * Returns all participants for a specific case
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.participantmodel.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get case_id from route parameter
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

// Validate case_id
if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

try {
    $caseModel = initializeClass("CaseModel", $caseId, ['participants']);
    $participants = $caseModel->caseArr['participants'];

    Response::success([
        'participants' => $participants
    ]);

} catch (OutOfBoundsException $e) {
    Response::error('Case not found', 404);
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
