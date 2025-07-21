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

class OutreachCurlHelper {

    private $access_token;
    private $unlimit_loop_count = 0;

    const OR_EXPIRED_TOKEN_MSG = 'expiredAccessToken';

    public function __construct($token = null) {
        $this->access_token = $token;
    }

    public function revokeAccessToken($params) {
        global $sugar_config;
        $redirect_url = $sugar_config['site_url'].'/index.php?entryPoint=outreach_code';
        $this->unlimit_loop_count++;
        if ($this->unlimit_loop_count > 5) {
            $GLOBALS['log']->fatal("Outreach: revokeAccessToken limit reached, please try again");
            return;
        }
        $outreach_helper = new \Outreach\OutreachApiHelper();
        $or_keys = $outreach_helper->retrieveSettings("or_keys");
        if (empty($or_keys['or_client_id']) || empty($or_keys['or_client_secret']) || empty($or_keys['or_refresh_token'])) {
            $GLOBALS['log']->fatal("Outreach: Can not revoke access token because client id, client secret or refresh token is empty");
            return;
        }
        $tokenResponse = $this->generateAccessToken(
                \Outreach\OutreachConfig::$oauth_token_url,
                $or_keys['or_client_id'],
                $or_keys['or_client_secret'],
                $redirect_url,
                'refresh_token',
                $or_keys['or_refresh_token']
        );
        $GLOBALS['log']->info('$tokenResponse', $tokenResponse);
        if (!empty($tokenResponse->access_token) && !empty($tokenResponse->refresh_token)) {
            $or_keys['or_access_token'] = $tokenResponse->access_token;
            $or_keys['or_refresh_token'] = $tokenResponse->refresh_token;
            OutReachApiHelper::$access_token = $tokenResponse->access_token;
            $outreach_helper->saveSettings('or_keys', $or_keys);
            $params['headers'] = array("Authorization" => "Bearer " . OutReachApiHelper::$access_token, "Content-type" => "application/vnd.api+json");
        }
        return $this->execute($params);
    }

    public function execute($params) {
        if (!empty($params['type']) && !empty($params['url']) && !empty($params['headers'])) {
            switch ($params['type']) {
                case 'PUT':
                    return $this->putMethod($params);

                case 'PATCH':
                    return $this->patchMethod($params);

                case 'DELETE':
                    return $this->deleteMethod($params);

                case 'POST':
                    return $this->postMethod($params);

                case 'GET':
                    return $this->getMethod($params);
            }
        } else {
            $GLOBALS['log']->fatal('Invalid data was sent to Outreach CurlHelper execute method', $params);
            return array();
        }
    }

    public function handleResponse($response, $params = array()) {
        $responseData = array();
        if ($response) {
            $body = $response->getBody();
            if ($body) {
                $content = $body->getContents();
                if ($content) {
                    $responseData = json_decode($content);
                    if ($responseData) {
                        $GLOBALS['log']->info('handleResponse: $responseData', $responseData);
                        if (isset($responseData->id) && $responseData->id == self::OR_EXPIRED_TOKEN_MSG) {
                            return $this->revokeAccessToken($params);
                        }
                        $code = $response->getStatusCode();
                        if ($code) {
                            $responseData->custom_Http_Status = $code;
                        } else {
                            $GLOBALS['log']->fatal('Outreach: code is empty in api response');
                            return false;
                        }
                    } else {
                        $GLOBALS['log']->fatal('Outreach: Response data is empty in api response');
                        return false;
                    }
                } else {
                    $GLOBALS['log']->fatal('Outreach: Content is empty in api response');
                    return false;
                }
            } else {
                $GLOBALS['log']->fatal('Outreach: Body is empty in api response');
                return false;
            }
        } else {
            $GLOBALS['log']->fatal('Outreach: Response is empty in api response');
            return false;
        }
        return $responseData;
    }

    public function postMethod($params) {
        $GLOBALS['log']->info('postMethod $params', $params);
        try {
            $response = (new ExternalResourceClient(60, 10))->post($params['url'], $params['data'], $params['headers']);
            return $this->handleResponse($response, $params);
        } catch (RequestException $e) {
            $GLOBALS['log']->log('Error: ' . $e->getMessage());
        }
    }

    public function getMethod($params) {
        $GLOBALS['log']->info('getMethod $params', $params);
        try {
            $response = (new ExternalResourceClient(60, 10))->get($params['url'], $params['headers']);
            return $this->handleResponse($response, $params);
        } catch (RequestException $e) {
            $GLOBALS['log']->log('Error: ' . $e->getMessage());
        }
    }

    public function putMethod($params) {
        $GLOBALS['log']->info('putMethod $params', $params);
        try {
            $response = (new ExternalResourceClient(60, 10))->put($params['url'], $params['data'], $params['headers']);
            return $this->handleResponse($response, $params);
        } catch (RequestException $e) {
            $GLOBALS['log']->log('Error: ' . $e->getMessage());
        }
    }

    public function patchMethod($params) {
        $GLOBALS['log']->info('patchMethod $params', $params);

        try {
            $response = (new ExternalResourceClient(60, 10))->patch($params['url'], $params['data'], $params['headers']);
            return $this->handleResponse($response, $params);
        } catch (RequestException $e) {
            $GLOBALS['log']->log('Error: ' . $e->getMessage());
        }
    }

    public function deleteMethod($params) {
        $GLOBALS['log']->info('deleteMethod $params', $params);
        try {
            $response = (new ExternalResourceClient(60, 10))->delete($params['url'], $params['headers']);
            return $this->handleResponse($response, $params);
        } catch (RequestException $e) {
            $GLOBALS['log']->log('Error: ' . $e->getMessage());
        }
    }

    public function curlForToken($oauthTokenUrl, $clientId, $clientSecret, $redirectUri, $grantType, $code_token) {
        $GLOBALS['log']->info('curlForToken $oauthTokenUrl', $oauthTokenUrl);
        $GLOBALS['log']->info('curlForToken $clientId', $clientId);
        $GLOBALS['log']->info('curlForToken $clientSecret', $clientSecret);
        $GLOBALS['log']->info('curlForToken $redirectUri', $redirectUri);
        $GLOBALS['log']->info('curlForToken $grantType', $grantType);
        $GLOBALS['log']->info('curlForToken $code_token', $code_token);
        $tokenType = '';
        if ($grantType == 'refresh_token') {
            $tokenType = 'refresh_token';
        } else {
            $tokenType = 'code';
        }

        try {
            $response = (new ExternalResourceClient(60, 10))->post($oauthTokenUrl, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => $grantType,
                $tokenType => $code_token
            ]);
            $GLOBALS['log']->info('curlForToken $response', $response);
            $responseData = json_decode($response->getBody()->getContents());
            $GLOBALS['log']->info('curlForToken $responseData', $responseData);
            $responseData->custom_Http_Status = $response->getStatusCode();
            return $responseData;
        } catch (RequestException $e) {
            $GLOBALS['log']->fatal('Error: ' . $e->getMessage());
        }
    }

    public function generateAccessToken($oauthTokenUrl, $clientId, $clientSecret, $redirectUrl, $grantType, $code_token) {
        return $this->curlForToken(
                        $oauthTokenUrl,
                        $clientId,
                        $clientSecret,
                        $redirectUrl,
                        $grantType,
                        $code_token
        );
    }
}
