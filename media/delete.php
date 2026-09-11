<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Response::error('Method not allowed', 405);
}

$user = Auth::authenticate();
if (!$user) {
    exit();
}

if (!isset($_GET['media_id'])) {
    Response::error('Media ID is required', 400);
}

$mediaId = intval($_GET['media_id']);

try {
    $db = getDB();
    
    // Check if media exists
    $media = $db->selectFirst("media", ["media_id" => $mediaId]);
    if (!$media) {
        Response::error('Media not found', 404);
    }

    // Delete from BunnyCDN if path is present
    if (!empty($media['file_path'])) {
        try {
            $bunnyClient = new \Bunny\Storage\Client($_ENV['BUNNY_CLIENT_SECRET'], $_ENV['BUNNY_STORAGE_ZONE'], \Bunny\Storage\Region::NEW_YORK);
            
            // Extract the path after the pull zone URL
            // file_path looks like: https://pharos-media.b-cdn.net/cases/ClientName/123/timestamp_filename.jpg
            // We need: cases/ClientName/123/timestamp_filename.jpg
            
            $pullZoneUrl = defined('BUNNY_PULL_ZONE_URL') ? BUNNY_PULL_ZONE_URL : $_ENV['BUNNY_PULL_ZONE_URL'];
            
            $relativePaths = [];
            
            if (strpos($media['file_path'], $pullZoneUrl) !== false) {
                $relativePaths[] = ltrim(str_replace($pullZoneUrl, '', $media['file_path']), '/');
            }
            
            // Also handle video thumbnail if it exists
            if (!empty($media['video_thumb']) && strpos($media['video_thumb'], $pullZoneUrl) !== false) {
                $relativePaths[] = ltrim(str_replace($pullZoneUrl, '', $media['video_thumb']), '/');
            }
            
            foreach ($relativePaths as $path) {
                $bunnyClient->delete($path);
            }
        } catch (Exception $e) {
            // Log error but continue with DB deletion so it's at least gone from the UI
            error_log("Failed to delete file from BunnyCDN: " . $e->getMessage());
        }
    }

    $db->delete("media", ["media_id" => $mediaId]);

    // Audit log: MEDIA_DELETE
    $auditModel = new AuditModel();
    $filename = basename($media['file_path']);
    $auditModel->log('MEDIA_DELETE', $user, $media['case_id'], 'media', $mediaId, [
        'filename' => $filename,
        'media_type' => $media['file_type']
    ]);

    Response::success(null, 'Media deleted successfully');
} catch (Exception $e) {
    Response::serverError($e->getMessage());
}
