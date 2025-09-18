1. CSV Orders Upload
Implement CSV upload to create orders:

Each line in the CSV represents a product.

A common Order ID groups multiple products into a single order.

Example:

Row 1: Order 1 - Product 1

Row 2: Order 1 - Product 2

Row 3: Order 1 - Product 3

CSV column matching:

If CSV columns don't match expected fields, provide a UI to map/match columns manually before upload.

2. Order Proofs (Images & Signatures)
Add Signature as an additional proof option along with Image:

On app, allow customer to sign on order delivery.

Admin should have options to enable/disable signature requirement:

Globally (for all orders).

Per specific order.

Admin should have options to enable/disable Order Images upload by rider:

Globally (for all orders).

Per specific order.

3. PDF Generation for Orders
Automatically create a PDF proof on order delivery:

Should include:

Company details (organization from where order is submitted).

Customer details.

Order details.

Delivery Proof (Images and Signature if enabled).

Latitude and Longitude coordinates.

Order delivery date.

Should NOT include:

Rider details.

Attach the generated PDF and send it in the order delivery email.

4. Customer Auto-fill and Address Handling
Auto-insert customer details while creating an order:

User can search for existing customers by name or email.

If found, auto-fill customer fields.

Under the address field:

Add a checkbox: "Deliver to different address".

If checked, show additional address fields.

Allow setting this new address as default for future orders.

5. Order Filters & Search
Implement filtering and searching for orders:

Filter by date range.

Filter by customer name or email.

Allow search across all order fields.

6. Organization Management
Add a new "Organizations" tab/module:

Create multiple organizations with:

Name

Address

While creating an order:

Select from which organization the order is being delivered.

Include this organization in the order PDF.

7. Inventory and Extra Items Management
Implement Inventory management system with extra items tracking:

On rider app:

When scanning products, if quantity exceeds required amount, automatically log the extra scanned items.

Example: If Order O1 needs 3x Product P8, and 5 are scanned, 2 are marked as extra items.

Admin side:

Admin can view logs of all extra items the rider picked up.

Failed deliveries:

All products from a failed order should be automatically added to extra items.

Delivery manifest:

When a rider submits a delivery manifest, automatically offload all extra products.

Force Delivery Options:

If an item is missing, damaged, or rejected by customer:

Allow force delivery without adding it to extra products.

Or, add rejected/damaged products into extra products.

Allow quantity adjustments (increase/decrease).

Enable Partial Delivery option if quantity is missing.

8. Scanner Improvements
Keep the product scanner always open (embedded scanner view).

Improve scanner reliability for continuous scanning.

9. Order Location Tracking
Save Latitude and Longitude coordinates:

Whether the delivery was successful or failed, always save lat/long.

10. Address Validation
Integrate API for address suggestions:

When user types address, show dropdown of valid addresses from API.

Force Insert Address:

If address validator rejects the address, allow admin/user to force insert the address anyway.

11. Logging
Improve system logs:

Log all important events and actions:

Order creation

Order status updates

Scans

Inventory updates

Deliveries

Address force insertions