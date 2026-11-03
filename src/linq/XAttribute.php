<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMAttr;

final class XAttribute extends XNode
{
    private readonly DOMAttr $attribute;

    public function __construct(XName|string|DOMAttr $name, string|int|float|bool $value = '')
    {
        if ($name instanceof DOMAttr) {
            $this->attribute = $name;
        } else {
            $name = XName::from($name);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $holder = $document->createElement('holder');
            $document->appendChild($holder);
            if ($name->namespaceName === '') {
                $this->attribute = $document->createAttribute($name->localName);
            } else {
                $holder->setAttributeNS($name->namespaceName, 'ns1:'.$name->localName, '');
                $this->attribute = $holder->getAttributeNodeNS($name->namespaceName, $name->localName);
                $holder->removeAttributeNode($this->attribute);
            }
            $this->setValue($value);
        }
        parent::__construct($this->attribute);
    }

    public function setValue(string|int|float|bool $value): void
    {
        while ($this->attribute->firstChild !== null) {
            $this->attribute->removeChild($this->attribute->firstChild);
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $this->attribute->appendChild($this->attribute->ownerDocument->createTextNode($text));
    }

    public function remove(): void
    {
        $owner = $this->attribute->ownerElement;
        if ($owner === null) {
            throw new \LogicException('The attribute has no parent.');
        }
        $owner->removeAttributeNode($this->attribute);
    }

    public function name(): XName
    {
        return new XName(
            $this->attribute->localName ?? $this->attribute->name,
            $this->attribute->namespaceURI ?? '',
        );
    }

    public function value(): string
    {
        return $this->attribute->value;
    }
}
