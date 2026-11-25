<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XAttribute, XElement, XNamespace};
use PHPUnit\Framework\TestCase;

final class XNamespaceTest extends TestCase
{
    public function testElementNamespaceLookupsRespectScopeAndShadowing(): void
    {
        $root = XElement::parse(
            '<root xmlns="urn:default" xmlns:p="urn:old" xmlns:alias="urn:old">'
            .'<child xmlns:p="urn:new"><leaf xmlns=""/></child></root>',
        );
        $child = $root->element('{urn:default}child');
        $leaf = $child->element('leaf');
        $default = XNamespace::get('urn:default');
        $old = XNamespace::get('urn:old');
        $new = XNamespace::get('urn:new');

        self::assertSame($default, $child->getDefaultNamespace());
        self::assertSame(XNamespace::none(), $leaf->getDefaultNamespace());
        self::assertSame($old, $root->getNamespaceOfPrefix('p'));
        self::assertSame($new, $leaf->getNamespaceOfPrefix('p'));
        self::assertSame('alias', $leaf->getPrefixOfNamespace($old));
        self::assertSame('p', $leaf->getPrefixOfNamespace($new));
        self::assertNull($root->getPrefixOfNamespace($default));
        self::assertNull($leaf->getNamespaceOfPrefix('missing'));
        self::assertNull($root->getPrefixOfNamespace($new));
    }

    public function testDefaultAndNamedBindingsCanCoexistWithReservedPrefixes(): void
    {
        $element = XElement::parse('<root xmlns="urn:both" xmlns:n="urn:both"/>');
        $both = XNamespace::get('urn:both');
        self::assertSame($both, $element->getDefaultNamespace());
        self::assertSame('n', $element->getPrefixOfNamespace($both));
        self::assertSame(XNamespace::xml(), $element->getNamespaceOfPrefix('xml'));
        self::assertSame(XNamespace::xmlns(), $element->getNamespaceOfPrefix('xmlns'));
        self::assertSame('xml', $element->getPrefixOfNamespace(XNamespace::xml()));
        self::assertSame('xmlns', $element->getPrefixOfNamespace(XNamespace::xmlns()));
        self::assertSame(XNamespace::none(), (new XElement('plain'))->getDefaultNamespace());

        $this->expectException(\InvalidArgumentException::class);
        $element->getNamespaceOfPrefix('');
    }

    public function testNamespacesAndNamesAreReused(): void
    {
        $people = XNamespace::get('urn:people');
        self::assertSame($people, XNamespace::get('urn:people'));
        self::assertSame('urn:people', $people->namespaceName);
        self::assertSame('urn:people', (string) $people);
        self::assertSame($people->getName('item'), $people->getName('item'));
        self::assertNotSame($people->getName('item'), XNamespace::get('urn:orders')->getName('item'));
        self::assertSame(XNamespace::none(), XNamespace::get(''));
        self::assertSame('item', (string) XNamespace::none()->getName('item'));
        self::assertSame(XNamespace::get('http://www.w3.org/XML/1998/namespace'), XNamespace::xml());
        self::assertSame(XNamespace::get('http://www.w3.org/2000/xmlns/'), XNamespace::xmlns());
    }

    public function testGeneratedNamesWorkForElementsAndAttributes(): void
    {
        $people = XNamespace::get('urn:people');
        $root = new XElement('root',
            new XElement($people->getName('item'), new XAttribute(XNamespace::xml()->getName('lang'), 'en'), 'Ada'),
            new XElement('item', 'Other'),
        );
        $parsed = XElement::parse($root->toXml());
        $item = $parsed->element($people->getName('item'));

        self::assertSame('Ada', $item?->value());
        self::assertSame('en', $item?->attribute(XNamespace::xml()->getName('lang'))?->value());
        self::assertSame('Other', $parsed->element(XNamespace::none()->getName('item'))?->value());
    }
}
