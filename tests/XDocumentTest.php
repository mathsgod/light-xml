<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XDocument, XElement, XAttribute, XName};
use PHPUnit\Framework\TestCase;

final class XDocumentTest extends TestCase
{
    public function testFunctionalConstructionEscapesTextAndAttributes(): void
    {
        $doc = new XDocument(new XElement('users', [
            new XElement('user', new XAttribute('id', 1), new XElement('name', 'Ada & <Bob>')),
            new XElement('user', new XAttribute('id', 2), new XElement('active', true)),
        ]));
        self::assertSame('users', (string) $doc->root()->name());
        self::assertSame(2, $doc->descendants('user')->count());
        self::assertSame('1', $doc->descendants('user')->first()->attribute('id')->value());
        self::assertStringContainsString('Ada &amp; &lt;Bob&gt;', $doc->toXml());
        self::assertSame('true', $doc->descendants('active')->single()->value());
        self::assertSame('Ada & <Bob>', XDocument::parse($doc->toXml())->descendants('name')->single()->value());
    }

    public function testEmptyDocumentCanReceiveOneRootAndBeCleared(): void
    {
        $doc = new XDocument();
        self::assertNull($doc->root());
        self::assertSame(0, $doc->elements()->count());
        $doc->add(null, [new XElement('root')]);
        self::assertSame('root', (string) $doc->root()->name());
        $doc->removeNodes();
        self::assertNull($doc->root());
        $doc->add(new XElement('replacement'));
        self::assertSame('replacement', (string) $doc->root()->name());
    }

    public function testSecondRootRejectedWithoutChangingDocument(): void
    {
        $doc = new XDocument(new XElement('root'));
        try {
            $doc->add(new XElement('other'));
            self::fail('Expected an exception');
        } catch (\InvalidArgumentException) {
            self::assertSame('root', (string) $doc->root()->name());
        }
    }

    public function testMultipleRootsInConstructorAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new XDocument([new XElement('a'), new XElement('b')]);
    }

    public function testDocumentTextIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new XDocument('text outside root');
    }

    public function testCopiesAndInsertedElementsAreIndependent(): void
    {
        $element = new XElement('root', new XElement('name', 'Ada'));
        $doc = new XDocument($element);
        $copy = new XDocument($doc);
        $copy->root()->setElementValue('name', 'Bob');
        self::assertSame('Ada', $doc->root()->element('name')->value());
        $element->setElementValue('name', 'Cat');
        self::assertSame('Ada', $doc->root()->element('name')->value());
        $elementCopy = new XElement($element);
        $elementCopy->setElementValue('name', 'Dan');
        self::assertSame('Cat', $element->element('name')->value());
    }

    public function testQueriesAndTransformationFromExistingTree(): void
    {
        $doc = XDocument::parse('<Root><Child>data1</Child><Child>other</Child><Child>data2</Child></Root>');
        $result = new XDocument(new XElement('Result', $doc->descendants('Child')
            ->where(fn ($node) => str_starts_with($node->value(), 'data'))));
        self::assertSame(['data1', 'data2'], $result->descendants('Child')->select(fn ($e) => $e->value())->toArray());
        self::assertSame(3, $doc->descendants('Child')->count());
        self::assertSame(1, $doc->elements('Root')->count());
        self::assertSame(0, $doc->elements('Child')->count());
        self::assertNull($doc->element('missing'));
        self::assertSame(4, $doc->descendants()->count());
    }

    public function testEditingUsesLiveWrappers(): void
    {
        $doc = XDocument::parse('<root><user id="1"><name>Ada</name></user></root>');
        $user = $doc->descendants('user')->single();
        $user->setElementValue('name', 'Bob');
        $user->setElementValue('active', true);
        $user->setAttributeValue('id', 2);
        self::assertSame('Bob', $doc->descendants('name')->single()->value());
        self::assertSame('2', $user->attribute('id')->value());
        $user->attribute('id')->setValue('3 & 4');
        self::assertSame('3 & 4', XDocument::parse($doc->toXml())->descendants('user')->single()->attribute('id')->value());
        $user->setAttributeValue('id', null);
        $user->setElementValue('active', null);
        self::assertNull($user->attribute('id'));
        self::assertNull($user->element('active'));
        self::assertSame('root', (string) $user->parent()->name());
        $user->setValue('plain & text');
        self::assertSame(0, $user->elements()->count());
        self::assertSame('plain & text', $user->value());
        $user->remove();
        self::assertSame(0, $doc->descendants('user')->count());
    }

    public function testNamespacesRoundTrip(): void
    {
        $doc = new XDocument(new XElement(new XName('root', 'urn:root'),
            new XElement('{urn:people}user', new XAttribute('{urn:ids}id', '7')),
            new XElement('plain', 'no namespace'),
        ));
        $parsed = XDocument::parse($doc->toXml());
        self::assertSame('7', $parsed->descendants('{urn:people}user')->single()->attribute('{urn:ids}id')->value());
        self::assertSame(1, $parsed->descendants('plain')->count());
        self::assertSame(0, $parsed->descendants('user')->count());
    }

    public function testDuplicateAttributeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new XElement('root', new XAttribute('id', 1), new XAttribute('id', 2));
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'light-xml-');
        try {
            $doc = new XDocument(new XElement('root', new XElement('child', 'value')));
            $doc->save($path, true);
            self::assertSame('value', XDocument::load($path)->descendants('child')->single()->value());
            (new XElement('other', 'text'))->save($path);
            self::assertSame('text', XElement::load($path)->value());
        } finally {
            unlink($path);
        }
    }

    public function testMalformedXmlDoesNotLeakLibxmlErrorMode(): void
    {
        $previous = libxml_use_internal_errors();
        try {
            XDocument::parse('<root>');
            self::fail('Expected invalid XML error');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Unable to parse XML.', $error->getMessage());
        }
        self::assertSame($previous, libxml_use_internal_errors());
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        XDocument::load(__DIR__.'/missing.xml');
    }

    public function testEmptyDocumentCannotBeSaved(): void
    {
        $this->expectException(\LogicException::class);
        (new XDocument())->save('/unused.xml');
    }

    public function testParsePreservesDocumentCommentsAndProcessingInstructions(): void
    {
        $doc = XDocument::parse('<?xml version="1.0"?><!--note--><?test value?><root/>');
        self::assertStringContainsString('<!--note-->', $doc->toXml());
        self::assertStringContainsString('<?test value?>', (string) new XDocument($doc));
        self::assertSame('child', (string) XElement::parse('<child/>')->name());
    }
}
