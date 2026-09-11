<?php
/**
 * TrackerDeploymentModel - Manages tracker deployments by investigators
 * 
 * TABLE: tracker_deployments
 * ==========================
 * Primary Key: deployment_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * deployment_id       int             Primary key, auto-increment
 * assignment_id       int             Foreign key to tracker_assignments table
 * investigator_id     int             Foreign key to investigators table
 * vehicle_description varchar(255)    Description of the vehicle tracker is on
 * deployed_at         timestamp       When the tracker was deployed
 * retrieved_at        timestamp       When the tracker was retrieved
 */

class TrackerDeploymentModel
{
    public $pdo;
    public $deploymentID = null;
    public $deploymentArr = [];

    public function __construct(?int $deploymentId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($deploymentId !== null) {
            $this->deploymentID = $deploymentId;
            $this->getDeployment();
        }
    }

    private function getDeployment() {
        $deployment = $this->pdo->selectFirst("tracker_deployments", ["deployment_id" => $this->deploymentID]);
        if ($deployment) {
            $this->deploymentArr = typeSet($deployment, "tracker_deployments");
        }
    }

    public function getDeploymentsByAssignment($assignmentId) {
        $deployments = $this->pdo->select("tracker_deployments", ["assignment_id" => $assignmentId], null, null, ['deployed_at' => 'DESC']);
        if (!$deployments) {
            return [];
        }
        foreach ($deployments as &$row) {
            $row = typeSet($row, "tracker_deployments");
        }
        return $deployments;
    }
}
