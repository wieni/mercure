Mercure Drupal module
======================

[![Latest Stable Version](https://poser.pugx.org/wieni/mercure/v/stable)](https://packagist.org/packages/wieni/mercure)
[![Total Downloads](https://poser.pugx.org/wieni/mercure/downloads)](https://packagist.org/packages/wieni/mercure)
[![License](https://poser.pugx.org/wieni/mercure/license)](https://packagist.org/packages/wieni/mercure)

> Mercure is a protocol allowing to push data updates to web browsers and other HTTP clients in a convenient, fast, reliable and battery-efficient way. It is especially useful to publish real-time updates of resources served through web APIs, to reactive web and mobile apps.

This module integrates [the Mercure Component](https://github.com/symfony/mercure) in Drupal.

## Installation

This package requires PHP 7.4 or higher and can be installed using
Composer:

```bash
composer require drupal/mercure
```

## Configuration

Config is stored as service parameters:

The `mercure` component can be configured in the `services.yml` file.

```yml
# public/sites/default/services.yml
parameters:
    mercure:
        hubs:
            default:
                # URL of the hub's publish endpoint
                url: 'https://demo.mercure.rocks/.well-known/mercure'
                # URL of the hub's public endpoint
                public_url: null
                # JSON Web Token configuration.
                jwt:
                    # JSON Web Token to use to publish to this hub.
                    value: null
                    # The ID of a service to call to provide the JSON Web Token.
                    provider: null
                    # The ID of a service to call to create the JSON Web Token.
                    factory: null
                    # A list of topics to allow publishing to when using the given factory to generate the JWT.
                    publish: []
                    # A list of topics to allow subscribing to when using the given factory to generate the JWT.
                    subscribe: []
                    # The JWT Secret to use.
                    secret: '!ChangeMe!'
                    # The algorithm to use to sign the JWT
                    algorithm: 'hmac.sha256'
        # Default lifetime of the cookie containing the JWT, in seconds. Defaults to the value of "framework.session.cookie_lifetime"
        default_cookie_lifetime: null
```

## Example

Use the `mercure.hub.default` service to inject the Hub in your services.

You can replace `default` with the hub key you used in your configuration.

```yml
# /public/modules/custom/mymodule/mymodule.services.yml

services:
    mymodule.my_service:
        class: Drupal\mymodule\MyService
        arguments:
            - '@mercure.hub.default'
```

Then use it to publish messages to Mercure.

```php
// /public/modules/custom/mymodule/MyService.php

namespace Drupal\mymodule\Controller\Node;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class MyService
{
    public function __construct(
        private HubInterface $mercure,
    ) {
    }

    public function something(): void
    {
        // Send a message to the products/e3563c99-d329-4490-aee5-579c3b6b3a8a
        // topic, notifying everyone that is subscribed to products/* that the
        // product is now out of stock.
        $update = new Update(
            'products/e3563c99-d329-4490-aee5-579c3b6b3a8a',
            json_encode(['status' => 'OutOfStock'])
        );

        $this->mercure->publish($update);
    }
}
```

### Minimal config

A minimal config looks like this:

```yaml
# public/sites/default/services.yml
parameters:
    mercure:
        hubs:
            default:
                # URL of the hub's publish endpoint
                url: 'https://demo.mercure.rocks/.well-known/mercure'
                # JSON Web Token configuration.
                jwt:
                    # The JWT Secret to use.
                    secret: '!ChangeMe!'
```

## Changelog
All notable changes to this project will be documented in the
[CHANGELOG](CHANGELOG.md) file.

## Security
If you discover any security-related issues, please email
[security@wieni.be](mailto:security@wieni.be) instead of using the issue
tracker.

## License
Distributed under the GPL version 2 License. See the [LICENSE](LICENSE.md) file
for more information.

## Acknowledgments
- [symfony/mercure-bundle](https://github.com/symfony/mercure-bundle)
    - This module is nothing more but a copy of the symfony bundle, altered for Drupal.
