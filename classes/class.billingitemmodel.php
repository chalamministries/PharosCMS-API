<?php
/**
 * BillingItemModel - Manages individual billing items for cases
 * 
 * TABLE: billing_items
 * ===================
 * Primary Key: item_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * item_id              int             Primary key, auto-increment
 * case_id              int             Foreign key to cases table
 * investigator_id      int             Optional foreign key to investigators table
 * activity_id          int             Optional link to specific activity
 * objective_id         int             Optional link to specific objective
 * invoice_id           int             Link to invoice when billed
 * description          varchar(255)    Description of the item
 * billing_notes        text            Internal billing notes
 * item_type            enum            'gps_deploy', 'gps_retrieve', etc.
 * quantity             int             Default 1
 * unit_cost            decimal(10,2)   Cost per unit
 * total_cost           decimal(10,2)   Total cost (quantity * unit_cost)
 * status               enum            'submitted', 'approved', 'rejected', 'billed'
 * submitted_at         datetime        When submitted
 * approved_by_admin_id int             Admin who approved
 * approval_date        datetime        When approved
 * created_at           datetime        Auto-populated timestamp
 * updated_at           datetime        Auto-updated timestamp
 */

class BillingItemModel
{
    public $pdo;
    public $itemID = null;
    public $itemArr = [];

    public function __construct(?int $itemId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($itemId !== null) {
            $this->itemID = $itemId;
            $this->getItem();
        }
    }

    private function getItem() {
        $item = $this->pdo->selectFirst("billing_items", ["item_id" => $this->itemID]);
        if ($item) {
            $this->itemArr = typeSet($item, "billing_items");
        }
    }

    public function getItemsByCase($caseId) {
        $items = $this->pdo->select("billing_items", ["case_id" => $caseId], null, null, ['submitted_at' => 'DESC']);
        if (!$items) {
            return [];
        }
        foreach ($items as &$row) {
            $row = typeSet($row, "billing_items");
        }
        return $items;
    }
}
