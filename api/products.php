<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../helpers/ImageUploader.php';

$product = new Product();
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = isset($_GET['path']) ? explode('/', trim($_GET['path'], '/')) : [];

// Parse request body for PUT requests
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        if (isset($path[0]) && $path[0] === 'stats') {
            // Get inventory statistics
            $stats = $product->getInventoryStats();
            echo json_encode([
                'success' => true,
                'inventory' => $stats
            ]);
        } 
        elseif (isset($path[0]) && $path[0] === 'low-stock') {
            // Get low stock products
            $lowStock = $product->getLowStockProducts();
            echo json_encode([
                'success' => true,
                'data' => $lowStock,
                'count' => count($lowStock)
            ]);
        }
        elseif (isset($path[0]) && $path[0] === 'category' && isset($path[1])) {
            // Get products by category
            $category = urldecode($path[1]);
            $products = $product->getByCategory($category);
            echo json_encode([
                'success' => true,
                'data' => $products
            ]);
        }
        elseif (isset($path[0]) && $path[0] === 'barcode' && isset($path[1])) {
            // Get product by barcode
            $barcode = $path[1];
            $productData = $product->getByBarcode($barcode);
            echo json_encode([
                'success' => true,
                'data' => $productData
            ]);
        }
        elseif (isset($path[0]) && is_numeric($path[0])) {
            // Get single product
            $product->id = intval($path[0]);
            if($product->readOne()) {
                // Get product images
                $images = getProductImages($product->id);
                echo json_encode([
                    'success' => true,
                    'id' => $product->id,
                    'item_code' => $product->item_code,
                    'product_name' => $product->product_name,
                    'category' => $product->category,
                    'unit_of_measure' => $product->unit_of_measure,
                    'unit_cost' => $product->unit_cost,
                    'selling_price' => $product->selling_price,
                    'opening_stock' => $product->opening_stock,
                    'quantity' => $product->quantity,
                    'reorder_level' => $product->reorder_level,
                    'min_stock' => $product->min_stock,
                    'barcode' => $product->barcode,
                    'supplier_sku' => $product->supplier_sku,
                    'warehouse_location' => $product->warehouse_location,
                    'bin_location' => $product->bin_location,
                    'brand' => $product->brand,
                    'warranty_period' => $product->warranty_period,
                    'description' => $product->description,
                    'notes' => $product->notes,
                    'is_active' => $product->is_active,
                    'track_inventory' => $product->track_inventory,
                    'is_featured' => $product->is_featured,
                    'allow_backorder' => $product->allow_backorder,
                    'tax_rate' => $product->tax_rate,
                    'tax_inclusive' => $product->tax_inclusive,
                    'weight' => $product->weight,
                    'length' => $product->length,
                    'width' => $product->width,
                    'height' => $product->height,
                    'images' => $images,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
        }
        else {
            // Get all products with pagination
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $category = isset($_GET['category']) ? $_GET['category'] : '';
            $offset = ($page - 1) * $limit;
            
            $products = $product->readPaginated($limit, $offset, $search, $category);
            $total = $product->getTotalCount($search, $category);
            
            echo json_encode([
                'success' => true,
                'data' => $products,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ]
            ]);
        }
        break;
        
    case 'POST':
        // Check if it's a draft save
        if (isset($path[0]) && $path[0] === 'draft') {
            $product->product_name = $_POST['product_name'] ?? '';
            $product->item_code = $_POST['item_code'] ?? '';
            $product->selling_price = $_POST['selling_price'] ?? 0;
            $product->is_draft = 1;
            
            if($product->saveDraft()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Product saved as draft',
                    'product_id' => $product->id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to save draft']);
            }
        }
        elseif (isset($path[0]) && $path[0] === 'stock' && isset($path[1])) {
            // Update stock
            $product_id = intval($path[1]);
            $quantity_change = $input['quantity'] ?? 0;
            
            if($product->updateStock($product_id, $quantity_change)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Stock updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to update stock']);
            }
        }
        else {
            // Create new product
            $product->item_code = $_POST['item_code'] ?? '';
            $product->product_name = $_POST['product_name'] ?? '';
            $product->category = $_POST['category'] ?? '';
            $product->unit_of_measure = $_POST['unit_of_measure'] ?? 'piece';
            $product->unit_cost = $_POST['unit_cost'] ?? 0;
            $product->selling_price = $_POST['selling_price'] ?? 0;
            $product->opening_stock = $_POST['opening_stock'] ?? 0;
            $product->quantity = $product->opening_stock;
            $product->reorder_level = $_POST['reorder_level'] ?? 5;
            $product->min_stock = $_POST['min_stock'] ?? 2;
            $product->barcode = $_POST['barcode'] ?? '';
            $product->supplier_sku = $_POST['supplier_sku'] ?? '';
            $product->warehouse_location = $_POST['warehouse_location'] ?? '';
            $product->bin_location = $_POST['bin_location'] ?? '';
            $product->brand = $_POST['brand'] ?? '';
            $product->warranty_period = $_POST['warranty_period'] ?? 0;
            $product->description = $_POST['description'] ?? '';
            $product->notes = $_POST['notes'] ?? '';
            $product->is_active = isset($_POST['is_active']) ? $_POST['is_active'] : 1;
            $product->track_inventory = isset($_POST['track_inventory']) ? $_POST['track_inventory'] : 1;
            $product->is_featured = isset($_POST['is_featured']) ? $_POST['is_featured'] : 0;
            $product->allow_backorder = isset($_POST['allow_backorder']) ? $_POST['allow_backorder'] : 0;
            $product->tax_rate = $_POST['tax_rate'] ?? 18;
            $product->tax_inclusive = $_POST['tax_inclusive'] ?? 1;
            $product->weight = $_POST['weight'] ?? 0;
            $product->length = $_POST['length'] ?? 0;
            $product->width = $_POST['width'] ?? 0;
            $product->height = $_POST['height'] ?? 0;
            $product->is_draft = 0;
            
            // Validate required fields
            if(empty($product->product_name) || empty($product->selling_price)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Product name and selling price are required']);
                break;
            }
            
            if($product->create()) {
                // Handle image uploads
                if(isset($_FILES['images'])) {
                    uploadProductImages($product->id, $_FILES['images']);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Product created successfully',
                    'product_id' => $product->id
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to create product']);
            }
        }
        break;
        
    case 'PUT':
        if (isset($path[0]) && is_numeric($path[0])) {
            // Update existing product
            $product->id = intval($path[0]);
            
            // Get existing product data first
            if(!$product->readOne()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                break;
            }
            
            // Update with new data (from JSON input)
            $product->item_code = $input['item_code'] ?? $product->item_code;
            $product->product_name = $input['product_name'] ?? $product->product_name;
            $product->category = $input['category'] ?? $product->category;
            $product->unit_of_measure = $input['unit_of_measure'] ?? $product->unit_of_measure;
            $product->unit_cost = $input['unit_cost'] ?? $product->unit_cost;
            $product->selling_price = $input['selling_price'] ?? $product->selling_price;
            $product->reorder_level = $input['reorder_level'] ?? $product->reorder_level;
            $product->min_stock = $input['min_stock'] ?? $product->min_stock;
            $product->barcode = $input['barcode'] ?? $product->barcode;
            $product->supplier_sku = $input['supplier_sku'] ?? $product->supplier_sku;
            $product->warehouse_location = $input['warehouse_location'] ?? $product->warehouse_location;
            $product->bin_location = $input['bin_location'] ?? $product->bin_location;
            $product->brand = $input['brand'] ?? $product->brand;
            $product->warranty_period = $input['warranty_period'] ?? $product->warranty_period;
            $product->description = $input['description'] ?? $product->description;
            $product->notes = $input['notes'] ?? $product->notes;
            $product->is_active = $input['is_active'] ?? $product->is_active;
            $product->track_inventory = $input['track_inventory'] ?? $product->track_inventory;
            $product->is_featured = $input['is_featured'] ?? $product->is_featured;
            $product->allow_backorder = $input['allow_backorder'] ?? $product->allow_backorder;
            $product->tax_rate = $input['tax_rate'] ?? $product->tax_rate;
            $product->tax_inclusive = $input['tax_inclusive'] ?? $product->tax_inclusive;
            $product->weight = $input['weight'] ?? $product->weight;
            $product->length = $input['length'] ?? $product->length;
            $product->width = $input['width'] ?? $product->width;
            $product->height = $input['height'] ?? $product->height;
            
            if($product->update()) {
                // Handle image deletions
                if(isset($input['delete_images'])) {
                    deleteProductImages($product->id, $input['delete_images']);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Product updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to update product']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        }
        break;
        
    case 'DELETE':
        if (isset($path[0]) && is_numeric($path[0])) {
            $product->id = intval($path[0]);
            
            if($product->delete()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Product deleted successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to delete product']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

// Helper functions for image handling
function getProductImages($product_id) {
    $conn = (new Database())->getConnection();
    $query = "SELECT id, image_url, is_primary FROM product_images WHERE product_id = :product_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(":product_id", $product_id);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function uploadProductImages($product_id, $files) {
    $upload_dir = __DIR__ . '/../uploads/products/';
    if(!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $conn = (new Database())->getConnection();
    
    foreach($files['tmp_name'] as $key => $tmp_name) {
        if($files['error'][$key] === UPLOAD_ERR_OK) {
            $file_name = time() . '_' . uniqid() . '_' . basename($files['name'][$key]);
            $target_file = $upload_dir . $file_name;
            
            if(move_uploaded_file($tmp_name, $target_file)) {
                $is_primary = ($key === 0) ? 1 : 0;
                $query = "INSERT INTO product_images (product_id, image_url, is_primary) 
                          VALUES (:product_id, :image_url, :is_primary)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(":product_id", $product_id);
                $stmt->bindParam(":image_url", $file_name);
                $stmt->bindParam(":is_primary", $is_primary);
                $stmt->execute();
            }
        }
    }
}

function deleteProductImages($product_id, $image_ids) {
    $conn = (new Database())->getConnection();
    $upload_dir = __DIR__ . '/../uploads/products/';
    
    foreach($image_ids as $image_id) {
        // Get image URL first
        $query = "SELECT image_url FROM product_images WHERE id = :id AND product_id = :product_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(":id", $image_id);
        $stmt->bindParam(":product_id", $product_id);
        $stmt->execute();
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($image) {
            // Delete file from server
            $file_path = $upload_dir . $image['image_url'];
            if(file_exists($file_path)) {
                unlink($file_path);
            }
            
            // Delete database record
            $query = "DELETE FROM product_images WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(":id", $image_id);
            $stmt->execute();
        }
    }
}
?>