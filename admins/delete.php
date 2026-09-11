<?php
/**
 * Delete Admin Endpoint
 * DELETE /api/admins/{id}
 *
 * Deletes an admin account
 * Requires authentication (super_admin only)
 * Note: Prevents deletion if it would leave no super_admins
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow DELETE or POST (some clients don't support DELETE)
if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only super_admins can delete admins
if (!Auth::hasRole($user, ['super_admin'])) {
    Response::error('Unauthorized. Super admin access required.', 403);
}

// Get admin ID from route (for DELETE) or body (for POST)
$adminId = null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!isset($_GET['id'])) {
        Response::error('Admin ID is required', 400);
    }
    $adminId = intval($_GET['id']);
} else {
    // POST method - get from JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['admin_id'])) {
        Response::error('admin_id is required', 400);
    }
    $adminId = intval($input['admin_id']);
}

if ($adminId <= 0) {
    Response::error('Invalid admin_id', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify admin exists
    $admin = $pdo->selectFirst("admins", ["admin_id" => $adminId]);

    if (!$admin) {
        Response::error('Admin not found', 404);
    }

    // Prevent deleting yourself
    if ($user['user_id'] == $adminId && $user['user_type'] === 'admin') {
        Response::error('You cannot delete your own admin account', 400);
    }

    // If deleting a super_admin, check if there are other super_admins
    if ($admin['role'] === 'super_admin') {
        $superAdminQuery = "SELECT COUNT(*) as super_admin_count FROM admins WHERE role = 'super_admin'";
        $superAdminResult = $pdo->queryFirst($superAdminQuery);

        if ($superAdminResult && $superAdminResult['super_admin_count'] <= 1) {
            Response::error('Cannot delete the last super admin. System must have at least one super admin.', 400);
        }
    }

    // Delete the admin
    $result = $pdo->delete("admins", ["admin_id" => $adminId]);

    if ($result !== false) {
        Response::success([
            'message' => 'Admin deleted successfully'
        ]);
    } else {
        Response::serverError('Failed to delete admin');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
