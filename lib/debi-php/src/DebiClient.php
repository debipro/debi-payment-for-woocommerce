<?php

declare(strict_types=1);

namespace Debi;

use Debi\HttpClient\ClientInterface as HttpClientInterface;
use Debi\HttpClient\DefaultClient;
use Debi\Service\AbstractService;
use Debi\Service\BillingPortalConfigurationService;
use Debi\Service\BillingPortalSessionService;
use Debi\Service\CustomerService;
use Debi\Service\EventService;
use Debi\Service\ExportService;
use Debi\Service\GatewayService;
use Debi\Service\ImportService;
use Debi\Service\LinkService;
use Debi\Service\MandateService;
use Debi\Service\PaymentMethodService;
use Debi\Service\PaymentService;
use Debi\Service\RefundService;
use Debi\Service\SessionService;
use Debi\Service\SubscriptionService;
use Debi\Service\WebhookEndpointService;

/**
 * Main entry point for the Debi PHP SDK.
 *
 * Construct one client per API key. Resource operations are dispatched through
 * lazily-instantiated services accessed as properties on the client:
 *
 *     $debi = new \Debi\DebiClient('sk_live_...');
 *     $customer = $debi->customers->create(['email' => 'a@b.com']);
 *
 * @property-read CustomerService $customers
 * @property-read PaymentService $payments
 * @property-read SubscriptionService $subscriptions
 * @property-read MandateService $mandates
 * @property-read PaymentMethodService $paymentMethods
 * @property-read RefundService $refunds
 * @property-read SessionService $sessions
 * @property-read LinkService $links
 * @property-read EventService $events
 * @property-read ExportService $exports
 * @property-read ImportService $imports
 * @property-read GatewayService $gateways
 * @property-read WebhookEndpointService $webhookEndpoints
 * @property-read BillingPortalSessionService $billingPortalSessions
 * @property-read BillingPortalConfigurationService $billingPortalConfigurations
 */
final class DebiClient
{
    public const DEFAULT_API_BASE = 'https://api.debi.pro';
    public const DEFAULT_SANDBOX_BASE = 'https://api.debi-test.pro';

    private readonly string $apiKey;
    private readonly string $apiBase;
    private readonly string $apiVersion;
    private readonly ApiRequestor $requestor;

    /** @var array<string, AbstractService> */
    private array $services = [];

    /**
     * @param string|array{
     *     api_key?: string,
     *     api_base?: string,
     *     api_version?: string,
     *     http_client?: HttpClientInterface,
     * } $apiKeyOrConfig  Plain API key for the common case, or an associative config array.
     * @param array<string,mixed> $config           Used only when the first argument is a string.
     */
    public function __construct(string|array $apiKeyOrConfig, array $config = [])
    {
        if (is_array($apiKeyOrConfig)) {
            $config = $apiKeyOrConfig;
        } else {
            $config = ['api_key' => $apiKeyOrConfig] + $config;
        }

        $apiKey = $config['api_key'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'Debi API key is required. Pass the secret key as the first argument '
                . 'or as `api_key` in the config array.'
            );
        }

        $this->apiKey = $apiKey;
        $this->apiBase = isset($config['api_base']) && is_string($config['api_base']) && $config['api_base'] !== ''
            ? rtrim($config['api_base'], '/')
            : self::DEFAULT_API_BASE;
        $this->apiVersion = isset($config['api_version']) && is_string($config['api_version']) && $config['api_version'] !== ''
            ? $config['api_version']
            : Debi::API_VERSION;

        $httpClient = $config['http_client'] ?? null;
        if ($httpClient !== null && !$httpClient instanceof HttpClientInterface) {
            throw new \InvalidArgumentException(
                '`http_client` must implement \\Debi\\HttpClient\\ClientInterface. '
                . 'To use a custom PSR-18 client, wrap it with \\Debi\\HttpClient\\DefaultClient.'
            );
        }
        $httpClient ??= new DefaultClient($config);

        $this->requestor = new ApiRequestor(
            httpClient: $httpClient,
            apiKey: $this->apiKey,
            apiBase: $this->apiBase,
            apiVersion: $this->apiVersion,
        );
    }

    public function __get(string $name): AbstractService
    {
        return $this->services[$name] ??= $this->buildService($name);
    }

    private function buildService(string $name): AbstractService
    {
        return match ($name) {
            'customers' => new CustomerService($this->requestor),
            'payments' => new PaymentService($this->requestor),
            'subscriptions' => new SubscriptionService($this->requestor),
            'mandates' => new MandateService($this->requestor),
            'paymentMethods' => new PaymentMethodService($this->requestor),
            'refunds' => new RefundService($this->requestor),
            'sessions' => new SessionService($this->requestor),
            'links' => new LinkService($this->requestor),
            'events' => new EventService($this->requestor),
            'exports' => new ExportService($this->requestor),
            'imports' => new ImportService($this->requestor),
            'gateways' => new GatewayService($this->requestor),
            'webhookEndpoints' => new WebhookEndpointService($this->requestor),
            'billingPortalSessions' => new BillingPortalSessionService($this->requestor),
            'billingPortalConfigurations' => new BillingPortalConfigurationService($this->requestor),
            default => throw new \InvalidArgumentException(
                "Unknown Debi service: '{$name}'. "
                . "Available: customers, payments, subscriptions, mandates, paymentMethods, "
                . "refunds, sessions, links, events, exports, imports, gateways, webhookEndpoints, "
                . "billingPortalSessions, billingPortalConfigurations."
            ),
        };
    }

    public function apiBase(): string
    {
        return $this->apiBase;
    }

    public function apiVersion(): string
    {
        return $this->apiVersion;
    }
}
