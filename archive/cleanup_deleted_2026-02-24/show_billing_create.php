<?php
$c=new mysqli('localhost','root','', 'capstone_dental');
if ($c->connect_error) {
    echo 'CONNECT_ERR: ' . $c->connect_error . PHP_EOL;
    exit(1);
}
$res = $c->query('SHOW CREATE TABLE billing');
if ($res) {
    $row = $res->fetch_assoc();
    echo $row['Create Table'] . PHP_EOL;
} else {
    echo 'NO_RESULT: ' . $c->error . PHP_EOL;
}
?>