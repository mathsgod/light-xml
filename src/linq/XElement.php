<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMAttr;
use DOMElement;
use DOMNode;
use Light\IO\TextReader;
use Light\IO\TextWriter;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use Light\Xml\XmlReader;
use Light\Xml\XmlWriter;

final class XElement extends XContainer
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

    public static function parse(string $xml, LoadOptions $options = LoadOptions::PreserveWhitespace): self
    {
        return XDocument::parse($xml, $options)->root();
    }

    /** @param string|resource|TextReader|XmlReader $path */
    public static function load(mixed $path, LoadOptions $options = LoadOptions::PreserveWhitespace): self
    {
        return XDocument::load($path, $options)->root();
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
                self::insertChild($this->elementNode, self::copyChild($item->elementNode, $this->elementNode));
            } elseif ($item instanceof XComment) {
                self::insertChild($this->elementNode, $item->copyTo($this->elementNode->ownerDocument));
            } elseif ($item instanceof XContentNode && in_array($item->nodeType(), [
                XML_TEXT_NODE, XML_CDATA_SECTION_NODE, XML_PI_NODE, XML_ENTITY_REF_NODE,
            ], true)) {
                self::insertChild($this->elementNode, self::copyChild($item->node, $this->elementNode));
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
                self::insertChild($this->elementNode, $this->elementNode->ownerDocument->createTextNode(
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
            self::insertChild($parent, $node, $before);
        }
    }

    /** @internal @return list<DOMNode> */
    public static function prepareInsertion(DOMNode $parent, array $content): array
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
            self::insertChild($this->elementNode, $child);
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
            self::insertChild($this->elementNode, $node);
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
        $existing = $this->attribute($name);
        if ($existing !== null) {
            $existing->setValue($value);
            return;
        }
        if (($name->namespaceName === '' && $name->localName === 'xmlns')
            || $name->namespaceName === 'http://www.w3.org/2000/xmlns/') {
            $this->change(XObjectChange::Value, function () use ($name, $value): void {
                if ($name->namespaceName === '') {
                    $this->elementNode->setAttribute('xmlns', self::text($value));
                } else {
                    $this->elementNode->setAttributeNS($name->namespaceName, 'xmlns:'.$name->localName, self::text($value));
                }
            });
            return;
        }
        $staging = new \DOMDocument();
        if ($name->namespaceName === '') {
            $attribute = $staging->createAttribute($name->localName);
        } else {
            $prefix = $this->elementNode->lookupPrefix($name->namespaceName);
            if ($prefix === null || $prefix === '' || $this->elementNode->lookupNamespaceURI($prefix) !== $name->namespaceName) {
                $index = 1;
                do {
                    $prefix = 'ns'.$index++;
                } while ($this->elementNode->lookupNamespaceURI($prefix) !== null);
            }
            $holder = $staging->appendChild($staging->createElement('holder'));
            if ($name->namespaceName === 'http://www.w3.org/XML/1998/namespace') {
                $owner = $this->elementNode->ownerDocument;
                if ($owner->documentElement !== null) {
                    $attribute = $owner->createAttributeNS($name->namespaceName, 'xml:'.$name->localName);
                    $attribute->appendChild($owner->createTextNode(self::text($value)));
                    XAttribute::attach($this->elementNode, $attribute);
                    return;
                }
                $this->change(XObjectChange::Add, fn () => $this->elementNode->setAttributeNS(
                    $name->namespaceName, 'xml:'.$name->localName, self::text($value),
                ));
                return;
            }
            $holder->setAttributeNS($name->namespaceName, $prefix.':'.$name->localName, '');
            $attribute = $holder->getAttributeNodeNS($name->namespaceName, $name->localName);
        }

        $attribute->appendChild($staging->createTextNode(self::text($value)));
        XAttribute::attach($this->elementNode, $this->elementNode->ownerDocument->importNode($attribute, true));
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

    public function writeTo(XmlWriter $writer): void
    {
        // Copying into a detached tree includes inherited namespace bindings.
        $writer->writeSerializedNode((new self($this))->toXml());
    }

    public function createReader(): XmlReader
    {
        // Copying into a detached tree includes namespace bindings inherited by this element.
        return XmlReader::fromString((new self($this))->toXml());
    }

    public function toXml(bool $formatOutput = false, SaveOptions $options = SaveOptions::None): string
    {
        $document = $this->elementNode->ownerDocument;
        $previous = $document->formatOutput;
        $document->formatOutput = $formatOutput && $options !== SaveOptions::DisableFormatting;
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

    /** @param string|resource|TextWriter|XmlWriter $path */
    public function save(mixed $path, bool $formatOutput = false, SaveOptions $options = SaveOptions::None): void
    {
        if ($path instanceof XmlWriter) {
            $this->writeTo($path);
            return;
        }
        (new XDocument($this))->save($path, $formatOutput, $options);
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

    /** @return IEnumerable<XElement> */
    public function descendantsAndSelf(XName|string|null $name = null): IEnumerable
    {

        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            if ($name === null || $name->matches($element)) {
                yield new self($element);
            }
            yield from self::descendantElements($element, $name);
        });
    }

    /** @return IEnumerable<XElement> */
    public function ancestorsAndSelf(XName|string|null $name = null): IEnumerable
    {
        return $this->queryAncestors($name, includeSelf: true);
    }

    /** @return IEnumerable<XNode> */
    public function descendantNodesAndSelf(): IEnumerable
    {
        return Enumerable::defer(function (): iterable {
            yield new self($this->elementNode);
            yield from self::childNodes($this->elementNode, true);
        });
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
}
