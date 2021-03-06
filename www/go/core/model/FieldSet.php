<?php

namespace go\core\model;

use go\core\acl\model\AclOwnerEntity;
use go\core\orm\Query;

/**
 * FieldSet entity
 * 
 * Find for entity type
 * ```
 * $fieldsets = \go\core\model\FieldSet::find()->filter(['entities' => ['Event']]);
 * ```
 */
class FieldSet extends AclOwnerEntity {
/**
	 * The ID
	 * 
	 * @var int
	 */
	public $id;

	public $name;
	
	public $description;
	
	protected $entityId;
	
	public $sortOrder;
	
	protected $entity;
	
	protected $filter;	
	
	/**
	 * The filter is an object that can be used to show and hide field sets based
	 * on the entity values.
	 * 
	 * For example a contact fieldset may have:
	 * 
	 * filter = {
	 *    addressBookId: [1, 2],
	 *    isOrganization: true
	 * }
	 * 
	 * Will only show this fieldset for addressBookId 1 and 2 and for organizations.
	 * 
	 * @return array
	 */
	public function getFilter() {
		return empty($this->filter) || $this->filter == '[]'  ? new \stdClass() : json_decode($this->filter, true);
	}
	
	public function setFilter($filter) {
		$this->filter = json_encode($filter);
	}
	
	protected static function defineMapping() {
		return parent::defineMapping()
						->addTable('core_customfields_field_set', 'fs')
						->setQuery((new Query())->select("e.name AS entity")->join('core_entity', 'e', 'e.id = fs.entityId'));						
	}
	
	public function getEntity() {
		return $this->entity;
	}
	
	public function setEntity($name) {
		$this->entity = $name;
		$e = \go\core\orm\EntityType::findByName($name);
		$this->entityId = $e->getId();
	}
	
	protected static function defineFilters() {
		return parent::defineFilters()
						->add('entities', function(\go\core\db\Criteria $criteria, $value) {
							//$ids = \go\core\orm\EntityType::namesToIds($value);			
							$criteria->andWhere('e.name', 'IN', $value);
						});
	}
	
	protected function internalDelete() {
		
		foreach(Field::find()->where(['fieldSetId' => $this->id]) as $field) {
			if(!$field->delete()) {
				return false;
			}
		}
		
		return parent::internalDelete();
	}
	
	protected function internalSave() {
		if(!parent::internalSave()) {
			return false;
		}
		
		return !$this->isNew() || $this->findAcl()->addGroup(\go\core\model\Group::ID_EVERYONE, \go\core\acl\model\Acl::LEVEL_WRITE)->save();
		
	}

}
