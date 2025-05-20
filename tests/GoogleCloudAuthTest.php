<?php
declare(strict_types=1);

namespace PHPExperts\GCloudAuth\Tests;

use GuzzleHttp\Exception\ClientException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use PHPExperts\GCloudAuth\GoogleCloudAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

function time(int $secondsDiff = 0)
{
    static $secondsDiffActual = 0;
    if ($secondsDiff !== 0) {
        $secondsDiffActual = $secondsDiff;
    }
    $time = \time() + $secondsDiffActual;
    dump(\time() . ' + ' . $secondsDiffActual . ' = ' . $time);

    return $time;
}

class GoogleCloudAuthTest extends TestCase
{
    private GoogleCloudAuth $auth;
    private RESTSpeaker $api;

    private array $validServiceAccountData = [
        'type'           => 'service_account',
        'project_id'     => 'test-project-123',
        'private_key_id' => 'abc123',
        'private_key'    => '-----BEGIN PRIVATE KEY-----\nMockKey\n-----END PRIVATE KEY-----',
        'client_email'   => 'test@test-project-123.iam.gserviceaccount.com',
        'client_id'      => '123456789',
        'auth_uri'       => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri'      => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/test@test-project-123.iam.gserviceaccount.com',
        'universe_domain' => 'googleapis.com'
    ];

    public function setUp(): void
    {
        parent::setUp();

        // The class will look for service-account.json in the project root
        $this->auth = new GoogleCloudAuth('service-account.json');

        // Create a REST speaker that will use our auth
        $this->api = new RESTSpeaker($this->auth);
    }

    /**
     * Helper method to create a mock file with specified content for testing
     */
    private function createMockFileWithContent(string $content): void
    {
        $projectRoot = $this->findProjectRoot();
        $filePath = $projectRoot . '/mock-service-account.json';
        file_put_contents($filePath, $content);

        // Register cleanup
        $this->tearDownFiles[] = $filePath;
    }

    /**
     * Storage for files created during tests that need cleanup
     */
    private array $tearDownFiles = [];

    public function tearDown(): void
    {
        // Clean up any files created during tests
        foreach ($this->tearDownFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function findProjectRoot(?string $startingPath = null): string
    {
        $mirror = new ReflectionMethod(GoogleCloudAuth::class, 'findProjectRoot');
        $mirror->setAccessible(true);

        return $mirror->invoke(null, $startingPath);
    }

    #[TestDox('Can authenticate with GCloud')]
    public function testCanAuthenticateWithGCloud(): void
    {
        // This test simply verifies that auth doesn't throw exceptions
        $this->assertInstanceOf(GoogleCloudAuth::class, $this->auth);
        $response = $this->auth->obtainGCloudOauthToken();
        self::assertStringStartsWith('ya29.c.', $response);
    }

    #[TestDox('Can load the service-account.json directly as a string')]
    public function testCanLoadServiceAccountJsonDirectly()
    {
        $serviceAccountJSON = file_get_contents($this->findProjectRoot() . '/service-account.json');
        $serviceAccountData = json_decode($serviceAccountJSON);
        $expected = 'https://accounts.google.com/o/oauth2/auth';

        self::assertEquals($expected, $serviceAccountData->auth_uri);

        $this->auth = new GoogleCloudAuth($serviceAccountJSON);
        $this->assertInstanceOf(GoogleCloudAuth::class, $this->auth);
        $response = $this->auth->obtainGCloudOauthToken();
        self::assertStringStartsWith('ya29.c.', $response);
    }

    public function testHandlesInvalidServiceAccountPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Cannot read");

        new GoogleCloudAuth('/path/to/nonexistent/service-account.json');
    }

    public function testHandlesInvalidServiceAccountData(): void
    {
        try {
            $invalidJson = '{"invalid": "json"';

            $this->createMockFileWithContent($invalidJson);
            $auth = new GoogleCloudAuth('mock-service-account.json');
        } catch (RuntimeException $e) {
            self::assertEquals('Invalid JSON in the service account file.', $e->getMessage());
        }

        try {
            $invalidJson = '{"invalid": "json"}';

            $this->createMockFileWithContent($invalidJson);
            $auth = new GoogleCloudAuth('mock-service-account.json');
            $auth->obtainGCloudOauthToken();
        } catch (RuntimeException $e) {
            self::assertEquals('Invalid service account JSON', $e->getMessage());
        }
    }

    #[TestDox('Handles error responses from Google Cloud')]
    public function testHandlesErrorResponseFromGCloud(): void
    {
        // Create a REST speaker with our auth
        $api = new RESTSpeaker($this->auth);

        // Invalid request to TTS API
        $endpoint = 'https://texttospeech.googleapis.com/v1/text:synthesize';

        // Missing required fields in payload
        $invalidPayload = [
            'input' => [],  // Missing 'text' field
            'voice' => [],  // Missing required voice fields
        ];

        // Expect an exception when making the request
        try {
            $api->post($endpoint, $invalidPayload);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Just verify we got an exception, the exact message may vary
            $this->assertStringContainsString('error', $e->getMessage());
        }
    }

    public function testCanOverrideTimeForTesting()
    {
        self::assertEquals(time(), \time());
        self::assertGreaterThan(\time() + 999, time(1000));
        self::assertGreaterThan(\time() + 999, time());
    }

    #[TestDox('Will cache the OAuth2 Token until expiration')]
    public function testWillCacheOAuth2TokenUntilExpiration()
    {
        $origOAuth2Token = $this->auth->obtainGCloudOauthToken();
        dump($origOAuth2Token);

        // Ask again, immediately. Expect the same.
        $secondOAuth2Token = $this->auth->obtainGCloudOauthToken();
        // dump($secondOAuth2Token);
        self::assertEquals($origOAuth2Token, $secondOAuth2Token);

        // Fast-forward by 5 minutes.
        time(300);
        self::assertGreaterThan(\time() + 299, time(), 'Uh oh! Overriding time() didn\'t work.');

        $thirdOAuth2Token = $this->auth->obtainGCloudOauthToken(fn () => time());
        dump($thirdOAuth2Token);
        self::assertEquals($origOAuth2Token, $thirdOAuth2Token);

        // Fast-forward by 59 minutes. Expect the same.
        time(3598);
        $newOAuth2Token = $this->auth->obtainGCloudOauthToken(fn () => time());
        dump($newOAuth2Token);
        self::assertEquals($origOAuth2Token, $newOAuth2Token);

        // Fast-forward by 1 hour exactly. Expect a new token.
        time(3600);
        $newOAuth2Token = $this->auth->obtainGCloudOauthToken(fn () => time());
        dump($newOAuth2Token);
        self::assertNotEquals($origOAuth2Token, $newOAuth2Token);

        // Fast-forward by 1 hour 5 minutes exactly. Expect the same new token.
        time(3605);
        $newerOAuth2Token = $this->auth->obtainGCloudOauthToken(fn () => time());
        self::assertEquals($newOAuth2Token, $newerOAuth2Token);
    }

    #[TestDox('Can synthesize speech using the GCloud API')]
    public function testCanSynthesizeSpeechUsingGCloud(): void
    {
        // Define the TTS API endpoint
        $endpoint = 'https://texttospeech.googleapis.com/v1/text:synthesize';

        // Create payload for the TTS API
        $payload = [
            'input' => [
                'text' => 'Hello world!',
            ],
            'voice' => [
                'languageCode' => 'en-US',
                'ssmlGender' => 'NEUTRAL',
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
            ],
        ];

        // Make the API call
        try {
            $response = $this->api->post($endpoint, $payload);
        } catch (ClientException $e) {
            dd((string) $this->api->getLastResponse()->getBody());

        }

        // Assert response has expected structure
        $this->assertObjectHasProperty('audioContent', $response);
        $this->assertNotEmpty($response->audioContent);

        // Optionally save the audio content to a file for manual verification
        $audioData = base64_decode($response->audioContent);
        $this->assertNotEmpty($audioData);

        file_put_contents(__DIR__ . '/test_output.mp3', $audioData);
    }

    /**
     * Create a testable instance by setting the service account data directly
     */
    private function createTestInstance(array $serviceAccountData): GoogleCloudAuth
    {
        // Create a JSON string from the service account data
        $serviceJSON = json_encode($serviceAccountData);

        // Use reflection to create an instance and set properties directly
        $reflection = new ReflectionClass(GoogleCloudAuth::class);

        // Create a new instance without calling the constructor
        $googleCloudAuth = $reflection->newInstanceWithoutConstructor();

        // Set the service account data
        $property = $reflection->getProperty('serviceAccountData');
        $property->setAccessible(true);
        $property->setValue($googleCloudAuth, $serviceAccountData);

        return $googleCloudAuth;
    }

    /**
     * @covers \PHPExperts\GCloudAuth\GoogleCloudAuth::getGoogleServiceAccountData
     */
    public function testGetGoogleServiceAccountData_ReturnsServiceAccountData(): void
    {
        // Arrange
        $googleCloudAuth = $this->createTestInstance($this->validServiceAccountData);

        // Act
        $result = $googleCloudAuth->getGoogleServiceAccountData();

        // Assert
        $this->assertSame($this->validServiceAccountData, $result);
        $this->assertArrayHasKey('project_id', $result);
        $this->assertArrayHasKey('private_key', $result);
        $this->assertArrayHasKey('client_email', $result);
    }

    /**
     * @covers \PHPExperts\GCloudAuth\GoogleCloudAuth::getProjectId
     */
    public function testGetProjectId_ReturnsProjectId(): void
    {
        // Arrange
        $googleCloudAuth = $this->createTestInstance($this->validServiceAccountData);
        $expectedProjectId = 'test-project-123';

        // Act
        $result = $googleCloudAuth->getProjectId();

        // Assert
        $this->assertSame($expectedProjectId, $result);
    }

    /**
     * @covers \PHPExperts\GCloudAuth\GoogleCloudAuth::getProjectId
     */
    public function testGetProjectId_ThrowsException_WhenProjectIdIsMissing(): void
    {
        // Arrange
        $incompleteServiceAccountData = $this->validServiceAccountData;
        unset($incompleteServiceAccountData['project_id']);

        $googleCloudAuth = $this->createTestInstance($incompleteServiceAccountData);

        // Assert and Act
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Google service-account.json does not contain the project_id.');

        $googleCloudAuth->getProjectId();
    }
}
