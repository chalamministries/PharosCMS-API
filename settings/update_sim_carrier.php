<?php
/**
 * Update SIM Carrier Endpoint
 * PUT /api/settings/sim_carriers/{id}
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
    Response::error('SIM Carrier ID is required', 400);
}

$id = intval($_GET['id']);

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['carrier_name'])) {
    Response::error('Carrier name is required', 400);
}

$carrierName = trim($input['carrier_name']);
if (empty($carrierName)) {
    Response::error('Carrier name cannot be empty', 400);
}

try {
    $db = getDB();

    // Check if exists
    $existing = $db->selectFirst("sim_carrier", ["carrier_id" => $id]);
    if (!$existing) {
        Response::error('SIM carrier not found', 404);
    }

    $result = $db->update("sim_carrier", ["carrier_name" => $carrierName], ["carrier_id" => $id]);

    if ($result !== false) {
        $updated = $db->selectFirst("sim_carrier", ["carrier_id" => $id]);
        Response::success($updated, 'SIM carrier updated successfully');
    } else {
        Response::serverError('Failed to update SIM carrier');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
