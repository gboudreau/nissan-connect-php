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
    const ERROR_CODE_INVALID_RESPONSE = 405;
    const ERROR_CODE_NOT_JSON = 406;
    const ERROR_CODE_TIMEOUT = 408;
    
    const STATUS_QUERY_OPTION_NONE   = 0;
    const STATUS_QUERY_OPTION_ASYNC  = 1;
    const STATUS_QUERY_OPTION_CACHED = 2;

    /** @var int How long should we wait, before throwing an exception, when waiting for the car to execute a command. @see $waitForResult parameter in the various function calls. */
    public $maxWaitTime = 290;

    /** @var boolean Enable to echo debugging information into the PHP error log. */
    public $debug = FALSE;

    private $baseURL = 'https://gdcportalgw.its-mo.com/gworchest_0307C/gdc/';

    private $resultKey = NULL;
    private $config = NULL;

    /** @var boolean Should we retry to login, if the API return us a 404 error. */
    private $shouldRetry = TRUE;

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
        $this->config->initialAppStrings = 'geORNtsZe5I4lRGjG9GZiA'; // Hard-coded in mobile apps?
        $this->config->basePRM = 'uyI5Dj9g8VCOFDnBRUbr3g'; // Will be overwritten with the response from the InitialApp.php call
        $this->config->customSessionID = ''; // Empty until login completes
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
     * @param int $option Specify one of the STATUS_QUERY_OPTION_* constant.
     *
     * @return stdClass
     * @throws Exception
     */
    public function getStatus($option = 0) {
        $this->prepare();
        if ($option != static::STATUS_QUERY_OPTION_CACHED) {
            $this->sendRequest('BatteryStatusCheckRequest.php');
            if ($option != static::STATUS_QUERY_OPTION_ASYNC) {
                $this->waitUntilSuccess('BatteryStatusCheckResultRequest.php');
            }
        }
        if ($option == static::STATUS_QUERY_OPTION_ASYNC) {
            return NULL;
        }

        $response = $this->sendRequest('BatteryStatusRecordsRequest.php');
        $this->_checkStatusResult($response, 'BatteryStatusRecords');

        $response2 = $this->sendRequest('RemoteACRecordsRequest.php');
        $this->_checkStatusResult($response2, 'RemoteACRecords');

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

        $result->RemoteACRunning = (($response2->RemoteACRecords->PluginState == 'CONNECTED' || $response2->RemoteACRecords->OperationResult == 'START_BATTERY') && $response2->RemoteACRecords->RemoteACOperation != 'STOP');
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
    
    private function _checkStatusResult($response, $what) {
        $allowed_op_result = array('START', 'START_BATTERY', 'FINISH');
        if (empty($response->{$what})) {
            throw new Exception("Missing '$what' in response received in call to '{$what}Request.php': " . json_encode($response), static::ERROR_CODE_INVALID_RESPONSE);
        }
        if (empty($response->{$what}->OperationResult)) {
            throw new Exception("Missing '$what->OperationResult' in response received in call to '{$what}Request.php': " . json_encode($response), static::ERROR_CODE_INVALID_RESPONSE);
        }
        if (array_search($response->{$what}->OperationResult, $allowed_op_result) === FALSE) {
            throw new Exception("Invalid 'OperationResult' received in call to '{$what}Request.php': " . $response->{$what}->OperationResult, static::ERROR_CODE_INVALID_RESPONSE);
        }
    }

    /**
     * Load the VIN, DCMID and CustomSessionID values, either from disk, if they were saved there by a previous call, or from the remote API, if not.
     *
     * @throws Exception
     */
    private function prepare($skip_local_file = FALSE) {
        if (empty($this->config->vin) || empty($this->config->dcmID) || empty($this->config->customSessionID)) {
            $uid = md5($this->config->username);
            $local_storage_file = "/tmp/.nissan-connect-storage-$uid.json";
            if (file_exists($local_storage_file) && !$skip_local_file) {
                $json = @json_decode(file_get_contents($local_storage_file));
                $this->config->vin = @$json->vin;
                $this->config->dcmID = @$json->dcmid;
                $this->config->customSessionID = @$json->sessionid;
            }
            if (empty($this->config->vin) || empty($this->config->dcmID) || empty($this->config->customSessionID)) {
                $this->login();
                file_put_contents($local_storage_file, json_encode(array('vin' => $this->config->vin, 'dcmid' => $this->config->dcmID, 'sessionid' => $this->config->customSessionID)));
                $this->debug("Saving DCMID, VIN and CustomSessionID into local file $local_storage_file");
            } else {
                $this->debug("Using DCMID, VIN and CustomSessionID found in local file $local_storage_file");
            }
        }
    }

    /**
     * Login using the user's email address and password, to get the DCMID value needed to make subsequent API calls.
     *
     * @throws Exception
     */
    private function login() {
        $result = $this->sendRequest('InitialApp.php');
        if (empty($result->baseprm)) {
            throw new Exception("Failed to get 'baseprm' using InitialApp.php. Response: " . json_encode($result), static::ERROR_CODE_LOGIN_FAILED);
        }
        $this->config->basePRM = $result->baseprm;

        $encrypted_encoded_password = static::encryptPassword($this->config->password, $this->config->basePRM);
        $params = array('UserId' => $this->config->username, 'Password' => $encrypted_encoded_password);
        $result = $this->sendRequest('UserLoginRequest.php', $params);

        if (isset($result->CustomerInfo->VehicleInfo->DCMID)) {
            $this->config->dcmID = $result->CustomerInfo->VehicleInfo->DCMID;
        }
        if (isset($result->VehicleInfoList->vehicleInfo[0]->custom_sessionid)) {
            $this->config->customSessionID = $result->VehicleInfoList->vehicleInfo[0]->custom_sessionid;
        }
        if (isset($result->CustomerInfo->VehicleInfo->VIN)) {
            $this->config->vin = $result->CustomerInfo->VehicleInfo->VIN;
        }
        if (empty($this->config->vin) || empty($this->config->dcmID) || empty($this->config->customSessionID)) {
            throw new Exception("Login failed, or failed to find car VIN, DCMID or custom_sessionid in response of login request: " . json_encode($result), static::ERROR_CODE_LOGIN_FAILED);
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
        $params['custom_sessionid'] = $this->config->customSessionID;
        $params['initial_app_strings'] = $this->config->initialAppStrings;
        $params['RegionCode'] = $this->config->country;
        $params['lg'] = 'en-US';
        $params['DCMID'] = $this->config->dcmID;
        $params['VIN'] = $this->config->vin;
        $params['tz'] = $this->config->tz;

        $url = $this->baseURL . $path;

        $this->debug("Request: POST $url " . json_encode($params));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
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
                if ($json->status == 404 && $this->shouldRetry) {
                    $this->debug("Request for '$path' failed. Response received: " . json_encode($json) . " Will retry.");
                    $this->shouldRetry = FALSE; // Don't loop infinitely!
                    $this->config->customSessionID = NULL;
                    $this->prepare(TRUE);
                    return $this->sendRequest($path, $params);
                }
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

    private static function encryptPassword($password, $key) {
        $size = @call_user_func('mcrypt_get_block_size', MCRYPT_BLOWFISH);
        if (empty($size)) {
            $size = @call_user_func('mcrypt_get_block_size', MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
        }
        $password = static::pkcs5_pad($password, $size);
        $iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB), MCRYPT_RAND);
        $encrypted_password = mcrypt_encrypt(MCRYPT_BLOWFISH, $key, $password, MCRYPT_MODE_ECB, $iv);
        return base64_encode($encrypted_password);
    }

    private static function pkcs5_pad($text, $blocksize) {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }
}
