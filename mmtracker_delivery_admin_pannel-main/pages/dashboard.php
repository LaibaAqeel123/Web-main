<?php
require_once '../includes/config.php';

requireLogin();

// --- helpers/filters --- FIXED
$company_id = (!isSuperAdmin() && !empty($_SESSION['company_id']))
  ? intval($_SESSION['company_id'])
  : null;

// Recent orders (left column, below Riders) - Updated to show assignment status
$recent_orders_sql = "SELECT 
    o.id, o.order_number, o.status, o.created_at,
    CASE WHEN o.customer_id > 0 THEN c.name ELSE 'N/A' END AS customer_name,
    c.phone AS customer_phone,
    o.latitude, o.longitude,
    m.id as manifest_id, u.name as assigned_rider_name
FROM Orders o
LEFT JOIN Customers c ON o.customer_id = c.id
LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
LEFT JOIN Manifests m ON mo.manifest_id = m.id
LEFT JOIN Users u ON m.rider_id = u.id
WHERE o.status = 'pending'";

if ($company_id) {
  $recent_orders_sql .= " AND o.company_id = $company_id";
}

$recent_orders_sql .= " ORDER BY o.created_at DESC LIMIT 30";

$recent_orders_result = mysqli_query($conn, $recent_orders_sql);

if (!$recent_orders_result) {
  die("Query Failed: " . mysqli_error($conn));
}

// Route Orders - shows orders for selected route or all orders when no route selected
$route_orders_sql = "SELECT 
    o.id, o.order_number, o.status, o.created_at,
    c.name AS customer_name,
    c.phone AS customer_phone,
    o.latitude, o.longitude,
    m.id as manifest_id, 
    u.name as assigned_rider_name,
    CASE WHEN m.id IS NOT NULL THEN 'assigned' ELSE 'unassigned' END as assignment_status
FROM Orders o
LEFT JOIN Customers c ON o.customer_id = c.id
LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
LEFT JOIN Manifests m ON mo.manifest_id = m.id
LEFT JOIN Users u ON m.rider_id = u.id
WHERE 1=1";

if ($company_id) {
  $route_orders_sql .= " AND o.company_id = $company_id";
}

$route_orders_sql .= " ORDER BY o.created_at DESC LIMIT 50";

$route_orders_result = mysqli_query($conn, $route_orders_sql);


// Riders list with order count - FIXED
$riders_sql = "SELECT DISTINCT u.id, u.name, u.phone,
    COUNT(mo.id) as current_orders
  FROM Users u
  LEFT JOIN RiderCompanies rc ON rc.rider_id = u.id
  LEFT JOIN Manifests m ON u.id = m.rider_id AND m.status IN ('pending', 'assigned', 'delivering')
  LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
  WHERE u.user_type = 'Rider'";

if ($company_id) {
  $riders_sql .= " AND (u.company_id = $company_id OR rc.company_id = $company_id)";
}

$riders_sql .= " GROUP BY u.id ORDER BY u.name";
$riders_result = mysqli_query($conn, $riders_sql);

// Routes / manifests list with order count - FIXED AMBIGUOUS COLUMN
$routes_sql = "SELECT m.id, m.status, m.created_at, m.rider_id, u.name as rider_name,
    COUNT(mo.id) as total_orders
  FROM Manifests m
  LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
  LEFT JOIN Users u ON m.rider_id = u.id " .
  ($company_id ? "WHERE m.company_id = $company_id " : "") . "
  GROUP BY m.id
  ORDER BY m.created_at DESC LIMIT 20";
$routes_result = mysqli_query($conn, $routes_sql);

// Riders latest locations (for Map) - FIXED AMBIGUOUS COLUMN
$riders_location_query = "
  SELECT DISTINCT rl.*, u.name AS rider_name
  FROM RidersLocations rl
  INNER JOIN (
    SELECT rider_id, MAX(created_at) AS latest_location
    FROM RidersLocations
    GROUP BY rider_id
  ) latest ON rl.rider_id = latest.rider_id AND rl.created_at = latest.latest_location
  INNER JOIN Users u ON rl.rider_id = u.id
  LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
  WHERE u.user_type = 'Rider'";

if ($company_id) {
  $riders_location_query .= " AND (u.company_id = $company_id OR rc.company_id = $company_id)";
}

$riders_location_result = mysqli_query($conn, $riders_location_query);
$riders_locations = [];
while ($row = mysqli_fetch_assoc($riders_location_result)) {
  $riders_locations[] = $row;
}
$user_id = intval($_SESSION['user_id']);
$preferences_sql = "SELECT panel_id, width, height, grid_columns 
                    FROM user_dashboard_preferences 
                    WHERE user_id = $user_id";
$preferences_result = mysqli_query($conn, $preferences_sql);

$user_preferences = [];
if ($preferences_result) {
  while ($pref = mysqli_fetch_assoc($preferences_result)) {
    $user_preferences[$pref['panel_id']] = [
      'width' => $pref['width'],
      'height' => $pref['height'],
      'grid_columns' => $pref['grid_columns']
    ];
  }
}

// Convert to JSON for JavaScript
$user_preferences_json = json_encode($user_preferences);
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard - <?php echo SITE_NAME; ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    .app-shell {
      display: flex;
      min-height: 100vh;
      background: #f3f4f6;
    }

    .sidebar {
      width: 72px;
      background: #0b1220;
      color: #fff;
      transition: width .18s ease;
    }

    .sidebar.expanded {
      width: 220px;
    }

    .sidebar .brand {
      padding: 14px;
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .sidebar .nav a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      color: #cbd5e1;
      text-decoration: none;
      border-radius: 8px;
    }

    .sidebar .nav a:hover {
      background: rgba(255, 255, 255, 0.03);
      color: #fff;
    }

    .sidebar .label {
      display: none;
    }

    .sidebar.expanded .label {
      display: inline-block;
    }

    .logo-container {
      display: none;
    }

    .sidebar.expanded .logo-container {
      display: flex;
    }

    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    .topbar {
      background: #0b1724;
      color: #fff;
      padding: 8px 16px;
      /* Reduced from 12px 20px */
      display: flex;
      justify-content: space-between;
      align-items: center;
      height: 48px;
      /* Fixed height, reduced from ~60px */
    }

    .content {
      padding: 6px;
      /* Reduced from 12px */
      flex: 1;
      overflow: auto;
      height: calc(100vh - 48px);
      /* Adjusted for new topbar height */
    }

    .grid-wrap {
      display: grid;
      gap: 0px;
      /* CHANGE FROM 4px TO 2px */
      grid-template-columns: 1fr 1fr;
      align-items: start;
      position: relative;
      height: 100%;
      max-height: calc(100vh - 54px);
    }


    @media (max-width:1100px) {
      .grid-wrap {
        grid-template-columns: 1fr;
      }
    }

    .panel {
      background: white;
      border-radius: 6px;
      padding: 4px;
      /* CHANGE FROM 6px TO 4px */
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
      position: relative;
      min-height: 120px;
      /* CHANGE FROM 140px TO 120px */
      max-height: 100%;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    /* Resizable panels */
    .resizable-panel {
      overflow: auto;
      min-width: 250px;
      min-height: 140px;
      /* Reduced from 200px */
      max-width: 100%;
      border: 1px solid #e5e7eb;
    }

    /* Make sure the main grid items don't stretch */
    .grid-wrap>div {
      height: fit-content;
    }

    /* Resize handle styling */
    .resize-handle {
      position: absolute;
      background: #6b7280;
      opacity: 0;
      transition: opacity 0.2s ease;
      z-index: 100;

    }

    .drivers-list,
    .orders-list,
    .routes-list {
      overflow-y: auto;
      
      flex: 1;
    }

    .resize-handle:hover,
    .panel:hover .resize-handle {
      opacity: 0.5;
    }

    .resize-handle:active {
      opacity: 0.8;
    }

    /* Horizontal resize handle */
    .resize-handle-h {
      right: 0;
      top: 0;
      bottom: 0;
      width: 4px;
      cursor: ew-resize;
    }

    /* Vertical resize handle */
    .resize-handle-v {
      left: 0;
      right: 0;
      bottom: 0;
      height: 4px;
      cursor: ns-resize;

    }

    .resize-handle-v,
    .resize-handle-corner {
      will-change: transform;
      pointer-events: auto;
      transition: none !important;
    }

    /* Corner resize handle */
    .resize-handle-corner {
      right: 0;
      bottom: 0;
      width: 12px;
      height: 12px;
      cursor: nw-resize;
      background: linear-gradient(-45deg, transparent 30%, #6b7280 30%, #6b7280 70%, transparent 70%);
    }

    /* Column resizer */
    .column-resizer {
      position: absolute;
      top: 0;
      bottom: 0;
      width: 8px;
      background: transparent;
      cursor: col-resize;
      z-index: 10;
      right: -4px;
    }

    .column-resizer:hover {
      background: rgba(59, 130, 246, 0.3);
    }

    .column-resizer.dragging {
      background: rgba(59, 130, 246, 0.5);
    }

    .left-column {
      position: relative;
      height: 100%;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0px;
      /* CHANGE FROM 4px TO 2px */

    }

    .right-column {
      height: 100%;
      display: flex;
      flex-direction: column;
      gap: 0px;
      /* CHANGE FROM 4px TO 2px */
    }


    /* Compact table styling */
    .panel table {
      width: 100%;
      font-size: 13px;
      /* Reduced from default */
    }

    .panel table thead th {
      padding: 3px 6px;
      /* CHANGE FROM 4px 8px TO 3px 6px */
      font-size: 11px;
      font-weight: 600;
    }

    .panel table tbody td {
      padding: 2px 6px;
      /* CHANGE FROM 3px 8px TO 2px 6px */
      line-height: 1.2;
    }


    .panel table tbody tr {
      height: auto;
      /* Let content determine height */
    }

    /* Compact section headers */
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
      /* CHANGE FROM 6px TO 4px */
    }

    .section-header h2 {
      font-size: 16px;
      /* Reduced from 18px (text-lg) */
      font-weight: 600;
      margin: 0;
    }

    .workflow-indicator {
      font-size: 10px;
      color: #6B7280;
      margin-top: 1px;
      /* CHANGE FROM 2px TO 1px */
      margin-bottom: 2px;
      /* CHANGE FROM 4px TO 2px */
      font-style: italic;
      line-height: 1.2;
    }

    .workflow-step {
      font-size: 9px;
      /* Reduced from 10px */
      color: #6B7280;
      background: #F3F4F6;
      padding: 2px 4px;
      /* Reduced from 2px 6px */
      border-radius: 3px;
      /* Reduced from 4px */
      font-weight: 500;
    }

    /* Compact status pills */
    .status-pill {
      font-weight: 700;
      font-size: 10px;
      /* Reduced from 12px */
      padding: 2px 6px;
      /* Reduced from 4px 8px */
      border-radius: 999px;
    }

    .status-delivered {
      background: #ECFDF5;
      color: #065F46;
    }

    .status-pending {
      background: #FFFBEB;
      color: #92400E;
    }

    .status-assigned {
      background: #EFF6FF;
      color: #1E3A8A;
    }

    .status-failed {
      background: #FEF2F2;
      color: #991B1B;
    }

    /* DRAG AND DROP STYLES FOR TABLE ROWS */
    .draggable-row {
      cursor: grab;
      transition: all 0.2s ease;
      user-select: none;
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
    }

    .draggable-row:hover {
      background: #f8fafc !important;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .draggable-row.dragging {
      opacity: 0.6;
      transform: scale(0.98) rotate(1deg);
      cursor: grabbing;
      background: #e0f2fe !important;
    }

    /* Drop zone styles for table rows */
    .drop-zone {
      position: relative;
      transition: all 0.2s ease;
    }

    .drop-zone.drag-over-order {
      background: #eff6ff !important;
      border-left: 4px solid #3b82f6;
      transform: scale(1.02);
      box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
    }

    .drop-zone.drag-over-route {
      background: #f0fdf4 !important;
      border-left: 4px solid #10b981;
      transform: scale(1.02);
      box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
    }

    .drop-zone.drag-over-order::after {
      content: "Drop order here";
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: rgba(59, 130, 246, 0.9);
      color: white;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: bold;
      z-index: 10;
      pointer-events: none;
    }

    .drop-zone.drag-over-route::after {
      content: "Drop route here";
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: rgba(16, 185, 129, 0.9);
      color: white;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: bold;
      z-index: 10;
      pointer-events: none;
    }

    /* Assigned status indicators */
    .assigned-indicator {
      display: inline-block;
      font-size: 9px;
      /* Reduced from 10px */
      color: #10b981;
      font-weight: 600;
      background: rgba(16, 185, 129, 0.1);
      padding: 1px 3px;
      /* Reduced from 2px 4px */
      border-radius: 3px;
      margin-left: 4px;
    }

    /* Prevent text selection during drag */
    .dragging-active {
      user-select: none !important;
      -webkit-user-select: none !important;
      -moz-user-select: none !important;
      -ms-user-select: none !important;
    }

    .panel.resizing {
      user-select: none;
      pointer-events: none;
    }

    .panel.resizing .resize-handle {
      pointer-events: auto;
    }

    #map {
      height: 100% !important;
      /* Force height */
      min-height: 250px !important;
      /* Ensure minimum */
      width: 100%;
      border-radius: 6px;
      position: relative;
      /* Add this */
    }

    /* Loading spinner */
    .loading-spinner {
      display: none;
      width: 14px;
      /* Reduced from 16px */
      height: 14px;
      border: 2px solid #f3f3f3;
      border-top: 2px solid #10B981;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-left: 6px;
      /* Reduced from 8px */
    }

    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    /* Success/error messages */
    .message {
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 12px 16px;
      border-radius: 8px;
      color: white;
      font-weight: 600;
      z-index: 1000;
      opacity: 0;
      transform: translateY(-20px);
      transition: all 0.3s ease;
    }

    .message.success {
      background: #10B981;
    }

    .message.error {
      background: #EF4444;
    }

    .message.show {
      opacity: 1;
      transform: translateY(0);
    }

    /* Route selection styles */
    .route-selected {
      background: #dbeafe !important;
      border-left: 4px solid #2563eb !important;
    }

    /* Route order row styles */
    .route-order-row {
      cursor: default;
    }

    .route-order-row[data-assignment-status="assigned"] {
      background-color: #f0f9ff;
    }

    .route-order-row[data-assignment-status="unassigned"] {
      background-color: #fffbeb;
    }

    /* Compact link styling */
    .panel a {
      font-size: 12px;
      /* Reduced from 14px (text-sm) */
    }

    /* Default heights for panels (first login) */
    #driversPanel {
      height: 180px;
    }

    #routeOrdersPanel {
      height: 200px;
    }

    #ordersPanel {
      height: 190px;
    }

    #mapPanel {
      height: 310px;
    }

    #routesPanel {
      height: 275px;
    }
  </style>
</head>

<body>

  <div class="app-shell">
    <!-- SIDEBAR  -->
    <aside id="sidebar" class="sidebar" onmouseenter="sidebarHover(true)" onmouseleave="sidebarHover(false)">
      <div class="brand">
        <button id="sidebarToggle" onclick="toggleSidebar()" class="text-white" style="background:none;border:none;">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18M3 6h18M3 18h18" />
          </svg>
        </button>
      </div>

      <nav class="nav px-2">
        <div class="flex items-center justify-center py-3 border-b border-gray-700 logo-container">
          <a href="<?php echo SITE_URL; ?>" class="p-2 hover:opacity-80 transition-opacity"></a>
        </div>

        <div class="mt-4">
          <a href="<?php echo SITE_URL; ?>pages/dashboard.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'dashboard.php') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
              </path>
            </svg>
            <span class="label truncate">Dashboard</span>
          </a>

          <?php if (isSuperAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>pages/companies/index.php"
              class="<?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                </path>
              </svg>
              <span class="label truncate">Companies</span>
            </a>
          <?php endif; ?>

          <?php if (isSuperAdmin() || isAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>pages/products/index.php"
              class="<?php echo (strpos($_SERVER['PHP_SELF'], 'products') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
              </svg>
              <span class="label truncate">Products</span>
            </a>
          <?php endif; ?>

          <?php if (isSuperAdmin() || isAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>pages/warehouses/index.php"
              class="<?php echo (strpos($_SERVER['PHP_SELF'], 'warehouses') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
              </svg>
              <span class="label truncate">Warehouses</span>
            </a>
          <?php endif; ?>

          <a href="<?php echo SITE_URL; ?>pages/orders/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'orders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
            </svg>
            <span class="label truncate">Orders</span>
          </a>

          <?php if (isSuperAdmin() || isAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>pages/organizations/index.php"
              class="<?php echo (strpos($_SERVER['PHP_SELF'], 'organizations') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                </path>
              </svg>
              <span class="label truncate">Organizations</span>
            </a>
          <?php endif; ?>



          <a href="<?php echo SITE_URL; ?>pages/manifests/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'routes') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01">
              </path>
            </svg>
            <span class="label truncate">Routes</span>
          </a>

          <?php if (isSuperAdmin() || isAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>pages/riders/index.php"
              class="<?php echo (strpos($_SERVER['PHP_SELF'], 'drivers') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                </path>
              </svg>
              <span class="label truncate">Drivers</span>
            </a>
          <?php endif; ?>

          <a href="<?php echo SITE_URL; ?>pages/apikeys/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'apikeys') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
              </path>
            </svg>
            <span class="label truncate">API Keys</span>
          </a>

          <?php if (isSuperAdmin() || isAdmin()): ?>
            <a href="<?php echo SITE_URL; ?>pages/users/index.php"
              class="<?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
                </path>
              </svg>
              <span class="label truncate"><?php echo isAdmin() ? "Admins" : "Users"; ?></span>
            </a>
          <?php endif; ?>
        </div>
      </nav>
    </aside>

    <!-- MAIN -->
    <div class="main">
      <div class="topbar">
        <div style="font-weight:700; font-size:1.5rem;">Admin Panel</div>
        <div style="display:flex;align-items:center;gap:14px;">
          <div class="text-sm text-gray-200"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
          <a href="<?php echo SITE_URL; ?>logout.php" class="text-indigo-300">Logout</a>
        </div>
      </div>

      <div class="content">


        <div class="grid-wrap" id="dashboardGrid">
          <!-- LEFT COLUMN: Drivers THEN Route Orders THEN Orders -->
          <div class="left-column" id="leftColumn">
            <div class="column-resizer" id="columnResizer"></div>

            <!-- Drivers Section -->
            <div class="panel resizable-panel " id="driversPanel">
              <div class="resize-handle resize-handle-v"></div>
              <div class="resize-handle resize-handle-corner"></div>

              <div class="section-header">
                <div class="flex items-center gap-2">
                  <h2 class="text-lg font-semibold">Drivers</h2>
                  <span class="workflow-step">Step 2: Assign Routes</span>
                </div>
                <a href="<?php echo SITE_URL; ?>pages/riders/index.php" class="text-indigo-600 text-sm">Manage</a>
              </div>
              <div class="workflow-indicator">Drag routes here to assign to drivers</div>
              <div id="driversList" class="drivers-list">
                <table class="w-full text-sm text-left text-gray-500">
                  <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                      <th scope="col" class="py-2 px-4">Drivers</th>
                      <th scope="col" class="py-2 px-4">Orders</th>
                    </tr>
                  </thead>
                  <tbody id="driversTableBody">
                    <?php if ($riders_result && mysqli_num_rows($riders_result) > 0): ?>
                      <?php while ($r = mysqli_fetch_assoc($riders_result)): ?>
                        <tr class="bg-white border-b hover:bg-gray-50 drop-zone"
                          data-driver-id="<?php echo intval($r['id']); ?>">
                          <td class="py-2 px-4 font-medium text-gray-900 whitespace-nowrap">
                            <?php echo htmlspecialchars($r['name'] ?? ''); ?>
                            <div class="loading-spinner"></div>
                          </td>
                          <td class="py-2 px-4">
                            <?php echo intval($r['current_orders'] ?? 0); ?>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="2" class="text-center py-4">No drivers found</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Route Orders Section -->
            <div class="panel resizable-panel " id="routeOrdersPanel">
              <div class="resize-handle resize-handle-v"></div>
              <div class="resize-handle resize-handle-corner"></div>

              <div class="section-header">
                <div class="flex items-center gap-2">
                  <h2 class="text-lg font-semibold" id="routeOrdersTitle">All Orders</h2>
                  <span class="workflow-step" id="routeOrdersWorkflowStep">Select route to view its orders</span>
                </div>
                <div class="flex items-center gap-2">
                  <button id="clearRouteSelection" class="text-indigo-600 text-sm hidden">Clear Selection</button>
                  <span class="text-sm text-gray-500" id="routeOrdersCount">0 orders</span>
                </div>
              </div>

              <div class="workflow-indicator" id="routeOrdersIndicator">
                Click on any route to see its orders, or view all orders below
              </div>

              <div class="orders-list" id="routeOrdersList">
                <table class="w-full text-sm text-left text-gray-500">
                  <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                      <th scope="col" class="py-2 px-4">Order #</th>
                      <th scope="col" class="py-2 px-4">Client</th>
                      <th scope="col" class="py-2 px-4">Status</th>
                      <th scope="col" class="py-2 px-4">Assignment</th>
                    </tr>
                  </thead>
                  <tbody id="routeOrdersTableBody">
                    <?php if ($route_orders_result && mysqli_num_rows($route_orders_result) > 0): ?>
                      <?php while ($order = mysqli_fetch_assoc($route_orders_result)): ?>
                        <tr class="order-rows bg-white border-b hover:bg-gray-50 route-order-row"
                          data-order-id="<?php echo intval($order['id']); ?>"
                          data-assignment-status="<?php echo $order['assignment_status']; ?>">
                          <td class="py-2 px-4 font-medium text-gray-900 whitespace-nowrap">
                            <?php echo htmlspecialchars($order['order_number']); ?>
                          </td>
                          <td class="py-2 px-4">
                            <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?>
                          </td>
                          <td class="py-2 px-4">
                            <span class="status-pill status-<?php echo $order['status']; ?>">
                              <?php echo ucfirst($order['status']); ?>
                            </span>
                          </td>
                          <td class="py-2 px-4 text-xs">
                            <?php if ($order['assignment_status'] === 'assigned'): ?>
                              <span class="text-green-600 font-semibold">
                                Route <?php echo $order['manifest_id']; ?>
                                <?php if ($order['assigned_rider_name']): ?>
                                  <br><span
                                    class="text-gray-500"><?php echo htmlspecialchars($order['assigned_rider_name']); ?></span>
                                <?php endif; ?>
                              </span>
                            <?php else: ?>
                              <span class="text-orange-600 font-semibold">Unassigned</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr id="noRouteOrdersRow">
                        <td colspan="4" class="text-center py-4">No orders found</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Orders Section -->
            <div class="panel resizable-panel" id="ordersPanel">
              <div class="resize-handle resize-handle-v"></div>
              <div class="resize-handle resize-handle-corner"></div>

              <div class="section-header">
                <div class="flex items-center gap-2">
                  <h2 class="text-lg font-semibold">Unassigned Orders</h2>
                  <span class="workflow-step">Step 1: Add to Routes</span>
                </div>
                <div class="flex items-center gap-4">
                  <a href="<?php echo SITE_URL; ?>pages/orders/create.php" class="text-indigo-600 text-sm">Create
                    Order</a>
                  <a href="<?php echo SITE_URL; ?>pages/orders/import.php" class="text-indigo-600 text-sm">Import
                    Order</a>
                  <a href="<?php echo SITE_URL; ?>pages/orders/index.php" class="text-indigo-600 text-sm">View all →</a>
                </div>
              </div>

              <div class="workflow-indicator">Drag orders to routes first, then assign routes to drivers</div>
              <div class="orders-list" id="ordersList">
                <table class="w-full text-sm text-left text-gray-500">
                  <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                      <th scope="col" class="py-2 px-4">Order #</th>
                      <th scope="col" class="py-2 px-4">Client</th>
                      <th scope="col" class="py-2 px-4">Status</th>
                    </tr>
                  </thead>
                  <tbody id="ordersTableBody">
                    <?php if ($recent_orders_result && mysqli_num_rows($recent_orders_result) > 0): ?>
                      <?php while ($order = mysqli_fetch_assoc($recent_orders_result)): ?>
                        <tr class="bg-white border-b hover:bg-gray-50 draggable-row" draggable="true"
                          data-order-id="<?php echo intval($order['id']); ?>">
                          <td class="py-2 px-4 font-medium text-gray-900 whitespace-nowrap">
                            <?php echo htmlspecialchars($order['order_number']); ?>
                          </td>
                          <td class="py-2 px-4">
                            <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?>
                          </td>
                          <td class="py-2 px-4">
                            <span class="status-pill status-pending"><?php echo ucfirst($order['status']); ?></span>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="3" class="text-center py-4">No unassigned orders found</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- RIGHT COLUMN: Map on top, Routes below -->
          <div class="right-column" id="rightColumn">
            <div class="panel resizable-panel " id="mapPanel">
              <div class="resize-handle resize-handle-v"></div>
              <div class="resize-handle resize-handle-corner"></div>

              <div class="flex justify-between items-center ">
                <h2 class="text-lg font-semibold">Drivers Location Map</h2>
                <div class="text-sm text-gray-500" id="lastUpdate">Last update: 0 drivers</div>
              </div>
              <div id="map"></div>
            </div>

            <div class="panel resizable-panel" id="routesPanel">
              <div class="resize-handle resize-handle-v"></div>
              <div class="resize-handle resize-handle-corner"></div>

              <div class="section-header">
                <div class="flex items-center gap-2">
                  <h2 class="text-lg font-semibold">Routes</h2>
                  <span class="workflow-step">Collect Orders → Assign to Drivers</span>
                </div>
                <div class="flex items-center gap-4">
                  <a href="<?php echo SITE_URL; ?>pages/manifests/create.php" class="text-indigo-600 text-sm">Create
                    Route</a>
                  <a href="<?php echo SITE_URL; ?>pages/manifests/index.php" class="text-indigo-600 text-sm">View all
                    →</a>
                </div>
              </div>

              <div class="workflow-indicator">Routes ready for assignment to drivers</div>
              <div class="routes-list" id="routesList">
                <table class="w-full text-sm text-left text-gray-500">
                  <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                    <tr>
                      <th scope="col" class="py-2 px-4">Route #</th>
                      <th scope="col" class="py-2 px-4">Driver</th>
                      <th scope="col" class="py-2 px-4">Orders</th>
                      <th scope="col" class="py-2 px-4">Status</th>
                    </tr>
                  </thead>
                  <tbody id="routesTableBody">
                    <?php if ($routes_result && mysqli_num_rows($routes_result) > 0): ?>
                      <?php while ($r = mysqli_fetch_assoc($routes_result)): ?>
                        <tr
                          class="bg-white border-b hover:bg-gray-50 drop-zone <?php echo ($r['total_orders'] > 0) ? 'draggable-row' : ''; ?>"
                          data-route-id="<?php echo intval($r['id']); ?>" <?php echo ($r['total_orders'] > 0) ? 'draggable="true"' : ''; ?>>
                          <td class="py-2 px-4 font-medium text-gray-900 whitespace-nowrap">
                            R-<?php echo intval($r['id']); ?>
                            <div class="loading-spinner"></div>
                          </td>
                          <td class="py-2 px-4">
                            <?php echo htmlspecialchars($r['rider_name'] ?? 'Unassigned'); ?>
                          </td>
                          <td class="py-2 px-4">
                            <span class="orders-count"><?php echo intval($r['total_orders'] ?? 0); ?></span>
                            <?php if ($r['total_orders'] > 0): ?>
                              <span class="assigned-indicator">Ready</span>
                            <?php endif; ?>
                          </td>
                          <td class="py-2 px-4">
                            <?php echo htmlspecialchars(ucfirst($r['status'] ?? '')); ?>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="4" class="text-center py-4">No routes found</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Message container for notifications -->
  <div id="messageContainer"></div>


  <script>
    // ========== SIDEBAR ==========
const sidebar = document.getElementById('sidebar');
let pinned = false;

if (sidebar && sidebar.classList.contains('expanded')) {
  sidebar.classList.remove('expanded');
}

function sidebarHover(incoming) {
  if (pinned) return;
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  if (incoming) {
    sidebar.classList.add('expanded');
  } else {
    sidebar.classList.remove('expanded');
  }
}

function toggleSidebar() {
  pinned = !pinned;
  const sidebar = document.getElementById('sidebar');
  if (sidebar) {
    sidebar.classList.toggle('expanded', pinned);
  }
}

// ========== MESSAGE SYSTEM ==========
function showMessage(text, type = 'success') {
  const container = document.getElementById('messageContainer');
  const message = document.createElement('div');
  message.className = `message ${type}`;
  message.textContent = text;
  container.appendChild(message);

  setTimeout(() => message.classList.add('show'), 100);
  setTimeout(() => {
    message.classList.remove('show');
    setTimeout(() => container.removeChild(message), 300);
  }, 3000);
}

// ========== GLOBAL DRAG STATE ==========
let draggedOrderId = null;
let draggedRouteId = null;
let draggedElement = null;
let dragType = null;

// ========== ROUTE ORDERS MANAGEMENT ==========
let selectedRouteId = null;
let allOrdersData = [];

const routeOrdersManager = {
  init() {
    this.loadAllOrders();
    this.setupRouteClickHandlers();
    this.setupClearSelection();
  },

  async loadAllOrders() {
    this.displayCurrentOrders();
    this.updateOrdersCount();
  },

  displayCurrentOrders() {
    const rows = document.querySelectorAll('#routeOrdersTableBody tr.route-order-row');
    this.updateOrdersCount(rows.length);
  },

  setupRouteClickHandlers() {
    document.addEventListener('click', (e) => {
      if (e.target.closest('.draggable-row.dragging')) return;
      const routeRow = e.target.closest('#routesTableBody tr[data-route-id]');
      if (routeRow && !e.target.closest('.loading-spinner')) {
        const routeId = routeRow.dataset.routeId;
        this.selectRoute(routeId);
      }
    });
  },

  setupClearSelection() {
    const clearBtn = document.getElementById('clearRouteSelection');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        this.clearRouteSelection();
      });
    }
  },

  async selectRoute(routeId) {
    if (selectedRouteId === routeId) {
      this.clearRouteSelection();
      return;
    }
    selectedRouteId = routeId;
    this.updateRouteSelectionUI(routeId);
    await this.loadRouteOrders(routeId);
    this.highlightSelectedRoute(routeId);
  },

  async loadRouteOrders(routeId) {
    const tableBody = document.getElementById('routeOrdersTableBody');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">Loading...</td></tr>';

    // Get the correct API path
    const currentPath = window.location.pathname;
    const isInPagesFolder = currentPath.includes('/pages/');
    const apiPath = isInPagesFolder ? '../api/get_route_orders.php' : 'api/get_route_orders.php';

    try {
      const response = await fetch(`${apiPath}?manifest_id=${routeId}`);
      const orders = await response.json();

      if (response.ok) {
        tableBody.innerHTML = '';
        if (orders.length > 0) {
          orders.forEach(order => {
            const row = document.createElement('tr');
            row.className = 'bg-white border-b hover:bg-gray-50 route-order-row';
            row.dataset.orderId = order.id;
            row.innerHTML = `
              <td class="py-2 px-4 font-medium text-gray-900 whitespace-nowrap">${order.order_number}</td>
              <td class="py-2 px-4">${order.customer_name || 'N/A'}</td>
              <td class="py-2 px-4">
                <span class="status-pill status-${order.status}">
                  ${order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                </span>
              </td>
              <td class="py-2 px-4 text-xs">
                <span class="text-green-600 font-semibold">Route ${routeId}</span>
              </td>
            `;
            tableBody.appendChild(row);
          });
          const noOrdersRow = document.getElementById('noRouteOrdersRow');
          if (noOrdersRow) noOrdersRow.style.display = 'none';
        } else {
          tableBody.innerHTML = '<tr id="noRouteOrdersRow"><td colspan="4" class="text-center py-4">No orders found for this route</td></tr>';
        }
        this.updateOrdersCount(orders.length);
      } else {
        tableBody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-red-500">${orders.error || 'Failed to load data.'}</td></tr>`;
        this.updateOrdersCount(0);
      }
    } catch (error) {
      tableBody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-red-500">Failed to connect to server.</td></tr>`;
      this.updateOrdersCount(0);
    }
  },

  clearRouteSelection() {
    selectedRouteId = null;
    this.updateRouteSelectionUI(null);
    const allRows = document.querySelectorAll('#routeOrdersTableBody tr.route-order-row');
    allRows.forEach(row => row.style.display = '');
    const noOrdersRow = document.getElementById('noRouteOrdersRow');
    if (noOrdersRow) noOrdersRow.style.display = 'none';
    this.updateOrdersCount(allRows.length);
    this.highlightSelectedRoute(null);
  },

  updateRouteSelectionUI(routeId) {
    const title = document.getElementById('routeOrdersTitle');
    const workflowStep = document.getElementById('routeOrdersWorkflowStep');
    const indicator = document.getElementById('routeOrdersIndicator');
    const clearBtn = document.getElementById('clearRouteSelection');

    if (routeId) {
      if (title) title.textContent = `Route R-${routeId} Orders`;
      if (workflowStep) workflowStep.textContent = 'Route Selected';
      if (indicator) indicator.textContent = `Showing orders assigned to Route R-${routeId}`;
      if (clearBtn) clearBtn.classList.remove('hidden');
    } else {
      if (title) title.textContent = 'All Orders';
      if (workflowStep) workflowStep.textContent = 'Select route to view its orders';
      if (indicator) indicator.textContent = 'Click on any route to see its orders, or view all orders below';
      if (clearBtn) clearBtn.classList.add('hidden');
    }
  },

  highlightSelectedRoute(routeId) {
    document.querySelectorAll('#routesTableBody tr').forEach(row => {
      row.classList.remove('route-selected');
    });
    if (routeId) {
      const routeRow = document.querySelector(`#routesTableBody tr[data-route-id="${routeId}"]`);
      if (routeRow) routeRow.classList.add('route-selected');
    }
  },

  updateOrdersCount(count) {
    if (count === undefined) {
      const visibleRows = document.querySelectorAll('#routeOrdersTableBody tr.route-order-row[style=""], #routeOrdersTableBody tr.route-order-row:not([style])');
      count = visibleRows.length;
    }
    const countElement = document.getElementById('routeOrdersCount');
    if (countElement) {
      countElement.textContent = `${count} order${count !== 1 ? 's' : ''}`;
    }
  },

  refresh() {
    setTimeout(() => {
      if (selectedRouteId) {
        this.loadRouteOrders(selectedRouteId);
      } else {
        this.clearRouteSelection();
      }
    }, 500);
  }
};

// ========== DRAG AND DROP ==========

// Track which elements have been set up to avoid duplicate event listeners
const setupTracking = {
  orders: new Set(),
  routes: new Set(),
  routeDrops: new Set(),
  driverDrops: new Set()
};

function setupDraggableOrders() {
  const orderRows = document.querySelectorAll('#ordersTableBody tr.draggable-row[data-order-id]');
  console.log('🔵 [DRAG-ORDER] Found', orderRows.length, 'order rows');

  orderRows.forEach(row => {
    const orderId = row.dataset.orderId;
    if (setupTracking.orders.has(orderId)) return;
    setupTracking.orders.add(orderId);

    row.addEventListener('dragstart', (e) => {
      console.log('🟢 [DRAG-ORDER] DRAGSTART - Order ID:', row.dataset.orderId);
      document.body.classList.add('dragging-active');
      draggedOrderId = row.dataset.orderId;
      draggedElement = row;
      dragType = 'order';
      row.classList.add('dragging');
      e.dataTransfer.setData('text/plain', draggedOrderId);
      e.dataTransfer.effectAllowed = 'move';
      console.log('🟢 [DRAG-ORDER] dragType set to:', dragType);
      createDragGhost(row, e);
    });

    row.addEventListener('dragend', () => {
      console.log('🔴 [DRAG-ORDER] DRAGEND');
      document.body.classList.remove('dragging-active');
      row.classList.remove('dragging');
      draggedOrderId = null;
      draggedElement = null;
      dragType = null;
    });
  });
}

function setupDraggableRoutes() {
  const routeRows = document.querySelectorAll('#routesTableBody tr[data-route-id]');
  console.log('🔵 [DRAG-ROUTE] Found', routeRows.length, 'route rows');

  routeRows.forEach(row => {
    const routeId = row.dataset.routeId;

    const ordersCountSpan = row.querySelector('.orders-count');
    const orderCount = ordersCountSpan ? parseInt(ordersCountSpan.textContent) || 0 : 0;

    console.log('🔵 [DRAG-ROUTE] Route ID:', routeId, '| Orders:', orderCount, '| Draggable:', orderCount > 0);

    if (orderCount > 0) {
      row.setAttribute('draggable', 'true');
      row.classList.add('draggable-row');

      // Remove old listener if it exists and add new one
      if (setupTracking.routes.has(routeId)) {
        const oldRow = row;
        const newRow = row.cloneNode(true);
        row.parentNode.replaceChild(newRow, row);
        setupTracking.routes.delete(routeId);
        // Now set up the new row
        setupSingleDraggableRoute(newRow, routeId);
      } else {
        setupSingleDraggableRoute(row, routeId);
      }
    } else {
      row.removeAttribute('draggable');
      row.classList.remove('draggable-row');
      setupTracking.routes.delete(routeId);
    }
  });
}

function setupSingleDraggableRoute(row, routeId) {
  setupTracking.routes.add(routeId);

  row.addEventListener('dragstart', (e) => {
    console.log('🟢 [DRAG-ROUTE] ✅ DRAGSTART - Route ID:', routeId);
    document.body.classList.add('dragging-active');
    draggedRouteId = routeId;
    draggedElement = row;
    dragType = 'route';
    row.classList.add('dragging');
    e.dataTransfer.setData('text/plain', routeId);
    e.dataTransfer.effectAllowed = 'move';
    console.log('🟢 [DRAG-ROUTE] dragType set to:', dragType);
    console.log('🟢 [DRAG-ROUTE] draggedRouteId:', draggedRouteId);
    createDragGhost(row, e);
  });

  row.addEventListener('dragend', () => {
    console.log('🔴 [DRAG-ROUTE] DRAGEND - Route ID:', routeId);
    document.body.classList.remove('dragging-active');
    row.classList.remove('dragging');
    draggedRouteId = null;
    draggedElement = null;
    dragType = null;
  });
}

function createDragGhost(row, e) {
  const ghost = row.cloneNode(true);
  ghost.style.position = 'absolute';
  ghost.style.top = '-1000px';
  ghost.style.opacity = '0.8';
  ghost.style.transform = 'rotate(2deg) scale(0.95)';
  ghost.style.backgroundColor = '#e0f2fe';
  ghost.style.zIndex = '9999';
  document.body.appendChild(ghost);
  e.dataTransfer.setDragImage(ghost, e.offsetX, e.offsetY);
  setTimeout(() => {
    if (document.body.contains(ghost)) document.body.removeChild(ghost);
  }, 100);
}

function setupRouteDropTargets() {
  const routeRows = document.querySelectorAll('#routesTableBody tr[data-route-id]');
  console.log('🔵 [DROP-ROUTE] Setting up', routeRows.length, 'route drop targets (for orders)');

  routeRows.forEach(row => {
    const routeId = row.dataset.routeId;
    if (setupTracking.routeDrops.has(routeId)) return;
    setupTracking.routeDrops.add(routeId);

    row.classList.add('drop-zone');

    row.addEventListener('dragover', (e) => {
      if (dragType !== 'order') return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      row.classList.add('drag-over-order');
    });

    row.addEventListener('dragleave', (e) => {
      if (!row.contains(e.relatedTarget)) {
        row.classList.remove('drag-over-order');
      }
    });

    row.addEventListener('drop', async (e) => {
      e.preventDefault();
      row.classList.remove('drag-over-order');
      if (dragType !== 'order') return;
      const orderId = e.dataTransfer.getData('text/plain');
      console.log('🟢 [DROP-ROUTE] Order', orderId, 'dropped on Route', routeId);
      if (!orderId || !routeId) return;
      await assignOrderToRoute(orderId, routeId, row);
    });
  });
}

function setupDriverDropTargets() {
  const driverRows = document.querySelectorAll('#driversTableBody tr[data-driver-id]');
  console.log('🔵 [DROP-DRIVER] Setting up', driverRows.length, 'driver drop targets (for routes)');

  driverRows.forEach(row => {
    const driverId = row.dataset.driverId;
    if (setupTracking.driverDrops.has(driverId)) return;
    setupTracking.driverDrops.add(driverId);

    row.classList.add('drop-zone');

    row.addEventListener('dragover', (e) => {
      console.log('🟡 [DROP-DRIVER] DRAGOVER detected | dragType:', dragType);
      if (dragType !== 'route') {
        console.log('🔴 [DROP-DRIVER] Wrong dragType - expected "route", got:', dragType);
        return;
      }
      console.log('🟢 [DROP-DRIVER] ✅ DRAGOVER accepted for route');
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      row.classList.add('drag-over-route');
    });

    row.addEventListener('dragleave', (e) => {
      if (!row.contains(e.relatedTarget)) {
        row.classList.remove('drag-over-route');
      }
    });

    row.addEventListener('drop', async (e) => {
      e.preventDefault();
      console.log('🟢 [DROP-DRIVER] ✅ DROP EVENT TRIGGERED');
      row.classList.remove('drag-over-route');

      if (dragType !== 'route') {
        console.log('🔴 [DROP-DRIVER] ❌ Wrong dragType on drop:', dragType);
        return;
      }

      const routeId = e.dataTransfer.getData('text/plain');
      console.log('🟢 [DROP-DRIVER] Route ID:', routeId, '| Driver ID:', driverId);

      if (!routeId || !driverId) {
        console.log('🔴 [DROP-DRIVER] ❌ Missing route or driver ID');
        return;
      }

      console.log('🟢 [DROP-DRIVER] ✅ Calling assignRouteToDriver()');
      await assignRouteToDriver(routeId, driverId, row);
    });
  });
}

async function assignOrderToRoute(orderId, routeId, targetRow) {
  console.log('🟢 [ASSIGN-ORDER] Starting assignment...');
  const spinner = targetRow.querySelector('.loading-spinner');
  if (spinner) spinner.style.display = 'inline-block';

  // Get the correct API path
  const currentPath = window.location.pathname;
  const isInPagesFolder = currentPath.includes('/pages/');
  const apiPath = isInPagesFolder ? '../api/assign_order_to_route.php' : 'api/assign_order_to_route.php';

  console.log('🔵 [ASSIGN-ORDER] API Path:', apiPath);
  console.log('🔵 [ASSIGN-ORDER] Current Path:', currentPath);

  try {
    const response = await fetch(apiPath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        order_id: parseInt(orderId),
        route_id: parseInt(routeId)
      })
    });

    console.log('🔵 [ASSIGN-ORDER] Response status:', response.status);

    if (!response.ok) {
      const errorText = await response.text();
      console.log('🔴 [ASSIGN-ORDER] Response error:', errorText);
      throw new Error(`HTTP ${response.status}: ${errorText}`);
    }

    const result = await response.json();
    console.log('🔵 [ASSIGN-ORDER] Response:', result);

    if (result.success) {
      console.log('🟢 [ASSIGN-ORDER] ✅ Success!');
      if (draggedElement && draggedElement.dataset.orderId == orderId) {
        draggedElement.remove();
      }
      updateRouteRow(routeId, targetRow);
      showMessage(`Order #${getOrderNumber(orderId)} added to Route R-${routeId}`, 'success');

      setTimeout(() => {
        console.log('🔵 [ASSIGN-ORDER] Reinitializing drag/drop handlers...');
        setupDraggableRoutes();
        setupDriverDropTargets();
        routeOrdersManager.refresh();
      }, 100);
    } else {
      console.log('🔴 [ASSIGN-ORDER] ❌ Failed:', result.message);
      showMessage(result.message || 'Failed to add order to route', 'error');
    }
  } catch (error) {
    console.log('🔴 [ASSIGN-ORDER] ❌ Error:', error);
    showMessage('Network error: ' + error.message, 'error');
  } finally {
    if (spinner) spinner.style.display = 'none';
  }
}

async function assignRouteToDriver(routeId, riderId, targetRow) {
  console.log('🟢 [ASSIGN-ROUTE] Starting assignment...');
  const spinner = targetRow.querySelector('.loading-spinner');
  if (spinner) spinner.style.display = 'inline-block';

  // Get the correct API path
  const currentPath = window.location.pathname;
  const isInPagesFolder = currentPath.includes('/pages/');
  const apiPath = isInPagesFolder ? '../api/assign_route_to_rider.php' : 'api/assign_route_to_rider.php';

  console.log('🔵 [ASSIGN-ROUTE] API Path:', apiPath);
  console.log('🔵 [ASSIGN-ROUTE] Current Path:', currentPath);

  try {
    const response = await fetch(apiPath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        route_id: parseInt(routeId),
        rider_id: parseInt(riderId)
      })
    });

    console.log('🔵 [ASSIGN-ROUTE] Response status:', response.status);

    if (!response.ok) {
      const errorText = await response.text();
      console.log('🔴 [ASSIGN-ROUTE] Response error:', errorText);
      throw new Error(`HTTP ${response.status}: ${errorText}`);
    }

    const result = await response.json();
    console.log('🔵 [ASSIGN-ROUTE] Response:', result);

    if (result.success) {
      console.log('🟢 [ASSIGN-ROUTE] ✅ Success!');
      if (draggedElement && draggedElement.dataset.routeId == routeId) {
        const driverCell = draggedElement.querySelector('td:nth-child(2)');
        if (driverCell) driverCell.textContent = getDriverName(riderId);
      }
      updateDriverOrderCount(riderId, targetRow);
      showMessage(`Route R-${routeId} assigned to ${getDriverName(riderId)}`, 'success');
      routeOrdersManager.refresh();
    } else {
      console.log('🔴 [ASSIGN-ROUTE] ❌ Failed:', result.message);
      showMessage(result.message || 'Failed to assign route to driver', 'error');
    }
  } catch (error) {
    console.log('🔴 [ASSIGN-ROUTE] ❌ Error:', error);
    showMessage('Network error: ' + error.message, 'error');
  } finally {
    if (spinner) spinner.style.display = 'none';
  }
}

function updateRouteRow(routeId, routeRow) {
  const ordersCountSpan = routeRow.querySelector('.orders-count');
  if (ordersCountSpan) {
    const currentCount = parseInt(ordersCountSpan.textContent) || 0;
    ordersCountSpan.textContent = currentCount + 1;
    if (!routeRow.querySelector('.assigned-indicator')) {
      const readyIndicator = document.createElement('span');
      readyIndicator.className = 'assigned-indicator';
      readyIndicator.textContent = 'Ready';
      ordersCountSpan.parentNode.appendChild(readyIndicator);
    }
    routeRow.draggable = true;
    routeRow.classList.add('draggable-row');
  }
}

function updateDriverOrderCount(riderId, driverRow) {
  const orderCountCell = driverRow.querySelector('td:nth-child(2)');
  if (orderCountCell) {
    const currentCount = parseInt(orderCountCell.textContent) || 0;
    orderCountCell.textContent = currentCount + 1;
  }
}

function getOrderNumber(orderId) {
  if (draggedElement) {
    const orderNumberCell = draggedElement.querySelector('td:first-child');
    return orderNumberCell ? orderNumberCell.textContent.trim() : orderId;
  }
  return orderId;
}

function getDriverName(riderId) {
  const driverRow = document.querySelector(`#driversTableBody tr[data-driver-id="${riderId}"]`);
  if (driverRow) {
    const nameCell = driverRow.querySelector('td:first-child');
    return nameCell ? nameCell.textContent.trim() : 'Driver';
  }
  return 'Driver';
}

function initializeDragAndDrop() {
  console.log('═══════════════════════════════════════════');
  console.log('🔵 [DRAG-DROP] INITIALIZING DRAG AND DROP');
  console.log('═══════════════════════════════════════════');

  // Clear tracking on re-initialization
  setupTracking.orders.clear();
  setupTracking.routes.clear();
  setupTracking.routeDrops.clear();
  setupTracking.driverDrops.clear();

  setupDraggableOrders();
  console.log('✅ [DRAG-DROP] Orders setup complete');

  setupDraggableRoutes();
  console.log('✅ [DRAG-DROP] Routes setup complete');

  setupRouteDropTargets();
  console.log('✅ [DRAG-DROP] Route drop targets setup complete');

  setupDriverDropTargets();
  console.log('✅ [DRAG-DROP] Driver drop targets setup complete');

  console.log('═══════════════════════════════════════════');
  console.log('✅ [DRAG-DROP] INITIALIZATION COMPLETE');
  console.log('═══════════════════════════════════════════');
}

// ========== MAP INITIALIZATION ==========
let map = null;
const markers = {};

function addMapMarkers() {
  if (!map) return;

  Object.values(markers).forEach(marker => marker.remove());
  Object.keys(markers).forEach(key => delete markers[key]);

  if (drivers.length === 0) {
    document.getElementById('lastUpdate').textContent = 'Last update: 0 drivers';
    return;
  }

  const bounds = [];

  drivers.forEach(driver => {
    if (driver.latitude && driver.longitude) {
      const lat = parseFloat(driver.latitude);
      const lng = parseFloat(driver.longitude);

      const marker = L.marker([lat, lng]).addTo(map);
      marker.bindPopup(`
        <strong>${driver.rider_name || 'Unknown'}</strong><br>
        Last seen: ${driver.created_at || 'Unknown'}
      `);

      markers[driver.rider_id] = marker;
      bounds.push([lat, lng]);
    }
  });

  if (bounds.length > 0) {
    map.fitBounds(bounds, { padding: [50, 50] });
  }

  document.getElementById('lastUpdate').textContent = `Last update: ${drivers.length} driver${drivers.length !== 1 ? 's' : ''}`;
}

function fixMap() {
  if (map) {
    map.invalidateSize();
    setTimeout(() => {
      if (map) map.invalidateSize();
    }, 100);
  } else {
    initializeMap();
  }
}

function initializeMap() {
  try {
    const mapContainer = document.getElementById('map');

    if (!mapContainer) {
      setTimeout(initializeMap, 200);
      return;
    }

    const mapPanel = document.getElementById('mapPanel');

    if (mapPanel && mapPanel.style.height) {
      const height = parseInt(mapPanel.style.height) || 310;
      mapContainer.style.height = (height - 60) + 'px';
    } else {
      mapContainer.style.height = '250px';
    }

    if (mapContainer._leaflet_id) {
      mapContainer._leaflet_id = null;
    }

    if (map) {
      try {
        map.remove();
      } catch (e) { }
      map = null;
    }

    map = L.map('map', {
      center: [51.5074, -0.1278],
      zoom: 10,
      minZoom: 8,
      maxZoom: 18,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
    }).addTo(map);

    requestAnimationFrame(() => {
      map.invalidateSize();

      [50, 150, 300, 600].forEach((delay) => {
        setTimeout(() => {
          if (map) map.invalidateSize();
        }, delay);
      });
    });

    addMapMarkers();

    setTimeout(() => {
      if (map) map.invalidateSize();
    }, 800);

  } catch (error) {
    setTimeout(initializeMap, 500);
  }
}

// ========== RESIZABLE PANELS ==========
function initializeResizablePanels() {
  // Clear any existing initialization flags
  document.querySelectorAll('.resizable-panel').forEach(panel => {
    panel._resizeInitialized = false;
  });

  initializePanelResizing();
  initializeColumnResizing();
  makeResizeHandlesSticky();
}

function makeResizeHandlesSticky() {
  document.querySelectorAll('.resizable-panel').forEach(panel => {
    const handleV = panel.querySelector('.resize-handle-v');
    const handleCorner = panel.querySelector('.resize-handle-corner');

    if (!handleV && !handleCorner) return;

    panel.addEventListener('scroll', () => {
      const offset = panel.scrollTop;

      if (handleV) {
        handleV.style.transform = `translateY(${offset}px)`;
      }
      if (handleCorner) {
        handleCorner.style.transform = `translateY(${offset}px)`;
      }
    }, { passive: true });
  });

  console.log('✅ Sticky resize handles initialized');
}

function initializePanelResizing() {
  // First remove any existing resize handles and recreate them
  document.querySelectorAll('.resizable-panel').forEach(panel => {
    // Remove existing resize handles
    const existingHandles = panel.querySelectorAll('.resize-handle');
    existingHandles.forEach(handle => handle.remove());
    
    // Create fresh resize handles
    const resizeHandleV = document.createElement('div');
    resizeHandleV.className = 'resize-handle resize-handle-v';
    
    const resizeHandleCorner = document.createElement('div');
    resizeHandleCorner.className = 'resize-handle resize-handle-corner';
    
    panel.appendChild(resizeHandleV);
    panel.appendChild(resizeHandleCorner);
    
    // Mark as not initialized
    panel._resizeInitialized = false;
  });

  // Now initialize with fresh handles
  document.querySelectorAll('.resizable-panel').forEach(panel => {
    if (panel._resizeInitialized) return;
    
    const resizeHandleV = panel.querySelector('.resize-handle-v');
    const resizeHandleCorner = panel.querySelector('.resize-handle-corner');
    
    if (resizeHandleV) initializeResizeHandle(resizeHandleV, panel, 'vertical');
    if (resizeHandleCorner) initializeResizeHandle(resizeHandleCorner, panel, 'both');
    
    panel._resizeInitialized = true;
  });
}

function initializeColumnResizing() {
  const columnResizer = document.getElementById('columnResizer');
  const leftColumn = document.getElementById('leftColumn');
  const dashboardGrid = document.getElementById('dashboardGrid');

  if (!columnResizer || !leftColumn || !dashboardGrid) return;

  let isResizing = false;
  let startX = 0;
  let startWidth = 0;

  columnResizer.addEventListener('mousedown', (e) => {
    isResizing = true;
    startX = e.clientX;
    startWidth = leftColumn.offsetWidth;
    columnResizer.classList.add('dragging');
    document.addEventListener('mousemove', handleColumnResize);
    document.addEventListener('mouseup', stopColumnResize);
    e.preventDefault();
  });

  function handleColumnResize(e) {
    if (!isResizing) return;
    const deltaX = e.clientX - startX;
    const newWidth = Math.max(250, Math.min(startWidth + deltaX, window.innerWidth - 300));
    const percentage = (newWidth / dashboardGrid.offsetWidth) * 100;
    dashboardGrid.style.gridTemplateColumns = `${percentage}% 1fr`;
  }

  function stopColumnResize() {
    isResizing = false;
    columnResizer.classList.remove('dragging');
    const gridColumns = dashboardGrid.style.gridTemplateColumns;
    saveLayoutToDatabase('dashboardGrid', null, null, gridColumns);
    document.removeEventListener('mousemove', handleColumnResize);
    document.removeEventListener('mouseup', stopColumnResize);
  }
}

function initializeResizeHandle(handle, panel, direction) {
  let isResizing = false;
  let startX = 0, startY = 0, startWidth = 0, startHeight = 0;

  handle.addEventListener('mousedown', (e) => {
    isResizing = true;
    panel.classList.add('resizing');
    startX = e.clientX;
    startY = e.clientY;
    startWidth = parseInt(document.defaultView.getComputedStyle(panel).width, 10);
    startHeight = parseInt(document.defaultView.getComputedStyle(panel).height, 10);
    document.addEventListener('mousemove', handleResize);
    document.addEventListener('mouseup', stopResize);
    e.preventDefault();
    e.stopPropagation();
  });

  function handleResize(e) {
    if (!isResizing) return;
    if (direction === 'vertical' || direction === 'both') {
      const newHeight = Math.max(200, startHeight + e.clientY - startY);
      panel.style.height = newHeight + 'px';
    }
    if (direction === 'horizontal' || direction === 'both') {
      const newWidth = Math.max(250, startWidth + e.clientX - startX);
      panel.style.width = newWidth + 'px';
    }
    if (panel.id === 'mapPanel' && map) {
      setTimeout(() => map.invalidateSize(), 100);
    }
  }

  function stopResize() {
    isResizing = false;
    panel.classList.remove('resizing');
    saveLayoutToDatabase(panel.id, panel.style.width, panel.style.height);
    document.removeEventListener('mousemove', handleResize);
    document.removeEventListener('mouseup', stopResize);
  }
}

async function saveLayoutToDatabase(panelId, width, height, gridColumns = null) {
  try {
    await fetch('../api/save_dashboard_layout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        panel_id: panelId,
        width: width,
        height: height,
        grid_columns: gridColumns
      })
    });
  } catch (error) {
    // Silent fail
  }
}

function applySavedSizes() {
  const userPreferences = window.userPreferences || {};
  const defaultSizes = {
    driversPanel: { width: '100%', height: '180px' },
    routeOrdersPanel: { width: '100%', height: '200px' },
    ordersPanel: { width: '100%', height: '190px' },
    mapPanel: { width: '100%', height: '310px' },
    routesPanel: { width: '100%', height: '275px' }
  };

  document.querySelectorAll('.resizable-panel').forEach(panel => {
    const panelId = panel.id;
    const savedFromDB = userPreferences[panelId];
    const fallback = defaultSizes[panelId] || {};

    panel.style.width = (savedFromDB && savedFromDB.width) || fallback.width || '100%';
    panel.style.height = (savedFromDB && savedFromDB.height) || fallback.height || '220px';
  });

  const dashboardGrid = document.getElementById('dashboardGrid');
  if (dashboardGrid && userPreferences['dashboardGrid'] && userPreferences['dashboardGrid'].grid_columns) {
    dashboardGrid.style.gridTemplateColumns = userPreferences['dashboardGrid'].grid_columns;
  }
}

// ========== NAVIGATION FIX ==========
function reinitializeOnNavigation() {
  console.log('🔄 Checking if reinitialization needed...');
  
  const panels = document.querySelectorAll('.resizable-panel');
  const firstPanel = panels[0];
  
  // Check if resize functionality is working
  if (firstPanel && (!firstPanel._resizeInitialized || !firstPanel.querySelector('.resize-handle-v'))) {
    console.log('🔄 Reinitializing resize functionality...');
    
    // Force complete reinitialization
    initializeApp();
    
    // Mark as initialized
    panels.forEach(panel => {
      panel._resizeInitialized = true;
    });
  }
}

// ========== APP INITIALIZATION ==========
function initializeApp() {
  console.log('═══════════════════════════════════════════');
  console.log('🚀 APP INITIALIZATION STARTED');
  console.log('═══════════════════════════════════════════');

  try {
    // Reset all initialization flags
    document.querySelectorAll('.resizable-panel').forEach(panel => {
      panel._resizeInitialized = false;
    });

    applySavedSizes();
    initializeMap();
    initializeResizablePanels();

    console.log('🔵 [INIT] Initializing drag and drop...');
    initializeDragAndDrop();

    setTimeout(() => {
      routeOrdersManager.init();
      console.log('═══════════════════════════════════════════');
      console.log('✅ APP INITIALIZATION COMPLETE');
      console.log('═══════════════════════════════════════════');
    }, 300);

    // Safety recheck for navigation issues
    setTimeout(() => {
      console.log('🔵 [INIT] Navigation safety check...');
      reinitializeOnNavigation();
    }, 1000);

  } catch (error) {
    console.error('❌ Error during initialization:', error);
    // Retry on error
    setTimeout(initializeApp, 500);
  }
}

function delayedInitialize() {
  setTimeout(() => {
    console.log('🔵 [INIT] DOM ready - starting initialization after 400ms delay');
    initializeApp();
  }, 400);
}

// ========== EVENT LISTENERS ==========
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', delayedInitialize);
} else {
  delayedInitialize();
}

// Reinitialize on various page events
window.addEventListener('load', () => {
  fixMap();
  setTimeout(reinitializeOnNavigation, 200);
});

window.addEventListener('resize', () => {
  fixMap();
});

// FIX FOR NAVIGATION: Reinitialize when returning to dashboard
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    console.log('🔄 Page restored from cache - reinitializing...');
    setTimeout(initializeApp, 300);
  }
});

// Monitor URL changes for navigation
let currentUrl = window.location.href;
setInterval(() => {
  if (window.location.href !== currentUrl) {
    currentUrl = window.location.href;
    if (currentUrl.includes('dashboard.php')) {
      console.log('🔄 Navigation to dashboard detected - reinitializing...');
      setTimeout(initializeApp, 500);
    }
  }
}, 200);

// Reinitialize when page becomes visible again
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) {
    setTimeout(reinitializeOnNavigation, 200);
  }
});

// Force reinitialization when focusing on the window
window.addEventListener('focus', () => {
  setTimeout(reinitializeOnNavigation, 300);
});
  </script>



</body>

</html>