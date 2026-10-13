<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-students">
      <div class="page-header">
        <div>
          <h2>Student Health Records</h2>
          <p>Basic student info is registered separately via "+ Add Student". Use "Edit" on a row to update a student's info, or "Profile" to view their full record.</p>
        </div>
        <button class="btn-primary" onclick="openAddStudent()">+ Add Student</button>
      </div>

      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" id="search-students" placeholder="Search by name or ID..." oninput="filterStudents()">
        </div>
        <select id="filter-grade" onchange="filterStudents()">
            <option value="">All Levels</option>
            <option value="Daycare">Daycare</option>
            <option value="Kinder">Kinder</option>
            <option value="Grade 1">Grade 1</option>
            <option value="Grade 2">Grade 2</option>
            <option value="Grade 3">Grade 3</option>
            <option value="Grade 4">Grade 4</option>
            <option value="Grade 5">Grade 5</option>
            <option value="Grade 6">Grade 6</option>
            <option value="Grade 7">Grade 7</option>
            <option value="Grade 8">Grade 8</option>
            <option value="Grade 9">Grade 9</option>
            <option value="Grade 10">Grade 10</option>
            <option value="Grade 11">Grade 11</option>
            <option value="Grade 12">Grade 12</option>
      
        </select>
      </div>

      <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Student Name</th>
            <th>Grade Level</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tbody-students"></tbody>
      </table>
      </div>
    </div>
