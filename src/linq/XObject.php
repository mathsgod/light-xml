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
    /** @var WeakMap<DOMDocument, SplObjectStorage<DOMNode, list<object>>>|null */
    private static ?WeakMap $externalAnnotations = null;

    /** @var WeakMap<DOMNode, list<object>>|null */
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
            foreach ($this->annotationStore()[$this->node] ?? [] as $annotation) {
                if ($annotation instanceof $type) {
                    yield $annotation;
                }
            }
        });
    }

    /** @param class-string $type */
    public function removeAnnotations(string $type): void
    {
        $store = $this->annotationStore();
        $remaining = array_values(array_filter(
            $store[$this->node] ?? [],
            static fn (object $annotation): bool => !$annotation instanceof $type,
        ));
        if ($remaining === []) {
            unset($store[$this->node]);
        } else {
            $store[$this->node] = $remaining;
        }
    }

    /** @return SplObjectStorage<DOMNode, list<object>>|WeakMap<DOMNode, list<object>> */
    private function annotationStore(): SplObjectStorage|WeakMap
    {
        $document = $this->node instanceof DOMDocument
            ? $this->node
            : ($this->node->ownerDocument ?? $this->annotationDocument);
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
