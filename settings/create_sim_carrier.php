<?php
/**
 * Create SIM Carrier Endpoint
 * POST /api/settings/sim_carriers
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
if (!isset($input['carrier_name'])) {
    Response::error('Carrier name is required', 400);
}

$carrierName = trim($input['carrier_name']);
if (empty($carrierName)) {
    Response::error('Carrier name cannot be empty', 400);
}

try {
    $db = getDB();

    $id = $db->insert("sim_carrier", ["carrier_name" => $carrierName]);

    if ($id) {
        $carrier = $db->selectFirst("sim_carrier", ["carrier_id" => $id]);
        Response::success($carrier, 'SIM carrier created successfully', 201);
    } else {
        Response::serverError('Failed to create SIM carrier');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
