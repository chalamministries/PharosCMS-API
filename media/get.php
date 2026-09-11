<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
    
    $media = $db->selectFirst("media", ["media_id" => $mediaId]);
    if (!$media) {
        Response::error('Media not found', 404);
    }

    Response::success($media);
} catch (Exception $e) {
    Response::serverError($e->getMessage());
}
