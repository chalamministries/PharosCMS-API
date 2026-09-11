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

// Optional: override_retrieved_at if user is overriding a non-retrieved deployment
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$overrideRetrievedAt = $input['override_retrieved_at'] ?? null;

try {
    $db = getDB();

    // Verify tracker exists
    $tracker = $db->selectFirst("trackers", ["tracker_id" => $trackerId]);
    if (!$tracker) {
        Response::notFound('Tracker not found');
    }

    // Find active assignment
    $assignment = $db->queryFirst(
        "SELECT assignment_id, case_id FROM tracker_assignments WHERE tracker_id = :tracker_id AND assigned_at IS NOT NULL AND removed_at IS NULL",
        [":tracker_id" => $trackerId]
    );
    if (!$assignment) {
        Response::error('This tracker has no active assignment', 404);
        exit();
    }

    $assignmentId = $assignment['assignment_id'];

    // Check for unretrieved deployments
    $unretreived = $db->queryFirst(
        "SELECT deployment_id FROM tracker_deployments WHERE assignment_id = :assignment_id AND retrieved_at IS NULL",
        [":assignment_id" => $assignmentId]
    );

    if ($unretreived && !$overrideRetrievedAt) {
        // Return a special response indicating the tracker hasn't been retrieved
        Response::success([
            'requires_override' => true,
            'deployment_id'     => $unretreived['deployment_id'],
            'assignment_id'     => $assignmentId
        ], 'Tracker has not been retrieved');
        exit();
    }

    // If there's an unretrieved deployment and user provided override date, mark it retrieved
    if ($unretreived && $overrideRetrievedAt) {
        $db->update("tracker_deployments", [
            'retrieved_at' => $overrideRetrievedAt
        ], ["deployment_id" => $unretreived['deployment_id']]);
    }

    // Mark the assignment as removed
    $db->update("tracker_assignments", [
        'removed_at' => date('Y-m-d H:i:s')
    ], ["assignment_id" => $assignmentId]);

    // Update tracker status back to 'available'
    $db->update("trackers", ['status' => 'available'], ["tracker_id" => $trackerId]);

    // Audit log: TRACKER_UNASSIGN
    $case = $db->selectFirst("cases", ["case_id" => $assignment['case_id']]);
    $auditModel = new AuditModel();
    $auditModel->log('TRACKER_UNASSIGN', $user, $assignment['case_id'], 'tracker', $trackerId, [
        'tracker_name' => $tracker['name'],
        'case_number' => $case['case_number'] ?? 'Unknown'
    ]);

    Response::success([
        'assignment_id' => $assignmentId
    ], 'Tracker unassigned successfully');

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}
