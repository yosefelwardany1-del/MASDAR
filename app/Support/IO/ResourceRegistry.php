<?php

namespace App\Support\IO;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The single registry of import/export resources: the ResourceSchema classes listed in
 * config('io.resources'). Add a module by listing its schema there — no new controllers,
 * exporters or importers required.
 */
class ResourceRegistry
{
    /** @var array<string, ResourceSchema> */
    private array $schemas = [];

    public function __construct()
    {
        foreach ((array) config('io.resources', []) as $class) {
            $schema = new $class;
            $this->schemas[$schema->key()] = $schema;
        }
    }

    public function get(string $key): ResourceSchema
    {
        return $this->schemas[$key] ?? throw new NotFoundHttpException("Unknown import/export resource: {$key}");
    }

    /** @return array<string, ResourceSchema> */
    public function all(): array
    {
        return $this->schemas;
    }
}
