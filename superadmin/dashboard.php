<?php
session_start();
include 'navbar.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">

    <style>
    .custom-card {
      width: 250px;
      height: 150px;
      padding-top: 15px;
      padding-left: 10px;
      border-radius: 15px;
      box-shadow: 0 4px 8px rgba(0,0,0,0.2);
      transition: transform 0.3s;
    }
    .custom-card:hover {
      transform: translateY(-5px);
    }
    .custom-card .card-title {
      font-weight: bold;
      color: #333;
    }
    .custom-card .card-text {
      color: #555;
    }
    </style>
</head>

<body>
    <div class="container mt-5 d-flex gap-5 justify-content-center">
    <div class="card custom-card">
        <div class="card-body">
        <h5 class="card-title">Total Students</h5>
        <p class="card-text">NUMBER</p>
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-body">
        <h5 class="card-title">Active</h5>
        <p class="card-text">NUMBER</p>
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-body">
        <h5 class="card-title">Inactive</h5>
        <p class="card-text">NUMBER</p>
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-body">
        <h5 class="card-title">On leave</h5>
        <p class="card-text">NUMBER</p>
        </div>
    </div>

    </div>


<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>