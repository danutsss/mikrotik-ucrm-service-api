<?php

declare(strict_types = 1);

error_reporting(E_ALL);

use MikrotikService\Service\OptionsManager;
use MikrotikService\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

// Ensure that user is logged in and has permission to view IP Addresses.
$security = UcrmSecurity::create();
$user = $security -> getUser();

$optionsManager = new OptionsManager();
$pluginData = $optionsManager -> load();

/** Functions */
function multiCidrToRange($cidrs) {
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

$IPs = multiCidrToRange($pluginData -> ipAddresses);


/** Network API "GET" Devices */
$ipAddress = (isset($_GET['ipAddress']) ? $_GET['ipAddress'] : "NOT_SET");

// Get collection of all Devices.
$nmsDevices = UcrmApi::doRequest('devices/discovered') ?: [    'ipAddress' => $ipAddress   ];

?>


<html>
    <head>
        <!-- CSS only -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    </head>

    <body>
        <div>
            <table class = "table table-responsive table-inverse table-hover table-striped text-center">
                <thead>
                    <tr>
                        <th>IP</th>
                        <th>Folosit</th>
                    </tr>
                </thead>
                <?php
                    foreach($IPs as $IP) {
                        echo "<tr>";
                        echo "<td>". $IP ."</td>";
                        echo "<td style = 'font-weight: bold; color: red;'>";
                        foreach($nmsDevices as $nmsDevice) {
                            if($IP == $nmsDevice['ipAddress'])
                                echo "DA";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                ?>
            </table>
        </div>

        <!-- JavaScript Bundle with Popper -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
    </body>
</html>