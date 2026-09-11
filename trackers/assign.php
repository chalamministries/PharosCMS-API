<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

$trackerId = $_GET['id'] ?? null;
if (!$trackerId) {
    Response::validationError(['Tracker ID is required']);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    Response::validationError(['Invalid JSON input']);
}

$caseId = $input['case_id'] ?? null;
if (!$caseId) {
    Response::validationError(['Case ID is required']);
}

try {
    $db = getDB();

    // Verify tracker exists
    $tracker = $db->selectFirst("trackers", ["tracker_id" => $trackerId]);
    if (!$tracker) {
        Response::notFound('Tracker not found');
    }

    if ($tracker['status'] === 'retired' || $tracker['status'] === 'unavailable') {
        Response::error('This tracker is ' . $tracker['status'] . ' and cannot be assigned.', 400);
    }

    // Verify case exists
    $case = $db->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::notFound('Case not found');
    }

    // Check if tracker already has an active assignment
    $existing = $db->queryFirst(
        "SELECT assignment_id FROM tracker_assignments WHERE tracker_id = :tracker_id AND assigned_at IS NOT NULL AND removed_at IS NULL",
        [":tracker_id" => $trackerId]
    );
    if ($existing) {
        Response::error('This tracker is already assigned to a case', 409);
        exit();
    }

    // Create the assignment
    $assignmentId = $db->insert("tracker_assignments", [
        'tracker_id' => $trackerId,
        'case_id'    => $caseId
    ]);

    // Update tracker status to 'assigned'
    $db->update("trackers", ['status' => 'assigned'], ["tracker_id" => $trackerId]);

    // Audit log: TRACKER_ASSIGN
    $auditModel = new AuditModel();
    $auditModel->log('TRACKER_ASSIGN', $user, $caseId, 'tracker', $trackerId, [
        'tracker_name' => $tracker['name'],
        'case_number' => $case['case_number']
    ]);

    Response::success([
        'assignment_id' => $assignmentId
    ], 'Tracker assigned to case successfully');

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}
