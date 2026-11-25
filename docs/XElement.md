# XElement

[XML guide](xml.md) · [XDocument](XDocument.md)

`Light\Xml\Linq\XElement` represents an XML element with a name, attributes, and child nodes. An element can stand alone or belong to a document or another element.

## Create and load elements

~~~php
use Light\Xml\Linq\{XCData, XAttribute, XComment, XElement, XText};

$item = new XElement('item',
    new XAttribute('id', 7),
    new XText('Notebook '),
    new XElement('price', 12),
    new XCData('a < b'),
    new XComment('reviewed'),
);

$copy = new XElement($item);
$parsed = XElement::parse('<item id="7">Notebook</item>');
~~~

The constructor accepts elements, attributes, comments, text nodes, CDATA, scalar values, nested iterables, and `null`. Scalar values become text. Booleans become `true` or `false`. Inserting an existing node copies it.

`parse($xml)` and `load($path)` return the root element of the parsed XML. See [Load XML](XDocument.md#load-xml) for supported input types and `LoadOptions`.

## Names and namespaces

~~~php
use Light\Xml\Linq\{XElement, XNamespace};

$catalog = XNamespace::get('urn:catalog');
$item = new XElement($catalog->getName('item'), 'Notebook');
echo $item->name(); // {urn:catalog}item
~~~

Query by an `XName` or a string such as `'{urn:catalog}item'`. A bare `'item'` matches names with no namespace. `getDefaultNamespace()` returns the current default namespace or `XNamespace::none()`. `getNamespaceOfPrefix($prefix)` resolves a nonempty prefix and throws for an empty string. `getPrefixOfNamespace($namespace)` returns a matching nonempty prefix. Both prefix lookup methods return `null` if no binding matches.

## Query and navigate

| Method | Purpose |
| --- | --- |
| `name()`, `value()` | Get the `XName` and the combined descendant text. |
| `element($name)`, `elements($name = null)` | Find the first matching child element or enumerate matching child elements. |
| `descendants($name = null)`, `descendantsAndSelf($name = null)` | Find descendants in document order. The second method considers the current element first. |
| `attribute($name)`, `attributes($name = null)` | Find an attribute or enumerate attributes. An absent attribute returns `null`. |
| `nodes()`, `descendantNodes()`, `descendantNodesAndSelf()` | Query mixed nodes, including text, CDATA, and comments. |
| `firstNode()`, `lastNode()`, `comments()` | Get the first or last child node or enumerate direct child comments. |
| `ancestors()`, `ancestorsAndSelf()` | Enumerate ancestors from nearest to farthest, with an optional name filter. |
| `elementsBeforeSelf()`, `elementsAfterSelf()` | Query preceding or following sibling elements in document order. |
| `nodesBeforeSelf()`, `nodesAfterSelf()` | Query preceding or following sibling nodes in document order. |

Sequences are evaluated lazily. Editing a returned node updates the original tree. `parent()` returns the parent element and returns `null` for the root. `document()` returns the containing document. `nextNode()` and `previousNode()` return adjacent siblings. `isBefore()` and `isAfter()` compare positions within the same tree.

## Edit elements

~~~php
$item->setAttributeValue('id', 8);
$item->setElementValue('price', 15);
$item->setAttributeValue('legacy', null); // Remove the attribute
$item->setElementValue('discount', null); // Remove the first matching child element
~~~

| Method | Effect |
| --- | --- |
| `add()`, `addFirst()` | Append content or insert it before the first child. `add()` also accepts attributes. |
| `addBeforeSelf()`, `addAfterSelf()` | Insert sibling content before or after the current element. |
| `replaceWith()`, `remove()` | Replace or remove the current element. |
| `setValue()` | Replace child nodes with text while retaining attributes. |
| `replaceNodes()`, `removeNodes()` | Replace or clear child nodes while retaining attributes. |
| `replaceAttributes()`, `removeAttributes()` | Replace or clear attributes while retaining children. |
| `replaceAll()`, `removeAll()` | Replace or clear both attributes and child nodes while retaining the element. |

`replaceNodes($item->nodes())` reads and copies the original nodes before editing, so it can use a lazy query over the same element. `replaceAttributes()` accepts `XAttribute` objects and nested iterables. Duplicate attributes raise an exception. Sibling insertion and replacement require a parent container.

## Output and streaming

`toXml()` and string conversion produce an element fragment. When saving to a path, writable stream, or `TextWriter`, `save()` copies the element into a document for output. With an `XmlWriter`, `save()` and `writeTo($writer)` write the element at the writer's current position.

`createReader()` reads a snapshot that includes the inherited namespace declarations needed by the element. `createWriter()` buffers new children and appends them to the element when you call `close()`.

See the [XML guide](xml.md) for more examples.
