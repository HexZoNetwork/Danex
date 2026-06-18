<?php
class Connector {
	private static $instance = NULL;
	public static function getConnexion() {
	       	if (!isset(self::$instance)) {
				include("keys.php");
		$dsn = 'mysql:host='.$serverHost.';port='.$serverPort.';dbname='.$dbName.';charset=utf8mb4';
		self::$instance = new PDO($dsn, $username, $pass, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		}
	return self::$instance;
	}
}
?>
