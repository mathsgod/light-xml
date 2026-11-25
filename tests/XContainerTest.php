<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XComment, XContainer, XDocument, XElement, XNode, XText};
use PHPUnit\Framework\TestCase;

final class XContainerTest extends TestCase
{
    public function testDocumentAndElementShareContainerQueries(): void
    {
        $doc = XDocument::parse('<!--start--><root>text<child/></root>');
        $root = $doc->root();

        self::assertInstanceOf(XContainer::class, $doc);
        self::assertInstanceOf(XNode::class, $doc);
        self::assertInstanceOf(XContainer::class, $root);
        self::assertInstanceOf(XComment::class, $doc->firstNode());
        self::assertInstanceOf(XElement::class, $doc->lastNode());
        self::assertInstanceOf(XText::class, $root->firstNode());
        self::assertSame('child', (string) $root->lastNode()->name());
        self::assertSame(['root', 'child'], $doc->descendants()
            ->select(static fn (XElement $element): string => (string) $element->name())->toArray());
        self::assertSame(1, $root->descendants()->count());

        $root->removeNodes();
        self::assertNull($root->firstNode());
        self::assertNull($root->lastNode());
    }

    public function testDocumentAnnotationsAndLazyQueriesSurviveDomRebuild(): void
    {
        $doc = new XDocument(new XElement('root'));
        $annotation = new \stdClass();
        $doc->addAnnotation($annotation);
        $nodes = $doc->nodes();
        $source = XDocument::parse('<!DOCTYPE root><root/>');
        $doc->addFirst($source->firstNode());

        self::assertSame($annotation, $doc->annotation(\stdClass::class));
        self::assertSame(2, $nodes->count());
        self::assertNull((new XDocument($doc))->annotation(\stdClass::class));
    }
}
