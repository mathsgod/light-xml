<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;

final class XComment extends XNode
{
    private readonly DOMComment $comment;

    public function __construct(string|DOMComment|self $value)
    {
        if ($value instanceof DOMComment) {
            $this->comment = $value;
        } else {
            $text = $value instanceof self ? $value->value() : $value;
            self::validate($text);
            $document = new AnnotationDocument();
            $this->comment = $document->createComment($text);
        }
        parent::__construct($this->comment);
    }

    public function value(): string
    {
        return $this->comment->data;
    }

    public function setValue(string $value): void
    {
        self::validate($value);
        $this->comment->data = $value;
    }

    public function parent(): ?XElement
    {
        $parent = $this->comment->parentNode;
        return $parent instanceof DOMElement ? new XElement($parent) : null;
    }

    public function remove(): void
    {
        $this->comment->parentNode?->removeChild($this->comment);
    }

    public function toXml(): string
    {
        return '<!--'.$this->value().'-->';
    }

    public function __toString(): string
    {
        return $this->toXml();
    }

    /** @internal */
    public function copyTo(DOMDocument $document): DOMComment
    {
        return $document->importNode($this->comment, true);
    }

    /** @internal @return IEnumerable<XComment> */
    public static function childrenOf(DOMNode $parent): IEnumerable
    {
        return Enumerable::defer(static function () use ($parent): iterable {
            foreach ($parent->childNodes as $child) {
                if ($child instanceof DOMComment) {
                    yield new self($child);
                }
            }
        });
    }

    private static function validate(string $value): void
    {
        if (str_contains($value, '--') || str_ends_with($value, '-')
            || preg_match('/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid XML comment text.');
        }
    }
}
