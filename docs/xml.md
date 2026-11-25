# LINQ to XML

[套件說明](../README.md) · [Enumerable](https://github.com/mathsgod/light-linq/blob/v1.0.0/docs/enumerable.md)

XML API 以 DOM 為基礎，需要 `ext-dom`。先載入專案的 Composer autoloader。

## 逐步讀取 XML

`Light\Xml\XmlReader` 使用 PHP 的 `ext-xmlreader`。可用 `fromString($xml)` 讀取
XML 字串，或用 `openUri($path)` 讀取檔案。PHP 8.4 及以上亦支援
`fromStream($stream)`。文件和元素的 `createReader()` 會先序列化成完整的 XML 快照。

```php
$reader = $document->createReader();
while ($reader->read()) {
    if ($reader->nodeType() === \XMLReader::ELEMENT) {
        echo $reader->localName(), PHP_EOL;
    }
}
$reader->close();
```

## 逐步寫入 XML

`Light\Xml\XmlWriter` 使用 PHP 的 `ext-xmlwriter`。可用 `openMemory()`
在記憶體產生 XML，或用 `openUri($path)` 寫入檔案，再呼叫 `flush()`。

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

`writeTo($writer)` 將現有文件或元素寫入指定的 XML writer。`createWriter()`
則建立一個會將內容加入目前文件或元素的 writer。呼叫 `close()` 才會提交內容。

```php
$target = new \Light\Xml\Linq\XDocument();
$writer = $target->createWriter();
$document->writeTo($writer);
$writer->close();
```

## 儲存到 TextWriter

`XDocument::save()` 和 `XElement::save()` 亦接受可寫的 PHP stream 和
`Light\Xml\XmlWriter`。Stream 從目前位置寫入，呼叫端負責關閉。
傳入 `XmlWriter` 時沿用 `writeTo()`，由呼叫端完成或關閉 writer。

安裝選用套件 `mathsgod/light-io` 後，`XDocument::save()` 和
`XElement::save()` 可接受 `Light\IO\TextWriter`。Writer 會收到與檔案輸出相同的
XML 字串。

```php
$writer = new \Light\IO\StringWriter();
$document->save($writer, formatOutput: true);
$xml = $writer->toString();
```

## 保留空白與控制縮排

`XDocument` 和 `XElement` 的 `parse()` 和 `load()` 預設使用
`LoadOptions::PreserveWhitespace`，保留輸入的空白節點。
可傳入 `options: LoadOptions::None` 讓 DOM 移除純縮排空白。

`toXml()` 和 `save()` 可傳入 `options: SaveOptions::DisableFormatting`，
即使同時傳入 `formatOutput: true`，序列化也不會加入縮排。
當 `save()` 接收 `XmlWriter` 時，由 writer 控制其輸出設定。

## 解析與查詢

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

從檔案載入時使用 `XDocument::load('users.xml')`。空字串、不合法 XML 或不可讀檔案會拋出 `InvalidArgumentException`。

`XDocument::load()` 和 `XElement::load()` 亦接受可讀的 PHP stream，或選用套件
`mathsgod/light-io` 提供的 `Light\IO\TextReader`。兩者都由目前位置讀到結尾，
再解析完整 XML，呼叫端負責管理輸入來源。

傳入 `Light\Xml\XmlReader` 時，reader 可尚未開始讀取，或已定位在頂層節點。
`load()` 會讀取剩餘的頂層內容至 EOF。原生 reader 的節點序列不包含 XML 宣告，
因此這種載入方式不會保留原始宣告。

| 方法 | 用途 |
| --- | --- |
| `XDocument::root()` | 根元素 |
| `XDocument::descendants($name)` | 匹配的元素，包括匹配的根元素 |
| `XElement::element($name)` | 第一個匹配的直接子元素，沒有時為 null |
| `XElement::elements($name = null)` | 直接子元素序列 |
| `XElement::descendants($name = null)` | 後代元素序列，不包括自身 |
| `XElement::attribute($name)` | 匹配 attribute，沒有時為 null |
| `XElement::attributes($name = null)` | attribute 序列 |
| `XElement::value()` | 文字內容，包括後代文字 |
| `XElement::name()` | 元素的 `XName` |
| `XDocument::toXml($formatOutput = false)` | 序列化 XML 字串 |

序列方法回傳 lazy `IEnumerable`，可接 `where()`、`select()`、`groupBy()` 等。XML 文件本身在 parse／load 時已載入 DOM，並非串流 XML parser。

## XML 宣告

```php
use Light\Xml\Linq\{XDeclaration, XDocument, XElement};

$document = new XDocument(
    new XDeclaration('1.0', 'UTF-8', 'yes'),
    new XElement('users'),
);
$encoding = $document->declaration()?->encoding(); // 'UTF-8'
$document->setDeclaration(null); // 輸出時省略 XML 宣告
```

`parse()` 和 `load()` 會讀取原有宣告。沒有宣告時，`declaration()` 回傳
`null`。省略的 `encoding` 或 `standalone` 欄位也會回傳 `null`。
明確設定宣告後，`toXml()` 和 `save()` 會按宣告的編碼輸出。
未呼叫 `setDeclaration()` 且未傳入宣告的文件沿用原有 DOM 序列化行為。

## XML 註解

```php
use Light\Xml\Linq\{XComment, XDocument, XElement};

$document = new XDocument(
    new XComment('匯出資料'),
    new XElement('users', new XComment('待更新')),
    new XComment('匯出完成'),
);
$document->root()->comments()->single()->setValue('已更新');
```

`XDocument` 和 `XElement` 的建構式與 `add()` 接受 `XComment`，
`XElement::replaceAll()` 亦可加入註解。`comments()` 按文件順序列出直接子註解，
包括從 XML 讀取的註解。可用 `value()` 讀取內容、`setValue()` 修改，
或用 `remove()` 從文件移除。加入註解時會複製節點，透過 `comments()` 取出的註解則可直接編輯原樹。

## Namespace、XNamespace 與 XName

同一個 URI 要用於多個名稱時，可以用 `XNamespace::get()` 取得 namespace，
再用 `getName()` 建立可用於新增及查詢的 `XName`。

```php
use Light\Xml\Linq\{XElement, XNamespace};

$people = XNamespace::get('urn:people');
$root = new XElement($people->getName('users'),
    new XElement($people->getName('user'), 'Ada'),
);
$user = $root->element($people->getName('user'));
```

`none()`、`xml()` 和 `xmlns()` 分別回傳空 namespace 和 XML 的兩個保留
namespace。`get()` 以 URI 重用 namespace 物件，`getName()` 以本地名稱重用
該 namespace 產生的 `XName`。

```php
use Light\Xml\Linq\XName;

$namespaced = XDocument::parse(
    '<users xmlns="urn:people"><user><name>Ada</name></user></users>'
);
$people = $namespaced->descendants('{urn:people}user')->toArray();
$name = $people[0]->element(new XName('name', 'urn:people'))?->value(); // 'Ada'
```

使用 `{namespace-uri}localName` 或 `new XName($localName, $namespaceUri)`。單純 `'user'` 只匹配沒有 namespace 的元素，不能以 XML prefix 代替 namespace URI。預設 namespace 內的元素亦須指定 URI。

建立、修改及儲存 XML 的完整範例請參考[套件說明](../README.md)。目前仍是 Microsoft LINQ to XML 的功能子集。


可用以下方法查詢元素所在範圍內的 namespace 綁定。

| 方法 | 回傳值 |
| --- | --- |
| `getDefaultNamespace()` | 預設 namespace，沒有時回傳 `XNamespace::none()` |
| `getNamespaceOfPrefix($prefix)` | prefix 對應的 `XNamespace`，未綁定時回傳 `null` |
| `getPrefixOfNamespace($namespace)` | 其中一個已綁定的非空 prefix，沒有時回傳 `null` |

查詢會考慮繼承及覆蓋的綁定。查詢預設 namespace 時使用
`getDefaultNamespace()`，因為 `getNamespaceOfPrefix('')` 會拋出 `InvalidArgumentException`。

## 元素導覽與順序

| 方法 | 用途 |
| --- | --- |
| `ancestors($name = null)` | 從父元素開始，由近至遠列出祖先 |
| `ancestorsAndSelf($name = null)` | 從目前元素開始，由近至遠列出祖先 |
| `descendantsAndSelf($name = null)` | 從目前元素開始，按文件順序列出後代 |
| `elementsBeforeSelf($name = null)` | 按文件順序列出目前元素之前的同層元素 |
| `elementsAfterSelf($name = null)` | 按文件順序列出目前元素之後的同層元素 |

以上查詢延遲執行，可傳入 `XName` 或名稱字串篩選。
`isBefore($other)` 和 `isAfter($other)` 比較同一棵樹上的節點，所有 XNode 子類別都可使用。
比較同一節點時兩者都回傳 `false`，傳入 `null` 時只有 `isAfter()` 回傳
`true`。比較不同樹上的節點會拋出 `LogicException`。

## 變更事件

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

`onChanging()` 在修改前觸發，`onChanged()` 在成功修改後觸發。
兩者會從受影響物件向祖先傳遞，並回傳取消訂閱的 callback。
事件種類包括 `Add`、`Remove` 和 `Value`。XML 宣告的設定獨立於節點事件。
批量操作通常按節點發出事件。將含 DTD 的完整文件寫入空白文件時，會發出一次文件層級的 `Add`。
監聽器可以讀取 XML。若 `Changing` 監聽器拋出例外，會中止該次修改。
若批量操作已完成部分修改，或 `Changed` 監聽器拋出例外，已完成的修改會保留。

## Annotation

```php
$user->addAnnotation(new RuntimeException('缺少 email'));
$firstIssue = $user->annotation(Throwable::class);
$issues = $user->annotations(Throwable::class)->toArray();
$user->removeAnnotations(Throwable::class);
```

Annotation 可附加任何物件，並按類別或介面查詢。同一節點經不同查詢取得時
會共用 annotation。複製節點或輸出 XML 時，annotation 留在原節點上。

## XObject、XNode 與 XContainer

`XObject` 提供共用的 annotation API。`XAttribute` 直接繼承自 `XObject`，
而元素、文件、文字、CDATA、註解等節點繼承自 `XNode`。
`XNode` 提供祖先及同層查詢，也提供 `addBeforeSelf()`、`addAfterSelf()`、
`replaceWith()` 和 `remove()`。文件本身位於樹頂，祖先及同層查詢會回傳空序列。

`XObject` 提供 `nodeType()`（PHP 的 `XML_*` 類型常數）、`baseUri()`（沿用
DOM 的 URI 解析）、`parent()` 和 `document()`。根元素的 `parent()` 回傳
`null`，可用 `document()` 取得所屬文件。未接入文件的節點，其 `document()`
回傳 `null`。`XNode` 的 `nextNode()` 和 `previousNode()` 回傳相鄰的同層節點。

`XDocument` 和 `XElement` 都繼承自 `XContainer`，而 `XContainer` 繼承自
`XNode`。可用 `XContainer` 作為型別，讓同一段程式查詢或修改文件與元素。
`firstNode()` 和 `lastNode()` 回傳首尾子節點，容器沒有子節點時回傳 `null`。
文件與元素各自驗證可加入的內容，兩者都可使用 `XNode` 的 annotation 方法。

## 節點查詢

| 方法 | 用途 |
| --- | --- |
| `nodes()` | 直接子節點 |
| `descendantNodes()` | 所有後代節點 |
| `descendantNodesAndSelf()` | 目前元素及所有後代節點 |
| `nodesBeforeSelf()` | 目前元素之前的同層節點 |
| `nodesAfterSelf()` | 目前元素之後的同層節點 |

查詢會延遲執行，並按文件順序列出節點。元素、註解、文字和 CDATA 分別以
`XElement`、`XComment`、`XText` 和 `XCData` 回傳。處理指令等其他節點以
`XContentNode` 回傳，可用 `nodeType()`、`nodeName()`、`value()` 和 `toXml()` 讀取。
屬性可透過 `attributes()` 查詢。

`XDocument::nodes()` 會按文件順序列出頂層節點，包括根元素、註解、處理指令及
文件類型。`XDocument::descendantNodes()` 亦會列出根元素內的後代節點。
`addFirst()` 在現有節點前插入內容，`replaceNodes()` 會先讀取並驗證輸入，
因此可以直接傳入同一文件的 `nodes()`。XML 宣告仍可透過 `declaration()` 讀取。
從另一份文件插入文件類型時，DOM 文件需要重建，原有節點 wrapper 會指向已分離的節點。

### 文件類型

```php
use Light\Xml\Linq\{XDocument, XDocumentType, XElement};

$doc = new XDocument(
    new XDocumentType('report', systemId: 'report.dtd'),
    new XElement('report'),
);
$systemId = $doc->documentType()?->systemId();
```

`documentType()` 回傳 `XDocumentType` 或 `null`。亦可在 `nodes()` 查詢中取得
該節點，並用 `name()`、`publicId()`、`systemId()` 和 `internalSubset()` 讀取欄位。
同一文件最多有一個 `DOCTYPE`，位置須在根元素之前。

### 文字與 CDATA

```php
use Light\Xml\Linq\{XCData, XElement, XText};

$paragraph = new XElement('p', new XText('Hello '), new XElement('b', 'Ada'), new XCData('a < b'));
$paragraph->nodes()->first()->setValue('Hi ');
```

`XText` 和 `XCData` 可傳入元素建構式、`add()` 或 `replaceNodes()`。
加入時會複製節點。透過查詢取得的節點可用 `setValue()` 編輯或用 `remove()` 移除。

## 插入與替換元素

| 方法 | 用途 |
| --- | --- |
| `addFirst(...$content)` | 在首個子節點前插入內容 |
| `addBeforeSelf(...$content)` | 在目前元素前插入同層節點 |
| `addAfterSelf(...$content)` | 在目前元素後插入同層節點 |
| `replaceWith(...$content)` | 以新節點替換目前元素，空內容會移除元素 |

內容可包含元素、註解、純量文字、巢狀 iterable 或 `null`。插入的節點會被複製，
內容會先完整列舉及驗證。文件層級只接受元素及註解，且最多保留一個根元素。
同層插入及替換要求目前元素已連接到父元素或文件。

## 移除與替換 attributes 和子節點

| 方法 | 用途 |
| --- | --- |
| `removeAll()` | 移除 attributes 與子節點 |
| `removeAttributes()` | 只移除 attributes |
| `replaceAttributes(...$content)` | 只替換 attributes，接受 `XAttribute`、巢狀 iterable 及 `null` |
| `replaceNodes(...$content)` | 只替換子節點，可直接傳入 `nodes()` 的查詢結果 |

替換方法會在修改元素前列舉並驗證內容。傳入空內容會清空對應項目。
DOM 管理的 namespace 宣告與 `attributes()` 所列出的 attributes 分開處理。

## replaceAll：替換全部 attributes 與子節點

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

`XElement::replaceAll(mixed ...$content): void` 保留元素名稱、元素本身及其 parent，
取代 attributes 和全部子節點（包括文字、comment、CDATA 及 processing instruction）。
其他指向該元素的 wrapper 會看到更新。與 `removeNodes()` 不同，它亦移除原有 attributes。

內容規則與 `add()` 相同，接受 XElement、XAttribute、scalar、巢狀 iterable 及 null。
純量以文字加入並作 XML escaping；bool 轉為 `true`／`false`。
`replaceAll()`、`replaceAll(null)` 或空 iterable 均會清空元素內容及 attributes。

方法先完整列舉並複製新內容，再修改原樹，因此可以直接傳入自身的
`elements()`／`attributes()` 延遲查詢或單次 Generator。
新內容有重複 attribute、無效型別，或輸入列舉拋出例外時，目標保持不變；
使用者 Generator／callback 自行修改目標的副作用則不會回滾。
已存在的元素一律深層複製，不會從原樹搬走。已刪除子節點的舊 wrapper 仍代表舊節點。

namespace 的 URI／local name 會保留，但 DOM 可能保留 namespace 宣告，或使用不同 prefix；
不保證與 .NET 輸出字串逐字相同。本實作沒有 .NET 的 Changing／Changed 事件。
