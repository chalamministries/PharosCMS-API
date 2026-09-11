<?php
/**
 * Create Tracker Brand Endpoint
 * POST /api/settings/tracker_brands
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
if (!isset($input['brand_name'])) {
    Response::error('Brand name is required', 400);
}

$brandName = trim($input['brand_name']);
if (empty($brandName)) {
    Response::error('Brand name cannot be empty', 400);
}

try {
    $db = getDB();

    $id = $db->insert("tracker_brand", ["brand_name" => $brandName]);

    if ($id) {
        $brand = $db->selectFirst("tracker_brand", ["brand_id" => $id]);
        Response::success($brand, 'Tracker brand created successfully', 201);
    } else {
        Response::serverError('Failed to create tracker brand');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
