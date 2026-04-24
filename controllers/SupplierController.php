<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Supplier;

class SupplierController extends Controller {
    private $supplierModel;
    
    public function __construct() {
        $this->supplierModel = new Supplier();
    }
    
    public function index() {
        $page = $_GET['page'] ?? 1;
        $search = $_GET['search'] ?? null;
        
        $suppliers = $this->supplierModel->paginate($page, 15);
        
        foreach ($suppliers['data'] as &$supplier) {
            $supplier['total_spent'] = $this->supplierModel->getTotalSpent($supplier['id']);
        }
        
        $this->view('suppliers/index', [
            'suppliers' => $suppliers,
            'search' => $search
        ]);
    }
    
    public function create() {
        $this->view('suppliers/create');
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'supplier_name' => 'required|min:2|max:100',
            'telephone' => 'required',
            'email' => 'required|email',
            'address' => 'required'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('/suppliers/create');
            return;
        }
        
        $supplierId = $this->supplierModel->create($data);
        $_SESSION['success'] = 'Supplier added successfully';
        $this->redirect("/suppliers/view/{$supplierId}");
    }
    
    public function view($id) {
        $supplier = $this->supplierModel->find($id);
        
        if (!$supplier) {
            $_SESSION['error'] = 'Supplier not found';
            $this->redirect('/suppliers');
            return;
        }
        
        $purchaseHistory = $this->supplierModel->getPurchaseHistory($id);
        $totalSpent = $this->supplierModel->getTotalSpent($id);
        
        $this->view('suppliers/view', [
            'supplier' => $supplier,
            'purchaseHistory' => $purchaseHistory,
            'totalSpent' => $totalSpent
        ]);
    }
    
    public function edit($id) {
        $supplier = $this->supplierModel->find($id);
        
        if (!$supplier) {
            $_SESSION['error'] = 'Supplier not found';
            $this->redirect('/suppliers');
            return;
        }
        
        $this->view('suppliers/edit', ['supplier' => $supplier]);
    }
    
    public function update($id) {
        $data = $this->sanitize($_POST);
        
        $this->supplierModel->update($id, $data);
        $_SESSION['success'] = 'Supplier updated successfully';
        $this->redirect("/suppliers/view/{$id}");
    }
    
    public function delete($id) {
        $this->supplierModel->delete($id);
        $_SESSION['success'] = 'Supplier deleted successfully';
        $this->redirect('/suppliers');
    }
}