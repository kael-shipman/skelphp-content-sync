<?php
namespace Skel;

class ContentFile extends DataClass implements Interfaces\ContentFile {
  const TABLE_NAME = 'contentFiles';

  protected $db;

  public function __construct(array $e=array(), Interfaces\Template $t=null) {
    parent::__construct($e, $t);
    $this->addDefinedFields(array('path', 'mtime', 'contentId'));
  }








  protected function convertDataToField(string $field, $dataVal) {
    if ($dataVal === null) return $dataVal;
    if ($field == 'mtime') return new \DateTime('@'.$dataVal);
    return parent::convertDataToField($field, $dataVal);
  }

  public function setDb(Interfaces\ContentSyncDb $db) {
    $this->db = $db;
    return $this;
  }

  protected function typecheckAndConvertInput(string $field, $val) {
    if ($val === null || $val instanceof DataCollection) return $val;

    if ($field == 'mtime') {
      if (!($val instanceof \DateTime)) throw new \InvalidArgumentException("Field `$field` must be a DateTime object.");
      return $val->getTimestamp();
    }
    if ($field == 'contentId') {
      if (!is_numeric($val)) throw new \InvalidArgumentException("Field `$field` must be a numeric ID.");
      return (int)$val;
    }
    if ($field == 'path') {
      if (!is_string($val) && !($val instanceof Interfaces\Uri)) throw new \InvalidArgumentException("Field `$field` must either be a string path or an instance of `\Skel\Interfaces\Uri`.");
      if (is_string($val)) return $val;
      else return $val->getPath();
    }
    return parent::typecheckAndConvertInput($field, $val);
  }

  protected function validateField(string $field) {
    $val = $this->get($field);
    $required = array(
      'path' => "You must specify a path for this file",
      'mtime' => "You must indicate when the file was last modified",
      'contentId' => "You must associate a contentId with this file",
    );

    if (array_key_exists($field, $required) && ($val === null || $val === '')) $this->setError($field, $required[$field], 'required');
    else $this->clearError($field, 'required');
  }

  public function validateObject(Interfaces\Db $db) {
    if (!$db->filePathIsUnique($this)) {
      $this->setError('path', "The file path associated with this file is already registered in the database. This shouldn't happen. You may need to rebuild your database (i.e., delete the db file and allow the system to recreate it).", 'uniqueness');
    } else {
      $this->clearError('path','uniqueness');
    }

    if (!$db->fileContentIdIsUnique($this)) {
      $this->setError('contentId', "The contentId associated with this file is already registered with another file in the Db. This shouldn't happen. You may need to rebuild your database (i.e., delete the db file and allow the system to recreate it).", 'uniqueness');
    } else {
      $this->clearError('contentId','uniqueness');
    }
  }
}


?>
