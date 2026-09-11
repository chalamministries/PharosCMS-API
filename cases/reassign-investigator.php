<?php
/**
 * Reassign Primary Investigator Endpoint
 * POST /api/cases/{id}/assign
 *
 * Reassigns the primary investigator on a case
 * - Unassigns current lead investigator (sets unassigned_at)
 * - Creates new lead assignment for new investigator
 * - Updates cases table investigator_id
 *
 * Requires authentication (admin only)
 */

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

// Only admins can reassign investigators
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get case ID from route
if (!isset($_GET['id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['id']);

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['investigator_id'])) {
    Response::error('investigator_id is required', 400);
}

$newInvestigatorId = intval($input['investigator_id']);

// Validate IDs
if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

if ($newInvestigatorId <= 0) {
    Response::error('Invalid investigator ID', 400);
}

try {
    $pdo = $GLOBALS['pdo'];
    $assignmentModel = initializeClass("AssignmentModel");

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Verify new investigator exists
    $investigator = $pdo->selectFirst("investigators", ["investigator_id" => $newInvestigatorId]);
    if (!$investigator) {
        Response::error('Investigator not found', 404);
    }

    // Step 1: Find and unassign current lead investigator(s) - only if they exist
    $currentLeadQuery = "
        SELECT assignment_id, investigator_id
        FROM case_assignments
        WHERE case_id = :case_id
        AND role = 'lead'
        AND unassigned_at IS NULL
    ";
    $currentLeads = $pdo->query($currentLeadQuery, [':case_id' => $caseId]);

    // Check if new investigator is already the lead
    foreach ($currentLeads as $lead) {
        if ($lead['investigator_id'] == $newInvestigatorId) {
            Response::error('This investigator is already the lead on this case', 400);
        }
    }

    // Unassign current lead(s) only if there are any
    if (count($currentLeads) > 0) {
        foreach ($currentLeads as $lead) {
            // Use AssignmentModel to unassign
            try {
                $assignment = initializeClass("AssignmentModel", $lead['assignment_id']);
                $assignment->unassign();
            } catch (Exception $e) {
                // If AssignmentModel fails, use direct update
                $pdo->update(
                    'case_assignments',
                    ['unassigned_at' => date('Y-m-d H:i:s')],
                    ['assignment_id' => $lead['assignment_id']]
                );
            }
        }
    }

    // Step 2: Check if new investigator has a current ACTIVE assignment (as support role)
    $activeAssignmentQuery = "
        SELECT assignment_id
        FROM case_assignments
        WHERE case_id = :case_id
        AND investigator_id = :investigator_id
        AND unassigned_at IS NULL
    ";
    $activeAssignment = $pdo->queryFirst($activeAssignmentQuery, [
        ':case_id' => $caseId,
        ':investigator_id' => $newInvestigatorId
    ]);

    if ($activeAssignment) {
        // Investigator is currently active on this case (e.g., as support) - update to lead
        $pdo->update(
            'case_assignments',
            [
                'role' => 'lead',
                'assigned_at' => date('Y-m-d H:i:s'),
                'assigned_by' => $user['user_id']
            ],
            ['assignment_id' => $activeAssignment['assignment_id']]
        );
    } else {
        // Step 3: Create NEW lead assignment record (preserves audit trail of previous assignments)
        $assignmentData = [
            'case_id' => $caseId,
            'investigator_id' => $newInvestigatorId,
            'role' => 'lead',
            'assigned_at' => date('Y-m-d H:i:s'),
            'assigned_by' => $user['user_id'],
            'assigned_hourly_rate' => $investigator['hourly_rate']
        ];

        $newAssignmentId = $pdo->insert('case_assignments', $assignmentData);

        if (!$newAssignmentId) {
            Response::serverError('Failed to create assignment record');
        }
    }

    // Step 4: Update cases table investigator_id
    $newData = array('investigator_id' => $newInvestigatorId);

    // Just in case the status was not set to "assigned" or higher
    if($case['status'] == "new") {
        $newData['status'] = "assigned";
    }

    $pdo->update(
        'cases',
        $newData,
        ['case_id' => $caseId]
    );

    // Audit log: CASE_ASSIGN
    $auditModel = new AuditModel();
    $auditModel->log('CASE_ASSIGN', $user, $caseId, 'case', $caseId, [
        'case_number' => $case['case_number'],
        'investigator' => $investigator['first_name'] . ' ' . $investigator['last_name']
    ]);

    // Fetch updated case data
    $updatedCase = $pdo->selectFirst("cases", ["case_id" => $caseId]);

    // Get investigator name for response
    $investigatorName = $investigator['first_name'] . ' ' . $investigator['last_name'];

    Response::success([
        'case' => $updatedCase,
        'investigator_name' => $investigatorName,
        'message' => 'Primary investigator reassigned successfully'
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
