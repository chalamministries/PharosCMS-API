<?php
/**
 * Update Admin Endpoint
 * PUT /api/admins/{id}
 *
 * Updates an existing admin
 * Requires authentication (super_admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only super_admins can update admins
if (!Auth::hasRole($user, ['super_admin'])) {
    Response::error('Unauthorized. Super admin access required.', 403);
}

// Get admin ID from route
if (!isset($_GET['id'])) {
    Response::error('Admin ID is required', 400);
}

$adminId = intval($_GET['id']);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input)) {
    Response::error('No data provided', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify admin exists
    $admin = $pdo->selectFirst("admins", ["admin_id" => $adminId]);

    if (!$admin) {
        Response::error('Admin not found', 404);
    }

    // Build update parameters dynamically
    $updateParams = [];

    if (isset($input['first_name'])) {
        $firstName = trim($input['first_name']);
        if (empty($firstName)) {
            Response::error('first_name cannot be empty', 400);
        }
        $updateParams['first_name'] = $firstName;
    }

    if (isset($input['last_name'])) {
        $lastName = trim($input['last_name']);
        if (empty($lastName)) {
            Response::error('last_name cannot be empty', 400);
        }
        $updateParams['last_name'] = $lastName;
    }

    if (isset($input['email'])) {
        $email = trim($input['email']);

        if (empty($email)) {
            Response::error('email cannot be empty', 400);
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 400);
        }

        // Check if email already exists for a different admin
        $existingQuery = "SELECT admin_id FROM admins WHERE email = :email AND admin_id != :id";
        $existing = $pdo->queryFirst($existingQuery, [
            ':email' => $email,
            ':id' => $adminId
        ]);

        if ($existing) {
            Response::error('An admin with this email already exists', 400);
        }

        $updateParams['email'] = $email;
    }

    if (isset($input['role'])) {
        $role = $input['role'];

        // Validate role enum
        if (!in_array($role, ['super_admin', 'case_manager', 'billing_admin'])) {
            Response::error('Invalid role. Must be one of: super_admin, case_manager, billing_admin', 400);
        }

        $updateParams['role'] = $role;
    }

    if (empty($updateParams)) {
        Response::error('No valid fields to update', 400);
    }

    // Execute update
    $result = $pdo->update("admins", $updateParams, ["admin_id" => $adminId]);

    if ($result !== false) {
        // Fetch updated admin
        $updated = $pdo->selectFirst("admins", ["admin_id" => $adminId]);

        // Remove password_hash from response
        unset($updated['password_hash']);

        Response::success([
            'admin' => $updated,
            'message' => 'Admin updated successfully'
        ]);
    } else {
        Response::serverError('Failed to update admin');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
