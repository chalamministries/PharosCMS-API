<?php
/**
 * Change Password Endpoint
 * POST /api/auth/change-password
 * 
 * Allows authenticated users to change their password
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

// Get request body
$data = Response::getJsonInput();

// Validate required fields
$missing = Response::validateRequired($data, ['current_password', 'new_password']);
if (!empty($missing)) {
    Response::validationError(['missing_fields' => $missing]);
}

$currentPassword = $data['current_password'];
$newPassword = $data['new_password'];

// Validate new password strength
if (strlen($newPassword) < 8) {
    Response::error('New password must be at least 8 characters long', 400);
}

try {
    $db = getDB();
    
    // Determine table and ID field
    $table = '';
    $idField = '';
    
    switch ($user['user_type']) {
        case 'client':
            $table = 'clients';
            $idField = 'client_id';
            break;
        case 'investigator':
            $table = 'investigators';
            $idField = 'investigator_id';
            break;
        case 'admin':
            $table = 'admins';
            $idField = 'admin_id';
            break;
    }
    
    // Get current user data
    $query = "SELECT password_hash FROM $table WHERE $idField = :user_id LIMIT 1";
    $userData = $db->queryFirst($query, [':user_id' => $user['user_id']]);
    
    if (!$userData) {
        Response::error('User not found', 404);
    }
    
    // Verify current password
    if (!Auth::verifyPassword($currentPassword, $userData['password_hash'])) {
        Response::error('Current password is incorrect', 401);
    }
    
    // Hash new password
    $newPasswordHash = Auth::hashPassword($newPassword);
    
    // Update password
    $updated = $db->update($table, ['password_hash' => $newPasswordHash], [$idField => $user['user_id']]);
    
    if ($updated === false) {
        Response::serverError('Failed to update password');
    }
    
    // Optionally revoke all refresh tokens to force re-login on all devices
    if (isset($data['revoke_all_sessions']) && $data['revoke_all_sessions'] === true) {
        $db->update("refresh_tokens", 
            ['is_revoked' => true], 
            [
                'user_id' => $user['user_id'],
                'user_type' => $user['user_type'],
                'is_revoked' => false
            ]
        );
    }
    
    Response::success(null, 'Password changed successfully');
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
