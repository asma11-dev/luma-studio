<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = trim((string)readline('Owner name: '));
$email = strtolower(trim((string)readline('Owner email: ')));
$password = (string)readline('Password (at least 12 characters): ');
if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Please enter a name, valid email, and password with at least 12 characters.\n"); exit(1);
}
$q = db()->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'owner')");
try { $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]); }
catch (PDOException $e) { fwrite(STDERR, "Could not create owner. The email may already be in use.\n"); exit(1); }
fwrite(STDOUT, "Owner account created. Sign in at /?page=login\n");
