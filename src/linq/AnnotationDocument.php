<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;
use DOMNode;
use SplObjectStorage;

/** @internal */
final class AnnotationDocument extends DOMDocument
{
    /** @var SplObjectStorage<DOMNode, array<int|string, object|array>>|null */
    public ?SplObjectStorage $annotations = null;

    public ?XDocument $wrapper = null;

    public function __clone()
    {
        $this->annotations = null;
        $this->wrapper = null;
    }
}
