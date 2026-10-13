<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Offense Categories (masterlist) — lives under the System section.
 * Used by Administrator and Guidance Staff. The Severity Level and Default
 * Sanction set here are pulled into every "Record Offense" form.
 */
?>
<div class="page" id="page-offense-categories">
  <div class="page-header">
    <div>
      <h2>Offense Categories</h2>
      <p>Manage the masterlist of offense categories, their severity level, and default sanction.</p>
    </div>
    <button class="btn-primary" onclick="openAddCategory()">+ Add Category</button>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <input type="text" id="search-categories" placeholder="Search category..." oninput="filterOffenseCategories()">
    </div>
    <select id="filter-category-severity" onchange="filterOffenseCategories()">
      <option value="">All Severities</option>
      <option value="Minor">Minor</option>
      <option value="Moderate">Moderate</option>
      <option value="Major">Major</option>
      <option value="Severe">Severe</option>
    </select>
    <select id="filter-category-status" onchange="filterOffenseCategories()">
      <option value="active">Active</option>
      <option value="inactive">Inactive</option>
      <option value="">All Status</option>
    </select>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Category</th>
          <th>Severity</th>
          <th>Default Sanction</th>
          <th>Description</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="tbody-categories"></tbody>
    </table>
  </div>
</div>

<!-- Add / Edit Offense Category -->
<div class="modal-overlay" id="categoriesModal">
  <div class="modal-card" style="max-width:640px">
    <div class="modal-header">
      <h2 id="categoryModalTitle">Add Offense Category</h2>
      <button onclick="closeCategoryModal()">&#x2715;</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <input type="hidden" id="cat-id">
        <div class="form-group full">
          <label>Category Name <span style="color:#c0392b">*</span></label>
          <input type="text" id="cat-name" placeholder="e.g. Bullying / Harassment" oninput="this.value = capitalizeWords(this.value)">
        </div>
        <div class="form-group">
          <label>Severity Level <span style="color:#c0392b">*</span></label>
          <select id="cat-severity">
            <option value="Minor">Minor</option>
            <option value="Moderate">Moderate</option>
            <option value="Major">Major</option>
            <option value="Severe">Severe</option>
          </select>
        </div>
        <div class="form-group">
          <label>Default Sanction</label>
          <input type="text" id="cat-sanction" placeholder="e.g. Parent conference + written warning">
        </div>
        <div class="form-group full">
          <label>Description</label>
          <input type="text" id="cat-description" placeholder="What counts under this category..." oninput="applyLiveSentenceCap(this)">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeCategoryModal()">Cancel</button>
      <button class="btn-primary" id="catSaveBtn" onclick="saveOffenseCategory()">+ Add Category</button>
    </div>
  </div>
</div>
