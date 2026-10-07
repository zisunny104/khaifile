# 授權盤點

文件核對日期：2026-10-08（依儲存庫授權檔及本機套件告知核對，非重新驗證所有上游來源）。以下區分本儲存庫散布的資產與另外安裝的伺服器工具。

| 元件 | 已確認授權 | 本專案處理方式 |
| --- | --- | --- |
| KhaiFile PHP／JavaScript／CSS | MIT | 根目錄 LICENSE 保留版權與完整條文 |
| Tocas UI 5.7.0 | MIT | 固定 upstream commit；保留 vendor/tocas/LICENSE |
| Tocas 內嵌 Floating UI | MIT | upstream source 註記 1.2.2；保留 FLOATING-UI-LICENSE.txt |
| Tocas 內嵌 flag-icons 樣式 | MIT | 保留 FLAG-ICONS-LICENSE.txt；未散布旗幟 SVG |
| Font Awesome Free 6.7.2 | 字型 SIL OFL 1.1；CSS／程式碼 MIT | 由 TTF metadata 確認版本，保留該版本完整授權檔 |
| LibreOffice | MPL 2.0，加上各內嵌第三方條款 | 獨立 CLI 安裝，未複製到本儲存庫 |
| Ghostscript 10.05.1（驗證機套件） | AGPL-3.0-or-later | 獨立 CLI 安裝，未複製到本儲存庫 |
| Bubblewrap | LGPL-2.1-or-later | 由系統套件安裝，未複製到本儲存庫 |
| util-linux（prlimit） | 依套件各元件授權 | 由系統套件安裝，未複製到本儲存庫 |
| Noto CJK（建議字型） | SIL OFL 1.1 | 由系統套件安裝；本儲存庫不散布 |

## 本儲存庫的散布

MIT 元件須保留版權與授權告知；不要因壓縮 JavaScript／CSS 而把相應授權檔
從發布內容移除。Tocas 的 MIT 授權不取代其內嵌元件的原始條款，本儲存庫
已保留 Floating UI、flag-icons 與 Font Awesome 的告知。

Font Awesome 圖示字型允許使用與嵌入，散布仍須保留 OFL 條款，不能把字型
本身單獨出售。修改字型時還須遵守 Reserved Font Name 等規定；此處未修改
字型。本專案未散布 Font Awesome SVG 圖示；上游完整授權檔亦記載 SVG
圖示的 CC BY 4.0 條款，新增 SVG 資產時需重新盤點。

## 伺服器依賴

**Ghostscript 不是 MIT 元件。** 驗證機的 Debian 套件明列
AGPL-3.0-or-later；Artifex 另提供商業授權。KhaiFile 以獨立 subprocess
呼叫系統安裝的 `gs`，未修改 Ghostscript，也未把它的程式碼或二進位加入
儲存庫。這種部署方式不代表可忽略其授權。

若之後製作包含 Ghostscript 的容器、安裝包或 appliance，需保留授權告知，
並按條款提供對應來源與相關材料。若修改 AGPL 程式且讓使用者透過網路與
該修改版本互動，還須處理 AGPL 第 13 節的對應來源提供義務。商業授權的
範圍以實際取得的合約為準，不能只把整個安裝包標成 MIT。

LibreOffice 的實際安裝 LICENSE 以 MPL 2.0 為主，並包含其他函式庫、字型
與 artwork 的條款。散布或修改它時應遵循安裝版本的完整 LICENSE；本專案
沒有修改或散布 LibreOffice。

系統字型也需逐套確認。Noto CJK 的 OFL 允許字型嵌入；不能假設 Microsoft
Office 隨附字型或其他商用字型都允許搬到伺服器或隨套件散布。

上傳文件及其轉換結果的內容仍依原始文件授權，不會因使用 MIT 工具而變成
MIT。使用者應保有發布文件與其中圖片、字型等內容的權利。

## 檢查來源

- [Tocas UI 5.7.0 原始授權](https://github.com/teacat/tocas/blob/5.7.0/LICENSE)
- [Tocas 內嵌 Floating UI 原始檔](https://github.com/teacat/tocas/blob/5.7.0/src/scripts/tocas.floating-ui.js)
- [Floating UI 1.2.2 npm 套件](https://www.npmjs.com/package/@floating-ui/dom/v/1.2.2)：下載時已驗證 registry 的 SRI 摘要
- [flag-icons 原始授權](https://github.com/lipis/flag-icons/blob/main/LICENSE)
- [Font Awesome 6.7.2 完整授權](https://github.com/FortAwesome/Font-Awesome/blob/6.7.2/LICENSE.txt)
- [LibreOffice 授權說明](https://www.libreoffice.org/about-us/licenses/)
- [Ghostscript 授權說明](https://www.ghostscript.com/licensing/)
- [GNU AGPL 第 13 節](https://www.gnu.org/licenses/agpl-3.0.html#section13)
- 本機 `/usr/share/doc/bubblewrap/copyright`、`/usr/share/doc/util-linux/copyright`
- 本驗證機的 LibreOffice `LICENSE`、`/usr/share/doc/ghostscript/copyright`、`/usr/share/doc/fonts-noto-cjk/copyright`
