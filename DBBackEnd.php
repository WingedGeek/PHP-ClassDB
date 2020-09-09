<?php
// namespace ClassDBI;
require_once(__DIR__."/ClassDBI.php");  // The directory of the file.

class DBBackEnd extends ClassDBI {
    /***************** Database-specific values *****************/
    private $host = 'mysql.mydomain.com';
    private $dbname = 'mydomaindb';
    private $user = 'dbuser';
    private $pass = 'passW0rd';
    private $charset = 'utf8mb4';
    private $path_to_classes = __DIR__ . "/lib/ClassDBI/DBBackEnd";
    /***************** End user configuration *****************/

    function __construct( $cparams = null ) {
        $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
        $dbparams = array(
            'db' => array(
                'dsn' 	=> $dsn,
                'user' 	=> $this->user,
                'pass' 	=> $this->pass
            )
        );
        try {
            parent::__construct( $dbparams, $cparams );
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
}
?>
