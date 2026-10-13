<?php
require_once "config/session_init.php";
session_start();
session_unset();
session_destroy();
// Use a relative redirect so the app works at any URL path,
// not just at the hardcoded /Colegio/ location.
header("Location: index.php");
exit();