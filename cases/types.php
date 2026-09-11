<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = PDOWrapper::instance();

    $caseTypes = $pdo->select('case_types', [], ['id', 'name', 'description'], 'name');

    http_response_code(200);
    echo json_encode(['case_types' => $caseTypes]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}