<?php

declare(strict_types=1);

namespace Light\Xml;

use XMLWriter as NativeWriter;

final class XmlWriter
{
    private bool $closed = false;
    private bool $documentEnded = false;
    private int $elementDepth = 0;

    private function __construct(
        private readonly NativeWriter $writer,
        private readonly bool $inMemory,
        private readonly ?\Closure $onClose = null,
    ) {
    }

    public static function openMemory(): self
    {
        return self::memoryWriter(null);
    }

    /** @internal */
    public static function forContainer(\Closure $onClose): self
    {
        return self::memoryWriter($onClose);
    }

    private static function memoryWriter(?\Closure $onClose): self
    {
        $writer = self::newWriter();
        self::check($writer->openMemory(), 'open memory');
        return new self($writer, true, $onClose);
    }

    public static function openUri(string $uri): self
    {
        $writer = self::newWriter();
        self::check(@$writer->openUri($uri), 'open the output URI');
        return new self($writer, false);
    }

    public function setIndent(bool $indent): void
    {
        self::check($this->native()->setIndent($indent), 'set indentation');
    }

    public function writeStartDocument(string $version = '1.0', ?string $encoding = null, ?string $standalone = null): void
    {
        self::check($this->native()->startDocument($version, $encoding, $standalone), 'start the document');
    }

    public function writeEndDocument(): void
    {
        self::check($this->native()->endDocument(), 'end the document');
        $this->documentEnded = true;
    }

    public function writeStartElement(string $localName, ?string $namespaceUri = null, ?string $prefix = null): void
    {
        $written = $namespaceUri === null && $prefix === null
            ? $this->native()->startElement($localName)
            : $this->native()->startElementNs($prefix, $localName, $namespaceUri);
        self::check($written, 'start an element');
        ++$this->elementDepth;
    }

    public function writeEndElement(): void
    {
        self::check($this->native()->endElement(), 'end an element');
        --$this->elementDepth;
    }

    public function writeAttributeString(
        string $localName,
        string $value,
        ?string $namespaceUri = null,
        ?string $prefix = null,
    ): void {
        $written = $namespaceUri === null && $prefix === null
            ? $this->native()->writeAttribute($localName, $value)
            : $this->native()->writeAttributeNs($prefix, $localName, $namespaceUri, $value);
        self::check($written, 'write an attribute');
    }

    public function writeString(string $value): void
    {
        $writer = $this->native();
        if ($this->elementDepth === 0 && trim($value, " \t\r\n") !== '') {
            throw new \InvalidArgumentException('Only whitespace text is allowed outside an element.');
        }
        self::check($writer->text($value), 'write text');
    }

    public function writeComment(string $value): void
    {
        if (str_contains($value, '--') || str_ends_with($value, '-')) {
            throw new \InvalidArgumentException('Invalid XML comment text.');
        }
        self::check($this->native()->writeComment($value), 'write a comment');
    }

    public function writeCData(string $value): void
    {
        // Split the CDATA terminator across adjacent sections.
        $parts = explode(']]>', $value);
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $section = ($index === 0 ? '' : '>').$part.($index === $last ? '' : ']]');
            self::check($this->native()->writeCdata($section), 'write CDATA');
        }
    }

    public function outputMemory(bool $flush = true): string
    {
        if (!$this->inMemory) {
            throw new \LogicException('The XML writer is not using a memory buffer.');
        }
        return $this->writer->outputMemory($flush && $this->onClose === null);
    }

    public function flush(): string|int
    {
        if ($this->onClose !== null) {
            return $this->outputMemory(false);
        }
        $result = $this->writer->flush();
        if (is_int($result) && $result < 0) {
            throw new \RuntimeException('Unable to flush the XML writer.');
        }
        return $result;
    }

    /** @internal Write XML serialized from an existing node. */
    public function writeSerializedNode(string $xml): void
    {
        self::check($this->native()->writeRaw($xml), 'write an XML node');
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if (!$this->documentEnded) {
            self::check($this->writer->endDocument(), 'finish the XML content');
        }
        if ($this->onClose !== null) {
            $xml = $this->writer->outputMemory();
            $this->closed = true;
            ($this->onClose)(str_ends_with($xml, "\n") ? substr($xml, 0, -1) : $xml);
            return;
        }
        if (!$this->inMemory) {
            $this->flush();
        }
        $this->closed = true;
    }

    private function native(): NativeWriter
    {
        if ($this->closed) {
            throw new \LogicException('The XML writer is closed.');
        }
        if ($this->documentEnded) {
            throw new \LogicException('The XML writer has ended the document.');
        }
        return $this->writer;
    }

    private static function newWriter(): NativeWriter
    {
        if (!extension_loaded('xmlwriter')) {
            throw new \LogicException('Light\\Xml\\XmlWriter requires ext-xmlwriter.');
        }
        return new NativeWriter();
    }

    private static function check(bool $success, string $operation): void
    {
        if (!$success) {
            throw new \RuntimeException("Unable to {$operation}.");
        }
    }
}
