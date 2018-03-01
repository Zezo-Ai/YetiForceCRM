<?php

/**
 * Wdiget to show work time.
 *
 * @copyright YetiForce Sp. z o.o
 * @license YetiForce Public License 3.0 (licenses/LicenseEN.txt or yetiforce.com)
 * @author Tomasz Kur <t.kur@yetiforce.com>
 */
class OSSTimeControl_AllTimeControl_Dashboard extends Vtiger_IndexAjax_View
{
	public function getSearchParams($assignedto, $dateStart, $dateEnd)
	{
		$conditions = [];
		$listSearchParams = [];
		if ($assignedto != '') {
			array_push($conditions, ['assigned_user_id', 'e', $assignedto]);
		}
		if (!empty($dateStart) && !empty($dateEnd)) {
			array_push($conditions, ['due_date', 'bw', $dateStart . ',' . $dateEnd . '']);
		}
		$listSearchParams[] = $conditions;
		return '&search_params=' . json_encode($listSearchParams) . '&viewname=All';
	}

	public function getWidgetTimeControl($user, $time)
	{
		if (!$time) {
			return [];
		}
		$timeDatabase['start'] = DateTimeField::convertToDBFormat($time['start']);
		$timeDatabase['end'] = DateTimeField::convertToDBFormat($time['end']);
		$currentUser = Users_Record_Model::getCurrentUserModel();
		if ($user == 'all') {
			$user = array_keys(\App\Fields\Owner::getInstance(false, $currentUser)->getAccessibleUsers());
		}
		if (!is_array($user)) {
			$user = [$user];
		}
		$colors = \App\Fields\Picklist::getColors('timecontrol_type');
		$moduleName = 'OSSTimeControl';
		$query = (new App\Db\Query())->select(['sum_time', 'due_date', 'vtiger_osstimecontrol.timecontrol_type', 'vtiger_crmentity.smownerid', 'timecontrol_typeid'])
			->from('vtiger_osstimecontrol')
			->innerJoin('vtiger_crmentity', 'vtiger_osstimecontrol.osstimecontrolid = vtiger_crmentity.crmid')
			->innerJoin('vtiger_timecontrol_type', 'vtiger_osstimecontrol.timecontrol_type = vtiger_timecontrol_type.timecontrol_type')
			->where(['vtiger_crmentity.setype' => $moduleName, 'vtiger_crmentity.smownerid' => $user]);
		\App\PrivilegeQuery::getConditions($query, $moduleName);
		$query->andWhere([
			'and',
			['>=', 'vtiger_osstimecontrol.due_date', $timeDatabase['start']],
			['<=', 'vtiger_osstimecontrol.due_date', $timeDatabase['end']],
			['vtiger_osstimecontrol.deleted' => 0],
		]);
		$timeTypes = [];
		$smOwners = [];
		$dataReader = $query->createCommand()->query();
		$chartData = [
			'labels' => [],
			'fullLabels' => [],
			'datasets' => [],
			'show_chart' => false,
			'names' => [] // names for link generation
		];
		while ($row = $dataReader->read()) {
			$label = \App\Language::translate($row['timecontrol_type'], 'OSSTimeControl');
			$workingTimeByType[$label] += (float) $row['sum_time'];
			$workingTime[$row['smownerid']][$row['timecontrol_type']] += (float) $row['sum_time'];
			if (!in_array($row['timecontrol_type'], $timeTypes)) {
				$timeTypes[$row['timecontrol_typeid']] = $row['timecontrol_type'];
				// one dataset per type
				$chartData['datasets'][] = [
					'label' => $label,
					'original_label' => $label,
					'_type' => $row['timecontrol_type'],
					'data' => [],
					'backgroundColor' => [],
					'links' => [],
				];
			}
			if (!in_array($row['smownerid'], $smOwners)) {
				$smOwners[] = $row['smownerid'];
				$ownerName = \App\Fields\Owner::getUserLabel($row['smownerid']);
				$chartData['labels'][] = vtlib\Functions::getInitials($ownerName);
				$chartData['fullLabels'][] = $ownerName;
			}
		}
		foreach ($chartData['datasets'] as &$dataset) {
			$dataset['label'] .= ': ' . \CurrencyField::convertToUserFormat($workingTimeByType[$dataset['label']]);
		}
		if ($dataReader->count() > 0) {
			foreach ($workingTime as $ownerId => $timeValue) {
				foreach ($timeTypes as $timeTypeId => $timeType) {
					// if owner has this kind of type
					if ($timeValue[$timeType]) {
						$userTime = $timeValue[$timeType];
					} else {
						$userTime = 0;
					}
					foreach ($chartData['datasets'] as &$dataset) {
						if ($dataset['_type'] === $timeType) {
							// each data item is an different owner time in this dataset/time type
							$dataset['data'][] = round($userTime, 2);
							$dataset['backgroundColor'][] = $colors[$timeTypeId];
							$chartData['show_chart'] = true;
						}
					}
				}
			}
			foreach ($smOwners as $ownerId) {
				foreach ($chartData['datasets'] as &$dataset) {
					$dataset['links'][] = 'index.php?module=OSSTimeControl&view=List&viewname=All' . $this->getSearchParams($ownerId, $time['start'], $time['end']);
				}
			}
		}
		$dataReader->close();
		return $chartData;
	}

	public function process(\App\Request $request)
	{
		$currentUserId = \App\User::getCurrentUserId();
		$viewer = $this->getViewer($request);
		$moduleName = $request->getModule();
		$user = $request->getByType('owner', 2);
		$time = $request->getDateRange('time');
		$widget = Vtiger_Widget_Model::getInstance($request->getInteger('linkid'), $currentUserId);
		if (empty($time)) {
			$time = Settings_WidgetsManagement_Module_Model::getDefaultDate($widget);
			if ($time === false) {
				$time['start'] = App\Fields\Date::formatToDisplay('now');
				$time['end'] = App\Fields\Date::formatToDisplay('now');
			} else {
				$time['start'] = \App\Fields\Date::formatToDisplay($time['start']);
				$time['end'] = \App\Fields\Date::formatToDisplay($time['end']);
			}
		}
		if (empty($user)) {
			$user = Settings_WidgetsManagement_Module_Model::getDefaultUserId($widget);
		}
		$viewer->assign('TCPMODULE_MODEL', Settings_TimeControlProcesses_Module_Model::getCleanInstance()->getConfigInstance());
		$viewer->assign('USERID', $user);
		$viewer->assign('DTIME', $time);
		$viewer->assign('DATA', $this->getWidgetTimeControl($user, $time));
		$viewer->assign('WIDGET', $widget);
		$viewer->assign('MODULE_NAME', $moduleName);
		$viewer->assign('LOGGEDUSERID', $currentUserId);
		$viewer->assign('ACCESSIBLE_USERS', \App\Fields\Owner::getInstance($moduleName, $currentUserId)->getAccessibleUsersForModule());
		$viewer->assign('ACCESSIBLE_GROUPS', \App\Fields\Owner::getInstance($moduleName, $currentUserId)->getAccessibleGroupForModule());
		if ($request->has('content')) {
			$viewer->view('dashboards/TimeControlContents.tpl', $moduleName);
		} else {
			$viewer->view('dashboards/AllTimeControl.tpl', $moduleName);
		}
	}
}
