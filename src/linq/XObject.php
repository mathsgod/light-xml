<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use SplObjectStorage;
use WeakMap;

abstract class XObject
{
    /** @var WeakMap<DOMDocument, SplObjectStorage<DOMNode, array<int|string, object|array>>>|null */
    private static ?WeakMap $externalAnnotations = null;

    /** @var WeakMap<DOMNode, array<int|string, object|array>>|null */
    private static ?WeakMap $detachedAnnotations = null;

    // Keep the owner wrapper alive so later queries see the same annotation store.
    private readonly ?DOMDocument $annotationDocument;

    protected function __construct(protected DOMNode $node)
    {
        $this->annotationDocument = $node->ownerDocument;
    }

    public function nodeType(): int
    {
        return $this->node->nodeType;
    }

    public function baseUri(): string
    {
        return $this->node->baseURI ?? '';
    }

    public function parent(): ?XElement
    {
        $parent = $this->node instanceof DOMAttr ? $this->node->ownerElement : $this->node->parentNode;
        return $parent instanceof DOMElement ? new XElement($parent) : null;
    }

    public function document(): ?XDocument
    {
        $node = $this->node instanceof DOMAttr ? $this->node->ownerElement : $this->node;
        while ($node?->parentNode !== null) {
            $node = $node->parentNode;
        }
        return $node instanceof AnnotationDocument ? $node->wrapper : null;
    }

    private static bool $dispatchingChange = false;

    /** @return \Closure(): void Unsubscribe from this event. */
    public function onChanging(callable $listener): \Closure
    {
        return $this->subscribe('changing', $listener);
    }

    /** @return \Closure(): void Unsubscribe from this event. */
    public function onChanged(callable $listener): \Closure
    {
        return $this->subscribe('changed', $listener);
    }

    private function subscribe(string $phase, callable $listener): \Closure
    {
        $store = $this->annotationStore();
        $entries = $store[$this->node] ?? [];
        $callback = static fn (XObject $sender, XObjectChange $change) => $listener($sender, $change);
        $id = spl_object_id($callback);
        $entries[$phase][$id] = $callback;
        $store[$this->node] = $entries;

        return function () use ($phase, $id, $callback): void {
            $store = $this->annotationStore();
            $entries = $store[$this->node] ?? [];
            if (($entries[$phase][$id] ?? null) === $callback) {
                unset($entries[$phase][$id]);
                if ($entries[$phase] === []) {
                    unset($entries[$phase]);
                }
                $this->saveEntries($store, $entries);
            }
        };
    }

    protected function change(XObjectChange $change, callable $mutation, ?DOMNode $parent = null): void
    {
        if (self::$dispatchingChange) {
            throw new \LogicException('XML cannot be modified from a change listener.');
        }

        $listeners = ['changing' => [], 'changed' => []];
        for ($node = $this->node; $node !== null; $node = $parent ?? self::eventParent($node), $parent = null) {
            $store = self::metadataStore($node, $this->annotationDocument);
            $entries = $store[$node] ?? [];
            foreach ($listeners as $phase => $callbacks) {
                array_push($listeners[$phase], ...array_values($entries[$phase] ?? []));
            }
        }

        $this->dispatch($listeners['changing'], $change);
        $mutation();
        $this->dispatch($listeners['changed'], $change);
    }

    private static function eventParent(DOMNode $node): ?DOMNode
    {
        return $node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
    }

    private function dispatch(array $listeners, XObjectChange $change): void
    {
        self::$dispatchingChange = true;
        try {
            foreach ($listeners as $listener) {
                $listener($this, $change);
            }
        } finally {
            self::$dispatchingChange = false;
        }
    }

    public function addAnnotation(object $annotation): void
    {
        $store = $this->annotationStore();
        $annotations = $store[$this->node] ?? [];
        $annotations[] = $annotation;
        $store[$this->node] = $annotations;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public function annotation(string $type): ?object
    {
        foreach ($this->annotations($type) as $annotation) {
            return $annotation;
        }
        return null;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return IEnumerable<T>
     */
    public function annotations(string $type): IEnumerable
    {
        return Enumerable::defer(function () use ($type): iterable {
            foreach ($this->annotationStore()[$this->node] ?? [] as $key => $annotation) {
                if (is_int($key) && $annotation instanceof $type) {
                    yield $annotation;
                }
            }
        });
    }

    /** @param class-string $type */
    public function removeAnnotations(string $type): void
    {
        $store = $this->annotationStore();
        $remaining = array_filter(
            $store[$this->node] ?? [],
            static fn (mixed $entry, int|string $key): bool => is_string($key) || !$entry instanceof $type,
            ARRAY_FILTER_USE_BOTH,
        );
        $this->saveEntries($store, $remaining);
    }

    private function saveEntries(SplObjectStorage|WeakMap $store, array $remaining): void
    {
        if ($remaining === []) {
            unset($store[$this->node]);
        } else {
            $store[$this->node] = $remaining;
        }
    }

    /** @return SplObjectStorage<DOMNode, array<int|string, object|array>>|WeakMap<DOMNode, array<int|string, object|array>> */
    private function annotationStore(): SplObjectStorage|WeakMap
    {
        return self::metadataStore($this->node, $this->annotationDocument);
    }

    private static function metadataStore(DOMNode $node, ?DOMDocument $fallback): SplObjectStorage|WeakMap
    {
        $document = $node instanceof DOMDocument ? $node : ($node->ownerDocument ?? $fallback);
        if ($document instanceof AnnotationDocument) {
            return $document->annotations ??= new SplObjectStorage();
        }
        if ($document === null) {
            return self::$detachedAnnotations ??= new WeakMap();
        }
        self::$externalAnnotations ??= new WeakMap();
        return self::$externalAnnotations[$document] ??= new SplObjectStorage();
    }

}
