<?php

declare(strict_types=1);

namespace Debi;

/**
 * Marker base class for top-level Debi API resources (those with a stable URL
 * such as `/v1/customers/{id}`). Subclasses declare {@see ApiResource::OBJECT_NAME}
 * which is used by {@see Util\Util} to deserialize responses to the right type.
 *
 * Behavior (CRUD, actions) lives on {@see Service\AbstractService} subclasses,
 * not here — keeping resources as pure data objects makes them safe to pass
 * around, cache, and serialize without dragging the HTTP layer with them.
 */
abstract class ApiResource extends DebiObject
{
    /** Value of the `object` field for instances of this resource. */
    public const OBJECT_NAME = '';
}
