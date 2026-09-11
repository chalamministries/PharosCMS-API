<?php

class AuditModel {
    private $db;

    public function __construct() {
        $this->db = $GLOBALS['pdo'];
    }

    /**
     * Log an audit action
     * 
     * @param string $actionCode The code from audit_action_types
     * @param array $user The user data from Auth::authenticate()
     * @param int|null $caseId Optional case ID
     * @param string|null $entityType Optional entity type
     * @param int|null $entityId Optional entity ID
     * @param array $variables Key-value pairs for template placeholders
     * @return bool
     */
    public function log($actionCode, $user, $caseId = null, $entityType = null, $entityId = null, $variables = []) {
        try {
            // Get action type ID
            $actionType = $this->db->queryFirst(
                "SELECT id FROM audit_action_types WHERE code = :code",
                [':code' => $actionCode]
            );

            if (!$actionType) {
                error_log("Audit action code not found: $actionCode");
                return false;
            }

            // Ensure actor is in variables
            if (!isset($variables['actor'])) {
                $variables['actor'] = $user['first_name'] . ' ' . $user['last_name'];
            }

            // Map user type to a single character for the audit_logs table
            $userTypeChar = 'U';
            switch ($user['user_type']) {
                case 'admin':
                    $userTypeChar = 'A';
                    break;
                case 'investigator':
                    $userTypeChar = 'I';
                    break;
                case 'client':
                    $userTypeChar = 'C';
                    break;
            }

            $data = [
                'user_type' => $userTypeChar,
                'user_id' => $user['user_id'],
                'action_type_id' => $actionType['id'],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'case_id' => $caseId,
                'variables' => json_encode($variables),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ];

            return $this->db->insert('audit_logs', $data);
        } catch (Exception $e) {
            error_log("Audit logging failed: " . $e->getMessage());
            return false;
        }
    }
}
