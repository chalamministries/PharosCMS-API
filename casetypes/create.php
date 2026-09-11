<?php
/**
 * Create Case Type Endpoint
 * POST /api/casetypes
 *
 * Creates a new case type
 * Requires authentication (admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.casetypemodel.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can create case types
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['short_code']) || !isset($input['description'])) {
    Response::error('short_code and description are required', 400);
}

$shortCode = trim($input['short_code']);
$description = trim($input['description']);

// Validate short_code length
if (empty($shortCode)) {
    Response::error('short_code cannot be empty', 400);
}

if (empty($description)) {
    Response::error('description cannot be empty', 400);
}

try {
    $caseTypeModel = initializeClass("CaseTypeModel");

    // Check if short_code already exists
    $pdo = $GLOBALS['pdo'];
    $existingQuery = "SELECT case_type_id FROM case_type WHERE short_code = :short_code";
    $existing = $pdo->queryFirst($existingQuery, [':short_code' => $shortCode]);

    if ($existing) {
        Response::error('A case type with this short_code already exists', 400);
    }

    // Create the case type
    $participantSchema = isset($input['participant_schema']) ? $input['participant_schema'] : null;
    $caseTypeId = $caseTypeModel->createCaseType($shortCode, $description, $participantSchema);

    if ($caseTypeId) {
        // Fetch the created case type
        $caseType = $pdo->selectFirst("case_type", ["case_type_id" => $caseTypeId]);
        if (isset($caseType['participant_schema']) && $caseType['participant_schema'] !== null) {
            $caseType['participant_schema'] = json_decode($caseType['participant_schema'], true);
        }

        Response::success([
            'case_type' => $caseType,
            'message' => 'Case type created successfully'
        ], 201);
    } else {
        Response::serverError('Failed to create case type');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
