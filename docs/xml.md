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
