<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

final readonly class XDeclaration
{
    public function __construct(
        private string $version = '1.0',
        private ?string $encoding = null,
        private ?string $standalone = null,
    ) {
        if (preg_match('/\A1\.[0-9]+\z/', $version) !== 1) {
            throw new \InvalidArgumentException('Invalid XML version.');
        }
        if ($encoding !== null && preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/', $encoding) !== 1) {
            throw new \InvalidArgumentException('Invalid XML encoding name.');
        }
        if ($standalone !== null && $standalone !== 'yes' && $standalone !== 'no') {
            throw new \InvalidArgumentException('XML standalone must be yes or no.');
        }
    }

    public function version(): string
    {
        return $this->version;
    }

    public function encoding(): ?string
    {
        return $this->encoding;
    }

    public function standalone(): ?string
    {
        return $this->standalone;
    }

    public function __toString(): string
    {
        $xml = '<?xml version="'.$this->version.'"';
        if ($this->encoding !== null) {
            $xml .= ' encoding="'.$this->encoding.'"';
        }
        if ($this->standalone !== null) {
            $xml .= ' standalone="'.$this->standalone.'"';
        }
        return $xml.'?>';
    }
}
