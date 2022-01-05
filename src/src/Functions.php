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

function randomGUID() {
    if(function_exists('com_create_guid') === true) {
        return trim(com_create_guid(), '{}');
    }

    return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
}

function cidrToRange($cidrs) {
    $return_array = array();
    $cidrs = explode(",", str_replace(" ", "", $cidrs));

    foreach($cidrs as $cidr) {
        $begin_end = explode("/", $cidr);
        $ip_exp = explode(".", $begin_end[0]);
        $range[0] = long2ip((ip2long($begin_end[0])) & ((-1 << (32 - (int) $begin_end[1]))));
        $range[1] = long2ip((ip2long($range[0])) + pow(2, (32 - (int) $begin_end[1])) - 1);

        unset($ip_exp[3]);
        
        $ip_prefix = implode(".", $ip_exp);
        $count = str_replace($ip_prefix . ".", "", $range[0]);
        $ncount = 0;

        while($count <= (str_replace($ip_prefix, "", $range[0]) + str_replace($ip_prefix . ".", "", $range[1]))) {
            $return_array[] = $ip_prefix . "." . $count;
            $count ++;
            $ncount ++;
        }

        $begin_end = false;
        $ip_exp = false;
        $range = false;
        $ip_prefix = false;
        $count = false;
    }
    return $return_array;
}