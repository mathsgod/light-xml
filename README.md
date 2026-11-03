# light-xml

PHP 8.3+ 的 LINQ 風格 XML 套件，參考 [.NET XDocument](https://learn.microsoft.com/zh-tw/dotnet/api/system.xml.linq.xdocument?view=net-10.0) 的核心用法。目前是功能子集，不是 .NET API 的完整移植。

## 環境與開發安裝

需要 PHP 8.3+、`ext-dom`。目前使用相鄰的 `../light-linq` 作為本機 Composer path dependency，以提供 `IEnumerable` 查詢。XML 實作、查詢測試及 fixture 已由 light-linq 移入本套件。

```sh
composer install
composer test
```

Namespace 為 `Light\Xml\Linq`，PSR-4 對應 `src/linq/`。本套件沿用 PHP camelCase 方法命名。

## 建立、修改、儲存 document

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

文字及 attribute 值會交由 DOM 正確 escape，不需要自行組合 XML 字串。bool 會序列化成 `true`／`false`。

## 解析及查詢

```php
$loaded = XDocument::load('users.xml');
$parsed = XDocument::parse('<root><item>one</item><item>two</item></root>');

$names = $loaded->descendants('user')
    ->where(fn ($user) => $user->attribute('active')?->value() === 'true')
    ->select(fn ($user) => $user->element('name')?->value())
    ->toArray(); // ['Alice']
```

`elements()` 只列舉直接子元素；`descendants()` 列舉所有後代元素。對 document 呼叫 descendants 會包括根元素；對 element 呼叫則不包括自身。查詢序列是 lazy `IEnumerable`，支援 light-linq 的篩選、投影、分組等操作。文件本身在 parse/load 時已完整載入記憶體。

## 以查詢結果建立另一份 document

```php
$transformed = new XDocument(
    new XElement('selected',
        $loaded->descendants('user')
            ->where(fn ($user) => $user->attribute('id')?->value() === '1'),
    ),
);
```

建構及 `add()` 接受元素、巢狀 iterable；element 亦接受 attribute、字串、數字及 bool。null 會略過。文件只接受根元素／其 iterable，最多一個根元素；文件頂層的文字及其他物件會被拒絕。

## 修改與複製規則

| 方法 | 行為 |
| --- | --- |
| `new XDocument()` | 空文件，`root()` 回傳 null |
| `new XDocument($otherDocument)` | 深層複製整份文件 |
| `new XElement($otherElement)` | 深層複製元素 |
| `add(...$content)` | 加入內容 |
| `setValue($value)` | 取代元素所有子內容；保留 attributes |
| `setElementValue($name, $value)` | 修改第一個匹配子元素，沒有則新增；null 表示移除 |
| `setAttributeValue($name, $value)` | 新增／修改 attribute；null 表示移除 |
| `removeNodes()` | 移除直接子節點，保留元素 attributes |
| `XElement::remove()` | 從父節點移除該元素 |
| `XElement::parent()` | 父元素，沒有則 null |
| `XAttribute::setValue()` / `remove()` | 修改／移除 attribute |

透過查詢取得的 wrapper 指向原文件節點，修改會反映到文件。將既有元素交給 constructor／`add()` 時則**一律深層複製**，不會將原節點搬走；這是本版本明確採用的規則，與 .NET 未附屬節點可能直接掛入新樹的行為不同。

重複加入同名 attribute 會拋出 `InvalidArgumentException`；要覆寫請用 `setAttributeValue()`。沒有 parent 的元素／attribute 呼叫 `remove()` 會拋出 `LogicException`。

## Namespace

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

名稱使用 `XName` 或 `{namespace-uri}localName`，不要用 prefix 當作 namespace URI。attribute namespace prefix 由函式庫產生；只用 local name 會匹配沒有 namespace 的節點。

## I/O 與錯誤

`XDocument`／`XElement` 都有 `parse()`、`load()`、`toXml()`、`save()`。load/save 目前只接受檔案路徑；`toXml()`／字串轉換不寫入檔案。文件序列化包含 XML declaration，元素的 `toXml()` 則只輸出該元素。

不合法 XML、不可讀的輸入檔案及不合法內容會拋出例外。空 document 可存在及序列化，但必須有根元素才能 `save()`；輸出失敗拋出 `RuntimeException`。格式化不會移除 parse 時已保留的空白文字節點。

## 與 .NET 的差距

目前已有核心建立、複製、讀取、查詢、修改及儲存流程。仍未實作 `XDeclaration`、`XComment` 等完整節點建構 API、事件、annotations、async、stream reader/writer、XSD 驗證、完整導覽與 replace 系列方法。解析後的 document-level comment／processing instruction 會保留並在儲存時輸出，但目前未提供相應的專用 wrapper。

## 從 light-linq XML 遷移

舊的 `Light\Linq\Xml` 類別已移除，請在應用程式加入本套件依賴，並將 imports 改為 `Light\Xml\Linq`。例如改為 `use Light\Xml\Linq\XDocument;`。

原有的 parse/load、root、elements/descendants、attribute/attributes、XName 及 toXml 用法仍適用。舊的查詢範例已搬到 [XML 查詢指南](docs/xml.md)。light-linq 保留集合查詢及 provider 功能；只有本套件需要 `ext-dom`，依賴方向為 light-xml → light-linq。
