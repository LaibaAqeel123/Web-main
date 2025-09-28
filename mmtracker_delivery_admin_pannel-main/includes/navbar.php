<?php
validUser();
$logo_path = SITE_URL . "assets/images/logo.png";
?>
<div class="flex">
<nav class="bg-gray-800 w-64 min-h-screen flex flex-col transition-all duration-300 hover:w-72">
    <div class="flex items-center justify-center h-20 border-b border-gray-700">
        <a href="<?php echo SITE_URL; ?>" class="p-2 hover:opacity-80 transition-opacity">
            <img class="h-10 w-auto" src="<?php echo $logo_path; ?>" alt="<?php echo SITE_NAME; ?> Logo">
        </a>
    </div>
    
    <div class="mt-6 flex-1 flex flex-col gap-2 px-3">
        <a href="<?php echo SITE_URL; ?>pages/dashboard.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'dashboard.php') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            <span class="truncate">Dashboard</span>
        </a>

        <?php if (isSuperAdmin()): ?>
        <a href="<?php echo SITE_URL; ?>pages/companies/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <span class="truncate">Companies</span>
        </a>
        <?php endif; ?>

        <?php if (isSuperAdmin() || isAdmin()): ?>
        <a href="<?php echo SITE_URL; ?>pages/products/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'products') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
            </svg>
            <span class="truncate">Products</span>
        </a>
        <?php endif; ?>

        <?php if (isSuperAdmin() || isAdmin()): ?>
        <a href="<?php echo SITE_URL; ?>pages/warehouses/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'warehouses') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
            </svg>
            <span class="truncate">Warehouses</span>
        </a>
        <?php endif; ?>

        <a href="<?php echo SITE_URL; ?>pages/orders/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'orders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
            </svg>
            <span class="truncate">Orders</span>
        </a>

        <?php if (isSuperAdmin() || isAdmin()): ?>
        <a href="<?php echo SITE_URL; ?>pages/organizations/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'organizations') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <span class="truncate">Organizations</span>
        </a>
        <?php endif; ?>

        <a href="<?php echo SITE_URL; ?>pages/manifests/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'manifests') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
            </svg>
            <span class="truncate">Routes</span>  <!-- Changed from "Manifests" to "Routes" -->
        </a>

        <a href="<?php echo SITE_URL; ?>pages/apikeys/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'apikeys') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
            </svg>
            <span class="truncate">API Keys</span>
        </a>

        <?php if (isSuperAdmin() || isAdmin()): ?>
        <a href="<?php echo SITE_URL; ?>pages/riders/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'riders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            <span class="truncate">Drivers</span>
        </a>
        <?php endif; ?>

        <?php if (isSuperAdmin() || isAdmin()): ?>
        <a href="<?php echo SITE_URL; ?>pages/users/index.php"
            class="<?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> group flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            <span class="truncate"><?php echo isAdmin() ? "Admins" : "Users"; ?></span>
        </a>
        <?php endif; ?>
    </div>
</nav>