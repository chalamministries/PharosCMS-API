<?php
/**
 * Update Participant Endpoint
 * PUT /api/participants/{id}
 *
 * Updates a participant
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

// Get participant ID from route
if (!isset($_GET['id'])) {
    Response::error('Participant ID is required', 400);
}

$participantId = intval($_GET['id']);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input)) {
    Response::error('No data provided', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify participant exists
    $participant = $pdo->selectFirst("case_participants", ["participant_id" => $participantId]);
    if (!$participant) {
        Response::error('Participant not found', 404);
    }

    // Build update parameters dynamically
    $updateParams = [];

    // participant_type is a dedicated column — allow updating it
    if (isset($input['participant_type'])) {
        if (empty(trim($input['participant_type']))) {
            Response::error('participant_type cannot be empty', 400);
        }
        $updateParams['participant_type'] = $input['participant_type'];
    }

    // Full metadata replace (from modal save)
    if (isset($input['metadata']) && is_array($input['metadata'])) {
        $meta = $input['metadata'];
        $updateParams['metadata'] = json_encode($meta);
        // Mirror convenience fields to dedicated columns
        if (array_key_exists('first_name', $meta)) { $updateParams['first_name'] = $meta['first_name']; }
        if (array_key_exists('last_name',  $meta)) { $updateParams['last_name']  = $meta['last_name']; }
        if (array_key_exists('phone', $meta)) { $updateParams['phone'] = $meta['phone']; }
    }

    // Partial metadata patch (from inline edit — merge with existing)
    if (isset($input['metadata_patch']) && is_array($input['metadata_patch'])) {
        $current = $participant['metadata'] ? json_decode($participant['metadata'], true) : [];
        $merged  = array_merge($current ?: [], $input['metadata_patch']);
        $updateParams['metadata'] = json_encode($merged);
        // Mirror convenience fields to dedicated columns if they were patched
        $patch = $input['metadata_patch'];
        if (array_key_exists('first_name', $patch)) { $updateParams['first_name'] = $patch['first_name']; }
        if (array_key_exists('last_name',  $patch)) { $updateParams['last_name']  = $patch['last_name']; }
        if (array_key_exists('phone', $patch)) { $updateParams['phone'] = $patch['phone']; }
    }

    if (empty($updateParams)) {
        Response::error('No valid fields to update', 400);
    }

    // Add updated_at
    $updateParams['updated_at'] = date('Y-m-d H:i:s');

    // Execute update
    $result = $pdo->update("case_participants", $updateParams, ["participant_id" => $participantId]);

    if ($result !== false) {
        // Handle vehicle sub-object upsert
        if (isset($input['vehicle'])) {
            $v = $input['vehicle'];
            $existingVehicle = $pdo->queryFirst(
                "SELECT vehicle_id FROM case_vehicles WHERE participant_id = :pid LIMIT 1",
                [':pid' => $participantId]
            );
            if ($existingVehicle) {
                $pdo->update('case_vehicles', [
                    'make'            => $v['make'] ?? null,
                    'model'           => $v['model'] ?? null,
                    'color'           => $v['color'] ?? null,
                    'tag_number'      => $v['tag_number'] ?? null,
                    'decals_markings' => $v['decals_markings'] ?? null,
                ], ['vehicle_id' => $existingVehicle['vehicle_id']]);
            } elseif (!empty($v['make']) || !empty($v['model'])) {
                $pdo->insert('case_vehicles', [
                    'case_id'         => $participant['case_id'],
                    'participant_id'  => $participantId,
                    'make'            => $v['make'] ?? null,
                    'model'           => $v['model'] ?? null,
                    'color'           => $v['color'] ?? null,
                    'tag_number'      => $v['tag_number'] ?? null,
                    'decals_markings' => $v['decals_markings'] ?? null,
                ]);
            }
        }

        // Fetch updated participant
        $updated = $pdo->selectFirst("case_participants", ["participant_id" => $participantId]);

        Response::success([
            'participant' => $updated,
            'message' => 'Participant updated successfully'
        ]);
    } else {
        Response::serverError('Failed to update participant');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
