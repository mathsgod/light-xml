<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use SplObjectStorage;
use WeakMap;

abstract class XNode
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

    /** Keep document-level metadata when the container must rebuild its DOM. */
    protected function replaceDocumentNode(AnnotationDocument $document): void
    {
        $previous = $this->node;
        if ($previous === $document) {
            return;
        }
        if ($previous instanceof AnnotationDocument && isset($previous->annotations[$previous])) {
            $document->annotations ??= new SplObjectStorage();
            $document->annotations[$document] = $previous->annotations[$previous];
            unset($previous->annotations[$previous]);
        }
        $this->node = $document;
    }

    protected static function wrapNode(DOMNode $node): self
    {
        return match (true) {
            $node instanceof \DOMElement => new XElement($node),
            $node instanceof \DOMComment => new XComment($node),
            $node instanceof \DOMCdataSection => new XCData($node),
            $node instanceof \DOMText => new XText($node),
            default => new XContentNode($node),
        };
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
