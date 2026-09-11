<?php
/**
 * Create Case Endpoint
 * POST /api/cases
 * 
 * Creates a new case
 * Requires authentication (admin or client)
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';
 require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins and clients can create cases
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager', 'investigator', 'client'])) {
    Auth::forbidden('Only admins and clients can create cases');
}

// Get request body - READ ONLY ONCE
$data = Response::getJsonInput();

// Check if we got valid data
if ($data === null || !is_array($data)) {
    Response::error('Invalid JSON data', 400);
    exit();
}
    
$filteredData = array_filter($data, function($value) {
    // This will remove null, empty strings, and false
    // but keep 0 and '0'
    return $value !== null && $value !== '' && $value !== false;
});

try {
    $db = getDB();
    
    // Check if case number exists (rare collision)
    $existingCase = $db->selectFirst("cases", array("case_id" => $_GET['id']));
    
    if (!$existingCase) {
        Response::error('Case not found', 404);
        exit();
    }
    
    // Handle admin_notes separately if it exists and user has permission
    if (isset($filteredData['admin_notes']) && !Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
        // Remove admin_notes if user doesn't have permission
        unset($filteredData['admin_notes']);
    }
    
    // Only proceed with update if there are fields to update
    if (empty($filteredData)) {
        Response::error('No valid fields to update', 400);
        exit();
    }
    
    // Update the case
    $db->update('cases', $filteredData, array("case_id" => $_GET['id']));

    // Audit log: CASE_UPDATE or CASE_STATUS_CHANGE
    $auditModel = new AuditModel();
    if (isset($filteredData['status']) && $filteredData['status'] !== $existingCase['status']) {
        $auditModel->log('CASE_STATUS_CHANGE', $user, $_GET['id'], 'case', $_GET['id'], [
            'case_name' => $existingCase['case_number'],
            'new_status' => ucfirst($filteredData['status'])
        ]);
        
        // Remove status from filteredData so we don't log it again as a generic CASE_UPDATE
        unset($filteredData['status']);
    }

    // Log each other field change
    $excludeFromLogging = ['client_id', 'investigator_id', 'case_id', 'case_number', 'created_at', 'updated_at'];
    $validColumns = getTableColumns('cases');

    foreach ($filteredData as $field => $value) {
        // Ignores fields that are not in the table or (client_id, investigator_id)
        if (!in_array($field, $validColumns) || in_array($field, $excludeFromLogging)) {
            continue;
        }

        // Skip logging if value hasn't actually changed (comparing with existingCase)
        if (isset($existingCase[$field]) && $existingCase[$field] == $value) {
            continue;
        }

        $logValue = $value;
        $fieldNameForLog = str_replace('_', ' ', $field);

        // If (case_type_id), field_value needs to be the case type name by looking up the ID in the case_type table
        if ($field === 'case_type_id') {
            $caseType = $db->selectFirst("case_type", ["case_type_id" => $value]);
            if ($caseType) {
                $logValue = $caseType['description'] ?? $caseType['short_code'] ?? $value;
            }
            $fieldNameForLog = 'case type';
        }

        if (in_array($field, ['case_title', 'description', 'ai_synopsis', 'admin_notes'])) {
            $logValue = strlen($logValue) > 50 ? substr($logValue, 0, 50) . '...' : $logValue;
        }

        $auditModel->log('CASE_UPDATE', $user, $_GET['id'], 'case', $_GET['id'], [
            'field_name' => $fieldNameForLog,
            'field_value' => $logValue
        ]);
    }

    Response::success([
        'case_id' => $_GET['id'],
        'updated_fields' => array_keys($filteredData)
    ], 'Case updated successfully', 200);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
