<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XDocument, XElement, XObject, XObjectChange};
use PHPUnit\Framework\TestCase;

final class ChangeEventTest extends TestCase
{
    public function testEventsFollowNodesAcrossWrappersAndBubbleInBothPhases(): void
    {
        $doc = XDocument::parse('<root><item/></root>');
        $events = [];
        $record = static function (string $phase) use (&$events): \Closure {
            return static function (XObject $sender, XObjectChange $change) use ($phase, &$events): void {
                $events[] = [$phase, $change, $sender->parent()?->name()->localName];
            };
        };
        $doc->onChanging($record('document before'));
        $unsubscribe = $doc->root()->element('item')->onChanged($record('item after'));
        $doc->onChanged($record('document after'));
        $doc->root()->element('item')->remove();
        $unsubscribe();

        self::assertSame([
            ['document before', XObjectChange::Remove, 'root'],
            ['item after', XObjectChange::Remove, null],
            ['document after', XObjectChange::Remove, null],
        ], $events);
    }

    public function testAttributeEventsExposeOldAndNewValuesAndCanBeCancelled(): void
    {
        $root = new XElement('root');
        $events = [];
        $root->onChanged(static function (XObject $sender, XObjectChange $change) use (&$events): void {
            $events[] = [$change, $sender->value()];
        });
        $root->setAttributeValue('id', 'old');
        $unsubscribe = $root->onChanging(static function (XObject $sender): void {
            self::assertSame('old', $sender->value());
            throw new \RuntimeException('cancelled');
        });
        try {
            $root->setAttributeValue('id', 'blocked');
            self::fail('The listener should cancel the change.');
        } catch (\RuntimeException $error) {
            self::assertSame('cancelled', $error->getMessage());
        }
        $unsubscribe();
        self::assertSame('old', $root->attribute('id')->value());
        $root->setAttributeValue('id', 'new');
        $root->setAttributeValue('id', null);
        self::assertSame([
            [XObjectChange::Add, 'old'],
            [XObjectChange::Value, 'new'],
            [XObjectChange::Remove, 'new'],
        ], $events);
    }
}
