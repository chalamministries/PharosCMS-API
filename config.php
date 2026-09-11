<?php
/**
 * API Configuration File
 * Contains database credentials and JWT settings
 */

// CORS: allow only trusted frontend origins
$allowedOrigins = ['https://mypharos.cc', 'https://pharoscms.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Allow-Credentials: false');
    header('Access-Control-Max-Age: 86400');
}

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error reporting
error_reporting(E_ALL & ~E_NOTICE); // Keep critical errors only
ini_set('display_errors', 0); // 🔒 CRITICAL: Never expose errors in production


// JWT Configuration
define('JWT_EXPIRATION', 86400); // 24 hours in seconds

// API Configuration
define('API_VERSION', 'v1');
define('TIMEZONE', 'America/New_York');

// Google Gemini AI Configuration
//define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'];
//define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent');

// Set timezone
date_default_timezone_set(TIMEZONE);

// CORS Headers (adjust as needed)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


function getDB() {
    //$db = PDOWrapper::instance();
    
    // Configure master connection
    // $db->configMaster(
    //     DB_HOST,
    //     DB_NAME,
    //     DB_USER,
    //     DB_PASSWORD,
    //     DB_PORT
    // );
    
    // Enable error logging if needed
//    $GLOBALS['pdo']->LOG_ERRORS = true;
//    $GLOBALS['pdo']->LOG_FILE = 'mysql.txt';
    
    return $GLOBALS['pdo'];
}

function guidv4($data = null) {
    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
    $data = $data ?? random_bytes(16);
    assert(strlen($data) == 16);

    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID.
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}