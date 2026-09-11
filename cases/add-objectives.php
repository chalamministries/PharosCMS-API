<?php
/**
 * Add Case Objectives Endpoint (Batch)
 * POST /api/cases/{case_id}/objectives
 *
 * Creates multiple objectives for a case in a single request
 * Used by the case creation wizard to save all objectives at once
 *
 * Requires authentication (admin only)
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

// Only admins can create objectives
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

// Validate objectives array
if (!isset($input['objectives']) || !is_array($input['objectives'])) {
	Response::error('objectives array is required', 400);
}

if (empty($input['objectives'])) {
	Response::error('At least one objective is required', 400);
}

// Valid priorities
$validPriorities = ['low', 'medium', 'high', 'urgent'];

try {
	$pdo = $GLOBALS['pdo'];

	// Verify case exists
	$case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
	if (!$case) {
		Response::error('Case not found', 404);
	}

	$createdObjectives = [];
	$errors = [];

	foreach ($input['objectives'] as $index => $obj) {
		// Validate required fields
		if (!isset($obj['objective_title']) || empty(trim($obj['objective_title']))) {
			$errors[] = "Objective #" . ($index + 1) . " is missing a title";
			continue;
		}

		$title = trim($obj['objective_title']);
		$description = isset($obj['objective_description']) ? trim($obj['objective_description']) : null;
		$priority = isset($obj['priority']) ? $obj['priority'] : 'medium';
		$estimatedHours = isset($obj['estimated_hours']) ? floatval($obj['estimated_hours']) : null;
		$dueDate = isset($obj['due_date']) && !empty($obj['due_date']) ? $obj['due_date'] : null;
		$sortOrder = $index + 1;

		// Validate priority
		if (!in_array($priority, $validPriorities)) {
			$priority = 'medium';
		}

		// Insert objective
		$objectiveData = [
			'case_id' => $caseId,
			'objective_title' => $title,
			'objective_description' => $description,
			'priority' => $priority,
			'status' => 'draft',
			'estimated_hours' => $estimatedHours,
			'due_date' => $dueDate,
			'sort_order' => $sortOrder,
			'created_at' => date('Y-m-d H:i:s'),
			'updated_at' => date('Y-m-d H:i:s')
		];

		$objectiveId = $pdo->insert('objectives', $objectiveData);

		if ($objectiveId) {
			$objective = $pdo->selectFirst("objectives", ["objective_id" => $objectiveId]);
			$createdObjectives[] = $objective;
		} else {
			$errors[] = "Failed to create objective #" . ($index + 1) . ": " . $title;
		}
	}

	// Return results
	if (count($createdObjectives) > 0) {
		$response = [
			'objectives' => $createdObjectives,
			'created_count' => count($createdObjectives),
			'message' => count($createdObjectives) . ' objective(s) created successfully'
		];

		if (count($errors) > 0) {
			$response['warnings'] = $errors;
		}

		Response::success($response, 201);
	} else {
		Response::error('Failed to create any objectives: ' . implode(', ', $errors), 400);
	}

} catch (Exception $e) {
	Response::serverError('An error occurred: ' . $e->getMessage());
}
