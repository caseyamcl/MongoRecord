<?php

require_once(__DIR__ . '/../lib/MongoRecord.php');
require_once(__DIR__ . '/../lib/MongoRecordIterator.php');
require_once(__DIR__ . '/../lib/Inflector.php');
require_once(__DIR__ . '/../lib/BaseMongoRecord.php');
require_once(__DIR__ . '/../lib/MongoRecordValidationException.php');

class MongoRecordTest extends PHPUnit_Framework_TestCase {

  private $conn;
  private $db;

  // --------------------------------------------------------------

  function setUp()
  {
    parent::setUp();
    $this->conn = new Mongo('mongodb://localhost');

    if ($this->conn) {
      $this->db = $this->conn->testdb;

      BaseMongoRecord::$connection = $this->conn;
      BaseMongoRecord::$database = 'testdb';
    }
  }

  // --------------------------------------------------------------

  function tearDown()
  {    
    parent::tearDown();

    if ($this->db) {
      $this->db->drop();
    }
  } 

  // --------------------------------------------------------------

  function testMongoDBConnectionGood() {

    //Test connection good
    $this->assertInstanceOf('MongoDB', $this->db, "Test cannot create a MongoDB.  Check connection parameters in testCase (u/n & p/w and server, etc)");
    
    //Test can write to db
    $testcol = $this->db->testcol;
    $this->assertTrue($testcol->insert(array('name' => 'Tester', 'age' => '30')));
  }
  
  // --------------------------------------------------------------

  function testCreateNewEntitySucceeds() {

    $obj = new TestEntity();
    $this->assertInstanceOf('TestEntity', $obj);

  }

  // --------------------------------------------------------------

  function testGetAttributesReturnsCorrectAttributes() {

    $obj = new TestEntity();
    $this->assertEquals(array('email' => NULL, 'password' => NULL), $obj->getAttributes());
  }  

  // --------------------------------------------------------------

  function testGetAttributeNamesReturnsCorrectList() {

    $obj = new TestEntity();
    $this->assertEquals(array('email', 'password'), $obj->getAttributeNames());
  }

  // --------------------------------------------------------------


  function testSetAttributesSetsCorrectlyForValidAttrs() {

    $obj = new TestEntity();
    $obj->email = 'test@example.com';
    $obj->password = 'pass1234';

    $this->assertEquals('test@example.com', $obj->email);
    $this->assertEquals('pass1234', $obj->password);
  }  

  // --------------------------------------------------------------

  function testSaveWritesRecordToDatabase() {

    $obj = new TestEntity();
    $obj->email = 'test@example.com';
    $obj->password = 'pass1234';

    $this->assertTrue($obj->save());
    $rec = $obj::findOne();

    $this->assertEquals('test@example.com', $rec->email);
  }

  // --------------------------------------------------------------

  function testIDisNullForUnsavedRecord() {
    $obj = new TestEntity();
    $obj->email = 'test@example.com';
    $this->assertEquals(NULL, $obj->getID());
  }

  // --------------------------------------------------------------

  function testSaveGeneratesAnIDForTheRecord() {

    $obj = new TestEntity();
    $obj->email = 'test@example.com';
    $obj->save();
    $this->assertRegExp("/[a-e0-9]+/", $obj->getID());
    $this->assertGreaterThan(5, strlen($obj->getID()));
  }
 
  // --------------------------------------------------------------

  function testValidationReturnsFalseForInvalidField() {

    $obj = new TestEntityTwo();
    $obj->email = 'personatpersoncom';
    $this->assertFalse($obj->validate());
  }

  // --------------------------------------------------------------

  function testValidationReturnsTrueForValidField() {

    $obj = new TestEntityTwo();
    $obj->email = 'person@person.com';
    $this->assertTrue($obj->validate());
  }

  // --------------------------------------------------------------

  function testSaveFailsForValidationFailures() {

    $obj = new TestEntityTwo();
    $obj->email = 'personatpersoncom';

    try {
      $obj->save();
    } catch (MongoRecordValidationException $e) {
      return;
    }

    $this->fail("Invalid validation should have thrown an exception!");

  }

  // --------------------------------------------------------------

  function testIterator() {

    $obj = new TestEntity();
    $obj->email = 'someguy@example.com';
    $obj->password = 'pass1234';

    $expectedArr = array('email' => 'someguy@example.com', 'password' => 'pass1234', '_id' => NULL);
    $testArr = array();

    foreach($obj as $k => $v) {
       $testArr[$k] = $v;
    }

    $this->assertEquals($testArr, $expectedArr);
  }

  // --------------------------------------------------------------

  /**
   * Test that any properties beginning with $_... are not
   * seen as attributes
   */
  function testNonAttributePropertiesDoNotGetSeenAsAttributes()
  {
    $obj = new TestEntityThree();
    $obj->email = 'somebody@example.com';
    $obj->firstName = 'Somebody';

    $this->assertEquals(array('firstName' => 'Somebody', 'email' => 'somebody@example.com'), $obj->getAttributes());
  }

  // --------------------------------------------------------------

  function testNonAttributePropertiesDoNotGetSavedAsAttributes()
  {
    $obj = new TestEntityThree();
    $obj->email = 'somebody@example.com';
    $obj->firstName = 'Somebody';
    $obj->save();

    //var_dump(get_class_methods(get_class($this->db)));
    $rec = $this->db->test_entity_threes->findOne(array('firstName' => 'Somebody', 'email' => 'somebody@example.com'));
    $this->assertEquals(array('_id', 'firstName', 'email'), array_keys((array) $rec));
  }
}

// ============================================================

class TestEntity extends BaseMongoRecord {

  protected $email;
  protected $password;
}

// ============================================================

class TestEntityTwo extends BaseMongoRecord {

  protected $firstName;
  protected $email;

  public static function validatesEmail($val) {
    return (strpos($val, '@') !== FALSE);
  }
}

// ============================================================

class TestEntityThree extends TestEntityTwo {

  public    $_publicItem    = 'abc';
  private   $_privateItem   = 'def';
  protected $_protectedItem = 'ghi';

}

/* EOF: MongoRecordTest.php */
