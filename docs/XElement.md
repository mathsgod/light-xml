# XElement

[XML 指南](xml.md) · [XDocument](XDocument.md)

`Light\Xml\Linq\XElement` 代表一個 XML 元素，包含名稱、屬性及子節點。它可以獨立使用，也可以放入文件或其他元素。

## 建立與載入元素

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

建構內容可含元素、屬性、註解、文字節點、CDATA、純量、巢狀 iterable 或 `null`。純量會成為文字，布林值以 `true` 或 `false` 表示。加入既有節點會複製內容。

`parse($xml)` 和 `load($path)` 回傳解析結果的根元素。`load()` 的輸入類型及 `LoadOptions` 見 [XDocument 的載入說明](XDocument.md#載入-xml)。

## 名稱與 namespace

~~~php
use Light\Xml\Linq\{XElement, XNamespace};

$catalog = XNamespace::get('urn:catalog');
$item = new XElement($catalog->getName('item'), 'Notebook');
echo $item->name(); // {urn:catalog}item
~~~

查詢可用 `XName` 或 `'{urn:catalog}item'`。單獨的 `'item'` 匹配無 namespace 的名稱。`getDefaultNamespace()` 回傳預設 namespace；沒有時回傳 `XNamespace::none()`。`getNamespaceOfPrefix($prefix)` 查 prefix 綁定，空字串會拋出例外。`getPrefixOfNamespace($namespace)` 找其中一個有效非空 prefix。找不到綁定會回傳 `null`。

## 查詢與導覽

| 方法 | 用途 |
| --- | --- |
| `name()`、`value()` | 取元素的 `XName` 和所有後代文字的合併結果。 |
| `element($name)`、`elements($name = null)` | 取第一個符合的直接子元素或符合的直接子元素序列。 |
| `descendants($name = null)`、`descendantsAndSelf($name = null)` | 按文件順序找後代；後者會先考慮目前元素。 |
| `attribute($name)`、`attributes($name = null)` | 查指定屬性或列出屬性。缺少指定屬性時回傳 `null`。 |
| `nodes()`、`descendantNodes()`、`descendantNodesAndSelf()` | 查混合節點，包括文字、CDATA 和註解。 |
| `firstNode()`、`lastNode()`、`comments()` | 取首尾子節點或直接子註解。 |
| `ancestors()`、`ancestorsAndSelf()` | 按由近至遠的順序列出祖先，可用名稱篩選。 |
| `elementsBeforeSelf()`、`elementsAfterSelf()` | 按文件順序查前後的同層元素。 |
| `nodesBeforeSelf()`、`nodesAfterSelf()` | 按文件順序查前後的所有同層節點。 |

序列延遲執行，查到的節點可以直接編輯原樹。`parent()` 取父元素，`document()` 取所屬文件；根元素的 `parent()` 回傳 `null`。`nextNode()` 和 `previousNode()` 取相鄰節點。`isBefore()` 和 `isAfter()` 比較同一棵樹內的節點順序。

## 修改元素

~~~php
$item->setAttributeValue('id', 8);
$item->setElementValue('price', 15);
$item->setAttributeValue('legacy', null); // 移除屬性
$item->setElementValue('discount', null); // 移除第一個符合的直接子元素
~~~

| 方法 | 改動範圍 |
| --- | --- |
| `add()`、`addFirst()` | 分別在內容末尾和子節點開頭插入內容。`add()` 也接受屬性。 |
| `addBeforeSelf()`、`addAfterSelf()` | 在目前節點前後插入同層內容。 |
| `replaceWith()`、`remove()` | 替換或移除目前元素。 |
| `setValue()` | 將所有子節點換成文字，保留屬性。 |
| `replaceNodes()`、`removeNodes()` | 替換或清空子節點，保留屬性。 |
| `replaceAttributes()`、`removeAttributes()` | 替換或清空屬性，保留子節點。 |
| `replaceAll()`、`removeAll()` | 同時處理屬性與子節點，保留元素本身。 |

`replaceNodes($item->nodes())` 會先讀取並複製原節點，因此可用自己的延遲查詢作為輸入。`replaceAttributes()` 接受 `XAttribute` 和巢狀 iterable，重複屬性會拋出例外。同層插入或替換需要父容器。

## 輸出與串流

`toXml()` 和字串轉換輸出元素片段。`save()` 寫往路徑、可寫 stream 或 `TextWriter` 時，會以元素副本建立文件來儲存。傳入 `XmlWriter`，或直接呼叫 `writeTo($writer)`，則把元素寫到 writer 的目前位置。

`createReader()` 讀取元素快照，序列化時會帶上需要的繼承 namespace。`createWriter()` 緩衝新增的子節點，呼叫 `close()` 時才加入元素。

更多例子見 [XML 指南](xml.md)。
