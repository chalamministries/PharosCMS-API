<?php
/**
 * Update Objective Endpoint
 * PUT /api/objectives/{id}
 *
 * Updates an objective
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get objective ID from route
if (!isset($_GET['id'])) {
    Response::error('Objective ID is required', 400);
}

$objectiveId = intval($_GET['id']);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input)) {
    Response::error('No data provided', 400);
}

try {
    $db = getDB();

    // Verify objective exists
    $objectiveQuery = "SELECT * FROM objectives WHERE objective_id = :id";
    $objective = $db->queryFirst($objectiveQuery, [':id' => $objectiveId]);

    if (!$objective) {
        Response::error('Objective not found', 404);
    }

    // Build update parameters dynamically
    $updateParams = [];

    if (array_key_exists('objective_title', $input)) {
        $updateParams['objective_title'] = trim($input['objective_title']);
    }

    if (array_key_exists('objective_description', $input)) {
        $updateParams['objective_description'] = trim($input['objective_description']);
    }

    if (array_key_exists('priority', $input)) {
        $validPriorities = ['low', 'medium', 'high', 'urgent'];
        if ($input['priority'] && !in_array($input['priority'], $validPriorities)) {
            Response::error('Invalid priority', 400);
        }
        $updateParams['priority'] = $input['priority'];
    }

    if (array_key_exists('status', $input)) {
        $validStatuses = ['draft', 'assigned', 'in_progress', 'completed', 'approved'];
        if ($input['status'] && !in_array($input['status'], $validStatuses)) {
            Response::error('Invalid status', 400);
        }
        $status = $input['status'];
    } else {
        // Use current status
        $status = $objective['status'];
    }

    if (array_key_exists('assigned_id', $input)) {
        $assignedId = $input['assigned_id'] !== null ? intval($input['assigned_id']) : null;
        $updateParams['assigned_id'] = $assignedId;
        
        // If assigned and currently draft, move to assigned
        if ($assignedId && $status === 'draft') {
            $status = 'assigned';
        } elseif (!$assignedId && $status === 'assigned') {
            // If unassigned and currently assigned, move back to draft
            $status = 'draft';
        }
    } else {
        $assignedId = $objective['assigned_id'];
    }

    if (array_key_exists('assigned_type', $input)) {
        $assignedType = $input['assigned_type'] !== null ? $input['assigned_type'] : null;
        $validTypes = ['admin', 'investigator'];
        if ($assignedType && !in_array($assignedType, $validTypes)) {
            Response::error('Invalid assigned_type', 400);
        }
        $updateParams['assigned_type'] = $assignedType;
    }

    // Set status update
    $updateParams['status'] = $status;

    // If status is being updated or calculated status is different
    if ($status !== $objective['status']) {
        // If marking as completed, set completed_at
        if ($status === 'completed' && !$objective['completed_at']) {
            $updateParams['completed_at'] = date('Y-m-d H:i:s');
            if ($user['user_type'] === 'investigator') {
                $updateParams['completed_by_investigator_id'] = $user['user_id'];
            }
        }

        // If approving, set approved_at
        if ($status === 'approved' && !$objective['approved_at']) {
            $updateParams['approved_at'] = date('Y-m-d H:i:s');
            if (Auth::hasRole($user, ['admin', 'super_admin'])) {
                $updateParams['approved_by_user_id'] = $user['user_id'];
            }
        }
    }

    if (array_key_exists('estimated_hours', $input)) {
        $updateParams['estimated_hours'] = $input['estimated_hours'] !== null ? floatval($input['estimated_hours']) : null;
    }

    if (array_key_exists('start_date', $input)) {
        $updateParams['start_date'] = $input['start_date'] != "" ? $input['start_date'] : null;
    }

    if (array_key_exists('start_time', $input)) {
        $updateParams['start_time'] = $input['start_time'] != "" ? $input['start_time'] : null;
    }

    if (array_key_exists('due_date', $input)) {
        $updateParams['due_date'] = $input['due_date'] != "" ? $input['due_date'] : null;
    }

    if (array_key_exists('completion_percentage', $input)) {
        $updateParams['completion_percentage'] = $input['completion_percentage'] !== null ? intval($input['completion_percentage']) : null;
    }

    if (empty($updateParams)) {
        Response::error('No valid fields to update', 400);
    }

    // Add updated_at
    $updateParams['updated_at'] = date('Y-m-d H:i:s');

    // Execute update using the database wrapper's update method
    $db->update('objectives', $updateParams, ['objective_id' => $objectiveId]);

    // Fetch updated objective to verify and return
    $updated = $db->queryFirst($objectiveQuery, [':id' => $objectiveId]);

    if ($updated) {
        Response::success([
            'objective' => $updated,
            'message' => 'Objective updated successfully'
        ]);
    } else {
        Response::serverError('Failed to fetch updated objective');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
