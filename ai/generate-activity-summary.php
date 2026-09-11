<?php
/**
 * AI Generate Activity Summary Endpoint
 * POST /api/ai/generate-activity-summary
 *
 * Generates a concise summary for an activity based on its time entries using Google Gemini AI
 * Requires authentication
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input', 400);
}

if (!isset($input['activity_id'])) {
    Response::error('activity_id is required', 400);
}

$activityId = intval($input['activity_id']);
if ($activityId <= 0) {
    Response::error('Invalid activity_id', 400);
}

try {
    $db = getDB();

    // Get activity data
    $activity = $db->selectFirst("activities", ["activity_id" => $activityId]);
    if (!$activity) {
        Response::error('Activity not found', 404);
    }

    // Get time entries for this activity
    $timeEntries = $db->query(
        "SELECT * FROM time_entries WHERE activity_id = :activity_id ORDER BY entry_time ASC, created_at ASC",
        [":activity_id" => $activityId]
    );

    $notes = $activity['notes'] ?? '';
    $summary = '';

    if (empty($timeEntries)) {
        if (!empty($notes)) {
            // If no time entries but notes ARE found, use them for the activity description
            $summary = $notes;
        } else {
            // If no notes AND no time entries are found, leave activityDescription blank
            $summary = '';
        }
    } else {
        // If time entries are present, process them with AI. 
        // If notes are also present, they will be included in the prompt.
        
        // Build the prompt
        $prompt = buildActivitySummaryPrompt($activity, $timeEntries);

        // Call Gemini API
        $summary = callGeminiForActivitySummary($prompt);

        if (!$summary) {
            Response::error('Failed to generate summary', 500);
        }
    }

    // Save to the activities table
    $db->update('activities', ['activity_description' => $summary], ['activity_id' => $activityId]);

    Response::success([
        'activity_description' => $summary,
        'activity_id' => $activityId
    ]);

} catch (Exception $e) {
    error_log("Error generating summary for activity {$activityId}: " . $e->getMessage());
    Response::error('Failed to generate summary: ' . $e->getMessage(), 500);
}

/**
 * Build the activity summary prompt from activity data and time entries
 */
function buildActivitySummaryPrompt($activity, $timeEntries) {
    $prompt = "You are a professional investigator. Below is a list of time entries (timestamps and descriptions) for an investigative activity session. ";
    
    $notes = $activity['notes'] ?? '';
    if (!empty($notes)) {
        $prompt .= "The following notes were also provided for this activity: " . $notes . "\n\n";
    }

    $prompt .= "Please write a short, professional summary of the entire activity session based on these entries" . (!empty($notes) ? " and notes" : "") . ". ";
    $prompt .= "The summary should be a single paragraph, factual, and suitable for a formal investigative report. ";
    $prompt .= "Do not use bullet points or headers. Return ONLY the summary text, nothing else.\n\n";

    $prompt .= "Activity Date: " . $activity['date_of_activity'] . "\n\n";
    $prompt .= "Time Entries:\n";

    foreach ($timeEntries as $entry) {
        $time = !empty($entry['entry_time']) ? $entry['entry_time'] : 'N/A';
        $desc = !empty($entry['entry_description']) ? $entry['entry_description'] : '(No description)';
        $prompt .= "- [" . $time . "]: " . $desc . "\n";
    }

    return $prompt;
}

/**
 * Call Google Gemini API for summary generation
 */
function callGeminiForActivitySummary($prompt) {
    if (!defined('GEMINI_API_KEY') || !defined('GEMINI_API_URL')) {
        throw new Exception("Gemini API configuration missing");
    }

    $apiKey = GEMINI_API_KEY;
    $url = GEMINI_API_URL . '?key=' . $apiKey;

    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.5,
            'topK' => 40,
            'topP' => 0.9,
            'maxOutputTokens' => 256,
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL error: " . $error);
    }

    if ($httpCode !== 200) {
        error_log("Gemini API error: " . $response);
        throw new Exception("Gemini API returned HTTP {$httpCode}");
    }

    $result = json_decode($response, true);

    if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Invalid response from Gemini API");
    }

    $summary = trim($result['candidates'][0]['content']['parts'][0]['text']);

    // Clean up any markdown formatting
    $summary = preg_replace('/^[#*\-]+\s*/', '', $summary);
    $summary = trim($summary);

    return $summary;
}
