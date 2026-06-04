<?php

declare(strict_types=1);

namespace Debi\Util;

use Debi\DebiObject;
use Debi\Resource;

/**
 * Internal helpers. No public stability guarantees beyond what the changelog
 * records — user code should not import this class directly.
 *
 * @internal
 */
final class Util
{
    /**
     * Map of API `object` discriminator => concrete resource class.
     *
     * Resources not listed fall back to a plain {@see DebiObject}, which means
     * the SDK keeps working even when the API introduces a new resource type
     * before a new SDK release is published.
     *
     * Lists are not mapped here — they have no `object` discriminator. They
     * are explicitly constructed by {@see Service\AbstractService::requestCollection()}.
     *
     * @var array<string, class-string<DebiObject>>
     */
    private const RESOURCE_MAP = [
        'customer' => Resource\Customer::class,
        'payment' => Resource\Payment::class,
        'subscription' => Resource\Subscription::class,
        'mandate' => Resource\Mandate::class,
        'payment_method' => Resource\PaymentMethod::class,
        'refund' => Resource\Refund::class,
        'session' => Resource\Session::class,
        'link' => Resource\Link::class,
        'event' => Resource\Event::class,
        'export' => Resource\Export::class,
        'import' => Resource\Import::class,
        'gateway' => Resource\Gateway::class,
        'webhook' => Resource\WebhookEndpoint::class,
        // Billing-portal resources use Stripe-style dotted discriminators
        // (see openapi components/schemas/BillingPortal*.yaml); the rest of
        // the surface area still uses bare snake_case names.
        'billing_portal.session' => Resource\BillingPortalSession::class,
        'billing_portal.configuration' => Resource\BillingPortalConfiguration::class,
    ];

    private function __construct() {}

    /**
     * Recursively convert a decoded JSON value into the matching object graph.
     * Arrays carrying an `object` field are upgraded to concrete subclasses;
     * everything else falls back to {@see DebiObject}.
     */
    public static function convertToObject(mixed $value): mixed
    {
        if (is_array($value)) {
            if (self::isSequential($value)) {
                return array_map(self::convertToObject(...), $value);
            }
            $object = is_string($value['object'] ?? null) ? $value['object'] : null;
            $class = ($object !== null && isset(self::RESOURCE_MAP[$object]))
                ? self::RESOURCE_MAP[$object]
                : DebiObject::class;

            /** @var DebiObject $instance */
            $instance = $class::constructFrom($value);
            return $instance;
        }
        return $value;
    }

    /**
     * @param array<int|string,mixed> $array
     */
    public static function isSequential(array $array): bool
    {
        return $array === [] || array_is_list($array);
    }

    /**
     * Replace resource objects with their ids when encoding parameters. Lets
     * users pass either a `Customer` or a string id to methods that expect one.
     *
     * @param array<int|string,mixed> $params
     * @return array<int|string,mixed>
     */
    public static function objectsToIds(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v instanceof DebiObject && isset($v->id) && is_string($v->id)) {
                $out[$k] = $v->id;
            } elseif (is_array($v)) {
                $out[$k] = self::objectsToIds($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
