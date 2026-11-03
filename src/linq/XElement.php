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
            $document = new \DOMDocument('1.0', 'UTF-8');
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
        foreach ($content as $item) {
            if ($item === null) {
                continue;
            }
            if ($item instanceof self) {
                $copy = $item->copyTo($this->elementNode->ownerDocument);
                if (($copy->namespaceURI ?? '') === ''
                    && ($this->elementNode->lookupNamespaceURI(null) ?? '') !== '') {
                    $copy->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', '');
                }
                $this->elementNode->appendChild($copy);
            } elseif ($item instanceof XAttribute) {
                $name = $item->name();
                if ($this->attribute($name) !== null) {
                    throw new \InvalidArgumentException('An attribute with this name already exists.');
                }
                $this->setAttributeValue($name, $item->value());
            } elseif (is_iterable($item)) {
                foreach ($item as $child) {
                    $this->add($child);
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
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            yield from self::descendantElements($element, $name);
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
