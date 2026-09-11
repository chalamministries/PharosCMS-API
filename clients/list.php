<?php
/**
 * List Clients Endpoint
 * GET /api/clients
 * 
 * Returns list of clients with optional filtering and pagination
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
    exit();
}

// Only admins can list clients
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Auth::forbidden('Only admins can list clients');
}

try {
    $clientModel = initializeClass("ClientModel");
    $result = $clientModel->listClients($_GET);
    
    // Return success response with pagination info
    Response::success($result);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
