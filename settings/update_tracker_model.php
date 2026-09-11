<?php
/**
 * Update Tracker Model Endpoint
 * PUT /api/settings/tracker_models/{id}
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
    Response::error('Model ID is required', 400);
}

$id = intval($_GET['id']);

// Get JSON input
$input = Response::getJsonInput();

try {
    $db = getDB();

    // Check if exists
    $existing = $db->selectFirst("tracker_model", ["model_id" => $id]);
    if (!$existing) {
        Response::error('Tracker model not found', 404);
    }

    $data = [];
    if (isset($input['model_name'])) {
        $data['model_name'] = trim($input['model_name']);
        if (empty($data['model_name'])) {
            Response::error('Model name cannot be empty', 400);
        }
    }

    if (isset($input['brand_id'])) {
        $brandId = intval($input['brand_id']);
        // Verify brand exists
        $brand = $db->selectFirst("tracker_brand", ["brand_id" => $brandId]);
        if (!$brand) {
            Response::error('Tracker brand not found', 404);
        }
        $data['brand_id'] = $brandId;
    }
    
    if (isset($input['battery_field'])) {
        $data['battery_field'] = trim($input['battery_field']);
    }

    if (empty($data)) {
        Response::error('No valid fields to update', 400);
    }

    $result = $db->update("tracker_model", $data, ["model_id" => $id]);

    if ($result !== false) {
        $updated = $db->selectFirst("tracker_model", ["model_id" => $id]);
        Response::success($updated, 'Tracker model updated successfully');
    } else {
        Response::serverError('Failed to update tracker model');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
