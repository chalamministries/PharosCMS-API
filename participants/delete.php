<?php
/**
 * Delete Participant Endpoint
 * DELETE /api/participants/{id}
 *
 * Deletes a participant
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

try {
    $pdo = $GLOBALS['pdo'];

    // Verify participant exists
    $participant = $pdo->selectFirst("case_participants", ["participant_id" => $participantId]);
    if (!$participant) {
        Response::error('Participant not found', 404);
    }

    // Delete participant
    $result = $pdo->delete('case_participants', ['participant_id' => $participantId]);

    if ($result) {
        Response::success([
            'message' => 'Participant deleted successfully'
        ]);
    } else {
        Response::serverError('Failed to delete participant');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
