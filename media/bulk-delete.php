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

$data = Response::getJsonInput();

if ($data === null || !isset($data['media_ids']) || !is_array($data['media_ids'])) {
    Response::error('Invalid input: media_ids must be an array', 400);
}

$mediaIds = array_map('intval', $data['media_ids']);
if (empty($mediaIds)) {
    Response::error('No media IDs provided', 400);
}

try {
    $db = getDB();
    $auditModel = new AuditModel();
    $pullZoneUrl = defined('BUNNY_PULL_ZONE_URL') ? BUNNY_PULL_ZONE_URL : (isset($_ENV['BUNNY_PULL_ZONE_URL']) ? $_ENV['BUNNY_PULL_ZONE_URL'] : '');
    
    $bunnyClient = null;
    if (isset($_ENV['BUNNY_CLIENT_SECRET']) && isset($_ENV['BUNNY_STORAGE_ZONE'])) {
        try {
            $bunnyClient = new \Bunny\Storage\Client($_ENV['BUNNY_CLIENT_SECRET'], $_ENV['BUNNY_STORAGE_ZONE'], \Bunny\Storage\Region::NEW_YORK);
        } catch (Exception $e) {
            error_log("Failed to initialize BunnyCDN client: " . $e->getMessage());
        }
    }

    $successCount = 0;
    foreach ($mediaIds as $mediaId) {
        $media = $db->selectFirst("media", ["media_id" => $mediaId]);
        if (!$media) continue;

        // Delete from BunnyCDN
        if ($bunnyClient && !empty($media['file_path'])) {
            try {
                $relativePaths = [];
                if (!empty($pullZoneUrl) && strpos($media['file_path'], $pullZoneUrl) !== false) {
                    $relativePaths[] = ltrim(str_replace($pullZoneUrl, '', $media['file_path']), '/');
                }
                if (!empty($media['video_thumb']) && !empty($pullZoneUrl) && strpos($media['video_thumb'], $pullZoneUrl) !== false) {
                    $relativePaths[] = ltrim(str_replace($pullZoneUrl, '', $media['video_thumb']), '/');
                }
                foreach ($relativePaths as $path) {
                    $bunnyClient->delete($path);
                }
            } catch (Exception $e) {
                error_log("Failed to delete file from BunnyCDN for media ID $mediaId: " . $e->getMessage());
            }
        }

        $db->delete("media", ["media_id" => $mediaId]);

        // Audit log: MEDIA_DELETE
        $filename = basename($media['file_path']);
        $auditModel->log('MEDIA_DELETE', $user, $media['case_id'], 'media', $mediaId, [
            'filename' => $filename,
            'media_type' => $media['file_type']
        ]);
        $successCount++;
    }

    Response::success(['deleted_count' => $successCount], "Deleted $successCount media items successfully");
} catch (Exception $e) {
    Response::serverError($e->getMessage());
}
