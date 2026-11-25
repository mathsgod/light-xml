<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XComment, XDeclaration, XDocument, XElement};
use PHPUnit\Framework\TestCase;

final class XCommentTest extends TestCase
{
    public function testCommentsRoundTripInOrderAndCanBeEdited(): void
    {
        $comment = new XComment('before & <');
        $doc = new XDocument(new XDeclaration(), $comment, [
            new XElement('root', 'a', $comment, 'b'),
            new XComment('after'),
        ]);
        $xml = $doc->toXml();
        self::assertStringContainsString("<!--before & <-->\n<root>a<!--before & <-->b</root>\n<!--after-->", $xml);

        $parsed = XDocument::parse($xml);
        [$before, $after] = $parsed->comments()->toArray();
        self::assertSame('before & <', $before->value());
        $after->remove();
        $root = $parsed->root();
        $nested = $root->comments()->single();
        self::assertSame('root', (string) $nested->parent()?->name());
        $nested->setValue('updated');
        self::assertSame('ab', $root->value());
        self::assertSame('<root>a<!--updated-->b</root>', $root->toXml());
        self::assertSame(1, $parsed->comments()->count());
        self::assertSame('<!--before & <-->', (string) $comment);
    }

    public function testReplaceAllCopiesCommentsFromLazyQuery(): void
    {
        $root = new XElement('root', 'old', new XComment('keep'));
        $original = $root->comments()->single();
        $root->replaceAll($root->comments(), 'new');
        self::assertNull($original->parent());
        self::assertSame('<root><!--keep-->new</root>', $root->toXml());
    }

    public function testInvalidCommentLeavesCurrentValueIntact(): void
    {
        $comment = new XComment('valid');
        foreach (['a--b', 'trailing-', "nul\0byte", "\xFF"] as $invalid) {
            try {
                $comment->setValue($invalid);
                self::fail('Expected invalid XML comment text to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame('valid', $comment->value());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new XComment('--');
    }

    public function testSecondRootRejectsTheWholeBatchIncludingComments(): void
    {
        $doc = new XDocument(new XElement('root'));
        try {
            $doc->add(new XComment('extra'), new XElement('other'));
            self::fail('Expected the second root to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $doc->comments()->count());
        }
    }
}
