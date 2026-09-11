<?php
/**
 * Create Objective Endpoint
 * POST /api/cases/{case_id}/objectives
 *
 * Creates a new objective for a case
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can create objectives
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Response::error('Unauthorized', 403);
}

// Get case_id from route parameter
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}
$caseId = intval($_GET['case_id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['objective_title'])) {
    Response::error('objective_title is required', 400);
}
$title = trim($input['objective_title']);
$description = isset($input['objective_description']) ? trim($input['objective_description']) : null;
$priority = isset($input['priority']) ? $input['priority'] : 'medium';
$estimatedHours = isset($input['estimated_hours']) ? floatval($input['estimated_hours']) : null;
$startDate = isset($input['start_date']) && $input['start_date'] != "" ? $input['start_date'] : null;
$startTime = isset($input['start_time']) && $input['start_time'] != "" ? $input['start_time'] : null;
$dueDate = isset($input['due_date']) && $input['due_date'] != "" ? $input['due_date'] : null;
$assignedType = isset($input['assigned_type']) ? $input['assigned_type'] : null;
$assignedId = isset($input['assigned_id']) ? intval($input['assigned_id']) : null;

// Determine status based on assignment
$status = ($assignedId) ? 'assigned' : 'draft';

// Validate priority
$validPriorities = ['low', 'medium', 'high', 'urgent'];
if (!in_array($priority, $validPriorities)) {
    Response::error('Invalid priority. Must be: low, medium, high, or urgent', 400);
}

// Validate assigned_type
if ($assignedType && !in_array($assignedType, ['admin', 'investigator'])) {
    Response::error('Invalid assigned_type. Must be: admin or investigator', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Build insert data
    $data = [
        'case_id' => $caseId,
        'objective_title' => $title,
        'objective_description' => $description,
        'priority' => $priority,
        'status' => $status,
        'estimated_hours' => $estimatedHours,
        'start_date' => $startDate,
        'start_time' => $startTime,
        'due_date' => $dueDate,
        'assigned_type' => $assignedType,
        'assigned_id' => $assignedId
    ];

    // Insert objective - returns the new ID
    $objectiveId = $pdo->insert('objectives', $data);

    if ($objectiveId) {
        // Audit log: OBJECTIVE_CREATE
        $auditModel = new AuditModel();
        $auditModel->log('OBJECTIVE_CREATE', $user, $caseId, 'objective', $objectiveId, [
            'objective_name' => $title
        ]);

        // Fetch the created objective
        $objective = $pdo->selectFirst("objectives", ["objective_id" => $objectiveId]);

        Response::success([
            'objective' => $objective,
            'message' => 'Objective created successfully'
        ], 201);
    } else {
        Response::serverError('Failed to create objective');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
