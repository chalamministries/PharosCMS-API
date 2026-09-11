<?php
/**
 * Update Client Endpoint
 * PUT /api/clients/{id}
 *
 * Updates editable fields on a client record.
 * Requires authentication (admin / super_admin / case_manager).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

$user = Auth::authenticate();
if (!$user) {
    exit();
}

if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Auth::forbidden('Insufficient permissions to update clients');
}

if (!isset($_GET['id'])) {
    Response::error('Client ID is required', 400);
}

$clientId = intval($_GET['id']);
if ($clientId <= 0) {
    Response::error('Invalid client ID', 400);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body)) {
    Response::error('Request body must be a JSON object with fields to update', 400);
}

$pdo = $GLOBALS['pdo'];

// Email uniqueness check (excluding current client)
if (isset($body['email']) && !empty($body['email'])) {
    $existing = $pdo->queryFirst(
        "SELECT client_id FROM clients WHERE email = :email AND client_id != :client_id",
        [':email' => $body['email'], ':client_id' => $clientId]
    );
    if ($existing) {
        Response::validationError(['email' => 'Email address is already in use by another client']);
    }
}

try {
    $clientModel = initializeClass("ClientModel", $clientId);
    $updated = $clientModel->updateClient($clientId, $body);

    Response::success($updated, 'Client updated successfully');

} catch (OutOfBoundsException $e) {
    Response::notFound('Client not found');

} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 400);

} catch (Exception $e) {
    error_log("Error updating client {$clientId}: " . $e->getMessage());
    Response::serverError('An error occurred while updating the client');
}
