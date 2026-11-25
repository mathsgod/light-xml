# light-xml

A LINQ-style XML package for PHP 8.3+, inspired by the core [.NET XDocument](https://learn.microsoft.com/en-us/dotnet/api/system.xml.linq.xdocument?view=net-10.0) API.

## Installation

Install the Composer package in your application.

```sh
composer require mathsgod/light-xml
```

Requires PHP 8.3+ and `ext-dom`. Composer installs `mathsgod/light-linq ^1.0` for lazy `IEnumerable` queries.

## Development

```sh
composer install
composer test
```

Import tree types such as `XDocument` and `XElement` from `Light\Xml\Linq`. The XML reader and writer are in `Light\Xml`.

## Documentation

- [XML guide](docs/xml.md)
- [XDocument](docs/XDocument.md)
- [XElement](docs/XElement.md)

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
$firstUser->setElementValue('active', true);

$xml = $document->toXml(formatOutput: true);
$document->save('users.xml', formatOutput: true);
```

DOM escapes text and attribute values, so you do not need to build XML strings manually. Boolean values are serialized as `true` or `false`.

## Parse and query XML

```php
$loaded = XDocument::load('users.xml');
$parsed = XDocument::parse('<root><item>one</item><item>two</item></root>');

$names = $loaded->descendants('user')
    ->where(fn ($user) => $user->element('active')?->value() === 'true')
    ->select(fn ($user) => $user->element('name')?->value())
    ->toArray(); // ['Alice']
```

`elements()` enumerates direct child elements only. `descendants()` enumerates all descendant elements. Document descendants include the root element, while element descendants exclude the element itself. Query sequences are lazy `IEnumerable` instances supporting light-linq filtering, projection, grouping, and other operations. The document itself is loaded entirely into memory during parse/load.

## Build another document from query results

```php
$transformed = new XDocument(
    new XElement('selected',
        $loaded->descendants('user')
            ->where(fn ($user) => $user->attribute('id')?->value() === '1'),
    ),
);
```

Constructors and `add()` accept elements, comments, and nested iterables. Elements also accept attributes, text nodes, CDATA, strings, numbers, and booleans. Documents can contain one root element, processing instructions, comments, and a DOCTYPE before the root. The document constructor also accepts an optional leading `XDeclaration`. Both containers ignore `null` entries.

## Editing and copying rules

| Method | Behavior |
| --- | --- |
| `new XDocument()` | Creates an empty document. `root()` returns `null` |
| `new XDocument($otherDocument)` | Deep-copies the entire document |
| `new XElement($otherElement)` | Deep-copies the element |
| `add(...$content)` | Adds content |
| `setValue($value)` | Replaces all child content while preserving attributes |
| `setElementValue($name, $value)` | Updates the first matching child element or adds one. Pass `null` to remove it |
| `setAttributeValue($name, $value)` | Adds or updates an attribute. Pass `null` to remove it |
| `removeNodes()` | Removes child nodes while preserving element attributes |
| `replaceAll(...$content)` | Replaces attributes and child nodes after snapshotting new content |
| `XElement::remove()` | Removes the element from its parent |
| `XElement::parent()` | Returns the parent element, or null |
| `XAttribute::setValue()` / `remove()` | Updates or removes an attribute |

Wrappers returned by queries reference nodes in the original document, so edits update that document. Passing an existing element to a constructor or `add()` **always creates a deep copy** instead of moving the original node. This is an explicit rule of this implementation. .NET may attach an unparented node directly to the new tree.

Adding a duplicate attribute name throws `InvalidArgumentException`. Use `setAttributeValue()` to overwrite it. Calling `remove()` on an element or attribute without a parent throws `LogicException`.

### Replace all element content

```php
$root = XElement::parse('<Root old="yes"><Data>1</Data><Data>3</Data></Root>');
$root->replaceAll(
    new XAttribute('version', 2),
    $root->elements('Data')->where(fn ($element) => (int) $element->value() >= 3),
);
// <Root version="2"><Data>3</Data></Root>
```

`replaceAll()` keeps the element itself and its parent, replacing its attributes
and all child nodes. It accepts the same content as `add()`: elements,
attributes, scalars, nested iterables and null. No arguments or empty content
clear the element. Input is fully materialized and copied before editing the
target, so queries over the current element work safely. Invalid content,
duplicate attributes or input iteration failures leave the target unchanged
(unless user callbacks themselves modify it). Existing source trees are copied.

Namespace names survive serialization. DOM may retain namespace declarations
or choose different prefixes. Subscribe through `onChanging()` and `onChanged()` to observe mutations. See the [change event guide](docs/xml.md#change-events) for listener and batch behavior.

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

## XML input and output

| Method | Supported inputs or outputs |
| --- | --- |
| `parse($xml)` | XML string |
| `load($path)` | File path, readable PHP stream, `Light\IO\TextReader`, or `Light\Xml\XmlReader` |
| `toXml()` | XML string |
| `save($path)` | File path, writable PHP stream, `Light\IO\TextWriter`, or `Light\Xml\XmlWriter` |

Install `mathsgod/light-io` to use the text reader and writer interfaces. The XML reader and writer require `ext-xmlreader` and `ext-xmlwriter` respectively. File paths and PHP streams work with the core package.

Loading preserves whitespace by default. Pass `options: LoadOptions::None` to let DOM remove indentation whitespace. Use `formatOutput: true` to indent output, or `options: SaveOptions::DisableFormatting` to suppress automatic indentation. XML writer destinations use the writer's settings. See the [XDocument guide](docs/XDocument.md) for details.

## Differences from .NET

The package covers synchronous XML construction, navigation, editing and I/O. Async I/O and XSD validation are outside the current API. The per-class guides describe the supported options and behaviors.
