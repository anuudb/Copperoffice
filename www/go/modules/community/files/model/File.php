<?php
namespace go\modules\community\files\model;

class File extends Node {
	
	public $metaData;
	public $mimeType;
	public $byteSize;
	public $blobId;
	public $versions = [];
	
	protected static function defineMapping() {
		return parent::defineMapping()
				  ->addRelation('versions', Version::class, ['id' => 'fileId'], true);
	}
	
	
}