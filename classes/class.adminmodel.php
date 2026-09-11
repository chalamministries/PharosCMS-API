<?php
/**
 * AdminModel - Manages administrative users
 * 
 * TABLE: admins
 * =============
 * Primary Key: admin_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * admin_id      int             Primary key, auto-increment
 * first_name    varchar(100)    Admin first name
 * last_name     varchar(100)    Admin last name
 * email         varchar(255)    Admin email (unique)
 * password_hash varchar(255)    Hashed password
 * role          enum            'super_admin', 'case_manager', 'billing_admin'
 * created_at    datetime        Auto-populated timestamp
 * updated_at    datetime        Auto-updated timestamp
 */

class AdminModel
{
    public $pdo;
    public $adminID = null;
    public $adminArr = [];

    public function __construct(?int $adminId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($adminId !== null) {
            if ($adminId <= 0) {
                throw new OutOfRangeException("Admin ID must be positive");
            }
            $this->adminID = $adminId;
            $this->getAdmin();

            if (empty($this->adminArr)) {
                throw new OutOfBoundsException("Admin {$adminId} not found");
            }
        }
    }

    private function getAdmin() {
        $admin = $this->pdo->selectFirst("admins", ["admin_id" => $this->adminID]);
        if ($admin) {
            $this->adminArr = typeSet($admin, "admins");
        }
    }

    public function getAllAdmins() {
        $admins = $this->pdo->select("admins", [], null, null, ['last_name' => 'ASC', 'first_name' => 'ASC']);
        if (!$admins) {
            return [];
        }
        foreach ($admins as &$admin) {
            $admin = typeSet($admin, "admins");
            unset($admin['password_hash']);
        }
        return $admins;
    }
}
