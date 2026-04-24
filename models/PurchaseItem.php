<?php
namespace App\Models;

use Core\Model;

class PurchaseItem extends Model {
    protected $table = 'purchase_items';
    protected $fillable = [
        'purchase_id', 'product_id', 'item_code', 'product_name',
        'quantity', 'unit_price', 'discount', 'total'
    ];
}