<?php

/**
 * BaseMongoRecord
 */
abstract class BaseMongoRecord implements MongoRecord, Iterator
{
    /**
     * @var int Mongo Record ID
     */
    protected $__id = null;

    /**
     * @var array Errors
     */
    protected $__errors;

    /**
     * @var boolean Is New Record
     */
    private $__isNew;

    /**
     * @var int Iterator Position
     */
    private $__iteratorPosition = 0;

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

    /**
     * Constructor
     * 
     * @param array $attributes  Optionally preset some attributes
     * @param boolean $isNew       true for new record
     */
    public function __construct($attributes = array(), $isNew = true)
    {
        $this->__isNew = $isNew;
        $this->__errors = array();

        foreach($attributes as $k => $v) {

            if ('_id' == $k) {
                $this->__id = $v;
            }
            else {
                $this->__set($k, $v);
            }
        }

        if ($isNew) {
            $this->afterNew();
        }
    }

    // --------------------------------------------------------------

    /**
     * Magic Method provides access only to attributes
     */
    public function __set($name, $val)
    {
        if (in_array($name, array_keys($this->getAttributes()))) {
            $this->$name = $val;
        }
        else {
            throw new \Exception("The attribute $name does not exist in the " . get_class($this) . " Entity!");
        }
    }

    // --------------------------------------------------------------

    /**
     * Magic Method provides access only to attributes and ID
     */
    public function __get($name)
    {
        if ('_id' == $name) {
            return $this->getID();
        }
        elseif (isset($this->getAttributes(true)->$name)) {
            return $this->getAttributes(true)->$name;
        }
        else {
            return null;
        }
    }

    // --------------------------------------------------------------

    /**
     * Validate the attributes
     *
     * @return boolean
     */
    public function validate()
    {
        $this->beforeValidation();
        $retval = $this->isValid();
        $this->afterValidation();
        return $retval;
    }

    // --------------------------------------------------------------

    /**
     * Save the record to the database
     *
     * @param array $options
     * @return boolean
     */
    public function save(array $options = array())
    {
        if ( ! $this->validate()) {
            throw new MongoRecordValidationException("Validation failed!  Cannot save.");
        }

        $this->beforeSave();

        $attrs = $this->getAttributes();

        if ($this->__id) {
            $attrs['_id'] = $this->__id;
        }

        $collection = self::getCollection();
        $res = $collection->save($attrs, $options);

        $this->__id = $attrs['_id'];

        $this->__isNew = false;
        $this->afterSave();

        return true;
    }

    // --------------------------------------------------------------

    /**
     * Destroy the record in the database
     *
     * @return boolean|null
     */
    public function destroy()
    {
        $this->beforeDestroy();

        if ( ! $this->__isNew)
        {
            $collection = self::getCollection();
            return $collection->remove(array('_id' => $this->__id));
        }
        else {
            return null;
        }
    }

    // --------------------------------------------------------------

    /**
     * Get the ID
     *
     * Returns null if a new record
     *
     * @return null|string
     */
    public function getID()
    {
        return (string) $this->__id;
    }

    // --------------------------------------------------------------

    /**
     * Get the attributes
     *
     * @param boolean $asObj 
     * @param boolean $includeID
     * @return array|object
     */
    public function getAttributes($asObj = false, $includeID = false)
    {
        $arr = array();
        foreach(self::getAttributeNames($asObj, $includeID) as $attrName) {
            $arr[$attrName] = $this->$attrName;
        }

        if ($includeID) {
            $arr['_id'] = $this->getID();
        }
        else {
            unset($arr['_id']);
        }

        return ($asObj) ? (object) $arr : $arr;
    }

    // --------------------------------------------------------------

    /**
     * Get the names of the attributes
     *
     * @param boolean $asObj
     * @param boolean $includeID
     * @return array|object
     */
    public static function getAttributeNames($asObj = false, $includeID = false)
    {
        //Get an instance of the class
        $className = get_called_class();
        $that = new $className;
        $arr = array_keys(get_object_vars($that));

        //Filter any '_' items out
        $arr = array_filter($arr, function($v) {
            return (strcmp($v{0}, '_') !== 0);
        });

        return ($asObj) ? (object) $arr : $arr;
    }

    // --------------------------------------------------------------

    /*
     * ITERATOR METHODS
     */
    public function current() {
        $anames = $this->getAttributeNames(false, true);
        $anames[] = '_id';

        $attrs = $this->getAttributes(false, true);
        $attrs['_id'] = $this->getID();

        return $attrs[$anames[$this->__iteratorPosition]];
    }

    public function key() {
        $anames = $this->getAttributeNames(false, true);
        $anames[] = '_id';
        
        return $anames[$this->__iteratorPosition];
    }

    public function next() {
        ++$this->__iteratorPosition;
    }

    public function rewind() {
        $this->__iteratorPosition = 0;
    }

    public function valid() {
        $anames = $this->getAttributeNames(false, true);
        $anames[] = '_id';

        return isset($anames[$this->__iteratorPosition]);
    }


    // --------------------------------------------------------------

    /*
     * CUSTOM OVERRIDES / CALLBACKS
     */
    public function beforeSave() {}
    public function afterSave() {}
    public function beforeValidation() {}
    public function afterValidation() {}
    public function beforeDestroy() {}
    public function afterNew() {}


    // --------------------------------------------------------------

    /*
     * STATIC METHODS
     */

    /**
     * Find MongoDB Records
     *
     * This returns a MongoDB Iterator, which is useful for
     * dealing with large numbers of results, but it only stores
     * one record in memory at a time.
     *
     * @param array $query   See Mongo PECL documentation at php.net
     * @param array $options See Mongo PECL documentation at php.net
     * @return MongoRecordIterator 
     */
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

    /**
     * Find a single MongoDB Record
     *
     * @param array $query
     * @param array $options
     * @return object
     */
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

    /**
     * Find a record by its ID
     *
     * Shortcut function that just calls ::findOne() with MongoID($id)
     * See: http://php.net/manual/en/mongocollection.findone.php#example-1434
     *
     * @param string $id
     * @return object
     */
    public static function findByID($id)
    {
        $query = array('_id' => new MongoId($id));
        return self::findOne($query);
    }

    // --------------------------------------------------------------

    /**
     * Count records in a collection, or records from a query
     *
     * @param array $query
     * @return int
     */ 
    public static function count($query = array())
    {
        $collection = self::getCollection();
        $documents = $collection->count($query);

        return $documents;
    }

    // --------------------------------------------------------------

    /**
     * Find MongoDB Records and return them as an array
     *
     * This works like the self::find() method, but returns an array
     * of all of the records in memory, which is sometimes easier to
     * work with than an Iterator
     *
     * @param array $query
     * @param array $options
     * @return array
     */
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

    /**
     * Validate values and return true or false 
     *
     * @return boolean
     */
    protected function isValid()
    {
        $className = get_called_class();
        $attrNames = $this->getAttributeNames();

        foreach (get_class_methods($className) as $method) {
            if (substr($method, 0, 9) == 'validates')
            {
                $attrName = substr($method, 9);
                $attrName{0} = strtolower($attrName{0});
                if (in_array($attrName, $attrNames)) {
                    return call_user_func(array($className, $method), $this->$attrName);
                }
                else {
                    throw new Exception(sprintf("Cannot run the validator %s!  That attribute '%s' does not exist.", $method, $attrName));
                }
            }
        }

        return true;
    }

    // --------------------------------------------------------------

    /** 
     * Instantiate a record from a MongoDB object
     *
     * @param object $document
     * @return object
     */
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

    /**
     * Get the collection for this class
     *
     * @return MongoCollection
     * @throws Exception
     */
    protected static function getCollection()
    {
        $className = get_called_class();

        if (null !== static::$collectionName)
        {
            $collectionName = static::$collectionName;
        }
        else
        {
            $collectionName = explode('\\', $className);
            $collectionName = end($collectionName);

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

    /**
     * Set the timeout for connecting to MongoDB
     *
     * @param int $timeout
     */
    public static function setFindTimeout($timeout)
    {
        $className = get_called_class();
        $className::$findTimeout = $timeout;
    }

    // --------------------------------------------------------------

    /**
     * Ensures that an index exists for the given keys and options
     *
     * @param array $keys
     * @param array $options
     * @return boolean  (true)
     */
    public static function ensureIndex(array $keys, array $options = array())
    {
        return self::getCollection()->ensureIndex($keys, $options);
    }

    // --------------------------------------------------------------

    /**
     * Deletes an index for given keys
     *
     * @param array $keys
     * @return array
     */
    public static function deleteIndex($keys)
    {
        return self::getCollection()->deleteIndex($keys);
    }

}

/* EOF: BaseMongoRecord.php */