# MMTracker Delivery Management System

A comprehensive delivery management system for tracking and managing deliveries, riders, manifests, and orders.

## Overview

MMTracker is a web-based delivery management platform that helps businesses manage their delivery operations efficiently. The system provides features for order tracking, rider management, manifest creation, real-time location tracking, and more.

## Features

- **User Management**: Multiple user roles (Super Admin, Admin, Rider)
- **Company Management**: Multi-company support with individual settings
- **Order Tracking**: Complete lifecycle management for delivery orders
- **Manifest System**: Group multiple orders into delivery manifests
- **Rider Management**: Assign and track delivery personnel
- **Real-time Location**: Track riders' locations in real-time
- **Firebase Integration**: Push notifications for updates
- **API Support**: REST API for integration with external systems
- **Warehouse Management**: Support for multiple warehouses
- **Product Tracking**: Inventory and delivery tracking

## Technology Stack

- **Backend**: PHP
- **Database**: MySQL
- **Frontend**: HTML, Tailwind CSS, JavaScript
- **Push Notifications**: Firebase Cloud Messaging
- **Real-time Communication**: WebSockets (Ratchet)

## Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher
- Composer for dependency management
- XAMPP, WAMP, LAMP, or other web server stack

## Installation

1. Clone the repository to your web server directory
   ```
   git clone https://github.com/your-username/mmtracker_delivery_admin_pannel.git
   ```

2. Navigate to the project directory
   ```
   cd mmtracker_delivery_admin_pannel
   ```

3. Install dependencies using Composer
   ```
   composer install
   ```

4. Import the database schema
   ```
   mysql -u username -p database_name < delivery_app_db.sql
   ```

5. Configure the database connection in `includes/config.php`
   ```php
   $db_host = 'localhost';
   $db_user = 'your_username';
   $db_pass = 'your_password';
   $db_name = 'delivery_app_db';
   ```

6. Configure Firebase credentials for push notifications

7. Create a Super Admin user by accessing the script with the secret key
   ```
   http://your-domain.com/create_super_admin.php?key=thisismysecurekey983u*#&@HC*&H#C*&H#sdkjvb2c9ejhvc92u3env39uehv38urebv&@#*&@@#3er4uyvb
   ```
   
   This will create a default Super Admin with the following credentials:
   - Username: mnaveedpaki
   - Password: Mnaveed@coM25867##
   
   **Important**: For security reasons, change these credentials immediately after your first login and modify the secret key in the script.

## Directory Structure

- `/api`: REST API endpoints
- `/assets`: Static assets (CSS, JavaScript, images)
- `/db`: Database-related files
- `/includes`: Common PHP includes and configuration
- `/pages`: Web UI pages
- `/server`: WebSocket server for real-time updates (Not using anymore)
- `/vendor`: Composer dependencies

## API Documentation

The system includes a REST API for integration with external systems and mobile applications:

- **Rider API**: Authentication, order updates, location updates
- **Admin API**: Order creation, manifest management, rider assignment

API authentication is handled via API keys that can be generated in the admin panel.

### API Endpoints

#### Rider API Endpoints
- **POST /api/rider/login.php** - Authenticates riders and provides access token
- **POST /api/rider/logout.php** - Logs out rider and invalidates token
- **GET /api/rider/manifests.php** - Retrieves rider's assigned manifests and orders
- **POST /api/rider/update_manifest_status.php** - Updates status of a manifest (assigned, delivering, delivered)
- **POST /api/rider/update_order_status.php** - Updates status of an order (pending, delivering, delivered, failed)
- **POST /api/rider/update_fcm.php** - Updates rider's Firebase Cloud Messaging token for notifications
- **POST /api/rider/update_profile.php** - Updates rider's profile information
- **POST /api/rider/update_product_quantities.php** - Updates product quantities during delivery
- **POST /api/rider/update_product_tracking.php** - Records product tracking information
- **POST /api/rider/verify_admin_pin.php** - Verifies admin PIN for sensitive operations
- **POST /api/rider/request_password_reset.php** - Requests password reset token
- **POST /api/rider/reset_password.php** - Resets rider's password using valid token

#### Admin API Endpoints
- **POST /api/admin/create_order.php** - Creates a new delivery order with products
- **GET /api/get_rider_info.php** - Gets information about a specific rider

#### General API Endpoints
- **POST /api/[endpoint]** - All API endpoints accept authentication via API key or JWT token
- **GET /api/[endpoint]** - Endpoints support proper error handling with HTTP status codes

### API Authentication
API requests must include authentication using one of the following methods:
- API Key: Include `X-API-KEY` header with valid API key generated from admin panel
- JWT Token: Include `Authorization: Bearer [token]` header for rider authentication

### Sample Request
```http
POST /api/admin/create_order.php HTTP/1.1
Host: mmtracker.com
X-API-KEY: your_api_key_here
Content-Type: application/json

{
  "customer_name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890",
  "address_line1": "123 Main Street",
  "city": "New York",
  "postal_code": "10001",
  "products": [
    {"product_id": 1, "quantity": 2},
    {"product_id": 3, "quantity": 1}
  ]
}
```

### Sample Response
```json
{
  "status": "success",
  "message": "Order created successfully",
  "data": {
    "order_id": 123,
    "order_number": "ORD-20250131-C28A"
  }
}
```

## Security Notes

1. The `create_super_admin.php` script should be removed or secured after creating the initial Super Admin account.
2. The default credentials in the installation script should be changed immediately after setup.

