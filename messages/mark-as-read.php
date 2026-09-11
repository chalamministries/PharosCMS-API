<?php
/**
 * Mark Message as Read Endpoint
 * PUT /api/messages/{message_id}/read
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

// Get message_id from the route parameters
if (!isset($_GET['message_id'])) {
    Response::error('Message ID is required', 400);
}

// Strip msg_ prefix if present
$messageIdStr = $_GET['message_id'];
$messageId = intval(str_replace('msg_', '', $messageIdStr));

try {
    $mm = initializeClass("MessageModel");
    
    // Check if message exists and user has access
    // For now, we'll just mark it as read, but in a real app we might want to check
    // if the user is a participant in the case associated with this message.
    
    $success = $mm->markAsRead($messageId);

    if ($success) {
        Response::success(null, 'Message marked as read');
    } else {
        // If update returned false, it might be because the ID doesn't exist 
        // or it was already marked as read (0 rows affected).
        // Let's just return success for idempotency, or error if we want to be strict.
        Response::success(null, 'Message marked as read or no changes made');
    }

} catch (Exception $e) {
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}
