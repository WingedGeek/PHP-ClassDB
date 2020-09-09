<?php
/**
 * Version 0.1
 * Heavily inspired by, and loosely sharing the API (but no code) from, Class::DBI
 * 	(https://metacpan.org/pod/Class::DBI)
 * User: WingedGeek
 * Date: 4/4/20
 * Time: 12:28 AM
 */

//namespace ClassDBI;

class ClassDBI
{
	private static $pdo;    // Shared across all instances of ClassDBI and subclasses (i.e., table objects)
	private $table = null;    // Distinct to each instantiation of this class or subclasses
	private $allcolumns = array();
	private $has_many = array();    // 'column' => 'table class', e.g., 'books' => 'Book'.
	// Book will set has_a('author' => 'Author').
	// book FK_column: Author->table() . "_" . Author->primaryKey()
	// Author->get('books') will return array containing Books where book.FK_column = Author->primaryKey();
	//   call_user_func(array($class,'test'));
	//	 $object = & call_user_func(array($class,'getInstance'), $param1, $param2 );
	// rch not necessary? private static $default_pk_suffix = "id";
	private $has_many_through = array();    // Like has_many, but also specifies a link table
	//	Song has_many Writers, Writer has_many Songs, linked through link_song_writer.
	//	Writers.php: ->has_many_through('songs', 'Song', 'link_song_writer')
	//		[ 'songs' => [ 'object' => 'Writer', 'linktable' => 'link_song_writer' ]

	private $has_a = array();
	// Likewise has_a('author' => 'Author')
	//	start with has_a name ('author')
	//	query Author to determine primaryKey ('authorid')
	//	foreign key = 'author_authorid'
	//	Retrieve all Author objects where primaryKey ('authorid') = this.author_authorid
// | bookid          | char(36)     | NO   | PRI | NULL    |       |
// | author_authorid | char(36)     | YES  |     | NULL    |       |

	private $primaryKey;
	private $rowdata = array();
	private $dirty = false;

	private $newPrimaryKeyCallbackFunction = 'generateUUID';    // Extensible way to generate primary key for new records
	// Can vary by table, so, not static.

	// TODO Constructors that create objects with row data.
	// TODO Static methods that create objects with skeleton data


	public function set_db($dsn, $user, $pass, $options = null)
	{
		$opt = (func_num_args() == 3 || $options === null)
			? [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			]
			: $options;
		try {
			ClassDBI::$pdo = new PDO($dsn, $user, $pass, $opt);
		} catch (\PDOException $e) {
			throw new \PDOException("E0006: " . $e->getMessage(), (int)$e->getCode());
		}
	}

	public function toString( $json = false ) {
		$retval = array();
		foreach ($this->allcolumns as $column) {
			$retval["{$column}"] = $this->get($column);
		}

		if($json) {
			return json_encode($retval);
		} else {
			return print_r($retval, true);
		}
	}

	/* This sets up a database connection with the given information.

		This uses Ima::DBI to set up an inheritable connection (named Main). It is therefore usual to only set up a connection() in your application base class () and let the 'table' classes inherit from it.
	*/
	public function connection($dsn, $user, $pass, $options = null)
	{
		if ((func_num_args() == 3 || $options === null))
			$this->set_db($dsn, $user, $pass);
		else
			$this->set_db($dsn, $user, $pass, $options);
	}

	public function getPDO()
	{
		return ClassDBI::$pdo;
	}

	public static function instantiateWithRowData($objectClass, $rowdata)
	{
		//$retval = call_user_func(array($objectClass,'__construct'));
		// TODO
		// print get_class();
	}


	function __construct($dbparams, $cparams)
	{
		//  $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
		//	$options = [
		//		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		// 		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		//		PDO::ATTR_EMULATE_PREPARES   => false,
		//  ]

		// Use default options if none supplied. Actually, why are we doing this?
		// We know what we want, right?
// 		$opt = (func_num_args() == 3 || $options === null)
// 			?	[
// 					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
// 					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
// 					PDO::ATTR_EMULATE_PREPARES   => false,
// 				]
// 			:	$options;
		$opt = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];
		try {
			$this->set_db($dbparams['db']['dsn'], $dbparams['db']['user'], $dbparams['db']['pass'], $opt);
		} catch (\PDOException $e) {
			throw new \PDOException("E0007: " . $e->getMessage(), (int)$e->getCode());
		}
	}

	/**
	 *
	 * @return string Newly generated primary key
	 */
	public static function getNewPrimaryKey()
	{
		$name = get_called_class();
		/* @var $o ClassDBI */
		$o = new $name();
		return $o->generatePrimaryKey();
	}

	public function generatePrimaryKey()
	{
		$fname = $this->newPrimaryKeyCallbackFunction;
		return $this->$fname();
	}

	public function setPrimaryKeyCallback($name)
	{
		$cname = get_called_class();
		if (!method_exists($cname, $name))
			throw new \PDOException("E0008: method '$name()' does not exist in $cname", -1);
		// This was failing, even though the programs using the subclasses got the expected results.
// 		if(!is_callable($name))
// 			throw new \PDOException("method '$name()' exists but is not callable by $cname", -1);
		$this->newPrimaryKeyCallbackFunction = $name;
	}

	public function getPrimaryKeyColumnName()
	{
		return $this->primaryKey;
	}

	// TODO doesn't work? Undefined index
	public function getPrimaryKey()
	{
		return $this->allcolumns[$this->primaryKey];
	}

	// Checks to see if the primary key exists; if it does not, return false, if it does, return id
	public function checkAndGetId()
	{
		$pkname = $this->primaryKey;
		// Is the primary key included in the column data? It better be.
		if (!in_array($pkname, $this->allcolumns))
			return false;

		// Is the primary key set?
		$pkval = $this->id();
		if ($pkval === null || strlen($pkval) < 1)
			return false;

		return $pkval;
	}

	// create in database (handle primary key generation or retrieval as needed
	// TODO


	public function id()
	{    // returns the primary key value TODO: if set, null if not
		$pk = $this->get($this->primaryKey);
		return $pk;
	}


	/**
	 * Inserts new data into database, returns object representing the newly inserted row. If table has a single primary key column and
	 * that column value is not defined in $data[], insert() will generate it using the method specified in newPrimaryKeyCallbackFunction
	 * If has_a is specified, an object of the appropriate type can be passed for that column
	 *
	 *
	 * @param String[] $insdata Associative array; keys match up to the database table columns, values are initial settings for those fields.
	 *
	 * @see setPrimaryKeyCallback()
	 * @throws Exception If something interesting cannot happen
	 * @return ClassDBI (subclass, e.g., Author)
	 */
	public static function insert($insdata = null, $suppress_primary_key = false ) {

//		ob_start();
//		echo "suppress_primary_key: " . var_dump($suppress_primary_key);
//		$suppress_primary_key = true;
//		echo "suppress_primary_key now: " . var_dump($suppress_primary_key);
//
//		self::debug(ob_get_clean());


		$ocname = get_called_class();
		self::debug($ocname . "::insert() called with insdata: " . print_r($insdata, true) . ", suppress_primary_key: " . print_r($suppress_primary_key, true));

		/* @var $obj ClassDBI */        // Eliminates "method 'primaryKey not found in ... Referenced method not found in subject class." warning in PhpStorm
		$obj = new $ocname();

		// start with blank array of columns to populate:
		$insertdata = array();

		// If primary key creation is not suppressed:
		if( $suppress_primary_key === false ) {
			self::debug("Populating primary key...");
			$pk = $obj->primaryKey();
			// If primary key is not included in $data (or is null or empty), generate a new one using
			// specified callback method:
			if ((!array_key_exists($pk, $insertdata)) || ($insertdata["$pk"] === null) || (strlen($insertdata["$pk"]))) {
				$insertdata["$pk"] = $obj->generatePrimaryKey();
			}
		} else {
			self::debug("primary key suppressed");
		}

		foreach ($insdata as $key => $val) {
			if (array_key_exists($key, $obj->has_a)) {
				// TODO

				// Handle object
				// Determine the column in this table that will resolve to the object (e.g., if object is Author, author_authorid, determined with ->table() and ->primaryKey())
//				$cname = $obj->has_a["key"];	// E.g. Author
//				$o = new $cname();				// E.g. ClassDBI::DBLibrary::Author
//				$lclColumnName = $o->table() . "_" . $o->primaryKey();
//				$lclValue = $val->getPrimaryKey();
//				$insertdata[$lclColumnName] = $lclValue;

				// 2020.04.06
				// e.g., 'book' => a Book object
				// TODO handle objects with no primary key yet...

				// getTableNameAndPrimaryKey

			} else {
				// scalar
				$insertdata[$key] = $val;
			}
		}

		// print_r($insertdata);
		self::debug("data now: " . print_r($insertdata, true ));
		$insertReady = ClassDBI::BuildInsertColumnsPlaceholdersData($insertdata);

		self::debug("insertReady: " . print_r($insertReady, true));

		// To eliminate PhpStorm warnings re SQL dialect: PhpStorm -> Preferences -> Languages & Frameworks -> SQL Dialects
		// To eliminate "no data sources" warnings:
		$SQL = "INSERT INTO " . $obj->table() . " (" . $insertReady['columns'] . ") VALUES (" . $insertReady['placeholders'] . ")";
		self::debug($SQL);

		// $stmt = $obj->getPDO()->prepare($SQL);	// gave warnings in IDE, so broke it out into separate statements:
		/* @var PDO $pdo */
		$pdo = $stmt = $obj->getPDO();
		/* @var PDOStatement $stmt */
		$stmt = $pdo->prepare($SQL);

		self::debug($SQL);
		self::debug("->execute() with: " . print_r($insertReady['values'], true));

		$stmt->execute($insertReady['values']);
		if($suppress_primary_key === false)
			return $obj->retrieve($insertdata[$obj->primaryKey()]);
		else
			return $obj;

// The before_create trigger is invoked directly after storing the supplied values into the new object and before inserting the record into the database. The object stored in $self may not have all the functionality of the final object after_creation, particularly if the database is going to be providing the primary key value.

// For tables with multi-column primary keys you need to supply all the key values, either in the arguments to the insert() method, or by setting the values in a before_create trigger.

// If the class has declared relationships with foreign classes via has_a(), you can pass an object to insert() for the value of that key. Class::DBI will Do The Right Thing.

// After the new record has been inserted into the database the data for non-primary key columns is discarded from the object. If those columns are accessed again they'll simply be fetched as needed. This ensures that the data in the application is consistent with what the database actually stored.

// The after_create trigger is invoked after the database insert has executed.


	}

	public static function buildInsertColumnsPlaceholdersData($darray)
	{
		$retval = array(
			'columns' => '',
			'placeholders' => '',
			'values' => array()
		);

		foreach ($darray as $key => $val) {
			$retval['columns'] .= (strlen($retval['columns']) > 0) ? ", $key" : "$key";
			$retval['placeholders'] .= (strlen($retval['placeholders']) > 0) ? ", ?" : "?";
			array_push($retval['values'], $val);
		}

		return $retval;
	}

	function populate($data)
	{
		// TODO sanity check

		// rowdata; fail if try to set a column that hasn't been specified in the ->columns() method
		foreach ($data as $key => $val) {
			if (!in_array($key, $this->allcolumns)) {
				throw new \PDOException("E0009: Attempting to populate non-existent column '$key'", -1);
			} else {
				$this->rowdata["$key"] = $val;
			}
		}
		// $this->dirty = true;	// State has changed since last UPDATE	// TODO make this intelligent; if all vals are same as existing data, not dirty.
	}


	/**
	 * Retrieve a single ClassDBI object (representing one row of data) from the database.
	 *
	 * @author WingedGeek
	 * @param string $primarykey The primary key used to specify which record (row) to retrieve.
	 * @return ClassDBI object specified by the supplied primary key.
	 */
	public static function retrieve($primarykey)
	{
		$class = get_called_class();    // e.g. Author (get_class() returns ClassDBI, get_called_class returns Author, extending DBLibrary, extending ClassDBI...
		/* @var ClassDBI $obj */
		$obj = new $class();            // e.g., Author
		/* @var PDO $pdo */
		$pdo = $obj->getPDO();

		$SQL = "SELECT " . $obj->buildAllColumnNamesSQL() . " FROM " . $obj->table() . " WHERE " . $obj->primaryKey . " = ?";
		$stmt = $pdo->prepare($SQL);
		$stmt->execute([$primarykey]);
		if ($row = $stmt->fetch()) {
			$obj->populate($row);
			return $obj;
		}
		throw new \PDOException("E0010: Cannot retrieve row data from " . $obj->table() . " for " . $obj->primaryKey . " = '" . $primarykey . "'", -1);
	}


	function buildAllColumnNamesSQL()
	{
		$colnames = "";
		foreach ($this->allcolumns as $colname) {
			$comma = (strlen($colnames) > 1) ? ", " : "";
			$colnames .= $comma . $colname;
		}
		return $colnames;
	}

	function buildAllColumnPlaceholders()
	{
		$placeholders = "";
		for ($i = 0; $i < count($this->allcolumns); $i++) {
			$placeholders .= ($i > 0) ? ", ?" : "?";
		}
		return $placeholders;
	}

	/*	An accessor to get/set the name of the database table in which this class is stored. It -must- be set.
	*/
	function table($table = null)
	{
		if ((func_num_args() == 1 || $table !== null)) {
			$this->table = $table;
			return true;
		} else {
			return $this->table;
		}
	}

	function primaryKey($pkey = null)
	{    // gets/sets the column name of the primary key, apparently.
		if ((func_num_args() == 1 || $pkey !== null)) {
			$this->primaryKey = $pkey;
			return true;
		} else {
			return $this->primaryKey;
		}
	}


	function columns($col_array = null)
	{
		$this->sanityCheck(array('table'));

		if ((func_num_args() == 1 || $col_array !== null)) {
			// If 'all', populate $this->allcolumns and set primary key as the first entry in this list
			if (isset($col_array['all']) && count($col_array['all']) > 0) {
// 				print "Processing 'all' ...\n";
				$this->primarykey($col_array['all'][0]);
				$this->allcolumns = array();
				foreach ($col_array['all'] as $colname) {
					array_push($this->allcolumns, $colname);
				}
			}
			return true;
		} else {
			return $this->allcolumns;
		}
	}

	/**
	 * Specifies a many:many relationship and the class (linktable) that creates that relationship.
	 *
	 * Like has_many, but also specifies a link table
	 *    Song has_many Writers, Writer has_many Songs, linked through link_song_writer.
	 *    Writers.php: ->has_many_through('songs', 'Song', 'Link_Song_Writer')
	 *  [ 'songs' => [ 'object' => 'Writer', 'linktable' => 'Link_Song_Writer' ]
	 *
	 * @param string $name E.g., 'phones'
	 * @param string $className E.g., 'Phone'
	 * @param string $linkClass E.g., 'Link_Contact_phone'
	 */
	function has_many_through($name, $className, $linkClass)
	{
		// Add or modify entry in the has_many_through associative array
		$this->has_many_through["$name"] = [
			'object' => $className,
			'linkclass' => $linkClass
		];
	}

	function has_many($name, $className, $linktable = null)
	{
		if (func_num_args() == 3 && $linktable !== null) {
			$this->has_many_through($name, $className, $linktable);
		} else {
			// Add or modify entry in the has_many associative array
			$this->has_many["$name"] = $className;
		}
	}

	function has_a($name, $className, $columnName = null)
	{
		// TODO modify to allow specifying column name ('created_user_uuid') instead of always divining it ('user_uuid')
		$this->has_a["$name"] = array('class' => $className, 'colname' => $columnName);
	}

	/**
	 * Retrieve all ClassDBI objects (each representing one row of data) from the database, ordered by $oc
	 *
	 * @author WingedGeek
	 * @param  string $oc An order by clause; e.g., if $oc = "NAME ASC", the SQL query will terminate in "ORDER BY NAME ASC"
	 * @return ClassDBI[] ClassDBI objects, each representing one row of data
	 */
	public static function fetchAll($oc = null)
	{
		$orderclause = (func_num_args() == 1 || $oc !== null) ? $oc : "";
		//$this->sanityCheck( array('table', 'columns') );
		//
		$retval = array();

		$name = get_called_class();
		/* @var ClassDBI $o */
		$o = new $name();

		$SQL = "SELECT " . $o->buildAllColumnNamesSQL() . " FROM " . $o->table();
		if (strlen($orderclause) > 1)
			$SQL .= " ORDER BY $orderclause";
		/* @var PDO $pdo */
		$pdo = $o->getPDO();
		$stmt = $pdo->prepare($SQL);
		$stmt->execute();
		while ($row = $stmt->fetch()) {
			/* @var ClassDBI $obj */
			$obj = new $name();
			$obj->populate($row);
			array_push($retval, $obj);
		}
		return $retval;
	}

	/**
	 * Returns array of ClassDBI objects matching the supplied search criteria.
	 *
	 * @param string[] $term_val_array Associative array of search terms, e.g., ['first_name => 'John', 'last_name' => 'Smith']
	 * @param string $oc An order by clause; e.g., if $oc = "NAME ASC", the SQL query will terminate in "ORDER BY NAME ASC"
	 * @return ClassDBI[]
	 */
	public static function search($term_val_array, $oc = null)
	{
		// xTODO add ORDER logic
		$orderclause = (func_num_args() == 2 || $oc !== null) ? $oc : "";

		// xTODO Implement wildcards
		// TODO handle ESCAPE?
		// Build a new term_val array with 'LIKE' or '=' as appropriate (scan for wildcards):
		$search_array = array();
		foreach ($term_val_array as $inkey => $inval) {
			if ((strpos($inval, '%') === 0) || (strrpos($inval, '%') === (strlen($inval) - 1))) {
				// The value we're searching for starts or ends with a MySQL wildcard '%' (colname LIKE ?)
				$search_array[$inkey . " LIKE "] = $inval;
			} else {
				// No wild card, straight match only (colname = ?)
				$search_array[$inkey . " = "] = $inval;
			}
		}

		$name = get_called_class();    // e.g. Author
		/* @var ClassDBI $o */
		$o = new $name();            // e.g., Author

		// No 'internal' specified, because it's built into the keys in search_array.
		$parsed = $o->buildPlaceHolder($search_array, "", " AND ");
		$whereclause = $parsed["placeholder"];
		$values = $parsed["values"];

		$SQL = "SELECT " . $o->buildAllColumnNamesSQL() . " FROM " . $o->table() . " WHERE $whereclause";
		if (strlen($orderclause) > 1)
			$SQL .= " ORDER BY $orderclause";

		/* @var PDO $pdo */
		$pdo = $o->getPDO();
		$stmt = $pdo->prepare($SQL);
		$stmt->execute($values);

		$retval = array();
		while ($row = $stmt->fetch()) {
			/* @var ClassDBI $obj */
			$obj = new $name();
			$obj->populate($row);
			array_push($retval, $obj);
		}
		return $retval;
	}

	function buildPlaceHolder($assocArray, $internal, $glue)
	{
		$retval = array('placeholder' => "", 'values' => array());
		foreach ($assocArray as $key => $val) {
			$retval["placeholder"] .= (strlen($retval["placeholder"]) > 0) ? $glue : "";
			$retval["placeholder"] .= $key . "" . $internal . "?";
			array_push($retval["values"], $val);
		}
		return $retval;
	}

	public function update()
	{
		if (!$this->dirty) // Only make an expensive database query if the state has changed
			return;

		// xTODO handle objects (has_a)
		// This should not be necessary, since set() is already populating the foreign keys.


		// Prepare SQL query with all the column names (e.g., UPDATE author SET uuid = ?, first = ? ... WHERE uuid = ?):
		$parsed = $this->buildPlaceHolder($this->rowdata, " = ", ", ");
		$SQL = "UPDATE " . $this->table() . " SET " . $parsed["placeholder"] . " WHERE " . $this->primaryKey() . " = ?";
		/* @var PDO $pdo */
		$pdo = $this->getPDO();
		$stmt = $pdo->prepare($SQL);

		// Append the primary key value to the end, to handle that last placeholder '?' (where uuid = ?):
		array_push($parsed["values"], $this->get($this->primaryKey()));

		// Execute the SQL query:
		$stmt->execute($parsed["values"]);
	}

	public function delete()
	{
		$SQL = "DELETE FROM " . $this->table() . " WHERE " . $this->primaryKey() . " = ?";
		/* @var PDO $pdo */
		$pdo = $this->getPDO();
		$stmt = $pdo->prepare($SQL);
		//$stmt->execute( [ $this->get( $this->primaryKey() ) ] );
		$stmt->execute([$this->id()]);
	}

	/**
	 * Quick and dirty way to get all the raw column data for a row; references ('has_a' 'has_many') are not expanded. Raw (e.g., foreign keys) data only.
	 * @return associative array, { $col => $val, ... }
	 */
	public function getRawAssoc() {
		$retval = array();
		foreach($this->allcolumns as $col) {
			$retval["{$col}"] = $this->get("$col");
		}

		return $retval;
	}

	function get_include_path() {
		return __DIR__ . DIRECTORY_SEPARATOR . get_parent_class($this) . DIRECTORY_SEPARATOR;  // /home/user/lib/DBBackEnd/
	}

	public function get( $name, $orderclause = null ) {					// e.g. 'author'
		$path = $this->get_include_path();  // __DIR__ . DIRECTORY_SEPARATOR . get_parent_class($this) . DIRECTORY_SEPARATOR;  // /home/user/lib/DBBackEnd/

		if(array_key_exists($name, $this->has_a)) {	// Should be simple; retrieve the object.
/*			$objName = $this->has_a["$name"];		// e.g. Author
//			$obj = new $objName();
//			$table = $obj->table();					// e.g. author
//			$pk = $obj->primaryKey();				// e.g. authorid
//			$lclColumnName = $table . "_" . $pk;	// author_authorid (enforced convention) // TODO make more flexible?
			$foreignID = $this->rowdata[ $this->getTableNameAndPrimaryKey( $objName ) ];
			return $objName::retrieve( $foreignID );	// Author::retrieve( <id> );
*/
			// Modified to handle             // $this->has_a["$name"] = array( 'class' => $className, 'colname' => $columnName );

			$hasa_entry = $this->has_a["$name"];
			/* @var ClassDBI $obj */
			$obj = $hasa_entry["class"];
            $objName = $hasa_entry["class"];
			require_once($path . $objName . ".php");

			$fk_colname = ($hasa_entry["colname"] !== null ) ? $hasa_entry["colname"] : $this->getTableNameAndPrimaryKey( $objName );
            return $obj::retrieve( $this->rowdata["{$fk_colname}"] );
		}

		if(array_key_exists($name, $this->has_many_through)) {
			/*  e.g. for Contact has_many_through('phones', 'Phone', 'Link_Contact_Phone'):
			 *  retrieve has_many_through definition:
			 * (
				    [object] => Phone
				    [linkclass] => Link_Contact_Phone
				)
			 *  contact finds has_many_through
			 *      knows its own table name
			 *      knows its own primary key
			 *      assembles it (contact_uuid)
			 * determines target class (Phone)
			 *      queries for table (phone)
			 *      queries for primary key (uuid)
			 *      assembles it
			 *
			 *  Uses search() to retrieve all Link_Contact_Phone records for contact_uuid
			 *  Instantiates array
			 *  Uses targetClass (Phone)::retrieve to pull each uuid
			 */
			// Determine this table's name and uuid, which will be the search column for the link table:
			$search_column = $this->table() . "_" . $this->primaryKey();        // contact_uuid

			// Fetch the relationship definition (set using ->has_many_through():
			$ar = $this->has_many_through["{$name}"];   // [object] => 'Phone', [linkclass] => 'Link_Contact_Phone'

			// Dynamically load the required classes:
			require_once($path . $ar['object'] . ".php");
			require_once($path . $ar['linkclass'] . ".php");

			// Determine target class table name and primary key:
			/* @var ClassDBI $tmpobj */
			$tmpobj = new $ar['object'];

			// This worked, but didn't allow for, e.g., order_by where the sort value ('note.created') was in the target table.
//			$result_column = $tmpobj->table() . "_" . $tmpobj->primaryKey();    // phone_uuid
//
//			// Get the rows from the link table
//			$linked = $ar['linkclass']::search( [ "$search_column" => $this->id() ], $orderclause );
//
//			$retval = array();
//			/* @var ClassDBI $linkobj */
//			foreach($linked as $linkobj) {
//				$target_id = $linkobj->get("$result_column");
//				// Add an object (e.g. Phone) to the array, retrieved using the primary key specified in the link table
//				// e.g. phone_uuid (where each link_contact_phone row contains a contact_uuid and a phone_uuid):
//				array_push( $retval, $ar['object']::retrieve( $target_id ));
//			}

			// Modified approach:
			// get link table name:
			$lt = new $ar['linkclass'];
			$lt_name = $lt->table();            // e.g. link_matter_note

			// get target table name:
			$tt = new $ar['object'];
			$tt_name = $tt->table();            // note

			// determine target table primary key
			$tt_pk = $tt->primaryKey();         // uuid

			// determine foreign key
			$lt_fk = $tt_name . "_" . $tt_pk;   // note_uuid

			$this->debug("Building a SQL query with $lt_name");
			// mysql> select * from link_matter_note inner join note on link_matter_note.note_uuid = note.uuid order by created desc;
			$SQL = "SELECT * FROM $lt_name INNER JOIN $tt_name ON $lt_name.$lt_fk = $tt_name.$tt_pk WHERE $search_column = ?";
			if((isset($orderclause)) && ($orderclause !== null) && (strlen($orderclause) > 3))
				$SQL .= " ORDER BY " . $orderclause;
			$this->debug("get() has_many_through SQL: " . $SQL);

			/* @var PDO $pdo */
			$pdo = $this->getPDO();
			$stmt = $pdo->prepare($SQL);
			$stmt->execute([$this->id()]);


			$retval = array();
			while($row = $stmt->fetch()) {
				$this->debug("Looking for $lt_fk in " . print_r($row, true));
				$target_id = $row["{$lt_fk}"];
				$this->debug("Found: '" . $target_id . "'");
				array_push($retval, $ar['object']::retrieve( $target_id ));
			}
			return $retval;
		}

		if(array_key_exists($name, $this->has_many)) {	// e.g., 'books'
			// TODO do we care about a reverse map (has_a)?
			// TODO do we care about the rigid convention here?
			$search_column = $this->table() . "_" . $this->primaryKey();
			$search_value = $this->get( $this->primaryKey() );
			$linkedClassName = $this->has_many["$name"];


//			echo "<pre>\n";
//            var_dump(debug_backtrace());
//			echo "</pre>\n";
//
//			$called_class = get_called_class();
//			$parent_object_name = $called_class::get_parent_class();

			//$path .= DIRECTORY_SEPARATOR . get_parent_class( $this ) . DIRECTORY_SEPARATOR;

//            $path .= DIRECTORY_SEPARATOR . $parent_object_name . DIRECTORY_SEPARATOR;
//            echo "<pre>get_class( this ): " . get_class($this) . "</pre>\n";
//            echo "<pre>get_parent_class( this ): " . get_parent_class($this) . "</pre>\n";

			require_once($path . $linkedClassName . ".php");

			return $linkedClassName::search( array( $search_column => $search_value ), $orderclause );
		}

		if(in_array($name, $this->allcolumns)) {	// Scalar. Simplest.
			return $this->rowdata["$name"];
		}

        throw new \PDOException("E0001: get('$name') not defined for " . get_called_class(), -1);
    }

    public function getTableNameAndPrimaryKey( $objectName ) {
		// Takes the name of an object (e.g., "Book") and instantiates it,
		// then accesses its table name ('book') and the name of its primary key ('uuid').
		// Returns them combined in what should be the foreign key column name, e.g., book_uuid
		require_once("DBBackEnd/" . $objectName . ".php");		// TODO Fix to be dynamic not hardcoded

		/* @var ClassDBI $obj */
        $obj = new $objectName();
        $table = $obj->table();
        $pk = $obj->primaryKey();
       	return $table . "_" . $pk;
    }

	public function set($name, $value) {
		// has_a
		if(array_key_exists($name, $this->has_a)) {	// This handles a passed Object; a scalar (database key) can be directly set via the foreign key column (e.g., this will use a passed Author object to set author_uuid, but author_uuid can also be set directly.
			/* @var ClassDBI $value */
			// Update to handle expressly set column names:
            // $this->has_a["$name"] = array( 'class' => $className, 'colname' => $columnName );

			// Old:
//          $foreign_key_colname = $value->table() . "_" . $value->primaryKey();
			// Expressly set columns:
			$hasa_entry = $this->has_a["{$name}"];
			$foreign_key_colname = ($hasa_entry["colname"] !== null) ?  $hasa_entry["colname"] : $value->table() . "_" . $value->primaryKey();
            return $this->set( $foreign_key_colname, $value->id() );
		}
		// scalar (easy):
		if(in_array($name, $this->allcolumns)) {
            /* @var string $value */
            if($this->rowdata["$name"] != $value) {
				// If rowdata[$name] is not already equal to the specified $value,
				// assign the new value to that key in the rowdata array and mark
				// the object 'dirty' (has change(s) not yet written to the database)
				$this->rowdata["$name"] = $value;
				$this->dirty = true;
 			}
 			return $this->dirty;
		}

		if(array_key_exists($name, $this->has_many) || array_key_exists($name, $this->has_many_through)) {
            throw new \PDOException("E0002: set() not defined for has_many relationships");
		}

		throw new \PDOException("E0003: set('$name') undefined for " . get_called_class());
	}






	/** Internal convenience function to build array used for ::insert and ::search related to link tables
	 *
	 * @var ClassDBI $object
	 * @var string[] $additional_columns
	 * @return string[] associative array
	 */
	function build_link_array( $object, $additional_columns) {
		$retval = array();

		$leftside_col = $this->table() . "_" . $this->primaryKey();
		$rightside_col = $object->table() . "_" . $object->primaryKey();
		$retval["{$leftside_col}"] = $this->id();
		$retval["{$rightside_col}"] = $object->id();

		if($additional_columns !== false) {
			foreach($additional_columns as $col => $val) {
				$retval["{$col}"] = $val;
			}
		}
		return $retval;
	}

	/** Remove link table entry linking this ClassDBI object to another (many:many)
	 *
	 * 	$additional_columns is an optional associative array for link tables that include additional
	 *  columns describing the relationship, e.g., Link_Matter_Contact contains the expected
	 *  matter_uuid and contact_uuid foreign keys, but also a contactrelationship_uuid. Only link table
	 *  records matching all columns will be deleted.
	 *
	 * @var ClassDBI $object
	 * @var string[] $additional_columns
	 * @return string[] array of IDs the links to which were deleted
	 */
	function unlink($object, $additional_columns = false) {
		$objClassName = get_class( $object );   // e.g. Contact
		$foreignKey = $object->table() . "_" . $object->primaryKey();   // e.g. contact_uuid
		$retval = array();

		// Populate the array to be passed to ::search, including any additional columns
		$searchData = $this->build_link_array($object, $additional_columns);
		self::debug( "unlink() with searchData: " . print_r($searchData, true));

		// Search for has_many_through and find those that link with the type of $object
		foreach($this->has_many_through as $hmt) {
			if( strcmp($hmt['object'], $objClassName) == 0 ) {
				// Match.
				$linkclassObjName = $hmt['linkclass'];
				require_once( $this->get_include_path() . $linkclassObjName . ".php");
				// Call, e.g., Link_Matter_Contact::search():
				try {
					$linked = call_user_func( [ $linkclassObjName, 'search' ], $searchData );
					/* @var ClassDBI $link */
					foreach($linked as $link) {
						array_push($retval, $link->get('$foreignKey'));
						$link->delete();
					}
				} catch( Exception $e ) {
					self::debug( print_r($e, true) );
				}
			}
		}
		return $retval;
	}


	/** Link another ClassDBI object through a link table (many:many)
	 *
	 * $additional_columns is an optional associative array for link tables that include additional
	 *  columns describing the relationship, e.g., Link_Matter_Contact contains the expected
	 *  matter_uuid and contact_uuid foreign keys, but also a contactrelationship_uuid. The
	 *  keys/values in this array will be used to populate those columns.
	 *
	 * @var ClassDBI $object
	 * @var string[] $additional_columns
	 */
	function link($object, $additional_columns = false) {
		// for many:many through a link_through table
		$objClassName = get_class( $object );       // e.g. Note
		// search for has_many_through where the target class is the same type as passed $object

		// Populate the array to be passed to ::insert, including any additional columns
		$insertData = $this->build_link_array($object, $additional_columns);

		self::debug( "link() with insertData: " . print_r($insertData, true));

		foreach($this->has_many_through as $hmt) {
			//	(
			//		[object] => Note
			//		[linkclass] => Link_Matter_Note
			//	)

			if( strcmp($hmt['object'], $objClassName) == 0 ) {
				$linkclassObjName = $hmt['linkclass'];
				require_once( $this->get_include_path() . $linkclassObjName . ".php");
				// Call, e.g., Link_Matter_Note::insert():
				try {
					call_user_func( [ $linkclassObjName, 'insert' ], $insertData, true );
				} catch( Exception $e ) {
					self::debug( print_r($e, true) );
				}
			}
		}
	}

	public static function debug( $message ) {
		file_put_contents( "/home/kpgsaa/cgi_debug.log", date("Y-m-d H:i:s") . "\t" . $message . "\n", FILE_APPEND | LOCK_EX );
	}


	function sanityCheck( $checkarray = null ) {
// 		print "Performing sanity check: " . print_r($checkarray, 1);
//		if(func_num_args() == 1 || $table !== null) {
		if(func_num_args() == 1) {
			if(in_array( "table", $checkarray )) {
				if($this->table === null)
					throw new \PDOException("E0004: Table cannot be null", -1);
			}
			if(in_array( "columns", $checkarray )) {
				// print_r($this->allcolumns);
				if($this->allcolumns === null || count($this->allcolumns) < 1)
					throw new \PDOException("E0005: Columns array cannot be empty", -1);
			}
		}
	}

	/*	the fundamental set() and get() methods

		$value = $obj->get($column_name);
		@values = $obj->get(@column_names);

		$obj->set($column_name => $value);
		$obj->set($col1 => $value1, $col2 => $value2 ... );
		These methods are the fundamental entry points for getting and setting column values. The extra accessor methods automatically generated for each column of your table are simple wrappers that call these get() and set() methods.

		The set() method calls normalize_column_values() then validate_column_values() before storing the values. The before_set_$column trigger is invoked by validate_column_values(), checking any constraints that may have been set up.

		The after_set_$column trigger is invoked after the new value has been stored.

		It is possible for an object to not have all its column data in memory (due to lazy inflation). If the get() method is called for such a column then it will select the corresponding group of columns and then invoke the select trigger.
	*/
// 	function get($col) {
// 		return 1;
// 	}

// Declare your columns.
// This is done using the columns() method. In the simplest form, you tell it the name of all your columns (with the single primary key first):
//
// Music::CD->columns(All => qw/cdid artist title year/);
// If the primary key of your table spans multiple columns then declare them using a separate call to columns() like this:
//
// Music::CD->columns(Primary => qw/pk1 pk2/);
// Music::CD->columns(Others => qw/foo bar baz/);

    /**
     * @brief Generates a Universally Unique IDentifier, version 4.
     *
     * This function generates a truly random UUID. The built in CakePHP String::uuid() function
     * is not cryptographically secure. You should uses this function instead.
     *
     * @see http://tools.ietf.org/html/rfc4122#section-4.4
     * @see http://en.wikipedia.org/wiki/UUID
     * @return string A UUID, made up of 32 hex digits and 4 hyphens.
     * @throws Exception
     */
	    public static function generateUUID() {
			$pr_bits = null;
			$fp = @fopen('/dev/urandom','rb');
			if ($fp !== false) {
				$pr_bits .= @fread($fp, 16);
				@fclose($fp);
			} else {
                throw new \Exception("E0011: Unable to generate UUID value (attempting to open /dev/urandom in mode 'rb')", -1);
			}

			$time_low = bin2hex(substr($pr_bits,0, 4));
			$time_mid = bin2hex(substr($pr_bits,4, 2));
			$time_hi_and_version = bin2hex(substr($pr_bits,6, 2));
			$clock_seq_hi_and_reserved = bin2hex(substr($pr_bits,8, 2));
			$node = bin2hex(substr($pr_bits,10, 6));

			/**
			 * Set the four most significant bits (bits 12 through 15) of the
			 * time_hi_and_version field to the 4-bit version number from
			 * Section 4.1.3.
			 * @see http://tools.ietf.org/html/rfc4122#section-4.1.3
			 */
			$time_hi_and_version = hexdec($time_hi_and_version);
			$time_hi_and_version = $time_hi_and_version >> 4;
			$time_hi_and_version = $time_hi_and_version | 0x4000;

			/**
			 * Set the two most significant bits (bits 6 and 7) of the
			 * clock_seq_hi_and_reserved to zero and one, respectively.
			 */
			$clock_seq_hi_and_reserved = hexdec($clock_seq_hi_and_reserved);
			$clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
			$clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;

			return sprintf('%08s-%04s-%04x-%04x-%012s',
				$time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node);
	}
}

?>
