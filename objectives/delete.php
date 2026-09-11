<?php
/**
 * Delete Objective Endpoint
 * DELETE /api/objectives/delete
 *
 * Deletes an objective (only if no activities are attached)
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.objectivemodel.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow DELETE or POST (some clients don't support DELETE)
if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can delete objectives
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Response::error('Unauthorized', 403);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['objective_id'])) {
    Response::error('objective_id is required', 400);
}

$objectiveId = intval($input['objective_id']);

if ($objectiveId <= 0) {
    Response::error('Invalid objective_id', 400);
}

try {
    // Initialize ObjectiveModel
    $objectiveModel = initializeClass("ObjectiveModel", $objectiveId);
    $objectiveData = $objectiveModel->objectiveArr;

    // Delete the objective (will throw exception if activities exist)
    $objectiveModel->deleteObjective();

    // Audit log: OBJECTIVE_DELETE
    $auditModel = new AuditModel();
    $auditModel->log('OBJECTIVE_DELETE', $user, $objectiveData['case_id'], 'objective', $objectiveId, [
        'objective_name' => $objectiveData['objective_title']
    ]);

    Response::success([
        'message' => 'Objective deleted successfully'
    ]);

} catch (OutOfBoundsException $e) {
    Response::error('Objective not found', 404);
} catch (DomainException $e) {
    // This is thrown when activities exist
    Response::error($e->getMessage(), 400);
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
