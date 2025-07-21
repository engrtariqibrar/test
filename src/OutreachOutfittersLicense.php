<?php

/**
 * Organization: Brainsell
 * Author: Muhammad Tariq Ibrar
 * Email: tibrar@brainsell.com, engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

use Sugarcrm\Sugarcrm\Security\HttpClient\ExternalResourceClient;
use Sugarcrm\Sugarcrm\Security\HttpClient\RequestException;

class OutreachOutfittersLicense {

    public $or_config = array(
        'name' => 'Outreach', //The matches the id value in your manifest file. This allow the library to lookup addon version from upgrade_history, so you can see what version of addon your customers are using
        'shortname' => 'Outreach', //The short name of the Add-on. e.g. For the url https://www.sugaroutfitters.com/addons/sugaroutfitters the shortname would be sugaroutfitters    
        'public_key' => '6a086f01af22fc5ced9bfec109d9b280', // Yearly
        'api_url' => 'https://www.sugaroutfitters.com/api/v1',
        'validate_users' => false,
        'manage_licensed_users' => false, //Enable the user management tool to determine which users will be licensed to use the add-on. validate_users must be set to true if this is enabled. If the add-on must be licensed for all users then set this to false.
        'validation_frequency' => 'hourly', //default: weekly options: hourly, daily, weekly
        'continue_url' => '', //[optional] Will show a button after license validation that will redirect to this page. Could be used to redirect to a configuration page such as index.php?module=MyCustomModule&action=config
    );

    public function validate($key = null, $getKey = null) {
        $outreach_helper = new \Outreach\OutreachApiHelper();
        if ($getKey) {
            $license = $outreach_helper->retrieveSettings("license");
            $key = isset($license['license_key']) ? $license['license_key'] : null;
        }
        if (empty($key)) {
            $GLOBALS['log']->fatal("Outreach: Unable to validate license because the key is empty");
            return array('success' => false, 'result' => 'Key is required');
        }

        $data = array('key' => $key);
        $response = $this->call('key/validate', $data, 'get');
        $GLOBALS['log']->info('OutreachOutfittersLicense validate $response',$response);
        $license = array(
            'license_key' => $key,
            'is_valid' => 1,
            'checked_date' => date('Y-m-d')
        );
        if ($response['success'] != true) {
            $GLOBALS['log']->fatal('Outreach: Failed to validate outreach license', $response);
            $license['is_valid'] = 0;
        }
        $outreach_helper->saveSettings('license', $license);
        return $response;
    }

    public function get_default_payload($custom_data = array()) {
        global $sugar_config, $sugar_flavor;
        $not_set_value = 'not set';
        $data = array();

        if (empty($custom_data['key'])) {
            $data['key'] = empty($sugar_config['outfitters_licenses'][$this->or_config['shortname']]) ? false : $sugar_config['outfitters_licenses'][$this->or_config['shortname']];
        } else {
            $data['key'] = $custom_data['key'];
        }

        if (empty($custom_data['public_key'])) {
            $data['public_key'] = empty($this->or_config['public_key']) ? $not_set_value : $this->or_config['public_key'];
        } else {
            $data['public_key'] = $custom_data['public_key'];
        }

        $data['sugar_edition'] = empty($sugar_flavor) ? $not_set_value : $sugar_flavor;
        $data['db_type'] = empty($sugar_config['dbconfig']['db_type']) ? $not_set_value : $sugar_config['dbconfig']['db_type'];
        $data['developerMode'] = (!empty($sugar_config['developerMode']) && $sugar_config['developerMode'] === true ? 'true' : 'false');
        $data['host_name'] = empty($sugar_config['host_name']) ? $not_set_value : $sugar_config['host_name'];
        $data['package_scan'] = (!empty($sugar_config['moduleInstaller']) && !empty($sugar_config['moduleInstaller']['packageScan']) && $sugar_config['moduleInstaller']['packageScan'] === true ? 'true' : 'false');
        $data['sugar_version'] = empty($sugar_config['sugar_version']) ? $not_set_value : $sugar_config['sugar_version'];
        $data['site_url'] = empty($sugar_config['site_url']) ? $not_set_value : $sugar_config['site_url'];
        return $data;
    }

    public function call($path, $custom_data = array(), $method = 'post') {
        $url = $this->or_config['api_url'] . '/' . $path;
        $data = $this->get_default_payload($custom_data);
        if (is_array($data)) {
            if (empty($data['key'])) {
                return array(
                    'success' => false,
                    'result' => 'Key could not be found locally. Please go to the license configuration tool and enter your key.'
                );
            }
            if ($method === 'post') {
                try {
                    $response = (new ExternalResourceClient(60, 10))->post($url, $data);
                } catch (RequestException $e) {
                    $GLOBALS['log']->log('Error License CURL POST Req: ' . $e->getMessage());
                }
            } else {
                $url .= '?' . http_build_query($data);
                try {
                    $response = (new ExternalResourceClient(60, 10))->get($url);
                } catch (RequestException $e) {
                    $GLOBALS['log']->log('Error License CURL GET Req: ' . $e->getMessage());
                }
            }

            $respStatus = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            //if it is not a 200 response assume a 400. Good enough for this purpose.
            if ($respStatus == 0) {
                $GLOBALS['log']->fatal('Outreach: Unable to validate license. Please configure the firewall to allow requests to ' . $this->or_config['api_url'] . '/key/validate and make sure that SSL certs are up to date on the server.');
                return array(
                    'success' => false,
                    'result' => 'Unable to validate the license key. Please configure the firewall to allow requests to ' . $this->or_config['api_url'] . '/key/validate and make sure that SSL certs are up to date on the server.'
                );
            } else if ($respStatus != 200) {
                return array(
                    'success' => false,
                    'result' => $result
                );
            } else {
                return array(
                    'success' => true,
                    'result' => $result
                );
            }
        } else {
            $GLOBALS['log']->fatal('Outreach: Invalid data was sent to OutreachOutfittersLicense call Method', $data);
            return array(
                'success' => false,
                'result' => 'Invalid data sent to CURL request'
            );
        }
    }
}
