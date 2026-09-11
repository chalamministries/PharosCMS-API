<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    $updateData = [];
    if (isset($data['is_client_viewable'])) $updateData['is_client_viewable'] = intval($data['is_client_viewable']);
    if (isset($data['is_investigator_viewable'])) $updateData['is_investigator_viewable'] = intval($data['is_investigator_viewable']);

    if (empty($updateData)) {
        Response::error('No fields to update', 400);
    }

    $successCount = 0;
    foreach ($mediaIds as $mediaId) {
        // Check if media exists and get details for audit
        $media = $db->selectFirst("media", ["media_id" => $mediaId]);
        if ($media) {
            $db->update("media", $updateData, ["media_id" => $mediaId]);
            
            // Audit log: MEDIA_UPDATE
            $filename = basename($media['file_path']);
            $auditModel->log('MEDIA_BULK_UPDATE', $user, $media['case_id'], 'media', $mediaId, [
                'filename' => $filename,
                'media_type' => $media['file_type'],
                'updates' => $updateData
            ]);
            $successCount++;
        }
    }

    Response::success(['updated_count' => $successCount], "Updated $successCount media items successfully");
} catch (Exception $e) {
    Response::serverError($e->getMessage());
}
