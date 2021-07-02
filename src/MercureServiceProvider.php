<?php

namespace Drupal\mercure;

use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Messenger\UpdateHandler;

class MercureServiceProvider implements ServiceProviderInterface, CompilerPassInterface
{
    private ContainerBuilder $container;

    /**
     * Shamelessly stolen and altered for Drupal from
     * @see https://github.com/symfony/mercure-bundle/blob/976062f11649605122b5514cb8e534a29e830123/src/DependencyInjection/MercureExtension.php
     *
     * Credits go to them, not me
     */
    public function register(ContainerBuilder $container)
    {
        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container)
    {
        $this->container = $container;
        if (
            !$container->hasParameter('mercure')
            || !($config = $container->getParameter('mercure'))
            || empty($config['hubs'] ?? [])) {
            return;
        }

        $defaultCookieLifetime = 0;
        if ($container->hasParameter('session.storage.options')) {
            $defaultCookieLifetime = $container->getParameter('session.storage.options')['cookie_lifetime'] ?? $defaultCookieLifetime;
        }

        $config['default_cookie_lifetime'] ??= $defaultCookieLifetime;

        $defaultPublisher = null;
        $defaultHubId = null;
        $hubs = [];
        foreach ($config['hubs'] as $name => $hub) {
            $tokenFactory = null;
            if (!isset($hub['jwt'])) {
                throw new \UnexpectedValueException(
                    sprintf('Parameter "mercure.hubs.%s.jwt" is not set. The mercure module needs at least a mercure.hubs.%s.jwt.secret parameter to continue.', $name, $name)
                );
            }

            [$tokenProvider, $tokenFactory] = $this->registerTokenProviderAndTokenFactory($name, $hub);

            $container->registerAliasForArgument($tokenProvider, TokenProviderInterface::class, $name);
            $container->registerAliasForArgument($tokenProvider, TokenProviderInterface::class, sprintf('%sProvider', $name));
            $container->registerAliasForArgument($tokenProvider, TokenProviderInterface::class, sprintf('%sTokenProvider', $name));

            $hubId = sprintf('mercure.hub.%s', $name);
            $publisherId = sprintf('mercure.hub.%s.publisher', $name);
            $hubs[$name] = new Reference($hubId);
            if (!$defaultPublisher && ($config['default_hub'] ?? $name) === $name) {
                $defaultHubId = $hubId;
                $defaultPublisher = $publisherId;
            }

            $this->registr($hubId, Hub::class)
                ->addArgument($hub['url'])
                ->addArgument(new Reference($tokenProvider))
                ->addArgument($tokenFactory ? new Reference($tokenFactory) : null)
                ->addArgument($hub['public_url'] ?? null)
                //->addArgument(new Reference('http_client', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))
                ->addTag('mercure.hub');

            $container->registerAliasForArgument($hubId, HubInterface::class, sprintf('%sHub', $name));
            $container->registerAliasForArgument($hubId, HubInterface::class, $name);

            $bus = $hub['bus'] ?? null;
            $attributes = null === $bus ? [] : ['bus' => $hub['bus']];

            $messengerHandlerId = sprintf('mercure.hub.%s.message_handler', $name);
            $this->registr($messengerHandlerId, UpdateHandler::class)
                ->addArgument(new Reference($hubId))
                ->addTag('messenger.message_handler', $attributes);
        }

        $container->setAlias(HubInterface::class, $defaultHubId);

        $this->registr(HubRegistry::class)
            ->addArgument(new Reference($defaultHubId))
            ->addArgument($hubs);

        $this->registr(Authorization::class)
            ->addArgument(new Reference(HubRegistry::class))
            ->addArgument($config['default_cookie_lifetime']);

        $this->registr(Discovery::class, Discovery::class)
            ->addArgument(new Reference(HubRegistry::class));
    }

    private function registerTokenProviderAndTokenFactory(string $name, array $hub): array
    {
        $tokenFactory = $tokenProvider = null;

        // First we check if a jwt value is set. If so we ignore the rest.
        if (isset($hub['jwt']['value'])) {
            $tokenProvider = sprintf('mercure.hub.%s.jwt.provider', $name);

            $this->registr($tokenProvider, StaticTokenProvider::class)
                ->addArgument($hub['jwt']['value'])
                ->addTag('mercure.jwt.provider');

            return [$tokenProvider, $tokenFactory];
        }

        // No jwt value is set, perhaps a jwt provider?
        if (isset($hub['jwt']['provider'])) {
            $tokenProvider = $hub['jwt']['provider'];
            return [$tokenProvider, $tokenFactory];
        }

        // No provider is set, perhaps a jwt factory?
        if (isset($hub['jwt']['factory'])) {
            $tokenFactory = $hub['jwt']['factory'];
            return [$tokenProvider, $tokenFactory];
        }

        // No jwt value, provider or factory is set. Finally check the secret
        // This is the last possible option, so fail if it is not set.
        if (empty($hub['jwt']['secret'])) {
            throw new \UnexpectedValueException(
                sprintf('Parameter "mercure.hubs.%s.jwt.secret" is not set.', $name)
            );
        }

        $tokenFactory = sprintf('mercure.hub.%s.jwt.factory', $name);
        $this->registr($tokenFactory, LcobucciFactory::class)
            ->addArgument($hub['jwt']['secret'])
            ->addArgument($hub['jwt']['algorithm'] ?? 'hmac.sha256')
            ->addTag('mercure.jwt.factory');

        $tokenProvider = sprintf('mercure.hub.%s.jwt.provider', $name);
        $this->registr($tokenProvider, FactoryTokenProvider::class)
            ->addArgument(new Reference($tokenFactory))
            ->addArgument($hub['jwt']['subscribe'] ?? [])
            ->addArgument($hub['jwt']['publish'] ?? [])
            ->addTag('mercure.jwt.factory');

        $this->container->registerAliasForArgument($tokenFactory, TokenFactoryInterface::class, $name);
        $this->container->registerAliasForArgument(
            $tokenFactory,
            TokenFactoryInterface::class,
            sprintf('%sFactory', $name)
        );
        $this->container->registerAliasForArgument(
            $tokenFactory,
            TokenFactoryInterface::class,
            sprintf('%sTokenFactory', $name)
        );

        return [$tokenProvider, $tokenFactory];
    }

    private function registr(string $id, ?string $class = null): Definition
    {
        // This is a workaround because Drupal's container->register() requires
        // an id to be lowercase and as such it can't be used to register classes.
        // </rage>
        return $this->container->setDefinition($id, new Definition($class ?? $id));
    }
}
