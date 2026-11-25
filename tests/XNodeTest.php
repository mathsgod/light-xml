<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XComment, XDocument, XElement, XNode, XObject, XText};
use PHPUnit\Framework\TestCase;

final class XNodeTest extends TestCase
{
    public function testDocumentAndMixedNodesCanCompareOrder(): void
    {
        $doc = XDocument::parse('<!--start--><root>text<![CDATA[data]]></root>');
        $comment = $doc->firstNode();
        [$text, $cdata] = $doc->root()->nodes()->toArray();

        self::assertTrue($doc->isBefore($comment));
        self::assertTrue($comment->isBefore($text));
        self::assertTrue($text->isAfter($doc));
        self::assertTrue($cdata->isAfter($text));
        self::assertFalse($doc->isBefore($doc));
    }

    public function testNodePropertiesFollowTheCurrentTree(): void
    {
        $doc = XDocument::parse('<!--start--><root xml:base="https://example.test/base/"><child id="7"/>text</root>');
        $root = $doc->root();
        $child = $root->element('child');
        $attribute = $child->attribute('id');

        self::assertSame(XML_DOCUMENT_NODE, $doc->nodeType());
        self::assertSame(XML_ELEMENT_NODE, $root->nodeType());
        self::assertSame(XML_ATTRIBUTE_NODE, $attribute->nodeType());
        self::assertSame($doc, $doc->document());
        self::assertSame($doc, $root->document());
        self::assertSame($doc, $attribute->document());
        self::assertSame('child', (string) $attribute->parent()->name());
        self::assertNull($root->parent());
        self::assertSame('https://example.test/base/', $child->baseUri());
        self::assertInstanceOf(XComment::class, $root->previousNode());
        self::assertInstanceOf(XText::class, $child->nextNode());
        self::assertNull($root->nextNode());
        self::assertNull($doc->previousNode());

        $child->remove();
        self::assertNull($child->document());
        self::assertNull($attribute->document());
        self::assertNull((new XElement('detached'))->document());

        $copy = new XDocument($doc);
        self::assertSame($copy, $copy->root()->document());
        $parsedRoot = XElement::parse('<other/>');
        self::assertSame('other', (string) $parsedRoot->document()?->root()?->name());
    }

    public function testMixedNodesCanNavigateAndEditTheirSiblings(): void
    {
        $root = XElement::parse('<root><first/>text<!--note--><last/></root>');
        [, $text, $comment] = $root->nodes()->toArray();
        self::assertInstanceOf(XText::class, $text);
        self::assertInstanceOf(XComment::class, $comment);
        self::assertSame('root', (string) $text->ancestors()->single()->name());
        self::assertSame('first', (string) $comment->elementsBeforeSelf()->single()->name());
        self::assertSame('last', (string) $text->elementsAfterSelf('last')->single()->name());
        self::assertSame(['<first/>', 'text'], $comment->nodesBeforeSelf()->select(strval(...))->toArray());
        self::assertSame(['<!--note-->', '<last/>'], $text->nodesAfterSelf()->select(strval(...))->toArray());

        $comment->addBeforeSelf(new XElement('added'));
        $text->addAfterSelf('!');
        $comment->replaceWith(new XText('new'));
        $text->remove();
        self::assertSame('<root><first/>!<added/>new<last/></root>', $root->toXml());
    }

    public function testDocumentHasNoParentAndAttributesOnlyInheritXObject(): void
    {
        $doc = XDocument::parse('<root id="1"/>');
        self::assertSame([], $doc->ancestors()->toArray());
        self::assertSame([], $doc->nodesAfterSelf()->toArray());
        $attribute = $doc->root()->attribute('id');
        self::assertInstanceOf(XObject::class, $attribute);
        self::assertNotInstanceOf(XNode::class, $attribute);
        $metadata = new \stdClass();
        $attribute->addAnnotation($metadata);
        self::assertSame($metadata, $doc->root()->attribute('id')->annotation(\stdClass::class));

        foreach ([
            static fn () => $doc->addBeforeSelf(new XComment('before')),
            static fn () => $doc->addAfterSelf(new XComment('after')),
            static fn () => $doc->replaceWith(),
            static fn () => $doc->remove(),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('Expected the parentless document mutation to fail.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('no parent', $error->getMessage());
            }
        }
    }

    public function testReplacingTopLevelCommentCannotAddASecondRoot(): void
    {
        $doc = XDocument::parse('<!--start--><?pi value?><root/>');
        $comment = $doc->firstNode();
        $before = $doc->toXml();
        try {
            $comment->replaceWith(new XElement('second'));
            self::fail('Expected invalid document content to fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $doc->toXml());
        }

        $instruction = $comment->nodesAfterSelf()->first();
        $comment->replaceWith($instruction);
        self::assertSame(['<?pi value?>', '<?pi value?>', '<root/>'], $doc->nodes()->select(strval(...))->toArray());
    }
}
