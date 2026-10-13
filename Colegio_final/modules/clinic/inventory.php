<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-inventory">
      <div class="page-header">
        <div>
          <h2>Medicine Inventory</h2>
          <p>Track stock levels and expiration dates.</p>
        </div>
        <button class="btn-primary" onclick="openAddMedicine()">+ Add Medicine</button>
      </div>

      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" id="search-inventory" placeholder="Search medicine..." oninput="filterInventory()">
        </div>
        <select id="filter-inv-status" onchange="filterInventory()">
          <option value="">All Status</option>
          <option value="Valid">Valid</option>
          <option value="Expiring Soon">Expiring Soon</option>
          <option value="Expired">Expired</option>
          <option value="Expiry Alert">Expiring Soon + Expired</option>
          <option value="Low Stock">Low Stock</option>
          <option value="Out of Stock">Out of Stock</option>
        </select>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Medicine</th>
              <th>Quantity</th>
              <th>Unit</th>
              <th>Expiry Date</th>
              <th>Stock Level</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tbody-inventory"></tbody>
        </table>
      </div>
    </div>
