<?php

declare(strict_types=1);

namespace Light\Xml;

use XMLReader as NativeReader;

final class XmlReader
{
    private function __construct(private readonly NativeReader $reader)
    {
    }

    public static function fromString(string $xml): self
    {
        $reader = self::newReader();
        self::withXmlErrors(static fn (): bool => $reader->XML($xml, null, LIBXML_NONET));
        return new self($reader);
    }

    public static function openUri(string $uri): self
    {
        $reader = self::newReader();
        self::withXmlErrors(static fn (): bool => $reader->open($uri, null, LIBXML_NONET));
        return new self($reader);
    }

    /** @param resource $stream */
    public static function fromStream(mixed $stream): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream resource.');
        }
        if (!method_exists(NativeReader::class, 'fromStream')) {
            throw new \LogicException('XmlReader::fromStream() requires PHP 8.4 or later and ext-xmlreader.');
        }
        return new self(self::withXmlErrors(
            static fn (): NativeReader => NativeReader::fromStream($stream, null, LIBXML_NONET),
        ));
    }

    public function read(): bool
    {
        return self::withXmlErrors(fn (): bool => $this->reader->read(), allowFalse: true);
    }

    /** @internal Reconstruct the unread document from native reader events. */
    public function readDocumentXml(): string
    {
        if ($this->nodeType() === NativeReader::NONE && !$this->read()) {
            throw new \InvalidArgumentException('The XML reader has no remaining document.');
        }

        if ($this->depth() !== 0) {
            throw new \InvalidArgumentException('The XML reader must be positioned at document level.');
        }

        $xml = '';
        do {
            if ($this->depth() === 0 && in_array($this->nodeType(), [
                NativeReader::ELEMENT, NativeReader::DOC_TYPE, NativeReader::COMMENT, NativeReader::PI,
            ], true)) {
                $outer = self::withXmlErrors(fn (): string => $this->reader->readOuterXml());
                if ($outer === '') {
                    throw new \RuntimeException('Unable to serialize the current XML reader node.');
                }
                $xml .= $outer;
            }
        } while ($this->read());

        return $xml;
    }

    public function close(): void
    {
        $this->reader->close();
    }

    public function nodeType(): int
    {
        return $this->reader->nodeType;
    }

    public function name(): string
    {
        return $this->reader->name;
    }

    public function localName(): string
    {
        return $this->reader->localName;
    }

    public function namespaceUri(): string
    {
        return $this->reader->namespaceURI;
    }

    public function prefix(): string
    {
        return $this->reader->prefix;
    }

    public function value(): string
    {
        return $this->reader->value;
    }

    public function depth(): int
    {
        return $this->reader->depth;
    }

    public function isEmptyElement(): bool
    {
        return $this->reader->isEmptyElement;
    }

    public function getAttribute(string $name): ?string
    {
        return $this->reader->getAttribute($name);
    }

    private static function newReader(): NativeReader
    {
        if (!extension_loaded('xmlreader')) {
            throw new \LogicException('Light\\Xml\\XmlReader requires ext-xmlreader.');
        }
        return new NativeReader();
    }

    private static function withXmlErrors(callable $operation, bool $allowFalse = false): mixed
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $result = $operation();
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new \RuntimeException(trim($error->message));
                }
            }
            if ($result === false && !$allowFalse) {
                throw new \RuntimeException('Unable to open the XML reader.');
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
