<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') { echo json_encode(['ok'=>false,'error'=>'method']); exit; }

$username = trim($_POST['username'] ?? '');
$exclude = trim($_POST['exclude'] ?? ''); // username to exclude (old contact)

if ($username === '') { echo json_encode(['ok'=>true,'exists'=>false]); exit; }

if ($exclude !== '' && $exclude === $username) {
    echo json_encode(['ok'=>true,'exists'=>false]);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$exists = ($res && $res->num_rows > 0);

echo json_encode(['ok'=>true,'exists'=>$exists]);
