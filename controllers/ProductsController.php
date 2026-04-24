<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Product;
use Utils\Flash;
use function generateProductCode;

class ProductsController extends Controller {
    private $productModel;

    public function __construct() {
        $this->productModel = new Product();
        $this->checkAuth();
    }

    private function checkAuth() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            $this->redirect('index.php');
        }
    }

    public function index() {
        $products = $this->productModel->all();
        $this->render('services_products/index', [
            'products' => $products,
            'edit_product' => null
        ]);
    }

    public function edit($id) {
        $products = $this->productModel->all();
        $edit_product = $this->productModel->find($id);
        $this->render('services_products/index', [
            'products' => $products,
            'edit_product' => $edit_product
        ]);
    }

    public function save() {
        $id = $_POST['product_id'] ?? null;
        $data = [
            'item_code' => trim($_POST['item_code']),
            'product_name' => trim($_POST['product_name']),
            'category' => trim($_POST['category'] ?? ''),
            'unit_of_measure' => trim($_POST['unit_of_measure']),
            'unit_cost' => (float)$_POST['unit_cost'],
            'selling_price' => (float)$_POST['selling_price'],
            'quantity' => (int)$_POST['opening_stock'],
            'reorder_level' => (int)$_POST['reorder_level'],
            'description' => trim($_POST['description'] ?? '')
        ];

        if (!$id && $this->productModel->existsByCode($data['item_code'])) {
            Flash::set('error', 'Product code already exists!');
            $this->redirect('/services_products');
            return;
        }

        $success = $this->productModel->createOrUpdate($id, $data);
        Flash::set('success', $success ? ($id ? 'Product updated' : 'Product added') : 'Operation failed');
        $this->redirect('/services_products');
    }

    public function delete() {
        $id = $_POST['product_id'] ?? null;
        if ($id && $this->productModel->softDelete($id)) {
            Flash::set('success', 'Product deactivated successfully.');
        } else {
            Flash::set('error', 'Deactivation failed.');
        }
        $this->redirect('/services_products');
    }

    public function view($id) {
        $product = $this->productModel->find($id);
        if (!$product) {
            Flash::set('error', 'Product not found.');
            $this->redirect('/services_products');
        }
        $this->render('products/view', ['product' => $product]);
    }
}