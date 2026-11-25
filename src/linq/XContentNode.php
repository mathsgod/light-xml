<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMNode;

/** A wrapper for XML content without a dedicated node class. */
class XContentNode extends XNode
{
    /** @internal */
    public function __construct(DOMNode $node)
    {
        parent::__construct($node);
    }

    public function nodeType(): int
    {
        return $this->node->nodeType;
    }

    public function nodeName(): string
    {
        return $this->node->nodeName;
    }

    public function value(): string
    {
        return $this->node->nodeValue ?? '';
    }

    public function toXml(): string
    {
        $xml = $this->node->ownerDocument->saveXML($this->node);
        if ($xml === false) {
            throw new \RuntimeException('Unable to serialize XML node.');
        }
        return $xml;
    }

    public function __toString(): string
    {
        return $this->toXml();
    }
}
