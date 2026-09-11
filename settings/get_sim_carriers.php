<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

try {
    $db = getDB();

    $carriers = $db->select("sim_carrier");

    Response::success([
        'carriers' => $carriers
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}