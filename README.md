# XenForo-Opay — OPay / 綠界 金流整合套件

XenForo 2.3 的 **OPay 歐付寶**（與 **ECPay 綠界科技** 共用同一 AIO API）官方金流整合套件，提供完整的「使用者升級 / 商品付款」流程，並在後台提供訂單管理、手動成立 / 取消 / 退款，以及即時向 OPay 查詢訂單狀態等管理功能。

- 套件 ID：`YUCTS/Opay`
- 適用版本：XenForo 2.3.0 以上
- 開發者：[YUCTS](https://forum.yucts.com)
- 授權：MIT

---

## 目錄
1. [功能特色](#功能特色)
2. [支援的付款方式](#支援的付款方式)
3. [系統需求](#系統需求)
4. [安裝步驟](#安裝步驟)
5. [後台設定](#後台設定)
6. [後台訂單管理](#後台訂單管理)
7. [付款流程說明](#付款流程說明)
8. [檔案結構](#檔案結構)
9. [安全性說明](#安全性說明)
10. [常見問題](#常見問題)
11. [疑難排解](#疑難排解)
12. [版本歷史](#版本歷史)
13. [回報問題與支援](#回報問題與支援)
14. [授權](#授權)

---

## 功能特色

- 完全依照 [XenForo 官方 Payment Provider 規範](https://xenforo.com/docs/dev/payment-providers/) 撰寫，繼承 `XF\Payment\AbstractProvider`，可直接套用至：
  - **使用者升級（User Upgrade）**
  - **任何實作 `XF\Purchasable\AbstractPurchasable` 的第三方付費商品**
- 同時支援 **OPay 歐付寶** 與 **ECPay 綠界科技**（同一介面，僅 API 主機不同）。
- 後台「OPay 訂單管理」介面，可：
  - 依訂單編號、會員、狀態進行搜尋
  - 檢視單筆訂單詳情、原始回傳資料、相關交易記錄
  - **手動成立訂單**（觸發 XenForo 的升級開通或商品交付流程）
  - **手動取消訂單**
  - **向 OPay 申請退刷 / 退款**（信用卡走 `DoAction(R)`、ATM/CVS 走 `AioChargeback`）
  - **即時向 OPay 查詢訂單狀態**（`QueryTradeInfo`）
- 完整的 CheckMacValue 雙向驗證（SHA256，採 `hash_equals` 常數時間比對）。
- 自訂資料表 `xf_yucts_opay_transaction`，與 XenForo 內建 `xf_payment_provider_log` 互相對應。
- 內建每日 04:15 排程，自動清理過期的 `pending` / `failed` / `cancelled` 紀錄，`paid` 與 `refunded` 永久保留以利對帳。
- 全繁體中文介面與用語。

---

## 支援的付款方式

| 付款方式 | OPay 代號 | 後台手動退款 |
| --- | --- | --- |
| 信用卡 | `Credit` | 退刷（`DoAction(R)`） |
| 網路 ATM | `WebATM` | 廠商通知退款（`AioChargeback`） |
| ATM 虛擬帳號 | `ATM` | 廠商通知退款（`AioChargeback`） |
| 超商代碼 | `CVS` | 廠商通知退款（`AioChargeback`） |

> 註：本版本不支援信用卡定期定額（Recurring）。若您僅以 XenForo 內建「使用者升級」做單次或可重複續購之升級，本套件已可完整支援。

---

## 系統需求

- XenForo 2.3.0 以上
- PHP 7.4 以上（建議 8.0+）
- 必要 PHP 擴充：`curl`, `mbstring`, `hash`, `json`
- 一組有效的 OPay 或綠界 ECPay 特店帳號（含 `MerchantID` / `HashKey` / `HashIV`）

---

## 安裝步驟

### 方式一：以 ZIP 安裝

1. 下載本倉庫的 ZIP（或 Release 包）。
2. 將壓縮包中的 `src/` 目錄解壓上傳至您 XenForo 站點的根目錄，會自動合併至既有的 `src/addons/` 結構。最終路徑必須為：
   ```
   <您的 XF 根目錄>/src/addons/YUCTS/Opay/addon.json
   ```
3. 進入 XenForo 後台：**附加元件 → 安裝可用的附加元件**，找到「OPay 歐付寶 (台灣金流)」並按下安裝。

### 方式二：以 Git 部署

```bash
cd /path/to/your-xenforo/src/addons
mkdir -p YUCTS
cd YUCTS
git clone https://github.com/PomeloSky/XenForo-Opay.git Opay
# 注意：此倉庫 src/addons/YUCTS/Opay 才是 addon 本體
```

或更乾淨的作法：

```bash
git clone https://github.com/PomeloSky/XenForo-Opay.git /tmp/xf-opay
cp -r /tmp/xf-opay/src/addons/YUCTS /path/to/your-xenforo/src/addons/
```

---

## 後台設定

### 1. 申請或登入 OPay / 綠界後台
取得三個關鍵憑證：
- 特店編號 `MerchantID`
- `HashKey`
- `HashIV`

> **測試環境**請使用 OPay / 綠界提供的測試專用憑證；上線前請務必切換為正式環境並填入正式憑證。

### 2. 建立付款設定檔
進入 **後台 → 設定 → 付款方式 → 新增付款設定檔**，選擇「**OPay 歐付寶 (台灣金流)**」，依序填寫：

| 設定 | 說明 |
| --- | --- |
| 顯示標題 | 例如「信用卡 / ATM / 超商付款」 |
| 特店編號 (MerchantID) | OPay/綠界提供 |
| HashKey / HashIV | OPay/綠界提供 |
| 服務商 | OPay 或 ECPay（決定 API 主機） |
| 環境 | 上線前請以 Stage 測試環境完成端對端驗證 |
| 允許的付款方式 | 至少勾選 1 項，僅勾 1 項時將直接導向該付款頁 |
| ATM / CVS 繳費期限 | 依您的對帳習慣調整 |

### 3. 將付款設定檔指派給使用者升級
**後台 → 使用者 → 使用者升級**，在升級項目中勾選剛才建立的 OPay 付款設定檔。

### 4. （可選）調整全域選項
**後台 → 設定 → 選項 → OPay 歐付寶**：
- **交易記錄保留天數**：超過此天數的 `pending` / `failed` / `cancelled` 紀錄會在每日 04:15 自動清除（最少 7 天）。
- **啟用除錯模式**：將完整回傳資料寫入 `internal_data/data_log_`（僅供上線前測試）。

---

## 後台訂單管理

進入 **後台 → OPay 歐付寶 → 訂單管理**。

| 動作 | 說明 |
| --- | --- |
| 訂單列表 | 可依「商店訂單編號」、「會員」、「狀態」搜尋並分頁。 |
| 檢視訂單 | 顯示完整訂單欄位、最近一次 OPay 回傳資料、與該訂單相關的 XF 付款日誌。 |
| 編輯 | 可寫入後台備註（不影響 OPay 訂單本身）。 |
| 手動成立訂單 | 將狀態強制設為「已付款」，並透過 XF 標準流程觸發升級開通 / 商品交付。**只能對 `pending` 或 `failed` 狀態執行**。 |
| 手動取消訂單 | 將狀態改為「已取消」，並寫入交易記錄。**只能對 `pending` 或 `failed` 狀態執行**。 |
| 申請退款 | 對信用卡呼叫 `DoAction(Action=R)`、對 ATM/CVS 呼叫 `AioChargeback`。 |
| 查詢 OPay | 即時打 `QueryTradeInfo`，回傳結果會寫入訂單 `extra_info.last_query`。 |

所有手動操作都會：
1. 同步寫入 `xf_payment_provider_log`（與 `xf_yucts_opay_transaction.extra_info`）
2. 帶上「執行者使用者名稱」與「備註」欄位
3. 受 `yuctsOpayManage` 管理員權限保護

---

## 付款流程說明

```
        ┌────────────┐                ┌───────────────┐
        │  XF User   │                │   OPay AIO    │
        └─────┬──────┘                └───────┬───────┘
              │  1. 點擊升級                  │
              │ ───────────────┐              │
              │                ▼              │
        ┌─────┴──────────────────┐            │
        │  payment.php (XF)      │            │
        │  → YUCTS\Opay\Payment  │            │
        │      ::initiatePayment │            │
        └─────┬──────────────────┘            │
              │  2. 寫入 xf_yucts_opay_transaction
              │     (pending)                 │
              │                               │
              │  3. 回傳 auto-submit 表單     │
              │  (CheckMacValue=SHA256)       │
              │ ─────────────────────────────►│
              │                               │
              │  4. 使用者完成付款            │
              │                               │
              │  5. ReturnURL (server-to-     │
              │     server) → payment_callback.php
              │ ◄─────────────────────────────│
              │                               │
        ┌─────┴────────────────────┐          │
        │ payment_callback.php     │          │
        │  → setupCallback()       │          │
        │  → validateCallback()    │          │
        │       (CheckMacValue!)   │          │
        │  → validateCost()        │          │
        │  → getPaymentResult()    │          │
        │  → completeTransaction() │          │
        └─────┬────────────────────┘          │
              │                               │
              │  6. 回應 1|OK                 │
              │ ─────────────────────────────►│
              │                               │
              │  7. 觸發 XF 升級開通          │
              │     (User Upgrade Job)        │
              │                               │
              │  8. 使用者被導回 OrderResultURL
              │     (顯示「升級完成」頁面)    │
              ▼                               ▼
```

**安全要點**：
- 在第 5 步收到回傳時，第一件事就是 `CheckMacValue` 驗證（採 `hash_equals` 常數時間比對）。驗證失敗將直接以 HTTP 400 拒絕並寫入錯誤日誌。
- 金額另以 `validateCost()` 與 `xf_purchase_request.cost_amount` 比對，避免攻擊者以小額付款開通大額升級。
- `validateTransaction()` 確保同一筆 OPay TradeNo 不會被處理兩次。

---

## 檔案結構

```
src/addons/YUCTS/Opay/
├── addon.json
├── Setup.php                     # 安裝 / 解除安裝 / 升級
├── Admin/
│   └── Controller/
│       └── Order.php             # 後台訂單管理控制器
├── Cron/
│   └── Cleanup.php               # 每日清理舊記錄
├── Entity/
│   └── OpayTransaction.php       # ORM Entity
├── Finder/
│   └── OpayTransaction.php
├── Install/
│   └── Data/
│       └── MySql.php             # 資料表 schema 定義
├── Payment/
│   └── Opay.php                  # XF 付款服務提供者本體
├── Repository/
│   └── OpayTransaction.php
├── Vendor/
│   ├── Sdk.php                   # OPay AIO API 輕量封裝
│   └── SdkException.php
├── _data/
│   ├── admin_navigation.xml
│   ├── admin_permission.xml
│   ├── cron.xml
│   ├── option_groups.xml
│   ├── options.xml
│   ├── phrases.xml
│   ├── routes.xml
│   └── templates.xml
└── hashes.json                   # 套件健康檢查用 SHA-256 清單
```

倉庫根目錄另附 `build/generate-hashes.php`：當您修改任何 addon 內檔案後，需要在 commit 前重新產生 `hashes.json`，否則 XenForo 安裝畫面會跳出「此插件的 hashes.json 文件丟失」或「健康檢查未通過」的警告。

```bash
php build/generate-hashes.php
```

該腳本完全比照 XenForo 內建 `\XF\Service\AddOn\HashGeneratorService` 的演算法（SHA-256，計算前先移除 `\r`），輸出與官方一致。

### 資料表
- `xf_yucts_opay_transaction` — 本套件建立。
- `xf_payment_provider` — 由 `Setup::installStep2()` 註冊 `('opay', 'YUCTS\Opay:Opay', 'YUCTS/Opay')`。
- `xf_payment_provider_log` — XenForo 內建，本套件以 `provider_id = 'opay'` 寫入。

---

## 安全性說明

本套件設計時遵循以下原則：

1. **CheckMacValue 雙向驗證**：所有對 OPay 發出與從 OPay 接收的請求，都會以 SHA256 計算並驗證 `CheckMacValue`。回傳驗證採用 `hash_equals()` 進行常數時間比對，避免時序攻擊。
2. **金額驗證**：回呼處理時除 CheckMacValue 外，另以 `validateCost()` 將 `TradeAmt` 與 `xf_purchase_request.cost_amount` 做整數比對。
3. **防止重複處理**：以 XF 內建 `validateTransaction()` 機制（依 `xf_payment_provider_log` 之 `transaction_id` + `provider_id`）防止同一筆 OPay TradeNo 被處理兩次。
4. **管理介面權限**：所有後台動作（檢視、編輯、手動成立 / 取消 / 退款 / 查詢）皆強制 `assertAdminPermission('yuctsOpayManage')`。
5. **表單 CSRF**：所有 POST 動作均由 XF 的 `<xf:form>` 標籤自動帶上 CSRF token。
6. **HTTPS 強制**：對 OPay API 的 cURL 呼叫啟用 `CURLOPT_SSL_VERIFYPEER` 與 `CURLOPT_SSL_VERIFYHOST=2`。
7. **SQL 注入防護**：所有資料庫操作均透過 XF Entity / Finder / Schema Manager（參數化查詢）執行。
8. **敏感資料**：寫入交易日誌前會主動移除 `CheckMacValue` 等驗證字段。
9. **解除安裝清理**：移除套件時，會同步刪除所屬資料表與 `xf_payment_profile` 中以 `opay` 為 provider 的紀錄，避免後台殘留無效項目。

> **建議**：上線前請先於測試環境完整跑過一輪「下單 → ATM 取號 → 繳費 → 後台手動成立 → 退款」流程。

---

## 常見問題

**Q1. OPay 與 ECPay 綠界差別？**
A：兩者共用同一 AIO API（甚至參數名稱、CheckMacValue 算法、回傳格式都完全相同），只是 API 主機網址不同。本套件以單一付款設定即可切換，請依您實際申請的服務商選擇。

**Q2. 為什麼回呼後使用者沒有自動升級？**
A：請依序檢查：
1. 後台 → OPay 歐付寶 → 訂單管理，看該筆訂單目前狀態。若仍為 `pending`，代表 OPay 的 ReturnURL 沒有打回您的站點，請確認 ReturnURL 可從外網存取且為 HTTPS。
2. 後台 → OPay 歐付寶 → 交易記錄，是否有錯誤訊息（特別注意「CheckMacValue verify fail」）。常見原因為 HashKey/HashIV 填錯，或正式 / 測試環境憑證錯置。
3. 若 OPay 端已顯示付款成功但站點未開通，可使用後台的「查詢 OPay 訂單狀態」按鈕。

**Q3. 可以對 ATM/CVS 退款嗎？**
A：可以，但走的是「廠商通知 OPay 退款」(`AioChargeback`)，款項自您的 OPay 餘額退回，不會自動回到買家帳戶——需您另行匯款給買家。信用卡退刷則由 OPay 直接退回原卡。

**Q4. 為何不支援定期定額？**
A：XenForo 內建 User Upgrade 已可由使用者重複續購，且 OPay 定期定額有金額固定的限制，與 XF Recurring 規格契合度低，因此本版本暫不實作。如有需求歡迎於 GitHub 發 Issue 討論。

---

## 疑難排解

### 安裝後付款選單看不到 OPay
- 確認 `src/addons/YUCTS/Opay/addon.json` 存在且未損毀。
- 後台「附加元件」中本套件狀態須為 **已啟用**。
- 嘗試後台 → 工具 → 重建 → 重建主要快取。

### 「OPay 付款服務商未正確安裝」
- 進入後台「附加元件」，對本套件按下「升級」即會重新註冊 `xf_payment_provider`。

### Callback 回傳 400 / CheckMacValue verify fail
- 檢查 `HashKey` / `HashIV` 是否與目前環境（Stage / Production）相符。
- 檢查您的 web server 是否在中間有對請求做任何 URL Rewrite / Body 改寫（如 mod_security 移除 `+`）。

---

## 版本歷史

### 1.0.0（2026-05-25）
- 首版發布。
- 支援 OPay / ECPay 雙服務商。
- 支援信用卡 / WebATM / ATM / CVS 四種付款方式。
- 完整後台訂單管理（檢視、編輯備註、手動成立、手動取消、退款、查詢）。
- 每日排程清理過期記錄。

---

## 回報問題與支援

- 主要支援平台：[YUCTS 論壇 https://forum.yucts.com](https://forum.yucts.com)
- 程式問題請於 [GitHub Issues](https://github.com/PomeloSky/XenForo-Opay/issues) 提出。
- 提報問題時請盡可能提供：
  - XenForo 版本
  - PHP 版本
  - 本套件版本
  - 是否為 OPay / ECPay、Stage / Production
  - 後台 → OPay → 交易記錄 中的對應錯誤訊息（請先去除 `MerchantID` / `HashKey` / `HashIV` 等敏感欄位）

---

## 授權

本專案以 [MIT License](LICENSE) 授權釋出。

OPay AIO API 的歸屬及商標權屬於[歐付寶第三方支付股份有限公司](https://www.opay.tw/)。ECPay 綠界 AIO API 的歸屬及商標權屬於[綠界科技股份有限公司](https://www.ecpay.com.tw/)。本套件僅為與其官方 API 介接之第三方整合工具，與兩家公司無從屬關係。
