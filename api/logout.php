<?php
require_once __DIR__ . '/bootstrap.php';
require_method('POST');
session_destroy_cookie();
json_out(['ok' => true]);
