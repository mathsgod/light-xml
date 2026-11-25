<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocumentType;

final class XDocumentType extends XContentNode
{
    public function __construct(
        string|DOMDocumentType|self $name,
        ?string $publicId = null,
        ?string $systemId = null,
        ?string $internalSubset = null,
    ) {
        if ($name instanceof DOMDocumentType) {
            parent::__construct($name);
            return;
        }

        if ($name instanceof self) {
            $internalSubset = $name->internalSubset();
            $systemId = $name->systemId();
            $publicId = $name->publicId();
            $name = $name->name();
        }

        if ($name === '' || strpbrk($name, " \t\r\n<>&[]\"'") !== false) {
            throw new \InvalidArgumentException('Invalid XML document type name.');
        }
        if ($publicId !== null && $systemId === null) {
            throw new \InvalidArgumentException('A public identifier requires a system identifier.');
        }

        $xml = '<!DOCTYPE '.$name;
        if ($publicId !== null) {
            $xml .= ' PUBLIC '.self::literal($publicId).' '.self::literal($systemId);
        } elseif ($systemId !== null) {
            $xml .= ' SYSTEM '.self::literal($systemId);
        }
        if ($internalSubset !== null) {
            $xml .= ' ['.$internalSubset.']';
        }

        // Parse only a placeholder document, with external resolution disabled.
        $document = XDocument::parse($xml.'><placeholder/>');
        $doctype = $document->documentType();
        if ($doctype === null || $doctype->name() !== $name
            || (string) $document->root()?->name() !== 'placeholder' || $document->nodes()->count() !== 2) {
            throw new \InvalidArgumentException('Invalid XML document type declaration.');
        }
        parent::__construct($doctype->node);
    }

    public function name(): string
    {
        return $this->node->name;
    }

    public function publicId(): ?string
    {
        return $this->node->publicId !== '' || $this->externalIdKind() === 'PUBLIC'
            ? $this->node->publicId
            : null;
    }

    public function systemId(): ?string
    {
        return $this->node->systemId !== '' || $this->externalIdKind() !== null
            ? $this->node->systemId
            : null;
    }

    public function internalSubset(): ?string
    {
        return $this->node->internalSubset;
    }

    private function externalIdKind(): ?string
    {
        // DOM returns an empty string for both omitted and explicitly empty identifiers.
        $afterName = substr($this->toXml(), strlen('<!DOCTYPE '.$this->name()));
        return preg_match('/\A\s+(PUBLIC|SYSTEM)\s/', $afterName, $matches) === 1 ? $matches[1] : null;
    }

    private static function literal(string $value): string
    {
        if (!str_contains($value, '"')) {
            return '"'.$value.'"';
        }
        if (!str_contains($value, "'")) {
            return "'".$value."'";
        }
        throw new \InvalidArgumentException('XML document type identifiers cannot contain both quote characters.');
    }
}
