<?php
/**
 * Get Single Investigator Endpoint
 * GET /api/investigators/{id}
 *
 * Returns detailed information about a specific investigator
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get investigator ID from route
if (!isset($_GET['id'])) {
    Response::error('Investigator ID is required', 400);
}

$investigatorId = intval($_GET['id']);

try {
    $investigatorModel = initializeClass("InvestigatorModel", $investigatorId, true);
    $investigator = $investigatorModel->investigatorArr;

    Response::success([
        'investigator' => $investigator
    ]);

} catch (OutOfBoundsException $e) {
    Response::error('Investigator not found', 404);
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
