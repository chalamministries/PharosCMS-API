<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

try {
    $trackerModel = initializeClass("TrackerDeviceModel");
    $result = $trackerModel->listTrackers($_GET);

    Response::success($result);

} catch (Exception $e) {
    Response::error('An error occurred: ' . $e->getMessage(), 500);
}
