<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * About Us — shared by Administrator, Clinic Staff, and Guidance Staff.
 * Static page: system description, development team, acknowledgement.
 */
?>
<div class="page" id="page-about">
  <div class="page-header">
    <div>
      <h2>About Us</h2>
      <p>Web-Based Integrated Clinic and Guidance Office Management System</p>
    </div>
  </div>

  <div class="card about-hero">
    <img class="about-logo" src="images/colegiologo.jpg" alt="Colegio de San Francisco logo">
    <div class="about-body">
      <h3 class="about-heading">About Us</h3>
      <p>The Web-Based Integrated Clinic and Guidance Office Management System is a capstone project developed for Colegio de San Francisco to support the efficient management of student health and guidance records.</p>
      <p>The system aims to provide a centralized platform where authorized Clinic and Guidance Office personnel can securely manage, retrieve, update, and monitor relevant student records. It is designed to improve coordination between the Clinic and Guidance Office, support timely intervention, and reduce the challenges associated with manual record-keeping.</p>
      <p>Through this system, the project aims to contribute to better student health, safety, well-being, and more efficient school services.</p>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Development Team</span></div>
    <div class="about-team">
      <div class="about-member">
        <div class="about-role">Project Adviser</div>
        <div class="about-name">Ms. Marriel J. Bella</div>
      </div>
      <div class="about-member">
        <div class="about-role">Technical Critique</div>
        <div class="about-name">Mr. Edison S. De Los Santos</div>
      </div>
      <div class="about-member">
        <div class="about-role">Researchers</div>
        <div class="about-name">Gene D. Colocado</div>
        <div class="about-name">Enrique Jose D. Delos Reyes</div>
        <div class="about-name">Wallace C. Genuino</div>
      </div>
    </div>
  </div>

  <div class="card about-body">
    <div class="card-header"><span class="card-title">Acknowledgement</span></div>
    <p>The development team would like to express their sincere gratitude to everyone who contributed to the completion of this project. We especially acknowledge our Project Adviser for providing guidance and valuable recommendations throughout the development of the system.</p>
    <p>We also extend our appreciation to our Technical Critic for providing technical insights and suggestions that helped improve the system. We are equally grateful to the researchers and everyone who supported the study and development of the Web-Based Integrated Clinic and Guidance Office Management System for Colegio de San Francisco.</p>
    <p>Finally, we thank Colegio de San Francisco for providing the opportunity to develop this system in support of improved student health, safety, well-being, and school services.</p>
  </div>
</div>
