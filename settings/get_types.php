<?php
/**
 * Get Single Case Endpoint
 * GET /api/cases/{case_id}
 * 
 * Returns detailed information about a specific case
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';


// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

$pdo = $GLOBALS['pdo'];

$case_types = $pdo->select("case_type");

Response::success($case_types);