<?php
require_once(__DIR__ . "/../DBBackEnd.php");/**
 * Created by parse_structure.php.
 * User: chris
 * Created: 2020-04-04 21:10:36 Z
 */
class Link_Contact_Phone extends DBBackEnd {
	public function __construct() {
		DBBackEnd::__construct();
		$this->table('link_contact_phone');
		$this->columns(array('all' => array(
			'contact_uuid',
			'phone_uuid',
		)));
	}
}
?>
