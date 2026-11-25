<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;
use DOMComment;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use SplObjectStorage;

abstract class XNode extends XObject
{
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
            $node instanceof \DOMDocumentType => new XDocumentType($node),
            $node instanceof \DOMCdataSection => new XCData($node),
            $node instanceof \DOMText => new XText($node),
            default => new XContentNode($node),
        };
    }

    public function isBefore(?XNode $other): bool
    {
        return $this->compareDocumentOrder($other) < 0;
    }

    public function isAfter(?XNode $other): bool
    {
        return $this->compareDocumentOrder($other) > 0;
    }

    private function compareDocumentOrder(?XNode $other): int
    {
        if ($other === null) {
            return 1;
        }
        if ($this->node === $other->node) {
            return 0;
        }

        $left = self::pathToRoot($this->node);
        $right = self::pathToRoot($other->node);
        if (end($left) !== end($right)) {
            throw new \LogicException('The nodes do not share a common ancestor.');
        }

        while ($left !== [] && $right !== [] && end($left) === end($right)) {
            array_pop($left);
            array_pop($right);
        }
        if ($left === [] || $right === []) {
            return ($left !== []) <=> ($right !== []);
        }

        $rightBranch = end($right);
        for ($node = end($left)->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node === $rightBranch) {
                return -1;
            }
        }
        return 1;
    }

    /** @return non-empty-list<DOMNode> */
    private static function pathToRoot(DOMNode $node): array
    {
        $path = [$node];
        while ($node->parentNode !== null) {
            $node = $node->parentNode;
            $path[] = $node;
        }
        return $path;
    }

    public function nextNode(): ?self
    {
        $next = $this->node->nextSibling;
        return $next === null ? null : self::wrapNode($next);
    }

    public function previousNode(): ?self
    {
        $previous = $this->node->previousSibling;
        return $previous === null ? null : self::wrapNode($previous);
    }

    public function addBeforeSelf(mixed ...$content): void
    {
        $this->insertBeside($content);
    }

    public function addAfterSelf(mixed ...$content): void
    {
        $this->insertBeside($content, after: true);
    }

    public function replaceWith(mixed ...$content): void
    {
        $this->insertBeside($content, replace: true);
    }

    private function insertBeside(array $content, bool $after = false, bool $replace = false): void
    {
        $target = $this->node;
        $parent = $target->parentNode;
        if ($parent === null) {
            throw new \LogicException('The node has no parent.');
        }
        $nodes = XElement::prepareInsertion($parent, $content);
        if ($target->parentNode !== $parent) {
            throw new \LogicException('The node changed parents while collecting content.');
        }
        $before = $after || $replace ? $target->nextSibling : $target;
        if ($parent instanceof DOMDocument) {
            self::validateDocumentInsertion($parent, $nodes, $before, $replace ? $target : null);
        }
        if ($replace) {
            $parent->removeChild($target);
        }
        foreach ($nodes as $node) {
            $parent->insertBefore($node, $before);
        }
    }

    /** @param list<DOMNode> $nodes */
    private static function validateDocumentInsertion(
        DOMDocument $document,
        array $nodes,
        ?DOMNode $before,
        ?DOMNode $replaced,
    ): void {
        $root = $document->documentElement;
        $hasRoot = $root !== null && $root !== $replaced;
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                if ($hasRoot) {
                    throw new \InvalidArgumentException('An XML document can have only one root element.');
                }
                $hasRoot = true;
                for ($sibling = $before; $sibling !== null; $sibling = $sibling->nextSibling) {
                    if ($sibling instanceof \DOMDocumentType) {
                        throw new \InvalidArgumentException('The root element must follow the document type.');
                    }
                }
            } elseif (!$node instanceof DOMComment && !$node instanceof DOMProcessingInstruction) {
                throw new \InvalidArgumentException('Only elements, comments and processing instructions can be inserted here.');
            }
        }
    }

    public function remove(): void
    {
        if ($this->node->parentNode === null) {
            throw new \LogicException('The node has no parent.');
        }
        $this->node->parentNode->removeChild($this->node);
    }

    /** @return IEnumerable<XElement> */
    public function ancestors(XName|string|null $name = null): IEnumerable
    {
        return $this->queryAncestors($name, includeSelf: false);
    }

    /** @return IEnumerable<XElement> */
    protected function queryAncestors(XName|string|null $name, bool $includeSelf): IEnumerable
    {
        $element = $this->node;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name, $includeSelf): iterable {
            $start = $includeSelf ? $element : $element->parentNode;
            for ($node = $start; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($name === null || $name->matches($node)) {
                    yield new XElement($node);
                }
            }
        });
    }

    /** @return IEnumerable<XElement> */
    public function elementsBeforeSelf(XName|string|null $name = null): IEnumerable
    {
        return $this->querySiblings($name, before: true);
    }

    /** @return IEnumerable<XElement> */
    public function elementsAfterSelf(XName|string|null $name = null): IEnumerable
    {
        return $this->querySiblings($name, before: false);
    }

    /** @return IEnumerable<XElement> */
    private function querySiblings(XName|string|null $name, bool $before): IEnumerable
    {
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(function () use ($name, $before): iterable {
            foreach ($this->siblings($before) as $node) {
                if ($node instanceof DOMElement && ($name === null || $name->matches($node))) {
                    yield new XElement($node);
                }
            }
        });
    }

    /** @return IEnumerable<XNode> */
    public function nodesBeforeSelf(): IEnumerable
    {
        return $this->siblingNodes(before: true);
    }

    /** @return IEnumerable<XNode> */
    public function nodesAfterSelf(): IEnumerable
    {
        return $this->siblingNodes(before: false);
    }

    /** @return IEnumerable<XNode> */
    private function siblingNodes(bool $before): IEnumerable
    {
        return Enumerable::defer(function () use ($before): iterable {
            foreach ($this->siblings($before) as $node) {
                yield self::wrapNode($node);
            }
        });
    }

    /** @return \Generator<int, DOMNode> */
    private function siblings(bool $before): \Generator
    {
        $element = $this->node;
        $start = $before ? $element->parentNode?->firstChild : $element->nextSibling;
        for ($node = $start; $node !== null && $node !== $element; $node = $node->nextSibling) {
            yield $node;
        }
    }
}
