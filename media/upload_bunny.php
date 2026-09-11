<?php
/**
 * Upload Media Endpoint
 * POST /api/media
 * 
 * Uploads media
 * Requires authentication (admin or client)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';
require_once __DIR__ . '/../../classes/class.auditmodel.php';
 
use BunnyCDN\Storage\BunnyCDNStorage;

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	Response::error('Method not allowed', 200);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
	exit();
}

// Check for oversized upload if no files were processed but request is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_FILES) && empty(file_get_contents('php://input'))) {
    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        Response::error('The uploaded file exceeds the maximum allowed size on the server. Please check server configuration (upload_max_filesize).', 413);
    }
}

// Determine input type: direct file upload or URL-based
if (isset($_POST['chunk_index']) || isset($_POST['chunk'])) {
    // Chunked upload
    processChunkedUpload();
} elseif (!empty($_FILES['files'])) {
	// Direct file upload from admin portal
	processDirectUpload($_FILES['files']);
} else {
	// URL-based upload from Bubble
	$json = Response::getJsonInput();
	processJSON($json);
}

function processChunkedUpload() {
    // Support both custom chunking and Plupload
    $chunkIndex = isset($_POST['chunk']) ? intval($_POST['chunk']) : (isset($_POST['chunk_index']) ? intval($_POST['chunk_index']) : 0);
    $totalChunks = isset($_POST['chunks']) ? intval($_POST['chunks']) : (isset($_POST['total_chunks']) ? intval($_POST['total_chunks']) : 1);
    $fileId = $_POST['file_id'] ?? ($_POST['tid'] ?? null); // Plupload might use tid or we can use tid as fileId
    $fileName = $_POST['file_name'] ?? ($_POST['name'] ?? 'unknown');
    $caseId = $_GET['case_id'] ?? $_POST['case_id'] ?? null;
    $postData = $_POST; // Capture all POST data before potentially losing it

    if (!$fileId) {
        // If no fileId/tid, use fileName as part of the ID to keep chunks together
        $fileId = md5($fileName . $caseId);
    }

    $tempDir = sys_get_temp_dir() . '/uploads/' . $fileId;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    $finalFile = $tempDir . '/' . $fileName;
    $chunkFile = $_FILES['file']['tmp_name'] ?? ($_FILES['files']['tmp_name'][0] ?? $_FILES['files']['tmp_name']);

    // Append chunk to a separate file for each chunk
    $chunkFileFinal = $tempDir . '/' . $fileName . '.part' . $chunkIndex;
    $chunkFileSource = $_FILES['file']['tmp_name'] ?? ($_FILES['files']['tmp_name'][0] ?? $_FILES['files']['tmp_name']);

    if (!move_uploaded_file($chunkFileSource, $chunkFileFinal)) {
        // Fallback if move_uploaded_file fails (e.g. if it's already moved or custom upload)
        if (!rename($chunkFileSource, $chunkFileFinal)) {
            copy($chunkFileSource, $chunkFileFinal);
        }
    }

    if ($chunkIndex === $totalChunks - 1) {
        // Last chunk received
        Logger("SUCCESS: Last chunk received for $fileName. Piecing together files.");
        
        $out = fopen($finalFile, "wb");
        if (!$out) {
            Logger("ERROR: Failed to open final file for writing: $finalFile");
            Response::error("Failed to open final file for writing", 500);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $partFile = $tempDir . '/' . $fileName . '.part' . $i;
            
            // Wait a bit for the file to appear if it's not there (async uploads might be slightly out of order in terms of filesystem availability)
            $attempts = 0;
            while (!file_exists($partFile) && $attempts < 20) {
                usleep(200000); // 200ms
                $attempts++;
            }

            if (!file_exists($partFile)) {
                Logger("ERROR: Missing chunk part: $partFile");
                fclose($out);
                Response::error("Missing chunk part: $i", 500);
            }

            $in = fopen($partFile, "rb");
            if ($in) {
                while (!feof($in)) {
                    $buff = fread($in, 1024 * 1024); // 1MB buffer
                    fwrite($out, $buff);
                }
                fclose($in);
            }
            unlink($partFile); // Delete part after merging
        }
        fclose($out);

        $mergedFileSize = filesize($finalFile);
        
        if ($mergedFileSize === 0) {
            Logger("ERROR: Merged file is 0 bytes: $finalFile");
            Response::error("Uploaded file is empty", 400);
        }

        Logger("SUCCESS: File pieced together. Total size: " . $mergedFileSize);
        
        // Prepare file info for background task
        $files = [
            'name' => $fileName,
            'tmp_name' => $finalFile,
            'error' => UPLOAD_ERR_OK,
            'size' => $mergedFileSize
        ];

        // Insert placeholder DB record so UI can show "processing" state
        $globalDescription = $_POST['description'] ?? null;
        $placeholderMediaId = null;
        try {
            $db = getDB();
            if (method_exists($db, 'reconnectMaster')) {
                $db->reconnectMaster();
            }

            $placeholderData = [
                'case_id' => $caseId,
                'file_path' => $fileName,
                'description' => $globalDescription,
                'processing' => 1
            ];

            // Add activity/objective IDs if present (often passed in $_POST during chunked upload)
            if (isset($_POST['activity_id'])) $placeholderData['activity_id'] = $_POST['activity_id'];
            if (isset($_POST['activity_uuid'])) $placeholderData['activity_uuid'] = $_POST['activity_uuid'];
            if (isset($_POST['uuid'])) $placeholderData['activity_uuid'] = $_POST['uuid'];
            if (isset($_POST['objective_id'])) $placeholderData['objective_id'] = $_POST['objective_id'];
            if (isset($_POST['is_client_viewable'])) $placeholderData['is_client_viewable'] = $_POST['is_client_viewable'];
            if (isset($_POST['is_investigator_viewable'])) $placeholderData['is_investigator_viewable'] = $_POST['is_investigator_viewable'];

            $placeholderMediaId = $GLOBALS['pdo']->insert("media", $placeholderData);
            Logger("PLACEHOLDER: Media record created with ID: $placeholderMediaId for $fileName");
        } catch (Exception $e) {
            Logger("PLACEHOLDER ERROR: Could not create placeholder: " . $e->getMessage());
        }

        // Process the file BEFORE sending response to avoid background processing issues
        Logger("INFO: Processing $fileName before sending final response. Media ID: $placeholderMediaId");
        
        // Ensure we have enough time to process large files
        set_time_limit(300); // 5 minutes should be enough for most files
        
        try {
            Logger("PROCESSING: Starting for: $fileName (" . $files['size'] . " bytes)");
            
            // Validate reassembled file
            if (!file_exists($finalFile)) {
                Logger("PROCESSING ERROR: Final file vanished: $finalFile");
                throw new Exception("Final file vanished");
            }

            // Call the regular processing logic
            processDirectUpload($files, $placeholderMediaId);
            Logger("PROCESSING SUCCESS: Completed for: $fileName");
        } catch (Throwable $e) {
            Logger("PROCESSING ERROR: Failed for $fileName: " . $e->getMessage());
            Logger("PROCESSING ERROR TRACE: " . $e->getTraceAsString());
            
            // Mark placeholder as failed if it exists
            if ($placeholderMediaId) {
                try {
                    $db = getDB();
                    if (method_exists($db, 'reconnectMaster')) $db->reconnectMaster();
                    $GLOBALS['pdo']->update("media", ['processing' => -1], ["media_id" => $placeholderMediaId]);
                } catch (Throwable $dbErr) {
                    Logger("PROCESSING ERROR: Could not mark failure in DB: " . $dbErr->getMessage());
                }
            }
            Response::error("Processing failed: " . $e->getMessage(), 500);
        } finally {
            // Cleanup
            if (file_exists($finalFile)) {
                Logger("Cleaning up final file: $finalFile");
                unlink($finalFile);
            }
            if (is_dir($tempDir)) {
                // Remove temp dir
                $files_in_dir = glob($tempDir . '/*');
                foreach($files_in_dir as $f) {
                    if(is_file($f)) unlink($f);
                }
                rmdir($tempDir);
            }
        }
    } else {
        Response::success(['chunk' => $chunkIndex], "Chunk uploaded", 200);
    }
}

function Logger($message) {
    error_log($message);
}

function processDirectUpload($files, $placeholderMediaId = null) {
    $caseId = $_GET['case_id'] ?? $_POST['case_id'] ?? null;
    Logger("INFO: processDirectUpload started. Case ID: " . ($caseId ?? 'null') . ", Placeholder ID: " . ($placeholderMediaId ?? 'null'));

    // 1. Get Global Metadata
    $globalData = [];
    if (!empty($_POST['data'])) {
        $globalData = json_decode($_POST['data'], true);
    }

    // Fallback global description from dynamicFormData
    $globalDescription = $_POST['description'] ?? null;

    // Validate case and client
    $caseRecord = $GLOBALS['pdo']->selectFirst("cases", array("case_id" => $caseId));
    if(!$caseRecord) {
        Response::error("Case Not Found", 200);
        exit();
    }

    $clientRecord = $GLOBALS['pdo']->selectFirst("clients", array("client_id" => $caseRecord['client_id']));
    if(!$clientRecord) {
        Response::error("Client Not Found", 200);
        exit();
    }

    $exifAvailable = isExifAvailable();
    $bunnyClient = new \Bunny\Storage\Client($_ENV['BUNNY_CLIENT_SECRET'], $_ENV['BUNNY_STORAGE_ZONE'], \Bunny\Storage\Region::NEW_YORK);

    // 2. Normalize File List
    $fileList = [];
    if (isset($files['name'])) {
        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                $fileList[] = [
                    'name' => $files['name'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                    'index' => $i // Keep track of index for POST data lookup
                ];
            }
        } else {
            $fileList[] = [
                'name' => $files['name'],
                'tmp_name' => $files['tmp_name'],
                'error' => $files['error'],
                'size' => $files['size'],
                'index' => 0
            ];
        }
    } elseif (isset($_FILES['file'])) {
        // Support Plupload's 'file' parameter (not 'files')
        $file = $_FILES['file'];
        $fileList[] = [
            'name' => $_POST['name'] ?? $file['name'],
            'tmp_name' => $file['tmp_name'],
            'error' => $file['error'],
            'size' => $file['size'],
            'index' => 0
        ];
    }

    foreach ($fileList as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Logger("ERROR: File upload error code: " . $file['error'] . " for file: " . $file['name']);
            continue;
        }

        // Re-open DB for metadata/validation if closed
        $db = getDB();
        if (method_exists($db, 'reconnectMaster')) {
            $db->reconnectMaster();
        }

        try {
            $tmpPath = $file['tmp_name'];
            $isFilePath = is_file($tmpPath);
            
            $uniqueFileName = time() . '_' . $file['name'];
            
            // Check if file still exists and is not 0 bytes (relevant for background processing)
            if ($isFilePath) {
                clearstatcache(true, $tmpPath);
                if (!file_exists($tmpPath)) {
                    Logger("ERROR: Temporary file missing: $tmpPath for file: " . $file['name']);
                    continue;
                }
                $tmpSize = filesize($tmpPath);
                if ($tmpSize === 0) {
                    Logger("ERROR: Temporary file is 0 bytes: $tmpPath for file: " . $file['name']);
                    continue;
                }
                Logger("INFO: Found temporary file: $tmpPath ($tmpSize bytes)");
            }

            Logger("INFO: Processing file: " . $file['name'] . " (" . $file['size'] . " bytes)");
            $mimeType = getMimeType($tmpPath, !$isFilePath);
            Logger("INFO: Mime type detected: $mimeType for file: " . $file['name']);
            
            // Truncate mime type if it exceeds column length (varchar 50)
            if (strlen($mimeType) > 50) {
                $mimeType = substr($mimeType, 0, 50);
            }

            $fileType = getFileCategory($mimeType);
            $fileInfo = [];
            $metadata = null;

            // EXIF Metadata Extraction (BEFORE burning, to avoid stripping by GD)
            if ($fileType === 'image' && $exifAvailable) {
                Logger("INFO: Extracting EXIF metadata for: " . $file['name']);
                $metadata = getImageMetadata($tmpPath, !$isFilePath);
                Logger("INFO: EXIF Metadata extracted: " . json_encode($metadata));
                if ($metadata && !empty($metadata['timestamp'])) { 
                    $fileInfo['captured_at'] = $metadata['timestamp']; 
                    Logger("INFO: EXIF/Metadata captured_at found: " . $metadata['timestamp'] . " for: " . $file['name']);
                } else {
                    Logger("INFO: No EXIF/Metadata captured_at found for: " . $file['name']);
                }
                if ($metadata) {
                    if ($metadata['gps_latitude']) { $fileInfo['gps_lat'] = $metadata['gps_latitude']; }
                    if ($metadata['gps_longitude']) { $fileInfo['gps_lng'] = $metadata['gps_longitude']; }
                    if ($metadata['gps_accuracy']) { $fileInfo['gps_accuracy'] = $metadata['gps_accuracy']; }
                    if ($metadata['altitude']) { $fileInfo['altitude'] = $metadata['altitude']; }
                }
            }

            // Burn Timestamp if requested and it's an image
            if ($fileType === 'image' && !empty($_POST['burn_timestamp'])) {
                // Default to provided burn_date/time
                $burnDate = $_POST['burn_date'] ?? date('Y-m-d');
                $burnTime = $_POST['burn_time'] ?? date('H:i');
                $burnPlacement = $_POST['burn_placement'] ?? 'bottom-right';

                // IF we have EXIF captured_at, use that instead of user-provided values
                if (!empty($fileInfo['captured_at'])) {
                    $exifTimestamp = strtotime($fileInfo['captured_at']);
                    if ($exifTimestamp !== false) {
                        $burnDate = date('Y-m-d', $exifTimestamp);
                        $burnTime = date('H:i', $exifTimestamp);
                        Logger("INFO: Using EXIF captured_at for burn-in: $burnDate $burnTime for " . $file['name']);
                    }
                }

                Logger("INFO: Burning timestamp to image: " . $file['name'] . " with Date: $burnDate, Time: $burnTime, Placement: $burnPlacement");
                $burntImageContent = burnTimestampToImage($tmpPath, $burnDate, $burnTime, $burnPlacement, !$isFilePath);
                if ($burntImageContent !== false) {
                    // If it was a file path, we should probably save it to a new temp file or handle it as content
                    // For processDirectUpload, $tmpPath is usually the $_FILES['tmp_name']
                    // We can overwrite the content if we handle it carefully, but it's safer to treat as content from now on
                    $tmpPath = $burntImageContent;
                    $isFilePath = false;
                    $file['size'] = strlen($burntImageContent);
                    Logger("INFO: Timestamp burnt to image: " . $file['name']);
                } else {
                    Logger("ERROR: Failed to burn timestamp to image: " . $file['name']);
                }
            }

            // 3. PRIORITY DESCRIPTION LOGIC
            $fileDescription = $globalDescription;
            if (isset($_POST['description']) && is_array($_POST['description'])) {
                $fileDescription = $_POST['description'][$file['index']] ?? $globalDescription;
            } elseif (!empty($_POST['description']) && $file['index'] === 0) {
                $fileDescription = $_POST['description'];
            }

            // If EXIF has a description/caption and we don't have one from POST, use it
            if ($metadata && !empty($metadata['description']) && empty($fileDescription)) {
                $fileDescription = $metadata['description'];
            }

            // Close DB connection during long-running I/O and processing
            $db = getDB();
            if (method_exists($db, 'closeConnections')) {
                $db->closeConnections();
            }

            $caseFolder = "cases/" . $clientRecord['first_name'] . $clientRecord['last_name'] . '/' . $caseId;
            Logger("INFO: Uploading to Bunny folder: $caseFolder for file: " . $file['name']);
            $bunnyUrl = uploadToBunny($bunnyClient, $tmpPath, $uniqueFileName, $caseFolder, !$isFilePath);
            Logger("INFO: Upload successful: $bunnyUrl for file: " . $file['name']);

            // Video Thumbnail Logic
            $thumbnailUrl = null;
            if ($fileType === 'video') {
                Logger("INFO: Generating video thumbnail for: " . $file['name']);
                $thumbnailContent = generateVideoThumbnail($tmpPath, $file['name'], !$isFilePath);
                if ($thumbnailContent !== false) {
                    $thumbnailFileName = pathinfo($uniqueFileName, PATHINFO_FILENAME) . '_thumb.jpg';
                    $thumbnailUrl = uploadToBunny($bunnyClient, $thumbnailContent, $thumbnailFileName, $caseFolder, true);
                    Logger("INFO: Thumbnail uploaded: $thumbnailUrl");
                } else {
                    Logger("WARNING: Thumbnail generation returned false for: " . $file['name']);
                }
            }

            // 4. Save to Database
            Logger("INFO: Re-establishing database connection for indexing.");
            $db = getDB();
            if (method_exists($db, 'reconnectMaster')) {
                $db->reconnectMaster();
            }

            // Determine captured_at
            $capturedAt = $globalData['captured_at'] ?? $_POST['captured_at'] ?? $fileInfo['captured_at'] ?? null;
            if (empty($capturedAt)) {
                $capturedAt = date('Y-m-d H:i:s');
                Logger("INFO: No captured_at found in POST or Metadata, using today's date: $capturedAt for " . $file['name']);
            } else {
                Logger("INFO: Using final captured_at: $capturedAt for " . $file['name']);
            }

            Logger("INFO: Saving media to database for case: $caseId");
            $media = initializeClass("MediaModel");
            $mediaData = array(
                "case_id" => $caseId,
                "investigator_id" => $globalData['investigator_id'] ?? null,
                "file_path" => $bunnyUrl,
                "description" => $fileDescription,
                "file_mime" => $mimeType,
                "file_size_bytes" => $file['size'],
                "file_type" => $fileType,
                "uploaded_at" => date('Y-m-d H:i:s'),
                "captured_at" => $capturedAt,
                "entry_id" => $globalData['entry_id'] ?? $_POST['entry_id'] ?? null,
                "lat" => $fileInfo['gps_lat'] ?? null,
                "lng" => $fileInfo['gps_lng'] ?? null,
                "gps_accuracy" => $fileInfo['gps_accuracy'] ?? null,
                "altitude" => $fileInfo['altitude'] ?? null,
                "video_thumb" => $thumbnailUrl,
                "is_client_viewable" => $_POST['is_client_viewable'] ?? 0,
                "is_investigator_viewable" => $_POST['is_investigator_viewable'] ?? 1
            );

            // Link to Activity/Objective if UUID is present
            $uuid = $globalData['uuid'] ?? $_POST['uuid'] ?? null;
            if($uuid) {
                $mediaData['activity_uuid'] = $uuid;
                $activity = $GLOBALS['pdo']->selectFirst("activities", array("uuid" => $uuid));
                $mediaData['activity_id'] = $activity['activity_id'] ?? null;
                Logger("INFO: Linked to activity UUID: $uuid, ID: " . $mediaData['activity_id']);
            }

            $activityId = $globalData['activity_id'] ?? $_POST['activity_id'] ?? null;
            if($activityId) {
                $mediaData['activity_id'] = $activityId;
                Logger("INFO: Linked to activity ID: $activityId");
            }

            $objectiveId = $globalData['objective_id'] ?? $_POST['objective_id'] ?? null;
            if($objectiveId) {
                $mediaData['objective_id'] = $objectiveId;
                Logger("INFO: Linked to objective ID: $objectiveId");
            }

            $entryId = $globalData['entry_id'] ?? $_POST['entry_id'] ?? null;
            if($entryId) {
                $mediaData['entry_id'] = $entryId;
                Logger("INFO: Linked to time entry ID: $entryId");
            }


            if ($placeholderMediaId) {
                // Update placeholder record and mark processing complete
                $mediaData['processing'] = 0;
                $GLOBALS['pdo']->update("media", $mediaData, ["media_id" => $placeholderMediaId]);
                $newMediaId = $placeholderMediaId;
                Logger("INFO: Updated placeholder media ID: $newMediaId, processing complete");
            } else {
                // Direct upload - insert with processing already complete
                $mediaData['processing'] = 0;
                $newMediaId = $media->createMedia($mediaData);
                Logger("INFO: Media created in DB with ID: $newMediaId");
            }

            // Audit log: MEDIA_UPLOAD
            $auditModel = new AuditModel();
            $auditModel->log('MEDIA_UPLOAD', Auth::authenticate(), $caseId, 'media', $newMediaId, [
                'filename' => $file['name'],
                'media_type' => $fileType
            ]);

        } catch (Throwable $e) {
            Logger("Error processing " . $file['name'] . ": " . $e->getMessage());
            Logger("Trace: " . $e->getTraceAsString());
        }
    }

    Logger("INFO: All files processed. Sending final response.");
    Response::success(['case_id' => $caseId], 'Media uploaded successfully', 201);
}

function processJSON($data) {
    $caseId = $_GET['case_id'];
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        Response::error("Invalid JSON: " . json_last_error_msg(), 200);
        exit();
    }
    
    if (!isset($data['fileURLs']) || empty($data['fileURLs'])) {
        Response::error("No fileURLs found in JSON", 200);
        exit();
    }
    
    $caseRecord = $GLOBALS['pdo']->selectFirst("cases", array("case_id" => $caseId));
    if(!$caseRecord) {
        Response::error("Case Not Found", 200);
        exit();
    }
    
    $clientRecord = $GLOBALS['pdo']->selectFirst("clients", array("client_id" => $caseRecord['client_id']));
    if(!$clientRecord) {
        Response::error("Client Not Found", 200);
        exit();
    }
    
    $exifAvailable = isExifAvailable();
    $bunnyClient = new \Bunny\Storage\Client($_ENV['BUNNY_CLIENT_SECRET'], $_ENV['BUNNY_STORAGE_ZONE'], \Bunny\Storage\Region::NEW_YORK);
    
    $urls = array_map('trim', explode(',', $data['fileURLs']));
    $uploadedUrls = [];

    foreach ($urls as $url) {
        $url = stripslashes($url);
        if (empty($url)) continue;
        
        try {
            Logger("Processing: " . $url . "\n");
            $urlParts = parse_url($url);
            $pathParts = pathinfo($urlParts['path']);
            $fileName = $pathParts['basename'];
            $uniqueFileName = time() . '_' . $fileName;
            
            Logger("Downloading file...\n");
            $fileContent = downloadFile($url);
            
            $mimeType = getMimeType($fileContent, true);
            if (strlen($mimeType) > 50) {
                $mimeType = substr($mimeType, 0, 50);
            }
            
            $fileType = getFileCategory($mimeType);
            $fileInfo = [];
            
            // EXIF Metadata Extraction
            if ($fileType === 'image' && $exifAvailable) {
                $metadata = getImageMetadata($fileContent, true);
                if ($metadata['timestamp']) {
                    $fileInfo['captured_at'] = $metadata['timestamp'];
                }
                if ($metadata['gps_latitude'] && $metadata['gps_longitude']) {
                    $fileInfo['gps_latitude'] = $metadata['gps_latitude'];
                    $fileInfo['gps_longitude'] = $metadata['gps_longitude'];
                }
                if ($metadata['gps_accuracy']) {
                    $fileInfo['gps_accuracy'] = $metadata['gps_accuracy'];
                }
                if ($metadata['altitude']) {
                    $fileInfo['altitude'] = $metadata['altitude'];
                }
                if (!empty($metadata['description']) && empty($data['description'])) {
                    $data['description'] = $metadata['description'];
                }
            }

            // Burn Timestamp if requested and it's an image
            if ($fileType === 'image' && !empty($data['burn_timestamp'])) {
                $burnDate = $data['burn_date'] ?? date('Y-m-d');
                $burnTime = $data['burn_time'] ?? date('H:i');
                $burnPlacement = $data['burn_placement'] ?? 'bottom-right';

                // IF we have EXIF captured_at, use that instead of user-provided values
                if (!empty($fileInfo['captured_at'])) {
                    $exifTimestamp = strtotime($fileInfo['captured_at']);
                    if ($exifTimestamp !== false) {
                        $burnDate = date('Y-m-d', $exifTimestamp);
                        $burnTime = date('H:i', $exifTimestamp);
                        Logger("INFO: Using EXIF captured_at for burn-in (URL): $burnDate $burnTime for " . $fileName);
                    }
                }

                $burntImageContent = burnTimestampToImage($fileContent, $burnDate, $burnTime, $burnPlacement, true);
                if ($burntImageContent !== false) {
                    $fileContent = $burntImageContent;
                    Logger("INFO: Timestamp burnt to image (URL upload): " . $fileName);
                }
            }
            $capturedAt = $data['captured_at'] ?? $fileInfo['captured_at'] ?? null;
            if (empty($capturedAt)) {
                $capturedAt = date('Y-m-d H:i:s');
                Logger("INFO: No captured_at found for bubble upload, using today's date: $capturedAt");
            }
            
            $caseFolder = "cases/" . $clientRecord['first_name'] . $clientRecord['last_name'] . '/' . $caseId;
            $bunnyUrl = uploadToBunny($bunnyClient, $fileContent, $uniqueFileName, $caseFolder, true);
            $uploadedUrls[] = $bunnyUrl;
            
            $thumbnailUrl = null;
            if ($fileType === 'video') {
                $thumbnailContent = generateVideoThumbnail($fileContent, $fileName, true);
                if ($thumbnailContent !== false) {
                    $thumbnailFileName = pathinfo($uniqueFileName, PATHINFO_FILENAME) . '_thumb.jpg';
                    $thumbnailUrl = uploadToBunny($bunnyClient, $thumbnailContent, $thumbnailFileName, $caseFolder, true);
                }
            }
            
            $media = initializeClass("MediaModel");
            $mediaData = array(
                "case_id" => $caseId,
                "investigator_id" => $data['investigator_id'],
                "file_path" => $bunnyUrl,
                "description" => $data['description'],
                "file_mime" => $mimeType,
                "file_type" => $fileType,
                "uploaded_at" => date('Y-m-d H:i:s'),
                "captured_at" => $capturedAt,
                "lat" => $fileInfo['gps_latitude'] ?? null,
                "lng" => $fileInfo['gps_longitude'] ?? null,
                "gps_accuracy" => $fileInfo['gps_accuracy'] ?? null,
                "altitude" => $fileInfo['altitude'] ?? null,
                "video_thumb" => $thumbnailUrl
            );
            if(isset($data['is_client_viewable'])) {
                $mediaData['is_client_viewable'] = $data['is_client_viewable'];
            }            
            if(isset($data['uuid'])) {
                $mediaData['activity_uuid'] = $data['uuid'];
                $activity = $GLOBALS['pdo']->selectFirst("activities", array("uuid" => $data['uuid']));
                $mediaData['activity_id'] = $activity['activity_id'] ?? null;
            }
            if(isset($data['activity_id'])) {
                $mediaData['activity_id'] = $data['activity_id'];
            }
            if(isset($data['objective_id'])) {
                $mediaData['objective_id'] = $data['objective_id'];
            }
            if ($fileType === 'video') {
                $mediaData['video_thumb'] = $thumbnailUrl;
            }
            
            $media->createMedia($mediaData);
            deleteBubbleFile($url);

            // Audit log: MEDIA_UPLOAD
            $auditModel = new AuditModel();
            $auditModel->log('MEDIA_UPLOAD', Auth::authenticate(), $caseId, 'media', null, [
                'filename' => $fileName,
                'media_type' => $fileType
            ]);
            
        } catch (Exception $e) {
            Logger("✗ Error processing " . $url . ": " . $e->getMessage() . "\n");
        }
    }
    
    Response::success(['case_id' => $caseId], 'Media Uploaded successfully', 201);
}

function burnTimestampToImage($input, $date, $time, $placement, $isContent = false) {
    try {
        if (!class_exists('Imagick')) {
            Logger("WARNING: Imagick class not found, falling back to GD for burnTimestampToImage");
            return burnTimestampToImageGD($input, $date, $time, $placement, $isContent);
        }

        $imagick = new Imagick();
        if ($isContent) {
            $imagick->readImageBlob($input);
        } else {
            $imagick->readImage($input);
        }

        // Auto-orient based on EXIF
        $imagick->autoOrient();

        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        // Formatting
        // Line 1: Meridian Time (e.g., 6:27 PM)
        // Line 2: Date in 26 Feb 2026 format
        $line1 = date('g:i A', strtotime($time));
        $line2 = date('j M Y', strtotime($date));

        $draw = new ImagickDraw();
        $fontPath = __DIR__ . '/../../assets/fonts/Courier_New.ttf';
        if (file_exists($fontPath)) {
            $draw->setFont($fontPath);
        }
        
        $fontSize = 36; // pt (Imagick font size is different from GD)
        // Scaling font size based on image height for consistency across different resolutions
        // 18pt is roughly good for 1000px height. Scale accordingly.
        $scaledFontSize = (int)($fontSize * ($height / 1000));
        if ($scaledFontSize < 12) $scaledFontSize = 12;
        
        $draw->setFontSize($scaledFontSize);
        $draw->setFillColor('white');
        $draw->setStrokeColor('white');
        $draw->setStrokeWidth(2);
        $draw->setTextAntialias(true);

        // Calculate padding (10%)
        $paddingX = (int)($width * 0.05); // Reduced padding for better fit
        $paddingY = (int)($height * 0.05);

        // Get text metrics
        $metrics1 = $imagick->queryFontMetrics($draw, $line1);
        $metrics2 = $imagick->queryFontMetrics($draw, $line2);
        
        $textWidth = max($metrics1['textWidth'], $metrics2['textWidth']);
        $textHeight = $metrics1['textHeight'] + $metrics2['textHeight'] + 5; // 5px spacing

        switch ($placement) {
            case 'top-left':
                $x = $paddingX;
                $y = $paddingY + $metrics1['ascender'];
                break;
            case 'top-right':
                $x = $width - $paddingX - $textWidth;
                $y = $paddingY + $metrics1['ascender'];
                break;
            case 'bottom-left':
                $x = $paddingX;
                $y = $height - $paddingY - $metrics2['textHeight'] - 5;
                break;
            case 'bottom-right':
            default:
                $x = $width - $paddingX - $textWidth;
                $y = $height - $paddingY - $metrics2['textHeight'] - 5;
                break;
        }

        // Draw shadow/outline for readability
        $draw->setFillColor('black');
        $draw->setStrokeColor('black');
        $draw->setStrokeWidth(2);
        $imagick->annotateImage($draw, $x + 2, $y + 2, 0, $line1);
        $imagick->annotateImage($draw, $x + 2, $y + $metrics1['textHeight'] + 5 + 2, 0, $line2);

        $draw->setFillColor('white');
        $draw->setStrokeColor('white');
        $draw->setStrokeWidth(2);
        $imagick->annotateImage($draw, $x, $y, 0, $line1);
        $imagick->annotateImage($draw, $x, $y + $metrics1['textHeight'] + 5, 0, $line2);

        // Force output to JPEG
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(90);
        $output = $imagick->getImageBlob();
        
        $imagick->clear();
        $imagick->destroy();

        return $output;
    } catch (Exception $e) {
        Logger("ERROR in burnTimestampToImage (Imagick): " . $e->getMessage());
        // Attempt GD fallback if Imagick fails
        return burnTimestampToImageGD($input, $date, $time, $placement, $isContent);
    }
}

function burnTimestampToImageGD($input, $date, $time, $placement, $isContent = false) {
    try {
        if ($isContent) {
            $image = imagecreatefromstring($input);
        } else {
            $mime = getMimeType($input);
            if (strpos($mime, 'image/jpeg') !== false) {
                $image = imagecreatefromjpeg($input);
            } elseif (strpos($mime, 'image/png') !== false) {
                $image = imagecreatefrompng($input);
            } elseif (strpos($mime, 'image/gif') !== false) {
                $image = imagecreatefromgif($input);
            } else {
                $image = imagecreatefromstring(file_get_contents($input));
            }
        }

        if (!$image) {
            Logger("ERROR: Could not create image for burning timestamp (GD)");
            return false;
        }

        // Handle orientation if EXIF is available
        if (!$isContent && function_exists('exif_read_data')) {
            $exif = @exif_read_data($input);
            if ($exif && !empty($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3: $image = imagerotate($image, 180, 0); break;
                    case 6: $image = imagerotate($image, -90, 0); break;
                    case 8: $image = imagerotate($image, 90, 0); break;
                }
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Formatting
        // Line 1: Meridian Time (e.g., 6:27 PM)
        // Line 2: Date in 26 Feb 2026 format
        $line1 = date('g:i A', strtotime($time));
        $line2 = date('j M Y', strtotime($date));

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        // Font settings
        $fontSize = 12; // pt
        $fontPath = __DIR__ . '/../../assets/fonts/Courier_New.ttf';
        
        $hasTTF = function_exists('imagettftext') && file_exists($fontPath);

        // Calculate position (10% padding)
        $paddingX = (int)($width * 0.1);
        $paddingY = (int)($height * 0.1);

        if ($hasTTF) {
            // Get bounding box for text to align correctly
            $bbox1 = imagettfbbox($fontSize, 0, $fontPath, $line1);
            $bbox2 = imagettfbbox($fontSize, 0, $fontPath, $line2);
            
            $textWidth = max($bbox1[2] - $bbox1[0], $bbox2[2] - $bbox2[0]);
            $textHeight = ($bbox1[1] - $bbox1[7]) + ($bbox2[1] - $bbox2[7]) + 10; // 10px spacing

            switch ($placement) {
                case 'top-left':
                    $x = $paddingX;
                    $y = $paddingY + ($bbox1[1] - $bbox1[7]);
                    break;
                case 'top-right':
                    $x = $width - $paddingX - $textWidth;
                    $y = $paddingY + ($bbox1[1] - $bbox1[7]);
                    break;
                case 'bottom-left':
                    $x = $paddingX;
                    $y = $height - $paddingY - ($bbox2[1] - $bbox2[7]) - 10;
                    break;
                case 'bottom-right':
                default:
                    $x = $width - $paddingX - $textWidth;
                    $y = $height - $paddingY - ($bbox2[1] - $bbox2[7]) - 10;
                    break;
            }

            // Draw shadow for readability
            imagettftext($image, $fontSize, 0, $x + 1, $y + 1, $black, $fontPath, $line1);
            imagettftext($image, $fontSize, 0, $x + 2, $y + 1, $black, $fontPath, $line1);
            imagettftext($image, $fontSize, 0, $x + 1, $y + 2, $black, $fontPath, $line1);
            imagettftext($image, $fontSize, 0, $x + 2, $y + 2, $black, $fontPath, $line1);
            
            imagettftext($image, $fontSize, 0, $x, $y, $white, $fontPath, $line1);
            imagettftext($image, $fontSize, 0, $x + 1, $y, $white, $fontPath, $line1);
            imagettftext($image, $fontSize, 0, $x, $y + 1, $white, $fontPath, $line1);
            imagettftext($image, $fontSize, 0, $x + 1, $y + 1, $white, $fontPath, $line1);
            
            $y2 = $y + ($bbox2[1] - $bbox2[7]) + 10;
            imagettftext($image, $fontSize, 0, $x + 1, $y2 + 1, $black, $fontPath, $line2);
            imagettftext($image, $fontSize, 0, $x + 2, $y2 + 1, $black, $fontPath, $line2);
            imagettftext($image, $fontSize, 0, $x + 1, $y2 + 2, $black, $fontPath, $line2);
            imagettftext($image, $fontSize, 0, $x + 2, $y2 + 2, $black, $fontPath, $line2);
            
            imagettftext($image, $fontSize, 0, $x, $y2, $white, $fontPath, $line2);
            imagettftext($image, $fontSize, 0, $x + 1, $y2, $white, $fontPath, $line2);
            imagettftext($image, $fontSize, 0, $x, $y2 + 1, $white, $fontPath, $line2);
            imagettftext($image, $fontSize, 0, $x + 1, $y2 + 1, $white, $fontPath, $line2);
        } else {
            // Fallback to built-in GD font if TTF is not available
            $font = 5; // Built-in font
            $fw = imagefontwidth($font);
            $fh = imagefontheight($font);
            $textWidth = max(strlen($line1), strlen($line2)) * $fw;

            switch ($placement) {
                case 'top-left': $x = $paddingX; $y = $paddingY; break;
                case 'top-right': $x = $width - $paddingX - $textWidth; $y = $paddingY; break;
                case 'bottom-left': $x = $paddingX; $y = $height - $paddingY - ($fh * 2 + 5); break;
                case 'bottom-right':
                default: $x = $width - $paddingX - $textWidth; $y = $height - $paddingY - ($fh * 2 + 5); break;
            }

            imagestring($image, $font, $x + 1, $y + 1, $line1, $black);
            imagestring($image, $font, $x, $y, $line1, $white);
            imagestring($image, $font, $x + 1, $y + $fh + 6, $line2, $black);
            imagestring($image, $font, $x, $y + $fh + 5, $line2, $white);
        }

        ob_start();
        imagejpeg($image, null, 90);
        $output = ob_get_clean();
        imagedestroy($image);

        return $output;
    } catch (Exception $e) {
        Logger("ERROR in burnTimestampToImageGD: " . $e->getMessage());
        return false;
    }
}

function getMimeType($input, $isContent = false) {
    if ($isContent) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->buffer($input);
    }
    
    // For files on disk, try finfo first
    $mimeType = false;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = @$finfo->file($input);
    }
    
    // Fallback to mime_content_type if available
    if (!$mimeType && function_exists('mime_content_type')) {
        $mimeType = @mime_content_type($input);
    }
    
    // Final fallback: check extension if it's a file path
    if (!$mimeType || $mimeType === 'application/octet-stream') {
        $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
        $map = [
            'mp4' => 'video/mp4',
            'm4v' => 'video/x-m4v',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mpg' => 'video/mpeg',
            'mpeg' => 'video/mpeg',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf'
        ];
        if (isset($map[$extension])) {
            $mimeType = $map[$extension];
        }
    }
    
    return $mimeType ?: 'application/octet-stream';
}

function getFileCategory($mimeType) {
    $mimeType = strtolower($mimeType);
    if (strpos($mimeType, 'image/') === 0) return 'image';
    if (strpos($mimeType, 'audio/') === 0) return 'audio';
    if (strpos($mimeType, 'video/') === 0 || $mimeType === 'application/x-mpegurl' || $mimeType === 'application/vnd.apple.mpegurl') return 'video';
    
    // Additional video mime types that might not start with video/
    $videoMimes = [
        'application/x-flash-video',
        'application/octet-stream' // If we still have octet-stream here, check extension again
    ];
    if (in_array($mimeType, $videoMimes)) return 'video';
    
    return 'document';
}

function downloadFile($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $fileContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error downloading file: " . $error);
    }
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new Exception("Failed to download file. HTTP Status: " . $httpCode);
    }
    return $fileContent;
}

function uploadToBunny($storage, $input, $fileName, $folder = 'cases', $isContent = false) {
    $remotePath = $folder . '/' . $fileName;
    $tempFile = null;

    if ($isContent) {
        $tempFile = sys_get_temp_dir() . '/' . uniqid() . '_upload.tmp';
        file_put_contents($tempFile, $input);
        $localPath = $tempFile;
        Logger("uploadToBunny: Created temp file from content: $localPath");
    } else {
        $localPath = $input;
        if (!file_exists($localPath)) {
            Logger("uploadToBunny ERROR: Local file not found: $localPath");
            throw new Exception("Local file not found for upload: $localPath");
        }
        clearstatcache(true, $localPath);
        $fsize = filesize($localPath);
        if ($fsize === 0) {
            Logger("uploadToBunny WARNING: Local file is 0 bytes: $localPath");
        } else {
            Logger("uploadToBunny: Local file found: $localPath ($fsize bytes)");
        }
    }

    try {
        Logger("Uploading to Bunny: $localPath -> $remotePath (" . filesize($localPath) . " bytes)");
        $storage->upload($localPath, $remotePath);
        Logger("Bunny upload successful for $fileName");
    } catch (Exception $e) {
        Logger("Bunny upload FAILED for $fileName: " . $e->getMessage());
        Logger("Bunny upload ERROR TRACE: " . $e->getTraceAsString());
        throw $e;
    } finally {
        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
            Logger("uploadToBunny: Deleted temp file: $tempFile");
        }
    }

    $pullZoneUrl = defined('BUNNY_PULL_ZONE_URL') ? BUNNY_PULL_ZONE_URL : $_ENV['BUNNY_PULL_ZONE_URL'];
    return $pullZoneUrl . '/' . $remotePath;
}

function generateVideoThumbnail($input, $originalFilename, $isContent = false) {
    $tempVideoPath = sys_get_temp_dir() . '/' . uniqid() . '_' . $originalFilename;
    $tempThumbPath = sys_get_temp_dir() . '/' . uniqid() . '_thumbnail.jpg';
    
    try {
        if ($isContent) {
            file_put_contents($tempVideoPath, $input);
        } else {
            $tempVideoPath = $input;
        }
        
        $durationCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tempVideoPath) . " 2>&1";
        $durationOutput = shell_exec($durationCmd);
        $duration = (float)trim($durationOutput);
        $timestamp = ($duration > 10) ? '00:00:10' : '00:00:00';
        
        $ffmpegCmd = sprintf(
            "ffmpeg -ss %s -i %s -vframes 1 -q:v 2 %s 2>&1",
            escapeshellarg($timestamp),
            escapeshellarg($tempVideoPath),
            escapeshellarg($tempThumbPath)
        );
        shell_exec($ffmpegCmd);
        
        $thumbnailContent = false;
        if (file_exists($tempThumbPath) && filesize($tempThumbPath) > 0) {
            $thumbnailContent = file_get_contents($tempThumbPath);
        }
        
        if ($isContent && file_exists($tempVideoPath)) @unlink($tempVideoPath);
        if (file_exists($tempThumbPath)) @unlink($tempThumbPath);
        
        return $thumbnailContent;
    } catch (Exception $e) {
        if ($isContent && file_exists($tempVideoPath)) @unlink($tempVideoPath);
        if (file_exists($tempThumbPath)) @unlink($tempThumbPath);
        throw new Exception("Error generating thumbnail: " . $e->getMessage());
    }
}

function deleteBubbleFile($fileUrl) {
    $bubbleAppName = $_ENV['BUBBLE_DATA_APP_NAME'];
    $bubbleApiToken = $_ENV['BUBBLE_API_KEY'];
    preg_match('/\/f([0-9]+x[0-9]+)\//', $fileUrl, $matches);
    if (!isset($matches[1])) {
        throw new Exception("Could not extract file ID from Bubble URL: " . $fileUrl);
    }
    $fileId = 'f' . $matches[1];
    $apiUrl = "https://{$bubbleAppName}.bubbleapps.io/version-test/api/1.1/obj/file/{$fileId}";
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $bubbleApiToken,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error deleting from Bubble: " . $error);
    }
    curl_close($ch);
    if ($httpCode === 204 || $httpCode === 200) return true;
    throw new Exception("Failed to delete from Bubble. HTTP Status: {$httpCode}, Response: {$response}");
}

function getImageMetadata($input, $isContent = false) {
    $metadata = [
        'timestamp' => null, 'camera_make' => null, 'camera_model' => null,
        'width' => null, 'height' => null, 'gps_latitude' => null,
        'gps_longitude' => null, 'orientation' => null
    ];
    $tempFile = $input;
    if ($isContent) {
        $tempFile = sys_get_temp_dir() . '/' . uniqid() . '_image.jpg';
        file_put_contents($tempFile, $input);
        Logger("DEBUG: getImageMetadata created temp file for content: $tempFile (" . strlen($input) . " bytes)");
    } else {
        if (!file_exists($tempFile)) {
            Logger("ERROR: getImageMetadata input file not found: $tempFile");
            return $metadata;
        }
        clearstatcache(true, $tempFile);
        Logger("DEBUG: getImageMetadata processing file: $tempFile (" . filesize($tempFile) . " bytes)");
    }

    try {
        $imageInfo = @getimagesize($tempFile, $info);
        if ($imageInfo !== false) {
            $metadata['width'] = $imageInfo[0];
            $metadata['height'] = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            Logger("DEBUG: getimagesize detected mime: $mimeType, dimensions: {$metadata['width']}x{$metadata['height']}");
        }

        // --- NEW: Try Imagick for metadata extraction ---
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($tempFile);
                $properties = $imagick->getImageProperties();
                Logger("DEBUG: Imagick properties found: " . json_encode($properties));

                // Date/Time
                $imagickDateFields = [
                    'exif:DateTimeOriginal', 'exif:DateTime', 'exif:DateTimeDigitized',
                    'exif:ModifyDate', 'ModifyDate',
                    'xmp:CreateDate', 'png:Creation Time', 'png:creation time', 'date:create', 'date:modify',
                    'iptc:2#055', 'iptc:2#060' // Date and Time
                ];
                foreach ($imagickDateFields as $field) {
                    if (!empty($properties[$field])) {
                        $rawDate = $properties[$field];
                        // Handle EXIF date format (YYYY:MM:DD HH:MM:SS)
                        if (preg_match('/^\d{4}:\d{2}:\d{2}/', $rawDate)) {
                            $formattedDate = str_replace(':', '-', substr($rawDate, 0, 10)) . substr($rawDate, 10);
                            $ts = strtotime($formattedDate);
                        } else {
                            $ts = strtotime($rawDate);
                        }
                        if ($ts) {
                            $metadata['timestamp'] = date('Y-m-d H:i:s', $ts);
                            Logger("INFO: Found valid date in Imagick property $field: $rawDate -> " . $metadata['timestamp']);
                            break;
                        }
                    }
                }

                // Camera info
                if (empty($metadata['camera_make']) && !empty($properties['exif:Make'])) $metadata['camera_make'] = trim($properties['exif:Make']);
                if (empty($metadata['camera_model']) && !empty($properties['exif:Model'])) $metadata['camera_model'] = trim($properties['exif:Model']);
                if (empty($metadata['software']) && !empty($properties['exif:Software'])) $metadata['software'] = trim($properties['exif:Software']);
                if (empty($metadata['software']) && !empty($properties['png:Software'])) $metadata['software'] = trim($properties['png:Software']);

                // GPS
                if (!empty($properties['exif:GPSLatitude']) && !empty($properties['exif:GPSLatitudeRef']) && 
                    !empty($properties['exif:GPSLongitude']) && !empty($properties['exif:GPSLongitudeRef'])) {
                    
                    // Imagick EXIF GPS values are often strings like "34/1, 5/1, 1234/100"
                    $metadata['gps_latitude'] = getGps(explode(',', $properties['exif:GPSLatitude']), $properties['exif:GPSLatitudeRef']);
                    $metadata['gps_longitude'] = getGps(explode(',', $properties['exif:GPSLongitude']), $properties['exif:GPSLongitudeRef']);
                }
                
                if (!empty($properties['exif:GPSAltitude'])) {
                    $altParts = explode(',', $properties['exif:GPSAltitude']);
                    $alt = gps2Num(trim($altParts[0] ?? '0/1'));
                    $ref = $properties['exif:GPSAltitudeRef'] ?? '0';
                    $metadata['altitude'] = ($ref === '1') ? -$alt : $alt;
                }

                // Description/Title
                if (empty($metadata['description']) && !empty($properties['exif:ImageDescription'])) $metadata['description'] = trim($properties['exif:ImageDescription']);
                if (empty($metadata['description']) && !empty($properties['png:Description'])) $metadata['description'] = trim($properties['png:Description']);
                if (empty($metadata['description']) && !empty($properties['iptc:2#120'])) $metadata['description'] = trim($properties['iptc:2#120']);

                $imagick->clear();
                $imagick->destroy();
            } catch (Exception $ie) {
                Logger("WARNING: Imagick failed to process $tempFile: " . $ie->getMessage());
            }
        }

        $exif = false;
        // exif_read_data supports JPEG and TIFF. Some PHP versions support more, but PNG is generally not supported for EXIF.
        $supportedExifTypes = [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM];
        if ($imageInfo !== false && in_array($imageInfo[2], $supportedExifTypes)) {
            $exif = @exif_read_data($tempFile);
            if ($exif === false) {
                $error = error_get_last();
                Logger("WARNING: exif_read_data returned false for $tempFile. PHP Error: " . ($error['message'] ?? 'None'));
            }
        } else {
            Logger("INFO: Skipping exif_read_data for unsupported file type: " . ($imageInfo[2] ?? 'unknown'));
        }
        
        if ($exif !== false) {
            $dateFields = [
                'DateTimeOriginal', 
                'DateTime', 
                'DateTimeDigitized', 
                'CreationDate', 
                'CreateDate', 
                'DateCreated',
                'ModifyDate'
            ];
            foreach ($dateFields as $field) {
                if (isset($exif[$field]) && !empty($exif[$field])) {
                    $rawDate = $exif[$field];
                    // Handle EXIF date format (YYYY:MM:DD HH:MM:SS)
                    if (preg_match('/^\d{4}:\d{2}:\d{2}/', $rawDate)) {
                        $formattedDate = str_replace(':', '-', substr($rawDate, 0, 10)) . substr($rawDate, 10);
                        $ts = strtotime($formattedDate);
                    } else {
                        $ts = strtotime($rawDate);
                    }
                    
                    if ($ts) {
                        $metadata['timestamp'] = date('Y-m-d H:i:s', $ts);
                        Logger("INFO: Found valid date in EXIF field $field: $rawDate -> " . $metadata['timestamp']);
                        break;
                    } else {
                        Logger("WARNING: Failed to parse date in EXIF field $field: $rawDate");
                    }
                }
            }
            if (!$metadata['timestamp'] && isset($exif['FileDateTime'])) {
                $metadata['timestamp'] = date('Y-m-d H:i:s', $exif['FileDateTime']);
            }
            if (isset($exif['Make'])) $metadata['camera_make'] = trim($exif['Make']);
            if (isset($exif['Model'])) $metadata['camera_model'] = trim($exif['Model']);
            if (isset($exif['COMPUTED']['Width'])) $metadata['width'] = $exif['COMPUTED']['Width'];
            if (isset($exif['COMPUTED']['Height'])) $metadata['height'] = $exif['COMPUTED']['Height'];
            if (isset($exif['Orientation'])) $metadata['orientation'] = $exif['Orientation'];
            
            // Extract GPS data including altitude and accuracy
            if (isset($exif['GPSLatitude']) && isset($exif['GPSLatitudeRef']) && isset($exif['GPSLongitude']) && isset($exif['GPSLongitudeRef'])) {
                $metadata['gps_latitude'] = getGps($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
                $metadata['gps_longitude'] = getGps($exif['GPSLongitude'], $exif['GPSLongitudeRef']);
            }
            if (isset($exif['GPSAltitude']) && isset($exif['GPSAltitudeRef'])) {
                $alt = gps2Num($exif['GPSAltitude']);
                // Ref 0 = above sea level, Ref 1 = below sea level
                $metadata['altitude'] = (bin2hex($exif['GPSAltitudeRef']) === '01') ? -$alt : $alt;
            }
            if (isset($exif['GPSHPositioningError'])) {
                $metadata['gps_accuracy'] = gps2Num($exif['GPSHPositioningError']);
            }

            // Extract more EXIF fields
            $metadata['exposure_time'] = $exif['ExposureTime'] ?? null;
            $metadata['f_number'] = $exif['FNumber'] ?? null;
            $metadata['iso_speed_ratings'] = $exif['ISOSpeedRatings'] ?? null;
            $metadata['focal_length'] = $exif['FocalLength'] ?? null;
            $metadata['software'] = $exif['Software'] ?? null;

            // Log full EXIF for debugging (careful with large data)
            Logger("DEBUG: Full EXIF data for $tempFile: " . json_encode($exif));
        }
        
        // Handle PNG metadata (tEXt, zTXt, iTXt chunks)
        if ($imageInfo !== false && $imageInfo[2] === IMAGETYPE_PNG) {
            $pngMetadata = parsePngMetadata($tempFile);
            if (!empty($pngMetadata)) {
                Logger("DEBUG: PNG metadata found for $tempFile: " . json_encode($pngMetadata));
                
                // Common PNG creation time keys
                $pngDateFields = ['xmp:CreateDate', 'Creation Time', 'creation time', 'date:create', 'date:modify'];
                foreach ($pngDateFields as $field) {
                    if (!empty($pngMetadata[$field])) {
                        $ts = strtotime($pngMetadata[$field]);
                        if ($ts && !$metadata['timestamp']) {
                            $metadata['timestamp'] = date('Y-m-d H:i:s', $ts);
                            Logger("INFO: Found valid date in PNG metadata field $field: {$pngMetadata[$field]} -> " . $metadata['timestamp']);
                            break;
                        }
                    }
                }
                
                // Other metadata
                if (empty($metadata['camera_make']) && !empty($pngMetadata['Make'])) $metadata['camera_make'] = $pngMetadata['Make'];
                if (empty($metadata['camera_model']) && !empty($pngMetadata['Model'])) $metadata['camera_model'] = $pngMetadata['Model'];
                if (empty($metadata['software']) && !empty($pngMetadata['Software'])) $metadata['software'] = $pngMetadata['Software'];
                if (empty($metadata['description']) && !empty($pngMetadata['Description'])) $metadata['description'] = $pngMetadata['Description'];
                if (empty($metadata['description']) && !empty($pngMetadata['Comment'])) $metadata['description'] = $pngMetadata['Comment'];
            }
        }
        
        // Try IPTC metadata
        $iptc = [];
        @getimagesize($tempFile, $info);
        if (isset($info['APP13'])) {
            $iptc = @iptcparse($info['APP13']);
            if ($iptc) {
                Logger("DEBUG: IPTC data found for $tempFile: " . json_encode($iptc));
                // 2#120 is Caption/Abstract (Description)
                if (empty($metadata['description']) && !empty($iptc['2#120'][0])) {
                    $metadata['description'] = $iptc['2#120'][0];
                }
                // 2#005 is Object Name (Title)
                if (empty($metadata['title']) && !empty($iptc['2#005'][0])) {
                    $metadata['title'] = $iptc['2#005'][0];
                }
                // 2#025 is Keywords
                if (!empty($iptc['2#025'])) {
                    $metadata['keywords'] = $iptc['2#025'];
                }
            }
        }
        if (!$metadata['timestamp']) {
            $mtime = filemtime($tempFile);
            $metadata['timestamp'] = date('Y-m-d H:i:s', $mtime);
            Logger("INFO: No date found in EXIF/IPTC. Using filemtime: " . $metadata['timestamp']);
        }
    } catch (Exception $e) {
        Logger("ERROR: Exception in getImageMetadata: " . $e->getMessage());
    }
    if ($isContent && file_exists($tempFile)) @unlink($tempFile);
    return $metadata;
}

function getGps($exifCoord, $hemi) {
    $degrees = count($exifCoord) > 0 ? gps2Num($exifCoord[0]) : 0;
    $minutes = count($exifCoord) > 1 ? gps2Num($exifCoord[1]) : 0;
    $seconds = count($exifCoord) > 2 ? gps2Num($exifCoord[2]) : 0;
    $flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;
    return $flip * ($degrees + $minutes / 60 + $seconds / 3600);
}

function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return $parts[0];
    return floatval($parts[0]) / floatval($parts[1]);
}

function isExifAvailable() {
    return function_exists('exif_read_data');
}

/**
 * Parses PNG metadata from tEXt and iTXt chunks.
 *
 * @param string $path Path to the PNG file.
 * @return array Extracted metadata as key-value pairs.
 */
function parsePngMetadata($path) {
    $metadata = [];
    $handle = @fopen($path, 'rb');
    if (!$handle) return $metadata;

    // Skip PNG signature
    fseek($handle, 8);

    while (!feof($handle)) {
        $lengthData = fread($handle, 4);
        if (strlen($lengthData) < 4) break;
        $length = unpack('N', $lengthData)[1];
        
        $type = fread($handle, 4);
        if (strlen($type) < 4) break;

        if ($type === 'tEXt') {
            $data = fread($handle, $length);
            $parts = explode("\0", $data, 2);
            if (count($parts) === 2) {
                $metadata[$parts[0]] = $parts[1];
            }
        } elseif ($type === 'iTXt') {
            $data = fread($handle, $length);
            $parts = explode("\0", $data, 5);
            // iTXt structure: Keyword (null) Compression flag (null) Compression method (null) Language tag (null) Translated keyword (null) Text
            // We are interested in Keyword and Text (the last part)
            if (count($parts) >= 5) {
                $keyword = $parts[0];
                $text = end($parts);
                $metadata[$keyword] = $text;
            }
        } elseif ($type === 'zTXt') {
            $data = fread($handle, $length);
            $parts = explode("\0", $data, 3);
            if (count($parts) >= 3) {
                $keyword = $parts[0];
                $compressedText = $parts[2];
                $text = @gzuncompress($compressedText);
                if ($text !== false) {
                    $metadata[$keyword] = $text;
                }
            }
        } elseif ($type === 'IEND') {
            break;
        } else {
            fseek($handle, $length, SEEK_CUR);
        }
        
        // Skip CRC
        fseek($handle, 4, SEEK_CUR);
    }
    
    fclose($handle);
    return $metadata;
}
