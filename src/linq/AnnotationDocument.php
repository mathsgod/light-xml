<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;
use DOMNode;
use SplObjectStorage;

/** @internal */
final class AnnotationDocument extends DOMDocument
{
    /** @var SplObjectStorage<DOMNode, list<object>>|null */
    public ?SplObjectStorage $annotations = null;

    public function __clone()
    {
        $this->annotations = null;
    }
}
