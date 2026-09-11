<?php
/**
 * Send Email by Type Endpoint
 * POST /api/emails/send/{type_key}
 *
 * Parameters:
 * - client_id (int, required)
 * - case_id (int, optional)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../JWT.php';
require_once __DIR__ . '/../../Auth.php';
require_once __DIR__ . '/../../Response.php';
require_once __DIR__ . '/../../../classes/class.auditmodel.php';
require_once __DIR__ . '/../../../classes/class.mailer.php';

// Authenticate admin/investigator user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed. Received: ' . $_SERVER['REQUEST_METHOD']);
}

// Get type_key from router (available via $params if using the router)
// But since this file is loaded directly by the router, we need to get $type_key.
// The router in api/index.php passes extracted $params to the included file.
if (!isset($params['type_key'])) {
    Response::error('Missing template type key.');
}

$type_key = $params['type_key'];
$data = Response::getJsonInput();

// Validate required fields
$missing = Response::validateRequired($data, ['client_id']);
if (!empty($missing)) {
    Response::validationError(['missing_fields' => $missing]);
}

$clientId = intval($data['client_id']);
$caseId = isset($data['case_id']) ? intval($data['case_id']) : null;

$pdo = $GLOBALS['pdo'];

// 1. Fetch the template
$template = $pdo->selectFirst("email_templates", ["type_key" => $type_key]);
if (!$template) {
    Response::notFound("Template with key '{$type_key}' not found.");
}

// 2. Fetch Client Data
$client = $pdo->selectFirst("clients", ["client_id" => $clientId]);
if (!$client) {
    Response::notFound("Client not found.");
}

// 3. Fetch Case Data if caseId is provided
$case = null;
if ($caseId) {
    $case = $pdo->selectFirst("cases", ["case_id" => $caseId]);
} elseif ($type_key === 'welcome_email') {
    // For welcome email, try to find the most recent case for the client if not provided
    $case = $pdo->queryFirst("SELECT * FROM cases WHERE client_id = :client_id ORDER BY created_at DESC LIMIT 1", [':client_id' => $clientId]);
}

// 4. Handle Special Token: temp_password
$tempPassword = null;
if (strpos($template['body'], '{{temp_password}}') !== false) {
    // Generate a simple random password (8 chars)
    $tempPassword = bin2hex(random_bytes(4));
    $hashedPassword = Auth::hashPassword($tempPassword);
    
    // Update client password
    $pdo->update("clients", ["password_hash" => $hashedPassword], ["client_id" => $clientId]);
}

// 5. Replace Tokens
$tokens = [
    '{{client.first_name}}' => $client['first_name'] ?? '',
    '{{client.last_name}}' => $client['last_name'] ?? '',
    '{{client.email}}' => $client['email'] ?? '',
    '{{client.phone_number}}' => $client['phone_number'] ?? '',
    '{{case.case_number}}' => $case['case_number'] ?? 'N/A',
    '{{case.access_code}}' => $case['access_code'] ?? 'N/A',
    '{{temp_password}}' => $tempPassword ?? '[PASSWORD ALREADY SET]'
];

$subject = str_replace(array_keys($tokens), array_values($tokens), $template['subject']);
$body = str_replace(array_keys($tokens), array_values($tokens), $template['body']);

// 6. Send Email
$mailer = new Mailer();
$result = $mailer->send($client['email'], $subject, $body);

if ($result === true) {
    // 7. Audit Log
    $auditModel = new AuditModel();
    $auditModel->log('EMAIL_SENT', $user, $case['case_id'] ?? null, 'client', $clientId, [
        'template_type' => $type_key,
        'recipient' => $client['email'],
        'subject' => $subject
    ]);

    Response::success(null, "Email sent successfully to {$client['email']}.");
} else {
    Response::error("Failed to send email: " . $result);
}
