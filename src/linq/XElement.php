<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMAttr;
use DOMElement;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;

final class XElement extends XNode
{
    private readonly DOMElement $elementNode;

    public function __construct(XName|string|DOMElement|self $name, mixed ...$content)
    {
        if ($name instanceof DOMElement) {
            $this->elementNode = $name;
        } else {
            $document = new AnnotationDocument('1.0', 'UTF-8');
            if ($name instanceof self) {
                $this->elementNode = $document->importNode($name->elementNode, true);
            } else {
                $name = XName::from($name);
                $this->elementNode = $document->createElementNS(
                    $name->namespaceName === '' ? null : $name->namespaceName,
                    $name->localName,
                );
            }
        }
        parent::__construct($this->elementNode);
        $this->add(...$content);
    }

    /** Imported content is deep-copied; existing trees are never moved. @internal */
    public function copyTo(\DOMDocument $document): DOMElement
    {
        return $document->importNode($this->elementNode, true);
    }

    public static function parse(string $xml): self
    {
        return XDocument::parse($xml)->root();
    }

    public static function load(string $path): self
    {
        return XDocument::load($path)->root();
    }

    public function add(mixed ...$content): void
    {
        $this->addContent($content, allowAttributes: true);
    }

    private function addContent(array $content, bool $allowAttributes): void
    {
        foreach ($content as $item) {
            if ($item === null) {
                continue;
            }
            if ($item instanceof self) {
                $this->elementNode->appendChild(self::copyChild($item->elementNode, $this->elementNode));
            } elseif ($item instanceof XComment) {
                $this->elementNode->appendChild($item->copyTo($this->elementNode->ownerDocument));
            } elseif ($item instanceof XContentNode && in_array($item->nodeType(), [
                XML_TEXT_NODE, XML_CDATA_SECTION_NODE, XML_PI_NODE, XML_ENTITY_REF_NODE,
            ], true)) {
                $this->elementNode->appendChild(self::copyChild($item->node, $this->elementNode));
            } elseif ($item instanceof XAttribute) {
                if (!$allowAttributes) {
                    throw new \InvalidArgumentException('Attributes cannot be inserted as child nodes.');
                }
                $name = $item->name();
                if ($this->attribute($name) !== null) {
                    throw new \InvalidArgumentException('An attribute with this name already exists.');
                }
                $this->setAttributeValue($name, $item->value());
            } elseif (is_iterable($item)) {
                foreach ($item as $child) {
                    $this->addContent([$child], $allowAttributes);
                }
            } elseif (is_scalar($item)) {
                $this->elementNode->appendChild($this->elementNode->ownerDocument->createTextNode(
                    self::text($item),
                ));
            } else {
                throw new \InvalidArgumentException('Unsupported XML element content.');
            }
        }
    }

    public function addFirst(mixed ...$content): void
    {
        $parent = $this->elementNode;
        $nodes = self::prepareInsertion($parent, $content);
        $before = $parent->firstChild;
        foreach ($nodes as $node) {
            $parent->insertBefore($node, $before);
        }
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
        $element = $this->elementNode;
        $parent = $element->parentNode;
        if ($parent === null) {
            throw new \LogicException('The element has no parent.');
        }
        $nodes = self::prepareInsertion($parent, $content);
        if ($element->parentNode !== $parent) {
            throw new \LogicException('The element changed parents while collecting content.');
        }
        if ($parent instanceof \DOMDocument) {
            $roots = (int) (!$replace && $parent->documentElement !== null);
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    ++$roots;
                } elseif (!$node instanceof \DOMComment) {
                    throw new \InvalidArgumentException('Only elements and comments can be inserted at document level.');
                }
            }
            if ($roots > 1) {
                throw new \InvalidArgumentException('An XML document can have only one root element.');
            }
        }

        $before = $after || $replace ? $element->nextSibling : $element;
        if ($replace) {
            $parent->removeChild($element);
        }
        foreach ($nodes as $node) {
            $parent->insertBefore($node, $before);
        }
    }

    /** @return list<DOMNode> */
    private static function prepareInsertion(DOMNode $parent, array $content): array
    {
        $staging = new self('content');
        $staging->addContent($content, allowAttributes: false);

        $nodes = [];
        foreach ($staging->elementNode->childNodes as $node) {
            $nodes[] = self::copyChild($node, $parent);
        }
        return $nodes;
    }

    private static function copyChild(DOMNode $child, DOMNode $parent): DOMNode
    {
        $document = $parent instanceof \DOMDocument ? $parent : $parent->ownerDocument;
        $copy = $document->importNode($child, true);
        if ($copy instanceof DOMElement && ($copy->namespaceURI ?? '') === ''
            && $parent instanceof DOMElement && ($parent->lookupNamespaceURI(null) ?? '') !== '') {
            $copy->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', '');
        }
        return $copy;
    }

    /** Replace attributes and child nodes after materializing and validating all new content. */
    public function replaceAll(mixed ...$content): void
    {
        // Building a detached tree first snapshots lazy queries over this element,
        // copies existing nodes, and leaves the target untouched if input fails.
        $replacement = new self($this->name(), ...$content);
        $attributes = $replacement->attributes()->toArray();
        $nodes = [];
        foreach ($replacement->elementNode->childNodes as $child) {
            $nodes[] = $this->elementNode->ownerDocument->importNode($child, true);
        }

        $this->removeAll();
        $this->add($attributes);
        foreach ($nodes as $child) {
            $this->elementNode->appendChild($child);
        }
    }

    public function removeAll(): void
    {
        $this->removeAttributes();
        $this->removeNodes();
    }

    public function removeAttributes(): void
    {
        foreach ($this->attributes()->toArray() as $attribute) {
            $attribute->remove();
        }
    }

    public function replaceAttributes(mixed ...$content): void
    {
        $replacement = new self($this->name());
        $replacement->addAttributes($content);
        $attributes = $replacement->attributes()->toArray();

        $this->removeAttributes();
        $this->add($attributes);
    }

    private function addAttributes(iterable $content): void
    {
        foreach ($content as $item) {
            if ($item === null) {
                continue;
            }
            if ($item instanceof XAttribute) {
                $name = $item->name();
                if (($name->namespaceName === '' && $name->localName === 'xmlns')
                    || $name->namespaceName === 'http://www.w3.org/2000/xmlns/') {
                    throw new \InvalidArgumentException('Namespace declarations cannot be replaced as attributes.');
                }
                $this->add($item);
            } elseif (is_iterable($item)) {
                $this->addAttributes($item);
            } else {
                throw new \InvalidArgumentException('Attribute replacement requires XAttribute content.');
            }
        }
    }

    public function replaceNodes(mixed ...$content): void
    {
        $nodes = self::prepareInsertion($this->elementNode, $content);
        $this->removeNodes();
        foreach ($nodes as $node) {
            $this->elementNode->appendChild($node);
        }
    }

    public function setValue(string|int|float|bool $value): void
    {
        $this->removeNodes();
        $this->add($value);
    }

    public function setAttributeValue(XName|string $name, string|int|float|bool|null $value): void
    {
        $name = XName::from($name);
        if ($value === null) {
            $this->attribute($name)?->remove();
            return;
        }
        if ($name->namespaceName === '') {
            $this->elementNode->setAttribute($name->localName, self::text($value));
            return;
        }
        $prefix = $this->elementNode->lookupPrefix($name->namespaceName);
        if ($prefix === null || $prefix === '') {
            $index = 1;
            do {
                $prefix = 'ns'.$index++;
            } while ($this->elementNode->lookupNamespaceURI($prefix) !== null);
        }
        $this->elementNode->setAttributeNS($name->namespaceName, $prefix.':'.$name->localName, self::text($value));
    }

    public function setElementValue(XName|string $name, string|int|float|bool|null $value): void
    {
        $element = $this->element($name);
        if ($value === null) {
            $element?->remove();
        } elseif ($element === null) {
            $this->add(new self($name, $value));
        } else {
            $element->setValue($value);
        }
    }

    public function removeNodes(): void
    {
        while ($this->elementNode->firstChild !== null) {
            $this->elementNode->removeChild($this->elementNode->firstChild);
        }
    }

    public function remove(): void
    {
        if ($this->elementNode->parentNode === null) {
            throw new \LogicException('The element has no parent.');
        }
        $this->elementNode->parentNode->removeChild($this->elementNode);
    }

    public function parent(): ?self
    {
        $parent = $this->elementNode->parentNode;
        return $parent instanceof DOMElement ? new self($parent) : null;
    }

    public function toXml(bool $formatOutput = false): string
    {
        $document = $this->elementNode->ownerDocument;
        $previous = $document->formatOutput;
        $document->formatOutput = $formatOutput;
        try {
            $xml = $document->saveXML($this->elementNode);
            if ($xml === false) {
                throw new \RuntimeException('Unable to serialize XML element.');
            }
            return $xml;
        } finally {
            $document->formatOutput = $previous;
        }
    }

    public function save(string $path, bool $formatOutput = false): void
    {
        (new XDocument($this))->save($path, $formatOutput);
    }

    public function __toString(): string
    {
        return $this->toXml();
    }

    private static function text(string|int|float|bool $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    public function name(): XName
    {
        return new XName(
            $this->elementNode->localName ?? $this->elementNode->tagName,
            $this->elementNode->namespaceURI ?? '',
        );
    }

    public function getDefaultNamespace(): XNamespace
    {
        return XNamespace::get($this->elementNode->lookupNamespaceURI(null) ?? '');
    }

    public function getNamespaceOfPrefix(string $prefix): ?XNamespace
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Namespace prefix cannot be empty.');
        }
        return match ($prefix) {
            'xml' => XNamespace::xml(),
            'xmlns' => XNamespace::xmlns(),
            default => ($uri = $this->elementNode->lookupNamespaceURI($prefix)) === null
                ? null
                : XNamespace::get($uri),
        };
    }

    public function getPrefixOfNamespace(XNamespace $namespace): ?string
    {
        if ($namespace === XNamespace::xml()) {
            return 'xml';
        }
        if ($namespace === XNamespace::xmlns()) {
            return 'xmlns';
        }

        $uri = $namespace->namespaceName;
        if ($uri === '') {
            return null;
        }
        $element = $this->elementNode;
        $prefix = $element->lookupPrefix($uri);
        if ($prefix !== null && $prefix !== '' && $element->lookupNamespaceURI($prefix) === $uri) {
            return $prefix;
        }

        // A default binding can coexist with a named prefix for the same URI.
        $xpath = new \DOMXPath($element->ownerDocument);
        foreach ($xpath->query('namespace::*', $element) as $binding) {
            if ($binding->nodeName !== 'xmlns' && $binding->nodeValue === $uri) {
                return $binding->localName;
            }
        }
        return null;
    }

    public function value(): string
    {
        return $this->elementNode->textContent;
    }

    public function element(XName|string $name): ?self
    {
        $name = XName::from($name);
        foreach ($this->elementNode->childNodes as $child) {
            if ($child instanceof DOMElement && $name->matches($child)) {
                return new self($child);
            }
        }
        return null;
    }

    /** @return IEnumerable<XComment> */
    public function comments(): IEnumerable
    {
        return XComment::childrenOf($this->elementNode);
    }

    /** @return IEnumerable<XElement> */
    public function elements(XName|string|null $name = null): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && ($name === null || $name->matches($child))) {
                    yield new XElement($child);
                }
            }
        });
    }

    /** @return IEnumerable<XElement> */
    public function descendants(XName|string|null $name = null): IEnumerable
    {
        return $this->queryDescendants($name, includeSelf: false);
    }

    /** @return IEnumerable<XElement> */
    public function descendantsAndSelf(XName|string|null $name = null): IEnumerable
    {
        return $this->queryDescendants($name, includeSelf: true);
    }

    /** @return IEnumerable<XElement> */
    private function queryDescendants(XName|string|null $name, bool $includeSelf): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name, $includeSelf): iterable {
            if ($includeSelf && ($name === null || $name->matches($element))) {
                yield new self($element);
            }
            yield from self::descendantElements($element, $name);
        });
    }

    /** @return IEnumerable<XElement> */
    public function ancestors(XName|string|null $name = null): IEnumerable
    {
        return $this->queryAncestors($name, includeSelf: false);
    }

    /** @return IEnumerable<XElement> */
    public function ancestorsAndSelf(XName|string|null $name = null): IEnumerable
    {
        return $this->queryAncestors($name, includeSelf: true);
    }

    /** @return IEnumerable<XElement> */
    private function queryAncestors(XName|string|null $name, bool $includeSelf): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name, $includeSelf): iterable {
            $start = $includeSelf ? $element : $element->parentNode;
            for ($node = $start; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($name === null || $name->matches($node)) {
                    yield new self($node);
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
                    yield new self($node);
                }
            }
        });
    }

    /** @return IEnumerable<XNode> */
    public function nodes(): IEnumerable
    {
        return $this->queryNodes(descend: false, includeSelf: false);
    }

    /** @return IEnumerable<XNode> */
    public function descendantNodes(): IEnumerable
    {
        return $this->queryNodes(descend: true, includeSelf: false);
    }

    /** @return IEnumerable<XNode> */
    public function descendantNodesAndSelf(): IEnumerable
    {
        return $this->queryNodes(descend: true, includeSelf: true);
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
    private function queryNodes(bool $descend, bool $includeSelf): IEnumerable
    {
        $element = $this->elementNode;
        return Enumerable::defer(static function () use ($element, $descend, $includeSelf): iterable {
            if ($includeSelf) {
                yield new self($element);
            }
            yield from self::childNodes($element, $descend);
        });
    }

    /** @return \Generator<int, XNode> */
    private static function childNodes(DOMElement $element, bool $descend): \Generator
    {
        foreach ($element->childNodes as $child) {
            yield self::wrapNode($child);
            if ($descend && $child instanceof DOMElement) {
                yield from self::childNodes($child, true);
            }
        }
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
        $element = $this->elementNode;
        $start = $before ? $element->parentNode?->firstChild : $element->nextSibling;
        for ($node = $start; $node !== null && $node !== $element; $node = $node->nextSibling) {
            yield $node;
        }
    }

    private static function wrapNode(DOMNode $node): XNode
    {
        return match (true) {
            $node instanceof DOMElement => new self($node),
            $node instanceof \DOMComment => new XComment($node),
            $node instanceof \DOMCdataSection => new XCData($node),
            $node instanceof \DOMText => new XText($node),
            default => new XContentNode($node),
        };
    }

    public function isBefore(self|XComment|XContentNode|null $other): bool
    {
        return $this->compareDocumentOrder($other) < 0;
    }

    public function isAfter(self|XComment|XContentNode|null $other): bool
    {
        return $this->compareDocumentOrder($other) > 0;
    }

    private function compareDocumentOrder(self|XComment|XContentNode|null $other): int
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

    public function attribute(XName|string $name): ?XAttribute
    {
        $name = XName::from($name);
        foreach ($this->elementNode->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && $name->matches($attribute)) {
                return new XAttribute($attribute);
            }
        }
        return null;
    }

    /** @return IEnumerable<XAttribute> */
    public function attributes(XName|string|null $name = null): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            foreach ($element->attributes as $attribute) {
                if ($attribute instanceof DOMAttr && ($name === null || $name->matches($attribute))) {
                    yield new XAttribute($attribute);
                }
            }
        });
    }

    /** @return \Generator<int, XElement> */
    private static function descendantElements(DOMNode $parent, ?XName $name): \Generator
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($name === null || $name->matches($child)) {
                yield new self($child);
            }
            yield from self::descendantElements($child, $name);
        }
    }
}
