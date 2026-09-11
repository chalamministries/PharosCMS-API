<?php
/**
 * Login Endpoint
 * POST /api/auth/login
 * 
 * Authenticates users (clients, investigators, admins) and returns JWT token
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';
 require_once __DIR__ . '/../../classes/class.auditmodel.php';

// DEBUG: Log the request method
error_log("LOGIN ENDPOINT - Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("LOGIN ENDPOINT - Request URI: " . $_SERVER['REQUEST_URI']);
error_log("LOGIN ENDPOINT - Query String: " . ($_SERVER['QUERY_STRING'] ?? 'none'));

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed. Received: ' . $_SERVER['REQUEST_METHOD'], 200);
}

// Get request body
$data = Response::getJsonInput();

// Validate required fields
$missing = Response::validateRequired($data, ['email', 'password', 'user_type']);
if (!empty($missing)) {
    Response::validationError(['missing_fields' => $missing]);
}

$email = trim($data['email']);
$password = $data['password'];
$userType = $data['user_type']; // 'client', 'investigator', or 'admin'

// Validate user type
$validUserTypes = ['client', 'investigator', 'admin'];
if (!in_array($userType, $validUserTypes)) {
    Response::error('Invalid user_type. Must be: client, investigator, or admin', 200);
}

try {
    $db = getDB();
    
    // Determine which table to query based on user type
    $table = '';
    $idField = '';
    
    switch ($userType) {
        case 'client':
            $table = 'clients';
            $idField = 'client_id';
            break;
        case 'investigator':
            $table = 'investigators';
            $idField = 'investigator_id';
            break;
        case 'admin':
            $table = 'admins';
            $idField = 'admin_id';
            break;
    }
    
    // Query user by email
    $query = "SELECT * FROM $table WHERE email = :email LIMIT 1";

    $user = $db->queryFirst($query, [':email' => $email]);

    if (!$user) {
        // Audit log: LOGIN_FAIL
        $auditModel = new AuditModel();
        $auditModel->log('LOGIN_FAIL', ['user_id' => 0, 'user_type' => $userType, 'first_name' => 'Unknown', 'last_name' => 'User'], null, 'auth', null, [
            'email' => $email
        ]);
        Response::error('Invalid email credentials', 200);
    }
    
    // Verify password
    if (!Auth::verifyPassword($password, $user['password_hash'])) {
        // Audit log: LOGIN_FAIL
        $auditModel = new AuditModel();
        $auditModel->log('LOGIN_FAIL', ['user_id' => $user[$idField], 'user_type' => $userType, 'first_name' => $user['first_name'], 'last_name' => $user['last_name']], null, 'auth', $user[$idField], [
            'email' => $email
        ]);
        Response::error('Invalid password credentials', 200);
    }

    // Additional check for clients and case access code
    if ($userType === 'client' && isset($data['access_code'])) {
        $fullAccessCode = trim($data['access_code']);
        
        if (strlen($fullAccessCode) < 5) {
            Response::error('Invalid access code format.', 200);
        }

        $accessCodePrefix = substr($fullAccessCode, 0, 4);
        $caseId = substr($fullAccessCode, 4);

        if (!is_numeric($accessCodePrefix) || !is_numeric($caseId)) {
            Response::error('Invalid access code format.', 200);
        }

        $checkQuery = "SELECT COUNT(*) as count FROM cases 
                      WHERE case_id = :case_id 
                      AND client_id = :client_id 
                      AND access_code = :access_code";
        
        $hasAccess = $db->queryFirst($checkQuery, [
            ':case_id' => intval($caseId),
            ':client_id' => $user['client_id'],
            ':access_code' => $accessCodePrefix
        ]);
        
        if (!$hasAccess || $hasAccess['count'] == 0) {
            // Audit log: LOGIN_FAIL_ACCESS_DENIED
            $auditModel = new AuditModel();
            $auditModel->log('LOGIN_FAIL_ACCESS_DENIED', ['user_id' => $user['client_id'], 'user_type' => 'client'], null, 'auth', $user['client_id'], [
                'email' => $email,
                'attempted_access_code' => $fullAccessCode,
                'parsed_case_id' => $caseId
            ]);
            Response::error('Invalid access code for this account.', 200);
        }
        
        // Add case_id to payload for client
        $payload['case_id'] = intval($caseId);
    }
    
    // Check status for investigators
    if ($userType === 'investigator' && isset($user['status']) && $user['status'] !== 'active') {
        Response::error('Account is not active', 200);
    }

    // Set Default Expiration
    $expire = JWT_EXPIRATION;
    
    // Prepare token payload
    $payload = [
        'user_id' => $user[$idField],
        'email' => $user['email'],
        'user_type' => $userType,
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name']
    ];
    
    // Add role for admins
    if ($userType === 'admin' && isset($user['role'])) {
        $payload['role'] = $user['role'];
        $expire = 31556952;
    }
    
    // Add license number for investigators
    if ($userType === 'investigator' && isset($user['license_number'])) {
        $payload['license_number'] = $user['license_number'];
    }

    // Generate JWT token (24 hour expiration)
    $token = JWT::encode($payload, $expire);

    // Audit log: LOGIN
    $auditModel = new AuditModel();
    $auditModel->log('LOGIN', [
        'user_id' => $user[$idField],
        'user_type' => $userType,
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name']
    ], null, 'auth', $user[$idField]);
    
    // Return success with token and user info
    Response::success([
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => (time() + $expire) * 1000,
        'user' => [[
            'id' => $user[$idField],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'user_type' => $userType
        ]]
    ], 'Login successful');
    
} catch (Exception $e) {
    Response::serverError('An error occurred during login: ' . $e->getMessage());
}