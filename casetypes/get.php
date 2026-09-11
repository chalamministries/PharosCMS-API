<?php
/**
 * Get Single Case Type Endpoint
 * GET /api/casetypes/{id}
 *
 * Returns detailed information about a specific case type
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
    exit();
}

// Get case type ID from route
if (!isset($_GET['id'])) {
    Response::error('Case Type ID is required', 400);
}

$caseTypeId = intval($_GET['id']);

try {
    $pdo = $GLOBALS['pdo'];

    // Fetch case type
    $caseType = $pdo->selectFirst("case_type", ["case_type_id" => $caseTypeId]);

    if (!$caseType) {
        Response::error('Case type not found', 404);
    }

    // Get count of cases using this type
    $casesQuery = "SELECT COUNT(*) as case_count FROM cases WHERE case_type = :case_type_id";
    $casesResult = $pdo->queryFirst($casesQuery, [':case_type_id' => $caseTypeId]);

    $caseType['cases_count'] = $casesResult ? intval($casesResult['case_count']) : 0;

    // Decode participant_schema JSON string to object for frontend
    if (isset($caseType['participant_schema']) && $caseType['participant_schema'] !== null) {
        $caseType['participant_schema'] = json_decode($caseType['participant_schema'], true);
    }

    Response::success([
        'case_type' => $caseType
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
