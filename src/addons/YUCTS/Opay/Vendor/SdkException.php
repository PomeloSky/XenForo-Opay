<?php

namespace YUCTS\Opay\Vendor;

/**
 * 對應 OPay SDK 相關錯誤的例外類別。
 * 與 \XF\PrintableException 不同：本例外面向程式 (API/CheckMac) 錯誤，
 * 應由呼叫端視情況轉為對用戶的訊息或寫入交易記錄。
 */
class SdkException extends \Exception
{
}
