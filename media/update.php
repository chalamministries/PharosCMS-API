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

if (!isset($_GET['media_id'])) {
    Response::error('Media ID is required', 400);
}

$mediaId = intval($_GET['media_id']);
$data = Response::getJsonInput();

if ($data === null) {
    Response::error('Invalid JSON input', 400);
}

try {
    $db = getDB();
    
    // Check if media exists
    $media = $db->selectFirst("media", ["media_id" => $mediaId]);
    if (!$media) {
        Response::error('Media not found', 404);
    }

    $updateData = [];
    if (isset($data['description'])) $updateData['description'] = $data['description'];
    if (isset($data['is_client_viewable'])) $updateData['is_client_viewable'] = intval($data['is_client_viewable']);
    if (isset($data['is_investigator_viewable'])) $updateData['is_investigator_viewable'] = intval($data['is_investigator_viewable']);
    if (isset($data['captured_at'])) {
        $updateData['captured_at'] = !empty($data['captured_at']) ? date('Y-m-d H:i:s', strtotime($data['captured_at'])) : null;
    }

    if (empty($updateData)) {
        Response::error('No fields to update', 400);
    }

    $db->update("media", $updateData, ["media_id" => $mediaId]);

    // Audit log: MEDIA_UPDATE
    $auditModel = new AuditModel();
    $filename = basename($media['file_path']);
    $auditModel->log('MEDIA_UPDATE', $user, $media['case_id'], 'media', $mediaId, [
        'filename' => $filename,
        'media_type' => $media['file_type']
    ]);

    Response::success(null, 'Media updated successfully');
} catch (Exception $e) {
    Response::serverError($e->getMessage());
}
