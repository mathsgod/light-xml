<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

use DOMDocument;

final class XDocument extends XContainer
{
    private AnnotationDocument $document;
    private ?XDeclaration $declaration = null;
    private bool $declarationOverridden = false;

    /** Create a document with an optional leading declaration and root, or a deep copy. */
    public function __construct(mixed ...$content)
    {
        if (count($content) === 1 && $content[0] instanceof self) {
            $this->document = clone $content[0]->document;
            parent::__construct($this->document);
            $this->declaration = $content[0]->declaration;
            $this->declarationOverridden = $content[0]->declarationOverridden;
            return;
        }
        $this->document = self::newDocument();
        parent::__construct($this->document);
        if (($content[0] ?? null) instanceof XDeclaration) {
            $this->setDeclaration(array_shift($content));
        }
        $this->add(...$content);
    }

    private function setDocument(AnnotationDocument $document): void
    {
        $this->replaceDocumentNode($document);
        $this->document = $document;
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
        $this->insertNodes($content);
    }

    public function addFirst(mixed ...$content): void
    {
        $this->insertNodes($content, first: true);
    }

    public function replaceNodes(mixed ...$content): void
    {
        $this->insertNodes($content, replace: true);
    }

    private function insertNodes(array $content, bool $first = false, bool $replace = false): void
    {
        $nodes = iterator_to_array(self::flattenContent($content), false);
        $document = $this->document;
        $existingType = $document->doctype;
        $existingRoot = !$replace && $document->documentElement !== null;
        $hasRoot = !$first && $existingRoot;
        $hasType = !$replace && $existingType !== null;
        $doctype = null;
        foreach ($nodes as $node) {
            if ($node instanceof XElement) {
                if ($hasRoot || $existingRoot) {
                    throw new \InvalidArgumentException('An XML document can have only one root element.');
                }
                if ($first && !$replace && $existingType !== null) {
                    throw new \InvalidArgumentException('The root element must follow the document type.');
                }
                $hasRoot = true;
            } elseif ($node instanceof XContentNode && $node->nodeType() === XML_DOCUMENT_TYPE_NODE) {
                if ($hasRoot || $hasType) {
                    throw new \InvalidArgumentException('A document type must occur once, before the root element.');
                }
                $hasType = true;
                $doctype = $node;
            }
        }

        if ($doctype !== null && ($existingType === null || !$doctype->wraps($existingType))) {
            if (!$replace && $document->firstChild !== null) {
                // Rebuild only when importing a type, which legacy DOM cannot copy directly.
                $existing = $this->nodes()->toArray();
                $nodes = $first ? [...$nodes, ...$existing] : [...$existing, ...$nodes];
            }
            $document = $this->documentWithType($doctype);
            $replace = true;
        }

        $prepared = [];
        foreach ($nodes as $node) {
            $prepared[] = $node === $doctype ? $document->doctype : $node->copyTo($document);
        }

        $before = $first && !$replace ? $document->firstChild : null;
        $retainedType = $doctype === null ? null : $document->doctype;
        if ($replace) {
            for ($child = $this->document->firstChild; $child !== null; $child = $next) {
                $next = $child->nextSibling;
                if ($child !== $retainedType) {
                    $this->document->removeChild($child);
                }
            }
            $this->setDocument($document);
            $before = $retainedType;
        }
        foreach ($prepared as $node) {
            if ($node === $retainedType) {
                $before = null;
                continue;
            }
            $document->insertBefore($node, $before);
        }
    }

    /** @return \Generator<int, XElement|XComment|XContentNode> */
    private static function flattenContent(iterable $content): \Generator
    {
        foreach ($content as $item) {
            if ($item === null) {
                continue;
            }
            if ($item instanceof XElement || $item instanceof XComment
                || ($item instanceof XContentNode && in_array($item->nodeType(), [XML_PI_NODE, XML_DOCUMENT_TYPE_NODE], true))) {
                yield $item;
            } elseif (is_iterable($item)) {
                yield from self::flattenContent($item);
            } else {
                throw new \InvalidArgumentException('Document content must be an element, comment, processing instruction or document type.');
            }
        }
    }

    private function documentWithType(XContentNode $doctype): AnnotationDocument
    {
        $document = self::newDocument();
        self::withXmlErrors(
            static fn (): bool => $document->loadXML($doctype->toXml().'<placeholder/>', LIBXML_NONET),
            'Unable to copy XML document type.',
        );
        $document->removeChild($document->documentElement);
        $document->xmlVersion = $this->document->xmlVersion;
        if ($this->document->encoding !== null) {
            $document->encoding = $this->document->encoding;
        }
        if ($this->declaration?->standalone() !== null) {
            $document->xmlStandalone = $this->declaration->standalone() === 'yes';
        }
        return $document;
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
        $result->setDocument($document);
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
        $result->setDocument($document);
        $result->declaration = self::readFileDeclaration($path);
        return $result;
    }

    public function root(): ?XElement
    {
        return $this->document->documentElement === null
            ? null
            : new XElement($this->document->documentElement);
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

    private static function newDocument(): AnnotationDocument
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
