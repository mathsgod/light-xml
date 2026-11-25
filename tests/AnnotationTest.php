<?php

declare(strict_types=1);

namespace Light\Xml\Tests;

use Light\Xml\Linq\{XDocument, XElement};
use PHPUnit\Framework\TestCase;

final class AnnotationTest extends TestCase
{
    public function testAnnotationsSurviveFreshQueriesAndFilterByType(): void
    {
        $document = XDocument::parse('<root><user id="7"/></root>');
        $first = new \RuntimeException('First issue');
        $second = new \LogicException('Second issue');
        $metadata = new \stdClass();
        $document->root()->element('user')->addAnnotation($first);
        gc_collect_cycles();

        $user = $document->descendants('user')->single();
        $issues = $user->annotations(\Throwable::class);
        $user->addAnnotation($metadata);
        $user->addAnnotation($second);

        self::assertSame($first, $user->annotation(\Throwable::class));
        self::assertSame([$first, $second], $issues->toArray());
        $document->root()->element('user')->removeAnnotations(\Throwable::class);
        self::assertSame([], $issues->toArray());
        self::assertNull($user->annotation(\Throwable::class));
        self::assertSame($metadata, $user->annotation(\stdClass::class));
    }

    public function testCopyAndSerializationExcludeAnnotations(): void
    {
        $document = XDocument::parse('<root><user/></root>');
        $xml = $document->toXml();
        $user = $document->root()->element('user');
        $user->addAnnotation(new \stdClass());

        self::assertSame($xml, $document->toXml());
        self::assertNull((new XDocument($document))->root()->element('user')->annotation(\stdClass::class));
        self::assertNull((new XElement($user))->annotation(\stdClass::class));
        self::assertNull(XDocument::parse($xml)->root()->element('user')->annotation(\stdClass::class));

        $user->remove();
        self::assertInstanceOf(\stdClass::class, $user->annotation(\stdClass::class));
    }
}
