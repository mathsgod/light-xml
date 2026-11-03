<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;

final class XDocument
{
    private DOMDocument $document;

    /** Create an empty document, a document with a root, or a deep copy. */
    public function __construct(mixed ...$content)
    {
        $this->document = self::newDocument();
        if (count($content) === 1 && $content[0] instanceof self) {
            $this->document = clone $content[0]->document;
            return;
        }
        $this->add(...$content);
    }

    public function add(mixed ...$content): void
    {
        $elements = [];
        $collect = static function (mixed $item) use (&$collect, &$elements): void {
            if ($item === null) {
                return;
            }
            if ($item instanceof XElement) {
                $elements[] = $item;
                return;
            }
            if (is_iterable($item)) {
                foreach ($item as $child) {
                    $collect($child);
                }
                return;
            }
            throw new \InvalidArgumentException('Document content must be an XElement or iterable of elements.');
        };
        foreach ($content as $item) {
            $collect($item);
        }
        if (count($elements) + ($this->root() === null ? 0 : 1) > 1) {
            throw new \InvalidArgumentException('An XML document can have only one root element.');
        }
        foreach ($elements as $element) {
            $this->document->appendChild($element->copyTo($this->document));
        }
    }

    public function removeNodes(): void
    {
        while ($this->document->firstChild !== null) {
            $this->document->removeChild($this->document->firstChild);
        }
    }

    public function element(XName|string $name): ?XElement
    {
        $root = $this->root();
        return $root !== null && XName::from($name)->matches($this->document->documentElement)
            ? $root : null;
    }

    /** @return IEnumerable<XElement> */
    public function elements(XName|string|null $name = null): IEnumerable
    {
        return Enumerable::defer(function () use ($name): iterable {
            $root = $name === null ? $this->root() : $this->element($name);
            if ($root !== null) {
                yield $root;
            }
        });
    }

    public function save(string $path, bool $formatOutput = false): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Output path cannot be empty.');
        }
        if ($this->root() === null) {
            throw new \LogicException('A document must have a root element before it can be saved.');
        }
        if (@file_put_contents($path, $this->toXml($formatOutput)) === false) {
            throw new \RuntimeException("Unable to save XML file: {$path}");
        }
    }

    public function __toString(): string
    {
        return $this->toXml();
    }

    public static function parse(string $xml): self
    {
        if ($xml === '') {
            throw new \InvalidArgumentException('XML input cannot be empty.');
        }
        $document = self::newDocument();
        self::withXmlErrors(
            static fn (): bool => $document->loadXML($xml, LIBXML_NONET),
            'Unable to parse XML.',
        );
        $result = new self();
        $result->document = $document;
        return $result;
    }

    public static function load(string $path): self
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("XML file is not readable: {$path}");
        }
        $document = self::newDocument();
        self::withXmlErrors(
            static fn (): bool => $document->load($path, LIBXML_NONET),
            "Unable to load XML file: {$path}",
        );
        $result = new self();
        $result->document = $document;
        return $result;
    }

    public function root(): ?XElement
    {
        return $this->document->documentElement === null
            ? null
            : new XElement($this->document->documentElement);
    }

    /** @return IEnumerable<XElement> */
    public function descendants(XName|string|null $name = null): IEnumerable
    {
        $document = $this->document;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($document, $name): iterable {
            $root = $document->documentElement;
            if ($root === null) {
                return;
            }
            if ($name === null || $name->matches($root)) {
                yield new XElement($root);
            }
            yield from (new XElement($root))->descendants($name);
        });
    }

    public function toXml(bool $formatOutput = false): string
    {
        $previous = $this->document->formatOutput;
        $this->document->formatOutput = $formatOutput;
        try {
            $xml = $this->document->saveXML();
            if ($xml === false) {
                throw new \RuntimeException('Unable to serialize XML document.');
            }
            return $xml;
        } finally {
            $this->document->formatOutput = $previous;
        }
    }

    private static function newDocument(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        return $document;
    }

    /** @param callable(): bool $operation */
    private static function withXmlErrors(callable $operation, string $message): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if ($operation()) {
                return;
            }
            $errors = libxml_get_errors();
            $detail = $errors === [] ? '' : ' '.trim($errors[0]->message);
            throw new \InvalidArgumentException($message.$detail);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
