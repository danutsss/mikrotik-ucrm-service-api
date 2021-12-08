<?php

function generateRandPassword() {
    $alphabet = "abcdefghijklmnopqrstuwxyzABCDEFGHIJKLMNOPQRSTUWXYZ0123456789";

    /** Remember to declare $pass as an array. */
    $pass = array();

    /** Put the length -1 in cache. */
    $alphaLength = strlen($alphabet) - 1;
    
    for($i = 0; $i < 8; $i ++) {
        $n = rand(0, $alphaLength);
        $pass[] = $alphabet[$n];
    }

    /** Turn the array into string. */
    return implode($pass);
}

function randomIPFromRange($rangeStart, $rangeEnd) {
    return long2ip(random_int(ip2long($rangeStart), ip2long($rangeEnd)));
}