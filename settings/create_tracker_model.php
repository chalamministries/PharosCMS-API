<?php
/**
 * Create Tracker Model Endpoint
 * POST /api/settings/tracker_models
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
if (!isset($input['model_name']) || !isset($input['brand_id'])) {
    Response::error('Model name and brand ID are required', 400);
}

$modelName = trim($input['model_name']);
$brandId = intval($input['brand_id']);

if (empty($modelName)) {
    Response::error('Model name cannot be empty', 400);
}

try {
    $db = getDB();

    // Verify brand exists
    $brand = $db->selectFirst("tracker_brand", ["brand_id" => $brandId]);
    if (!$brand) {
        Response::error('Tracker brand not found', 404);
    }

    $id = $db->insert("tracker_model", [
        "model_name" => $modelName,
        "brand_id" => $brandId,
        "battery_field" => isset($input['battery_field']) ? trim($input['battery_field']) : null
    ]);

    if ($id) {
        $model = $db->selectFirst("tracker_model", ["model_id" => $id]);
        Response::success($model, 'Tracker model created successfully', 201);
    } else {
        Response::serverError('Failed to create tracker model');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
