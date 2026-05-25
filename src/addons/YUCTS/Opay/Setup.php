<?php

namespace YUCTS\Opay;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use YUCTS\Opay\Install\Data\MySql;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	// =============================================================
	// 安裝
	// =============================================================

	public function installStep1(): void
	{
		$sm = $this->schemaManager();

		foreach (MySql::getTables() AS $tableName => $closure)
		{
			$sm->createTable($tableName, $closure);
		}
	}

	public function installStep2(): void
	{
		$this->db()->insert('xf_payment_provider', [
			'provider_id'    => 'opay',
			'provider_class' => 'YUCTS\\Opay:Opay',
			'addon_id'       => 'YUCTS/Opay',
		], false, 'provider_class = VALUES(provider_class), addon_id = VALUES(addon_id)');
	}

	// =============================================================
	// 升級 (預留)
	// =============================================================

	// 1.0.x 之間目前無 schema 變更，後續版本若有調整請於此新增 upgradeXxxxxxxStepN 方法。

	// =============================================================
	// 解除安裝
	// =============================================================

	public function uninstallStep1(): void
	{
		$sm = $this->schemaManager();

		foreach (MySql::getTables() AS $tableName => $closure)
		{
			$sm->dropTable($tableName);
		}
	}

	public function uninstallStep2(): void
	{
		$this->db()->delete('xf_payment_provider', 'provider_id = ?', 'opay');

		// 同步移除以本套件建立的所有付款設定檔，避免後台殘留無效項目。
		$this->db()->delete('xf_payment_profile', 'provider_id = ?', 'opay');
	}
}
