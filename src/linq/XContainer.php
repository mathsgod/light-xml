<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMElement;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use Light\Xml\XmlReader;
use Light\Xml\XmlWriter;

abstract class XContainer extends XNode
{
    abstract public function toXml(bool $formatOutput = false, SaveOptions $options = SaveOptions::None): string;

    public function createReader(): XmlReader
    {
        return XmlReader::fromString($this->toXml());
    }

    public function createWriter(): XmlWriter
    {
        $writer = XmlWriter::forContainer(function (string $xml): void {
            $this->add(XElement::parse($xml)->nodes());
        });
        // Keep the native writer inside an element so it escapes fragment text.
        $writer->writeStartElement('fragment');
        return $writer;
    }

    abstract public function writeTo(XmlWriter $writer): void;

    abstract public function add(mixed ...$content): void;

    abstract public function addFirst(mixed ...$content): void;

    abstract public function replaceNodes(mixed ...$content): void;

    public function removeNodes(): void
    {
        while ($this->node->firstChild !== null) {
            self::removeChild($this->node, $this->node->firstChild);
        }
    }

    public function firstNode(): ?XNode
    {
        $node = $this->node->firstChild;
        return $node === null ? null : self::wrapNode($node);
    }

    public function lastNode(): ?XNode
    {
        $node = $this->node->lastChild;
        return $node === null ? null : self::wrapNode($node);
    }

    /** @return IEnumerable<XNode> */
    public function nodes(): IEnumerable
    {
        return Enumerable::defer(fn (): iterable => self::childNodes($this->node, false));
    }

    /** @return IEnumerable<XNode> */
    public function descendantNodes(): IEnumerable
    {
        return Enumerable::defer(fn (): iterable => self::childNodes($this->node, true));
    }

    /** @return IEnumerable<XComment> */
    public function comments(): IEnumerable
    {
        return Enumerable::defer(fn (): iterable => XComment::childrenOf($this->node));
    }

    public function element(XName|string $name): ?XElement
    {
        foreach ($this->elements($name) as $element) {
            return $element;
        }
        return null;
    }

    /** @return IEnumerable<XElement> */
    public function elements(XName|string|null $name = null): IEnumerable
    {
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(function () use ($name): iterable {
            foreach ($this->node->childNodes as $child) {
                if ($child instanceof DOMElement && ($name === null || $name->matches($child))) {
                    yield new XElement($child);
                }
            }
        });
    }

    /** @return IEnumerable<XElement> */
    public function descendants(XName|string|null $name = null): IEnumerable
    {
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(fn (): iterable => self::descendantElements($this->node, $name));
    }

    /** @return \Generator<int, XNode> */
    protected static function childNodes(DOMNode $parent, bool $descend): \Generator
    {
        foreach ($parent->childNodes as $child) {
            yield self::wrapNode($child);
            if ($descend && $child instanceof \DOMElement) {
                yield from self::childNodes($child, true);
            }
        }
    }

    /** @return \Generator<int, XElement> */
    protected static function descendantElements(DOMNode $parent, ?XName $name): \Generator
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($name === null || $name->matches($child)) {
                yield new XElement($child);
            }
            yield from self::descendantElements($child, $name);
        }
    }
}
