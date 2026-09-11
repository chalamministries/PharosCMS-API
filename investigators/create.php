<?php
/**
 * Create Investigator Endpoint
 * POST /api/investigators
 *
 * Creates a new investigator account
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

// Only admins can create investigators
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get JSON input
$input = Response::getJsonInput();

// Validate required fields
if (!isset($input['first_name']) || !isset($input['last_name']) || !isset($input['email']) || !isset($input['password'])) {
    Response::error('first_name, last_name, email, and password are required', 400);
}

$firstName = trim($input['first_name']);
$lastName = trim($input['last_name']);
$email = trim($input['email']);
$password = $input['password'];
$phoneNumber = isset($input['phone_number']) ? trim($input['phone_number']) : null;
$licenseNumber = isset($input['license_number']) ? trim($input['license_number']) : null;
$specializations = isset($input['specializations']) ? trim($input['specializations']) : null;
$hourlyRate = isset($input['hourly_rate']) ? $input['hourly_rate'] : null;
$companyName = isset($input['company_name']) ? trim($input['company_name']) : null;
$displayAs = isset($input['display_as']) ? trim($input['display_as']) : null;
$status = isset($input['status']) ? $input['status'] : 'active';

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

// Validate password strength
if (strlen($password) < 8) {
    Response::error('Password must be at least 8 characters long', 400);
}

// Validate status enum
if (!in_array($status, ['active', 'inactive', 'on_leave'])) {
    Response::error('Invalid status. Must be one of: active, inactive, on_leave', 400);
}

// Validate hourly_rate if provided
    if ($hourlyRate !== null && (!is_numeric($hourlyRate) || $hourlyRate < 0)) {
    Response::error('hourly_rate must be a positive number', 400);
}

$driveTimeRate = isset($input['drive_time_rate']) ? $input['drive_time_rate'] : null;

// Validate drive_time_rate if provided
if ($driveTimeRate !== null && (!is_numeric($driveTimeRate) || $driveTimeRate < 0)) {
    Response::error('drive_time_rate must be a positive number', 400);
}

$mileageRate = isset($input['mileage_rate']) ? $input['mileage_rate'] : null;

// Validate mileage_rate if provided
if ($mileageRate !== null && (!is_numeric($mileageRate) || $mileageRate < 0)) {
    Response::error('mileage_rate must be a positive number', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Check if email already exists
    $existingEmail = $pdo->queryFirst(
        "SELECT investigator_id FROM investigators WHERE email = :email",
        [':email' => $email]
    );

    if ($existingEmail) {
        Response::error('An investigator with this email already exists', 400);
    }

    // Check if license_number already exists (if provided)
    if ($licenseNumber) {
        $existingLicense = $pdo->queryFirst(
            "SELECT investigator_id FROM investigators WHERE license_number = :license_number",
            [':license_number' => $licenseNumber]
        );

        if ($existingLicense) {
            Response::error('An investigator with this license number already exists', 400);
        }
    }

    // Hash the password
    $passwordHash = Auth::hashPassword($password);

    // Build insert data
    $data = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone_number' => $phoneNumber,
        'license_number' => $licenseNumber,
        'specializations' => $specializations,
        'hourly_rate' => $hourlyRate,
        'company_name' => $companyName,
        'display_as' => $displayAs ?: ($firstName . ' ' . $lastName),
        'drive_time_rate' => $driveTimeRate,
        'mileage_rate' => $mileageRate,
        'password_hash' => $passwordHash,
        'status' => $status
    ];

    // Insert investigator
    $investigatorId = $pdo->insert('investigators', $data);

    if ($investigatorId) {
        // Fetch the created investigator
        $investigator = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);

        // Remove password_hash from response
        unset($investigator['password_hash']);

        Response::success([
            'investigator' => $investigator,
            'message' => 'Investigator created successfully'
        ], 201);
    } else {
        Response::serverError('Failed to create investigator');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
