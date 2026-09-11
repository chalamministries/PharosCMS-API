<?php
/**
 * Update Tracker Brand Endpoint
 * PUT /api/settings/tracker_brands/{id}
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
    Response::error('Brand ID is required', 400);
}

$id = intval($_GET['id']);

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

    // Check if exists
    $existing = $db->selectFirst("tracker_brand", ["brand_id" => $id]);
    if (!$existing) {
        Response::error('Tracker brand not found', 404);
    }

    $result = $db->update("tracker_brand", ["brand_name" => $brandName], ["brand_id" => $id]);

    if ($result !== false) {
        $updated = $db->selectFirst("tracker_brand", ["brand_id" => $id]);
        Response::success($updated, 'Tracker brand updated successfully');
    } else {
        Response::serverError('Failed to update tracker brand');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
