<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XComment, XDocument, XDocumentType, XElement, XNode};
use PHPUnit\Framework\TestCase;

final class DocumentNodeTest extends TestCase
{
    public function testQueriesAndReplacementPreserveDocumentOrderAndInternalSubset(): void
    {
        $doc = XDocument::parse('<?xml version="1.0" standalone="no"?><!--start--><?p value?>'
            .'<!DOCTYPE root [<!ENTITY text "value">]><root><child>&text;</child></root><!--end-->');
        $nodes = $doc->nodes();
        $before = $doc->toXml();
        self::assertCount(5, $nodes->toArray());
        self::assertSame(
            ['<!--start-->', '<?p value?>', '<!DOCTYPE root', '<root><child>&', '<child>&text;<', '&text;', '<!--end-->'],
            $doc->descendantNodes()->select(static fn (XNode $node): string => substr((string) $node, 0, 14))->toArray(),
        );
        $doc->replaceNodes($nodes);
        self::assertSame($before, $doc->toXml());
        self::assertSame('value', $doc->root()->value());
        self::assertSame('no', $doc->declaration()->standalone());
    }

    public function testAddFirstPreservesOrderAndRejectsInvalidBatches(): void
    {
        $doc = new XDocument(new XElement('root'));
        $nodes = $doc->nodes();
        $doc->addFirst(new XComment('first'), [null, new XComment('second')]);
        self::assertSame(['<!--first-->', '<!--second-->', '<root/>'], $nodes->select(strval(...))->toArray());

        $before = $doc->toXml();
        try {
            $doc->replaceNodes(new XElement('one'), new XElement('two'));
            self::fail('Expected two root elements to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $doc->toXml());
        }
        $doc->replaceNodes();
        self::assertSame([], $nodes->toArray());
    }

    public function testDocumentTypeCanBeCopiedBeforeAnExistingRoot(): void
    {
        $source = XDocument::parse('<!DOCTYPE root [<!ENTITY text "value">]><root/>');
        $type = $source->nodes()->first();
        self::assertInstanceOf(XDocumentType::class, $type);
        $doc = new XDocument(new XElement('root'));
        $doc->addFirst($type);

        self::assertStringContainsString('<!ENTITY text "value">', $doc->toXml());
        self::assertSame('root', (string) $doc->root()->name());
        self::assertSame(XML_DOCUMENT_TYPE_NODE, $source->nodes()->first()->nodeType());
    }

    public function testConstructedDocumentTypeRetainsIdentifiersAndSubset(): void
    {
        $type = new XDocumentType('report', '-//Example//DTD Report//EN', 'report.dtd', '<!ENTITY company "Acme">');
        $doc = new XDocument(new XDocumentType($type), new XElement('report'));
        $loaded = XDocument::parse($doc->toXml());
        $loadedType = $loaded->documentType();

        self::assertSame('report', $loadedType?->name());
        self::assertSame('-//Example//DTD Report//EN', $loadedType->publicId());
        self::assertSame('report.dtd', $loadedType->systemId());
        self::assertStringContainsString('<!ENTITY company "Acme">', $loadedType->internalSubset());
        self::assertInstanceOf(XDocumentType::class, $loaded->firstNode());
        self::assertNull((new XDocument())->documentType());
        self::assertNull((new XDocumentType('report'))->systemId());
        self::assertSame('', (new XDocumentType('report', systemId: ''))->systemId());
    }

    public function testMalformedInternalSubsetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new XDocumentType('report', internalSubset: '<!ENTITY unclosed');
    }
}
