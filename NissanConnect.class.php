<?php
/*
Copyright 2016-2018 Guillaume Boudreau
Source: https://github.com/gboudreau/nissan-connect-php

This class is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This class is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

class NissanConnect {

    /* Those are the possible values for the constructor 'country' parameter. */
    const COUNTRY_CANADA = 'CA';
    const COUNTRY_US = 'US';
    const COUNTRY_EU = 'NE'; // @TODO
    const COUNTRY_AUSTRALIA = 'NMA'; // @TODO
    const COUNTRY_JAPAN = 'NML'; // @TODO

    /* Those error code will be used in Exception that can be thrown when errors occur. */
    /* @deprecated */
    const ERROR_CODE_MISSING_RESULTKEY = 400;
    const ERROR_CODE_LOGIN_FAILED = 403;
    /* @deprecated */
    const ERROR_CODE_INVALID_RESPONSE = 405;
    const ERROR_CODE_NOT_JSON = 406;
    /* @deprecated */
    const ERROR_CODE_TIMEOUT = 408;

    /* @deprecated */
    const STATUS_QUERY_OPTION_NONE   = 0;
    /* @deprecated */
    const STATUS_QUERY_OPTION_ASYNC  = 1;
    /* @deprecated */
    const STATUS_QUERY_OPTION_CACHED = 2;

    /* @deprecated */
    const ENCRYPTION_OPTION_OPENSSL    = 0;
    /* @deprecated */
    const ENCRYPTION_OPTION_WEBSERVICE = 1;

    /* @var boolean Enable to echo debugging information into the PHP error log. */
    public $debug = FALSE;

    private $baseURL = 'https://icm.infinitiusa.com/NissanLeafProd/rest/';

    private $config = NULL;

    /* @var boolean Should we retry to login, if the API return us a 404 error. */
    private $shouldRetry = TRUE;

    /**
     * NissanConnect constructor.
     *
     * @param string $username         The username (or email address) to use to login on the remote API.
     * @param string $password         The password to use to login on the remote API.
     * @param string $tz               The timezone to use for dates. Default value: America/New_York
     * @param string $country          One of the COUNTRY_* constants available in this class. Default value: COUNTRY_US
     * @param int    $encryptionOption Use ENCRYPTION_OPTION_OPENSSL (the default) if you can; otherwise, use ENCRYPTION_OPTION_WEBSERVICE, which will use a remote web-service to encrypt your password. @deprecated
     */
    public function __construct($username, $password, $tz = 'America/New_York', $country = NissanConnect::COUNTRY_US, $encryptionOption = 0) {
        $this->config = new stdClass();
        $this->config->username = $username;
        $this->config->password = $password;
        $this->config->country = strtoupper($country);
        $this->config->vin = '';
        $this->config->authToken = '';
        $this->config->accountID = '';
        date_default_timezone_set($tz);
    }

    /**
     * Start the Climate Control.
     *
     * @param bool $waitForResult Should we wait until the command result is known, before returning? Enabling this will wait until the car executed the command, and returned the response, which can sometimes take a few minutes. @deprecated
     *
     * @return stdClass
     * @throws Exception
     */
    public function startClimateControl($waitForResult = FALSE, $target_temperature = NULL, $target_temperature_unit = 'C') {
        $this->prepare();
        $params = array('executionTime' => date('c'));
        if (!empty($target_temperature)) {
            $params['preACunit'] = $target_temperature_unit;
            $params['preACtemp'] = $target_temperature;
        }
        $result = $this->sendRequest("hvac/vehicles/{$this->config->vin}/activateHVAC", $params);
        return $result;
    }

    /**
     * Stop the Climate Control.
     *
     * @param bool $waitForResult Should we wait until the command result is known, before returning? Enabling this will wait until the car executed the command, and returned the response, which can sometimes take a few minutes. @deprecated
     *
     * @return stdClass
     * @throws Exception
     */
    public function stopClimateControl($waitForResult = FALSE) {
        $this->prepare();
        $result = $this->sendRequest("hvac/vehicles/{$this->config->vin}/deactivateHVAC");
        return $result;
    }

    /**
     * Start charging.
     *
     * @return stdClass Example: {"status": 200, "message": "success"}
     * @throws Exception
     */
    public function startCharge() {
        $this->prepare();
        $result = $this->sendRequest("battery/vehicles/{$this->config->vin}/remoteChargingRequest");
        return $result;
    }

    /**
     * Stop charging.
     *
     * @return stdClass Example: {"status": 200, "message": "success"}
     * @throws Exception
     */
    public function stopCharge() {
        $this->prepare();
        $result = $this->sendRequest("battery/vehicles/{$this->config->vin}/cancelRemoteChargingRequest");
        return $result;
    }

    /**
     * Lock car doors.
     *
     * @param string $pin 4-digit security PIN as configured in the Nissan Connect App
     *
     * @return stdClass
     * @throws Exception
     */
    public function lockDoors(string $pin = '0000') {
        $this->prepare();
        $params = array(
            'remoteRequest' => array(
                'authorizationKey' => $pin
            )
        );
        $result = $this->sendRequest("remote/vehicles/{$this->config->vin}/accounts/{$this->config->accountID}/rdl/createRDL", $params);
        return $result;
    }

    /**
     * Get battery & climate control status.
     *
     * @param int $option Specify one of the STATUS_QUERY_OPTION_* constant. @deprecated
     *
     * @return stdClass
     * @throws Exception
     */
    public function getStatus($option = 0) {
        $this->prepare();

        $response = $this->sendRequest("battery/vehicles/{$this->config->vin}/getChargingStatusRequest", array(), 'GET');

        $result = new stdClass();

        $result->status = $response->status;

        $result->LastUpdated = date('Y-m-d H:i', strtotime($response->batteryRecords->lastUpdatedDateAndTime));

        $result->PluggedIn = ( $response->batteryRecords->pluginState != 'NOT_CONNECTED' );
        $result->ChargingMode = $response->batteryRecords->batteryStatus->batteryChargingStatus;
        $result->Charging = ( $result->ChargingMode != 'NO' );

        $result->BatteryCapacity = (int) $response->batteryRecords->batteryStatus->batteryCapacity;
        if (!empty($response->batteryRecords->batteryStatus->batteryRemainingAmount)) {
            $result->BatteryRemainingAmount = (int) $response->batteryRecords->batteryStatus->batteryRemainingAmount;
        } else {
            $result->BatteryRemainingAmount = NULL;
        }
        if (!empty($response->batteryRecords->batteryStatus->batteryRemainingAmountWH)) {
            $result->BatteryRemainingAmountWH = (float) $response->batteryRecords->batteryStatus->batteryRemainingAmountWH;
        } else {
            $result->BatteryRemainingAmountWH = NULL;
        }
        if (!empty($response->batteryRecords->batteryStatus->batteryRemainingAmountkWH)) {
            $result->BatteryRemainingAmountkWH = (float) $response->batteryRecords->batteryStatus->batteryRemainingAmountkWH;
        } else {
            $result->BatteryRemainingAmountkWH = NULL;
        }
        // SOC = The percentage state of charge (don't work under 5%) -> API Answer is "SOC":{"Display":"---"}}
        if (!empty($response->batteryRecords->batteryStatus->soc)) {
            $result->SOC =  $response->batteryRecords->BatteryStatus->soc->value;
        } else {
            $result->SOC = NULL;
        }
        // Interior temperature (MY2018+)
        if (isset($response->temperatureRecords->inc_temp)) {
            $result->interior_temperature = (float) $response->temperatureRecords->inc_temp;
        }

        foreach (array('timeRequired', 'timeRequired200', 'timeRequired200_6kW') as $var_name) {
            if (empty($response->batteryRecords->{$var_name}->hourRequiredToFull) && empty($response->batteryRecords->{$var_name}->minutesRequiredToFull)) {
                $result->{$var_name} = NULL;
                continue;
            }
            $result->{$var_name} = (object) array(
                'Hours' => (int) $response->batteryRecords->{$var_name}->hourRequiredToFull,
                'Minutes' => (int) $response->batteryRecords->{$var_name}->minutesRequiredToFull
            );
            $result->{$var_name}->Formatted = '';
            if (!empty($result->{$var_name}->Hours)) {
                $result->{$var_name}->Formatted .= $result->{$var_name}->Hours . 'h ';
            }
            if (!empty($result->{$var_name}->Minutes)) {
                $result->{$var_name}->Formatted .= $result->{$var_name}->Minutes . 'm ';
            }
        }

        // Can be Null, under 15km
        if (empty($response->batteryRecords->cruisingRangeAcOn)) {
            $result->CruisingRangeAcOn = NULL;
            $result->CruisingRangeUnit = NULL;
            $result->CruisingRangeUnit = '---';
        } elseif ($this->config->country == NissanConnect::COUNTRY_US) {
            $result->CruisingRangeAcOn = $response->batteryRecords->cruisingRangeAcOn * 0.000621371192;
            $result->CruisingRangeAcOff = $response->batteryRecords->cruisingRangeAcOff * 0.000621371192;
            $result->CruisingRangeUnit = 'miles';
        } else {
            $result->CruisingRangeAcOn = $response->batteryRecords->cruisingRangeAcOn / 1000;
            $result->CruisingRangeAcOff = $response->batteryRecords->cruisingRangeAcOff / 1000;
            $result->CruisingRangeUnit = 'km';
        }

        // @TODO How can we get those..?
        //$response2 = $this->sendRequest("hvac/vehicles/{$this->config->vin}/getHVACStatusRequest", array(), 'GET');
        //
        //$result->RemoteACRunning = ((@$response2->RemoteACRecords->PluginState == 'CONNECTED' || @$response2->RemoteACRecords->OperationResult == 'START_BATTERY') && @$response2->RemoteACRecords->RemoteACOperation != 'STOP');
        //if (isset($response2->RemoteACRecords->ACStartStopDateAndTime)) {
        //    $result->RemoteACLastChanged = date('Y-m-d H:i', strtotime($response2->RemoteACRecords->ACStartStopDateAndTime));
        //} else {
        //    $result->RemoteACLastChanged = NULL;
        //}
        //if (!empty($response2->RemoteACRecords->ACStartStopURL)) {
        //    $result->ACStartStopURL = $response2->RemoteACRecords->ACStartStopURL;
        //} else {
        //    $result->ACStartStopURL = NULL;
        //}
        //if (isset($response2->RemoteACRecords->ACDurationBatterySec)) {
        //    $result->ACDurationBatterySec = (int) $response2->RemoteACRecords->ACDurationBatterySec;
        //} else {
        //    $result->ACDurationBatterySec = FALSE;
        //}
        //if (isset($response2->RemoteACRecords->ACDurationBatterySec)) {
        //    $result->ACDurationPluggedSec = (int) $response2->RemoteACRecords->ACDurationPluggedSec;
        //} else {
        //    $result->ACDurationPluggedSec = FALSE;
        //}

        return $result;
    }

    public function getLocation() {
        return $this->sendRequest("vehicleLocator/vehicles/{$this->config->vin}/refreshVehicleLocator");
    }

    /**
     * Load the VIN, authToken and accountID values, either from disk, if they were saved there by a previous call, or from the remote API, if not.
     *
     * @param bool $skip_local_file Should we skip loading the cached information from the local file, and force a login to obtain them.
     *
     * @return void
     * @throws Exception
     */
    private function prepare($skip_local_file = FALSE) {
        if ($skip_local_file || empty($this->config->vin) || empty($this->config->authToken) || empty($this->config->accountID) || empty($this->config->cookie)) {
            $uid = md5($this->config->username);
            $local_storage_file = sys_get_temp_dir() . "/.nissan-connect-storage-$uid.json";
            if (file_exists($local_storage_file) && !$skip_local_file) {
                $json = @json_decode(file_get_contents($local_storage_file));
                $this->config->vin = @$json->vin;
                $this->config->authToken = @$json->authToken;
                $this->config->accountID = @$json->accountID;
                $this->config->cookie = @$json->cookie;
            }
            if ($skip_local_file || empty($this->config->vin) || empty($this->config->authToken) || empty($this->config->accountID)) {
                $this->login();
                file_put_contents($local_storage_file, json_encode(array('vin' => $this->config->vin, 'authToken' => $this->config->authToken, 'accountID' => $this->config->accountID, 'cookie' => $this->config->cookie)));
                $this->debug("Saving authToken, VIN and accountID into local file $local_storage_file");
            } else {
                $this->debug("Using authToken, VIN and accountID found in local file $local_storage_file");
            }
        }
    }

    /**
     * Login using the user's email address and password, to get the DCMID value needed to make subsequent API calls.
     *
     * @return void
     * @throws Exception
     */
    private function login() {
        $params = array(
            'authenticate' => array(
                'userid' => $this->config->username,
                'password' => $this->config->password,
                'brand-s' => 'N',
                'language-s' => "en",
                'country' => $this->config->country,
            )
        );
        $result = $this->sendRequest('auth/authenticationForAAS', $params);

        if (isset($result->accountID)) {
            $this->config->accountID = $result->accountID;
        }
        if (isset($result->authToken)) {
            $this->config->authToken = $result->authToken;
        }
        if (isset($result->vehicles[0]->uvi)) {
            $this->config->vin = $result->vehicles[0]->uvi;
        }
        if (empty($this->config->vin) || empty($result->accountID) || empty($result->authToken)) {
            throw new Exception("Login failed, or failed to find car VIN, authToken or accountID in response of login request: " . json_encode($result), static::ERROR_CODE_LOGIN_FAILED);
        }
    }

    /**
     * Send an HTTP request to the specified script, and return the JSON-decoded result.
     *
     * @param string $path   Script to send the request to.
     * @param array  $params Query parameters to send with the request.
     * @param string $method GET or POST (default)
     *
     * @return stdClass JSON-decoded response from API.
     * @throws Exception
     */
    private function sendRequest($path, $params = array(), $method = 'POST') {
        $headers = array(
            "Content-Type: application/json; charset=utf-8",
            "API-Key: f950a00e-73a5-11e7-8cf7-a6006ad3dba0"
        );

        if (!empty($this->config->authToken)) {
            $headers[] = "Authorization: {$this->config->authToken}";
        }

        if (!empty($this->config->cookie)) {
            $headers[] = "Cookie: " . $this->config->cookie;
        }

        $url = $this->baseURL . $path;

        $ch = curl_init($url);

        if (!empty($params) || $method == 'POST') {
            $this->debug("Request: POST $url " . json_encode($params));
            curl_setopt($ch, CURLOPT_POSTFIELDS, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        } else {
            $this->debug("Request: GET $url " . json_encode($params));
        }
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, "curlResponseHeaderCallback");
        global $cookies;
        $cookies = array();

        $result = curl_exec($ch);
        if ($result === FALSE) {
            die("Error during request to $url: " . curl_error($ch) . "\n");
        }
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (is_array($cookies) && count($cookies) > 0 && strpos($cookies[0], 'JSESSIONID=') === 0) {
            $this->config->cookie = $cookies[0];
        }

        if ($info['http_code'] !== 200) {
            if (($info['http_code'] == 401 || $info['http_code'] == 404 || $info['http_code'] == 405) && $this->shouldRetry) {
                $this->debug("Request for '$method $url' failed. Response received: " . json_encode($result) . " Will retry.");
                $this->shouldRetry = FALSE; // Don't loop infinitely!
                $this->config->customSessionID = NULL;
                $this->prepare(TRUE);
                return $this->sendRequest($path, $params, $method);
            }
            throw new Exception("Request for '$method $url' failed. Response received: " . json_encode($result), $info['http_code']);
        }

        $json = json_decode($result);
        if ($json) {
            $json->status = $info['http_code'];
            $this->debug("Response: " . json_encode($json));
            return $json;
        }

        throw new Exception("Non-JSON response received for request to '$method $url'. Response received: " . json_encode($result), static::ERROR_CODE_NOT_JSON);
    }

    /**
     * Log debugging information to the PHP error log.
     *
     * @param String $log Text to log.
     *
     * @return void
     */
    private function debug($log) {
        if ($this->debug) {
            $date = date('Y-m-d H:i:s');
            error_log("[$date] [NissanConnect] $log");
        }
    }
}

function curlResponseHeaderCallback($ch, $headerLine) {
    global $cookies;
    if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $headerLine, $cookie) == 1) {
        $cookies[] = $cookie[1];
    }
    return strlen($headerLine); // Needed by curl
}
