<?php

namespace Core\Http;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use JsonSerializable;

/** Keeps pagination keys intact before resources hide internal IDs or selected fields. */
class CollectionPage implements Arrayable, JsonSerializable
{
    public function __construct(private Collection $items, private array $metadata, private array $fields, private array $joined, private bool $flat = false) {}

    public function getCollection(): Collection
    {
        return $this->items;
    }

    public function setCollection(Collection $items): static
    {
        $this->items = $items;

        return $this;
    }

    public function through(callable $callback): static
    {
        $this->items = $this->items->map($callback);

        return $this;
    }

    public function withQueryString(): static
    {
        return $this;
    }

    public function toArray(): array
    {
        $items = $this->items->values()->map(function ($item, int $index): array {
            $data = $item instanceof JsonResource ? $item->resolve(request()) : ($item instanceof Arrayable ? $item->toArray() : (array) $item);
            if ($this->fields !== []) {
                $data = array_intersect_key($data, array_flip($this->fields));
            }

            return [...$data, ...($this->joined[$index] ?? [])];
        })->all();

        return $this->flat ? $items : [...$this->metadata, 'data' => $items];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
