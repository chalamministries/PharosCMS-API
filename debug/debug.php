<?php
/**
 * Debug Endpoint
 * Use this to troubleshoot request method issues
 * 
 * Access: GET/POST /api/debug/test
 */

header('Content-Type: application/json');

$debug_info = [
    'success' => true,
    'debug' => [
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'request_uri' => $_SERVER['REQUEST_URI'],
        'query_string' => $_SERVER['QUERY_STRING'] ?? 'none',
        'get_params' => $_GET,
        'post_params' => $_POST,
        'raw_input' => file_get_contents('php://input'),
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'none',
        'http_headers' => getallheaders(),
        'endpoint' => $_GET['endpoint'] ?? 'not set',
        'action' => $_GET['action'] ?? 'not set'
    ]
];

echo json_encode($debug_info, JSON_PRETTY_PRINT);
exit();
