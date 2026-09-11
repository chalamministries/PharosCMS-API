<?php
/**
 * Get Cases Endpoint
 * GET /api/cases
 * 
 * Returns list of cases with optional filtering
 * Requires authentication
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

try {
    $caseModel = initializeClass("CaseModel");
    $result = $caseModel->listCases($user, $_GET);
    
    // Return success response with pagination info
    Response::success($result);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
