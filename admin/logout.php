<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

unset($_SESSION['user'], $_SESSION['admin_last_activity']);
session_regenerate_id(true);
flash('success', 'Anda berhasil keluar dari Dashboard Admin.');
redirect('login.php');
