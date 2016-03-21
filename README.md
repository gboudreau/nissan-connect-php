# Nissan Connect PHP Class
Use the Nissan Connect (was Carwings) API using a simple PHP class.


## Installation

You can just download NissanConnect.class.php and require/include it, or use composer:

    require "gboudreau/nissan-connect-php": "dev-master"

## Usage

```php
require_once 'NissanConnect.class.php';

// All parameters except the first two (username & password) are optional; the default values are shown here
// If you don't have the mcrypt PHP extension, you can use a web-service to encrypt your password. Simply change the last parameter to NissanConnect::ENCRYPTION_OPTION_WEBSERVICE
$nissanConnect = new NissanConnect('you@something.com', 'your_password_here', 'America/New_York', NissanConnect::COUNTRY_US, NissanConnect::ENCRYPTION_OPTION_MCRYPT);

// Change to TRUE to log debugging information into your PHP error log
$nissanConnect->debug = FALSE;

// How long (in seconds) should we wait for the result before giving up. Only used when $waitForResult = TRUE
$nissanConnect->maxWaitTime = 290;

try {
    $result = $nissanConnect->getStatus();
    var_dump($result);
    
    // Start charging
    $nissanConnect->startCharge();
    
    // Should we wait until the command result is known, before returning? Enabling this will wait until the car executed the command, and returned the response, which can sometimes take a few minutes.
    $waitForResult = FALSE; 
    
    // Start Climate Control
    $nissanConnect->startClimateControl($waitForResult);
    
    // Stop Climate Control
    $nissanConnect->stopClimateControl($waitForResult);
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
  string(12) "NOT_CHARGING"
  ["BatteryCapacity"]=>
  int(12)
  ["BatteryRemainingAmount"]=>
  int(9)
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
  ["RemoteACRunning"]=>
  bool(false)
  ["RemoteACLastChanged"]=>
  string(16) "2016-02-21 15:24"
  ["ACStartStopURL"]=>
  NULL
  ["ACDurationBatterySec"]=>
  int(900)
  ["ACDurationPluggedSec"]=>
  int(7200)
}
```

## Acknowledgements

Thanks to [Joshua Perry](https://github.com/joshperry) for his [Carwings protocol reference](https://github.com/joshperry/carwings) which I used as a reference to refactor my [One-click access to LEAF](https://github.com/gboudreau/LEAF_Carwings_EasyAccess) by creating this class.
