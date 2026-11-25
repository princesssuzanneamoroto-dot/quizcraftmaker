<?php
require_once __DIR__ . '/db_connect.php';
require_method('POST');

destroy_session();

echo json_encode(['success' => true]);
