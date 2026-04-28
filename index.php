<?php
$method = $_GET['method'] ?? 'home';

// 1. 引入 Header
include "includes/header.php"; 
?>

<div class="container-fluid">
  <div class="row">
    <div class="col-md-3">
      <?php include "includes/sidebar.php"; ?>
    </div>

    <main class="col-md-9">
      <?php
      switch($method){
        case "login_message":
          include "login_message.php";
          break;
        default:
          include "admin/dashboard.php";
          break;
      }
      ?>
    </main>
  </div>
</div>

<?php 
// 4. 引入頁尾
include "includes/footer.php"; 
?>