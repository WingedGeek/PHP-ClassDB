<?php
require_once(__DIR__ . "/../DBBackEnd.php");/**
 * Created by parse_structure.php.
 * User: chris
 * Created: 2020-04-04 21:10:36 Z
 */
class Phonetype extends DBBackEnd {
	public function __construct() {
		DBBackEnd::__construct();
		$this->table('phonetype');
		$this->columns(array('all' => array(
			'uuid',
			'type',
		)));
	}
}
?>
