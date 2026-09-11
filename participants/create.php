<?php
/**
 * Create Participant Endpoint
 * POST /api/cases/{case_id}/participants
 *
 * Creates a new participant for a case
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
if (!isset($input['participant_type'])) {
    Response::error('participant_type is required', 400);
}

if (empty(trim($input['participant_type']))) {
    Response::error('participant_type cannot be empty', 400);
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
        'case_id'          => $caseId,
        'participant_type' => $input['participant_type']
    ];

    if (isset($input['metadata']) && is_array($input['metadata'])) {
        $meta = $input['metadata'];
        $data['metadata'] = json_encode($meta);
        // Mirror convenience fields to dedicated columns for indexed querying
        if (isset($meta['first_name'])) $data['first_name'] = $meta['first_name'];
        if (isset($meta['last_name']))  $data['last_name']  = $meta['last_name'];
        if (isset($meta['phone'])) $data['phone'] = $meta['phone'];
    }

    // Insert participant
    $participantId = $pdo->insert('case_participants', $data);

    if ($participantId) {
        // Handle vehicle sub-object
        if (!empty($input['vehicle'])) {
            $v = $input['vehicle'];
            if (!empty($v['make']) || !empty($v['model'])) {
                $pdo->insert('case_vehicles', [
                    'case_id'         => $caseId,
                    'participant_id'  => $participantId,
                    'make'            => $v['make'] ?? null,
                    'model'           => $v['model'] ?? null,
                    'color'           => $v['color'] ?? null,
                    'tag_number'      => $v['tag_number'] ?? null,
                    'decals_markings' => $v['decals_markings'] ?? null,
                ]);
            }
        }

        // Fetch the created participant
        $participant = $pdo->selectFirst("case_participants", ["participant_id" => $participantId]);

        Response::success([
            'participant' => $participant,
            'message' => 'Participant created successfully'
        ], 201);
    } else {
        Response::serverError('Failed to create participant');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
