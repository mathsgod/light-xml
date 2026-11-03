<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMNode;

abstract class XNode
{
    protected function __construct(protected readonly DOMNode $node)
    {
    }
}
