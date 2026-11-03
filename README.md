# light-xml

A LINQ-style XML package for PHP 8.3+, inspired by the core [.NET XDocument](https://learn.microsoft.com/en-us/dotnet/api/system.xml.linq.xdocument?view=net-10.0) API. This package implements a subset of its features, rather than a complete port of the .NET API.

## Requirements and development setup

Requires PHP 8.3+ and `ext-dom`. The adjacent `../light-linq` and `../light-collections` directories are used as local Composer path repositories. light-linq provides `IEnumerable` queries and depends on the standalone collections package. The XML implementation, query tests, and fixtures have been moved from light-linq into this package.

```sh
composer install
composer test
```

The namespace is `Light\Xml\Linq`, mapped to `src/linq/` through PSR-4. Methods use PHP camelCase naming.

## Create, edit, and save a document

```php
require 'vendor/autoload.php';

use Light\Xml\Linq\XDocument;
use Light\Xml\Linq\XElement;
use Light\Xml\Linq\XAttribute;

$document = new XDocument(
    new XElement('users',
        new XElement('user',
            new XAttribute('id', 1),
            new XElement('name', 'Ada'),
            new XElement('active', true),
        ),
        new XElement('user',
            new XAttribute('id', 2),
            new XElement('name', 'Bob'),
        ),
    ),
);

$firstUser = $document->root()->element('user');
$firstUser->setElementValue('name', 'Alice');
$firstUser->setAttributeValue('active', true);

$xml = $document->toXml(formatOutput: true);
$document->save('users.xml', formatOutput: true);
```

DOM escapes text and attribute values, so you do not need to build XML strings manually. Boolean values are serialized as `true` or `false`.

## Parse and query XML

```php
$loaded = XDocument::load('users.xml');
$parsed = XDocument::parse('<root><item>one</item><item>two</item></root>');

$names = $loaded->descendants('user')
    ->where(fn ($user) => $user->attribute('active')?->value() === 'true')
    ->select(fn ($user) => $user->element('name')?->value())
    ->toArray(); // ['Alice']
```

`elements()` enumerates direct child elements only; `descendants()` enumerates all descendant elements. Document descendants include the root element, while element descendants exclude the element itself. Query sequences are lazy `IEnumerable` instances supporting light-linq filtering, projection, grouping, and other operations. The document itself is loaded entirely into memory during parse/load.

## Build another document from query results

```php
$transformed = new XDocument(
    new XElement('selected',
        $loaded->descendants('user')
            ->where(fn ($user) => $user->attribute('id')?->value() === '1'),
    ),
);
```

Constructors and `add()` accept elements and nested iterables. Elements also accept attributes, strings, numbers, and booleans. Null values are ignored. Documents accept only root elements or iterables containing them, with at most one root element. Document-level text and other objects are rejected.

## Editing and copying rules

| Method | Behavior |
| --- | --- |
| `new XDocument()` | Creates an empty document; `root()` returns null |
| `new XDocument($otherDocument)` | Deep-copies the entire document |
| `new XElement($otherElement)` | Deep-copies the element |
| `add(...$content)` | Adds content |
| `setValue($value)` | Replaces all child content while preserving attributes |
| `setElementValue($name, $value)` | Updates the first matching child element or adds one; null removes it |
| `setAttributeValue($name, $value)` | Adds or updates an attribute; null removes it |
| `removeNodes()` | Removes child nodes while preserving element attributes |
| `XElement::remove()` | Removes the element from its parent |
| `XElement::parent()` | Returns the parent element, or null |
| `XAttribute::setValue()` / `remove()` | Updates or removes an attribute |

Wrappers returned by queries reference nodes in the original document, so edits update that document. Passing an existing element to a constructor or `add()` **always creates a deep copy** instead of moving the original node. This is an explicit rule of this implementation; .NET may attach an unparented node directly to the new tree.

Adding a duplicate attribute name throws `InvalidArgumentException`; use `setAttributeValue()` to overwrite it. Calling `remove()` on an element or attribute without a parent throws `LogicException`.

## Namespaces

```php
use Light\Xml\Linq\XName;

$people = new XDocument(
    new XElement(new XName('users', 'urn:people'),
        new XElement('{urn:people}user',
            new XAttribute('{urn:ids}id', '7'),
        ),
    ),
);
$user = $people->descendants('{urn:people}user')->single();
$id = $user->attribute('{urn:ids}id')->value(); // '7'
```

Use `XName` or `{namespace-uri}localName` for names, rather than a prefix in place of the namespace URI. The library generates namespace prefixes for attributes. A local name alone matches nodes without a namespace.

## I/O and errors

Both `XDocument` and `XElement` provide `parse()`, `load()`, `toXml()`, and `save()`. Currently, load/save accept file paths only. `toXml()` and string conversion do not write files. Document serialization includes an XML declaration; an element's `toXml()` outputs only that element.

Malformed XML, unreadable input files, and invalid content raise exceptions. An empty document can exist and be serialized, but it must have a root element before `save()` can be called. Output failures throw `RuntimeException`. Formatting does not remove whitespace text nodes preserved during parsing.

## Differences from .NET

The core creation, copying, loading, querying, editing, and saving workflows are supported. Features not yet implemented include the full node construction API, such as `XDeclaration` and `XComment`, events, annotations, async operations, stream readers/writers, XSD validation, complete navigation, and replacement methods. Parsed document-level comments and processing instructions are preserved during serialization, but dedicated wrappers are not yet available.

## Migrating from light-linq XML

The old `Light\Linq\Xml` classes have been removed. Add this package as an application dependency and update imports to `Light\Xml\Linq`, for example: `use Light\Xml\Linq\XDocument;`.

Existing parse/load, root, elements/descendants, attribute/attributes, XName, and toXml usage remains supported. Previous query examples are available in the [XML query guide](docs/xml.md). light-linq retains collection queries and provider functionality; only this package requires `ext-dom`. The dependency direction is light-xml → light-linq → light-collections.
