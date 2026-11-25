# XDocument

[XML guide](xml.md) · [XElement](XElement.md)

`Light\Xml\Linq\XDocument` represents an entire XML document, including its root element, declaration, comments, and processing instructions. A document can also contain one `DOCTYPE` before the root element.

## Create a document

~~~php
use Light\Xml\Linq\{XComment, XDeclaration, XDocument, XDocumentType, XElement};

$doc = new XDocument(
    new XDeclaration('1.0', 'UTF-8'),
    new XComment('Inventory snapshot'),
    new XDocumentType('inventory', systemId: 'inventory.dtd'),
    new XElement('inventory', new XElement('item', 'Notebook')),
);

$copy = new XDocument($doc);
~~~

`new XDocument()` creates an empty document. Add a root later with `add()`. The constructor accepts an optional leading `XDeclaration`. Copying a document copies its XML tree. Annotations and event subscriptions stay on the original nodes.

## Load XML

| Method | Input and behavior |
| --- | --- |
| `parse($xml, options: ...)` | Parse a string containing XML. |
| `load($path, options: ...)` | Read a local file path. |
| `load($stream, options: ...)` | Read a PHP stream from its current position to EOF. |
| `load($reader, options: ...)` | Read the remaining text from an optional `Light\IO\TextReader`, or read the remaining top-level nodes from a `Light\Xml\XmlReader`. The XML reader can be unstarted or positioned at a top-level node. |

~~~php
use Light\Xml\Linq\{LoadOptions, XDocument};

$doc = XDocument::load('inventory.xml', options: LoadOptions::None);
$inline = XDocument::parse('<inventory><item>Notebook</item></inventory>');
~~~

`LoadOptions::PreserveWhitespace` preserves whitespace nodes by default. `LoadOptions::None` lets DOM remove whitespace it recognizes as indentation. The caller closes the stream or reader. Loading through `XmlReader` omits the original XML declaration.

On libxml versions that omit the DOCTYPE from reader output, loading through an
`XmlReader` requires a replayable source when a DOCTYPE is present. Use an XML
string, a file, or a seekable stream for these documents.

## Read the document structure

| Method | Result |
| --- | --- |
| `root()` | The root element or `null`. |
| `declaration()` | The XML declaration or `null`. |
| `documentType()` | The `XDocumentType` or `null`. |
| `firstNode()`, `lastNode()` | The first or last top-level node, or `null`. |
| `element($name)`, `elements($name = null)` | The matching root element or a sequence containing it. |
| `descendants($name = null)` | Matching elements in document order, including the root when it matches. |
| `nodes()`, `descendantNodes()` | Top-level nodes or all descendant nodes, respectively. |
| `comments()` | Top-level comments. |

Queries return lazy `IEnumerable` sequences. Editing a returned node updates the original tree. `nodes()` includes the `DOCTYPE`. Use `declaration()` to retrieve the XML declaration.

## Edit a document

- `add(...$content)` appends nodes. `addFirst(...$content)` inserts them before the current first node.
- `replaceNodes(...$content)` reads and validates input before replacing the top-level nodes. It accepts the same document's `nodes()` sequence.
- `removeNodes()` clears the top-level nodes. Use `setDeclaration($declaration)` to update the declaration or pass `null` to omit it from output.

Content can include elements, comments, processing instructions, a document type, nested iterables, and `null`. A document can have at most one root and one document type. Insertion copies the supplied nodes. Importing a `DOCTYPE` from a different document rebuilds the underlying DOM and detaches previously returned node wrappers.

## Serialize and save

~~~php
use Light\Xml\Linq\SaveOptions;

$xml = $doc->toXml(formatOutput: true);
$doc->save('inventory.xml', formatOutput: true);
$doc->save($stream, options: SaveOptions::DisableFormatting);
~~~

`toXml()` and string conversion return the XML. `save()` requires a root and accepts a file path, writable PHP stream, optional `Light\IO\TextWriter`, or `Light\Xml\XmlWriter`. Stream output starts at the current position. For `XmlWriter`, `save()` uses `writeTo()` and the writer controls its output. The caller closes the stream or writer.

`formatOutput: true` enables DOM indentation. `SaveOptions::DisableFormatting` suppresses that indentation while preserving existing whitespace text.

### XML readers and writers

`createReader()` uses `ext-xmlreader` to read a serialized snapshot of the document. `createWriter()` and `writeTo()` use `Light\Xml\XmlWriter`, which requires `ext-xmlwriter`.

~~~php
use Light\Xml\Linq\XDocument;

$target = new XDocument();
$writer = $target->createWriter();
$doc->writeTo($writer);
$writer->close(); // Commit buffered content
~~~

See the [XML guide](xml.md) for change events and annotations.
