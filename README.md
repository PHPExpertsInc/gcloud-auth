# Google Cloud Auth

[![TravisCI]()]()
[![Maintainability]()]()
[![Test Coverage]()]()

GCloud Auth is a PHP Experts, Inc., Project for authenticating with Google Cloud API without 
needing the huge gcloud binary.

As of April 2025, the `google-cloud-cli` weighs in at around 150 MB, not counting dependencies,
and over 550 MB counting dependencies, on modern Linux systems.

Using the `google/apiclient` composer package itself weighs in at 141 MB.

This project, by comparison, weighs in around 10 KB (or 15,000x smaller).

It provides everything you need to retrieve an OAuth2 token for authenticating with Google Cloud
API services. It autogenerates the JWT token necessary for this, using ext-openssl.

It includes a drop-in `phpexperts/rest-speaker` `GoogleAuth` driver, but it can
also be used standalone by any PHP project.

## Installation

Via Composer

```bash
composer require phpexperts/gcloud-auth
```

## Usage

For use with `phpexperts/rest-speaker`:

```php
use PHPExperts\GoogleCloudAuth\GoogleCloudAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;

$api = new RESTSpeaker(new GoogleCloudAuth('relative/path/to/services.json'));
```

For use with any other PHP project:

```php
use PHPExperts\GoogleCloudAuth\GoogleCloudAuth;
$gcloudAuth = new GoogleCloudAuth('relative/path/to/services.json');

// Returns the raw Oauth2Token as a string.
$gcloudOauth2Token = $gcloudAuth->obtainGCloudOauthToken();

$url = "https://storage.googleapis.com/storage/v1/b?project=$projectId";
$http = new \GuzzleHttp\Client([
    'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => "Bearer $gcloudOauth2Token",
    ]
]);
$response = $http->get($url);
```

## Use cases

✔ Can authenticate with GCloud
✔ Handles invalid service account path
✔ Handles invalid service account data
✔ Handles error responses from Google Cloud
✔ Can override time for testing
✔ Will cache the OAuth2 Token until expiration
✔ Can synthesize speech using the GCloud API

## Testing

```bash
phpunit --testdox
```

## Contributors

[Theodore R. Smith](https://www.phpexperts.pro/]) <theodore@phpexperts.pro>  
GPG Fingerprint: 4BF8 2613 1C34 87AC D28F  2AD8 EB24 A91D D612 5690  
CEO: PHP Experts, Inc.

## License

MIT license. Please see the [license file](LICENSE) for more information.
