<?php
/**
 * Create Case Message Endpoint
 * POST /api/cases/{case_id}/messages
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

$adapter = new \Nc\FayeClient\Adapter\CurlAdapter();

$faye = new \Nc\FayeClient\Client($adapter, 'https://pharoscms.com:2096/faye');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

// Get case_id from the route parameters
if (!isset($_GET['case_id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['case_id']);

// Get input data
$input = Response::getJsonInput();

if (!$input) {
    Response::error('Invalid JSON input', 400);
}

// Validate required fields
$required = ['message_type'];
if (isset($input['message_type']) && $input['message_type'] === 'text') {
    $required[] = 'text';
} elseif (isset($input['message_type']) && $input['message_type'] === 'image') {
    $required[] = 'image_url';
}

$missing = Response::validateRequired($input, $required);
if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing), 400);
}

try {
    $case = initializeClass("CaseModel", $caseId, ['assignments']);
    
    // Check access
    if ($user['user_type'] === 'client') {
        // If userID is passed in input, check it against the case's client_id
        if (isset($input['userID']) && $case->caseArr['client_id'] != $input['userID']) {
            Response::error('Access denied', 403);
        }
        // Also ensure the authenticated client is the client associated with the case
        if ($case->caseArr['client_id'] != $user['user_id']) {
            Response::error('Access denied', 403);
        }
    } elseif ($user['user_type'] === 'investigator') {
        $isAssigned = false;
        $assignments = $case->caseArr['assignments'] ?? [];
        foreach ($assignments as $assignment) {
            if ($assignment['investigator_id'] == $user['user_id']) {
                $isAssigned = true;
                break;
            }
        }
        if (!$isAssigned) {
            Response::error('Access denied', 403);
        }
    } elseif ($user['user_type'] !== 'admin') {
        // Any other type (if any) that isn't admin
        Response::error('Access denied', 403);
    }

    $mm = initializeClass("MessageModel");
    
    $messageData = [
        'case_id'      => $caseId,
        'sender_id'    => $input['userID'] ?? $user['user_id'],
        'sender_type'  => $user['user_type'],
        'message_type' => $input['message_type'],
        'message_text' => $input['text'] ?? '',
        'image_url'    => $input['image_url'] ?? null,
        'thumbnail_url'=> $input['thumbnail_url'] ?? null
    ];
    $messageId = $mm->createMessage($messageData);

    if ($messageId) {
        $formattedMessage = $mm->formatMessage();

        $fayeData = [
            'event' => 'message.created',
            'room_id' => (string)$caseId,
            'channel' => "/cases/" . $caseId . "/messages",
            'message' => $formattedMessage
        ];
        error_log(json_encode($fayeData));
        $faye->send($fayeData['channel'], $fayeData);

        Response::success($formattedMessage, 'Message sent successfully', 201);
    } else {
        Response::error('Failed to create message', 500);
    }

} catch (OutOfBoundsException $e) {
    Response::notFound('Case not found');
} catch (Exception $e) {
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}
