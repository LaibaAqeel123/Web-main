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
    .app-shell { display:flex; min-height:100vh; background:#f3f4f6; }
    .sidebar { width:72px; background:#0b1220; color:#fff; transition: width .18s ease; }
    .sidebar.expanded { width:220px; }
    .sidebar .brand { padding:14px; display:flex; gap:10px; align-items:center; }
    .sidebar .nav a { display:flex; align-items:center; gap:12px; padding:12px; color:#cbd5e1; text-decoration:none; border-radius:8px; }
    .sidebar .nav a:hover { background: rgba(255,255,255,0.03); color:#fff; }
    .sidebar .label { display:none; } .sidebar.expanded .label { display:inline-block; }
    .logo-container { display:none; } .sidebar.expanded .logo-container { display:flex; }
    .main { flex:1; display:flex; flex-direction:column; }
    .topbar { background:#0b1724; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .content { padding:18px; flex:1; overflow:auto; }

    .grid-wrap { 
      display: grid; 
      gap: 12px; 
      grid-template-columns: 1fr 1fr; 
      align-items: start; 
      position: relative;
    }
    @media (max-width:1100px){ .grid-wrap { grid-template-columns:1fr; } }

    .panel { 
      background:white; 
      border-radius:8px; 
      padding:12px; 
      box-shadow:0 1px 3px rgba(0,0,0,0.06); 
      position: relative;
      min-height: 200px;
    }

    /* Resizable panels */
 
.resizable-panel {
  overflow: auto;
  min-width: 250px;
  min-height: 200px;
  max-width: 100%;
  border: 1px solid #e5e7eb;
}

/* Make sure the main grid items don't stretch */
.grid-wrap > div {
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
    }
    .orders-list { max-height:250px; overflow:auto; }
    .riders-list { max-height:280px; overflow:auto; }
    
    /* Status pill styling */
    .status-pill { font-weight:700; font-size:12px; padding:4px 8px; border-radius:999px; }
    .status-delivered { background:#ECFDF5; color:#065F46; }
    .status-pending { background:#FFFBEB; color:#92400E; }
    .status-assigned { background:#EFF6FF; color:#1E3A8A; }
    .status-failed { background:#FEF2F2; color:#991B1B; }

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
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
      font-size: 10px;
      color: #10b981;
      font-weight: 600;
      background: rgba(16, 185, 129, 0.1);
      padding: 2px 4px;
      border-radius: 3px;
      margin-left: 4px;
    }

    /* Workflow indicators */
    .workflow-indicator {
      font-size: 11px;
      color: #6B7280;
      margin-top: 4px;
      font-style: italic;
    }

    /* Section headers with workflow info */
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }
    .workflow-step {
      font-size: 10px;
      color: #6B7280;
      background: #F3F4F6;
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 500;
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

    #map { height: 360px; border-radius:8px; }

    /* Loading spinner */
    .loading-spinner {
      display: none;
      width: 16px;
      height: 16px;
      border: 2px solid #f3f3f3;
      border-top: 2px solid #10B981;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-left: 8px;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
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
    .message.success { background: #10B981; }
    .message.error { background: #EF4444; }
    .message.show { opacity: 1; transform: translateY(0); }

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

  </style>
</head>
<body>

<div class="app-shell">
  <!-- SIDEBAR  -->
  <aside id="sidebar" class="sidebar" onmouseenter="sidebarHover(true)" onmouseleave="sidebarHover(false)">
    <div class="brand">
      <button id="sidebarToggle" onclick="toggleSidebar()" class="text-white" style="background:none;border:none;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
      </button>
    </div>

    <nav class="nav px-2">
      <div class="flex items-center justify-center py-3 border-b border-gray-700 logo-container">
        <a href="<?php echo SITE_URL; ?>" class="p-2 hover:opacity-80 transition-opacity"></a>
      </div>

      <div class="mt-4">
        <a href="<?php echo SITE_URL; ?>pages/dashboard.php"
           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'dashboard.php') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
           <span class="label truncate">Dashboard</span>
        </a>

        <?php if (isSuperAdmin()): ?>
          <a href="<?php echo SITE_URL; ?>pages/companies/index.php"
             class="<?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
             <span class="label truncate">Companies</span>
          </a>
        <?php endif; ?>

        <?php if (isSuperAdmin() || isAdmin()): ?>
          <a href="<?php echo SITE_URL; ?>pages/products/index.php"
             class="<?php echo (strpos($_SERVER['PHP_SELF'], 'products') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
             <span class="label truncate">Products</span>
          </a>
        <?php endif; ?>

        <?php if (isSuperAdmin() || isAdmin()): ?>
          <a href="<?php echo SITE_URL; ?>pages/warehouses/index.php"
             class="<?php echo (strpos($_SERVER['PHP_SELF'], 'warehouses') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path></svg>
             <span class="label truncate">Warehouses</span>
          </a>
        <?php endif; ?>

        <a href="<?php echo SITE_URL; ?>pages/orders/index.php"
           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'orders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
           <span class="label truncate">Orders</span>
        </a>

        <?php if (isSuperAdmin() || isAdmin()): ?>
          <a href="<?php echo SITE_URL; ?>pages/organizations/index.php"
             class="<?php echo (strpos($_SERVER['PHP_SELF'], 'organizations') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
             <span class="label truncate">Organizations</span>
          </a>
        <?php endif; ?>

        <a href="<?php echo SITE_URL; ?>pages/manifests/index.php"
           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'manifests') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
           <span class="label truncate">Manifests</span>
        </a>

        <a href="<?php echo SITE_URL; ?>pages/apikeys/index.php"
           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'apikeys') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
           <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
           <span class="label truncate">API Keys</span>
        </a>

        <?php if (isSuperAdmin() || isAdmin()): ?>
          <a href="<?php echo SITE_URL; ?>pages/riders/index.php"
             class="<?php echo (strpos($_SERVER['PHP_SELF'], 'riders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
             <span class="label truncate">Riders</span>
          </a>
        <?php endif; ?>

        <?php if (isSuperAdmin() || isAdmin()): ?>
          <a href="<?php echo SITE_URL; ?>pages/users/index.php"
             class="<?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
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
      <h1 class="text-2xl font-bold mb-4">Dashboard</h1>

      <div class="grid-wrap" id="dashboardGrid">
        <!-- LEFT COLUMN: Riders THEN Route Orders THEN Orders -->
        <div class="left-column" id="leftColumn">
          <div class="column-resizer" id="columnResizer"></div>
          
          <!-- Riders Section -->
          <div class="panel resizable-panel mb-3" id="ridersPanel">
            <div class="resize-handle resize-handle-v"></div>
            <div class="resize-handle resize-handle-corner"></div>
            
            <div class="section-header">
              <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold">Riders</h2>
                <span class="workflow-step">Step 2: Assign Routes</span>
              </div>
              <a href="riders/index.php" class="text-indigo-600 text-sm">Manage</a>
            </div>
            <div class="workflow-indicator">Drag routes here to assign to riders</div>
           <div id="ridersList" class="riders-list">
    <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th scope="col" class="py-2 px-4">Rider</th>
                <th scope="col" class="py-2 px-4">Orders</th>
            </tr>
        </thead>
        <tbody id="ridersTableBody">
            <?php if ($riders_result && mysqli_num_rows($riders_result) > 0): ?>
                <?php while ($r = mysqli_fetch_assoc($riders_result)): ?>
                    <tr class="bg-white border-b hover:bg-gray-50 drop-zone" data-rider-id="<?php echo intval($r['id']); ?>">
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
                <tr><td colspan="2" class="text-center py-4">No riders found</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
          </div>

          <!-- Route Orders Section - NEW 5th CARD -->
          <div class="panel resizable-panel mb-3" id="routeOrdersPanel">
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
            
            <div class="orders-list" id="routeOrdersList" style="max-height: 280px; overflow: auto;">
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
                      <tr class="bg-white border-b hover:bg-gray-50 route-order-row" 
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

          <!-- Orders Section with Buttons -->
          <div class="panel resizable-panel" id="ordersPanel">
            <div class="resize-handle resize-handle-v"></div>
            <div class="resize-handle resize-handle-corner"></div>
            
            <div class="section-header">
              <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold">Unassigned Orders</h2>
                <span class="workflow-step">Step 1: Add to Routes</span>
              </div>
              <div class="flex items-center gap-4">
                <a href="orders/create.php" class="text-indigo-600 text-sm">Create Order</a>
                <a href="orders/import.php" class="text-indigo-600 text-sm">Import Order</a>
                <a href="orders/index.php" class="text-indigo-600 text-sm">View all →</a>
              </div>
            </div>
            
            <div class="workflow-indicator">Drag orders to routes first, then assign routes to riders</div>
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
          <div class="panel resizable-panel mb-3" id="mapPanel">
            <div class="resize-handle resize-handle-v"></div>
            <div class="resize-handle resize-handle-corner"></div>
            
            <div class="flex justify-between items-center mb-3">
              <h2 class="text-lg font-semibold">Riders Location Map</h2>
              <div class="text-sm text-gray-500" id="lastUpdate">Last update: 0 riders</div>
            </div>
            <div id="map"></div>
          </div>

          <div class="panel resizable-panel" id="routesPanel">
            <div class="resize-handle resize-handle-v"></div>
            <div class="resize-handle resize-handle-corner"></div>
            
            <div class="section-header">
              <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold">Routes</h2>
                <span class="workflow-step">Collect Orders → Assign to Riders</span>
              </div>
              <a href="manifests/index.php" class="text-indigo-600 text-sm">New</a>
            </div>
            <div class="workflow-indicator">Drop orders here, then drag routes to riders</div>
           <div id="routesList" style="max-height:180px; overflow:auto;">
    <table class="w-full text-sm text-left text-gray-500">
        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                <th scope="col" class="py-2 px-4">Route #</th>
                <th scope="col" class="py-2 px-4">Rider</th>
                <th scope="col" class="py-2 px-4">Orders</th>
                <th scope="col" class="py-2 px-4">Status</th>
            </tr>
        </thead>
        <tbody id="routesTableBody">
            <?php if ($routes_result && mysqli_num_rows($routes_result) > 0): ?>
                <?php while ($r = mysqli_fetch_assoc($routes_result)): ?>
                    <tr class="bg-white border-b hover:bg-gray-50 drop-zone draggable-row route-row" 
                        data-route-id="<?php echo intval($r['id']); ?>" 
                        draggable="<?php echo ($r['total_orders'] > 0) ? 'true' : 'false'; ?>"
                        style="cursor: pointer;">
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
                <tr><td colspan="4" class="text-center py-4">No routes found</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
          </div>
        </div>

      </div> <!-- grid-wrap -->
    </div> <!-- content -->
  </div> <!-- main -->
</div> <!-- app-shell -->

<!-- Message container for notifications -->
<div id="messageContainer"></div>

<script>
  // Sidebar behavior 
  const sidebar = document.getElementById('sidebar');
  let pinned = false;
  function sidebarHover(incoming){ if(pinned) return; incoming ? sidebar.classList.add('expanded') : sidebar.classList.remove('expanded'); }
  function toggleSidebar(){ pinned = !pinned; sidebar.classList.toggle('expanded', pinned); }
  sidebar.classList.remove('expanded');

  // Message system
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

  // Global drag state
  let draggedOrderId = null;
  let draggedRouteId = null;
  let draggedElement = null;
  let dragType = null; // 'order' or 'route'

  // Route Orders Management - NEW FUNCTIONALITY
  let selectedRouteId = null;
  let allOrdersData = []; // Cache for all orders data

  const routeOrdersManager = {
    
    // Initialize route orders functionality
    init() {
      this.loadAllOrders();
      this.setupRouteClickHandlers();
      this.setupClearSelection();
      console.log('Route Orders Manager initialized');
    },

    // Load all orders data via AJAX
    async loadAllOrders() {
      try {
        // For now, use the data already loaded in PHP
        this.displayCurrentOrders();
        this.updateOrdersCount();
        console.log('Route orders initialized with current data');
      } catch (error) {
        console.error('Error loading orders:', error);
      }
    },

    // Display current orders from PHP data
    displayCurrentOrders() {
      const rows = document.querySelectorAll('#routeOrdersTableBody tr.route-order-row');
      this.updateOrdersCount(rows.length);
    },

    // Setup click handlers for route rows
    setupRouteClickHandlers() {
      document.addEventListener('click', (e) => {
        // Don't interfere with drag operations
        if (e.target.closest('.draggable-row.dragging')) return;
        
        const routeRow = e.target.closest('#routesTableBody tr[data-route-id]');
        if (routeRow && !e.target.closest('.loading-spinner')) {
          const routeId = routeRow.dataset.routeId;
          this.selectRoute(routeId);
        }
      });
    },

    // Setup clear selection button
    setupClearSelection() {
      const clearBtn = document.getElementById('clearRouteSelection');
      clearBtn.addEventListener('click', () => {
        this.clearRouteSelection();
      });
    },

    // Select a route and show its orders
    async selectRoute(routeId) {
      if (selectedRouteId === routeId) {
        // If clicking the same route, clear selection
        this.clearRouteSelection();
        return;
      }

      selectedRouteId = routeId;
      
      // Update UI to show selected state
      this.updateRouteSelectionUI(routeId);
      
      // Load and display route-specific orders
      await this.loadRouteOrders(routeId);
      
      // Highlight selected route
      this.highlightSelectedRoute(routeId);
    },

    // Load orders for specific route (simulated with current data)
  // Load and display orders for a specific route by fetching data from the server
async loadRouteOrders(routeId) {
    const tableBody = document.getElementById('routeOrdersTableBody');
    tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">Loading...</td></tr>';

    try {
        const response = await fetch(`../api/get_route_orders.php?manifest_id=${routeId}`);
        const orders = await response.json();

        // Check if the request was successful
        if (response.ok) {
            tableBody.innerHTML = ''; // Clear the "Loading..." message
            if (orders.length > 0) {
                // Loop through the fetched orders and create new table rows
                orders.forEach(order => {
                    const row = document.createElement('tr');
                    row.className = 'bg-white border-b hover:bg-gray-50 route-order-row';
                    row.dataset.orderId = order.id;
                    row.innerHTML = `
                        <td class="py-2 px-4 font-medium text-gray-900 whitespace-nowrap">
                            ${order.order_number}
                        </td>
                        <td class="py-2 px-4">
                            ${order.customer_name || 'N/A'}
                        </td>
                        <td class="py-2 px-4">
                            <span class="status-pill status-${order.status}">
                                ${order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                            </span>
                        </td>
                        <td class="py-2 px-4 text-xs">
                            <span class="text-green-600 font-semibold">
                                Route ${routeId}
                            </span>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
                
                // Hide the "no orders" message if it exists
                const noOrdersRow = document.getElementById('noRouteOrdersRow');
                if (noOrdersRow) noOrdersRow.style.display = 'none';

            } else {
                // Display a message if no orders were found
                tableBody.innerHTML = '<tr id="noRouteOrdersRow"><td colspan="4" class="text-center py-4">No orders found for this route</td></tr>';
            }
            this.updateOrdersCount(orders.length);
        } else {
            // Handle API errors
            console.error('Failed to load route orders:', orders.error);
            tableBody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-red-500">${orders.error || 'Failed to load data.'}</td></tr>`;
            this.updateOrdersCount(0);
        }
    } catch (error) {
        // Handle network errors
        console.error('Fetch error:', error);
        tableBody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-red-500">Failed to connect to server.</td></tr>`;
        this.updateOrdersCount(0);
    }
},

    // Clear route selection and show all orders
    clearRouteSelection() {
      selectedRouteId = null;
      this.updateRouteSelectionUI(null);
      
      // Show all rows
      const allRows = document.querySelectorAll('#routeOrdersTableBody tr.route-order-row');
      allRows.forEach(row => row.style.display = '');
      
      // Hide "no orders" message
      const noOrdersRow = document.getElementById('noRouteOrdersRow');
      if (noOrdersRow) {
        noOrdersRow.style.display = 'none';
      }
      
      this.updateOrdersCount(allRows.length);
      this.highlightSelectedRoute(null);
    },

    // Update UI elements based on route selection
    updateRouteSelectionUI(routeId) {
      const title = document.getElementById('routeOrdersTitle');
      const workflowStep = document.getElementById('routeOrdersWorkflowStep');
      const indicator = document.getElementById('routeOrdersIndicator');
      const clearBtn = document.getElementById('clearRouteSelection');

      if (routeId) {
        title.textContent = `Route R-${routeId} Orders`;
        workflowStep.textContent = 'Route Selected';
        indicator.textContent = `Showing orders assigned to Route R-${routeId}`;
        clearBtn.classList.remove('hidden');
      } else {
        title.textContent = 'All Orders';
        workflowStep.textContent = 'Select route to view its orders';
        indicator.textContent = 'Click on any route to see its orders, or view all orders below';
        clearBtn.classList.add('hidden');
      }
    },

    // Highlight selected route in routes table
    highlightSelectedRoute(routeId) {
      // Remove previous highlights
      document.querySelectorAll('#routesTableBody tr').forEach(row => {
        row.classList.remove('route-selected');
      });

      // Add highlight to selected route
      if (routeId) {
        const routeRow = document.querySelector(`#routesTableBody tr[data-route-id="${routeId}"]`);
        if (routeRow) {
          routeRow.classList.add('route-selected');
        }
      }
    },

    // Update orders count display
    updateOrdersCount(count) {
      if (count === undefined) {
        const visibleRows = document.querySelectorAll('#routeOrdersTableBody tr.route-order-row[style=""], #routeOrdersTableBody tr.route-order-row:not([style])');
        count = visibleRows.length;
      }
      const countElement = document.getElementById('routeOrdersCount');
      countElement.textContent = `${count} order${count !== 1 ? 's' : ''}`;
    },

    // Refresh route orders data (call this when orders are moved)
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

  // DRAG AND DROP IMPLEMENTATION FOR TABLE ROWS

  // Setup draggable order rows (Step 1: Orders → Routes)
  function setupDraggableOrders() {
    const orderRows = document.querySelectorAll('#ordersTableBody tr.draggable-row[data-order-id]');
    
    orderRows.forEach(row => {
      row.addEventListener('dragstart', (e) => {
        document.body.classList.add('dragging-active');
        draggedOrderId = row.dataset.orderId;
        draggedElement = row;
        dragType = 'order';
        row.classList.add('dragging');
        
        // Set drag data
        e.dataTransfer.setData('text/plain', draggedOrderId);
        e.dataTransfer.effectAllowed = 'move';
        
        // Create ghost image
        createDragGhost(row, e);
        
        console.log(`Started dragging order: ${draggedOrderId}`);
      });

      row.addEventListener('dragend', () => {
        document.body.classList.remove('dragging-active');
        row.classList.remove('dragging');
        draggedOrderId = null;
        draggedElement = null;
        dragType = null;
        console.log('Order drag ended');
      });
    });
    
    console.log(`Setup ${orderRows.length} draggable order rows`);
  }

  // Setup draggable route rows (Step 2: Routes → Riders)
  function setupDraggableRoutes() {
    const routeRows = document.querySelectorAll('#routesTableBody tr.draggable-row[data-route-id][draggable="true"]');
    
    routeRows.forEach(row => {
      row.addEventListener('dragstart', (e) => {
        document.body.classList.add('dragging-active');
        draggedRouteId = row.dataset.routeId;
        draggedElement = row;
        dragType = 'route';
        row.classList.add('dragging');
        
        // Set drag data
        e.dataTransfer.setData('text/plain', draggedRouteId);
        e.dataTransfer.effectAllowed = 'move';
        
        // Create ghost image
        createDragGhost(row, e);
        
        console.log(`Started dragging route: ${draggedRouteId}`);
      });

      row.addEventListener('dragend', () => {
        document.body.classList.remove('dragging-active');
        row.classList.remove('dragging');
        draggedRouteId = null;
        draggedElement = null;
        dragType = null;
        console.log('Route drag ended');
      });
    });
    
    console.log(`Setup ${routeRows.length} draggable route rows`);
  }

  // Create drag ghost image
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
      if (document.body.contains(ghost)) {
        document.body.removeChild(ghost);
      }
    }, 100);
  }

  // Setup drop targets for route rows (accept orders only)
  function setupRouteDropTargets() {
    const routeRows = document.querySelectorAll('#routesTableBody tr.drop-zone[data-route-id]');
    
    routeRows.forEach(row => {
      row.addEventListener('dragover', (e) => {
        // Only accept orders, not routes
        if (dragType !== 'order') return;
        
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        row.classList.add('drag-over-order');
      });

      row.addEventListener('dragleave', (e) => {
        // Check if we're leaving the row entirely
        if (!row.contains(e.relatedTarget)) {
          row.classList.remove('drag-over-order');
        }
      });

      row.addEventListener('drop', async (e) => {
        e.preventDefault();
        row.classList.remove('drag-over-order');
        
        // Only accept orders
        if (dragType !== 'order') return;
        
        const orderId = e.dataTransfer.getData('text/plain');
        const routeId = row.dataset.routeId;
        
        if (!orderId || !routeId) return;

        await assignOrderToRoute(orderId, routeId, row);
      });
    });
    
    console.log(`Setup ${routeRows.length} route drop targets`);
  }

  // Setup drop targets for rider rows (accept routes only)
  function setupRiderDropTargets() {
    const riderRows = document.querySelectorAll('#ridersTableBody tr.drop-zone[data-rider-id]');
    
    riderRows.forEach(row => {
      row.addEventListener('dragover', (e) => {
        // Only accept routes, not orders
        if (dragType !== 'route') return;
        
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        row.classList.add('drag-over-route');
      });

      row.addEventListener('dragleave', (e) => {
        // Check if we're leaving the row entirely
        if (!row.contains(e.relatedTarget)) {
          row.classList.remove('drag-over-route');
        }
      });

      row.addEventListener('drop', async (e) => {
        e.preventDefault();
        row.classList.remove('drag-over-route');
        
        // Only accept routes
        if (dragType !== 'route') return;
        
        const routeId = e.dataTransfer.getData('text/plain');
        const riderId = row.dataset.riderId;
        
        if (!routeId || !riderId) return;

        await assignRouteToRider(routeId, riderId, row);
      });
    });
    
    console.log(`Setup ${riderRows.length} rider drop targets`);
  }

  // API CALLS FOR DRAG AND DROP OPERATIONS

  // Assign order to route (Step 1)
  async function assignOrderToRoute(orderId, routeId, targetRow) {
    const spinner = targetRow.querySelector('.loading-spinner');
    if (spinner) spinner.style.display = 'inline-block';

    try {
      console.log(`Assigning order ${orderId} to route ${routeId}`);
      
      const response = await fetch('../api/assign_order_to_route.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          order_id: parseInt(orderId),
          route_id: parseInt(routeId)
        })
      });

      const result = await response.json();
      console.log('Assignment result:', result);

      if (result.success) {
        // Remove the order row from the orders table
        if (draggedElement && draggedElement.dataset.orderId == orderId) {
          draggedElement.remove();
        }
        
        // Update the route row to show it now has orders
        updateRouteRow(routeId, targetRow);
        
        showMessage(`Order #${getOrderNumber(orderId)} added to Route R-${routeId}`, 'success');
        
        // Re-setup draggable routes since this route might now be draggable
        setTimeout(() => {
          setupDraggableRoutes();
          routeOrdersManager.refresh();
        }, 100);
        
      } else {
        showMessage(result.message || 'Failed to add order to route', 'error');
      }

    } catch (error) {
      console.error('Assignment error:', error);
      showMessage('Network error occurred', 'error');
    } finally {
      if (spinner) spinner.style.display = 'none';
    }
  }

  // Assign route to rider (Step 2)
  async function assignRouteToRider(routeId, riderId, targetRow) {
    const spinner = targetRow.querySelector('.loading-spinner');
    if (spinner) spinner.style.display = 'inline-block';

    try {
      console.log(`Assigning route ${routeId} to rider ${riderId}`);
      
      const response = await fetch('../api/assign_route_to_rider.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          route_id: parseInt(routeId),
          rider_id: parseInt(riderId)
        })
      });

      const result = await response.json();
      console.log('Route assignment result:', result);

      if (result.success) {
        // Update the route row to show the new rider assignment
        if (draggedElement && draggedElement.dataset.routeId == routeId) {
          const riderCell = draggedElement.querySelector('td:nth-child(2)');
          if (riderCell) {
            riderCell.textContent = getRiderName(riderId);
          }
        }
        
        // Update rider's order count
        updateRiderOrderCount(riderId, targetRow);
        
        showMessage(`Route R-${routeId} assigned to ${getRiderName(riderId)}`, 'success');
        
        // Refresh route orders
        routeOrdersManager.refresh();
        
      } else {
        showMessage(result.message || 'Failed to assign route to rider', 'error');
      }

    } catch (error) {
      console.error('Route assignment error:', error);
      showMessage('Network error occurred', 'error');
    } finally {
      if (spinner) spinner.style.display = 'none';
    }
  }

  // HELPER FUNCTIONS

  // Update route row after adding orders
  function updateRouteRow(routeId, routeRow) {
    const ordersCountSpan = routeRow.querySelector('.orders-count');
    if (ordersCountSpan) {
      const currentCount = parseInt(ordersCountSpan.textContent) || 0;
      ordersCountSpan.textContent = currentCount + 1;
      
      // Add "Ready" indicator if not present
      if (!routeRow.querySelector('.assigned-indicator')) {
        const readyIndicator = document.createElement('span');
        readyIndicator.className = 'assigned-indicator';
        readyIndicator.textContent = 'Ready';
        ordersCountSpan.parentNode.appendChild(readyIndicator);
      }
      
      // Make the route draggable now that it has orders
      routeRow.draggable = true;
      routeRow.classList.add('draggable-row');
    }
  }

  // Update rider order count
  function updateRiderOrderCount(riderId, riderRow) {
    const orderCountCell = riderRow.querySelector('td:nth-child(2)');
    if (orderCountCell) {
      const currentCount = parseInt(orderCountCell.textContent) || 0;
      orderCountCell.textContent = currentCount + 1;
    }
  }

  // Get order number from dragged element
  function getOrderNumber(orderId) {
    if (draggedElement) {
      const orderNumberCell = draggedElement.querySelector('td:first-child');
      return orderNumberCell ? orderNumberCell.textContent.trim() : orderId;
    }
    return orderId;
  }

  // Get rider name from rider ID
  function getRiderName(riderId) {
    const riderRow = document.querySelector(`#ridersTableBody tr[data-rider-id="${riderId}"]`);
    if (riderRow) {
      const nameCell = riderRow.querySelector('td:first-child');
      return nameCell ? nameCell.textContent.trim() : 'Rider';
    }
    return 'Rider';
  }

  // Initialize all drag and drop functionality
  function initializeDragAndDrop() {
    console.log('Initializing drag and drop functionality...');
    
    setupDraggableOrders();    // Step 1: Orders can be dragged
    setupDraggableRoutes();    // Step 2: Routes can be dragged (if they have orders)
    setupRouteDropTargets();   // Routes accept orders
    setupRiderDropTargets();   // Riders accept routes
    
    console.log('Drag and drop initialization complete');
    console.log('Workflow:');
    console.log('1. Drag orders from Orders table to Routes table');
    console.log('2. Drag routes from Routes table to Riders table');
    console.log('3. Click routes to view their orders in Route Orders panel');
  }

  // Map initialization (enhanced)
  const map = L.map('map', {
    center: [51.5074, -0.1278], 
    zoom: 10, 
    minZoom: 8, 
    maxZoom: 18
  });
  
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
  }).addTo(map);

  const riders = <?php echo json_encode($riders_locations); ?> || [];
  const markers = {};
  
  riders.forEach(loc => {
    const lat = parseFloat(loc.lat || loc.latitude || 0);
    const lng = parseFloat(loc.lng || loc.longitude || 0);
    if (!isFinite(lat) || !isFinite(lng)) return;
    
    const id = loc.rider_id || loc.id;
    const icon = L.divIcon({
      className: '',
      html: `<div style="text-align:center">
        <div style="width:12px;height:12px;background:#10B981;border:2px solid white;border-radius:50%;box-shadow:0 0 0 3px rgba(16,185,129,0.3)"></div>
        <div style="background:#fff;padding:2px 6px;border-radius:6px;margin-top:4px;font-size:11px;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,0.1);color:#374151">${loc.rider_name || 'Rider'}</div>
      </div>`
    });
    
    const marker = L.marker([lat, lng], { icon }).addTo(map);
    
    if (loc.created_at) {
      marker.bindPopup(`
        <div style="text-align:center;">
          <strong>${loc.rider_name || 'Rider'}</strong><br/>
          <small>Last update: ${new Date(loc.created_at).toLocaleString()}</small>
        </div>
      `);
    }
    
    markers[id] = marker;
  });

  // Fit map bounds
  const allCoords = Object.values(markers).map(m => m.getLatLng());
  if (allCoords.length) {
    const londonBounds = L.latLngBounds([[51.28, -0.51], [51.69, 0.33]]);
    map.fitBounds(londonBounds);
    document.getElementById('lastUpdate').innerText = `Last update: ${allCoords.length} riders`;
  } else {
    map.setView([51.5074, -0.1278], 10);
    document.getElementById('lastUpdate').innerText = 'Last update: 0 riders';
  }

  // Map resize handler
  function fixMap() {
    setTimeout(() => map.invalidateSize(), 250);
  }

  // Resizable panels functionality
  function initializeResizablePanels() {
    // Individual panel resizing
    initializePanelResizing();
    
    // Column resizing
    initializeColumnResizing();
    
    console.log('Resizable panels initialized');
  }

  // Initialize individual panel resizing
  function initializePanelResizing() {
    document.querySelectorAll('.resizable-panel').forEach(panel => {
      const resizeHandleV = panel.querySelector('.resize-handle-v');
      const resizeHandleCorner = panel.querySelector('.resize-handle-corner');
      
      if (resizeHandleV) {
        initializeResizeHandle(resizeHandleV, panel, 'vertical');
      }
      
      if (resizeHandleCorner) {
        initializeResizeHandle(resizeHandleCorner, panel, 'both');
      }
    });
  }

  // Initialize column resizing (left/right column split)
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
      document.removeEventListener('mousemove', handleColumnResize);
      document.removeEventListener('mouseup', stopColumnResize);
    }
  }

  // Initialize resize handle for individual panels
  function initializeResizeHandle(handle, panel, direction) {
    let isResizing = false;
    let startX = 0;
    let startY = 0;
    let startWidth = 0;
    let startHeight = 0;
    
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
      e.stopImmediatePropagation();
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
      
      // Trigger map resize if this is the map panel
      if (panel.id === 'mapPanel') {
        setTimeout(() => map.invalidateSize(), 100);
      }
    }
    
    function stopResize() {
      isResizing = false;
      panel.classList.remove('resizing');
      // Save the final size
localStorage.setItem(
  'panel-size-' + panel.id,
  JSON.stringify({ width: panel.style.width, height: panel.style.height })
);

      document.removeEventListener('mousemove', handleResize);
      document.removeEventListener('mouseup', stopResize);
    }
  }
// Default panel sizes (first-time)
const defaultGridColumns = '60% 40%';

const defaultSizes = {
  // Left Column Panels
  ridersPanel:     { width: '100%', height: '300px' },
  ordersPanel:     { width: '100%', height: '300px' },
  unassignedPanel: { width: '100%', height: '250px' },
  // Right Column Panels
  mapPanel:{ width: '100%', height: '400px' },
  routesPanel: { width: '100%', height: '400px' }
};
// Restore saved or default sizes
function applySavedSizes() {
  document.querySelectorAll('.resizable-panel').forEach(panel => {
    const saved = JSON.parse(localStorage.getItem('panel-size-' + panel.id));
    const fallback = defaultSizes[panel.id] || {};
    panel.style.width  = saved?.width  || fallback.width  || '';
    panel.style.height = saved?.height || fallback.height || '';
  });
}

  // Initialize everything when page loads
  document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM loaded, initializing...');
      applySavedSizes(); 
    initializeDragAndDrop();

    initializeResizablePanels();
    fixMap();
    
    // Initialize route orders manager
    setTimeout(() => {
      routeOrdersManager.init();
    }, 100);
  });

  // Map event handlers
  window.addEventListener('load', fixMap);
  window.addEventListener('resize', fixMap);
</script>
</body>
</html>