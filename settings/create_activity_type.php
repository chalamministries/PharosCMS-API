<?php
/**
 * Create Activity Type Endpoint
 * POST /api/settings/activity_types
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

// Only admins can manage settings
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['description'])) {
    Response::error('Description is required', 400);
}

$description = trim($input['description']);
if (empty($description)) {
    Response::error('Description cannot be empty', 400);
}

try {
    $db = getDB();

    $data = [
        'description' => $description,
        'default_client_rate' => $input['default_client_rate'] ?? null,
        'default_billing_method' => $input['default_billing_method'] ?? 'hourly',
        'default_equipment_fee' => $input['default_equipment_fee'] ?? null,
        'is_billable' => isset($input['is_billable']) ? (int)$input['is_billable'] : 1,
        'requires_gps' => isset($input['requires_gps']) ? (int)$input['requires_gps'] : 0,
        'display_order' => isset($input['display_order']) ? (int)$input['display_order'] : 0,
        'icon' => $input['icon'] ?? null
    ];

    $id = $db->insert("activity_type", $data);

    if ($id) {
        $activityType = $db->selectFirst("activity_type", ["activity_type_id" => $id]);
        Response::success($activityType, 'Activity type created successfully', 201);
    } else {
        Response::serverError('Failed to create activity type');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
