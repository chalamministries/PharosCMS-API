<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/classLoader.php';

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get refresh token from body
$input = json_decode(file_get_contents('php://input'), true);
$refreshToken = $input['refresh_token'] ?? null;

if (!$refreshToken) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing refresh_token']);
    exit;
}

// Validate refresh token in DB
try {
    $pdo = PDOWrapper::instance();
    
    // Check if refresh token exists and is valid
    $stmt = $pdo->prepare("SELECT user_id, expires_at FROM refresh_tokens WHERE token = ? AND revoked = 0");
    $stmt->execute([$refreshToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired refresh token']);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh token has expired']);
        exit;
    }

    // Generate new short-lived JWT
    $payload = [
        'user_id' => $row['user_id'],
        'iat' => time(),
        'exp' => time() + 900, // 15 minutes
        'iss' => 'https://api.pharoscms.com'
    ];

    $jwt = \Firebase\JWT\JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

    // Rotate refresh token: invalidate old, issue new
    $newRefreshToken = bin2hex(random_bytes(32));
    $newExpiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 3600)); // 7 days

    $pdo->beginTransaction();
    try {
        // Invalidate old token
        $stmt = $pdo->prepare("UPDATE refresh_tokens SET revoked = 1 WHERE token = ?");
        $stmt->execute([$refreshToken]);

        // Insert new token
        $stmt = $pdo->prepare("INSERT INTO refresh_tokens (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$row['user_id'], $newRefreshToken, $newExpiresAt]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to rotate refresh token']);
        exit;
    }

    // Return new tokens
    http_response_code(200);
    echo json_encode([
        'token' => $jwt,
        'refresh_token' => $newRefreshToken,
        'expires_in' => 900
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}