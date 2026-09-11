<?php
/**
 * Delete Activity Endpoint
 * DELETE /api/activities/{activity_id}
 *
 * Deletes an activity, its time entries, and associated media
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get activity ID from route parameter
if (!isset($_GET['activity_id'])) {
    Response::error('Activity ID is required', 400);
}

$activityId = intval($_GET['activity_id']);

try {
    $pdo = getDB();

    // Verify activity exists
    $activity = $pdo->selectFirst("activities", ["activity_id" => $activityId]);
    if (!$activity) {
        Response::error('Activity not found', 404);
    }

    $caseId = $activity['case_id'];

    // 1. Find and delete media associated with this activity from BunnyCDN
    // We search for media linked directly to the activity OR to time entries of this activity
    $mediaItems = $pdo->query(
        "SELECT * FROM media WHERE activity_id = :activity_id OR entry_id IN (SELECT entry_id FROM time_entries WHERE activity_id = :activity_id)",
        [':activity_id' => $activityId]
    );

    if (!empty($mediaItems)) {
        try {
            $bunnyClient = new \Bunny\Storage\Client($_ENV['BUNNY_CLIENT_SECRET'], $_ENV['BUNNY_STORAGE_ZONE'], \Bunny\Storage\Region::NEW_YORK);
            $pullZoneUrl = defined('BUNNY_PULL_ZONE_URL') ? BUNNY_PULL_ZONE_URL : $_ENV['BUNNY_PULL_ZONE_URL'];

            foreach ($mediaItems as $media) {
                if (!empty($media['file_path'])) {
                    $relativePaths = [];
                    if (strpos($media['file_path'], $pullZoneUrl) !== false) {
                        $relativePaths[] = ltrim(str_replace($pullZoneUrl, '', $media['file_path']), '/');
                    }
                    
                    if (!empty($media['video_thumb']) && strpos($media['video_thumb'], $pullZoneUrl) !== false) {
                        $relativePaths[] = ltrim(str_replace($pullZoneUrl, '', $media['video_thumb']), '/');
                    }

                    foreach ($relativePaths as $path) {
                        try {
                            $bunnyClient->delete($path);
                        } catch (Exception $e) {
                            error_log("Failed to delete file from BunnyCDN: " . $path . " Error: " . $e->getMessage());
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("BunnyCDN Client Error: " . $e->getMessage());
        }
    }

    // 2. Delete the activity (this will cascade delete time_entries and media records in DB due to foreign keys)
    $result = $pdo->delete('activities', ['activity_id' => $activityId]);

    if ($result) {
        // Audit log: ACTIVITY_DELETE
        $auditModel = new AuditModel();
        $auditModel->log('ACTIVITY_DELETE', $user, $caseId, 'activity', $activityId, [
            'activity_description' => $activity['activity_description'] ?? 'No description'
        ]);

        Response::success([
            'message' => 'Activity and associated data deleted successfully'
        ]);
    } else {
        Response::serverError('Failed to delete activity');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
