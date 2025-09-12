<?php
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . SITE_URL . "/admin/login.php");
        exit();
    }
}
