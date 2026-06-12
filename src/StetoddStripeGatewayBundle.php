<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle;

use Stetodd\PaymentGateway\PaymentGatewayInterface;
use Stetodd\StripeGatewayBundle\Webhook\StripeRequestParser;
use Stripe\StripeClient;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class StetoddStripeGatewayBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @psalm-suppress PossiblyUndefinedMethod, MixedMethodCall */
        $definition->rootNode()
            ->children()
                ->scalarNode('secret_key')->defaultValue('%env(STRIPE_SECRET_KEY)%')->end()
                ->scalarNode('checkout_success_url')->defaultValue('%env(STRIPE_CHECKOUT_SUCCESS_URL)%')->end()
                ->scalarNode('checkout_cancel_url')->defaultValue('%env(STRIPE_CHECKOUT_CANCEL_URL)%')->end()
                ->scalarNode('webhook_path')->defaultValue('/webhook/stripe')->end()
            ->end();
    }

    /**
     * @param array{secret_key: string, checkout_success_url: string, checkout_cancel_url: string, webhook_path: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(StripeClientFactory::class)
            ->args([$config['secret_key']]);

        $services->set(StripeClient::class)
            ->factory([service(StripeClientFactory::class), 'create']);

        $services->set(StripePaymentGateway::class)
            ->args([
                service(StripeClient::class),
                $config['checkout_success_url'],
                $config['checkout_cancel_url'],
                service('logger')->nullOnInvalid(),
            ]);

        $services->alias(PaymentGatewayInterface::class, StripePaymentGateway::class);

        $services->set(StripeRequestParser::class)
            ->args([
                service('serializer'),
                $config['webhook_path'],
            ]);
    }
}
