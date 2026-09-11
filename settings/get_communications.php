<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

$pdo = $GLOBALS['pdo'];

// Get settings from the single row (id=1)
$settings = $pdo->selectFirst("communications_settings", ["id" => 1]);

if (!$settings) {
    // If for some reason it doesn't exist, create it and return defaults
    $pdo->insert("communications_settings", ["id" => 1]);
    $settings = $pdo->selectFirst("communications_settings", ["id" => 1]);
}

// Get all email templates
$templates = $pdo->select("email_templates");


Response::success([
    "settings" => $settings,
    "templates" => $templates
]);
