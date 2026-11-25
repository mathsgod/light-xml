<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XDocument, XElement};
use Light\Xml\XmlReader;
use PHPUnit\Framework\TestCase;

final class XmlReaderTest extends TestCase
{
    public function testLoadPreservesTheDoctypeFromASeekableStream(): void
    {
        if (!method_exists(\XMLReader::class, 'fromStream')) {
            self::markTestSkipped('Native XMLReader stream support requires PHP 8.4.');
        }

        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, 'skip<!DOCTYPE r [<!ENTITY e "value">]><r>&e;</r><!--tail-->');
            fseek($stream, 4);
            $reader = XmlReader::fromStream($stream);
            $doc = XDocument::load($reader);
            self::assertSame('r', $doc->documentType()?->name());
            self::assertSame('value', $doc->root()->value());
            self::assertSame('tail', $doc->comments()->single()->value());
            $reader->close();
        } finally {
            fclose($stream);
        }
    }

    public function testLoadConsumesRemainingTopLevelNodes(): void
    {
        $reader = XmlReader::fromString('<!--skip--><?p data?><!DOCTYPE r [<!ENTITY e "value">]><r>&e;</r><!--tail-->');
        $reader->read();
        $reader->read();
        $doc = XDocument::load(path: $reader);
        self::assertSame('p', $doc->firstNode()->nodeName());
        self::assertSame('r', $doc->documentType()->name());
        self::assertSame('value', $doc->root()->value());
        self::assertSame('tail', $doc->comments()->single()->value());
        self::assertFalse($reader->read());
    }

    public function testDocumentReaderUsesSnapshotAndReportsNodes(): void
    {
        $doc = XDocument::parse('<r xmlns:p="urn:p"><p:item id="7">text</p:item><!--note--></r>');
        $reader = $doc->createReader();
        $doc->root()->replaceNodes(new XElement('changed'));

        $nodes = [];
        while ($reader->read()) {
            $nodes[] = [$reader->nodeType(), $reader->localName(), $reader->depth()];
            if ($reader->nodeType() === \XMLReader::ELEMENT && $reader->localName() === 'item') {
                self::assertSame('7', $reader->getAttribute('id'));
                self::assertSame('urn:p', $reader->namespaceUri());
            }
        }
        self::assertSame([
            [\XMLReader::ELEMENT, 'r', 0],
            [\XMLReader::ELEMENT, 'item', 1],
            [\XMLReader::TEXT, '#text', 2],
            [\XMLReader::END_ELEMENT, 'item', 1],
            [\XMLReader::COMMENT, '#comment', 1],
            [\XMLReader::END_ELEMENT, 'r', 0],
        ], $nodes);
        $reader->close();
    }

    public function testElementReaderPreservesInheritedNamespace(): void
    {
        $element = XDocument::parse('<r xmlns:p="urn:p"><p:item/></r>')->descendants('{urn:p}item')->single();
        $reader = $element->createReader();
        self::assertTrue($reader->read());
        self::assertSame('p:item', $reader->name());
        self::assertSame('urn:p', $reader->namespaceUri());
        self::assertTrue($reader->isEmptyElement());
    }

    public function testMalformedXmlIsReportedDuringReading(): void
    {
        $reader = XmlReader::fromString('<root>');
        $this->expectException(\RuntimeException::class);
        while ($reader->read()) {
        }
    }
}
