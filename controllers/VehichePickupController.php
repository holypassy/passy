<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Models\VehiclePickupReminder;
use App\Models\Customer;
use App\Models\User;
use App\Models\ReminderHistory; // optional, you can create

class VehiclePickupController extends Controller
{
    public function __construct()
    {
        // Ensure user is logged in
        if (!Session::get('logged_in')) {
            $this->redirect('index.php?url=auth/login');
        }

        // Ensure tables exist
        VehiclePickupReminder::createTable();
        // ReminderHistory::createTable(); // create if needed
    }

    public function index()
    {
        $filters = [
            'status' => $_GET['status'] ?? 'pending',
            'search' => $_GET['search'] ?? '',
            'pickup_type' => $_GET['pickup_type'] ?? ''
        ];

        $reminders = VehiclePickupReminder::getReminders($filters);
        $stats = VehiclePickupReminder::getStats();
        $customers = Customer::getAllActive();
        $staff = User::getStaff();

        $success = Session::flash('success');
        $error = Session::flash('error');

        $this->view('vehicle_pickup.index', [
            'reminders' => $reminders,
            'stats' => $stats,
            'customers' => $customers,
            'staff' => $staff,
            'filters' => $filters,
            'success' => $success,
            'error' => $error
        ]);
    }

    public function add()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('index.php?url=vehicle_pickup');
            return;
        }

        $data = [
            'customer_id' => $_POST['customer_id'],
            'job_card_id' => !empty($_POST['job_card_id']) ? $_POST['job_card_id'] : null,
            'vehicle_reg' => $_POST['vehicle_reg'],
            'vehicle_make' => $_POST['vehicle_make'] ?? null,
            'vehicle_model' => $_POST['vehicle_model'] ?? null,
            'pickup_type' => $_POST['pickup_type'],
            'pickup_address' => $_POST['pickup_address'] ?? null,
            'pickup_location_details' => $_POST['pickup_location_details'] ?? null,
            'pickup_date' => $_POST['pickup_date'],
            'pickup_time' => $_POST['pickup_time'] ?? null,
            'reminder_date' => $_POST['reminder_date'],
            'reminder_time' => $_POST['reminder_time'] ?? null,
            'reminder_type' => $_POST['reminder_type'],
            'notes' => $_POST['notes'] ?? null,
            'assigned_to' => !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
            'created_by' => Session::get('user_id', 1)
        ];

        try {
            VehiclePickupReminder::insert(VehiclePickupReminder::$table, $data);
            Session::set('_flash', ['success' => 'Pickup reminder created successfully!']);
        } catch (\Exception $e) {
            Session::set('_flash', ['error' => 'Error creating reminder: ' . $e->getMessage()]);
        }

        $this->redirect('index.php?url=vehicle_pickup');
    }

    public function sendReminder()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['reminder_id'])) {
            $this->redirect('index.php?url=vehicle_pickup');
            return;
        }

        $id = $_POST['reminder_id'];
        $reminder = VehiclePickupReminder::fetchOne("
            SELECT vpr.*, c.full_name, c.telephone, c.email, c.address as customer_address
            FROM vehicle_pickup_reminders vpr
            LEFT JOIN customers c ON vpr.customer_id = c.id
            WHERE vpr.id = ?
        ", [$id]);

        if ($reminder) {
            $message = "Dear " . $reminder['full_name'] . ",\n\n";
            $message .= "Vehicle Pickup Details:\n";
            $message .= "Vehicle: " . $reminder['vehicle_reg'] . " (" . $reminder['vehicle_make'] . " " . $reminder['vehicle_model'] . ")\n";

            if ($reminder['pickup_type'] == 'workshop') {
                $message .= "Pickup Location: Our Workshop\n";
                $message .= "Address: Savant Motors Workshop, Kampala, Uganda\n";
            } else {
                $message .= "Pickup Type: " . ucfirst($reminder['pickup_type']) . " Pickup\n";
                $message .= "Pickup Address: " . $reminder['pickup_address'] . "\n";
                if ($reminder['pickup_location_details']) {
                    $message .= "Location Details: " . $reminder['pickup_location_details'] . "\n";
                }
            }

            $message .= "Pickup Date: " . date('l, F j, Y', strtotime($reminder['pickup_date']));
            if ($reminder['pickup_time']) {
                $message .= " at " . date('h:i A', strtotime($reminder['pickup_time']));
            }
            $message .= "\n\nPlease ensure vehicle is accessible at the specified location.\n\nThank you for choosing Savant Motors!";

            // Log history (implement ReminderHistory model)
            // ...

            // Mark as sent
            VehiclePickupReminder::markSent($id);

            Session::set('_flash', ['success' => "Reminder sent successfully to " . $reminder['full_name']]);
        } else {
            Session::set('_flash', ['error' => "Reminder not found."]);
        }

        $this->redirect('index.php?url=vehicle_pickup');
    }

    public function startPickup()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['reminder_id'])) {
            $this->redirect('index.php?url=vehicle_pickup');
            return;
        }

        VehiclePickupReminder::updateStatus($_POST['reminder_id'], 'in_progress');
        Session::set('_flash', ['success' => "Pickup marked as in progress!"]);
        $this->redirect('index.php?url=vehicle_pickup');
    }

    public function completePickup()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['reminder_id'])) {
            $this->redirect('index.php?url=vehicle_pickup');
            return;
        }

        VehiclePickupReminder::updateStatus($_POST['reminder_id'], 'completed');
        Session::set('_flash', ['success' => "Pickup marked as completed!"]);
        $this->redirect('index.php?url=vehicle_pickup');
    }

    public function cancelReminder()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['reminder_id'])) {
            $this->redirect('index.php?url=vehicle_pickup');
            return;
        }

        VehiclePickupReminder::updateStatus($_POST['reminder_id'], 'cancelled');
        Session::set('_flash', ['success' => "Reminder cancelled!"]);
        $this->redirect('index.php?url=vehicle_pickup');
    }
}