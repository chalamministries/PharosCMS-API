<?php
/**
 * Create Activity Endpoint
 * POST /api/cases/{case_id}/activities
 *
 * Creates a new activity for a case
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

// Get case_id from route parameter
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

// Validate case_id
if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['date_of_activity']) || !isset($input['hours_spent'])) {
    Response::error('date_of_activity and hours_spent are required', 400);
}

// Extract fields
$dateOfActivity = $input['date_of_activity'];
$hoursSpent = isset($input['hours_spent']) ? $input['hours_spent'] : 0;
$activityDescription = isset($input['activity_description']) ? trim($input['activity_description']) : '';
$timeEntries = isset($input['time_entries']) ? $input['time_entries'] : [];


$objectiveId = isset($input['objective_id']) ? intval($input['objective_id']) : null;
$startTime = isset($input['start_time']) ? $input['start_time'] : null;
$endTime = isset($input['end_time']) ? $input['end_time'] : null;
$activityType = isset($input['activity_type']) ? intval($input['activity_type']) : 1;
$clientBillingMethod = isset($input['client_billing_method']) ? $input['client_billing_method'] : 'hourly';
$mileage = isset($input['mileage']) ? $input['mileage'] : null;
$status = isset($input['status']) ? $input['status'] : 'submitted';
$notes = isset($input['notes']) ? $input['notes'] : '';

// Handle performer logic (similar to create.php)
$investigatorId = null;
$adminId = null;

if (isset($input['performer_composite_id'])) {
    $performerId = $input['performer_composite_id'];

    // Check prefix to determine type
    if (substr($performerId, 0, 1) === 'a') {
        // Admin
        $adminId = intval(substr($performerId, 1));
        $investigatorId = null;
    } elseif (substr($performerId, 0, 1) === 'i') {
        // Investigator
        $investigatorId = intval(substr($performerId, 1));
        $adminId = null;
    } else {
        Response::error('Invalid performer_composite_id format. Must start with "a" or "i"', 400);
    }
} else {
    // Default to current user if not specified
    if ($user['user_type'] === 'investigator') {
        $investigatorId = $user['user_id'];
        $adminId = null;
    } elseif ($user['user_type'] === 'admin') {
        $adminId = $user['user_id'];
        $investigatorId = null;
    }
}

// Validate required fields are not empty
if (empty($dateOfActivity)) {
    Response::error('Date of Activity cannot be empty', 400);
}

//if (empty($activityDescription)) {
//    Response::error('activity_description cannot be empty', 400);
//}

// Validate hours_spent
//if (!is_numeric($hoursSpent) || $hoursSpent <= 0) {
//    Response::error('Hours Spent must be a positive number', 400);
//}

// Validate client_billing_method enum
if (!in_array($clientBillingMethod, ['hourly', 'flat_rate', 'equipment_fee', 'not_billable'])) {
    Response::error('Invalid client billing method. Must be one of: hourly, flat_rate, equipment_fee, not_billable', 400);
}

// Validate status enum
if (!in_array($status, ['pending', 'submitted', 'approved', 'rejected'])) {
    Response::error('Invalid status. Must be one of: pending, submitted, approved, rejected', 400);
}

// Validate mileage if provided
if ($mileage !== null && (!is_numeric($mileage) || $mileage < 0)) {
    Response::error('Mileage must be a positive number', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Verify objective exists if provided
    if ($objectiveId) {
        $objective = $pdo->selectFirst("objectives", ["objective_id" => $objectiveId]);
        if (!$objective || $objective['case_id'] != $caseId) {
            Response::error('Objective not found or does not belong to this case', 404);
        }
    }

    // Verify investigator exists if provided
    $investigator = null;
    if ($investigatorId) {
        $investigator = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);
        if (!$investigator) {
            Response::error('Investigator not found', 404);
        }
    }

    // Verify admin exists if provided
    $admin = null;
    if ($adminId) {
        $admin = $pdo->selectFirst("admins", ["admin_id" => $adminId]);
        if (!$admin) {
            Response::error('Admin not found', 404);
        }
    }

    // ============================================
    // RATE CALCULATIONS
    // ============================================

    // Default rates
    $defaultClientRate = 175.00; // System default client billing rate
    $performerRateAtTime = null;
    $performerCostTotal = null;
    $clientRatePerHour = null;
    $clientBillableAmount = null;

    // Get client billing rate from case override, fallback to system default
    if ($case['client_hourly_rate']) {
        $clientRatePerHour = floatval($case['client_hourly_rate']);
    } else {
        $clientRatePerHour = $defaultClientRate;
    }

    // Get performer rate based on performer type
    if ($investigatorId) {
        // For investigators: get rate from case_assignments, fallback to investigator default
        $assignment = $pdo->queryFirst(
            "SELECT assigned_hourly_rate FROM case_assignments
             WHERE case_id = :case_id AND investigator_id = :investigator_id AND unassigned_at IS NULL
             ORDER BY assigned_at DESC LIMIT 1",
            [':case_id' => $caseId, ':investigator_id' => $investigatorId]
        );

        if ($assignment && $assignment['assigned_hourly_rate']) {
            $performerRateAtTime = floatval($assignment['assigned_hourly_rate']);
        } elseif ($investigator && $investigator['hourly_rate']) {
            // Fallback to investigator's default rate
            $performerRateAtTime = floatval($investigator['hourly_rate']);
        }
    } elseif ($adminId) {
        // For admins: use the client rate (they don't have a separate cost rate)
        $performerRateAtTime = $clientRatePerHour;
    }

    // Calculate performer cost
    if ($performerRateAtTime !== null) {
        $performerCostTotal = floatval($hoursSpent) * $performerRateAtTime;
    }

    // Calculate client billable amount based on billing method
    if ($clientBillingMethod === 'hourly') {
        $clientBillableAmount = floatval($hoursSpent) * $clientRatePerHour;
    } elseif ($clientBillingMethod === 'not_billable') {
        $clientBillableAmount = 0;
    }
    // For 'flat_rate' and 'equipment_fee', client_billable_amount should be set manually

    // Build insert data
    $data = [
        'case_id' => $caseId,
        'uuid' => guidv4(),
        'objective_id' => $objectiveId,
        'investigator_id' => $investigatorId,
        'admin_id' => $adminId,
        'date_of_activity' => $dateOfActivity,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'hours_spent' => $hoursSpent,
        'activity_type' => $activityType,
        'activity_description' => $activityDescription,
        'performer_rate_at_time' => $performerRateAtTime,
        'performer_cost_total' => $performerCostTotal,
        'client_billing_method' => $clientBillingMethod,
        'client_rate_per_hour' => $clientRatePerHour,
        'client_billable_amount' => $clientBillableAmount,
        'notes' => $notes,
        'mileage' => $mileage,
        'status' => $status
    ];

    // Insert activity
    $activityId = $pdo->insert('activities', $data);

    if ($activityId) {
        // Audit log: ACTIVITY_CREATE
        $auditModel = new AuditModel();
        $activityTypeRecord = $pdo->selectFirst("activity_type", ["id" => $activityType]);
        $auditModel->log('ACTIVITY_CREATE', $user, $caseId, 'activity', $activityId, [
            'activity_type' => $activityTypeRecord['name'] ?? 'Unknown'
        ]);

        // If activity is linked to an objective, check if we need to update objective status
        if ($objectiveId) {
            // Check if objective is currently 'assigned' and has activities now
            $objective = $pdo->selectFirst("objectives", ["objective_id" => $objectiveId]);

            if ($objective && $objective['status'] === 'assigned') {
                // Update objective status to 'in_progress' since it now has activities
                $pdo->update('objectives',
                    ['status' => 'in_progress'],
                    ['objective_id' => $objectiveId]
                );
            }
        }

        // Fetch the created activity
        $activity = $pdo->selectFirst("activities", ["activity_id" => $activityId]);

        // Insert Time Entries
        $entryMappings = [];
        if (!empty($timeEntries)) {
            foreach ($timeEntries as $clientSideId => $entry) {
                // Some entries might have client_side_id as a key or property
                $cId = isset($entry['client_side_id']) ? $entry['client_side_id'] : $clientSideId;
            
                $entryData = [
                    'activity_id' => $activityId,
                    'case_id' => $caseId,
                    'investigator_id' => $investigatorId,
                    'entry_time' => !empty($entry['time']) ? $entry['time'] : null,
                    'hours_spent' => !empty($entry['hours']) ? floatval($entry['hours']) : 0,
                    'entry_description' => $entry['description'] ?? '',
                    'status' => $status
                ];
                $dbEntryId = $pdo->insert('time_entries', $entryData);
                $entryMappings[$cId] = $dbEntryId;
            }

        }

        // Generate AI Summary for the activity
        if (!empty($timeEntries) || !empty($notes)) {
            try {
                $activitySummaryUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/api/ai/generate-activity-summary";
                
                $ch = curl_init($activitySummaryUrl);
                $summaryPayload = json_encode(['activity_id' => $activityId]);
                
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $summaryPayload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
                ]);
                // Set a short timeout so we don't block the main response too long if AI is slow
                // though ideally this would be truly backgrounded.
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                
                $summaryResult = curl_exec($ch);
                $summaryHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($summaryHttpCode === 200) {
                    $summaryData = json_decode($summaryResult, true);
                    if (isset($summaryData['success']) && $summaryData['success']) {
                        // Refresh activity data to include the new description
                        $activity = $pdo->selectFirst("activities", ["activity_id" => $activityId]);
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to trigger AI summary: " . $e->getMessage());
            }
        }

        Response::success([
            'activity' => $activity,
            'entry_mappings' => $entryMappings,
            'message' => 'Activity created successfully'
        ], 201);
    } else {
        Response::serverError('Failed to create activity');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
