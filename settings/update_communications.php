<?php
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

// Get JSON input
$input = Response::getJsonInput();

$pdo = $GLOBALS['pdo'];

// 1. Update Global Settings
$allowed_settings = [
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 
    'smtp_from_email', 'smtp_from_name',
    'sms_provider', 'twilio_sid', 'twilio_token', 'twilio_phone_number'
];

$settings_data = [];
foreach ($allowed_settings as $field) {
    if (isset($input['settings'][$field])) {
        $settings_data[$field] = $input['settings'][$field];
    }
}

if (!empty($settings_data)) {
    // Ensure row 1 exists
    $settings = $pdo->selectFirst("communications_settings", ["id" => 1]);
    if (!$settings) {
        $pdo->insert("communications_settings", ["id" => 1]);
    }
    $pdo->update("communications_settings", $settings_data, ["id" => 1]);
}

// 2. Update Email Templates
if (isset($input['templates']) && is_array($input['templates'])) {
    foreach ($input['templates'] as $template) {
        if (isset($template['id'])) {
            $update_data = [];
            if (isset($template['subject'])) $update_data['subject'] = $template['subject'];
            if (isset($template['body'])) $update_data['body'] = $template['body'];
            
            if (!empty($update_data)) {
                $pdo->update("email_templates", $update_data, ["id" => $template['id']]);
            }
        }
    }
}

$updated_settings = $pdo->selectFirst("communications_settings", ["id" => 1]);
$updated_templates = $pdo->select("email_templates");

Response::success([
    "settings" => $updated_settings,
    "templates" => $updated_templates
], 'Communications settings updated successfully');
