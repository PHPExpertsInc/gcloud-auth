<?php
declare(strict_types=1);

namespace PHPExperts\GCloudAuth\Tests;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use PHPExperts\GCloudAuth\GoogleCloudAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ReadMeTest extends TestCase
{
    #[TestDox('First README example passes')]
    public function testRestSpeakerAuth()
    {
        $serviceAccountData = json_decode(file_get_contents(__DIR__ . '/../service-account.json'));
        $projectId = $serviceAccountData->project_id;

        $url = "https://storage.googleapis.com/storage/v1/b?project=$projectId";

        $api = new RESTSpeaker(new GoogleCloudAuth('service-account.json'));
        try {
            $response = $api->get($url);
        } catch (ClientException $e) {
            dd((string) $e->getResponse()->getBody());
        }

        self::assertIsObject($response);
        self::assertObjectHasProperty('kind', $response);
        self::assertEquals('storage#buckets', $response->kind);
    }

    #[TestDox('Second README example passes')]
    public function testStandaloneAuthToken()
    {
        $gcloudAuth = new GoogleCloudAuth('service-account.json');

        // Returns the raw Oauth2Token as a string.
        $gcloudOauth2Token = $gcloudAuth->obtainGCloudOauthToken();

        $serviceAccountData = json_decode(file_get_contents(__DIR__ . '/../service-account.json'));
        $projectId = $serviceAccountData->project_id;

        $url = "https://storage.googleapis.com/storage/v1/b?project=$projectId";
        $http = new \GuzzleHttp\Client([
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => "Bearer $gcloudOauth2Token",
            ]
        ]);
        $response = $http->get($url);
        self::assertInstanceOf(Response::class, $response);
        $data = json_decode($response->getBody()->getContents());

        self::assertIsObject($data);
        self::assertObjectHasProperty('kind', $data);
        self::assertEquals('storage#buckets', $data->kind);
    }
}
