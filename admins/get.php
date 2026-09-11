<?php
/**
 * Get Single Admin Endpoint
 * GET /api/admins/{id}
 *
 * Returns detailed information about a specific admin
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

// Only super_admins can view admin details
if (!Auth::hasRole($user, ['super_admin'])) {
    Response::error('Unauthorized. Super admin access required.', 403);
}

// Get admin ID from route
if (!isset($_GET['id'])) {
    Response::error('Admin ID is required', 400);
}

$adminId = intval($_GET['id']);

try {
    $adminModel = initializeClass("AdminModel", $adminId);

    Response::success([
        'admin' => $adminModel->adminArr
    ]);

} catch (OutOfBoundsException $e) {
    Response::error('Admin not found', 404);
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
