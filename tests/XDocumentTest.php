<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XDeclaration, XDocument, XElement, XAttribute, XName};
use PHPUnit\Framework\TestCase;
use Light\Xml\Linq\{LoadOptions, SaveOptions};

final class XDocumentTest extends TestCase
{
    public function testLoadOptionsPreserveWhitespaceByDefaultAndCanDiscardIndentation(): void
    {
        $xml = "<root>\n  <child>text</child>\n</root>";
        self::assertSame(3, XDocument::parse($xml)->root()->nodes()->count());
        self::assertSame(3, XElement::parse($xml, LoadOptions::PreserveWhitespace)->nodes()->count());
        self::assertSame(1, XElement::parse($xml, LoadOptions::None)->nodes()->count());

        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $xml);
            rewind($stream);
            self::assertSame(1, XDocument::load($stream, options: LoadOptions::None)->root()->nodes()->count());
        } finally {
            fclose($stream);
        }
    }

    public function testDisableFormattingOverridesRequestedIndentation(): void
    {
        $root = XElement::parse('<root><child/></root>');
        $doc = new XDocument($root);
        self::assertStringContainsString("\n  <child/>", $doc->toXml(formatOutput: true));
        self::assertSame($doc->toXml(), $doc->toXml(formatOutput: true, options: SaveOptions::DisableFormatting));
        self::assertSame($root->toXml(), $root->toXml(formatOutput: true, options: SaveOptions::DisableFormatting));

        $stream = fopen('php://temp', 'w+b');
        try {
            $root->save($stream, formatOutput: true, options: SaveOptions::DisableFormatting);
            rewind($stream);
            self::assertSame($doc->toXml(), stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testSaveWritesAtTheStreamPositionAndLeavesTheStreamOpen(): void
    {
        $doc = new XDocument(new XDeclaration('1.0', 'UTF-16'), new XElement('root', str_repeat('é', 9000)));
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, 'prefix');
            $doc->save(path: $stream, formatOutput: true);
            rewind($stream);
            self::assertSame('prefix'.$doc->toXml(true), stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testLoadReadsStreamFromItsCurrentPositionAndLeavesItOpen(): void
    {
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, 'skip<?xml version="1.0"?><root>é</root>');
            fseek($stream, 4);
            $document = XDocument::load(path: $stream);
            self::assertSame('é', $document->root()->value());
            self::assertSame('1.0', $document->declaration()?->version());
            self::assertTrue(feof($stream));
            fseek($stream, 4);
            self::assertSame('é', XElement::load($stream)->value());
        } finally {
            fclose($stream);
        }
    }

    public function testLoadRejectsWriteOnlyStream(): void
    {
        $stream = fopen('php://temp', 'wb');
        try {
            $this->expectException(\InvalidArgumentException::class);
            XDocument::load($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testDeclarationCanBeReplacedAndRemovedIndependentlyOfDocumentCopy(): void
    {
        $doc = XDocument::parse("<?xml version='1.0' encoding='UTF-8' standalone='no'?><!--note--><root/>");
        self::assertSame('1.0', $doc->declaration()?->version());
        self::assertSame('UTF-8', $doc->declaration()?->encoding());
        self::assertSame('no', $doc->declaration()?->standalone());

        $copy = new XDocument($doc);
        $copy->setDeclaration(new XDeclaration());
        self::assertSame('<?xml version="1.0"?>', (string) $copy->declaration());
        self::assertStringStartsWith("<?xml version=\"1.0\"?>\n", $copy->toXml());

        $copy->setDeclaration(null);
        self::assertNull($copy->declaration());
        self::assertStringStartsWith("<!--note-->\n<root/>", $copy->toXml());
        self::assertSame('no', $doc->declaration()?->standalone());
        self::assertNull(XDocument::parse('<?xml-stylesheet href="style.xsl"?><root/>')->declaration());
    }

    public function testDeclarationControlsEncodingWhenSavingAndLoading(): void
    {
        $declaration = new XDeclaration('1.0', 'UTF-16', 'yes');
        $doc = new XDocument($declaration, new XElement('root', 'é'));
        $path = tempnam(sys_get_temp_dir(), 'light-xml-');
        try {
            $doc->save($path, true);
            foreach ([XDocument::parse($doc->toXml()), XDocument::load($path)] as $reloaded) {
                self::assertEquals($declaration, $reloaded->declaration());
                self::assertSame('é', $reloaded->root()?->value());
            }
        } finally {
            unlink($path);
        }
    }

    public function testReplacingDeclarationPreservesDocumentTypeAndNodeOrder(): void
    {
        $doc = XDocument::parse('<!--before--><!DOCTYPE root [<!ENTITY text "value">]><root>&text;</root>');
        $doc->setDeclaration(new XDeclaration());
        $xml = $doc->toXml();

        self::assertStringContainsString("<!--before-->\n<!DOCTYPE root", $xml);
        self::assertStringContainsString('<root>&text;</root>', $xml);
        self::assertSame('value', XDocument::parse($xml)->root()?->value());
    }

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
