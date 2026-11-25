<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XCData, XComment, XDocument, XElement, XNode, XText};
use PHPUnit\Framework\TestCase;

final class ElementNodeQueryTest extends TestCase
{
    public function testMixedChildAndDescendantNodesKeepTheirTypesAndOrder(): void
    {
        $xml = '<root id="1">a<!--note--><child><![CDATA[b<c]]><?test data?></child>d</root>';
        $root = XElement::parse($xml);
        $serialize = static fn (XNode $node): string => (string) $node;
        $children = ['a', '<!--note-->', '<child><![CDATA[b<c]]><?test data?></child>', 'd'];
        $descendants = ['a', '<!--note-->', $children[2], '<![CDATA[b<c]]>', '<?test data?>', 'd'];

        self::assertSame($children, $root->nodes()->select($serialize)->toArray());
        self::assertSame($descendants, $root->descendantNodes()->select($serialize)->toArray());
        self::assertSame([$xml, ...$descendants], $root->descendantNodesAndSelf()->select($serialize)->toArray());

        [$text, $comment, $child] = $root->nodes()->toArray();
        self::assertInstanceOf(XText::class, $text);
        self::assertSame(XML_TEXT_NODE, $text->nodeType());
        self::assertInstanceOf(XComment::class, $comment);
        self::assertInstanceOf(XElement::class, $child);
        [$cdata, $instruction] = $child->nodes()->toArray();
        self::assertInstanceOf(XCData::class, $cdata);
        self::assertSame(XML_CDATA_SECTION_NODE, $cdata->nodeType());
        self::assertSame('b<c', $cdata->value());
        self::assertSame(XML_PI_NODE, $instruction->nodeType());
        self::assertSame('test', $instruction->nodeName());
        self::assertSame('data', $instruction->value());
        self::assertTrue($child->isAfter($text));
    }

    public function testTextEditsOnlyOneSegmentAndInsertedNodesAreCopied(): void
    {
        $text = new XText('Hello ');
        $cdata = new XCData('a < b');
        $root = new XElement('p', $text, new XElement('b', 'Ada'), $cdata, new XText('!'));
        [$first, , $section, $last] = $root->nodes()->toArray();
        $first->setValue('Hi & ');
        $section->setValue('x < y');
        $last->remove();

        self::assertSame('<p>Hi &amp; <b>Ada</b><![CDATA[x < y]]></p>', $root->toXml());
        self::assertSame('Hello ', $text->value());
        self::assertSame('a < b', $cdata->value());
        self::assertSame('p', (string) $first->parent()?->name());
        self::assertNull($last->parent());
        self::assertSame('&lt;&amp;', (string) new XText('<&'));
        self::assertSame('a]]>b', XElement::parse('<p>'.new XCData('a]]>b').'</p>')->value());
    }

    public function testSiblingQueriesAreLazyAndWorkAtDocumentLevel(): void
    {
        $doc = XDocument::parse('<?intro data?><root>a<child/>b<?tail data?></root><!--end-->');
        $root = $doc->root();
        $child = $root->element('child');
        $serialize = static fn (XNode $node): string => (string) $node;
        $before = $child->nodesBeforeSelf()->select($serialize);
        $after = $child->nodesAfterSelf()->select($serialize);
        $child->addBeforeSelf(new XComment('new'));

        self::assertSame(['a', '<!--new-->'], $before->toArray());
        self::assertSame(['b', '<?tail data?>'], $after->toArray());
        self::assertSame(['<?intro data?>'], $root->nodesBeforeSelf()->select($serialize)->toArray());
        self::assertSame(['<!--end-->'], $root->nodesAfterSelf()->select($serialize)->toArray());
        $child->remove();
        self::assertSame([], $before->toArray());
        self::assertSame([], $after->toArray());
    }
}
