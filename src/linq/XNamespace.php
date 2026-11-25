<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

final class XNamespace
{
    /** @var array<string, self> */
    private static array $namespaces = [];

    /** @var array<string, XName> */
    private array $names = [];

    private function __construct(public readonly string $namespaceName)
    {
    }

    private function __clone()
    {
    }

    public static function get(string $namespaceName): self
    {
        $key = '{'.$namespaceName;
        return self::$namespaces[$key] ??= new self($namespaceName);
    }

    public static function none(): self
    {
        return self::get('');
    }

    public static function xml(): self
    {
        return self::get('http://www.w3.org/XML/1998/namespace');
    }

    public static function xmlns(): self
    {
        return self::get('http://www.w3.org/2000/xmlns/');
    }

    public function getName(string $localName): XName
    {
        $key = '{'.$localName;
        return $this->names[$key] ??= new XName($localName, $this->namespaceName);
    }

    public function __toString(): string
    {
        return $this->namespaceName;
    }
}
