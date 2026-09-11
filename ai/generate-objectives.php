<?php
/**
 * AI Generate Objectives Endpoint
 * POST /api/ai/generate-objectives
 *
 * Generates case objectives using Google Gemini AI
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

$caseId         = isset($input['case_id']) ? intval($input['case_id']) : null;
$inlineCaseData = $input['case_data'] ?? null;
$customPrompt   = isset($input['prompt']) ? trim($input['prompt']) : null;

if (!$inlineCaseData && (!$caseId || $caseId <= 0)) {
    Response::error('Either case_id or case_data is required', 400);
}

try {
    if ($inlineCaseData) {
        $caseData = $inlineCaseData;        // wizard path — no DB query
    } else {
        $caseData = fetchCaseData($caseId); // existing case edit path
        if (!$caseData) Response::error('Case not found', 404);
    }

    // Load the AI prompt template
    $promptTemplate = getPromptTemplate();

    // Build the prompt
    $fullPrompt = buildPrompt($promptTemplate, $caseData, $customPrompt);

    // Call Gemini API
    $objectives = callGeminiAPI($fullPrompt);

    if (!$objectives) {
        Response::error('Failed to generate objectives', 500);
    }

    // Return the generated objectives
    Response::success([
        'objectives' => $objectives,
        'case_id' => $caseId
    ]);

} catch (Exception $e) {
    error_log("Error generating objectives for case {$caseId}: " . $e->getMessage());
    Response::error('Failed to generate objectives: ' . $e->getMessage(), 500);
}

/**
 * Get the AI prompt template
 */
function getPromptTemplate() {
    return <<<'EOD'
# PROMPT: AI-Generated Case Objectives from Intake Form Data

## Context
You are helping create SQL INSERT statements for a private investigation case management system called Pharos CMS. Based on intake form data submitted by clients, you need to generate professional, actionable objectives that investigators will work on.

## Your Task
Analyze the case intake data provided and generate 4-5 professional objectives that:
1. Are specific, measurable, and actionable
2. Break down the client's overall goal into logical investigation steps
3. Prioritize based on urgency and importance
4. Include realistic time estimates
5. Follow a logical workflow sequence
6. **Detect and utilize specific dates and times** mentioned in the case description to set realistic start and due dates for objectives.

## Objective Creation Guidelines

### Date and Time Detection
- **Analyze text for specific dates**: Look for dates (e.g., "April 29th", "5/1", "next Monday") and times mentioned in the case description.
- **Set Start and Due Dates**: If a specific event is mentioned for a date, create an objective that starts and ends around that date.
- **Current Date Context**: Use the "Current Date" provided in the case data as the reference point for relative dates like "today", "tomorrow", or "next week".
- **Format**: All dates must be in `YYYY-MM-DD` format.

### Priority Levels
- **urgent**: Time-sensitive, critical to case (missing persons, child safety, imminent danger)
- **high**: Very important, core investigation goals
- **medium**: Important but not critical
- **low**: Nice-to-have, secondary objectives

### Status
All new objectives start with: **assigned**

### Estimated Hours
- Simple tasks (interviews, document review): 5-8 hours
- Standard surveillance/investigation: 10-15 hours
- Complex operations (extended surveillance, multi-location): 20+ hours

### Due Dates
- **urgent**: 3-5 days from case start
- **high**: 7-14 days from case start
- **medium**: 14-21 days from case start
- **low**: 21-30 days from case start

### Objective Workflow Pattern
Follow this typical investigation sequence:

1. **Locate/Identify** - Find subjects, establish identity, document baseline
2. **Surveil/Document** - Gather evidence, monitor activities, document behavior
3. **Investigate Specific Claims** - Verify allegations (infidelity, substance abuse, etc.)
4. **Coordinate** - Work with attorneys, authorities, or agencies if applicable
5. **Compile/Report** - Organize evidence, prepare final report for client/court

## Output Format

Return the objectives as a **JSON array** with this exact structure:

```json
{
  "objectives": [
    {
      "objective_title": "Clear, Action-Oriented Title (5-8 words)",
      "objective_description": "Detailed description of what the investigator needs to do. Be specific about locations, people, evidence to gather, and methods to use. Include any special considerations or safety concerns.",
      "priority": "urgent|high|medium|low",
      "estimated_hours": 0.0,
      "due_days": 0,
      "start_date": "YYYY-MM-DD",
      "due_date": "YYYY-MM-DD"
    }
  ]
}
```

**Field Definitions:**
- `objective_title` (string): Short, action-oriented title (5-8 words max)
- `objective_description` (string): Detailed instructions for investigator (2-4 sentences)
- `priority` (enum): "urgent" | "high" | "medium" | "low"
- `estimated_hours` (decimal): Realistic time estimate in hours (5.0, 10.0, 15.0, etc.)
- `due_days` (integer): Days from case start date (used as fallback if specific dates aren't found)
- `start_date` (string): The date the investigator should begin this objective (YYYY-MM-DD)
- `due_date` (string): The date the objective must be completed (YYYY-MM-DD)

**Do NOT include:**
- SQL statements
- Database IDs or keys
- Timestamps
- Status fields (always starts as "assigned")
- NULL values or tracking fields

## Examples

### Example 1: Child Welfare Case
**Client Request:** "To retrieve custody of my 3 grandchildren that are missing with their parents who are felons."

**Generated Objectives (JSON):**

```json
{
  "objectives": [
    {
      "objective_title": "Locate Missing Subjects and 3 Children",
      "objective_description": "Conduct urgent investigation to locate Jonathan McEnroe, Amanda Nicole Harned-Munson, and 3 missing minor children. Track last known locations, interview neighbors/relatives, check known associates. Focus on locating black Dodge Durango (tag SIC3002). Document current whereabouts and living conditions immediately.",
      "priority": "urgent",
      "estimated_hours": 20.0,
      "due_days": 3
    },
    {
      "objective_title": "Verify Children's Safety and Living Conditions",
      "objective_description": "Once located, document children's current living conditions, safety, supervision, and welfare. Obtain photo/video evidence of environment. Assess immediate danger or neglect. Document who is caring for children and quality of care. Report findings to DCFS and attorney immediately.",
      "priority": "urgent",
      "estimated_hours": 15.0,
      "due_days": 5
    },
    {
      "objective_title": "Conduct Surveillance on Subjects' Activities",
      "objective_description": "Monitor subjects' daily activities, locations, associates, and behavior patterns. Document evidence of continued drug use, violence, or neglect. Track movements and establish routine. Gather evidence supporting custody transfer to grandmother.",
      "priority": "high",
      "estimated_hours": 15.0,
      "due_days": 10
    },
    {
      "objective_title": "Coordinate with DCFS, Attorney, and Law Enforcement",
      "objective_description": "Maintain communication with DCFS, attorney Jennifer McCall, and law enforcement. Provide real-time updates on subjects' location. Assist in safe recovery of children. Document all communications and coordinate timing for custody transfer.",
      "priority": "urgent",
      "estimated_hours": 5.0,
      "due_days": 7
    },
    {
      "objective_title": "Compile Comprehensive Evidence Package for Court",
      "objective_description": "Organize all evidence including location documentation, surveillance photos/videos, witness statements, timeline of events, and condition reports. Prepare detailed report suitable for custody proceedings and DCFS review. Include evidence of parents' flight and unfitness.",
      "priority": "high",
      "estimated_hours": 5.0,
      "due_days": 14
    }
  ]
}
```

### Example 2: Infidelity Case with Specific Dates
**Current Date:** 2026-04-20
**Client Request:** "Wife made hotel reservation 4/29-30. She has a doctors appointment on 5/1 in Huntsville."

**Generated Objectives (JSON):**

```json
{
  "objectives": [
    {
      "objective_title": "Surveillance of Subject at Hotel Reservation",
      "objective_description": "Conduct surveillance at the hotel in Tennessee where the subject has a reservation for April 29th-30th. Document any co-habitants, visitors, or suspicious activities. Obtain photo/video evidence of infidelity if applicable.",
      "priority": "urgent",
      "estimated_hours": 16.0,
      "due_days": 10,
      "start_date": "2026-04-29",
      "due_date": "2026-04-30"
    },
    {
      "objective_title": "Monitor Subject during Huntsville Medical Appointment",
      "objective_description": "Observe subject's movements during her stated doctor's appointment in Huntsville on May 1st. Verify if she actually attends the appointment or meets with unauthorized individuals. Document vehicle movements and associates.",
      "priority": "high",
      "estimated_hours": 8.0,
      "due_days": 11,
      "start_date": "2026-05-01",
      "due_date": "2026-05-01"
    }
  ]
}
```

## Key Principles

### DO:
✓ Use specific names, locations, and details from the intake form
✓ Reference specific vehicles, phone numbers, addresses when relevant
✓ Mention coordinating agencies (DCFS, attorneys, law enforcement)
✓ Include both evidence collection AND documentation/reporting steps
✓ Consider investigator safety (violence history, criminal background)
✓ Sequence objectives logically (can't surveil before locating)
✓ Make titles action-oriented (Document, Investigate, Verify, Locate, Compile)

### DON'T:
✗ Use vague language ("gather information" - be specific about WHAT)
✗ Ignore safety concerns (violence history should affect priority/approach)
✗ Create duplicate objectives (each should have unique focus)
✗ Make unrealistic time estimates (20-hour surveillance in 1 day)
✗ Skip the final reporting objective (evidence must be organized)
✗ Use client's emotional language (professional, objective tone)

## Analysis Steps

When given intake form data:

1. **Identify Core Problem**
   - What is the client's main concern?
   - What type of case is this? (custody, infidelity, missing person, background check, etc.)

2. **Extract Key Players**
   - Who is the subject being investigated?
   - Who else is involved? (suspected affair partner, witnesses, children)
   - What agencies/attorneys are involved?

3. **Identify Evidence Needed**
   - What proof does the client need?
   - What will be used in court/proceedings?
   - What specific behaviors need documentation?

4. **Assess Urgency**
   - Are children at risk? (urgent)
   - Is someone missing? (urgent)
   - Is there a court date approaching? (high priority)
   - Legal deadlines? (adjust due dates)

5. **Break Down Workflow**
   - What needs to happen first? (locate, identify, establish baseline)
   - What's the main investigation work? (surveillance, documentation)
   - What happens at the end? (compile report, coordinate with authorities)

6. **Generate 4-5 Objectives**
   - Typically: 1-2 urgent, 2-3 high, 0-1 medium/low
   - Total estimated hours: 40-60 hours for standard case
   - Due dates spread across 3-21 days

## Special Case Types

### Missing Persons / Child Recovery
- First objective: Locate (URGENT, 3-5 day deadline)
- Second objective: Verify safety (URGENT, 5-7 day deadline)
- Include: Coordinate with authorities

### Infidelity Cases
- First objective: Establish routine/pattern
- Second objective: Document suspected relationship
- Third objective: Gather photo/video evidence
- Final objective: Compile evidence for divorce proceedings

### Custody/Child Welfare
- First objective: Document child's living conditions (URGENT)
- Second objective: Monitor parent's activities
- Third objective: Document fitness/unfitness evidence
- Include: Work with DCFS, attorney, CPS

### Background Checks / Due Diligence
- First objective: Public records search
- Second objective: Verify employment/education
- Third objective: Interview references
- Final objective: Compile comprehensive report

## Now Process This Case Data:
EOD;
}

/**
 * Fetch case data from internal API
 */
function fetchCaseData($caseId) {
    try {
        // Initialize CaseModel with all necessary includes
        $include = ['client', 'participants', 'attorney', 'vehicles'];
        $case = initializeClass("CaseModel", $caseId, $include);

        return $case->caseArr;

    } catch (Exception $e) {
        error_log("Error fetching case data: " . $e->getMessage());
        return null;
    }
}

/**
 * Build the complete prompt for Gemini
 */
function buildPrompt($template, $caseData, $customPrompt = null) {
    // If custom prompt provided, use it instead of the template intro
    if ($customPrompt) {
        $prompt = $customPrompt . "\n\n";
    } else {
        // Use the template up to the "Now Process This Case Data" section
        $templateParts = explode('## Now Process This Case Data:', $template);
        $prompt = $templateParts[0] . "\n\n";
    }

    // Add Contextual Information
    $prompt .= "## Current Context:\n";
    $prompt .= "Current Date: " . date('Y-m-d') . "\n";
    $prompt .= "Current Time: " . date('H:i:s') . "\n\n";

    // Add the case data
    $prompt .= "## Case Data to Analyze:\n\n";
    $prompt .= "```json\n";
    $prompt .= json_encode($caseData, JSON_PRETTY_PRINT);
    $prompt .= "\n```\n\n";

    // Add output instructions
    $prompt .= "## Required Output:\n\n";
    $prompt .= "Return ONLY a valid JSON object with this structure (no markdown, no code blocks):\n";
    $prompt .= "```json\n";
    $prompt .= json_encode([
        "objectives" => [
            [
                "objective_title" => "string",
                "objective_description" => "string",
                "priority" => "urgent|high|medium|low",
                "estimated_hours" => 0.0,
                "due_days" => 0,
                "start_date" => "YYYY-MM-DD",
                "due_date" => "YYYY-MM-DD"
            ]
        ]
    ], JSON_PRETTY_PRINT);
    $prompt .= "\n```\n";

    return $prompt;
}

/**
 * Call Google Gemini API
 */
function callGeminiAPI($prompt) {
    $apiKey = GEMINI_API_KEY;

    $url = GEMINI_API_URL . '?key=' . $apiKey;

    // Prepare request body
    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => $prompt
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 2048,
        ]
    ];

    // Make API request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

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

    // Extract the text response
    $generatedText = $result['candidates'][0]['content']['parts'][0]['text'];

    // Parse the JSON from the response
    $objectives = parseObjectivesFromResponse($generatedText);

    return $objectives;
}

/**
 * Parse objectives JSON from Gemini response
 * Handles cases where response includes markdown code blocks
 */
function parseObjectivesFromResponse($text) {
    // Remove markdown code blocks if present
    $text = preg_replace('/```json\s*/i', '', $text);
    $text = preg_replace('/```\s*$/i', '', $text);
    $text = trim($text);

    // Try to find JSON object in the text
    if (preg_match('/\{[\s\S]*"objectives"[\s\S]*\}/i', $text, $matches)) {
        $jsonText = $matches[0];
    } else {
        $jsonText = $text;
    }

    $parsed = json_decode($jsonText, true);

    if (!$parsed || !isset($parsed['objectives'])) {
        // Try to extract just the objectives array
        if (preg_match('/"objectives"\s*:\s*(\[[\s\S]*?\])/i', $text, $matches)) {
            return json_decode($matches[1], true);
        }

        throw new Exception("Failed to parse objectives from AI response");
    }

    return $parsed['objectives'];
}
