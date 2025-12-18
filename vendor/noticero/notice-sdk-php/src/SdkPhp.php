<?php

namespace Notice\SdkPhp;

class SdkPhp
{
    protected $token;
    protected $httpClient;
    protected $url = 'https://api.notice.ro/api/v1/';

    public function __construct($token)
    {
        $this->token = $token;
        $this->httpClient = new \GuzzleHttp\Client([
            'base_uri' => $this->url,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ]
        ]);
    }

    public function getIncomeSmsList()
    {
        $response = $this->httpClient->get('sms-in');

        return json_decode($response->getBody()->getContents());
    }

    public function getSentSmsList()
    {
        $response = $this->httpClient->get('sms-out');

        return json_decode($response->getBody()->getContents());
    }

    public function getTemplates()
    {
        $response = $this->httpClient->get('templates');

        return json_decode($response->getBody()->getContents());
    }

    public function sendSms($data)
    {
        if(!isset($data['number'])) {
            throw new \Exception('Missing recipient number!');
        }

        if(!isset($data['message']) && !isset($data['template_id'])) {
            throw new \Exception('Missing message or template ID in request!');
        }

        $response = $this->httpClient->post('sms-out', [
            'json' => $data
        ]);

        return json_decode($response->getBody()->getContents());
    }
}
