# Nissan Connect PHP Class
Use the Nissan Connect (was Carwings) API using a simple PHP class.


## Installation

You can just download NissanConnect.class.php and require/include it, or use composer:

    require "gboudreau/nissan-connect-php": "dev-master"

## Usage

```php
require_once 'NissanConnect.class.php';

// All parameters except the first two (username & password) are optional; the default values are shown here
$nissanConnect = new NissanConnect('you@something.com', 'your_password_here', 'America/New_York', NissanConnect::COUNTRY_US);

// Change to TRUE to log debugging information into your PHP error log
$nissanConnect->debug = FALSE;

try {
    $result = $nissanConnect->getStatus();
    var_dump($result);
    
    // Start charging
    $nissanConnect->startCharge();
    
    // Start Climate Control
    $nissanConnect->startClimateControl();
    
    // Stop Climate Control
    $nissanConnect->stopClimateControl();
} catch (Exception $ex) {
    echo "An error occurred: " . $ex->getMessage();
}
```

Example output (`var_dump`ed result of call to `getStatus`):

```php
object(stdClass)#9 (18) {
  ["LastUpdated"]=>
  string(16) "2016-02-21 15:24"
  ["PluggedIn"]=>
  bool(true)
  ["Charging"]=>
  bool(false)
  ["ChargingMode"]=>
  string(12) "NO"
  ["BatteryCapacity"]=>
  int(100)
  ["BatteryRemainingAmount"]=>
  int(92)
  ["BatteryRemainingAmountWH"]=>
  NULL
  ["BatteryRemainingAmountkWH"]=>
  NULL
  ["TimeRequiredToFull"]=>
  NULL
  ["TimeRequiredToFull200"]=>
  NULL
  ["TimeRequiredToFull200_6kW"]=>
  NULL
  ["CruisingRangeAcOn"]=>
  float(90.4)
  ["CruisingRangeAcOff"]=>
  float(115.712)
  ["CruisingRangeUnit"]=>
  string(2) "km"
}
```

## Acknowledgements

Thanks to [Joshua Perry](https://github.com/joshperry) for his [Carwings protocol reference](https://github.com/joshperry/carwings) which I used as a reference to refactor my [One-click access to LEAF](https://github.com/gboudreau/LEAF_Carwings_EasyAccess) by creating this class.
Thank to [BenWoodford](https://github.com/BenWoodford) for [BenWoodford/LeafAPI.md](https://gist.github.com/BenWoodford/141ca350445e994e69a70aabfb6db942)

Developed mainly using a free open-source license of  
![PHPStorm](https://d3uepj124s5rcx.cloudfront.net/items/0V0z2p0e0K1D0F3t2r1P/logo_PhpStorm.png)  
kindly provided by [JetBrains](http://www.jetbrains.com/). Thanks guys!
