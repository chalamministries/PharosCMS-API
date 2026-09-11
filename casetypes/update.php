<?php
/**
 * Update Case Type Endpoint
 * PUT /api/casetypes/{id}
 *
 * Updates an existing case type
 * Requires authentication (admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.casetypemodel.php';

// Only allow PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can update case types
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get case type ID from route
if (!isset($_GET['id'])) {
    Response::error('Case Type ID is required', 400);
}

$caseTypeId = intval($_GET['id']);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input)) {
    Response::error('No data provided', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case type exists
    $caseType = $pdo->selectFirst("case_type", ["case_type_id" => $caseTypeId]);

    if (!$caseType) {
        Response::error('Case type not found', 404);
    }

    // Build update query dynamically
    $updates = [];
    $params = [':id' => $caseTypeId];

    if (isset($input['short_code'])) {
        $shortCode = trim($input['short_code']);

        if (empty($shortCode)) {
            Response::error('short_code cannot be empty', 400);
        }

        // Check if short_code already exists for a different case type
        $existingQuery = "SELECT case_type_id FROM case_type WHERE short_code = :short_code AND case_type_id != :id";
        $existing = $pdo->queryFirst($existingQuery, [
            ':short_code' => $shortCode,
            ':id' => $caseTypeId
        ]);

        if ($existing) {
            Response::error('A case type with this short_code already exists', 400);
        }

        $updates[] = "short_code = :short_code";
        $params[':short_code'] = $shortCode;
    }

    if (isset($input['description'])) {
        $description = trim($input['description']);

        if (empty($description)) {
            Response::error('description cannot be empty', 400);
        }

        $updates[] = "description = :description";
        $params[':description'] = $description;
    }

    $participantSchema = null;
    if (isset($input['participant_schema']) && is_array($input['participant_schema'])) {
        $participantSchema = $input['participant_schema'];
        $updates[] = "participant_schema = :participant_schema";
        $params[':participant_schema'] = json_encode($participantSchema);
    }

    if (empty($updates)) {
        Response::error('No valid fields to update', 400);
    }

    // Execute update
    $caseTypeModel = initializeClass("CaseTypeModel");
    $result = $caseTypeModel->updateCaseType(
        $caseTypeId,
        $shortCode ?? $caseType['short_code'],
        $description ?? $caseType['description'],
        $participantSchema
    );

    if ($result !== false) {
        // Fetch updated case type
        $updated = $pdo->selectFirst("case_type", ["case_type_id" => $caseTypeId]);
        if (isset($updated['participant_schema']) && $updated['participant_schema'] !== null) {
            $updated['participant_schema'] = json_decode($updated['participant_schema'], true);
        }

        Response::success([
            'case_type' => $updated,
            'message' => 'Case type updated successfully'
        ]);
    } else {
        Response::serverError('Failed to update case type');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
