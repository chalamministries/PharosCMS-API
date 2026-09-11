<?php
/**
 * List Case Types Endpoint
 * GET /api/casetypes
 *
 * Returns all available case types
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.casetypemodel.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

try {
    $caseTypeModel = initializeClass("CaseTypeModel");
    $caseTypes = $caseTypeModel->getCaseTypes();

    Response::success([
        'case_types' => $caseTypes,
        'count' => count($caseTypes)
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
