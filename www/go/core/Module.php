<?php

namespace go\core;

use Exception;
use go\core\db\Utils;
use go\core\Environment;
use go\core\exception\NotFound;
use go\core\fs\File;
use go\core\fs\Folder;
use go\core\model;
use go\core\orm\Entity;
use go\core\util\ClassFinder;
use function GO;

/**
 * Base module class
 * 
 * Handles:
 * 
 * 1. Installation and uninstall of the module
 * 2. Registering Event listeners
 * 3. You can implement custom download methods prefixed with "download". For 
 *    example method go\modules\community\addressbook\Module::downloadVcard($contactId) 
 *    can be accessed with: "download.php?blob=community/addressbook/vcard/1"
 * 
 * @copyright (c) 2014, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
abstract class Module {
	
	/**
	 * Find module class file by name
	 * 
	 * @param string $moduleName
	 * @return self
	 */
	public static function findByName($moduleName) {
		$classFinder = new ClassFinder(false);
		$classFinder->addNamespace("go\\modules");
		$mods = $classFinder->findByParent(self::class);
		
		foreach($mods as $mod) {
			if($mod::getName() == $moduleName) {
				return new $mod;
			}
		}
		
		return false;
	}
	
	/**
	 * Install the module
	 * 
	 * @return model\Module;
	 */
	public final function install() {

		try{
			$this->installDatabase();
		} catch(Exception $e) {
			$this->uninstallDatabase();
			throw $e;
		}
		
		GO()->rebuildCache(true);
		GO()->getDbConnection()->beginTransaction();
		
		$model = new model\Module();
		$model->name = static::getName();
		$model->package = static::getPackage();
		$model->version = $this->getUpdateCount();
		
		if(!$model->save()) {
			$this->rollBack();
			return false;
		}
		
		if(!$this->registerEntities()) {
			$this->rollBack();
			return false;
		}
		
		if(!$this->afterInstall($model)) {
			$this->rollBack();
			return false;
		}		
		
		if(!GO()->getDbConnection()->commit()) {
			return false;
		}		
		
		return $model;
	}
	
	private function rollBack() {
		GO()->getDbConnection()->rollBack();
		$this->uninstallDatabase();
	}	
	
	/**
	 * Uninstall the module
	 * 
	 * @return bool
	 * @throws NotFound
	 */
	public function uninstall() {
		
		if(!$this->beforeUninstall()) {
			return false;
		}
		
		if(!$this->uninstallDatabase()) {
			return false;
		}
		
		$model = model\Module::find()->where(['name' => static::getName()])->single();
		if(!$model) {
			throw new NotFound();
		}
		
		if(!$model->delete()) {
			return false;
		}
		
		GO()->rebuildCache(true);
		
		return true;
	}
	
	
	/**
	 * Registers all entity in the core_entity table. This happens after the 
	 * core_module entry has been inserted.
	 * 
	 * De-registration is not necessary when the module is uninstalled because they 
	 * will be deleted by Mysql because of a cascading relation.
	 */
	public function registerEntities() {
		$entities = $this->getClassFinder()->findByParent(Entity::class);
		
		foreach($entities as $entity) {
			if(!$entity::getType()) {
				return false;
			}
		}		
		
		return true;
	}
	
	/**
	 * Installs the database for the module. This happens before the core_module entry has been inserted.
	 * @return boolean
	 */
	private function installDatabase() {
		$sqlFile = $this->getFolder()->getFile('install/install.sql');
		
		if ($sqlFile->exists()) {
			$queries = Utils::getSqlQueries($sqlFile);
			
			foreach ($queries as $query) {
				GO()->getDbConnection()->query($query);
			}
		}
				
		return true;
	}
	
	/**
	 * This will delete the module's database tables
	 * 
	 * @return boolean
	 */
	private function uninstallDatabase() {
		$sqlFile = $this->getFolder()->getFile('install/uninstall.sql');
		
		if ($sqlFile->exists()) {
			$queries = Utils::getSqlQueries($sqlFile);

			//disable foreign keys
			array_unshift($queries, "SET FOREIGN_KEY_CHECKS=0;");
			array_push($queries, "SET FOREIGN_KEY_CHECKS=1;");
			
			foreach ($queries as $query) {
				GO()->getDbConnection()->query($query);
			}
		}
		
		return true;
	}
	
	/**
	 * Override to implement installation routines after the database has been 
	 * created. Share the module with group "Internal" for example.
	 * 
	 * @return bool
	 */
	protected function afterInstall(model\Module $model) {
		return true;
	}
	
	/**
	 * Override to implement uninstallation routines before the database will be destroyed.
	 * @return bool
	 */
	protected function beforeUninstall() {
		return true;
	}
	
	/**
	 * Get a class finder instance that only searches this module
	 * 
	 * @return ClassFinder
	 */
	public function getClassFinder() {
		$classFinder = new ClassFinder(false);
		$classFinder->addNamespace(substr(static::class, 0, strrpos(static::class, "\\")));
		
		return $classFinder;
	}
	
	/**
	 * Get the updates.php file
	 * 
	 * @return File
	 */
	public function getUpdatesFile() {
		return $this->getFolder()->getFile('install/updates.php');
	}
	
	/**
	 * Counts the number of queries in the updates file
	 * 
	 * @return int
	 */
	public function getUpdateCount() {
		$updateFile = $this->getUpdatesFile();
		
		$count = 0;
		if($updateFile->exists()) {
			require($updateFile->getPath());
			
			if(isset($updates)){
				foreach($updates as $timestamp=>$queries)
					$count+=count($queries);
			}
		}
		
		return $count;			
	}

	/**
	 * Override to attach listeners
	 */
	public function defineListeners() {		
	}

	/**
	 * Get the author
	 * 
	 * @return string eg. "Intermesh BV <info@intermesh.nl>";
	 */
	abstract function getAuthor();

	/**
	 * Get dependent modules.
	 * 
	 * @return string[] eg. ["community/notes"]
	 */
	public function getDependencies() {
		return [];
	}

	/**
	 * get conflicting modules.
	 * 
	 * @return string[] eg. ["community/notes"]
	 */
	public function getConflicts() {
		return [];
	}

	/**
	 * 
	 * @deprecated
	 * @return type
	 */
	public function path() {
		return $this->getPath() . '/';
	}

	/**
	 * Get the filesystem path to the module
	 * 
	 * @return string
	 */
	public static function getPath() {
		
		//todo use reflection
		//
		//$reflector = new ReflectionClass('Foo');
		//	echo $reflector->getFileName();
		return Environment::get()->getInstallFolder() . '/' . dirname(str_replace('\\', '/', static::class));
	}
	
	/**
	 * Get the folder of this module
	 * 
	 * @return Folder
	 */
	public static function getFolder() {
		return new Folder(static::getPath());
	}
	
	/**
	 * 
	 * Get the name of this module
	 * 
	 * @return type
	 */
	public static function getName() {
		$parts = explode("\\", static::class);
		
		return $parts[3];
	}
	
	/**
	 * // backwards compatible 6.2
	 * 
	 * @deprecated since version number
	 * @return type
	 */
	public static function name() {
		return self::getName();
	}
	
	/**
	 * Get package name 
	 * 
	 * The package is a group of modules that belong to each other. It is used 
	 * to group modules per type or per customer.
	 * 
	 * @return string
	 */
	public static function getPackage() {
		$parts = explode("\\", static::class);		
		return $parts[2];
	}
	
	/**
	 * Get localized module title
	 * 
	 * @return string
	 */
	public static function getTitle() {
		
		$pkg = static::getPackage();
		$name = static::getName();
		
		if(!GO()->getLanguage()->translationExists("name", $pkg, $name)) {
			return $name;
		}
		
		return GO()->t("name", $pkg, $name);
	
	}
	
	
	/**
	 * Get localized module description
	 * 
	 * @return string
	 */
	public static function getDescription() {
		
		$pkg = static::getPackage();
		$name = static::getName();
		
		if(!GO()->getLanguage()->translationExists("name", $pkg, $name)) {
			return "No description";
		}
		
		return GO()->t("description", static::getPackage(), static::getName());		
	
	}
	
	/**
	 * Get icon URI
	 * 
	 * @return string
	 */
	public static function getIcon() {
		$icon = static::getFolder()->getFile('icon.png');
		
		if(!$icon->exists()) {
			$icon = Environment::get()->getInstallFolder()->getFile('views/Extjs3/themes/Paper/img/default-avatar.svg');
		}
		
		return 'data:'.$icon->getContentType().';base64,'. base64_encode($icon->getContents());
	}
	
	/**
	 * Get the module settings
	 * 
	 * A module must override this function and implement a \go\core\Settings object
	 * to store settings.
	 * 
	 * @return \go\core\Settings
	 */
	public function getSettings() {
		return null;
	}

}
