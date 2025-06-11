<?php
// includes/header.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();

}

?>

<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <title>Jorepukuria School Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    var modal = new bootstrap.Modal(document.getElementById('exampleModal'));
    document.getElementById('openModalBtn').addEventListener('click', function() {
      modal.show();
    });
  </script>
</head>
<body>
