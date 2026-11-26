<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
Auth::logout();
vsRedirect(SITE_URL . '/index.php');
