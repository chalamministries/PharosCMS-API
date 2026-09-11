<?php
/**
 * Create Client Endpoint
 * POST /api/clients
 *
 * Creates a new client
 * Requires authentication (admin only)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Only admins can create clients
if (!Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['first_name']) || !isset($input['last_name']) || !isset($input['email'])) {
    Response::error('first_name, last_name, and email are required', 400);
}

$firstName = trim($input['first_name']);
$lastName = trim($input['last_name']);
$email = trim($input['email']);

// Optional fields
$phoneNumber = isset($input['phone_number']) ? trim($input['phone_number']) : null;
$cellPhone = isset($input['cell_phone']) ? trim($input['cell_phone']) : null;
$addressStreet = isset($input['address_street']) ? trim($input['address_street']) : null;
$addressCity = isset($input['address_city']) ? trim($input['address_city']) : null;
$addressState = isset($input['address_state']) ? trim($input['address_state']) : null;
$addressZip = isset($input['address_zip']) ? trim($input['address_zip']) : null;
$companyName = isset($input['company_name']) ? trim($input['company_name']) : null;
$occupation = isset($input['occupation']) ? trim($input['occupation']) : null;
$workSchedule = isset($input['work_schedule']) ? trim($input['work_schedule']) : null;
$contactMethod = isset($input['contact_method']) ? trim($input['contact_method']) : null;
$subjectRelationship = isset($input['subject_relationship']) ? trim($input['subject_relationship']) : null;
$living = isset($input['living']) ? trim($input['living']) : null;

// Validate required fields are not empty
if (empty($firstName)) {
    Response::error('first_name cannot be empty', 400);
}

if (empty($lastName)) {
    Response::error('last_name cannot be empty', 400);
}

if (empty($email)) {
    Response::error('email cannot be empty', 400);
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Invalid email format', 400);
}

// Validate subject_relationship enum if provided
if ($subjectRelationship !== null && !in_array($subjectRelationship, ['spouse', 'divorced', 'boy/girlfriend', 'engaged', ''])) {
    Response::error('Invalid subject_relationship. Must be one of: spouse, divorced, boy/girlfriend, engaged', 400);
}

// Validate living enum if provided
if ($living !== null && !in_array($living, ['together', 'separate', ''])) {
    Response::error('Invalid living status. Must be one of: together, separate', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Check if email already exists
    $existingEmail = $pdo->queryFirst(
        "SELECT client_id FROM clients WHERE email = :email",
        [':email' => $email]
    );

    if ($existingEmail) {
        Response::error('A client with this email already exists', 400);
    }

    // Build insert data
    $data = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone_number' => $phoneNumber,
        'cell_phone' => $cellPhone,
        'address_street' => $addressStreet,
        'address_city' => $addressCity,
        'address_state' => $addressState,
        'address_zip' => $addressZip,
        'company_name' => $companyName,
        'occupation' => $occupation,
        'work_schedule' => $workSchedule,
        'contact_method' => $contactMethod,
        'subject_relationship' => $subjectRelationship ?: null,
        'living' => $living ?: null
    ];

    // Insert client
    $clientId = $pdo->insert('clients', $data);

    if ($clientId) {
        // Fetch the created client
        $client = $pdo->selectFirst("clients", ["client_id" => $clientId]);

        // Remove sensitive fields from response
        unset($client['password_hash']);
        unset($client['auth_token']);

        Response::success([
            'client' => $client,
            'message' => 'Client created successfully'
        ], 201);
    } else {
        Response::serverError('Failed to create client');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
