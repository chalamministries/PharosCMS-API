<?php
/**
 * Update Investigator Endpoint
 * PUT /api/investigators/{id}
 *
 * Updates an existing investigator
 * Requires authentication (admin only)
 */

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

// Only admins can update investigators
if (!Auth::hasRole($user, ['admin', 'super_admin'])) {
    Response::error('Unauthorized. Admin access required.', 403);
}

// Get investigator ID from route
if (!isset($_GET['id'])) {
    Response::error('Investigator ID is required', 400);
}

$investigatorId = intval($_GET['id']);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input)) {
    Response::error('No data provided', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Verify investigator exists
    $investigator = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);

    if (!$investigator) {
        Response::error('Investigator not found', 404);
    }

    // Build update parameters dynamically
    $updateParams = [];

    if (isset($input['first_name'])) {
        $firstName = trim($input['first_name']);
        if (empty($firstName)) {
            Response::error('first_name cannot be empty', 400);
        }
        $updateParams['first_name'] = $firstName;
    }

    if (isset($input['last_name'])) {
        $lastName = trim($input['last_name']);
        if (empty($lastName)) {
            Response::error('last_name cannot be empty', 400);
        }
        $updateParams['last_name'] = $lastName;
    }

    if (isset($input['email'])) {
        $email = trim($input['email']);

        if (empty($email)) {
            Response::error('email cannot be empty', 400);
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 400);
        }

        // Check if email already exists for a different investigator
        $existingQuery = "SELECT investigator_id FROM investigators WHERE email = :email AND investigator_id != :id";
        $existing = $pdo->queryFirst($existingQuery, [
            ':email' => $email,
            ':id' => $investigatorId
        ]);

        if ($existing) {
            Response::error('An investigator with this email already exists', 400);
        }

        $updateParams['email'] = $email;
    }

    if (isset($input['phone_number'])) {
        $updateParams['phone_number'] = $input['phone_number'] ? trim($input['phone_number']) : null;
    }

    if (isset($input['license_number'])) {
        $licenseNumber = $input['license_number'] ? trim($input['license_number']) : null;

        if ($licenseNumber) {
            // Check if license_number already exists for a different investigator
            $existingQuery = "SELECT investigator_id FROM investigators WHERE license_number = :license_number AND investigator_id != :id";
            $existing = $pdo->queryFirst($existingQuery, [
                ':license_number' => $licenseNumber,
                ':id' => $investigatorId
            ]);

            if ($existing) {
                Response::error('An investigator with this license number already exists', 400);
            }
        }

        $updateParams['license_number'] = $licenseNumber;
    }

    if (isset($input['specializations'])) {
        $updateParams['specializations'] = $input['specializations'] ? trim($input['specializations']) : null;
    }

    if (isset($input['hourly_rate'])) {
        $hourlyRate = $input['hourly_rate'];

        if ($hourlyRate !== null && (!is_numeric($hourlyRate) || $hourlyRate < 0)) {
            Response::error('hourly_rate must be a positive number', 400);
        }

        $updateParams['hourly_rate'] = $hourlyRate;
    }

    if (isset($input['company_name'])) {
        $updateParams['company_name'] = $input['company_name'] ? trim($input['company_name']) : null;
    }

    if (isset($input['display_as'])) {
        $updateParams['display_as'] = $input['display_as'] ? trim($input['display_as']) : null;
    }

    if (isset($input['drive_time_rate'])) {
        $driveTimeRate = $input['drive_time_rate'];

        if ($driveTimeRate !== null && (!is_numeric($driveTimeRate) || $driveTimeRate < 0)) {
            Response::error('drive_time_rate must be a positive number', 400);
        }

        $updateParams['drive_time_rate'] = $driveTimeRate;
    }

    if (isset($input['mileage_rate'])) {
        $mileageRate = $input['mileage_rate'];

        if ($mileageRate !== null && (!is_numeric($mileageRate) || $mileageRate < 0)) {
            Response::error('mileage_rate must be a positive number', 400);
        }

        $updateParams['mileage_rate'] = $mileageRate;
    }

    if (isset($input['status'])) {
        $status = $input['status'];

        // Validate status enum
        if (!in_array($status, ['active', 'inactive', 'on_leave'])) {
            Response::error('Invalid status. Must be one of: active, inactive, on_leave', 400);
        }

        $updateParams['status'] = $status;
    }

    if (empty($updateParams)) {
        Response::error('No valid fields to update', 400);
    }

    // Execute update
    $result = $pdo->update("investigators", $updateParams, ["investigator_id" => $investigatorId]);

    if ($result !== false) {
        // Fetch updated investigator
        $updated = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);

        // Remove password_hash from response
        unset($updated['password_hash']);

        Response::success([
            'investigator' => $updated,
            'message' => 'Investigator updated successfully'
        ]);
    } else {
        Response::serverError('Failed to update investigator');
    }

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
