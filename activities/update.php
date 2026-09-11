<?php
/**
 * Update Activity Endpoint
 * PUT /api/activities/{activity_id}
 *
 * Updates an existing activity and its time entries
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get activity_id from route parameter
if (!isset($_GET['activity_id'])) {
    Response::error('Activity ID is required', 400);
}

$activityId = intval($_GET['activity_id']);

// Validate activity_id
if ($activityId <= 0) {
    Response::error('Invalid activity ID', 400);
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

try {
    $pdo = $GLOBALS['pdo'];

    // Verify activity exists
    $existingActivity = $pdo->selectFirst("activities", ["activity_id" => $activityId]);
    if (!$existingActivity) {
        Response::error('Activity not found', 404);
    }

    $caseId = $existingActivity['case_id'];

    // Verify case exists
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Handle performer logic (similar to create.php)
    $investigatorId = null;
    $adminId = null;
    if (isset($input['performer_composite_id'])) {
        $performerId = $input['performer_composite_id'];
        if (substr($performerId, 0, 1) === 'a') {
            $adminId = intval(substr($performerId, 1));
            $investigatorId = null;
        } elseif (substr($performerId, 0, 1) === 'i') {
            $investigatorId = intval(substr($performerId, 1));
            $adminId = null;
        }
    } else {
        // Fallback to existing if not provided (though it should be)
        $investigatorId = $existingActivity['investigator_id'];
        $adminId = $existingActivity['admin_id'];
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
    // RATE CALCULATIONS (recalculate for update)
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
    // For 'flat_rate' and 'equipment_fee', client_billable_amount should be set manually,
    // so we keep existing value if not hourly or not_billable.
    else {
        $clientBillableAmount = $existingActivity['client_billable_amount'];
    }

    // Build update data
    $data = [
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
        'mileage' => $mileage,
        'notes' => $notes,
        'status' => $status
    ];

    // Update activity
    $pdo->update('activities', $data, ['activity_id' => $activityId]);

    // Update Time Entries
    // 1. Get existing entry IDs for this activity
    $existingEntries = $pdo->query("SELECT entry_id FROM time_entries WHERE activity_id = :activity_id", [':activity_id' => $activityId]);
    $existingEntryIds = array_column($existingEntries ?: [], 'entry_id');
    $updatedEntryIds = [];

    if (!empty($timeEntries)) {
        foreach ($timeEntries as $clientSideId => $entry) {
            $entryId = isset($entry['entry_id']) ? intval($entry['entry_id']) : null;
            
            $entryData = [
                'activity_id' => $activityId,
                'case_id' => $caseId,
                'investigator_id' => $investigatorId,
                'entry_time' => !empty($entry['time']) ? $entry['time'] : null,
                'hours_spent' => !empty($entry['hours']) ? floatval($entry['hours']) : 0,
                'entry_description' => $entry['description'] ?? '',
                'status' => $status
            ];

            if ($entryId && in_array($entryId, $existingEntryIds)) {
                // Update existing
                $pdo->update('time_entries', $entryData, ['entry_id' => $entryId]);
                $updatedEntryIds[] = $entryId;
            } else {
                // Insert new
                $newEntryId = $pdo->insert('time_entries', $entryData);
                if (isset($entry['client_side_id'])) {
                    $entryMappings[$entry['client_side_id']] = $newEntryId;
                } else {
                    $entryMappings[$clientSideId] = $newEntryId;
                }
            }
        }
    }

    // 2. Delete entries that were removed
    $entriesToDelete = array_diff($existingEntryIds, $updatedEntryIds);
    if (!empty($entriesToDelete)) {
        $pdo->query("DELETE FROM time_entries WHERE entry_id IN (" . implode(',', $entriesToDelete) . ")");
    }

    // Regenerate AI Summary
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $summaryResult = curl_exec($ch);
            $summaryHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($summaryHttpCode === 200) {
                $summaryData = json_decode($summaryResult, true);
                if (isset($summaryData['success']) && $summaryData['success']) {
                    // Fetch updated activity description
                    $refreshedActivity = $pdo->selectFirst("activities", ["activity_id" => $activityId]);
                    $activityDescription = $refreshedActivity['activity_description'] ?? $activityDescription;
                }
            }
        } catch (Exception $e) {
            error_log("Failed to trigger AI summary in update: " . $e->getMessage());
        }
    } else {
        // If no notes AND no time entries are found, leave activityDescription blank
        $pdo->update('activities', ['activity_description' => ''], ['activity_id' => $activityId]);
        $activityDescription = '';
    }

    // Audit log: ACTIVITY_UPDATE
    $auditModel = new AuditModel();
    $auditModel->log('ACTIVITY_UPDATE', $user, $caseId, 'activity', $activityId, [
        'activity_type_id' => $activityType
    ]);

    Response::success([
        'activity_id' => $activityId,
        'activity_description' => $activityDescription,
        'entry_mappings' => $entryMappings ?? [],
        'message' => 'Activity updated successfully'
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
