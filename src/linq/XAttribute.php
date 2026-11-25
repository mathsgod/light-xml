<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMAttr;

final class XAttribute extends XObject
{
    private readonly DOMAttr $attribute;

    public function __construct(XName|string|DOMAttr $name, string|int|float|bool $value = '')
    {
        if ($name instanceof DOMAttr) {
            $this->attribute = $name;
        } else {
            $name = XName::from($name);
            $document = new AnnotationDocument('1.0', 'UTF-8');
            $holder = $document->createElement('holder');
            $document->appendChild($holder);
            if ($name->namespaceName === '') {
                $this->attribute = $document->createAttribute($name->localName);
            } else {
                $holder->setAttributeNS($name->namespaceName, 'ns1:'.$name->localName, '');
                $this->attribute = $holder->getAttributeNodeNS($name->namespaceName, $name->localName);
                $holder->removeAttributeNode($this->attribute);
            }
        }
        parent::__construct($this->attribute);
        if (!$name instanceof DOMAttr) {
            $this->setValue($value);
        }
    }

    public function setValue(string|int|float|bool $value): void
    {
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $this->change(XObjectChange::Value, function () use ($text): void {
            while ($this->attribute->firstChild !== null) {
                $this->attribute->removeChild($this->attribute->firstChild);
            }
            $this->attribute->appendChild($this->attribute->ownerDocument->createTextNode($text));
        });
    }

    public function remove(): void
    {
        $owner = $this->attribute->ownerElement;
        if ($owner === null) {
            throw new \LogicException('The attribute has no parent.');
        }
        $this->change(XObjectChange::Remove, fn () => $owner->removeAttributeNode($this->attribute));
    }

    /** @internal */
    public static function attach(\DOMElement $owner, DOMAttr $attribute): void
    {
        $sender = new self($attribute);
        $sender->change(XObjectChange::Add, static function () use ($owner, $attribute): void {
            $prefix = $attribute->prefix;
            $namespace = $attribute->namespaceURI;
            // PHP 8.3 may attach an imported attribute without declaring its prefix.
            if ($prefix !== '' && $prefix !== null && $prefix !== 'xml' && $namespace !== null
                && $owner->lookupNamespaceURI($prefix) !== $namespace) {
                $owner->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $namespace);
            }
            $owner->setAttributeNodeNS($attribute);
        }, $owner);
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
