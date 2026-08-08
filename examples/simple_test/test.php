<?php

require_once "../../inc/b4b31/string_monger.php";

function id_not_found($id, $f){
    echo "\nid $id not found in $f\n";
    return false;
}

$x = new b4b31\string_monger('./1033','id_not_found');

var_dump($x(1));
var_dump($x(3));
var_dump($x(18));
var_dump($x(19));
