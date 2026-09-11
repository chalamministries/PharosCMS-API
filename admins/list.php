<?php
/**
 * Get List of All Admins
 * GET /api/admins
 *
 * Returns list of all administrators
 * Requires authentication (super_admin only)
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
	exit();
}

// Only super_admins can view admin list
if (!Auth::hasRole($user, ['super_admin'])) {
	Response::error('Unauthorized. Super admin access required.', 403);
}

try {
	$adminModel = initializeClass("AdminModel");
	$admins = $adminModel->getAllAdmins();

	Response::success([
		'admins' => $admins,
		'total_count' => count($admins)
	]);

} catch (Exception $e) {
	Response::serverError('An error occurred: ' . $e->getMessage());
}
