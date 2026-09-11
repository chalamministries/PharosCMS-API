<?php
/**
 * Create Admin Endpoint
 * POST /api/admins
 *
 * Creates a new administrative user
 * Requires authentication (super_admin only)
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

// Only super_admins can create other admins
if (!Auth::hasRole($user, ['super_admin'])) {
    Response::error('Unauthorized. Super admin access required.', 403);
}

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
$requiredFields = ['first_name', 'last_name', 'email', 'password', 'role'];
$missing = Response::validateRequired($input, $requiredFields);
if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing), 400);
}

$firstName = trim($input['first_name']);
$lastName = trim($input['last_name']);
$email = trim($input['email']);
$password = $input['password'];
$role = trim($input['role']);

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Invalid email format', 400);
}

// Validate role enum
$allowedRoles = ['super_admin', 'case_manager', 'billing_admin'];
if (!in_array($role, $allowedRoles)) {
    Response::error('Invalid role. Must be one of: ' . implode(', ', $allowedRoles), 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Check if email already exists
    $existingEmail = $pdo->queryFirst(
        "SELECT admin_id FROM admins WHERE email = :email",
        [':email' => $email]
    );

    if ($existingEmail) {
        Response::error('An admin with this email already exists', 400);
    }

    // Hash the password
    $passwordHash = Auth::hashPassword($password);

    // Build insert data
    $data = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => $role
    ];

    // Insert admin
    $adminId = $pdo->insert('admins', $data);

    if ($adminId) {
        // Fetch the created admin
        $admin = $pdo->selectFirst("admins", ["admin_id" => $adminId]);

        // Remove sensitive fields from response
        unset($admin['password_hash']);

        Response::success([
            'admin' => $admin,
            'message' => 'Admin created successfully'
        ], 'Admin created successfully', 201);
    } else {
        Response::serverError('Failed to create admin');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
