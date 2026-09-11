<?php
/**
 * InvoiceModel - Manages invoices for cases
 * 
 * TABLE: invoices
 * ===============
 * Primary Key: invoice_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * invoice_id     int             Primary key, auto-increment
 * case_id        int             Foreign key to cases table
 * client_id      int             Foreign key to clients table
 * invoice_number varchar(50)     Unique invoice number
 * invoice_date   date            Date of invoice
 * due_date       date            Due date of invoice
 * subtotal       decimal(12,2)   Subtotal amount
 * tax_rate       decimal(5,4)    Tax rate
 * tax_amount     decimal(12,2)   Tax amount
 * total_amount   decimal(12,2)   Total amount
 * amount_paid    decimal(12,2)   Amount already paid
 * status         enum            'draft', 'sent', 'paid', 'overdue', 'cancelled'
 * payment_terms  varchar(100)    Payment terms
 * notes          text            Additional notes
 * sent_at        datetime        When invoice was sent
 * paid_at        datetime        When invoice was paid
 * created_by     int             Admin ID who created the invoice
 * created_at     datetime        Auto-populated timestamp
 * updated_at     datetime        Auto-updated timestamp
 */

class InvoiceModel
{
    public $pdo;
    public $invoiceID = null;
    public $invoiceArr = [];

    public function __construct(?int $invoiceId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($invoiceId !== null) {
            $this->invoiceID = $invoiceId;
            $this->getInvoice();
        }
    }

    private function getInvoice() {
        $invoice = $this->pdo->selectFirst("invoices", ["invoice_id" => $this->invoiceID]);
        if ($invoice) {
            $this->invoiceArr = typeSet($invoice, "invoices");
        }
    }

    public function getInvoicesByCase($caseId) {
        $invoices = $this->pdo->select("invoices", ["case_id" => $caseId], null, null, ['invoice_date' => 'DESC']);
        if (!$invoices) {
            return [];
        }
        foreach ($invoices as &$invoice) {
            $invoice = typeSet($invoice, "invoices");
        }
        return $invoices;
    }

    public function getInvoicesByClient($clientId) {
        $invoices = $this->pdo->select("invoices", ["client_id" => $clientId], null, null, ['invoice_date' => 'DESC']);
        if (!$invoices) {
            return [];
        }
        foreach ($invoices as &$invoice) {
            $invoice = typeSet($invoice, "invoices");
        }
        return $invoices;
    }
}
