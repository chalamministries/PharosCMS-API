<?php
/**
 * Delete SIM Carrier Endpoint
 * DELETE /api/settings/sim_carriers/{id}
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
    Response::error('SIM Carrier ID is required', 400);
}

$id = intval($_GET['id']);

try {
    $db = getDB();

    // Check if exists
    $existing = $db->selectFirst("sim_carrier", ["carrier_id" => $id]);
    if (!$existing) {
        Response::error('SIM carrier not found', 404);
    }

    // Check if in use in trackers table
    $query = "SELECT COUNT(*) as count FROM trackers WHERE sim_carrier = :id";
    $result = $db->queryFirst($query, [':id' => $id]);

    if ($result && $result['count'] > 0) {
        Response::error('Cannot delete SIM carrier. It is currently in use by ' . $result['count'] . ' trackers.', 400);
    }

    $deleted = $db->delete("sim_carrier", ["carrier_id" => $id]);

    if ($deleted) {
        Response::success(null, 'SIM carrier deleted successfully');
    } else {
        Response::serverError('Failed to delete SIM carrier');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
