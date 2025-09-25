<?php
/***********************************************************************************
 * X2Engine Open Source Edition is a customer relationship management program developed by
 * X2 Engine, Inc. Copyright (C) 2011-2022 X2 Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 610121, Redwood City,
 * California 94061, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2 Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2 Engine".
 **********************************************************************************/






Yii::import('application.models.X2Model');
Yii::import('application.modules.user.models.*');

/**
 * This is the model class for table "x2_services".
 *
 * @package application.modules.services.models
 */
class Services extends X2Model
{

	public $account;

	public $verifyCode; // CAPTCHA for Service case form

	/**
	 * Returns the static model of the specified AR class.
	 * @return Services the static model class
	 */
	public static function model($className = __CLASS__)
	{
		return parent::model($className);
	}

	/**
	 * @return string the associated database table name
	 */
	public function tableName()
	{
		return 'x2_services';
	}

	public function behaviors()
	{
		return array_merge(parent::behaviors(), array(
			'LinkableBehavior' => array(
				'class' => 'LinkableBehavior',
				'module' => 'services',
				//		'icon'=>'accounts_icon.png',
			),
			'ERememberFiltersBehavior' => array(
				'class' => 'application.components.behaviors.ERememberFiltersBehavior',
				'defaults' => array(),
				'defaultStickOnClear' => false
			)
		));
	}

	public function rules()
	{
		$rules = array_merge(parent::rules(), array(
			array(
				'verifyCode',
				'captcha',
				'allowEmpty' => !CCaptcha::checkRequirements(),
				'on' => 'webFormWithCaptcha',
				'captchaAction' => 'site/webleadCaptcha'
			),
			array(
				'resolution',
				'validateResolution'
			),
			array('account', 'safe', 'on' => 'search'),
		));
		return $rules;
	}

	/**
	 * resolution can not be blank when status is "closed - resolved"
	 * Returns True or False
	 */
	public function validateResolution()
	{
		if (
			($this->status == "Closed - Resolved") && ((is_null($this->resolution))
				|| (ctype_space($this->resolution)))
		) {
			$this->addError('status', Yii::t('services', 'Resolution can not be blank when status is: Closed - Resolved'));
		}
	}

	public function afterFind()
	{
		if ($this->name != $this->id) {
			$this->name = $this->id;
			$this->update(array('name'));
		}
		return parent::afterFind();
	}

	/**
	 *
	 * @return boolean whether or not to save
	 */
	public function afterSave()
	{
		$model = $this->getOwner();

		$oldAttributes = $model->getOldAttributes();

		if ($model->escalatedTo != '' && (!isset($oldAttributes['escalatedTo']) || $model->escalatedTo != $oldAttributes['escalatedTo'])) {
			$event = new Events;
			$event->type = 'case_escalated';
			$event->user = $this->updatedBy;
			$event->associationType = 'Services';
			$event->associationId = $model->id;
			if ($event->save()) {
				$notif = new Notification;
				$notif->user = $model->escalatedTo;
				$notif->createDate = time();
				$notif->type = 'escalateCase';
				$notif->modelType = 'Services';
				$notif->modelId = $model->id;
				$notif->save();
			}
		}

		parent::afterSave();
	}

	public function search()
	{
		$criteria = new CDbCriteria;
		if (!empty($this->account)) {
			$criteria->join =
				'LEFT JOIN x2_contacts c ON c.nameId = t.contactId ' .
				'LEFT JOIN x2_accounts a ON a.nameId = c.company ';
			$criteria->compare('a.name', $this->account, true);
			echo "<pre>JOIN utilisé:\n";
			print_r($criteria->join);
			echo "\n</pre>";
		}
		return $this->searchBase($criteria);
	}


	public function relations()
	{
		return array_merge(parent::relations(), array(
			'contact' => array(self::BELONGS_TO, 'Contacts', 'contactId'),
		));
	}



	/**
	 *  Like search but filters by status based on the user's profile
	 *
	 */
	public function searchWithStatusFilter($pageSize = null, $uniqueId = null)
	{
		$criteria = new CDbCriteria;

		// Forcer le JOIN si filtre ou tri sur "account"
		$needAccountJoin = false;
		if (!empty($this->account))
			$needAccountJoin = true;
		if (isset($_GET['Services_sort']) && strpos($_GET['Services_sort'], 'account') !== false)
			$needAccountJoin = true;

		if ($needAccountJoin) {
			$criteria->join =
				'LEFT JOIN x2_contacts c ON c.nameId = t.contactId ' .
				'LEFT JOIN x2_accounts a ON a.nameId = c.company ';
			if (!empty($this->account)) {
				$criteria->compare('a.name', $this->account, true);
			}
		}

		// Filtrage des statuts à masquer selon le profil
		foreach ($this->getFields(true) as $fieldName => $field) {
			if ($fieldName == 'status') {
				$hideStatus = CJSON::decode(Yii::app()->params->profile->hideCasesWithStatus);
				if (!$hideStatus) {
					$hideStatus = array();
				}
				foreach ($hideStatus as $hide) {
					$criteria->compare('t.status', '<>' . $hide);
				}
			}
		}
		$criteria->together = true; // obligatoire pour JOIN custom

		// Tri personnalisé sur account (+ autres) pour SmartSort/DataProvider
		$sort = new SmartSort(
			get_class($this),
			isset($this->uid) ? $this->uid : get_class($this)
		);
		$sort->attributes = array_merge(
			array(
				'account' => array(
					'asc' => 'a.name ASC',
					'desc' => 'a.name DESC',
				),
			),
			$this->getSort()
		);
		$sort->defaultOrder = 't.lastUpdated DESC, t.id DESC';

		$dataProvider = new SmartActiveDataProvider(get_class($this), array(
			'sort' => $sort,
			'pagination' => array('pageSize' => $pageSize),
			'criteria' => $criteria,
			'uid' => $this->uid,
			'dbPersistentGridSettings' => $this->dbPersistentGridSettings,
			'disablePersistentGridSettings' => $this->disablePersistentGridSettings,
		));
		$sort->applyOrder($criteria);
		return $dataProvider;
	}



	public function getLastReply()
	{
		return $lastReply = Yii::app()->db->createCommand()
			->select('b.*')
			->from('x2_services a')
			->join('x2_service_replies b', 'a.id = b.serviceId')
			->where('a.id = :id')
			->order('b.createDate DESC')
			->queryRow(true, [':id' => $this->id]);
	}

	public function getReplies()
	{
		return $replies = Yii::app()->db->createCommand()
			->select('*')
			->from('x2_service_replies')
			->where('serviceId = :id')
			->order('createDate DESC')
			->queryAll(true, [':id' => $this->id]);
	}

}
