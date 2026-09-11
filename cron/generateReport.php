<?php
/**
 * Cron Job: Generate AI Reports
 * Run daily or weekly to generate reports from unassigned activities
 */
die()
require_once __DIR__ . '/../config.php';

// Get all cases with unassigned activities
$sql = "
	SELECT DISTINCT case_id 
	FROM activities 
	WHERE report_id IS NULL AND status = 'submitted'
";
$cases = $pdo->query($sql);

foreach ($cases as $case) {
	$caseId = $case['case_id'];
	
	// Load full case context
	$caseModel = initializeClass("CaseModel", $caseId, ['participants', 'objectives']);
	
	// Get unassigned activities
	$activityModel = initializeClass("ActivityModel");
	$activities = $activityModel->getUnassignedActivities($caseId);

	if (empty($activities)) {
		continue;
	}
	
	// Build prompt for Gemini
	$prompt = buildReportPrompt($caseModel->caseArr, $activities);
	
	// Send to Gemini (with images)
	$reportContent = generateReportWithGemini($prompt, $activities);
	
	if ($reportContent) {
		// Create the report
		$reportModel = initializeClass("ReportModel");
		$reportId = $reportModel->createReport([
			'case_id' => $caseId,
			'investigator_id' => 1, // System/AI user
			'title' => "Report: " . date('M d, Y'),
			'content' => $reportContent,
			'status' => 'submitted',
			'report_date' => date('Y-m-d H:i:s')
		]);
		
		// Assign activities to this report
		$activityIds = array_column($activities, 'activity_id');
		$activityModel->assignToReport($activityIds, $reportId);
		
		echo "Generated report {$reportId} for case {$caseId}\n";
	}
}

/**
 * Build the prompt for Gemini
 */
function buildReportPrompt($caseData, $activities) {
	$prompt = "You are a professional private investigator writing a detailed surveillance report.\n\n";
	
	// Case context
	$prompt .= "CASE INFORMATION:\n";
	$prompt .= "Case Number: {$caseData['case_number']}\n";
	$prompt .= "Case Title: {$caseData['case_title']}\n";
	$prompt .= "Case Type: {$caseData['case_type']}\n";
	
	if (!empty($caseData['objectives'])) {
		$prompt .= "Investigation Objectives: {$caseData['objectives']}\n";
	}
	
	// Subject information
	if (!empty($caseData['participants'])) {
		$prompt .= "\nSUBJECT INFORMATION:\n";
		foreach ($caseData['participants'] as $participant) {
			if ($participant['participant_type'] === 'subject') {
				$prompt .= "Name: {$participant['first_name']} {$participant['last_name']}\n";
				if ($participant['description']) {
					$prompt .= "Description: {$participant['description']}\n";
				}
			}
		}
	}
	
	$prompt .= "\n---\n\n";
	
	// Activities
	$prompt .= "SURVEILLANCE ACTIVITIES TO SUMMARIZE:\n\n";
	
	foreach ($activities as $index => $activity) {
		$activityNum = $index + 1;
		$prompt .= "Activity #{$activityNum}:\n";
		$prompt .= "Date: {$activity['date_of_activity']}\n";
		$prompt .= "Duration: {$activity['hours_spent']} hours\n";
		$prompt .= "Investigator: {$activity['investigator_name']}\n";
		$prompt .= "Description:\n{$activity['activity_description']}\n";
		
		if ($activity['media_count'] > 0) {
			$prompt .= "\nMedia Evidence ({$activity['media_count']} files):\n";
			foreach ($activity['media'] as $mediaIndex => $media) {
				$prompt .= "  - Image " . ($mediaIndex + 1) . ": {$media['description']}\n";
			}
		}
		
		$prompt .= "\n---\n\n";
	}
	
	// Instructions
	$prompt .= "INSTRUCTIONS:\n";
	$prompt .= "Write a professional investigation report that:\n";
	$prompt .= "1. Summarizes all surveillance activities chronologically\n";
	$prompt .= "2. Describes observations from the images provided\n";
	$prompt .= "3. Highlights key findings relevant to the case objectives\n";
	$prompt .= "4. Uses professional, objective language\n";
	$prompt .= "5. Includes specific times, locations, and behaviors observed\n";
	$prompt .= "6. References the images by their numbers (e.g., 'Image 1 shows...')\n";
	$prompt .= "\n**IMPORTANT: Format the entire report as clean, semantic HTML.**\n";
	$prompt .= "Use proper HTML tags: <h1>, <h2>, <h3>, <p>, <strong>, <em>, <ul>, <ol>, <li>, etc.\n";
	$prompt .= "Do NOT include ```html code fences, just return the HTML content directly.\n";
	$prompt .= "Start with <h1> for the main title and use appropriate heading hierarchy.\n";
	
	return $prompt;
}

/**
 * Send to Gemini API with images
 */
function generateReportWithGemini($prompt, $activities) {
	$apiKey = $_ENV['GEMINI_API_KEY']; // Store in environment variable
	
	// Prepare the content parts
	$contents = [
		[
			'role' => 'user',
			'parts' => []
		]
	];
	
	// Add text prompt
	$contents[0]['parts'][] = [
		'text' => $prompt
	];
	
	// Add images (Gemini can analyze images!)
	foreach ($activities as $activity) {
		foreach ($activity['media'] as $media) {
			// Only include images (not videos or docs)
			if (strpos($media['file_type'], 'image/') === 0) {
				
				// Fetch image from URL
				$imageData = @file_get_contents($media['file_path']);
				
				if ($imageData !== false) {
					$base64Image = base64_encode($imageData);
					
					$contents[0]['parts'][] = [
						'inline_data' => [
							'mime_type' => $media['file_type'],
							'data' => $base64Image
						]
					];
				} else {
					// Log error - image couldn't be fetched
					error_log("Failed to fetch image: {$media['file_path']}");
				}
			}
		}
	}
	
	// Make API call
	$data = [
		'contents' => $contents,
		'generationConfig' => [
			'temperature' => 0.7,
			'maxOutputTokens' => 8192
		]
	];
	
	$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Content-Type: application/json'
	]);
	
	$response = curl_exec($ch);
	curl_close($ch);
	
	$result = json_decode($response, true);
	
	if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
		return $result['candidates'][0]['content']['parts'][0]['text'];
	}
	
	return null;
}
