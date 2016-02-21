<?php
/*
Copyright 2016 Guillaume Boudreau
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
    const COUNTRY_CANADA = 'NCI';
    const COUNTRY_US = 'NNA';

    /* Those error code will be used in Exception that can be thrown when errors occur. */
    const ERROR_CODE_MISSING_RESULTKEY = 400;
    const ERROR_CODE_LOGIN_FAILED = 403;
    const ERROR_CODE_NOT_JSON = 406;
    const ERROR_CODE_TIMEOUT = 408;

    /** @var int How long should we wait, before throwing an exception, when waiting for the car to execute a command. @see $waitForResult parameter in the various function calls. */
    public $maxWaitTime = 290;

    /** @var boolean Enable to echo debugging information into the PHP error log. */
    public $debug = FALSE;

    private $baseURL = 'https://gdcportalgw.its-mo.com/orchestration_1111/gdc/';

    private $resultKey = NULL;
    private $config = NULL;

    /**
     * NissanConnect constructor.
     *
     * @param string $username The username (or email address) to use to login on the remote API.
     * @param string $password The password to use to login on the remote API.
     * @param string $tz The timezone to use for dates. Default value: America/New_York
     * @param string $country One of the COUNTRY_* constants available in this class. Default value: COUNTRY_US
     * @param string $vin If known, you can provide the VIN of the vehicle.  If not specified, it will be loaded by a call to the login API endpoint.
     * @param string $dcmID If known, you can provide the DCMID to use for API calls. If not specified, it will be loaded by a call to the login API endpoint.
     */
    public function __construct($username, $password, $tz = 'America/New_York', $country = NissanConnect::COUNTRY_US, $vin = NULL, $dcmID = NULL) {
        $this->config = new stdClass();
        $this->config->username = $username;
        $this->config->password = $password;
        $this->config->tz = $tz;
        $this->config->country = strtoupper($country);
        $this->config->vin = $vin;
        $this->config->dcmID = $dcmID;
    }

    /**
     * Start the Climate Control.
     *
     * @param bool $waitForResult Should we wait until the command result is known, before returning? Enabling this will wait until the car executed the command, and returned the response, which can sometimes take a few minutes.
     * @return stdClass
     * @throws Exception
     */
    public function startClimateControl($waitForResult=FALSE) {
        $this->prepare();
        $result = $this->sendRequest('ACRemoteRequest.php');
        if ($waitForResult) {
            // Wait until it completes
            return $this->waitUntilSuccess('ACRemoteResult.php');
        }
        return $result;
    }

    /**
     * Stop the Climate Control.
     *
     * @param bool $waitForResult Should we wait until the command result is known, before returning? Enabling this will wait until the car executed the command, and returned the response, which can sometimes take a few minutes.
     * @return stdClass
     * @throws Exception
     */
    public function stopClimateControl($waitForResult=FALSE) {
        $this->prepare();
        $result = $this->sendRequest('ACRemoteOffRequest.php');
        if ($waitForResult) {
            // Wait until it completes
            return $this->waitUntilSuccess('ACRemoteOffResult.php');
        }
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
        $result = $this->sendRequest('BatteryRemoteChargingRequest.php');
        return $result;
    }

    /**
     * Get battery & climate control status.
     *
     * @param bool $cached Should we return the result of the last query, instead of making a new query?
     * @return stdClass
     * @throws Exception
     */
    public function getStatus($cached=FALSE) {
        $this->prepare();
        if (!$cached) {
            $this->sendRequest('BatteryStatusCheckRequest.php');
            $this->waitUntilSuccess('BatteryStatusCheckResultRequest.php');
        }

        $response = $this->sendRequest('BatteryStatusRecordsRequest.php');
        if ($response->BatteryStatusRecords->OperationResult != "START") {
            throw new Exception("Invalid 'OperationResult' received in call to 'BatteryStatusRecordsRequest.php': " . $response->BatteryStatusRecords->OperationResult, static::ERROR_CODE_LOGIN_FAILED);
        }

        $response2 = $this->sendRequest('RemoteACRecordsRequest.php');
        if ($response2->RemoteACRecords->OperationResult != "START") {
            throw new Exception("Invalid 'OperationResult' received in call to 'RemoteACRecordsRequest.php': " . $response->RemoteACRecords->OperationResult, static::ERROR_CODE_LOGIN_FAILED);
        }

        $result = new stdClass();

        $result->LastUpdated = date('Y-m-d H:i', strtotime($response->BatteryStatusRecords->OperationDateAndTime));

        $result->PluggedIn = ( $response->BatteryStatusRecords->PluginState != 'NOT_CONNECTED' );
        $result->Charging = ( $response->BatteryStatusRecords->BatteryStatus->BatteryChargingStatus != 'NOT_CHARGING' );

        $result->BatteryCapacity = (int) $response->BatteryStatusRecords->BatteryStatus->BatteryCapacity;
        if (!empty($response->BatteryStatusRecords->BatteryStatus->BatteryRemainingAmount)) {
            $result->BatteryRemainingAmount = (int) $response->BatteryStatusRecords->BatteryStatus->BatteryRemainingAmount;
        } else {
            $result->BatteryRemainingAmount = NULL;
        }
        if (!empty($response->BatteryStatusRecords->BatteryStatus->BatteryRemainingAmountWH)) {
            $result->BatteryRemainingAmountWH = (float) $response->BatteryStatusRecords->BatteryStatus->BatteryRemainingAmountWH;
        } else {
            $result->BatteryRemainingAmountWH = NULL;
        }
        if (!empty($response->BatteryStatusRecords->BatteryStatus->BatteryRemainingAmountkWH)) {
            $result->BatteryRemainingAmountkWH = (float) $response->BatteryStatusRecords->BatteryStatus->BatteryRemainingAmountkWH;
        } else {
            $result->BatteryRemainingAmountkWH = NULL;
        }

        foreach (array('TimeRequiredToFull', 'TimeRequiredToFull200', 'TimeRequiredToFull200_6kW') as $var_name) {
            if (empty($response->BatteryStatusRecords->{$var_name}->HourRequiredToFull) && empty($response->BatteryStatusRecords->{$var_name}->MinutesRequiredToFull)) {
                $result->{$var_name} = NULL;
                continue;
            }
            $result->{$var_name} = (object) array(
                'Hours' => (int) $response->BatteryStatusRecords->{$var_name}->HourRequiredToFull,
                'Minutes' => (int) $response->BatteryStatusRecords->{$var_name}->MinutesRequiredToFull
            );
            $result->{$var_name}->Formatted = '';
            if (!empty($result->{$var_name}->Hours)) {
                $result->{$var_name}->Formatted .= $result->{$var_name}->Hours . 'h ';
            }
            if (!empty($result->{$var_name}->Minutes)) {
                $result->{$var_name}->Formatted .= $result->{$var_name}->Minutes . 'm ';
            }
        }

        if ($this->config->country == 'US') {
            $result->CruisingRangeAcOn = $response->BatteryStatusRecords->CruisingRangeAcOn * 0.000621371192;
            $result->CruisingRangeAcOff = $response->BatteryStatusRecords->CruisingRangeAcOff * 0.000621371192;
            $result->CruisingRangeUnit = 'miles';
        } else {
            $result->CruisingRangeAcOn = $response->BatteryStatusRecords->CruisingRangeAcOn / 1000;
            $result->CruisingRangeAcOff = $response->BatteryStatusRecords->CruisingRangeAcOff / 1000;
            $result->CruisingRangeUnit = 'km';
        }

        $result->RemoteACRunning = ($response2->RemoteACRecords->RemoteACOperation == 'START');
        $result->RemoteACLastChanged = date('Y-m-d H:i', strtotime($response2->RemoteACRecords->ACStartStopDateAndTime));
        if (!empty($response2->RemoteACRecords->ACStartStopURL)) {
            $result->ACStartStopURL = $response2->RemoteACRecords->ACStartStopURL;
        } else {
            $result->ACStartStopURL = NULL;
        }
        $result->ACDurationBatterySec = (int) $response2->RemoteACRecords->ACDurationBatterySec;
        $result->ACDurationPluggedSec = (int) $response2->RemoteACRecords->ACDurationPluggedSec;

        return $result;
    }

    /**
     * Load the VIN and DCMID values, either from disk, if they were saved there by a previous call, or from the remote API, if not.
     *
     * @throws Exception
     */
    private function prepare() {
        if (empty($this->config->vin) || empty($this->config->dcmID)) {
            $uid = md5($this->config->username);
            $local_storage_file = "/tmp/.nissan-connect-storage-$uid.json";
            if (file_exists($local_storage_file)) {
                $json = @json_decode(file_get_contents($local_storage_file));
                $this->config->vin = @$json->vin;
                $this->config->dcmID = @$json->dcmid;
            }
            if (empty($this->config->vin) || empty($this->config->dcmID)) {
                $this->login();
                file_put_contents($local_storage_file, json_encode(array('vin' => $this->config->vin, 'dcmid' => $this->config->dcmID)));
                $this->debug("Saving DCMID and VIN into local file $local_storage_file");
            } else {
                $this->debug("Using DCMID and VIN found in local file $local_storage_file");
            }
        }
    }

    /**
     * Login using the user's email address and password, to get the DCMID value needed to make subsequent API calls.
     *
     * @throws Exception
     */
    private function login() {
        $params = array('Password' => $this->config->password);
        $result = $this->sendRequest('UserLoginRequest.php', $params);

        if (isset($result->CustomerInfo->VehicleInfo->DCMID)) {
            $this->config->dcmID = $result->CustomerInfo->VehicleInfo->DCMID;
        }
        if (isset($result->CustomerInfo->VehicleInfo->VIN)) {
            $this->config->vin = $result->CustomerInfo->VehicleInfo->VIN;
        }
        if (empty($this->config->vin) || empty($this->config->dcmID)) {
            throw new Exception("Login failed, or failed to find car VIN and DCMID in response of login request: " . json_encode($result), static::ERROR_CODE_LOGIN_FAILED);
        }
    }

    /**
     * Send an HTTP GET request to the specified script, and return the JSON-decoded result.
     *
     * @param String $path Script to send the request to.
     * @param array $params Query parameters to send with the request.
     * @return stdClass JSON-decoded response from API.
     * @throws Exception
     */
    private function sendRequest($path, $params = array()) {
        $params['UserId'] = $this->config->username;
        $params['RegionCode'] = $this->config->country;
        $params['lg'] = 'en-US';
        $params['DCMID'] = $this->config->dcmID;
        $params['VIN'] = $this->config->vin;
        $params['tz'] = $this->config->tz;

        $encoded_params = array();
        foreach ($params as $k => $v) {
            $encoded_params[] = "$k=" . urlencode($v);
        }

        $url = $this->baseURL . $path . '?' . implode('&', $encoded_params);

        $this->debug("Request: $url");

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $result = curl_exec($ch);
        if ($result === FALSE) {
            die("Error during request to $url: " . curl_error($ch) . "\n");
        }
        curl_close($ch);

        $json = json_decode($result);
        if ($json) {
            if (isset($json->resultKey)) {
                $this->resultKey = $json->resultKey;
                $this->debug("Found resultKey in response: $this->resultKey");
            }
            if ($json->status !== 200) {
                throw new Exception("Request for '$path' failed. Response received: " . json_encode($json), $json->status);
            }
            $this->debug("Response: " . json_encode($json));
            return $json;
        }

        throw new Exception("Non-JSON response received for request to '$path'. Response received: " . json_encode($result), static::ERROR_CODE_NOT_JSON);
    }

    /** @noinspection PhpInconsistentReturnPointsInspection
     *
     * Wait until the previously-execute command completes. This will wait until the car executed the command, and returned the response, which can sometimes take a few minutes.
     *
     * @param string $path Script to use to query the server to know if the operation completed, or not yet.
     * @return stdClass
     * @throws Exception
     */
    private function waitUntilSuccess($path) {
        if (empty($this->resultKey)) {
            throw new Exception("Missing 'resultKey' to be able to wait for operation to complete using '$path'.", static::ERROR_CODE_MISSING_RESULTKEY);
        }
        $params = array('resultKey' => $this->resultKey);
        $start = time();
        while (TRUE) {
            $json = $this->sendRequest($path, $params);
            if ($json->responseFlag) {
                $this->resultKey = NULL;
                return $json;
            }
            if (time() - $start > $this->maxWaitTime) {
                throw new Exception("Timeout waiting for result using $path", static::ERROR_CODE_TIMEOUT);
            }
            sleep(1);
        }
    }

    /**
     * Log debugging information to the PHP error log.
     *
     * @param String $log
     */
    private function debug($log) {
        if ($this->debug) {
            $date = date('Y-m-d H:i:s');
            error_log("[$date] [NissanConnect] $log");
        }
    }
}
