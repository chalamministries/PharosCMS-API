<?php
/**
 * Batch Create Objectives Endpoint
 * POST /api/cases/{case_id}/objectives/batch
 *
 * Creates multiple objectives for a case in a single request
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

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
if (!isset($_GET['id'])) {
    Response::error('Case ID is required', 400);
}
$caseId = intval($_GET['id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate objectives array exists
if (!isset($input['objectives']) || !is_array($input['objectives'])) {
    Response::error('objectives array is required', 400);
}

$objectives = $input['objectives'];

if (count($objectives) === 0) {
    Response::error('At least one objective is required', 400);
}

// Validate each objective before inserting
$validPriorities = ['low', 'medium', 'high', 'urgent'];
$errors = [];

foreach ($objectives as $index => $obj) {
    if (!isset($obj['objective_title']) || empty(trim($obj['objective_title']))) {
        $errors[] = "Objective at index {$index}: objective_title is required";
    }

    if (isset($obj['priority']) && !in_array($obj['priority'], $validPriorities)) {
        $errors[] = "Objective at index {$index}: Invalid priority. Must be: low, medium, high, or urgent";
    }
}

if (!empty($errors)) {
    Response::validationError($errors);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    $createdObjectives = [];
    $failedCount = 0;

    // Insert each objective
    foreach ($objectives as $obj) {
        $title = trim($obj['objective_title']);
        $description = isset($obj['objective_description']) ? trim($obj['objective_description']) : null;
        $priority = isset($obj['priority']) ? $obj['priority'] : 'medium';
        $estimatedHours = isset($obj['estimated_hours']) ? floatval($obj['estimated_hours']) : null;
        $dueDate = isset($obj['due_date']) ? $obj['due_date'] : null;

        $data = [
            'case_id' => $caseId,
            'objective_title' => $title,
            'objective_description' => $description,
            'priority' => $priority,
            'status' => 'draft',
            'estimated_hours' => $estimatedHours,
            'due_date' => $dueDate
        ];

        $objectiveId = $pdo->insert('objectives', $data);

        if ($objectiveId) {
            $objective = $pdo->selectFirst("objectives", ["objective_id" => $objectiveId]);
            $createdObjectives[] = $objective;
        } else {
            $failedCount++;
        }
    }

    $totalRequested = count($objectives);
    $totalCreated = count($createdObjectives);

    Response::success([
        'objectives' => $createdObjectives,
        'summary' => [
            'requested' => $totalRequested,
            'created' => $totalCreated,
            'failed' => $failedCount
        ],
        'message' => "{$totalCreated} of {$totalRequested} objectives created successfully"
    ], 201);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
