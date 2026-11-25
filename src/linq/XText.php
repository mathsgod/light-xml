<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMElement;
use DOMText;

class XText extends XContentNode
{
    public function __construct(string|DOMText|self $value)
    {
        if ($value instanceof DOMText) {
            parent::__construct($value);
            return;
        }

        $text = $value instanceof self ? $value->value() : $value;
        self::validate($text);
        $document = new AnnotationDocument();
        parent::__construct($this->createNode($document, $text));
    }

    protected function createNode(\DOMDocument $document, string $value): DOMText
    {
        return $document->createTextNode($value);
    }

    public function setValue(string $value): void
    {
        self::validate($value);
        $this->node->nodeValue = $value;
    }

    public function parent(): ?XElement
    {
        $parent = $this->node->parentNode;
        return $parent instanceof DOMElement ? new XElement($parent) : null;
    }

    public function remove(): void
    {
        $this->node->parentNode?->removeChild($this->node);
    }

    private static function validate(string $value): void
    {
        if (preg_match('/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid XML text.');
        }
    }
}
