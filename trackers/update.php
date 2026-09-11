<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

$trackerId = $_GET['id'] ?? null;
if (!$trackerId) {
    Response::validationError(['Tracker ID is required']);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    Response::validationError(['Invalid JSON input']);
}

// Validate required fields
$required = ['name', 'imei', 'unique_id', 'device_model'];
$errors = [];
foreach ($required as $field) {
    if (empty($input[$field])) {
        $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
    }
}

if (!empty($errors)) {
    Response::validationError($errors);
}

try {
    $trackerModel = initializeClass("TrackerDeviceModel");
    $tracker = $trackerModel->updateTracker($trackerId, $input);

    // Audit log: TRACKER_UPDATE
    $auditModel = new AuditModel();
    $auditModel->log('TRACKER_UPDATE', $user, null, 'tracker', $trackerId, [
        'tracker_name' => $input['name']
    ]);

    Response::success(['tracker' => $tracker], 'Tracker updated successfully');

} catch (Exception $e) {
    $code = $e->getCode();
    if ($code < 400 || $code >= 600) {
        $code = 500;
    }
    Response::error($e->getMessage(), $code);
}
