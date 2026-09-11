<?php
/**
 * Delete Activity Type Endpoint
 * DELETE /api/settings/activity_types/{id}
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

try {
    $db = getDB();

    // Check if exists
    $existing = $db->selectFirst("activity_type", ["activity_type_id" => $id]);
    if (!$existing) {
        Response::error('Activity type not found', 404);
    }

    // Check if in use in activities table
    $query = "SELECT COUNT(*) as count FROM activities WHERE activity_type = :id";
    $result = $db->queryFirst($query, [':id' => $id]);

    if ($result && $result['count'] > 0) {
        Response::error('Cannot delete activity type. It is currently in use by ' . $result['count'] . ' activities.', 400);
    }

    $deleted = $db->delete("activity_type", ["activity_type_id" => $id]);

    if ($deleted) {
        Response::success(null, 'Activity type deleted successfully');
    } else {
        Response::serverError('Failed to delete activity type');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
