<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Service;
use Utils\Flash;

class ServicesController extends Controller {
    private $serviceModel;

    public function __construct() {
        $this->serviceModel = new Service();
        $this->checkAuth();
    }

    private function checkAuth() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            $this->redirect('index.php');
        }
    }

    public function index() {
        $services = $this->serviceModel->all();
        $this->render('services_products/index', [
            'services' => $services,
            'edit_service' => null
        ]);
    }

    public function edit($id) {
        $services = $this->serviceModel->all();
        $edit_service = $this->serviceModel->find($id);
        $this->render('services_products/index', [
            'services' => $services,
            'edit_service' => $edit_service
        ]);
    }

    public function save() {
        $id = $_POST['service_id'] ?? null;
        $data = [
            'service_name' => trim($_POST['service_name']),
            'category' => $_POST['category'] ?? 'Minor',
            'standard_price' => (float)$_POST['standard_price'],
            'estimated_duration' => trim($_POST['estimated_duration'] ?? ''),
            'track_interval' => isset($_POST['track_interval']) ? 1 : 0,
            'service_interval' => !empty($_POST['service_interval']) ? (int)$_POST['service_interval'] : null,
            'interval_unit' => $_POST['interval_unit'] ?? 'months',
            'has_expiry' => isset($_POST['has_expiry']) ? 1 : 0,
            'expiry_days' => !empty($_POST['expiry_days']) ? (int)$_POST['expiry_days'] : null,
            'expiry_unit' => $_POST['expiry_unit'] ?? 'months',
            'reminder_days' => (int)$_POST['reminder_days'],
            'description' => trim($_POST['description'] ?? ''),
            'service_includes' => trim($_POST['service_includes'] ?? ''),
            'requires_parts' => isset($_POST['requires_parts']) ? 1 : 0
        ];
        $success = $this->serviceModel->createOrUpdate($id, $data);
        Flash::set('success', $success ? ($id ? 'Service updated' : 'Service added') : 'Operation failed');
        $this->redirect('/services_products');
    }

    public function delete() {
        $id = $_POST['service_id'] ?? null;
        if ($id && $this->serviceModel->softDelete($id)) {
            Flash::set('success', 'Service deactivated successfully.');
        } else {
            Flash::set('error', 'Deactivation failed.');
        }
        $this->redirect('/services_products');
    }

    public function view($id) {
        $service = $this->serviceModel->find($id);
        if (!$service) {
            Flash::set('error', 'Service not found.');
            $this->redirect('/services_products');
        }
        $this->render('services/view', ['service' => $service]);
    }
}