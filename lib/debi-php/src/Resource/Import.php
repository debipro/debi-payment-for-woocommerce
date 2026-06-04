<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A bulk import job.
 *
 * Sample id: `IMB1rRDqkM5X`.
 *
 * @property string  $id
 * @property string  $object
 * @property string  $status
 * @property string  $resource
 * @property int     $valid_rows_count
 * @property int     $invalid_rows_count
 * @property ?string $ready_at
 * @property ?string $processed_at
 * @property ?string $cancelled_at
 * @property ?string $invalid_at
 * @property string  $created_at
 */
final class Import extends ApiResource
{
    public const OBJECT_NAME = 'import';
}
