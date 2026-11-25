<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XAttribute, XComment, XDocument, XElement};
use PHPUnit\Framework\TestCase;

final class ElementInsertionTest extends TestCase
{
    public function testInsertionAndReplacementPreserveContentOrder(): void
    {
        $root = XElement::parse('<root>start<target/>end</root>');
        $target = $root->element('target');
        $root->addFirst(null, ['first', new XComment('note')]);
        $target->addBeforeSelf('before', new XElement('left'));
        $target->addAfterSelf([new XElement('right'), 'after']);
        $target->replaceWith($target, true);

        self::assertSame(
            '<root>first<!--note-->startbefore<left/><target/>true<right/>afterend</root>',
            $root->toXml(),
        );
        self::assertNull($target->parent());
        $root->element('target')->replaceWith();
        self::assertNull($root->element('target'));
    }

    public function testAddFirstSnapshotsLazyContentAndPreservesNamespaces(): void
    {
        $root = XElement::parse('<root xmlns="urn:root"><plain xmlns=""/></root>');
        $root->addFirst($root->elements(), new XElement('other'));

        self::assertSame(['plain', 'other', 'plain'], array_map(
            static fn (XElement $child): string => (string) $child->name(),
            XElement::parse($root->toXml())->elements()->toArray(),
        ));
    }

    public function testRootSupportsSiblingCommentsAndReplacement(): void
    {
        $doc = XDocument::parse('<old/>');
        $root = $doc->root();
        $root->addBeforeSelf(new XComment('before'));
        $root->addAfterSelf(new XComment('after'));
        $root->replaceWith(new XComment('replacement'), new XElement('new'));

        self::assertStringContainsString("<!--before-->\n<!--replacement-->\n<new/>\n<!--after-->", $doc->toXml());
        self::assertSame('new', (string) $doc->root()->name());
    }

    public function testInvalidReplacementLeavesTheOriginalTreeIntact(): void
    {
        $doc = XDocument::parse('<root><child/></root>');
        $before = $doc->toXml();
        $invalidContent = [
            [new XElement('one'), new XElement('two')],
            ['text'],
            [new XAttribute('a', 1)],
            [new XAttribute('xmlns', 'urn:example')],
        ];
        foreach ($invalidContent as $content) {
            try {
                $doc->root()->replaceWith(new XComment('extra'), $content);
                self::fail('Expected invalid replacement to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $doc->toXml());
            }
        }
    }

    public function testSiblingInsertionRequiresAnAttachedElement(): void
    {
        $this->expectException(\LogicException::class);
        (new XElement('detached'))->addAfterSelf(new XComment('note'));
    }
}
