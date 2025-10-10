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
      padding: 8px 16px; /* Reduced from 12px 20px */
      display: flex;
      justify-content: space-between;
      align-items: center;
      height: 48px; /* Fixed height, reduced from ~60px */
    }

    .content {
      padding: 6px; /* Reduced from 12px */
      flex: 1;
      overflow: auto;
      height: calc(100vh - 48px); /* Adjusted for new topbar height */
    }

    .grid-wrap {
  display: grid;
  gap: 0px; /* CHANGE FROM 4px TO 2px */
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
  padding: 4px; /* CHANGE FROM 6px TO 4px */
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
  position: relative;
  min-height: 120px; /* CHANGE FROM 140px TO 120px */
  max-height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

    /* Resizable panels */
    .resizable-panel {
      overflow: auto;
      min-width: 250px;
      min-height: 140px; /* Reduced from 200px */
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
  gap: 0px; /* CHANGE FROM 4px TO 2px */

    }

  .right-column {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0px; /* CHANGE FROM 4px TO 2px */
}


    /* Compact table styling */
    .panel table {
      width: 100%;
      font-size: 13px; /* Reduced from default */
    }

   .panel table thead th {
  padding: 3px 6px; /* CHANGE FROM 4px 8px TO 3px 6px */
  font-size: 11px;
  font-weight: 600;
}

    .panel table tbody td {
  padding: 2px 6px; /* CHANGE FROM 3px 8px TO 2px 6px */
  line-height: 1.2;
}


    .panel table tbody tr {
      height: auto; /* Let content determine height */
    }

    /* Compact section headers */
    .section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px; /* CHANGE FROM 6px TO 4px */
}

    .section-header h2 {
      font-size: 16px; /* Reduced from 18px (text-lg) */
      font-weight: 600;
      margin: 0;
    }

  .workflow-indicator {
  font-size: 10px;
  color: #6B7280;
  margin-top: 1px; /* CHANGE FROM 2px TO 1px */
  margin-bottom: 2px; /* CHANGE FROM 4px TO 2px */
  font-style: italic;
  line-height: 1.2;
}

    .workflow-step {
      font-size: 9px; /* Reduced from 10px */
      color: #6B7280;
      background: #F3F4F6;
      padding: 2px 4px; /* Reduced from 2px 6px */
      border-radius: 3px; /* Reduced from 4px */
      font-weight: 500;
    }

    /* Compact status pills */
    .status-pill {
      font-weight: 700;
      font-size: 10px; /* Reduced from 12px */
      padding: 2px 6px; /* Reduced from 4px 8px */
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
      font-size: 9px; /* Reduced from 10px */
      color: #10b981;
      font-weight: 600;
      background: rgba(16, 185, 129, 0.1);
      padding: 1px 3px; /* Reduced from 2px 4px */
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
  height: 100% !important; /* Force height */
  min-height: 250px !important; /* Ensure minimum */
  width: 100%;
  border-radius: 6px;
  position: relative; /* Add this */
}

    /* Loading spinner */
    .loading-spinner {
      display: none;
      width: 14px; /* Reduced from 16px */
      height: 14px;
      border: 2px solid #f3f3f3;
      border-top: 2px solid #10B981;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-left: 6px; /* Reduced from 8px */
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
      font-size: 12px; /* Reduced from 14px (text-sm) */
    }
    /* Default heights for panels (first login) */
#driversPanel { height: 180px; }
#routeOrdersPanel { height: 200px; }
#ordersPanel { height: 190px; }
#mapPanel { height: 310px; }
#routesPanel { height: 275px; }


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
                        <tr class="bg-white border-b hover:bg-gray-50 drop-zone" data-driver-id="<?php echo intval($r['id']); ?>">
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
                                  <br><span class="text-gray-500"><?php echo htmlspecialchars($order['assigned_rider_name']); ?></span>
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
                <a href="<?php echo SITE_URL; ?>pages/orders/create.php" class="text-indigo-600 text-sm">Create Order</a>
<a href="<?php echo SITE_URL; ?>pages/orders/import.php" class="text-indigo-600 text-sm">Import Order</a>
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
                        <tr class="bg-white border-b hover:bg-gray-50 draggable-row" draggable="true" data-order-id="<?php echo intval($order['id']); ?>">
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
                      <tr><td colspan="3" class="text-center py-4">No unassigned orders found</td></tr>
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
              <a href="<?php echo SITE_URL; ?>pages/manifests/create.php" class="text-indigo-600 text-sm">Create Route</a>
<a href="<?php echo SITE_URL; ?>pages/manifests/index.php" class="text-indigo-600 text-sm">View all →</a>
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
                        <tr class="bg-white border-b hover:bg-gray-50 drop-zone <?php echo ($r['total_orders'] > 0) ? 'draggable-row' : ''; ?>" 
                            data-route-id="<?php echo intval($r['id']); ?>" 
                            <?php echo ($r['total_orders'] > 0) ? 'draggable="true"' : ''; ?>>
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

    try {
      const response = await fetch(`../api/get_route_orders.php?manifest_id=${routeId}`);
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

function setupDraggableOrders() {
  const orderRows = document.querySelectorAll('#ordersTableBody tr.draggable-row[data-order-id]');
  orderRows.forEach(row => {
    const newRow = row.cloneNode(true);
    row.parentNode.replaceChild(newRow, row);
    
    newRow.addEventListener('dragstart', (e) => {
      document.body.classList.add('dragging-active');
      draggedOrderId = newRow.dataset.orderId;
      draggedElement = newRow;
      dragType = 'order';
      newRow.classList.add('dragging');
      e.dataTransfer.setData('text/plain', draggedOrderId);
      e.dataTransfer.effectAllowed = 'move';
      createDragGhost(newRow, e);
    });

    newRow.addEventListener('dragend', () => {
      document.body.classList.remove('dragging-active');
      newRow.classList.remove('dragging');
      draggedOrderId = null;
      draggedElement = null;
      dragType = null;
    });
  });
}

function setupDraggableRoutes() {
  const routeRows = document.querySelectorAll('#routesTableBody tr.draggable-row[data-route-id][draggable="true"]');
  routeRows.forEach(row => {
    const newRow = row.cloneNode(true);
    row.parentNode.replaceChild(newRow, row);
    
    newRow.addEventListener('dragstart', (e) => {
      document.body.classList.add('dragging-active');
      draggedRouteId = newRow.dataset.routeId;
      draggedElement = newRow;
      dragType = 'route';
      newRow.classList.add('dragging');
      e.dataTransfer.setData('text/plain', draggedRouteId);
      e.dataTransfer.effectAllowed = 'move';
      createDragGhost(newRow, e);
    });

    newRow.addEventListener('dragend', () => {
      document.body.classList.remove('dragging-active');
      newRow.classList.remove('dragging');
      draggedRouteId = null;
      draggedElement = null;
      dragType = null;
    });
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
  const routeRows = document.querySelectorAll('#routesTableBody tr.drop-zone[data-route-id]');
  routeRows.forEach(row => {
    const newRow = row.cloneNode(true);
    row.parentNode.replaceChild(newRow, row);
    
    newRow.addEventListener('dragover', (e) => {
      if (dragType !== 'order') return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      newRow.classList.add('drag-over-order');
    });

    newRow.addEventListener('dragleave', (e) => {
      if (!newRow.contains(e.relatedTarget)) {
        newRow.classList.remove('drag-over-order');
      }
    });

    newRow.addEventListener('drop', async (e) => {
      e.preventDefault();
      newRow.classList.remove('drag-over-order');
      if (dragType !== 'order') return;
      const orderId = e.dataTransfer.getData('text/plain');
      const routeId = newRow.dataset.routeId;
      if (!orderId || !routeId) return;
      await assignOrderToRoute(orderId, routeId, newRow);
    });
  });
}

function setupDriverDropTargets() {
  const driverRows = document.querySelectorAll('#driversTableBody tr.drop-zone[data-driver-id]');
  driverRows.forEach(row => {
    const newRow = row.cloneNode(true);
    row.parentNode.replaceChild(newRow, row);
    
    newRow.addEventListener('dragover', (e) => {
      if (dragType !== 'route') return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      newRow.classList.add('drag-over-route');
    });

    newRow.addEventListener('dragleave', (e) => {
      if (!newRow.contains(e.relatedTarget)) {
        newRow.classList.remove('drag-over-route');
      }
    });

    newRow.addEventListener('drop', async (e) => {
      e.preventDefault();
      newRow.classList.remove('drag-over-route');
      if (dragType !== 'route') return;
      const routeId = e.dataTransfer.getData('text/plain');
      const riderId = newRow.dataset.driverId;
      if (!routeId || !riderId) return;
      await assignRouteToDriver(routeId, riderId, newRow);
    });
  });
}

async function assignOrderToRoute(orderId, routeId, targetRow) {
  const spinner = targetRow.querySelector('.loading-spinner');
  if (spinner) spinner.style.display = 'inline-block';

  try {
    const response = await fetch('../api/assign_order_to_route.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        order_id: parseInt(orderId),
        route_id: parseInt(routeId)
      })
    });

    const result = await response.json();

    if (result.success) {
      if (draggedElement && draggedElement.dataset.orderId == orderId) {
        draggedElement.remove();
      }
      updateRouteRow(routeId, targetRow);
      showMessage(`Order #${getOrderNumber(orderId)} added to Route R-${routeId}`, 'success');
      setTimeout(() => {
        setupDraggableRoutes();
        routeOrdersManager.refresh();
      }, 100);
    } else {
      showMessage(result.message || 'Failed to add order to route', 'error');
    }
  } catch (error) {
    showMessage('Network error occurred', 'error');
  } finally {
    if (spinner) spinner.style.display = 'none';
  }
}

async function assignRouteToDriver(routeId, riderId, targetRow) {
  const spinner = targetRow.querySelector('.loading-spinner');
  if (spinner) spinner.style.display = 'inline-block';

  try {
    const response = await fetch('../api/assign_route_to_rider.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        route_id: parseInt(routeId),
        rider_id: parseInt(riderId)
      })
    });

    const result = await response.json();

    if (result.success) {
      if (draggedElement && draggedElement.dataset.routeId == routeId) {
        const driverCell = draggedElement.querySelector('td:nth-child(2)');
        if (driverCell) driverCell.textContent = getDriverName(riderId);
      }
      updateDriverOrderCount(riderId, targetRow);
      showMessage(`Route R-${routeId} assigned to ${getDriverName(riderId)}`, 'success');
      routeOrdersManager.refresh();
    } else {
      showMessage(result.message || 'Failed to assign route to driver', 'error');
    }
  } catch (error) {
    showMessage('Network error occurred', 'error');
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
  setupDraggableOrders();
  setupDraggableRoutes();
  setupRouteDropTargets();
  setupDriverDropTargets();
}

// ========== MAP INITIALIZATION - ONLY MAP LOGS HERE ==========
const drivers = <?php echo json_encode($riders_locations); ?> || [];
const markers = {};
let map = null;
function initializeMap() {
  console.log('[MAP] === initializeMap() CALLED ===');
  console.log('[MAP] Document readyState:', document.readyState);
  console.log('[MAP] Drivers data:', drivers);

  try {
    const mapContainer = document.getElementById('map');
    console.log('[MAP] Map container found:', !!mapContainer);

    if (!mapContainer) {
      console.error('[MAP] ❌ Map container NOT found, retrying in 200ms');
      setTimeout(initializeMap, 200);
      return;
    }

    const mapPanel = document.getElementById('mapPanel');
    console.log('[MAP] Map panel found:', !!mapPanel);
    console.log('[MAP] Map panel height:', mapPanel ? mapPanel.style.height : 'N/A');

    if (mapPanel && mapPanel.style.height) {
      const height = parseInt(mapPanel.style.height) || 310;
      mapContainer.style.height = (height - 60) + 'px';
      console.log('[MAP] Set container height to:', mapContainer.style.height);
    } else {
      mapContainer.style.height = '250px';
      console.log('[MAP] Using fallback height: 250px');
    }

    // 🧩 FIX 1: Prevent "already initialized" errors
    if (mapContainer._leaflet_id) {
      console.warn('[MAP] Existing Leaflet instance detected — cleaning up');
      mapContainer._leaflet_id = null;
    }

    // Remove any old map if exists
    if (typeof map !== 'undefined' && map) {
      console.log('[MAP] Existing map found, removing it');
      try {
        map.remove();
      } catch (e) {
        console.log('[MAP] Error removing old map:', e);
      }
      map = null;
    }

    console.log('[MAP] Creating new Leaflet map...');
    map = L.map('map', {
      center: [51.5074, -0.1278],
      zoom: 10,
      minZoom: 8,
      maxZoom: 18,
    });
    console.log('[MAP] ✓ Leaflet map created');

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
    }).addTo(map);
    console.log('[MAP] ✓ Tile layer added');

    console.log('[MAP] Scheduling invalidateSize() calls...');
    requestAnimationFrame(() => {
      console.log('[MAP] invalidateSize() - requestAnimationFrame');
      map.invalidateSize();

      [50, 150, 300, 600].forEach((delay) => {
        setTimeout(() => {
          console.log(`[MAP] invalidateSize() - ${delay}ms`);
          map.invalidateSize();
        }, delay);
      });
    });

    addMapMarkers();
    console.log('[MAP] ✅ Map initialized successfully');

    // 🧩 FIX 2: Ensure map becomes visible after page navigation
    setTimeout(() => {
      console.log('[MAP] 🔄 Final visibility fix (invalidateSize after 800ms)');
      if (map) map.invalidateSize();
    }, 800);

  } catch (error) {
    console.error('[MAP] ❌ Error initializing map:', error);
    console.error('[MAP] Error stack:', error.stack);
    setTimeout(initializeMap, 500);
  }
}


// ========== RESIZABLE PANELS ==========

function initializeResizablePanels() {
  initializePanelResizing();
  initializeColumnResizing();
}

function initializePanelResizing() {
  document.querySelectorAll('.resizable-panel').forEach(panel => {
    const resizeHandleV = panel.querySelector('.resize-handle-v');
    const resizeHandleCorner = panel.querySelector('.resize-handle-corner');
    if (resizeHandleV) initializeResizeHandle(resizeHandleV, panel, 'vertical');
    if (resizeHandleCorner) initializeResizeHandle(resizeHandleCorner, panel, 'both');
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
    if (panel.id === 'mapPanel') {
      setTimeout(() => map && map.invalidateSize(), 100);
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
    const response = await fetch('../api/save_dashboard_layout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        panel_id: panelId,
        width: width,
        height: height,
        grid_columns: gridColumns
      })
    });

    const result = await response.json();
  } catch (error) {
    // Silent fail
  }
}

const userPreferences = <?php echo $user_preferences_json; ?> || {};
const defaultSizes = {
  driversPanel: { width: '100%', height: '180px' },
  routeOrdersPanel: { width: '100%', height: '200px' },
  ordersPanel: { width: '100%', height: '190px' },
  mapPanel: { width: '100%', height: '310px' },
  routesPanel: { width: '100%', height: '275px' }
};

function applySavedSizes() {
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

// ========== APP INITIALIZATION ==========
function initializeApp() {
  console.log('[MAP] ========================================');
  console.log('[MAP] APP INITIALIZATION STARTED');
  console.log('[MAP] ========================================');
  console.log('[MAP] Time:', new Date().toISOString());
  console.log('[MAP] Document readyState:', document.readyState);
  console.log('[MAP] Window loaded:', document.readyState === 'complete');
  
  try {
    console.log('[MAP] Step 1: Applying saved sizes...');
    applySavedSizes();
    
    console.log('[MAP] Step 2: Initializing map...');
    initializeMap();
    
    console.log('[MAP] Step 3: Initializing resizable panels...');
    initializeResizablePanels();
    
    console.log('[MAP] Step 4: Initializing drag and drop...');
    initializeDragAndDrop();
    
    console.log('[MAP] Step 5: Initializing route orders manager (300ms delay)...');
    setTimeout(() => {
      routeOrdersManager.init();
      console.log('[MAP] ========================================');
      console.log('[MAP] APP INITIALIZATION COMPLETE');
      console.log('[MAP] ========================================');
    }, 300);
    
  } catch (error) {
    console.error('[MAP] ❌❌❌ Error during initialization:', error);
    console.error('[MAP] Error stack:', error.stack);
  }
}

// Run initialization
console.log('[MAP] ========================================');
console.log('[MAP] SCRIPT LOADED');
console.log('[MAP] Document readyState:', document.readyState);
console.log('[MAP] ========================================');

if (document.readyState === 'loading') {
  console.log('[MAP] Document still loading, adding DOMContentLoaded listener');
  document.addEventListener('DOMContentLoaded', () => {
    console.log('[MAP] DOMContentLoaded event fired');
    initializeApp();
  });
} else {
  console.log('[MAP] Document already loaded, initializing immediately');
  initializeApp();
}

window.addEventListener('load', () => {
  console.log('[MAP] ========================================');
  console.log('[MAP] WINDOW LOAD EVENT FIRED');
  console.log('[MAP] ========================================');
  fixMap();
  if (!draggedOrderId && !draggedRouteId) {
    console.log('[MAP] Reinitializing drag and drop on window load');
    initializeDragAndDrop();
  }
});

window.addEventListener('resize', () => {
  console.log('[MAP] Window resize event');
  fixMap();
});

// Page visibility change handler
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) {
    console.log('[MAP] ========================================');
    console.log('[MAP] PAGE BECAME VISIBLE');
    console.log('[MAP] ========================================');
    
    const mapContainer = document.getElementById('map');
    const hasLeafletContent = mapContainer && mapContainer.querySelector('.leaflet-container');
    
    console.log('[MAP] Map exists:', !!map);
    console.log('[MAP] Container has leaflet content:', hasLeafletContent);
    
    if (!map || !hasLeafletContent) {
      console.log('[MAP] Map missing or empty, forcing reinitialization in 150ms');
      map = null;
      setTimeout(() => {
        initializeMap();
      }, 150);
    } else {
      console.log('[MAP] Map looks good, calling fixMap() in 100ms');
      setTimeout(fixMap, 100);
    }
  }
});

// Window focus event
window.addEventListener('focus', () => {
  console.log('[MAP] ========================================');
  console.log('[MAP] WINDOW FOCUS EVENT');
  console.log('[MAP] ========================================');
  
  setTimeout(() => {
    const mapContainer = document.getElementById('map');
    const hasLeafletContent = mapContainer && mapContainer.querySelector('.leaflet-container');
    
    console.log('[MAP] Map exists:', !!map);
    console.log('[MAP] Container has leaflet content:', hasLeafletContent);
    
    if (!map || !hasLeafletContent) {
      console.log('[MAP] Map not found on focus, reinitializing');
      map = null;
      initializeMap();
    } else {
      console.log('[MAP] Map found, calling fixMap()');
      fixMap();
    }
  }, 200);
});

// Page show event (fires when navigating back via browser history)
window.addEventListener('pageshow', (event) => {
  console.log('[MAP] ========================================');
  console.log('[MAP] PAGE SHOW EVENT');
  console.log('[MAP] Event persisted (from cache):', event.persisted);
  console.log('[MAP] ========================================');
  
  setTimeout(() => {
    const mapContainer = document.getElementById('map');
    const hasLeafletContent = mapContainer && mapContainer.querySelector('.leaflet-container');
    
    console.log('[MAP] Map exists:', !!map);
    console.log('[MAP] Container has leaflet content:', hasLeafletContent);
    
    if (!map || !hasLeafletContent || event.persisted) {
      console.log('[MAP] Forcing full map reinitialization');
      map = null;
      initializeMap();
    }
  }, 150);
});

</script>



</body>

</html>