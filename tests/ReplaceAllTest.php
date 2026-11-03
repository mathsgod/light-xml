<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\XAttribute;
use Light\Xml\Linq\XDocument;
use Light\Xml\Linq\XElement;
use PHPUnit\Framework\TestCase;

final class ReplaceAllTest extends TestCase
{
    public function testReplacesAttributesAndAllChildNodeKindsWithoutReplacingElement(): void
    {
        $document = XDocument::parse('<Parent><Root old="yes">old<!--comment--><![CDATA[data]]><?old test?><Old/></Root><Sibling/></Parent>');
        $root = $document->root()->element('Root');
        $otherWrapper = $document->root()->element('Root');
        $root->replaceAll(new XAttribute('version', 2), new XElement('New', 'hello'));
        self::assertSame('<Root version="2"><New>hello</New></Root>', $root->toXml());
        self::assertSame($root->toXml(), $otherWrapper->toXml());
        self::assertSame('Parent', (string) $root->parent()->name());
        self::assertNotNull($document->root()->element('Sibling'));
        self::assertNull($root->attribute('old'));
    }

    public function testOwnLazyQueryIsReadBeforeRemovingOldChildren(): void
    {
        $root = XElement::parse('<Root old="yes"><Data>1</Data><Data>2</Data><Data>3</Data><Data>4</Data></Root>');
        $root->replaceAll($root->elements('Data')->where(fn ($element) => (int) $element->value() >= 3)
            ->select(fn ($element) => new XElement('NewData', $element->value())));
        self::assertSame('<Root><NewData>3</NewData><NewData>4</NewData></Root>', $root->toXml());
    }

    public function testOwnExistingElementsAndAttributesAreCopiedBeforeClearing(): void
    {
        $root = XElement::parse('<Root a="1" b="2"><Data>1</Data><Data>2</Data></Root>');
        $oldChild = $root->elements()->first();
        $oldAttribute = $root->attribute('a');
        $root->replaceAll($root->attributes(), $root->elements());
        self::assertSame('<Root a="1" b="2"><Data>1</Data><Data>2</Data></Root>', $root->toXml());
        self::assertNull($oldChild->parent());
        $oldChild->setValue('changed');
        $oldAttribute->setValue('changed');
        self::assertSame('1', $root->element('Data')->value());
        self::assertSame('1', $root->attribute('a')->value());
    }

    public function testReplacingWithSelfUsesFiniteSnapshot(): void
    {
        $root = XElement::parse('<Root id="1"><Data>old</Data></Root>');
        $root->replaceAll($root);
        self::assertSame('<Root><Root id="1"><Data>old</Data></Root></Root>', $root->toXml());
    }

    public function testNestedSingleUseIterablesScalarsNullAndMixedContent(): void
    {
        $reads = 0;
        $items = (static function () use (&$reads): iterable {
            ++$reads;
            yield [new XAttribute('flag', true), null, ['A & ', false]];
            yield new XElement('Child', 'x < y');
            yield [12, 1.5];
        })();
        $root = new XElement('Root', 'old');
        $root->replaceAll($items);
        self::assertSame(1, $reads);
        self::assertSame('<Root flag="true">A &amp; false<Child>x &lt; y</Child>121.5</Root>', $root->toXml());
        self::assertSame('A & falsex < y121.5', XElement::parse($root->toXml())->value());
    }

    public function testEmptyNullAndNoArgumentsClearElement(): void
    {
        foreach ([[], [null], [[]], [(static function (): iterable { yield from []; })()]] as $content) {
            $root = XElement::parse('<Root old="yes"><Old/></Root>');
            $root->replaceAll(...$content);
            self::assertSame('<Root/>', $root->toXml());
        }
    }

    public function testOriginalSourceTreeRemainsIndependentAndParsedNodeKindsSurviveCopies(): void
    {
        $source = XElement::parse('<Source><Child><!--note--><![CDATA[a<b]]><?pi data?></Child></Source>');
        $before = $source->toXml();
        $target = new XElement('Target');
        $target->replaceAll($source->elements());
        self::assertSame('<Target><Child><!--note--><![CDATA[a<b]]><?pi data?></Child></Target>', $target->toXml());
        self::assertSame($before, $source->toXml());
        $target->element('Child')->setValue('changed');
        self::assertSame('a<b', $source->element('Child')->value());
    }

    public function testNamespaceNamesAndUnqualifiedChildrenSurviveRoundTrip(): void
    {
        foreach (['<Root xmlns="urn:root" xmlns:o="urn:old" o:id="old"><Old/></Root>',
            '<p:Root xmlns:p="urn:root" xmlns:o="urn:old" o:id="old"><Old/></p:Root>'] as $xml) {
            $root = XElement::parse($xml);
            $root->replaceAll(new XAttribute('{urn:new}id', 'new'),
                new XElement('Plain', 'plain'), new XElement('{urn:child}Child', 'namespaced'));
            $parsed = XElement::parse($root->toXml());
            self::assertSame('{urn:root}Root', (string) $parsed->name());
            self::assertNull($parsed->attribute('{urn:old}id'));
            self::assertSame('new', $parsed->attribute('{urn:new}id')->value());
            self::assertSame('plain', $parsed->element('Plain')->value());
            self::assertSame('namespaced', $parsed->element('{urn:child}Child')->value());
        }
    }

    public function testInvalidOrDuplicateContentLeavesOriginalTreeUnchanged(): void
    {
        foreach ([[new XElement('New'), new \stdClass()],
            [new XAttribute('id', 1), new XAttribute('id', 2)],
            [new XAttribute('{urn:id}id', 1), new XAttribute('{urn:id}id', 2)]] as $content) {
            $root = XElement::parse('<Root old="yes"><Old/></Root>');
            $before = $root->toXml();
            try {
                $root->replaceAll(...$content);
                self::fail('Expected invalid content');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $root->toXml());
            }
        }
    }

    public function testThrowingGeneratorLeavesOriginalTreeUnchanged(): void
    {
        $root = XElement::parse('<Root old="yes"><Old/></Root>');
        $before = $root->toXml();
        $failure = new \RuntimeException('Content failed');
        $content = (static function () use ($failure): iterable {
            yield new XAttribute('new', 'yes');
            yield new XElement('New');
            throw $failure;
        })();
        try {
            $root->replaceAll($content);
            self::fail('Expected content failure');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
            self::assertSame($before, $root->toXml());
        }
    }
}
