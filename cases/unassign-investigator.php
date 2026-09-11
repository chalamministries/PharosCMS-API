<?php
/**
 * Unassign Investigator Endpoint
 * DELETE /api/cases/{case_id}/assignments/{assignment_id}
 *
 * Unassigns an investigator from a case (sets unassigned_at)
 * If unassigning the lead while support investigators exist,
 * must provide promote_assignment_id to promote a support to lead
 *
 * Requires authentication (admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can unassign investigators
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get IDs from route
if (!isset($_GET['case_id']) || !isset($_GET['assignment_id'])) {
    Response::error('Case ID and Assignment ID are required', 400);
}

$caseId = intval($_GET['case_id']);
$assignmentId = intval($_GET['assignment_id']);

// Get optional JSON input for promotion
$input = Response::getJsonInput() ?: [];
$promoteAssignmentId = isset($input['promote_assignment_id']) ? intval($input['promote_assignment_id']) : null;

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Verify assignment exists and belongs to this case
    $assignment = $pdo->queryFirst(
        "SELECT * FROM case_assignments WHERE assignment_id = :assignment_id AND case_id = :case_id",
        [':assignment_id' => $assignmentId, ':case_id' => $caseId]
    );

    if (!$assignment) {
        Response::error('Assignment not found', 404);
    }

    if ($assignment['unassigned_at'] !== null) {
        Response::error('This investigator is already unassigned', 400);
    }

    // Check if this is the lead being unassigned
    $isLead = ($assignment['role'] === 'lead');

    // Get all active support investigators
    $supportInvestigators = $pdo->query(
        "SELECT * FROM case_assignments
         WHERE case_id = :case_id
         AND role = 'support'
         AND unassigned_at IS NULL",
        [':case_id' => $caseId]
    );

    // If unassigning lead and support exists, must promote one
    if ($isLead && count($supportInvestigators) > 0) {
        if (!$promoteAssignmentId) {
            // Return list of support investigators to choose from
            Response::error('Must promote a support investigator to lead', 400, [
                'require_promotion' => true,
                'support_investigators' => array_map(function($s) use ($pdo) {
                    $inv = $pdo->selectFirst("investigators", ["investigator_id" => $s['investigator_id']]);
                    return [
                        'assignment_id' => $s['assignment_id'],
                        'investigator_id' => $s['investigator_id'],
                        'investigator_name' => $inv['first_name'] . ' ' . $inv['last_name'],
                        'assigned_hourly_rate' => $s['assigned_hourly_rate']
                    ];
                }, $supportInvestigators)
            ]);
        }

        // Verify the promotion target is valid
        $promotionTarget = null;
        foreach ($supportInvestigators as $s) {
            if ($s['assignment_id'] == $promoteAssignmentId) {
                $promotionTarget = $s;
                break;
            }
        }

        if (!$promotionTarget) {
            Response::error('Invalid promotion target. Must be an active support investigator on this case.', 400);
        }

        // Promote the support to lead
        $pdo->update('case_assignments', [
            'role' => 'lead',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['assignment_id' => $promoteAssignmentId]);

        // Update cases table with new lead
        $pdo->update('cases', [
            'investigator_id' => $promotionTarget['investigator_id']
        ], ['case_id' => $caseId]);
    }

    // Unassign the investigator
    $pdo->update('case_assignments', [
        'unassigned_at' => date('Y-m-d H:i:s')
    ], ['assignment_id' => $assignmentId]);

    // If this was the lead and no support to promote, clear case investigator_id
    if ($isLead && count($supportInvestigators) === 0) {
        $pdo->update('cases', [
            'investigator_id' => null
        ], ['case_id' => $caseId]);
    }

    // Get investigator name for response
    $investigator = $pdo->selectFirst("investigators", ["investigator_id" => $assignment['investigator_id']]);
    $investigatorName = $investigator['first_name'] . ' ' . $investigator['last_name'];

    // Audit log: UNASSIGN
    $auditModel = new AuditModel();
    $auditModel->log('CASE_UPDATE', $user, $caseId, 'case', $caseId, [
        'field_name' => 'investigator',
        'field_value' => 'Unassigned: ' . $investigatorName
    ]);

    $response = [
        'message' => "Investigator {$investigatorName} unassigned successfully"
    ];

    // If we promoted someone, include that info
    if ($promoteAssignmentId && isset($promotionTarget)) {
        $promotedInv = $pdo->selectFirst("investigators", ["investigator_id" => $promotionTarget['investigator_id']]);
        $promotedName = $promotedInv['first_name'] . ' ' . $promotedInv['last_name'];
        
        $auditModel->log('CASE_ASSIGN', $user, $caseId, 'case', $caseId, [
            'case_number' => $case['case_number'],
            'investigator' => $promotedName
        ]);

        $response['promoted'] = [
            'investigator_id' => $promotionTarget['investigator_id'],
            'investigator_name' => $promotedInv['first_name'] . ' ' . $promotedInv['last_name']
        ];
        $response['message'] .= ". {$promotedInv['first_name']} {$promotedInv['last_name']} promoted to lead.";
    }

    Response::success($response);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
