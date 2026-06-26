<?php
require_once __DIR__ . '/../lib/feeder.php';
feeder_run($_GET['token'] ?? '');
