<?php
/**
 * Get Single Client Endpoint
 * GET /api/clients/{client_id}
 * 
 * Returns detailed information about a specific case
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';


// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	Response::error('Method not allowed', 205);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
	exit();
}

// Validate HTTP parameters
if (!isset($_GET['id'])) {
	Response::error('Client ID is required', 200);
}

$clientId = intval($_GET['id']);

// Validate basic input
if ($clientId <= 0) {
	Response::error('Invalid client ID', 200);
}

try {
	$client = initializeClass("ClientModel", $clientId);
	
	// Optional: Check if user has permission to view this case
	// if (!$case->userHasAccess($user['id'])) {
	//     Response::error('Access denied', 403);
	// }
	
	Response::success($client->clientArr);
	
} catch (OutOfBoundsException $e) {
	// Case doesn't exist
	Response::notFound('Client not found');
	
} catch (InvalidArgumentException | OutOfRangeException $e) {
	// Should rarely happen since we validated above, but just in case
	Response::error($e->getMessage(), 400);
	
} catch (Exception $e) {
	// Server error
	error_log("Error loading case {$clientId}: " . $e->getMessage());
	Response::error('Internal server error', 500);
}

