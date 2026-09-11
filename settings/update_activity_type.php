<?php
/**
 * Update Activity Type Endpoint
 * PUT /api/settings/activity_types/{id}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow PUT
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can manage settings
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get ID from route
if (!isset($_GET['id'])) {
    Response::error('Activity Type ID is required', 400);
}

$id = intval($_GET['id']);

// Get JSON input
$input = Response::getJsonInput();

try {
    $db = getDB();

    // Check if exists
    $existing = $db->selectFirst("activity_type", ["activity_type_id" => $id]);
    if (!$existing) {
        Response::error('Activity type not found', 404);
    }

    $data = [];
    if (isset($input['description'])) $data['description'] = trim($input['description']);
    if (isset($input['default_client_rate'])) $data['default_client_rate'] = $input['default_client_rate'];
    if (isset($input['default_billing_method'])) $data['default_billing_method'] = $input['default_billing_method'];
    if (isset($input['default_equipment_fee'])) $data['default_equipment_fee'] = $input['default_equipment_fee'];
    if (isset($input['is_billable'])) $data['is_billable'] = (int)$input['is_billable'];
    if (isset($input['requires_gps'])) $data['requires_gps'] = (int)$input['requires_gps'];
    if (isset($input['display_order'])) $data['display_order'] = (int)$input['display_order'];
    if (isset($input['icon'])) $data['icon'] = $input['icon'];

    if (empty($data)) {
        Response::error('No valid fields to update', 400);
    }

    $result = $db->update("activity_type", $data, ["activity_type_id" => $id]);

    if ($result !== false) {
        $updated = $db->selectFirst("activity_type", ["activity_type_id" => $id]);
        Response::success($updated, 'Activity type updated successfully');
    } else {
        Response::serverError('Failed to update activity type');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
