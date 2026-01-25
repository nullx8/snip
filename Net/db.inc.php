<?php

if (!defined('DB_HOST')) { 		define('DB_HOST', 		getenv('DB_HOST') ?: 'localhost'); }
if (!defined('DB_USER')) { 		define('DB_USER', 		getenv('DB_USER') ?: 'root'); }
if (!defined('DB_PWD')) { 		define('DB_PWD', 		getenv('DB_PWD') ?: ''); }
if (!defined('DB_DB')) { 		define('DB_DB', 		getenv('DB_DB') ?: ''); }
if (!defined('DB_CHARSET')) { 	define('DB_CHARSET', 	getenv('DB_CHARSET') ?: 'utf-8'); }

/*
 * call with 
 * require_once('Net/db.inc.php'); $db = new Db;
 */
 
class Db {
    // The database connection
    protected static $connection;

    /**
     * Connect to the database
     * 
     * @return bool false on failure / mysqli MySQLi object instance on success
     */
    public function connect() {    
        // Try and connect to the database
        if(!isset(self::$connection)) {
            // Load configuration as an array. Use the actual location of your configuration file
            self::$connection = new mysqli(DB_HOST, DB_USER, DB_PWD, DB_DB);

			// set to utf8
			mysqli_set_charset( self::$connection, DB_CHARSET);

        }

        // If connection was not successful, handle the error
        if(self::$connection === false) {
            // Handle error - notify administrator, log to a file, show an error screen, etc.
            return false;
        }
        return self::$connection;
    }


    public function errmail() {
		$error_msg= "Erron No:".mysql_errno(). "<br>";
		$error_msg .="Error message = ".mysql_error();
		$error_msg .="\n ".print_r($this->error(), true);
		$error_msg .="\non ". $_SERVER['REQUEST_URI'];
//		mail("swingfish@icloud.com","MySql Error",$error_msg,"");
	}

    public function query($query) {
        // Connect to the database
        $connection = $this -> connect();

        // Query the database
        $result = $connection -> query($query);

        if($result === false) {
            $this->errmail();
            return false;
        }
        else {
	        return $result;
        }
    }

	public function escape($sql) {
        $connection = $this -> connect();
		return $connection->real_escape_string($sql);
	}

    public function quote($value) {
        $connection = $this -> connect();
        return "'" . $connection -> real_escape_string($value) . "'";
    }
	



    public function select($query) {
        $rows = array();
        $result = $this -> query($query);
        if($result === false) {
            return false;
        }
        while ($row = $result -> fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

	public function result($result, $number=0, $field=0) {
        mysqli_data_seek($result, $number);
        $row = mysqli_fetch_array($result);
        return $row[$field];
    }

	function fetch($sql) {
		$query = $this->query($sql);
		return $this->result($query);
	}

	public function fetchNum($query) {
		return intval($this->fetch($query));
	}

    public function fetchRows($query) {
		return $this->select($query);
	}

    public function fetchRow($query) {
		return @$this->select($query)[0];
	}

    public function error() {
        $connection = $this -> connect();
        return $connection -> error;
    }
}