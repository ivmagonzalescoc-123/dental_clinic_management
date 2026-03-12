<?php
$passwords = [
    'patient123',
    'patient123',
    'ivan123',
    'admin123',
    'doctor123',
    'secretary123',
];

foreach ($passwords as $password) {
    echo $password . ' => ' . password_hash($password, PASSWORD_BCRYPT) . PHP_EOL;
}
