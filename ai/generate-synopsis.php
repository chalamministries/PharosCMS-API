<?php
/**
 * AI Generate Synopsis Endpoint
 * POST /api/ai/generate-synopsis
 *
 * Generates a one-paragraph AI synopsis for a case using Google Gemini AI
 * Takes case description, subject info, and persons involved to produce a summary
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

if (!isset($input['case_id'])) {
    Response::error('case_id is required', 400);
}

$caseId = intval($input['case_id']);
if ($caseId <= 0) {
    Response::error('Invalid case_id', 400);
}

try {
    $db = getDB();

    // Get case data
    $case = $db->selectFirst("cases", ["case_id" => $caseId]);
    if (!$case) {
        Response::error('Case not found', 404);
    }

    // Get participants for this case
    $participants = $db->query(
        "SELECT * FROM case_participants WHERE case_id = :case_id",
        [":case_id" => $caseId]
    );

    // Build the prompt
    $prompt = buildSynopsisPrompt($case, $participants ?: []);

    // Call Gemini API
    $synopsis = callGeminiForSynopsis($prompt);

    if (!$synopsis) {
        Response::error('Failed to generate synopsis', 500);
    }

    // Save to the cases table
    $db->update('cases', ['ai_synopsis' => $synopsis], ['case_id' => $caseId]);

    Response::success([
        'ai_synopsis' => $synopsis,
        'case_id' => $caseId
    ]);

} catch (Exception $e) {
    error_log("Error generating synopsis for case {$caseId}: " . $e->getMessage());
    Response::error('Failed to generate synopsis: ' . $e->getMessage(), 500);
}

/**
 * Build the synopsis prompt from case data and participants
 */
function buildSynopsisPrompt($case, $participants) {
    $prompt = "You are a professional investigative case manager. Based on the following case information, write a single concise paragraph synopsis that summarizes the case. The start date of the case does not represent we actually performed any services, however, it represents when we received the case and were hired by the client.";
    $prompt .= "The synopsis should be professional, factual, and suitable for a case file. Do not use bullet points or headers. Return ONLY the paragraph text, nothing else.\n\n";

    $prompt .= "Case Information:\n";
    if (!empty($case['case_title'])) {
        $prompt .= "Title: " . $case['case_title'] . "\n";
    }
    if (!empty($case['description'])) {
        $prompt .= "Description: " . $case['description'] . "\n";
    }
    if (!empty($case['admin_notes'])) {
        $prompt .= "Admin Notes: " . $case['admin_notes'] . "\n";
    }
    if (!empty($case['start_date'])) {
        $prompt .= "Start Date: " . $case['start_date'] . "\n";
    }

    // Separate primary subjects from other participants
    $primaryTypes = [];
    $otherParticipants = [];
    foreach ($participants as $p) {
        $type = $p['participant_type'] ?? '';
        if (strpos($type, 'subject') !== false || strpos($type, 'claimant') !== false || strpos($type, 'plaintiff') !== false) {
            $primaryTypes[] = $p;
        } else {
            $otherParticipants[] = $p;
        }
    }

    // Output primary subjects first with full detail
    foreach ($primaryTypes as $p) {
        $meta = [];
        if (!empty($p['metadata'])) {
            $meta = is_string($p['metadata']) ? json_decode($p['metadata'], true) : $p['metadata'];
        }
        $firstName = $meta['first_name'] ?? '';
        $lastName  = $meta['last_name']  ?? '';
        $name      = trim("$firstName $lastName") ?: ($meta['name'] ?? 'Unknown');
        $label = humanizeParticipantType($p['participant_type']);

        $prompt .= "\n" . $label . ": " . $name;
        if (!empty($meta['alias'])) $prompt .= " (alias: " . $meta['alias'] . ")";
        $prompt .= "\n";
        if (!empty($meta['description'])) $prompt .= $label . " Description: " . $meta['description'] . "\n";
        if (!empty($meta['occupation'])) $prompt .= $label . " Occupation: " . $meta['occupation'] . "\n";
        if (!empty($meta['employer'])) $prompt .= $label . " Employer: " . $meta['employer'] . "\n";
        if (!empty($meta['address'])) $prompt .= $label . " Address: " . $meta['address'] . "\n";
    }

    // Output other participants generically
    foreach ($otherParticipants as $p) {
        $meta = [];
        if (!empty($p['metadata'])) {
            $meta = is_string($p['metadata']) ? json_decode($p['metadata'], true) : $p['metadata'];
        }
        $firstName = $meta['first_name'] ?? '';
        $lastName  = $meta['last_name']  ?? '';
        $name      = trim("$firstName $lastName") ?: ($meta['name'] ?? 'Unknown');
        $label = humanizeParticipantType($p['participant_type']);

        $prompt .= "\n" . $label . ": " . $name;
        if (!empty($meta['description'])) $prompt .= " - " . $meta['description'];
        $prompt .= "\n";
    }

    return $prompt;
}

/**
 * Convert a snake_case participant type slug to a human-readable label
 * e.g. "person_involved" → "Person Involved", "attorney_defense" → "Attorney Defense"
 */
function humanizeParticipantType($type) {
    return ucwords(str_replace('_', ' ', $type));
}

/**
 * Call Google Gemini API for synopsis generation
 */
function callGeminiForSynopsis($prompt) {
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
            'maxOutputTokens' => 512,
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

    $synopsis = trim($result['candidates'][0]['content']['parts'][0]['text']);

    // Clean up any markdown formatting that might slip through
    $synopsis = preg_replace('/^[#*\-]+\s*/', '', $synopsis);
    $synopsis = trim($synopsis);

    return $synopsis;
}
