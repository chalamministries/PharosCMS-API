<?php
/**
 * Verify Token Endpoint
 * GET /api/auth/verify
 * 
 * Verifies if the provided access token is valid and returns user info
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

// Token is valid, return user info
Response::success([
    'valid' => true,
    'user' => [
        'id' => $user['user_id'],
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'user_type' => $user['user_type']
    ],
    'expires_at' => date('Y-m-d H:i:s', $user['exp'])
], 'Token is valid');
