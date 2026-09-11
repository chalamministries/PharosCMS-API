<?php
/**
 * MediaModel - Manages media files (photos, videos, documents) for cases
 *
 * TABLE: media
 * ============
 * Primary Key: media_id (int, AUTO_INCREMENT)
 *
 * FIELDS:
 * -------
 * media_id            int(11)         Primary key, auto-increment
 * report_id           int(11)         Foreign key to reports table (nullable)
 * case_id             int(11)         Foreign key to cases table
 * activity_id         int(11)         Foreign key to activities table (nullable)
 * activity_uuid       varchar(36)     UUID of linked activity (nullable)
 * objective_id        int(11)         Foreign key to objectives table (nullable)
 * investigator_id     int(11)         Foreign key to investigators table
 * file_path           varchar(512)    Path to file on server
 * file_type           varchar(50)     MIME type or file extension (nullable)
 * description         text            Description of media content (nullable)
 * uploaded_at         datetime        Auto-populated timestamp
 * is_client_viewable  tinyint(1)      Boolean: 0 or 1, default: 0 (whether client can see this file)
 * is_investigator_viewable  tinyint(1)      Boolean: 0 or 1, default: 0 (whether investigator can see this file)
 * processing          tinyint(1)      Boolean: 0 or 1, default: 1 (whether media is currently being processed)
 *
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: reports (report_id) - CASCADE delete
 * - Belongs to: cases (case_id) - CASCADE delete
 * - Belongs to: activities (activity_id) - nullable
 * - Belongs to: objectives (objective_id) - nullable
 * - Belongs to: investigators (investigator_id)
 */
class MediaModel
{
    // Fields to exclude from updates (auto-managed)
    private const UPDATE_EXCLUDED_FIELDS = [
        'media_id',      // Primary key
        'uploaded_at'    // Auto-populated
    ];
    
    private $pdo;
    private $mediaId = null;
    private $mediaArr = [];
    
    /**
     * Load a media record
     * 
     * @param int|null $mediaId The media ID to load
     * 
     * @throws InvalidArgumentException If media ID is not an integer
     * @throws OutOfRangeException If media ID is not positive
     * @throws OutOfBoundsException If media is not found
     */
    public function __construct($mediaId = null) {
        $this->pdo = $GLOBALS['pdo'];
        
        if ($mediaId !== null) {
            if (!is_int($mediaId)) {
                throw new InvalidArgumentException("Media ID must be an integer");
            }
            
            if ($mediaId <= 0) {
                throw new OutOfRangeException("Media ID must be positive");
            }
            
            $this->mediaId = $mediaId;
            $this->getMedia();
            
            if (empty($this->mediaArr)) {
                throw new OutOfBoundsException("Media {$mediaId} not found");
            }
        }
    }
    
    /**
     * Load the media record with related information
     */
    private function getMedia() {
        $sql = "
            SELECT
                m.*,
                r.title as report_title,
                r.report_date,
                CONCAT(i.first_name, ' ', i.last_name) as uploaded_by
            FROM media m
            LEFT JOIN reports r ON m.report_id = r.report_id
            LEFT JOIN investigators i ON m.investigator_id = i.investigator_id
            WHERE m.media_id = :media_id
        ";

        $result = $this->pdo->query($sql, [":media_id" => $this->mediaId]);

        if (!empty($result)) {
            $this->mediaArr = $result[0];
        }
    }


    public function getMediaByActivity($activityId) {
        $sql = "
            SELECT
                m.media_id,
                m.case_id,
                m.activity_id,
                m.activity_uuid,
                m.objective_id,
                m.investigator_id,
                m.file_path,
                m.file_type,
                m.video_thumb,
                m.description,
                m.uploaded_at,
                m.entry_id,
                m.is_client_viewable,
                m.is_investigator_viewable,
                m.processing,
                m.captured_at,
                CONCAT(i.first_name, ' ', i.last_name) as uploaded_by
            FROM media m
            LEFT JOIN investigators i ON m.investigator_id = i.investigator_id
            WHERE m.activity_id = :activity_id
            ORDER BY m.uploaded_at ASC
        ";

        $results = $this->pdo->query($sql, [":activity_id" => $activityId]) ?: [];

        // Group by description + uploaded_at
        $grouped = [];

        foreach ($results as $row) {
            // Create a unique key based on description and timestamp (rounded to nearest minute)
            $timestamp = strtotime($row['uploaded_at']);
            $roundedTime = date('Y-m-d H:i', $timestamp);
            $groupKey = md5($row['description'] . $roundedTime);

            if (!isset($grouped[$groupKey])) {
                // Initialize the group with metadata
                $grouped[$groupKey] = [
                    'case_id' => $row['case_id'],
                    'activity_id' => $row['activity_id'],
                    'activity_uuid' => $row['activity_uuid'],
                    'objective_id' => $row['objective_id'],
                    'description' => $row['description'],
                    'uploaded_at' => $row['uploaded_at'],
                    'uploaded_by' => $row['uploaded_by'],
                    'captured_at' => $row['captured_at'],
                    'investigator_id' => $row['investigator_id'],
                    'is_client_viewable' => $row['is_client_viewable'],
                    'is_investigator_viewable' => $row['is_investigator_viewable'],
                    'files' => []
                ];
            }

            // Add file to the group
            $grouped[$groupKey]['files'][] = [
                'media_id' => $row['media_id'],
                'file_path' => $row['file_path'],
                'file_type' => $row['file_type'],
                'video_thumb' => $row['video_thumb'],
                'entry_id' => $row['entry_id']
            ];
        }

        // Re-index array to remove hash keys
        return array_values($grouped);
    }

    /**
     * Get all media for a specific case
     *
     * @param int $caseId
     * @return array
     */
    public function getMediaByCase($caseId, $group = false) {

        $sql = "
            SELECT
                m.media_id,
                m.case_id,
                m.activity_id,
                m.activity_uuid,
                m.objective_id,
                m.investigator_id,
                m.file_path,
                m.file_type,
                m.video_thumb,
                m.description,
                m.uploaded_at,
                m.is_client_viewable,
                m.is_investigator_viewable,
                m.processing,
                m.captured_at,
                CONCAT(i.first_name, ' ', i.last_name) as uploaded_by
            FROM media m
            LEFT JOIN investigators i ON m.investigator_id = i.investigator_id
            WHERE m.case_id = :case_id
            ORDER BY m.uploaded_at ASC
        ";

        $results = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];

        if($group) {

            $grouped = [];

            foreach ($results as $row) {
                // Create a unique key based on description and timestamp (rounded to nearest minute)
                $timestamp = strtotime($row['uploaded_at']);
                $roundedTime = date('Y-m-d H:i', $timestamp);
                $groupKey = md5($row['description'] . $roundedTime);

                if (!isset($grouped[$groupKey])) {
                    // Initialize the group with metadata
                    $grouped[$groupKey] = [
                        'case_id' => $row['case_id'],
                        'activity_id' => $row['activity_id'],
                        'activity_uuid' => $row['activity_uuid'],
                        'objective_id' => $row['objective_id'],
                        'description' => $row['description'],
                        'uploaded_at' => $row['uploaded_at'],
                        'uploaded_by' => $row['uploaded_by'],
                        'investigator_id' => $row['investigator_id'],
                        'is_client_viewable' => $row['is_client_viewable'],
                        'is_investigator_viewable' => $row['is_investigator_viewable'],
                        'processing' => $row['processing'],
                        'captured_at' => $row['captured_at'],
                        'files' => []
                    ];
                }

                // Add file to the group
                $grouped[$groupKey]['files'][] = [
                    'media_id' => $row['media_id'],
                    'file_path' => $row['file_path'],
                    'file_type' => $row['file_type'],
                    'video_thumb' => $row['video_thumb'],
                    'entry_id' => $row['entry_id']
                ];
            }

            // Re-index array to remove hash keys
            return array_values($grouped);
        } else {
            return $results;
        }
    }
    
    /**
     * Get all media for a specific report
     *
     * @param int $reportId
     * @return array
     */
    public function getMediaByReport($reportId) {
        $sql = "
            SELECT
                m.media_id,
                m.case_id,
                m.report_id,
                m.investigator_id,
                m.file_path,
                m.file_type,
                m.video_thumb,
                m.description,
                m.uploaded_at,
                m.is_client_viewable,
                m.is_investigator_viewable,
                m.processing,
                m.captured_at,
                CONCAT(i.first_name, ' ', i.last_name) as uploaded_by
            FROM media m
            LEFT JOIN investigators i ON m.investigator_id = i.investigator_id
            WHERE m.report_id = :report_id
            ORDER BY m.uploaded_at ASC
        ";

        $results = $this->pdo->query($sql, [":report_id" => $reportId]) ?: [];

        return $results;
    }
    
    /**
     * Get intake media for a case (media not tied to any report)
     *
     * @param int $caseId
     * @return array
     */
    public function getIntakeMediaByCase($caseId) {
        $sql = "
            SELECT
                m.media_id,
                m.case_id,
                m.investigator_id,
                m.file_path,
                m.file_type,
                m.video_thumb,
                m.description,
                m.uploaded_at,
                m.is_client_viewable,
                m.is_investigator_viewable,
                m.processing,
                m.captured_at,
                CONCAT(i.first_name, ' ', i.last_name) as uploaded_by
            FROM media m
            LEFT JOIN investigators i ON m.investigator_id = i.investigator_id
            WHERE m.case_id = :case_id AND m.report_id IS NULL
            ORDER BY m.uploaded_at ASC
        ";

        $results = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];

        return $results;
    }
    
    /**
     * Get client-viewable media for a case
     *
     * @param int $caseId
     * @return array
     */
    public function getClientViewableMedia($caseId) {
        $sql = "
            SELECT
                m.media_id,
                m.case_id,
                m.report_id,
                m.file_path,
                m.file_type,
                m.video_thumb,
                m.description,
                m.uploaded_at,
                m.captured_at,
                r.title as report_title,
                r.report_date
            FROM media m
            LEFT JOIN reports r ON m.report_id = r.report_id
            WHERE m.case_id = :case_id AND m.is_client_viewable = 1 AND m.processing = 0
            ORDER BY m.uploaded_at DESC
        ";

        $results = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];

        return $results;
    }
    
    /**
     * Update media record
     * 
     * @return bool
     */
    public function updateMedia() {
        if (empty($this->mediaArr)) {
            throw new BadMethodCallException("Cannot update: media not loaded");
        }
        
        if ($this->mediaId == null || $this->mediaId == 0) {
            throw new BadMethodCallException("Cannot update: invalid media ID");
        }
        
        // Get only valid media table fields, excluding auto-managed fields
        $mediaData = filterTableFields(
            $this->mediaArr, 
            'media', 
            self::UPDATE_EXCLUDED_FIELDS
        );
        
        if (empty($mediaData)) {
            throw new BadMethodCallException("No valid fields to update");
        }
        
        $this->pdo->update("media", $mediaData, array("media_id" => $this->mediaId));
        return true;
    }
    
    /**
     * Create new media record
     * 
     * @param array $mediaData Array of media data to insert
     * @return int The newly created media_id
     * 
     * @throws InvalidArgumentException If required fields are missing
     */
    public function createMedia($mediaData) {
        // Validate required fields
        $requiredFields = ['case_id', 'file_path'];
        foreach ($requiredFields as $field) {
            if (!isset($mediaData[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        // Filter to only valid table fields
        //$mediaData = filterTableFields($mediaData, 'media', ['media_id', 'uploaded_at']);
        
        // Set defaults
        if (!isset($mediaData['is_client_viewable'])) {
            $mediaData['is_client_viewable'] = 0;
        }

        if (!isset($mediaData['is_investigator_viewable'])) {
            $mediaData['is_investigator_viewable'] = 0;
        }
        
        error_log(json_encode($mediaData));
        
        $mediaId = $this->pdo->insert("media", $mediaData);
        
        // Load the newly created media
        $this->mediaId = $mediaId;
        $this->getMedia();
        
        return $mediaId;
    }
    
    /**
     * Delete media record and optionally the file
     * 
     * @param bool $deleteFile Whether to delete the physical file
     * @return bool
     */
    public function deleteMedia($deleteFile = false) {
        if (empty($this->mediaArr)) {
            throw new BadMethodCallException("Cannot delete: media not loaded");
        }
        
        if ($this->mediaId == null || $this->mediaId == 0) {
            throw new BadMethodCallException("Cannot delete: invalid media ID");
        }
        
        // Optionally delete physical file
        if ($deleteFile && !empty($this->mediaArr['file_path'])) {
            $fullPath = $_SERVER['DOCUMENT_ROOT'] . $this->mediaArr['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
        
        $this->pdo->delete("media", array("media_id" => $this->mediaId));
        
        // Clear the loaded data
        $this->mediaArr = [];
        $this->mediaId = null;
        
        return true;
    }
    
    /**
     * Toggle client visibility
     * 
     * @return bool New visibility state
     */
    public function toggleClientViewable() {
        if (empty($this->mediaArr)) {
            throw new BadMethodCallException("Cannot toggle: media not loaded");
        }
        
        $newValue = $this->mediaArr['is_client_viewable'] ? 0 : 1;
        
        $this->pdo->update(
            "media", 
            array("is_client_viewable" => $newValue),
            array("media_id" => $this->mediaId)
        );
        
        $this->mediaArr['is_client_viewable'] = $newValue;
        
        return (bool)$newValue;
    }

    /**
     * Toggle client visibility
     *
     * @return bool New visibility state
     */
    public function toggleInvestigatorViewable() {
        if (empty($this->mediaArr)) {
            throw new BadMethodCallException("Cannot toggle: media not loaded");
        }

        $newValue = $this->mediaArr['is_investigator_viewable'] ? 0 : 1;

        $this->pdo->update(
            "media",
            array("is_investigator_viewable" => $newValue),
            array("media_id" => $this->mediaId)
        );

        $this->mediaArr['is_investigator_viewable'] = $newValue;

        return (bool)$newValue;
    }
    
    /**
     * Get media count for a case
     *
     * @param int $caseId
     * @return array Counts broken down by type
     */
    public function getMediaCounts($caseId) {
        $sql = "
            SELECT
                COUNT(*) as total_media,
                SUM(CASE WHEN report_id IS NULL THEN 1 ELSE 0 END) as intake_media,
                SUM(CASE WHEN report_id IS NOT NULL THEN 1 ELSE 0 END) as report_media,
                SUM(CASE WHEN is_client_viewable = 1 THEN 1 ELSE 0 END) as client_viewable
            FROM media
            WHERE case_id = :case_id
            AND processing = 0
        ";

        $result = $this->pdo->query($sql, [":case_id" => $caseId]);

        if (empty($result)) {
            return [
                'total_media' => 0,
                'intake_media' => 0,
                'report_media' => 0,
                'client_viewable' => 0
            ];
        }

        return [
            'total_media' => (int)$result[0]['total_media'],
            'intake_media' => (int)$result[0]['intake_media'],
            'report_media' => (int)$result[0]['report_media'],
            'client_viewable' => (int)$result[0]['client_viewable']
        ];
    }

}