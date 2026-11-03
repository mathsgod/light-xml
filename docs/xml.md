# LINQ to XML

[套件說明](../README.md) · [Enumerable](../../light-linq/docs/enumerable.md)

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
