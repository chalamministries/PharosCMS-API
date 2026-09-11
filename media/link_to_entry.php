<?php
/**
 * Link Existing Media to a Time Entry
 * POST /api/media/link_to_entry.php
 * 
 * Sets the entry_id for an array of media_ids.
 * Requires investigator or admin authentication.
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

// Ensure it's an investigator or admin
if ($user['user_type'] !== 'investigator' && $user['user_type'] !== 'admin') {
    Auth::forbidden('Access denied');
}

// Get JSON input
$input = Response::getJsonInput();

if (!isset($input['media_ids']) || !is_array($input['media_ids'])) {
    Response::error('media_ids (array) is required', 400);
}

// entry_id is optional; if missing, 0, or -1, we will UNLINK the media.
$entryId = isset($input['entry_id']) ? intval($input['entry_id']) : 0;
$mediaIds = array_map('intval', $input['media_ids']);

if (empty($mediaIds)) {
    Response::error('empty media_ids', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    $activityId = null;
    if ($entryId > 0) {
        // Verify entry exists
        $entry = $pdo->selectFirst("time_entries", ["entry_id" => $entryId]);
        if (!$entry) {
            Response::error('Time entry not found', 404);
        }
        $activityId = $entry['activity_id'];
    }

    // Update media records
    $placeholderKeys = [];
    $queryParams = [
        ':entry_id' => ($entryId > 0 ? $entryId : null),
        ':activity_id' => $activityId
    ];

    foreach ($mediaIds as $index => $id) {
        $key = ":media_id_" . $index;
        $placeholderKeys[] = $key;
        $queryParams[$key] = $id;
    }

    $placeholders = implode(',', $placeholderKeys);
    $query = "UPDATE media SET entry_id = :entry_id, activity_id = :activity_id WHERE media_id IN ($placeholders)";
    
    $pdo->query($query, $queryParams);

    Response::success([
        'message' => count($mediaIds) . ($entryId > 0 ? ' media items linked to entry ' . $entryId : ' media items unlinked'),
        'linked_count' => count($mediaIds)
    ]);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
