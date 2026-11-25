# XDocument

[XML 指南](xml.md) · [XElement](XElement.md)

`Light\Xml\Linq\XDocument` 代表整份 XML 文件，包括根元素、宣告，以及根元素前後的註解和處理指令。文件也可以包含一個位於根元素之前的 `DOCTYPE`。

## 建立文件

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

`new XDocument()` 建立空文件，可以稍後用 `add()` 加入根元素。建構式接受前置的 `XDeclaration`。複製文件會複製 XML 樹，annotation 和事件訂閱留在原節點。

## 載入 XML

| 入口 | 輸入與行為 |
| --- | --- |
| `parse($xml, options: ...)` | 解析 XML 內容字串。 |
| `load($path, options: ...)` | 從可讀的本機檔案路徑載入。 |
| `load($stream, options: ...)` | 從可讀 PHP stream 的目前位置讀到 EOF。 |
| `load($reader, options: ...)` | 從選用的 `Light\IO\TextReader` 讀取剩餘內容。 |
| `load($reader, options: ...)` | 從 `Light\Xml\XmlReader` 讀取剩餘頂層節點至 EOF。可傳入尚未讀取或已定位於頂層的 reader。 |

~~~php
use Light\Xml\Linq\{LoadOptions, XDocument};

$doc = XDocument::load('inventory.xml', options: LoadOptions::None);
$inline = XDocument::parse('<inventory><item>Notebook</item></inventory>');
~~~

預設 `LoadOptions::PreserveWhitespace` 保留空白節點。`LoadOptions::None` 讓 DOM 移除可辨識的縮排空白。呼叫者負責關閉輸入 stream 或 reader。透過 `XmlReader` 載入時，原始 XML 宣告會省略。

## 讀取文件結構

| 方法 | 回傳 |
| --- | --- |
| `root()` | 根元素或 `null`。 |
| `declaration()` | XML 宣告或 `null`。 |
| `documentType()` | `XDocumentType` 或 `null`。 |
| `firstNode()`、`lastNode()` | 頂層首尾節點或 `null`。 |
| `element($name)`、`elements($name = null)` | 符合名稱的根元素，或根元素序列。 |
| `descendants($name = null)` | 按文件順序列出符合條件的元素，涵蓋根元素。 |
| `nodes()`、`descendantNodes()` | 分別列出頂層節點和所有後代節點。 |
| `comments()` | 頂層註解序列。 |

序列以延遲執行的 `IEnumerable` 回傳。查詢得到的節點可以直接修改原樹。宣告由 `declaration()` 取得，`nodes()` 會列出 `DOCTYPE`。

## 修改文件

- `add(...$content)` 在末尾加入節點，`addFirst(...$content)` 在開頭加入。
- `replaceNodes(...$content)` 先讀取並驗證輸入，再替換所有頂層節點。可傳入同一文件的 `nodes()`。
- `removeNodes()` 清空頂層節點。要修改宣告，使用 `setDeclaration($declaration)`。傳入 `null` 可省略輸出宣告。

內容可含元素、註解、處理指令、文件類型、巢狀 iterable 或 `null`。文件最多有一個根元素及一個文件類型。加入節點會複製內容。匯入另一份文件的 `DOCTYPE` 時會重建底層 DOM，已取得的舊節點物件會指向分離的節點。

## 序列化與儲存

~~~php
use Light\Xml\Linq\SaveOptions;

$xml = $doc->toXml(formatOutput: true);
$doc->save('inventory.xml', formatOutput: true);
$doc->save($stream, options: SaveOptions::DisableFormatting);
~~~

`toXml()` 和字串轉換會回傳 XML。`save()` 要求文件有根元素，接受檔案路徑、可寫 PHP stream、選用的 `Light\IO\TextWriter`，或 `Light\Xml\XmlWriter`。Stream 會從目前位置寫入。傳入 `XmlWriter` 時會使用 `writeTo()`，由 writer 控制輸出。各種 stream 和 writer 都由呼叫者關閉。

`formatOutput: true` 啟用 DOM 縮排。`SaveOptions::DisableFormatting` 可停用該縮排，現有空白文字仍會輸出。

### XML Reader 與 Writer

`createReader()` 會從目前序列化結果建立完整快照。它需要 `ext-xmlreader`。`createWriter()` 和 `writeTo()` 需要使用由 `ext-xmlwriter` 支援的 `Light\Xml\XmlWriter`。

~~~php
use Light\Xml\Linq\XDocument;

$target = new XDocument();
$writer = $target->createWriter();
$doc->writeTo($writer);
$writer->close(); // 提交已緩衝的內容
~~~

變更事件與 annotation 用法見 [XML 指南](xml.md)。
