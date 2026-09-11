<?php
/**
 * Delete Case Type Endpoint
 * DELETE /api/casetypes/{id}
 *
 * Deletes a case type (only if no cases are using it)
 * Requires authentication (admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.casetypemodel.php';

// Only allow DELETE or POST (some clients don't support DELETE)
if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can delete case types
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get case type ID from route (for DELETE) or body (for POST)
$caseTypeId = null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!isset($_GET['id'])) {
        Response::error('Case Type ID is required', 400);
    }
    $caseTypeId = intval($_GET['id']);
} else {
    // POST method - get from JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['case_type_id'])) {
        Response::error('case_type_id is required', 400);
    }
    $caseTypeId = intval($input['case_type_id']);
}

if ($caseTypeId <= 0) {
    Response::error('Invalid case_type_id', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case type exists
    $caseType = $pdo->selectFirst("case_type", ["case_type_id" => $caseTypeId]);

    if (!$caseType) {
        Response::error('Case type not found', 404);
    }

    // Check if any cases are using this case type
    $casesQuery = "SELECT COUNT(*) as case_count FROM cases WHERE case_type = :case_type_id";
    $casesResult = $pdo->queryFirst($casesQuery, [':case_type_id' => $caseTypeId]);

    if ($casesResult && $casesResult['case_count'] > 0) {
        Response::error(
            'Cannot delete case type. ' . $casesResult['case_count'] . ' case(s) are currently using this type. Please reassign these cases first.',
            400
        );
    }

    // Delete the case type
    $result = $pdo->delete("case_type", ["case_type_id" => $caseTypeId]);

    if ($result !== false) {
        Response::success([
            'message' => 'Case type deleted successfully'
        ]);
    } else {
        Response::serverError('Failed to delete case type');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
