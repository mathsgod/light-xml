<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\XDocument;
use Light\Xml\Linq\XElement;
use Light\Xml\XmlWriter;
use PHPUnit\Framework\TestCase;

final class XmlWriterTest extends TestCase
{
    public function testSaveLeavesWriterOpenAndAllowsWritingElementsInsideIt(): void
    {
        $writer = XmlWriter::openMemory();
        $writer->writeStartElement('target');
        (new XElement('child', 'a & b'))->save($writer);
        $writer->writeEndElement();
        $source = XDocument::load(\Light\Xml\XmlReader::fromString($writer->outputMemory()));
        self::assertSame('a & b', $source->root()->element('child')->value());

        $source->save($writer);
        $writer->writeComment('tail');
        self::assertSame('tail', XDocument::parse($writer->outputMemory())->comments()->single()->value());
    }

    public function testDocumentCanBeWrittenIntoAnotherDocumentOnClose(): void
    {
        $source = XDocument::parse('<?xml version="1.0" encoding="ISO-8859-1"?>'
            .'<!--top--><!DOCTYPE root [<!ENTITY title "Report">]><root>&title; '.chr(233).'</root>');
        $target = new XDocument();
        $writer = $target->createWriter();
        $source->writeTo($writer);
        self::assertNull($target->root());

        $writer->close();
        $writer->close();
        self::assertSame('Report é', $target->root()?->value());
        self::assertSame('top', $target->comments()->single()->value());
        self::assertSame('root', $target->documentType()?->name());
        self::assertSame('ISO-8859-1', $target->declaration()?->encoding());
    }

    public function testElementWriterAppendsMixedNodesAndPreservesInheritedBindings(): void
    {
        $source = XElement::parse('<r xmlns:p="urn:p"><p:item>é</p:item></r>');
        $target = new XElement('target', 'existing');
        $writer = $target->createWriter();
        $writer->writeString(' & ');
        $source->element('{urn:p}item')->writeTo($writer);
        $writer->writeComment('tail');
        $writer->close();

        self::assertSame('existing & é', $target->value());
        self::assertSame('é', $target->element('{urn:p}item')?->value());
        self::assertSame('tail', $target->comments()->single()->value());
    }

    public function testInvalidDocumentBatchDoesNotChangeTheTarget(): void
    {
        $doc = XDocument::parse('<root/>');
        $before = $doc->toXml();
        $writer = $doc->createWriter();
        $writer->writeComment('tentative');
        $writer->writeStartElement('second');
        try {
            $writer->close();
            self::fail('Expected a second root to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $doc->toXml());
        }
    }

    public function testMemoryWriterEscapesTextAndPreservesCdata(): void
    {
        $writer = XmlWriter::openMemory();
        $writer->writeStartDocument();
        $writer->writeStartElement('root', 'urn:example', 'p');
        $writer->writeAttributeString('flag', '"<&', 'urn:attributes', 'a');
        $writer->writeString('<&');
        $writer->writeComment('note');
        $writer->writeCData('a]]>b]]>c');
        $writer->writeEndElement();
        $writer->writeEndDocument();

        $xml = $writer->outputMemory(false);
        $root = XDocument::parse($xml)->root();
        self::assertSame('{urn:example}root', (string) $root->name());
        self::assertSame('"<&', $root->attribute('{urn:attributes}flag')->value());
        self::assertSame('<&a]]>b]]>c', $root->value());
        self::assertSame('note', $root->comments()->single()->value());
        self::assertSame($xml, $writer->outputMemory());
        self::assertSame('', $writer->outputMemory());
    }

    public function testUriWriterFlushesToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'light-xml-');
        try {
            $writer = XmlWriter::openUri($path);
            $writer->writeStartElement('root');
            $writer->writeString('value');
            $writer->writeEndElement();
            self::assertIsInt($writer->flush());
            self::assertSame('value', XDocument::load($path)->root()->value());
        } finally {
            unset($writer);
            unlink($path);
        }
    }
}
