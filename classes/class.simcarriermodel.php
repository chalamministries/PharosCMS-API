<?php
/**
 * SimCarrierModel - Manages SIM card carriers
 * 
 * TABLE: sim_carrier
 * ==================
 * Primary Key: carrier_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * carrier_id   int             Primary key, auto-increment
 * carrier_name varchar(255)    Name of the carrier
 */

class SimCarrierModel
{
    public $pdo;
    public $carrierID = null;
    public $carrierArr = [];

    public function __construct(?int $carrierId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($carrierId !== null) {
            $this->carrierID = $carrierId;
            $this->getCarrier();
        }
    }

    private function getCarrier() {
        $carrier = $this->pdo->selectFirst("sim_carrier", ["carrier_id" => $this->carrierID]);
        if ($carrier) {
            $this->carrierArr = typeSet($carrier, "sim_carrier");
        }
    }

    public function getAllCarriers() {
        $carriers = $this->pdo->select("sim_carrier", [], null, null, ['carrier_name' => 'ASC']);
        if (!$carriers) {
            return [];
        }
        foreach ($carriers as &$carrier) {
            $carrier = typeSet($carrier, "sim_carrier");
        }
        return $carriers;
    }
}
