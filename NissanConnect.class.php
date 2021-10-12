<?php
/*
Copyright 2016-2017 Guillaume Boudreau
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
    const COUNTRY_EU = 'NE';
    const COUNTRY_AUSTRALIA = 'NMA';
    const COUNTRY_JAPAN = 'NML';

    /* Those error code will be used in Exception that can be thrown when errors occur. */
    const ERROR_CODE_MISSING_RESULTKEY = 400;
    const ERROR_CODE_LOGIN_FAILED = 403;
    const ERROR_CODE_INVALID_RESPONSE = 405;
    const ERROR_CODE_NOT_JSON = 406;
    const ERROR_CODE_TIMEOUT = 408;

    const STATUS_QUERY_OPTION_NONE   = 0;
    const STATUS_QUERY_OPTION_ASYNC  = 1;
    const STATUS_QUERY_OPTION_CACHED = 2;

    const ENCRYPTION_OPTION_OPENSSL    = 0;
    const ENCRYPTION_OPTION_WEBSERVICE = 1;

    const SECONDS_LIMIT_TO_CONSIDER_DATA_AS_FRESH = 120;
    const SECONDS_LIMIT_FOR_RETRYING_REQUESTS = 120;
    const SECONDS_BETWEEN_RETRIES = 5;

    /* @var int How long should we wait, before throwing an exception, when waiting for the car to execute a command. @see $waitForResult parameter in the various function calls. */
    public $maxWaitTime = 290;

    /* @var boolean Enable to echo debugging information into the PHP error log. */
    public $debug = FALSE;

    # The API URL is changed occasionally when Nissan introduce a new version.

    # When the API changes, it's worth taking a look at other sources, such as:
    # https://github.com/filcole/pycarwings2/issues/
    # https://github.com/jdhorne/pycarwings2/issues/
    # https://gitlab.com/tobiaswkjeldsen/dartcarwings

    # private $baseURL = 'https://gdcportalgw.its-mo.com/gworchest_160803EC/gdc/';  # No longer works for some, but works in Sweden. Tweaks were needed to make it work after 2018-12-25
    # private $baseURL = 'https://gdcportalgw.its-mo.com/api_v181217_NE/gdc/';    # New December 2018, but doesn't seem to work, gives {"status":408}
    # private $baseURL = 'https://gdcportalgw.its-mo.com/api_v180117_NE/gdc/';    # New from Summer 2018? Not working as of Jan 2019, 404
    # private $baseURL = 'https://gdcportalgw.its-mo.com/gworchest_160803A/gdc/'; # Stopped working summer 2018
    # private $baseURL = 'https://gdcportalgw.its-mo.com/api_v190426_NE/gdc/'; #Stopped working autumn 2021
    private $baseURL = 'https://gdcportalgw.its-mo.com/api_v210707_NE/gdc/';

    private $resultKey = NULL;
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
     * @param int    $encryptionOption Use ENCRYPTION_OPTION_OPENSSL (the default) if you can; otherwise, use ENCRYPTION_OPTION_WEBSERVICE, which will use a remote web-service to encrypt your password.
     */
    public function __construct($username, $password, $tz = 'America/New_York', $country = NissanConnect::COUNTRY_US, $encryptionOption = 0) {
        $this->config = new stdClass();
        $this->config->username = $username;
        $this->config->password = $password;
        $this->config->tz = $tz;
        $this->config->country = strtoupper($country);
        $this->config->vin = '';
        $this->config->dcmID = '';
        $this->config->UserVehicleBoundTime = '';
        $this->config->initialAppStrings = '9s5rfKVuMrT03RtzajWNcA'; // Hard-coded in mobile apps?
        $this->config->basePRM = 'uyI5Dj9g8VCOFDnBRUbr3g'; // Will be overwritten with the response from the InitialApp_v2.php call
        $this->config->customSessionID = ''; // Empty until login completes
        $this->config->encryptionOption = $encryptionOption;
        date_default_timezone_set($tz);
    }

    /**
     * Start the Climate Control.
     *
     * @param bool $waitForResult Should we wait until the command result is known, before returning? Enabling this will wait until the car executed the command, and returned the response, which can sometimes take a few minutes.
     *
     * @return stdClass
     * @throws Exception
     */
    public function startClimateControl($waitForResult = FALSE) {
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
     *
     * @return stdClass
     * @throws Exception
     */
    public function stopClimateControl($waitForResult = FALSE) {
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
     * Get driving history for the specified date
     *
     * @param date $targetDate Specify date to request information for
     *
     * @return stdClass
     * @throws Exception
     */
    public function getHistory($targetDate=null) {
        $this->prepare();
        $result = $this->sendRequest('CarKarteDetailInfoRequest.php', array('TargetDate' => $targetDate));
        return $result;
    }

    /*
     * Get current location
     * @return stdClass
     * @throws Exception

     * POST https://gdcportalgw.its-mo.com/gworchest_160803EC/gdc/MyCarFinderRequest.php HTTP/1.1
     * Eiter wait until success, or keep requesting:
     * POST https://gdcportalgw.its-mo.com/gworchest_160803EC/gdc/MyCarFinderResultRequest.php HTTP/1.1
     */
    public function getLocation() {
      $result = $this->sendRequest('MyCarFinderRequest.php');
      return $this->waitUntilSuccess('MyCarFinderResultRequest.php');
    }

    /**
     * Get the last known location
     *
     * @return stdClass
     * @throws Exception
     */
    public function lastLocation() {
        $this->prepare();
        $result = $this->sendRequest('MyCarFinderLatLng.php');
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
            $expected_last_updated_date = time();
            $this->debug("Expected last updated date: " . date("Y-m-d H:i:s", $expected_last_updated_date));
        }
        if ($option == static::STATUS_QUERY_OPTION_ASYNC) {
            return NULL;
        }

        // Make sure the response from BatteryStatusRecordsRequest.php was updated
        $start = time();
        while (TRUE) {
            $response = $this->sendRequest('BatteryStatusRecordsRequest.php');
            $this->_checkStatusResult($response, 'BatteryStatusRecords');

            if (empty($expected_last_updated_date)) {
                break;
            }
            $this->debug("Last Updated date received: " . date("Y-m-d H:i:s", strtotime($response->BatteryStatusRecords->OperationDateAndTime)));
            $time_diff = abs($expected_last_updated_date - strtotime($response->BatteryStatusRecords->OperationDateAndTime));
            $this->debug("  Last Updated Date: Received minus Expected = $time_diff seconds");
            if ($time_diff < static::SECONDS_LIMIT_TO_CONSIDER_DATA_AS_FRESH) {
              $this->debug("  Got freshly updated data in API response");
            } elseif (time() - $start < static::SECONDS_LIMIT_FOR_RETRYING_REQUESTS) {
                $this->debug("  Haven't yet got fresh data from the API, trying again in " . static::SECONDS_BETWEEN_RETRIES . " seconds...");
                sleep(static::SECONDS_BETWEEN_RETRIES);
                continue;
            } else {
                $this->debug("  Reached time limit of " . static::SECONDS_LIMIT_FOR_RETRYING_REQUESTS . " seconds, giving up waiting for updated data from API");
            }
            break;
        }

        $response2 = $this->sendRequest('RemoteACRecordsRequest.php', array('TimeFrom' => gmdate('Y-m-d\TH:i:s', strtotime($this->config->UserVehicleBoundTime))));

        $result = new stdClass();

        $result->status = $response2->status;

        $result->LastUpdated = date('Y-m-d H:i', strtotime($response->BatteryStatusRecords->OperationDateAndTime));

        $result->PluggedIn = ( $response->BatteryStatusRecords->PluginState != 'NOT_CONNECTED' );
        $result->ChargingMode = $response->BatteryStatusRecords->BatteryStatus->BatteryChargingStatus;
        $result->Charging = ( $result->ChargingMode != 'NOT_CHARGING' );

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
        // SOC = The percentage state of charge (don't work under 5%) -> API Answer is "SOC":{"Display":"---"}}
        if (!empty($response->BatteryStatusRecords->BatteryStatus->SOC->Value)) {
            $result->SOC =  $response->BatteryStatusRecords->BatteryStatus->SOC->Value;
        } else {
            $result->SOC = NULL;
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

        // Can be Null, under 15km
        if (empty($response->BatteryStatusRecords->CruisingRangeAcOn)) {
            $result->CruisingRangeAcOn = NULL;
            $result->CruisingRangeUnit = NULL;
            $result->CruisingRangeUnit = '---';
        } elseif ($this->config->country == NissanConnect::COUNTRY_US) {
            $result->CruisingRangeAcOn = $response->BatteryStatusRecords->CruisingRangeAcOn * 0.000621371192;
            $result->CruisingRangeAcOff = $response->BatteryStatusRecords->CruisingRangeAcOff * 0.000621371192;
            $result->CruisingRangeUnit = 'miles';
        } else {
            $result->CruisingRangeAcOn = $response->BatteryStatusRecords->CruisingRangeAcOn / 1000;
            $result->CruisingRangeAcOff = $response->BatteryStatusRecords->CruisingRangeAcOff / 1000;
            $result->CruisingRangeUnit = 'km';
        }

        $result->RemoteACRunning = ((@$response2->RemoteACRecords->PluginState == 'CONNECTED' || @$response2->RemoteACRecords->OperationResult == 'START_BATTERY') && @$response2->RemoteACRecords->RemoteACOperation != 'STOP');
        if (isset($response2->RemoteACRecords->ACStartStopDateAndTime)) {
            $result->RemoteACLastChanged = date('Y-m-d H:i', strtotime($response2->RemoteACRecords->ACStartStopDateAndTime));
        } else {
            $result->RemoteACLastChanged = NULL;
        }
        if (!empty($response2->RemoteACRecords->ACStartStopURL)) {
            $result->ACStartStopURL = $response2->RemoteACRecords->ACStartStopURL;
        } else {
            $result->ACStartStopURL = NULL;
        }
        if (isset($response2->RemoteACRecords->ACDurationBatterySec)) {
            $result->ACDurationBatterySec = (int) $response2->RemoteACRecords->ACDurationBatterySec;
        } else {
            $result->ACDurationBatterySec = FALSE;
        }
        if (isset($response2->RemoteACRecords->ACDurationBatterySec)) {
            $result->ACDurationPluggedSec = (int) $response2->RemoteACRecords->ACDurationPluggedSec;
        } else {
            $result->ACDurationPluggedSec = FALSE;
        }

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
     * Load the VIN, DCMID, UserVehicleBoundTime and CustomSessionID values, either from disk, if they were saved there by a previous call, or from the remote API, if not.
     *
     * @param bool $skip_local_file Should we skip loading the cached information from the local file, and force a login to obtain them.
     *
     * @return void
     * @throws Exception
     */
    private function prepare($skip_local_file = FALSE) {
        if (empty($this->config->vin) || empty($this->config->dcmID) || empty($this->config->customSessionID) || empty($this->config->UserVehicleBoundTime)) {
            $uid = md5($this->config->username);
            # Use the posix function if available. This requires php-posix or php-process package
            $unixuser = function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
            $local_storage_file = sys_get_temp_dir() . "/.nissan-connect-storage-$uid-$unixuser.json";

            $local_storage_file_old = sys_get_temp_dir() . "/.nissan-connect-storage-$uid.json";
            if (!file_exists($local_storage_file) && file_exists($local_storage_file_old)) {
                rename($local_storage_file_old, $local_storage_file);
            }

            if (file_exists($local_storage_file) && !$skip_local_file) {
                $json = @json_decode(file_get_contents($local_storage_file));
                $this->config->vin = @$json->vin;
                $this->config->dcmID = @$json->dcmid;
                $this->config->customSessionID = @$json->sessionid;
                $this->config->UserVehicleBoundTime = @$json->UserVehicleBoundTime;
            }
            if (empty($this->config->vin) || empty($this->config->dcmID) || empty($this->config->customSessionID) || empty($this->config->UserVehicleBoundTime)) {
                $this->login();
                file_put_contents($local_storage_file, json_encode(array('vin' => $this->config->vin, 'dcmid' => $this->config->dcmID, 'sessionid' => $this->config->customSessionID, 'UserVehicleBoundTime' => $this->config->UserVehicleBoundTime)));
                $this->debug("Saving DCMID, VIN, UserVehicleBoundTime and CustomSessionID into local file $local_storage_file");
            } else {
                $this->debug("Using DCMID, VIN, UserVehicleBoundTime and CustomSessionID found in local file $local_storage_file");
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
        $result = $this->sendRequest('InitialApp_v2.php');
        if (empty($result->baseprm)) {
            throw new Exception("Failed to get 'baseprm' using InitialApp_v2.php. Response: " . json_encode($result), static::ERROR_CODE_LOGIN_FAILED);
        }
        $this->config->basePRM = $result->baseprm;

        $encrypted_encoded_password = $this->encryptPassword($this->config->password, $this->config->basePRM);
        $params = array('UserId' => $this->config->username, 'Password' => $encrypted_encoded_password);
        $result = $this->sendRequest('UserLoginRequest.php', $params);

        if (isset($result->CustomerInfo->VehicleInfo->DCMID)) {
            $this->config->dcmID = $result->CustomerInfo->VehicleInfo->DCMID;
        }
        if (isset($result->CustomerInfo->VehicleInfo->UserVehicleBoundTime)) {
            $this->config->UserVehicleBoundTime = $result->CustomerInfo->VehicleInfo->UserVehicleBoundTime;
        }
        if (isset($result->VehicleInfoList->vehicleInfo[0]->custom_sessionid)) {
            $this->config->customSessionID = $result->VehicleInfoList->vehicleInfo[0]->custom_sessionid;
        }
        if (empty($this->config->customSessionID) && isset($result->vehicleInfo[0]->custom_sessionid)) {
            $this->config->customSessionID = $result->vehicleInfo[0]->custom_sessionid;
        }
        if (isset($result->CustomerInfo->VehicleInfo->VIN)) {
            $this->config->vin = $result->CustomerInfo->VehicleInfo->VIN;
        }
        if (empty($this->config->vin) || empty($this->config->dcmID) || empty($this->config->customSessionID) || empty($this->config->UserVehicleBoundTime)) {
            throw new Exception("Login failed, or failed to find car VIN, DCMID, UserVehicleBoundTime or custom_sessionid in response of login request: " . json_encode($result), static::ERROR_CODE_LOGIN_FAILED);
        }
    }

    /**
     * Send an HTTP GET request to the specified script, and return the JSON-decoded result.
     *
     * @param string $path   Script to send the request to.
     * @param array  $params Query parameters to send with the request.
     *
     * @return stdClass JSON-decoded response from API.
     * @throws Exception
     */
    private function sendRequest($path, $params = array()) {
        $params['custom_sessionid'] = empty($this->config->customSessionID) ? '' : $this->config->customSessionID;
        $params['initial_app_str'] = $this->config->initialAppStrings;
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
                if (($json->status == 401 || $json->status == 404 || $json->status == 408 || $json->status < 0) && $this->shouldRetry) {
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

    /* @noinspection PhpInconsistentReturnPointsInspection */
    /**
     * Wait until the previously-execute command completes. This will wait until the car executed the command, and returned the response, which can sometimes take a few minutes.
     *
     * @param string $path Script to use to query the server to know if the operation completed, or not yet.
     *
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

    private function encryptPassword($password, $key) {
        if ($this->config->encryptionOption == static::ENCRYPTION_OPTION_WEBSERVICE) {
            return trim(file_get_contents("https://dataproxy.pommepause.com/nissan-connect-encrypt.php?key=" . urlencode($key) . "&password=" . urlencode($password)));
        }
        if (!function_exists('openssl_encrypt')) {
            throw new Exception("OpenSSL support in PHP is not available. Either use ENCRYPTION_OPTION_WEBSERVICE as the encryption option, to use a remote web-service to encrypt passwords, or compile PHP using --with-openssl.");
        }
        $method = 'bf-ecb';
        $encrypted_password = openssl_encrypt($password, $method, $key, TRUE);
        return base64_encode($encrypted_password);
    }
}
