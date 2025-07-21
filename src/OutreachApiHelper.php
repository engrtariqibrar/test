<?php

/**
 * Author: Muhammad Tariq Ibrar
 * Email: engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

class OutReachApiHelper {

    private $curl_helper;
    private $retry = 0;
    static $access_token;

    const CONFIG_KEY = 'outreach_config';

    public function __construct() {
        $or_keys = self::retrieveSettings('or_keys');
        if (is_array($or_keys)) {
            self::$access_token = $or_keys['or_access_token'];
        }
        $this->curl_helper = new \Outreach\OutreachCurlHelper(self::$access_token);
    }

    public function saveSettings($name, $value) {
        if (!empty($name)) {
            $administrationObj = new \Administration();
            $administrationObj->saveSetting(self::CONFIG_KEY, $name, $value);
        } else {
            $GLOBALS['log']->fatal("Cannot save outreach settings with empty name");
        }
    }

    public function retrieveSettings($name = null) {
        if (!empty($name)) {
            $admin = new \Administration();
            $settings = $admin->retrieveSettings(self::CONFIG_KEY);
            if (isset($settings->settings[self::CONFIG_KEY . '_' . $name])) {
                $settings = $settings->settings[self::CONFIG_KEY . '_' . $name];
                if (!$settings) {
                    return null;
                }
                if (is_string($settings) || is_array($settings)) {
                    return $settings;
                }
                return json_decode($settings);
            }
        } else {
            $GLOBALS['log']->fatal("Cannot get outreach settings with empty name");
        }
        return null;
    }

    public function fetchObject($customObject, $is_url = false) {
        try {
            //URL will be retrieved from DB
            $access_token = self::$access_token;
            if ($is_url) {
                $url = $customObject;
            } else {
                $url = 'https://api.outreach.io/api/v2/' . $customObject;
            }
            $params = array(
                'type' => 'GET',
                'url' => $url,
                'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
            );
            return $this->curl_helper->execute($params);
        } catch (Exception $ex) {
            
        }
    }

    public function fetchObjectsWithFilters($customObject, $filters = array()) {
        try {
            //URL will be retrieved from DB
            if (empty($filters) || !is_array($filters)) {
                return [];
            }
            $filter = '';
            foreach ($filters as $key => $values) {
                $filter = "[{$key}]=" . implode(",", $values);
            }
            $access_token = self::$access_token;
            $url = 'https://api.outreach.io/api/v2/' . $customObject . "?filter{$filter}";
            $params = array(
                'type' => 'GET',
                'url' => $url,
                'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
            );
            return $this->curl_helper->execute($params);
        } catch (Exception $ex) {
            
        }
    }

    public function createObject($data, $customObject) {

        try {
            $access_token = self::$access_token;
            $url = 'https://api.outreach.io/api/v2/' . $customObject;
            $params = array(
                'type' => 'POST',
                'url' => $url,
                'data' => $data,
                'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
            );
            return $this->curl_helper->execute($params);
        } catch (Exception $ex) {
            $GLOBALS['log']->fatal('createObject Exception', $ex->getMessage());
            // log exeption here
        }
    }

    public function updateObject($data, $customObject, $recordId) {
        try {
            // To-Do URL will be retrieved from DB
            $access_token = self::$access_token;
            $url = 'https://api.outreach.io/api/v2/' . $customObject . '/' . $recordId;
            $params = array(
                'type' => 'PATCH',
                'url' => $url,
                'data' => $data,
                'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
            );
            return $this->curl_helper->execute($params);
        } catch (Exception $ex) {
            $GLOBALS['log']->fatal('updateObject exception', $ex->getMessage());
        }
    }

    public function deleteObject($customObject, $recordId) {

        try {
            // To-Do URL will be retrieved from DB
            $access_token = self::$access_token;
            $url = 'https://api.outreach.io/api/v2/' . $customObject . '/' . $recordId;
            $params = array(
                'type' => 'DELETE',
                'url' => $url,
                'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
            );

            return $this->curl_helper->execute($params);
        } catch (Exception $ex) {
            $GLOBALS['log']->fatal('deleteObject Exception', $ex->getMessage());
        }
    }

    public function processValidation() {
        $curlHelperObj = new OutreachCurlHelper(self::$access_token);
        $access_token = self::$access_token;
        $url = 'https://api.outreach.io/api/v2/accounts';
        $params = array(
            'type' => 'GET',
            'url' => $url,
            'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
        );

        return $curlHelperObj->execute($params);
    }

    //Webhook
    public function registerOutreachWebhook() {
        /* To-DO - This function will only contain the code to create $data variable
         * and pass $data, $url and $sugarInstanceUrl to 'POST' function of CRUD methods.
         * This function will be called when Authorize button is clicked
         */
        global $sugar_config;

        try {
            $curlHelperObj = new OutreachCurlHelper(self::$access_token);
            $sugarInstanceUrl = $sugar_config['site_url'] . '/index.php?entryPoint=outreach_webhook';
            $access_token = self::$access_token;
            $url = 'https://api.outreach.io/api/v2/webhooks';
            $data = json_encode(['data' => [
                    'type' => 'webhook', 'attributes' => [
                        'action' => '*', 'resource' => '*', 'url' => $sugarInstanceUrl
                    ],
            ]]);

            $params = array(
                'type' => 'POST', 'url' => $url, 'data' => $data, 'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-type' => 'application/vnd.api+json'),
            );

            return $curlHelperObj->execute($params);
        } catch (Exception $ex) {
            $GLOBALS['log']->fatal('registerOutreachWebhook Exception', $ex->getMessage());
        }
    }

    public function getObjectFieldsMeta($customObject, $data) {
        $or_module = \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL[$customObject];
        $responseData = $this->fetchObject($or_module);
        $responseData = $this->validateJsonMeta($responseData, $customObject, $data);

        if (is_array($responseData->data)) {
            return $this->createjsonMeta($responseData->data[0]);
        } else if ($responseData->data != null) {
            return $this->createjsonMeta($responseData->data);
        } {
            return $responseData;
        }
    }

    public function validateJsonMeta($responseData, $customObject, $data) {

        if ($this->retry == 3) {
            //Max 3 attempts
            return;
        }
        $this->retry++;
        if ($responseData->custom_Http_Status == '403' || $responseData->custom_Http_Status == '401') {
            return $responseData->errors[0]->detail;
        }
        if ($responseData->meta->count > 0 || $responseData->custom_Http_Status == '201' || !empty($responseData->data)) {
            return $responseData;
        } else {

            $responseData = $this->createObject(json_encode(['data' => [
                    'type' => \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_SINGULAR[$customObject],
                    'attributes' => $this->getTestData($customObject) //['firstName' => 'Test Integration Record - Donot Delete'],
        ]]), \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL[$customObject]);

            return $responseData;
        }
    }

    public function getTestData($customObject) {
        if ($customObject == 'Accounts') {
            return ['name' => 'Test Integration Record - Donot Delete'];
        } else if ($customObject == 'Opportunities') {
            return [
                'name' => 'Test Integration Record - Donot Delete',
                'closeDate' => '1970-10-06T00:00:00.000Z'
            ];
        } else {
            return ['firstName' => 'Test Integration Record - Donot Delete'];
        }
    }

    public function createjsonMeta($fieldAttributess) {
        $fieldsSet = array();
        $custom_fields = array();
        foreach ($fieldAttributess->attributes as $key => $value) {
            if (!in_array($key, \Outreach\OutreachConfig::$commonExcludedFields)) {
                if (str_contains($key, 'custom')) {
                    $custom_fields[$key] = $key;
                } else {
                    $fieldsSet[$key] = $key;
                }
            }
        }
        //For relationship fields
        foreach ($fieldAttributess->relationships as $key => $value) {
            if (!in_array($key, \Outreach\OutreachConfig::$commonExcludedFields)) {
                $fieldsSet[$key] = $key;
            }
        }
        $fieldsSet = array_merge($fieldsSet, $custom_fields);
        return $fieldsSet;
    }

    public static function array_to_object($array) {
        $obj = new stdClass;
        foreach ($array as $k => $v) {
            if (strlen($k)) {
                if (is_array($v)) {
                    $obj->{$k} = self::array_to_object($v);
                } else {
                    $obj->{$k} = $v;
                }
            }
        }
        return $obj;
    }

    public static function objectToArray($object) {
        return json_decode(json_encode($object), true);
    }

    //TODO add function to fetch $data in generic way to be used in getObjectFieldsMeta

    public function generateOureachMetaRecord($module) {
        $returnValue = [];
        //Use switch case and get the data params required for each module
        //Account can have firstName while record records may not have this field
        return $returnValue;
    }

    public function generateAuthorizeURL($clientId, $clientSecre) {
        global $sugar_config;
        $redirect_url = $sugar_config['site_url'].'/index.php?entryPoint=outreach_code';
        $clientId = urlencode($clientId);
        $clientSecre = urlencode($clientSecre);
        //Removed scrop to avoid errors: taskPriorities.all%20 profile%20
        $customAuthUrl = \Outreach\OutreachConfig::$outreachAuthUrl . '?' . 'client_id=' . $clientId . '&client_secret=' . $clientSecre . '&redirect_uri=' . $redirect_url . '&response_type=code&scope=sequenceStates.all%20sequences.all%20recipients.all%20mailboxes.all%20opportunityStages.all%20opportunityStages.read%20stages.all%20stages.read%20mailings.all%20mailings.read%20mailings.write%20mailings.delete%20taskPriorities.read%20tasks.all%20tasks.read%20tasks.write%20tasks.delete%20callPurposes.all%20callPurposes.read%20callDispositions.all%20callDispositions.read%20calls.all%20calls.read%20calls.write%20calls.delete%20accounts.all%20accounts.read%20accounts.write%20accounts.delete%20prospects.all%20prospects.write%20prospects.read%20prospects.delete%20webhooks.write%20webhooks.all%20webhooks.read%20webhooks.delete%20opportunities.all%20opportunities.write%20opportunities.read%20opportunities.delete%20users.write%20users.all%20users.read%20users.delete%20opportunityStages.write%20opportunityStages.all%20opportunityStages.read%20opportunityStages.delete%20phoneNumbers.all%20opportunityProspectRoles.all';
        return $customAuthUrl;
    }
}
