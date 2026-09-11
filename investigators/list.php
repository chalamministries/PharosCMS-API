<?php
/**
 * Get List of All Investigators
 * GET /api/investigators/list.php
 *
 * Returns list of all investigators with their workload information
 * Requires authentication (admin only)
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
	exit(); // Auth::authenticate() already sent error response
}

try {
	$investigatorModel = initializeClass("InvestigatorModel");
	$investigators = $investigatorModel->getAllInvestigators();

	// Format for backwards compatibility with the workload nested array if needed
	// The current frontend might expect a 'workload' key
	foreach ($investigators as &$inv) {
		$inv['workload'] = [
			'active_cases' => (int)$inv['active_cases'],
			'count_assigned' => (int)$inv['count_assigned'],
			'count_inprogress' => (int)$inv['count_inprogress'],
			'total_hours' => (float)$inv['total_hours'],
			'pending_activities' => (int)$inv['pending_activities'],
			'submitted_activities' => (int)$inv['submitted_activities'],
			'capacity' => (int)$inv['capacity']
		];
	}

	Response::success([
		'investigators' => $investigators,
		'total_count' => count($investigators)
	]);

} catch (Exception $e) {
	Response::serverError('An error occurred: ' . $e->getMessage());
}
