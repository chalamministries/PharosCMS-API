<?php
/**
 * Logout Endpoint (Simplified)
 * POST /api/auth/logout
 * 
 * Simple logout - client just needs to delete their token
 * This endpoint exists for consistency but doesn't need to do anything server-side
 * since JWT tokens expire automatically after 24 hours
 */
require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';
 require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user (validates token is valid)
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// For simple JWT, logout is client-side only
// Client should delete/clear the token from storage
// The token will automatically expire after 24 hours

// Audit log: LOGOUT
$auditModel = new AuditModel();
$auditModel->log('LOGOUT', $user, null, 'auth', $user['user_id']);

Response::success([
    'message' => 'Logout successful. Token will expire automatically.'
], 'Logged out successfully');
