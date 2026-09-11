<?php
/**
 * Create Draft Case Endpoint
 * POST /api/cases/draft
 *
 * Creates a new case with status 'new' (draft)
 * Called at the end of Step 1 in the case creation wizard
 * Returns the case_id for use in subsequent steps
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

// Only admins/case managers can create cases
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
	Response::error('Unauthorized. Admin access required.', 403);
}

// Get request body
$data = Response::getJsonInput();

// Validate required fields
$requiredFields = ['client_id', 'case_type', 'description', 'start_date'];
$missingFields = [];

foreach ($requiredFields as $field) {
	if (!isset($data[$field]) || empty($data[$field])) {
		$missingFields[] = $field;
	}
}

if (!empty($missingFields)) {
	Response::error('Missing required fields: ' . implode(', ', $missingFields), 400);
}

try {
	// Initialize CaseModel
	$caseModel = initializeClass("CaseModel");

	// Prepare case data
	$caseData = [
		'client_id' => (int)$data['client_id'],
		'case_type' => (int)$data['case_type'],
		'case_title' => $data['case_title'] ?? null,
		'priority' => $data['priority'] ?? 'medium',
		'description' => $data['description'],
		'initial_hours_allotted' => isset($data['initial_hours_allotted']) ? (float)$data['initial_hours_allotted'] : 12.00,
		'budget_amount' => isset($data['budget_amount']) ? (float)$data['budget_amount'] : null,
		'start_date' => $data['start_date'],
		'end_date' => $data['end_date'] ?? null,
		'admin_notes' => $data['admin_notes'] ?? null,
		'status' => 'new'  // Draft status
	];

	// Create the case
	$caseId = $caseModel->createCase($caseData);

	// Create Bunny CDN folder for the case
	$caseFolder = $caseModel->createCaseFolder($caseId, $caseData['client_id']);

	// Load the created case to get full data including case_number
	$newCase = initializeClass("CaseModel", $caseId);

	Response::success([
		'case_id' => $caseId,
		'case_number' => $newCase->caseArr['case_number'],
		'case_folder' => $caseFolder,
		'message' => 'Draft case created successfully'
	], 201);

} catch (InvalidArgumentException $e) {
	Response::error($e->getMessage(), 400);
} catch (Exception $e) {
	error_log("Draft case creation failed: " . $e->getMessage());
	Response::serverError('Failed to create draft case: ' . $e->getMessage());
}
