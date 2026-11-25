<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMCdataSection;
use DOMDocument;

final class XCData extends XText
{
    public function __construct(string|DOMCdataSection|XText $value)
    {
        parent::__construct($value);
    }

    protected function createNode(DOMDocument $document, string $value): DOMCdataSection
    {
        return $document->createCDATASection($value);
    }
}
