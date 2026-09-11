<?php
/**
 * MessageModel - Manages case-specific messages
 * 
 * TABLE: case_messages
 * ====================
 * Primary Key: message_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * message_id      int             Primary key, auto-increment
 * case_id         int             Foreign key to cases table
 * sender_id       int             ID of the sender (admin, investigator, client)
 * sender_type     enum            'investigator', 'case_manager', 'admin', 'client'
 * message_type    enum            'text', 'image', 'system'
 * message_text    text            The message content
 * image_url       varchar(512)    URL to image (if message_type is image)
 * thumbnail_url   varchar(512)    URL to thumbnail (if message_type is image)
 * created_at      timestamp       When the message was sent
 * read_at         timestamp       When the message was read
 */

class MessageModel
{
    public $pdo;
    public $messageID = null;
    public $messageArr = [];

    public function __construct(?int $messageId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($messageId !== null) {
            $this->messageID = $messageId;
            $this->getMessage();
        }
    }

    private function getMessage() {
        $message = $this->pdo->selectFirst("case_messages", ["message_id" => $this->messageID]);
        if ($message) {
            $this->messageArr = typeSet($message, "case_messages");
        }
    }

    public function getMessagesByCase($case_id) {
        $sql = "
            SELECT 
                m.*,
                CASE 
                    WHEN m.sender_type = 'investigator' THEN CONCAT(i.first_name, ' ', i.last_name)
                    WHEN m.sender_type = 'client' THEN CONCAT(c.first_name, ' ', c.last_name)
                    WHEN m.sender_type IN ('admin', 'case_manager') THEN CONCAT(a.first_name, ' ', a.last_name)
                    ELSE 'System'
                END as sender_name,
                CASE 
                    WHEN m.sender_type = 'investigator' THEN 'investigator'
                    WHEN m.sender_type = 'client' THEN 'client'
                    WHEN m.sender_type IN ('admin', 'case_manager') THEN 'admin'
                    ELSE 'system'
                END as sender_role
            FROM case_messages m
            LEFT JOIN investigators i ON m.sender_id = i.investigator_id AND m.sender_type = 'investigator'
            LEFT JOIN clients c ON m.sender_id = c.client_id AND m.sender_type = 'client'
            LEFT JOIN admins a ON m.sender_id = a.admin_id AND m.sender_type IN ('admin', 'case_manager')
            WHERE m.case_id = :case_id
            ORDER BY m.created_at ASC
        ";
        
        $messages = $this->pdo->query($sql, [":case_id" => $case_id]);
        
        if (!$messages) {
            return [];
        }

        $formattedMessages = [];
        foreach ($messages as $m) {
            $formattedMessages[] = [
                'message_id' => 'msg_' . $m['message_id'],
                'room_id' => (string)$m['case_id'],
                'case_id' => (string)$m['case_id'],
                'message_type' => $m['message_type'],
                'text' => $m['message_text'],
                'image_url' => $m['image_url'],
                'thumbnail_url' => $m['thumbnail_url'],
                'sender' => [
                    'user_id' => (string)$m['sender_id'],
                    'name' => $m['sender_name'],
                    'role' => $m['sender_role']
                ],
                'created_at' => date('c', strtotime($m['created_at'])),
                'read' => !empty($m['read_at'])
            ];
        }

        return $formattedMessages;
    }

    public function getUnreadCount($case_id, $user_id, $user_type) {
        $sql = "
            SELECT COUNT(*) as unread_count
            FROM case_messages
            WHERE case_id = :case_id
              AND read_at IS NULL
              AND (sender_id != :user_id OR sender_type != :user_type)
        ";
        $result = $this->pdo->queryFirst($sql, [
            ":case_id" => $case_id,
            ":user_id" => $user_id,
            ":user_type" => $user_type
        ]);
        
        return $result ? (int)$result['unread_count'] : 0;
    }

    public function getLastReadMessageId($case_id, $user_id, $user_type) {
        $sql = "
            SELECT message_id
            FROM case_messages
            WHERE case_id = :case_id
              AND read_at IS NOT NULL
              AND (sender_id != :user_id OR sender_type != :user_type)
            ORDER BY read_at DESC, message_id DESC
            LIMIT 1
        ";
        $result = $this->pdo->queryFirst($sql, [
            ":case_id" => $case_id,
            ":user_id" => $user_id,
            ":user_type" => $user_type
        ]);
        
        return $result ? 'msg_' . $result['message_id'] : null;
    }

    /**
     * Create a new message
     * 
     * @param array $data Message data
     * @return int|bool The message ID if created, false otherwise
     */
    public function createMessage($data) {
        $insertData = [
            'case_id'      => $data['case_id'],
            'sender_id'    => $data['sender_id'],
            'sender_type'  => $data['sender_type'],
            'message_type' => $data['message_type'] ?? 'text',
            'message_text' => $data['message_text'] ?? '',
            'image_url'    => $data['image_url'] ?? null,
            'thumbnail_url'=> $data['thumbnail_url'] ?? null,
            'created_at'   => date('Y-m-d H:i:s')
        ];

        $this->messageID = $this->pdo->insert("case_messages", $insertData);
        if ($this->messageID) {
            $this->getMessage();
            return $this->messageID;
        }
        return false;
    }

    /**
     * Format a single message for API response
     * 
     * @return array|null
     */
    public function formatMessage() {
        if (empty($this->messageArr)) {
            return null;
        }

        // We need to fetch sender info for formatting
        $m = $this->messageArr;
        $sql = "
            SELECT 
                CASE 
                    WHEN :sender_type = 'investigator' THEN CONCAT(i.first_name, ' ', i.last_name)
                    WHEN :sender_type = 'client' THEN CONCAT(c.first_name, ' ', c.last_name)
                    WHEN :sender_type IN ('admin', 'case_manager') THEN CONCAT(a.first_name, ' ', a.last_name)
                    ELSE 'System'
                END as sender_name,
                CASE 
                    WHEN :sender_type = 'investigator' THEN 'investigator'
                    WHEN :sender_type = 'client' THEN 'client'
                    WHEN :sender_type IN ('admin', 'case_manager') THEN 'admin'
                    ELSE 'system'
                END as sender_role
            FROM (SELECT 1) dummy
            LEFT JOIN investigators i ON :sender_id = i.investigator_id AND :sender_type = 'investigator'
            LEFT JOIN clients c ON :sender_id = c.client_id AND :sender_type = 'client'
            LEFT JOIN admins a ON :sender_id = a.admin_id AND :sender_type IN ('admin', 'case_manager')
        ";

        $sender = $this->pdo->queryFirst($sql, [
            ":sender_id" => $m['sender_id'],
            ":sender_type" => $m['sender_type']
        ]);

        return [
            'message_id' => 'msg_' . $m['message_id'],
            'room_id' => (string)$m['case_id'],
            'case_id' => (string)$m['case_id'],
            'message_type' => $m['message_type'],
            'text' => $m['message_text'],
            'image_url' => $m['image_url'],
            'thumbnail_url' => $m['thumbnail_url'],
            'sender' => [
                'user_id' => (string)$m['sender_id'],
                'name' => $sender ? $sender['sender_name'] : 'Unknown',
                'role' => $sender ? $sender['sender_role'] : 'unknown'
            ],
            'created_at' => date('c', strtotime($m['created_at'])),
            'read' => !empty($m['read_at'])
        ];
    }

    /**
     * Mark a message as read
     * 
     * @param int $message_id
     * @return bool
     */
    public function markAsRead($message_id) {
        $updateData = [
            'read_at' => date('Y-m-d H:i:s')
        ];
        return $this->pdo->update("case_messages", $updateData, ["message_id" => $message_id]);
    }
}
