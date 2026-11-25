<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;

final class XDocument
{
    private DOMDocument $document;
    private ?XDeclaration $declaration = null;
    private bool $declarationOverridden = false;

    /** Create a document with an optional leading declaration and root, or a deep copy. */
    public function __construct(mixed ...$content)
    {
        $this->document = self::newDocument();
        if (count($content) === 1 && $content[0] instanceof self) {
            $this->document = clone $content[0]->document;
            $this->declaration = $content[0]->declaration;
            $this->declarationOverridden = $content[0]->declarationOverridden;
            return;
        }
        if (($content[0] ?? null) instanceof XDeclaration) {
            $this->setDeclaration(array_shift($content));
        }
        $this->add(...$content);
    }

    public function declaration(): ?XDeclaration
    {
        return $this->declaration;
    }

    public function setDeclaration(?XDeclaration $declaration): void
    {
        $this->declaration = $declaration;
        $this->declarationOverridden = true;
    }

    public function add(mixed ...$content): void
    {
        $nodes = [];
        $rootCount = 0;
        $collect = static function (mixed $item) use (&$collect, &$nodes, &$rootCount): void {
            if ($item === null) {
                return;
            }
            if ($item instanceof XElement || $item instanceof XComment) {
                $nodes[] = $item;
                $rootCount += (int) ($item instanceof XElement);
                return;
            }
            if (is_iterable($item)) {
                foreach ($item as $child) {
                    $collect($child);
                }
                return;
            }
            throw new \InvalidArgumentException('Document content must be an XElement, XComment, or iterable of document content.');
        };
        foreach ($content as $item) {
            $collect($item);
        }
        if ($rootCount + (int) ($this->document->documentElement !== null) > 1) {
            throw new \InvalidArgumentException('An XML document can have only one root element.');
        }
        foreach ($nodes as $node) {
            $this->document->appendChild($node->copyTo($this->document));
        }
    }

    /** @return IEnumerable<XComment> */
    public function comments(): IEnumerable
    {
        return XComment::childrenOf($this->document);
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
        if (self::startsWithDeclaration(self::normalizeDeclaration(substr($xml, 0, 32)))) {
            $result->declaration = self::readDeclaration(substr($xml, 0, strpos($xml, '>') + 1));
        }
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
        $result->declaration = self::readFileDeclaration($path);
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
        $document = $this->declarationOverridden ? $this->documentForSerialization() : $this->document;
        $omitDeclaration = $this->declarationOverridden && $this->declaration === null;
        $previous = $document->formatOutput;
        $document->formatOutput = $formatOutput;
        try {
            $xml = $document->saveXML(null, $omitDeclaration ? LIBXML_NOXMLDECL : 0);
            if ($xml === false || ($xml === '' && !$omitDeclaration)) {
                throw new \RuntimeException('Unable to serialize XML document.');
            }
            return $xml;
        } finally {
            $document->formatOutput = $previous;
        }
    }

    private function documentForSerialization(): DOMDocument
    {
        $document = new DOMDocument();
        if ($this->document->doctype !== null) {
            // PHP 8.3 cannot import a document type with its internal subset.
            $doctype = $this->document->saveXML($this->document->doctype);
            self::withXmlErrors(
                static fn (): bool => $document->loadXML($doctype.'<placeholder/>', LIBXML_NONET),
                'Unable copy XML document type.',
            );
            $document->removeChild($document->documentElement);
        }
        $document->xmlVersion = $this->declaration?->version() ?? '1.0';
        $encoding = $this->declaration?->encoding();
        if ($encoding !== null) {
            $document->encoding = $encoding;
        }
        $standalone = $this->declaration?->standalone();
        if ($standalone !== null) {
            $document->xmlStandalone = $standalone === 'yes';
        }
        $before = $document->doctype;
        foreach ($this->document->childNodes as $child) {
            if ($child instanceof \DOMDocumentType) {
                $before = null;
                continue;
            }
            $document->insertBefore($document->importNode($child, true), $before);
        }
        return $document;
    }

    private static function readFileDeclaration(string $path): ?XDeclaration
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \InvalidArgumentException("XML file is not readable: {$path}");
        }
        try {
            $prefix = fread($stream, 32);
            if ($prefix === false || !self::startsWithDeclaration(self::normalizeDeclaration($prefix))) {
                return null;
            }

            while (!str_contains($prefix, '>')) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $prefix .= $chunk;
            }
            return self::readDeclaration($prefix);
        } finally {
            fclose($stream);
        }
    }

    private static function normalizeDeclaration(string $prefix): string
    {
        // XML declaration fields are ASCII even in UTF-16 and UTF-32 input.
        return preg_replace('/\A(?:\xEF\xBB\xBF|\xFE\xFF|\xFF\xFE)/', '', str_replace("\0", '', $prefix));
    }

    private static function startsWithDeclaration(string $prefix): bool
    {
        return preg_match('/\A<\?xml[ \t\r\n]/', $prefix) === 1;
    }

    private static function readDeclaration(string $prefix): ?XDeclaration
    {
        $prefix = self::normalizeDeclaration($prefix);
        if (!self::startsWithDeclaration($prefix)) {
            return null;
        }
        $header = strstr($prefix, '?>', true);
        if ($header === false) {
            return null;
        }
        preg_match_all('/(version|encoding|standalone)[ \t\r\n]*=[ \t\r\n]*(["\x27])(.*?)\2/s', $header, $matches, PREG_SET_ORDER);
        $values = array_column($matches, 3, 1);
        return new XDeclaration($values['version'], $values['encoding'] ?? null, $values['standalone'] ?? null);
    }

    private static function newDocument(): DOMDocument
    {
        $document = new AnnotationDocument('1.0', 'UTF-8');
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
