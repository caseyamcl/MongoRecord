<?php

/**
 * BaseMongoRecord
 */
abstract class BaseMongoRecord implements MongoRecord
{
  /**
   * @var int Mongo Record ID
   */
  protected $_id;

  /**
   * @var array Errors
   */
  protected $errors;

  /**
   * @var boolean Is New Record
   */
  private $new;

  /**
   * @var Mongo Database object (from PECL)
   */
  public static $database = null;

  /**
   * @var MongoDb Database Connection object (from PECL)
   */
  public static $connection = null;

  /**
   * @var int Timeout for finding records
   */
  public static $findTimeout = 20000;

  /**
   * Collection name will be generated automaticaly if setted to null.
   * If overridden in child class, then new collection name uses.
   *
   * @var string
   */
  protected static $collectionName = null;

  // --------------------------------------------------------------

  public function __construct($attributes = array(), $new = true)
  {
    $this->new = $new;
    $this->errors = array();

    foreach($attributes as $k => $v) {
      $this->__set($k, $v);
    }

    if ($new) {
      $this->afterNew();
    }
  }

  // --------------------------------------------------------------

  public function validate()
  {
    $this->beforeValidation();
    $retval = $this->isValid();
    $this->afterValidation();
    return $retval;
  }

  // --------------------------------------------------------------

  public function save(array $options = array())
  {
    if (!$this->validate())
      return false;

    $this->beforeSave();

    $collection = self::getCollection();
    $collection->save($this->getAttributes(), $options);

    $this->new = false;
    $this->afterSave();

    return true;
  }

  // --------------------------------------------------------------

  public function destroy()
  {
    $this->beforeDestroy();

    if (!$this->new)
    {
      $collection = self::getCollection();
      $collection->remove(array('_id' => $this->_id));
    }
  }

  // --------------------------------------------------------------

  public function getAttributes($as_obj = FALSE, $includeID = FALSE)
  {
    $arr = get_object_vars($this);
    unset($arr['errors'], $arr['new']);

    if ($includeID) {
      $arr['_id'] = (string) $this->getID();
    }
    else {
      unset($arr['_id']);
    }

    return ($as_obj) ? (object) $arr : $arr;
  }

  // --------------------------------------------------------------

  public function getID()
  {
    return $this->_id;
  }

  // --------------------------------------------------------------

  public function setID($id)
  {
    $this->_id = $id;
  }

  // --------------------------------------------------------------

  public function __set($name, $val) {

    if ('_id' == $name) {
      $this->setID($val);
    }
    elseif (in_array($name, array_keys($this->getAttributes()))) {
      $this->$name = $val;
    }
    else {
      throw new \Exception("The attribute $name does not exist in the " . get_class($this) . " Entity!");
    }
  }

  // --------------------------------------------------------------

  public function __get($name) {

    if ('_id' == $name) {
      return $this->getID();
    }
    elseif (isset($this->getAttributes(TRUE)->$name)) {
      return $this->getAttributes(TRUE)->$name;
    }
    else {
      return NULL;
    }
  }

  // --------------------------------------------------------------

  public static function getAttributeNames($as_obj = FALSE, $includeID = FALSE) {
    $arr = get_class_vars(get_called_class());
    unset($arr['errors'], $arr['new']);

    if ( ! $includeID) {
      unset($arr['_id']);
    }

    $arr = array_keys($arr);
    return ($as_obj) ? (object) $arr :$arr;
  }
  // --------------------------------------------------------------

  public static function find($query = array(), $options = array())
  {
    $collection = self::getCollection();
    $documents = $collection->find($query);
    $className = get_called_class();

    if (isset($options['sort']))
      $documents->sort($options['sort']);

    if (isset($options['offset']))
      $documents->skip($options['offset']);

    if (isset($options['limit']))
      $documents->limit($options['limit']);

    $documents->timeout($className::$findTimeout);

    return new MongoRecordIterator($documents, $className);
  }

  // --------------------------------------------------------------

  public static function findOne($query = array(), $options = array())
  {
    $options['limit'] = 1;

    $results = self::find($query, $options);

    if ($results)
      return $results->current();
    else
      return null;
  }

  // --------------------------------------------------------------

  public static function count($query = array())
  {
    $collection = self::getCollection();
    $documents = $collection->count($query);

    return $documents;
  }

  // --------------------------------------------------------------

  public static function findAll($query = array(), $options = array())
  {
    $collection = self::getCollection();
    $documents = $collection->find($query);
    $className = get_called_class();

    if (isset($options['sort']))
      $documents->sort($options['sort']);

    if (isset($options['offset']))
      $documents->skip($options['offset']);

    if (isset($options['limit']))
      $documents->limit($options['limit']);

    $ret = array();

    $documents->timeout($className::$findTimeout);

    while ($documents->hasNext())
    {
      $document = $documents->getNext();
      $ret[] = self::instantiate($document);
    }

    return $ret;
  }

  // --------------------------------------------------------------

  // framework overrides/callbacks:
  public function beforeSave() {}
  public function afterSave() {}
  public function beforeValidation() {}
  public function afterValidation() {}
  public function beforeDestroy() {}
  public function afterNew() {}

  // --------------------------------------------------------------

  protected function isValid()
  {
    $className = get_called_class();
    $methods = get_class_methods($className);

    foreach ($methods as $method)
    {
      if (substr($method, 0, 9) == 'validates')
      {
        $propertyCall = 'get' . substr($method, 9);
        if (!$className::$method($this->$propertyCall()))
        {
          return false;
        }
      }
    }

    return true;
  }

  // --------------------------------------------------------------

  private static function instantiate($document)
  {
    if ($document)
    {
      $className = get_called_class();
      return new $className($document, false);
    }
    else
    {
      return null;
    }
  }

  // --------------------------------------------------------------

  // core conventions
  protected static function getCollection()
  {
    $className = get_called_class();

    if (null !== static::$collectionName)
    {
      $collectionName = static::$collectionName;
    }
    else
    {
      $collectionName = $className;
      if (strpos($collectionName, '\\')) {
        $collectionName = explode('\\', $collectionName);
        $collectionName = array_pop($collectionName);
      }
      $inflector = Inflector::getInstance();
      $collectionName = $inflector->tableize($collectionName);
    }

    if ($className::$database == null)
      throw new Exception("BaseMongoRecord::database must be initialized to a proper database string");

    if ($className::$connection == null)
      throw new Exception("BaseMongoRecord::connection must be initialized to a valid Mongo object");

    if (!($className::$connection->connected))
      $className::$connection->connect();

    return $className::$connection->selectCollection($className::$database, $collectionName);
  }

  // --------------------------------------------------------------

  public static function setFindTimeout($timeout)
  {
    $className = get_called_class();
    $className::$findTimeout = $timeout;
  }

  // --------------------------------------------------------------

  public static function ensureIndex(array $keys, array $options = array())
  {
    return self::getCollection()->ensureIndex($keys, $options);
  }

  // --------------------------------------------------------------

  public static function deleteIndex($keys)
  {
    return self::getCollection()->deleteIndex($keys);
  }

}

/* EOF: BaseMongoRecord.php */