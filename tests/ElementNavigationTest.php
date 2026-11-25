<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XComment, XDocument, XElement};
use PHPUnit\Framework\TestCase;

final class ElementNavigationTest extends TestCase
{
    public function testAncestorAndDescendantQueriesIncludeSelfAndFilterByNamespace(): void
    {
        $root = XElement::parse('<root xmlns="urn:main"><group><group id="inner"/><group xmlns=""/></group></root>');
        $outer = $root->element('{urn:main}group');
        $inner = $outer->element('{urn:main}group');
        $name = static fn (XElement $element): string => (string) $element->name();

        self::assertSame(['{urn:main}group', '{urn:main}root'], $inner->ancestors()->select($name)->toArray());
        self::assertSame(['inner', null], $inner->ancestorsAndSelf('{urn:main}group')
            ->select(static fn (XElement $element): ?string => $element->attribute('id')?->value())->toArray());
        self::assertSame(['{urn:main}group', '{urn:main}group', 'group'], $outer->descendantsAndSelf()->select($name)->toArray());
        self::assertSame(['group'], $outer->descendantsAndSelf('group')->select($name)->toArray());
    }

    public function testSiblingQueriesFollowDocumentOrderAndEvaluateLazily(): void
    {
        $root = XElement::parse('<root><a/>text<!--note--><b/><target/><c/><a/></root>');
        $target = $root->element('target');
        $name = static fn (XElement $element): string => (string) $element->name();
        $before = $target->elementsBeforeSelf()->select($name);
        $after = $target->elementsAfterSelf()->select($name);

        $target->addBeforeSelf(new XElement('inserted'));
        self::assertSame(['a', 'b', 'inserted'], $before->toArray());
        self::assertSame(['c', 'a'], $after->toArray());
        self::assertSame(['a'], $target->elementsAfterSelf('a')->select($name)->toArray());

        $target->remove();
        self::assertSame([], $before->toArray());
        self::assertSame([], $after->toArray());
    }

    public function testOrderAcrossBranchesAndBetweenDifferentNodeTypes(): void
    {
        $doc = XDocument::parse('<!--prefix--><root><left><leaf/></left><!--middle--><right/></root>');
        $root = $doc->root();
        $leaf = $root->element('left')->element('leaf');
        $right = $root->element('right');
        $middle = $root->comments()->single();

        self::assertTrue($root->isBefore($leaf));
        self::assertTrue($leaf->isAfter($root));
        self::assertTrue($leaf->isBefore($right));
        self::assertTrue($right->isAfter($leaf));
        self::assertTrue($leaf->isBefore($middle));
        self::assertTrue($root->isAfter($doc->comments()->single()));
        self::assertFalse($root->isBefore($doc->root()));
        self::assertFalse($root->isAfter($doc->root()));
        self::assertFalse($root->isBefore(null));
        self::assertTrue($root->isAfter(null));
    }

    public function testDisconnectedNodesCannotBeOrdered(): void
    {
        $root = new XElement('root', new XElement('child', new XComment('note')));
        $child = $root->element('child');
        $child->remove();
        $this->expectException(\LogicException::class);
        $root->isBefore($child);
    }
}
