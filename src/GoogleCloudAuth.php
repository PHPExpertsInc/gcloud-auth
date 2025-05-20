<?php
declare(strict_types=1);

/**
 * This file is part of GCloud Auth, a PHP Experts, Inc., Project.
 *
 * Copyright © 2025 PHP Experts, Inc.
 * Author: Theodore R. Smith <theodore@phpexperts.pro>
 *   GPG Fingerprint: 4BF8 2613 1C34 87AC D28F  2AD8 EB24 A91D D612 5690
 *   https://www.phpexperts.pro/
 *   https://github.com/PHPExpertsInc/gcloud-auth
 *
 * This file is licensed under the MIT License.
 */

namespace PHPExperts\GCloudAuth;

use JsonException;
use PHPExperts\RESTSpeaker\NoAuth;
use PHPExperts\RESTSpeaker\RESTAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;
use RuntimeException;

class GoogleCloudAuth extends RESTAuth
{
    private array $serviceAccountData;
    private ?string $oauth2Token = null;
    private int $expiresAt = -1;

    /** @throws JsonException */
    public function __construct(string $serviceJSON)
    {
        // If it ends with .json, assume it is a file.
        if (str_ends_with($serviceJSON, '.json')) {
            // if It starts with '/', assume it's the file path.
            if (str_starts_with($serviceJSON, '/') === true) {
                $serviceAccountFile = $serviceJSON;
            } else {
                $appDir = self::findProjectRoot();
                $serviceAccountFile = $appDir . '/' . $serviceJSON;
            }

            if (!is_readable($serviceAccountFile)) {
                throw new \RuntimeException("Cannot read '$serviceAccountFile'.");
            }

            $serviceAccountData = json_decode(file_get_contents($serviceAccountFile), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON in the service account file.');
            }

            $this->serviceAccountData = $serviceAccountData;
        } else {
            $this->serviceAccountData = json_decode($serviceJSON, true, flags: JSON_THROW_ON_ERROR);
        }

        parent::__construct(RESTAuth::AUTH_MODE_OAUTH2);
    }

    protected function generateOAuth2TokenOptions(): array
    {
        return [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->obtainGCloudOauthToken()
            ]
        ];
    }

    public function obtainGCloudOauthToken($time = 'time'): string
    {
        $time = $time();
        if ($this->oauth2Token !== null && $time <= $this->expiresAt) {
            return $this->oauth2Token;
        }

        $serviceAccount = $this->serviceAccountData;

        if (!isset($serviceAccount['client_email'], $serviceAccount['private_key'], $serviceAccount['token_uri'])) {
            throw new RuntimeException('Invalid service account JSON');
        }

        $clientEmail = $serviceAccount['client_email'];
        $privateKey  = $serviceAccount['private_key'];
        $tokenUri    = $serviceAccount['token_uri'];
        $scope       = 'https://www.googleapis.com/auth/cloud-platform';

        // dd([
        //     'clientEmail' => $clientEmail,
        //     'privateKey'  => $privateKey,
        //     'tokenUri'    => $tokenUri,
        //     'scope '      => $scope ,
        // ]);

        // Create JWT components
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now    = time();
        $claim  = [
            'iss'   => $clientEmail,
            'scope' => $scope,
            'aud'   => $tokenUri,
            'exp'   => $now + 3600, // 1 hour expiration
            'iat'   => $now,
        ];

        $headerBase64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
        $claimBase64  = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($claim)));
        $unsignedJwt  = "$headerBase64.$claimBase64";

        // Sign the JWT
        $signature = '';
        openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureBase64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $jwtAssertion    = "$unsignedJwt.$signatureBase64";

        // Exchange JWT for access token using RESTSpeaker
        $api = new class($tokenUri) extends RESTSpeaker {
            public function __construct(string $baseUrl)
            {
                parent::__construct(new NoAuth(), $baseUrl);
            }
        };

        $response = $api->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwtAssertion,
        ], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        if (!isset($response->access_token)) {
            throw new \RuntimeException('Failed to obtain access token: ' . json_encode($response)); // @codeCoverageIgnore
        }

        $this->expiresAt = $response->expires_in + $time;

        $this->oauth2Token = $response->access_token;

        return $this->oauth2Token;
    }

    /**
     * Finds the root path of a Composer project by looking for the composer.json file.
     *
     * @param string|null $startingPath The directory path to start searching from (defaults to current working directory)
     * @return string The absolute path to the project root
     * @throws RuntimeException If composer.json cannot be found
     *
     * Copied from phpexperts/mini-api-framework, with permission.
     */
    private static function findProjectRoot(?string $startingPath = null): string
    {
        $currentPath = $startingPath ?: getcwd();

        // Normalize the path (remove trailing slashes, resolve relative paths)
        $currentPath = realpath($currentPath);

        if ($currentPath === false) {
            throw new RuntimeException("The starting path does not exist");
        }

        // Check if we've reached the filesystem root
        while ($currentPath !== '/' && $currentPath !== '') {
            // Check for composer.json in the current directory
            if (file_exists($currentPath . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $currentPath;
            }

            // Move up one directory level
            $parentPath = dirname($currentPath);

            // Prevent infinite loop if we can't go up further
            if ($parentPath === $currentPath) {
                break;
            }

            $currentPath = $parentPath;
        }

        throw new RuntimeException('Could not find composer.json in any parent directory');
    }
    public function getGoogleServiceAccountData(): array
    {
        return $this->serviceAccountData;
    }

    public function getProjectId(): string
    {
        if (!array_key_exists('project_id', $this->getGoogleServiceAccountData())) {
            throw new \InvalidArgumentException('The Google service-account.json does not contain the project_id.');
        }

        return $this->serviceAccountData['project_id'];
    }
}
