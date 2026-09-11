<?php
/**
 * Get Single Case Endpoint
 * GET /api/cases/{case_id}
 * 
 * Returns detailed information about a specific case
 * Requires authentication
 */

require_once 'config.php';
require_once 'database.php';
require_once 'JWT.php';
require_once 'Auth.php';
require_once 'Response.php';


// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Validate HTTP parameters
if (!isset($_GET['id'])) {
    Response::error('Case ID is required', 428);
}

$caseId = intval($_GET['id']);

// Validate basic input
if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

$include = isset($_GET['include']) ? $_GET['include'] : ['*'];


if(!is_array($include)) {
    $include = explode(",", $include);
}

if(isset($_GET['checksum']) && $_GET['checksum'] != "") {
    $caseFile = initializeClass("CaseModel");
    $checksum = $caseFile->getCaseChecksum($caseId);
    
    if($checksum['checksum'] == $_GET['checksum']) {
        Response::success(array(), "Case Matched", 208);
        die();
    }
}

try {
    $caseFile = initializeClass("CaseModel", $caseId, $include, $user);
    
    $case = $caseFile->caseArr;
    
    // Check permissions
    // if ($user['user_type'] === 'client' && $case['client_id'] != $user['user_id']) {
    //     Auth::forbidden('You do not have access to this case');
    // }
    // 
    // if ($user['user_type'] === 'investigator') {
    //     // Check if investigator is assigned to this case
    //     $assignmentQuery = "SELECT assignment_id FROM case_assignments 
    //                        WHERE case_id = :case_id 
    //                        AND investigator_id = :investigator_id 
    //                        AND unassigned_at IS NULL";
    //     $assignment = $db->queryFirst($assignmentQuery, [
    //         ':case_id' => $caseId,
    //         ':investigator_id' => $user['user_id']
    //     ]);
    //     
    //     if (!$assignment) {
    //         Auth::forbidden('You are not assigned to this case');
    //     }
    // }
    $checksum = $caseFile->getCaseChecksum($caseId);
    
    Response::success(array("case" => $case, "checksum" => $checksum['checksum']));
    
} catch (OutOfBoundsException $e) {
    // Case doesn't exist
    error_log($e);
    Response::notFound('Case not found');
    
} catch (InvalidArgumentException | OutOfRangeException $e) {
    // Should rarely happen since we validated above, but just in case
    Response::error($e->getMessage(), 400);
    
} catch (Exception $e) {
    // Server error
    //error_log("Error loading case {$caseId}: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

die();
