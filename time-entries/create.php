<?php
/**
 * Create Time Entry Endpoint
 * POST /api/time-entries
 * 
 * Allows investigators to submit time entries for cases
 * Requires authentication (investigators only)
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

// Only investigators can submit time entries
if ($user['user_type'] !== 'investigator') {
    Auth::forbidden('Only investigators can submit time entries');
}

// Get request body
$data = Response::getJsonInput();

// Required fields
$required = ['case_id', 'entry_date', 'hours_spent', 'description'];
$missing = Response::validateRequired($data, $required);
if (!empty($missing)) {
    Response::validationError(['missing_fields' => $missing]);
}

$caseId = intval($data['case_id']);
$entryDate = $data['entry_date'];
$hoursSpent = floatval($data['hours_spent']);
$description = trim($data['description']);

// Validate entry_date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
    Response::error('Invalid entry_date format. Use YYYY-MM-DD', 400);
}

// Validate hours_spent
if ($hoursSpent <= 0 || $hoursSpent > 24) {
    Response::error('hours_spent must be between 0 and 24', 400);
}

try {
    $db = getDB();
    
    // Check if investigator is assigned to the case
    $assignmentQuery = "SELECT 
        ca.assignment_id,
        ca.assigned_hourly_rate,
        c.case_id,
        c.case_number,
        c.status as case_status
    FROM case_assignments ca
    JOIN cases c ON ca.case_id = c.case_id
    WHERE ca.case_id = :case_id 
    AND ca.investigator_id = :investigator_id 
    AND ca.unassigned_at IS NULL";
    
    $assignment = $db->queryFirst($assignmentQuery, [
        ':case_id' => $caseId,
        ':investigator_id' => $user['user_id']
    ]);
    
    if (!$assignment) {
        Response::error('You are not assigned to this case', 403);
    }
    
    // Check if case is closed
    if (in_array($assignment['case_status'], ['closed_pending_bill', 'closed_billed'])) {
        Response::error('Cannot submit time entries for closed cases', 400);
    }
    
    // Prepare insert data
    $insertData = [
        'case_id' => $caseId,
        'investigator_id' => $user['user_id'],
        'entry_date' => $entryDate,
        'hours_spent' => $hoursSpent,
        'description' => $description,
        'status' => 'pending' // Default status is pending approval
    ];
    
    // Optional location field
    if (isset($data['location'])) {
        $insertData['location'] = trim($data['location']);
    }
    
    // Insert time entry
    $timeEntryId = $db->insert('time_entries', $insertData);
    
    if (!$timeEntryId) {
        Response::serverError('Failed to create time entry: ' . $db->getErrorMessage());
    }
    
    // Get the created time entry
    $createdEntry = $db->queryFirst(
        "SELECT te.*, 
         CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
         c.case_number
         FROM time_entries te
         JOIN investigators i ON te.investigator_id = i.investigator_id
         JOIN cases c ON te.case_id = c.case_id
         WHERE te.time_entry_id = :time_entry_id", 
        [':time_entry_id' => $timeEntryId]
    );
    
    Response::success([
        'time_entry_id' => $timeEntryId,
        'time_entry' => $createdEntry
    ], 'Time entry submitted successfully', 201);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
