# LINQ to XML

[Package README](../README.md) · [Enumerable](https://github.com/mathsgod/light-linq/blob/v1.0.0/docs/enumerable.md)

The XML API uses DOM and requires `ext-dom`. Load your application's Composer autoloader before using the examples.

## Class guides

| Class | Topics |
| --- | --- |
| [XDocument](XDocument.md) | Document structure, declarations, DOCTYPE, input, output, and options |
| [XElement](XElement.md) | Construction, queries, namespaces, editing, and output |

## Read XML node by node

`Light\Xml\XmlReader` uses PHP's `ext-xmlreader`. Use `fromString($xml)` for an XML string or `openUri($path)` for a file. PHP 8.4 and later also support `fromStream($stream)`. On a document or element, `createReader()` serializes a complete snapshot first.

```php
$reader = $document->createReader();
while ($reader->read()) {
    if ($reader->nodeType() === \XMLReader::ELEMENT) {
        echo $reader->localName(), PHP_EOL;
    }
}
$reader->close();
```

## Write XML node by node

`Light\Xml\XmlWriter` uses PHP's `ext-xmlwriter`. Use `openMemory()` to produce XML in memory, or `openUri($path)` to write to a file and call `flush()` to flush the output.

```php
use Light\Xml\XmlWriter;

$writer = XmlWriter::openMemory();
$writer->writeStartDocument();
$writer->writeStartElement('report');
$writer->writeAttributeString('id', '7');
$writer->writeString('A & B');
$writer->writeEndElement();
$writer->writeEndDocument();
$xml = $writer->outputMemory();
```

`writeTo($writer)` writes an existing document or element to an XML writer. `createWriter()` creates a writer that appends content to the current document or element when you call `close()`.

```php
$target = new \Light\Xml\Linq\XDocument();
$writer = $target->createWriter();
$document->writeTo($writer);
$writer->close();
```

## Save to a stream or writer

`XDocument::save()` and `XElement::save()` accept writable PHP streams and `Light\Xml\XmlWriter`. Streams are written from their current position. For an `XmlWriter`, `save()` delegates to `writeTo()`. The caller manages the stream or writer lifetime.

Install the optional `mathsgod/light-io` package to pass a `Light\IO\TextWriter` to `save()`. It receives the same serialized XML string as file output.

```php
$writer = new \Light\IO\StringWriter();
$document->save($writer, formatOutput: true);
$xml = $writer->toString();
```

## Preserve whitespace and control indentation

`XDocument` and `XElement` use `LoadOptions::PreserveWhitespace` by default for `parse()` and `load()`. Pass `options: LoadOptions::None` to let DOM remove indentation whitespace.

Pass `options: SaveOptions::DisableFormatting` to `toXml()` or `save()` to suppress indentation, even when `formatOutput: true`. When saving through an `XmlWriter`, the writer controls its output settings.

## Parse and query XML

```php
use Light\Xml\Linq\XDocument;

$document = XDocument::parse('<users>
    <user active="true"><name>Ada</name></user>
    <user active="false"><name>Bob</name></user>
</users>');

$names = $document->descendants('user')
    ->where(fn ($user) => $user->attribute('active')?->value() === 'true')
    ->select(fn ($user) => $user->element('name')?->value())
    ->toArray(); // ['Ada']

$root = $document->root();
$children = $root?->elements('user')->toArray();
$xml = $document->toXml(formatOutput: true);
```

Use `XDocument::load('users.xml')` to load a file. Empty input, malformed XML, and unreadable file paths raise `InvalidArgumentException`.

`XDocument::load()` and `XElement::load()` also accept a readable PHP stream or a `Light\IO\TextReader` from the optional `mathsgod/light-io` package. Both read from the current position to the end and then parse the complete XML. The caller manages the input source.

When passing a `Light\Xml\XmlReader`, leave it unstarted or position it at a top-level node. `load()` consumes the remaining top-level content through EOF. The reader's node sequence omits the original XML declaration.

| Method | Purpose |
| --- | --- |
| `XDocument::root()` | Return the root element. |
| `XDocument::descendants($name)` | Find matching elements, including the root when it matches. |
| `XElement::element($name)` | Find the first matching direct child element or return `null`. |
| `XElement::elements($name = null)` | Enumerate direct child elements. |
| `XElement::descendants($name = null)` | Enumerate descendant elements. |
| `XElement::attribute($name)` | Find a matching attribute or return `null`. |
| `XElement::attributes($name = null)` | Enumerate attributes. |
| `XElement::value()` | Return the combined descendant text. |
| `XElement::name()` | Return the element's `XName`. |
| `XDocument::toXml($formatOutput = false)` | Serialize the document to an XML string. |

Query methods return lazy `IEnumerable` sequences with operators such as `where()`, `select()`, and `groupBy()`. `parse()` and `load()` build the full DOM tree in memory.

## XML declarations

```php
use Light\Xml\Linq\{XDeclaration, XDocument, XElement};

$document = new XDocument(
    new XDeclaration('1.0', 'UTF-8', 'yes'),
    new XElement('users'),
);
$encoding = $document->declaration()?->encoding(); // 'UTF-8'
$document->setDeclaration(null); // Omit the XML declaration when serializing
```

`declaration()` returns the source declaration when available, or `null`. Omitted `encoding` and `standalone` fields also return `null`. After setting a declaration explicitly, `toXml()` and `save()` use its encoding for serialization. Otherwise, DOM controls the default declaration output.

## XML comments

```php
use Light\Xml\Linq\{XComment, XDocument, XElement};

$document = new XDocument(
    new XComment('Export data'),
    new XElement('users', new XComment('Pending update')),
    new XComment('Export complete'),
);
$document->root()->comments()->single()->setValue('Updated');
```

The constructors and `add()` methods on `XDocument` and `XElement` accept `XComment`. `XElement::replaceAll()` accepts comments too. `comments()` returns direct child comments in document order, including parsed comments. Use `value()` to read a comment, `setValue()` to edit it, and `remove()` to detach it. Inserting a comment copies it. Editing a comment returned by a query changes the original tree.

## Namespaces, XNamespace, and XName

Use `XNamespace::get()` to reuse a namespace URI and `getName()` to construct an `XName` for building or querying XML.

```php
use Light\Xml\Linq\{XElement, XNamespace};

$people = XNamespace::get('urn:people');
$root = new XElement($people->getName('users'),
    new XElement($people->getName('user'), 'Ada'),
);
$user = $root->element($people->getName('user'));
```

`none()`, `xml()`, and `xmlns()` return the empty namespace and the two reserved XML namespaces. `get()` reuses objects by URI. `getName()` reuses names by local name within a namespace.

```php
use Light\Xml\Linq\XName;

$namespaced = XDocument::parse(
    '<users xmlns="urn:people"><user><name>Ada</name></user></users>'
);
$people = $namespaced->descendants('{urn:people}user')->toArray();
$name = $people[0]->element(new XName('name', 'urn:people'))?->value(); // 'Ada'
```

Use `{namespace-uri}localName` or `new XName($localName, $namespaceUri)`. A bare `'user'` matches names with no namespace. Use the URI for names in a default namespace or a prefixed namespace. See the [README](../README.md) for a complete create, edit, and save example.

| Method | Result |
| --- | --- |
| `getDefaultNamespace()` | The current default namespace or `XNamespace::none()`. |
| `getNamespaceOfPrefix($prefix)` | The namespace bound to the prefix or `null`. |
| `getPrefixOfNamespace($namespace)` | A matching nonempty prefix or `null`. |

Lookups respect inherited and overridden bindings. Use `getDefaultNamespace()` for the default binding. `getNamespaceOfPrefix('')` raises `InvalidArgumentException`.

## Element navigation and order

| Method | Purpose |
| --- | --- |
| `ancestors($name = null)` | Enumerate ancestors from nearest to farthest, starting with the parent. |
| `ancestorsAndSelf($name = null)` | Enumerate ancestors starting with the current element. |
| `descendantsAndSelf($name = null)` | Enumerate the current element and its descendants in document order. |
| `elementsBeforeSelf($name = null)` | Enumerate preceding sibling elements in document order. |
| `elementsAfterSelf($name = null)` | Enumerate following sibling elements in document order. |

These queries are lazy and accept an `XName` or name string as a filter. `isBefore($other)` and `isAfter($other)` compare `XNode` instances in the same tree. Both return `false` when comparing a node to itself. For a `null` argument, `isBefore()` returns `false` and `isAfter()` returns `true`. Comparing nodes in different trees raises `LogicException`.

## Change events

```php
use Light\Xml\Linq\{XObject, XObjectChange};

$unsubscribe = $document->onChanged(
    static function (XObject $sender, XObjectChange $change): void {
        echo $change->name, PHP_EOL;
    },
);
$document->root()->setAttributeValue('status', 'ready');
$unsubscribe();
```

`onChanging()` runs before a mutation and `onChanged()` runs after it succeeds. Events propagate from the affected object to its ancestors. Both registration methods return an unsubscribe callback. Change kinds include `Add`, `Remove`, and `Value`. XML declaration changes follow their own API.

Batch operations typically emit events per node. Writing a complete document with a DTD into an empty document emits one document-level `Add`. Listeners can read the XML. An exception from a `Changing` listener stops the pending mutation. Completed mutations remain applied if a batch stops partway through or a `Changed` listener throws.

## Annotations

```php
$user->addAnnotation(new RuntimeException('Missing email'));
$firstIssue = $user->annotation(Throwable::class);
$issues = $user->annotations(Throwable::class)->toArray();
$user->removeAnnotations(Throwable::class);
```

Annotations attach objects to a node and can be queried by class or interface. Wrappers for the same node share its annotations. Copying or serializing a node leaves the annotations on the original node.

## XObject, XNode, and XContainer

`XObject` provides annotations and is the direct base of `XAttribute` and `XNode`. Elements, documents, text, CDATA, and comments inherit from `XNode`. `XNode` provides ancestor and sibling queries and supports `addBeforeSelf()`, `addAfterSelf()`, `replaceWith()`, and `remove()`. A document is at the top of its tree, so its ancestor and sibling queries return empty sequences.

`XObject` also provides `nodeType()` using PHP's `XML_*` constants, `baseUri()` using DOM URI resolution, `parent()`, and `document()`. The root element's `parent()` returns `null`. Use `document()` to retrieve its document. A node outside a document returns `null` from `document()`. `XNode::nextNode()` and `previousNode()` return adjacent siblings.

`XDocument` and `XElement` both extend `XContainer`, which extends `XNode`. Use `XContainer` as a parameter type when the same code queries or edits either kind of container. `firstNode()` and `lastNode()` return the first and last child, or `null` for an empty container. Each container validates the content it accepts.

## Query nodes

| Method | Purpose |
| --- | --- |
| `nodes()` | Enumerate direct child nodes. |
| `descendantNodes()` | Enumerate all descendant nodes. |
| `descendantNodesAndSelf()` | Enumerate the current element and its descendant nodes. |
| `nodesBeforeSelf()` | Enumerate preceding sibling nodes. |
| `nodesAfterSelf()` | Enumerate following sibling nodes. |

Queries are lazy and follow document order. Elements, comments, text, CDATA, and document types return `XElement`, `XComment`, `XText`, `XCData`, and `XDocumentType` respectively. Processing instructions and other generic nodes return `XContentNode`, with accessors such as `nodeType()`, `nodeName()`, `value()`, and `toXml()`. Use `attributes()` to query attributes.

`XDocument::nodes()` includes top-level comments, processing instructions, the document type, and the root element. `descendantNodes()` also walks into the root element. `addFirst()` prepends content. `replaceNodes()` reads and validates the input before replacing nodes, so it accepts a query over that same container. Use `declaration()` to access the XML declaration. Importing a document type from another file rebuilds the DOM, leaving previous node wrappers detached.

### Document type

```php
use Light\Xml\Linq\{XDocument, XDocumentType, XElement};

$doc = new XDocument(
    new XDocumentType('report', systemId: 'report.dtd'),
    new XElement('report'),
);
$systemId = $doc->documentType()?->systemId();
```

`documentType()` returns an `XDocumentType` or `null`. You can also get this node from `nodes()`. Use `name()`, `publicId()`, `systemId()`, and `internalSubset()` to read its fields. Each document can have one `DOCTYPE` before its root element.

### Text and CDATA

```php
use Light\Xml\Linq\{XCData, XElement, XText};

$paragraph = new XElement('p', new XText('Hello '), new XElement('b', 'Ada'), new XCData('a < b'));
$paragraph->nodes()->first()->setValue('Hi ');
```

Pass `XText` or `XCData` to an element constructor, `add()`, or `replaceNodes()`. Insertion copies the node. Use `setValue()` or `remove()` on a queried node to edit the original tree.

## Insert and replace elements

| Method | Purpose |
| --- | --- |
| `addFirst(...$content)` | Insert content before the first child. |
| `addBeforeSelf(...$content)` | Insert siblings before the current element. |
| `addAfterSelf(...$content)` | Insert siblings after the current element. |
| `replaceWith(...$content)` | Replace the current element. Empty content removes it. |

Content can include elements, comments, scalar text, nested iterables, and `null`. These methods enumerate and validate the input before editing and copy inserted nodes. At document level, sibling insertion accepts elements, comments, and processing instructions and enforces the single-root rule. Sibling insertion and replacement require an attached node.

## Remove or replace attributes and child nodes

| Method | Purpose |
| --- | --- |
| `removeAll()` | Remove attributes and child nodes. |
| `removeAttributes()` | Remove attributes while keeping child nodes. |
| `replaceAttributes(...$content)` | Replace attributes with `XAttribute` objects, nested iterables, or `null`. |
| `replaceNodes(...$content)` | Replace child nodes. Accepts a query returned by `nodes()`. |

Replacement methods enumerate and validate input before editing. Empty content clears the corresponding items. DOM manages namespace declarations separately from the attributes returned by `attributes()`.

## Replace all attributes and child nodes

```php
$root = XElement::parse('<Root old="yes"><Data>1</Data><Data>2</Data><Data>3</Data></Root>');
$root->replaceAll(
    new XAttribute('version', 2),
    $root->elements('Data')
        ->where(fn ($element) => (int) $element->value() >= 2)
        ->select(fn ($element) => new XElement('NewData', $element->value())),
);
// <Root version="2"><NewData>2</NewData><NewData>3</NewData></Root>
```

`XElement::replaceAll(mixed ...$content)` keeps the element, its name, and its parent while replacing attributes and all child nodes, including text, comments, CDATA, and processing instructions. Other wrappers for the same element see the update.

It accepts the same content as `add()`. Scalar values become escaped text, with booleans serialized as `true` and `false`. No arguments, `null`, or an empty iterable clears the element's attributes and children.

The method enumerates and copies the input before editing the tree, so it accepts the element's own `elements()` or `attributes()` queries and single-use generators. Duplicate attributes, invalid input types, and iteration failures leave the target intact. Side effects from user callbacks or generators persist. Source elements are deep-copied, and old child wrappers continue to refer to their detached nodes.

DOM preserves namespace URIs and local names and may retain namespace declarations or choose different prefixes in the output.
