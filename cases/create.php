<?php
/**
 * Create Complete Case Endpoint
 * POST /api/cases
 *
 * Creates a complete case with all data in one call.
 * Designed for external apps (Bubble, etc.) that want to create everything at once.
 *
 * For the admin wizard flow, use:
 * - POST /api/cases/draft (step 1)
 * - POST /api/cases/{id}/participants (step 2)
 * - POST /api/cases/{id}/objectives (step 3)
 * - POST /api/cases/{id}/assign (step 4)
 *
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
	exit();
}

// Get request body
$data = Response::getJsonInput();

// Validate required case fields
$requiredFields = ['client_id', 'case_type', 'description', 'start_date'];
$missingFields = [];

foreach ($requiredFields as $field) {
	if (!isset($data[$field]) || empty($data[$field])) {
		$missingFields[] = $field;
	}
}

if (!empty($missingFields)) {
	Response::error('Missing required fields: ' . implode(', ', $missingFields), 400);
}

try {
	$pdo = $GLOBALS['pdo'];

	// ============================================
	// 1. CREATE THE CASE
	// ============================================
	$caseModel = initializeClass("CaseModel");

	$caseData = [
		'client_id' => (int)$data['client_id'],
		'case_type_id' => (int)$data['case_type'],
		'case_title' => $data['case_title'] ?? null,
		'priority' => $data['priority'] ?? 'medium',
		'description' => $data['description'],
		'initial_hours_allotted' => isset($data['initial_hours_allotted']) ? (float)$data['initial_hours_allotted'] : 12.00,
		'budget_amount' => isset($data['budget_amount']) ? (float)$data['budget_amount'] : null,
		'start_date' => $data['start_date'],
		'end_date' => $data['end_date'] ?? null,
		'admin_notes' => $data['admin_notes'] ?? null,
		'status' => $data['status'] ?? 'new'
	];

	$draftCaseId = isset($data['draft_case_id']) ? intval($data['draft_case_id']) : null;

	if ($draftCaseId && $draftCaseId > 0) {
		// Finalize draft: update existing record
		$pdo->update('cases', $caseData, ['case_id' => $draftCaseId]);
		$caseId = $draftCaseId;
	} else {
		// New case (Bubble / external / fallback)
		$caseId = $caseModel->createCase($caseData);
	}

	// Audit log: CASE_CREATE
	$auditModel = new AuditModel();
	$caseNumberForLog = $data['case_number'] ?? $caseModel->caseArr['case_number'] ?? 'Unknown';
	$auditModel->log('CASE_CREATE', $user, $caseId, 'case', $caseId, [
		'case_number' => $caseNumberForLog
	]);

	// ============================================
	// 2. ADD PARTICIPANTS (if provided)
	// ============================================
	if (isset($data['participants']) && !empty($data['participants'])) {
		$participants = $data['participants'];

		// Detect format: new array format is numerically indexed
		$isNewFormat = isset($participants[0]) || (is_array($participants) && array_values($participants) === $participants && !empty($participants));

		if ($isNewFormat) {
			// ---- New array format: [{type, metadata, vehicle}] ----
			// Built-in fields sent at top-level (transitional shim) get folded into metadata
			$knownFields = [
				'first_name','last_name','alias','cell_phone','home_phone','email',
				'ssn','dob','sex','race','height','weight','hair_color','eye_color',
				'wears_glasses','description','address','city','state','zip',
				'employer','employer_address','employer_phone','occupation','work_schedule',
				'has_violence_history','violence_description','criminal_history',
				'legal_proceeding','legal_description','num_children','children_ages',
				'num_stepchildren','stepchildren_ages'
			];

			foreach ($participants as $p) {
				$pData = [
					'case_id'          => $caseId,
					'participant_type' => $p['type'] ?? 'subject'
				];

				// All field data goes in metadata
				$meta = $p['metadata'] ?? [];
				foreach ($knownFields as $f) {
					if (isset($p[$f]) && !isset($meta[$f])) $meta[$f] = $p[$f];
				}
				if (!empty($meta)) {
					$pData['metadata'] = json_encode($meta);
				}

				$participantId = $pdo->insert('case_participants', $pData);

				// Insert vehicle if provided
				if ($participantId && !empty($p['vehicle'])) {
					$v = $p['vehicle'];
					if (!empty($v['make']) || !empty($v['model'])) {
						$pdo->insert('case_vehicles', [
							'case_id'        => $caseId,
							'participant_id' => $participantId,
							'make'           => $v['make'] ?? null,
							'model'          => $v['model'] ?? null,
							'color'          => $v['color'] ?? null,
							'tag_number'     => $v['tag_number'] ?? null,
							'decals_markings'=> $v['decals_markings'] ?? null,
						]);
					}
				}
			}

		} else {
			// ---- Legacy object format (Bubble integration) ----

			// Subject
			if (isset($participants['subject']) && !empty($participants['subject'])) {
				$subject = $participants['subject'];
				$subjectData = [
					'case_id' => $caseId,
					'participant_type' => 'subject',
					'first_name' => $subject['first_name'] ?? null,
					'last_name' => $subject['last_name'] ?? null,
					'alias' => $subject['alias'] ?? null,
					'cell_phone' => $subject['phone'] ?? $subject['cell_phone'] ?? null,
					'email' => $subject['email'] ?? null,
					'address' => $subject['address'] ?? null,
					'occupation' => $subject['occupation'] ?? null,
					'employer' => $subject['employer'] ?? null,
					'description' => $subject['description'] ?? null
				];

				$pdo->insert('case_participants', $subjectData);

				// Add vehicle if provided
				if (!empty($subject['vehicle_make']) || !empty($subject['vehicle_model'])) {
					$vehicleData = [
						'case_id' => $caseId,
						'make' => $subject['vehicle_make'] ?? null,
						'model' => $subject['vehicle_model'] ?? null,
						'color' => $subject['vehicle_color'] ?? null,
						'tag_number' => $subject['vehicle_tag'] ?? null
					];
					$pdo->insert('case_vehicles', $vehicleData);
				}
			}

			// Persons Involved
			if (isset($participants['persons_involved']) && is_array($participants['persons_involved'])) {
				foreach ($participants['persons_involved'] as $person) {
					$personData = [
						'case_id' => $caseId,
						'participant_type' => 'person_involved',
						'first_name' => $person['first_name'] ?? null,
						'last_name' => $person['last_name'] ?? null,
						'cell_phone' => $person['phone'] ?? $person['cell_phone'] ?? null,
						'email' => $person['email'] ?? null,
						'address' => $person['address'] ?? null,
						'description' => $person['description'] ?? null
					];
					$pdo->insert('case_participants', $personData);
				}
			}

			// Attorneys (handle both 'attorneys' and 'attorney')
			$attorneysList = [];
			if (isset($participants['attorneys']) && is_array($participants['attorneys'])) {
				$attorneysList = $participants['attorneys'];
			} elseif (isset($participants['attorney']) && !empty($participants['attorney'])) {
				$attorneysList = [$participants['attorney']];
			}

			foreach ($attorneysList as $attorney) {
				$attorneyData = [
					'case_id' => $caseId,
					'name' => $attorney['name'] ?? (($attorney['first_name'] ?? '') . ' ' . ($attorney['last_name'] ?? '')),
					'phone' => $attorney['phone'] ?? $attorney['cell_phone'] ?? null,
					'email' => $attorney['email'] ?? null,
					'address' => $attorney['address'] ?? null
				];
				if (trim($attorneyData['name'])) {
					$pdo->insert('case_attorneys', $attorneyData);
				}
			}
		}
	}

	// ============================================
	// 3. ADD OBJECTIVES (if provided)
	// ============================================
	if (isset($data['objectives']) && is_array($data['objectives'])) {
		foreach ($data['objectives'] as $objective) {
			$objectiveData = [
				'case_id' => $caseId,
				'objective_title' => $objective['objective_title'] ?? $objective['title'] ?? 'Untitled Objective',
				'objective_description' => $objective['objective_description'] ?? $objective['description'] ?? null,
				'priority' => $objective['priority'] ?? 'medium',
				'status' => 'assigned',
				'due_date' => $objective['due_date'] ?? null,
				'estimated_hours' => $objective['estimated_hours'] ?? null
			];
			$pdo->insert('objectives', $objectiveData);
		}
	}

	// ============================================
	// 4. CREATE ASSIGNMENTS (if provided)
	// ============================================
	if (isset($data['assignments']) && is_array($data['assignments']) && !empty($data['assignments'])) {
		foreach ($data['assignments'] as $assignment) {
			$assignmentData = [
				'case_id' => $caseId,
				'investigator_id' => $assignment['investigator_id'],
				'role' => $assignment['role'] ?? 'support',
				'assigned_hourly_rate' => $assignment['assigned_hourly_rate'] ?? null,
				'assigned_at' => date('Y-m-d H:i:s'),
				'assigned_by' => $user['admin_id'] ?? $user['investigator_id'] ?? null
			];
			$pdo->insert('case_assignments', $assignmentData);
		}

		// Set the lead investigator on the case
		$leadAssignment = array_filter($data['assignments'], function($a) {
			return ($a['role'] ?? '') === 'lead';
		});

		if (!empty($leadAssignment)) {
			$investigatorId = reset($leadAssignment)['investigator_id'];
		} else {
			$investigatorId = $data['assignments'][0]['investigator_id'];
		}

		$pdo->update('cases', ['investigator_id' => $investigatorId, 'status' => 'assigned'], ['case_id' => $caseId]);

		// Audit log: CASE_ASSIGN
		$inv = $pdo->selectFirst("investigators", ["investigator_id" => $investigatorId]);
		if ($inv) {
			$auditModel->log('CASE_ASSIGN', $user, $caseId, 'case', $caseId, [
				'case_number' => $caseNumberForLog,
				'investigator' => $inv['first_name'] . ' ' . $inv['last_name']
			]);
		}
	}

	// ============================================
	// 5. CREATE BUNNY CDN FOLDER
	// ============================================
	// Skip if finalizing a draft — folder was already created by create-draft.php
	if (!$draftCaseId || $draftCaseId <= 0) {
		try {
			$caseModel->createCaseFolder($caseId, $data['client_id']);
		} catch (Exception $e) {
			error_log("Bunny folder creation failed: " . $e->getMessage());
		}
	}

	// Load created case to return full data
	$createdCase = initializeClass("CaseModel", $caseId);

	Response::success([
		'case_id' => $caseId,
		'case_number' => $createdCase->caseArr['case_number'],
		'status' => $createdCase->caseArr['status'],
		'message' => 'Case created successfully'
	], 201);

} catch (InvalidArgumentException $e) {
	Response::error($e->getMessage(), 400);
} catch (Exception $e) {
	error_log("Case creation failed: " . $e->getMessage());
	Response::serverError('Failed to create case: ' . $e->getMessage());
}
