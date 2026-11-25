# LINQ to XML

[套件說明](../README.md) · [Enumerable](https://github.com/mathsgod/light-linq/blob/v1.0.0/docs/enumerable.md)

XML API 以 DOM 為基礎，需要 `ext-dom`。先載入專案的 Composer autoloader。

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

## Namespace 與 XName

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


## 元素導覽與順序

| 方法 | 用途 |
| --- | --- |
| `ancestors($name = null)` | 從父元素開始，由近至遠列出祖先 |
| `ancestorsAndSelf($name = null)` | 從目前元素開始，由近至遠列出祖先 |
| `descendantsAndSelf($name = null)` | 從目前元素開始，按文件順序列出後代 |
| `elementsBeforeSelf($name = null)` | 按文件順序列出目前元素之前的同層元素 |
| `elementsAfterSelf($name = null)` | 按文件順序列出目前元素之後的同層元素 |

以上查詢延遲執行，可傳入 `XName` 或名稱字串篩選。
`isBefore($other)` 和 `isAfter($other)` 比較同一棵樹上的元素或註解。
比較同一節點時兩者都回傳 `false`，傳入 `null` 時只有 `isAfter()` 回傳
`true`。比較不同樹上的節點會拋出 `LogicException`。

## 節點查詢

| 方法 | 用途 |
| --- | --- |
| `nodes()` | 直接子節點 |
| `descendantNodes()` | 所有後代節點 |
| `descendantNodesAndSelf()` | 目前元素及所有後代節點 |
| `nodesBeforeSelf()` | 目前元素之前的同層節點 |
| `nodesAfterSelf()` | 目前元素之後的同層節點 |

查詢會延遲執行，並按文件順序列出節點。元素和註解分別以 `XElement` 和
`XComment` 回傳。文字、CDATA、處理指令等節點以 `XContentNode` 回傳，
可用 `nodeType()`、`nodeName()`、`value()` 和 `toXml()` 讀取。
屬性可透過 `attributes()` 查詢。

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
