<?php
/**
 * Get Investigator Cases Endpoint
 * GET /api/investigator-cases
 * 
 * Returns cases for a specific investigator based on action type
 * Query Parameters:
 * - investigator_id (required): The investigator ID
 * - action (required): 'active', 'history', or 'all'
 * 
 * Requires authentication
 */
require_once 'config.php';
require_once 'database.php';
require_once 'JWT.php';
require_once 'Auth.php';
require_once 'Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	Response::error('Method not allowed', 405);
}

// // Authenticate user
$user = Auth::authenticate();
if (!$user) {
	exit();
}

// Validate HTTP parameters
if (!isset($_GET['id'])) {
	Response::error('Investigator ID is required', 400);
}

if (!isset($_GET['action'])) {
	Response::error('Action parameter is required', 400);
}

$investigatorId = intval($_GET['id']);
$action = strtolower(trim($_GET['action']));

// Validate basic input
if ($investigatorId <= 0) {
	Response::error('Invalid investigator ID', 400);
}

// Validate action parameter
$validActions = ['active', 'history', 'all'];
if (!in_array($action, $validActions)) {
	Response::error('Invalid action. Must be: active, history, or all', 400);
}

// Check permissions
// Admin can view any investigator's cases
// Investigators can only view their own cases
if ($user['user_type'] === 'investigator' && $user['user_id'] != $investigatorId) {
	Auth::forbidden('You can only view your own cases');
}

// Clients should not have access to this endpoint
if ($user['user_type'] === 'client') {
	Auth::forbidden('You do not have access to this resource');
}

try {
	$caseModel = initializeClass("CaseModel");
	
	// Call appropriate method based on action
	switch ($action) {
		case 'active':
			$cases = $caseModel->getActiveCasesForInvestigator($investigatorId);
			break;
			
		case 'history':
			$cases = $caseModel->getActiveInvestigatorHistory($investigatorId);

			break;
			
		case 'all':
			$cases = $caseModel->getAllInvestigatorCases($investigatorId);

			break;
	}

	$in_progress = searchArray($cases, "status", "in_progress");
    $assigned = searchArray($cases, "status", "assigned");
    $active = array_merge($in_progress, $assigned);

	$db = getDB();
	
	$pending = $db->queryFirst("SELECT COUNT(*) AS count
							FROM activities 
							WHERE investigator_id = {$investigatorId}
							AND status = 'SUBMITTED'")['count'];
							
	$objectives = $db->queryFirst("SELECT COUNT(*) AS count
									FROM objectives 
									WHERE case_id IN (
										SELECT case_id 
										FROM case_assignments 
										WHERE investigator_id = {$investigatorId}
									)
									AND status IN ('ASSIGNED', 'IN PROGRESS')")['count'];
	
	Response::success([
		'investigator_id' => $investigatorId,
		'action' => $action,
		'active' => count($active),
		'pending' => $pending,
		'objectives' => $objectives,
		'cases' => $cases
	]);
	
} catch (Exception $e) {
	// Server error
	error_log("Error loading investigator cases (ID: {$investigatorId}, Action: {$action}): " . $e->getMessage());
	Response::error('Internal server error', 500);
}