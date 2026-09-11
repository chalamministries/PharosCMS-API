<?php
/**
 * Delete Investigator Endpoint
 * DELETE /api/investigators/{id}
 *
 * Deletes an investigator (only if no active case assignments)
 * Requires authentication (admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow DELETE or POST (some clients don't support DELETE)
if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can delete investigators
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get investigator ID from route (for DELETE) or body (for POST)
$investigatorId = null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!isset($_GET['id'])) {
        Response::error('Investigator ID is required', 400);
    }
    $investigatorId = intval($_GET['id']);
} else {
    // POST method - get from JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['investigator_id'])) {
        Response::error('investigator_id is required', 400);
    }
    $investigatorId = intval($input['investigator_id']);
}

if ($investigatorId <= 0) {
    Response::error('Invalid investigator_id', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify investigator exists
    $investigator = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);

    if (!$investigator) {
        Response::error('Investigator not found', 404);
    }

    // Check if investigator has any active case assignments
    $assignmentsQuery = "
        SELECT COUNT(DISTINCT ca.case_id) as active_cases
        FROM case_assignments ca
        JOIN cases c ON ca.case_id = c.case_id
        WHERE ca.investigator_id = :investigator_id
        AND c.status NOT IN ('completed', 'closed', 'cancelled')
    ";
    $assignmentsResult = $pdo->queryFirst($assignmentsQuery, [':investigator_id' => $investigatorId]);

    if ($assignmentsResult && $assignmentsResult['active_cases'] > 0) {
        Response::error(
            'Cannot delete investigator. ' . $assignmentsResult['active_cases'] . ' active case(s) are currently assigned. Please reassign these cases first.',
            400
        );
    }

    // Check if investigator has any activities
    $activitiesQuery = "SELECT COUNT(*) as activity_count FROM activities WHERE investigator_id = :investigator_id";
    $activitiesResult = $pdo->queryFirst($activitiesQuery, [':investigator_id' => $investigatorId]);

    if ($activitiesResult && $activitiesResult['activity_count'] > 0) {
        Response::error(
            'Cannot delete investigator. ' . $activitiesResult['activity_count'] . ' activity record(s) exist. Consider setting status to inactive instead.',
            400
        );
    }

    // Delete the investigator
    $result = $pdo->delete("investigators", ["investigator_id" => $investigatorId]);

    if ($result !== false) {
        Response::success([
            'message' => 'Investigator deleted successfully'
        ]);
    } else {
        Response::serverError('Failed to delete investigator');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
