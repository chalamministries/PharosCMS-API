<?php
/**
 * Add Case Assignment Endpoint
 * POST /api/cases/{case_id}/assignments
 *
 * Adds an investigator assignment to a case
 * Used by the case creation wizard to assign investigators
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

// Only admins can assign investigators
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
	Response::error('Unauthorized. Admin access required.', 403);
}

// Get case ID from route
if (!isset($_GET['case_id'])) {
	Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['investigator_id'])) {
	Response::error('investigator_id is required', 400);
}

$investigatorId = intval($input['investigator_id']);
$role = isset($input['role']) ? $input['role'] : 'lead';
$rate = isset($input['assigned_hourly_rate']) ? floatval($input['assigned_hourly_rate']) : null;

// Validate role
if (!in_array($role, ['lead', 'support'])) {
	Response::error('Invalid role. Must be: lead or support', 400);
}

try {
	$pdo = $GLOBALS['pdo'];

	// Verify case exists
	$case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
	if (!$case) {
		Response::error('Case not found', 404);
	}

	// Verify investigator exists
	$investigator = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);
	if (!$investigator) {
		Response::error('Investigator not found', 404);
	}

	// Use investigator's default rate if not specified
	if ($rate === null) {
		$rate = $investigator['hourly_rate'] ?? 45.00;
	}

	// If assigning as lead, check if there's already a lead and unassign them
	if ($role === 'lead') {
		$currentLead = $pdo->queryFirst(
			"SELECT assignment_id, investigator_id FROM case_assignments
			 WHERE case_id = :case_id AND role = 'lead' AND unassigned_at IS NULL",
			[':case_id' => $caseId]
		);

		if ($currentLead && $currentLead['investigator_id'] != $investigatorId) {
			// Unassign current lead
			$pdo->update('case_assignments', [
				'unassigned_at' => date('Y-m-d H:i:s')
			], ['assignment_id' => $currentLead['assignment_id']]);
		}
	}

	// If assigning as support, verify there's a lead
	if ($role === 'support') {
		$hasLead = $pdo->queryFirst(
			"SELECT assignment_id FROM case_assignments
			 WHERE case_id = :case_id AND role = 'lead' AND unassigned_at IS NULL",
			[':case_id' => $caseId]
		);

		if (!$hasLead) {
			Response::error('Cannot assign support investigator without a lead investigator', 400);
		}
	}

	// Check if investigator already assigned to this case
	$existingAssignment = $pdo->queryFirst(
		"SELECT assignment_id, role FROM case_assignments
		 WHERE case_id = :case_id AND investigator_id = :investigator_id AND unassigned_at IS NULL",
		[':case_id' => $caseId, ':investigator_id' => $investigatorId]
	);

	if ($existingAssignment) {
		// Update existing assignment (e.g., promoting support to lead or changing rate)
		$pdo->update('case_assignments', [
			'role' => $role,
			'assigned_hourly_rate' => $rate,
			'assigned_at' => date('Y-m-d H:i:s'),
			'assigned_by' => $user['admin_id'] ?? $user['user_id'] ?? null
		], ['assignment_id' => $existingAssignment['assignment_id']]);

		$assignmentId = $existingAssignment['assignment_id'];
	} else {
		// Create new assignment
		$assignmentData = [
			'case_id' => $caseId,
			'investigator_id' => $investigatorId,
			'role' => $role,
			'assigned_hourly_rate' => $rate,
			'assigned_at' => date('Y-m-d H:i:s'),
			'assigned_by' => $user['admin_id'] ?? $user['user_id'] ?? null
		];

		$assignmentId = $pdo->insert('case_assignments', $assignmentData);
	}

	// If this is a lead assignment, update the case investigator_id

	if ($role === 'lead') {

        $newData = array('investigator_id' => $investigatorId);
        if($case['status'] == "new") {
            $newData['status'] = "assigned";
        }
		$pdo->update('cases', $newData, ['case_id' => $caseId]);
	}

	// Fetch the assignment
	$assignment = $pdo->selectFirst("case_assignments", ["assignment_id" => $assignmentId]);

	// Audit log: CASE_ASSIGN
	$auditModel = new AuditModel();
	$auditModel->log('CASE_ASSIGN', $user, $caseId, 'case', $caseId, [
		'case_number' => $case['case_number'],
		'investigator' => $investigator['first_name'] . ' ' . $investigator['last_name']
	]);

	Response::success([
		'assignment' => $assignment,
		'investigator_name' => $investigator['first_name'] . ' ' . $investigator['last_name'],
		'message' => 'Investigator assigned successfully'
	], 201);

} catch (Exception $e) {
	Response::serverError('An error occurred: ' . $e->getMessage());
}
