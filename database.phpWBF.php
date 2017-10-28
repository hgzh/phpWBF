<?php
class phpWBFdatabase extends mysqli {

	/* connectWMCSReplica()
	 connect to a Wiki replica
	 @param string pCluster
	 @param string pDatabase
	*/		
	public function connectWMCSReplica($pCluster, $pDatabase) {
		$userinfo = posix_getpwuid(posix_getuid());
		$config = parse_ini_file($userinfo['dir'] . '/replica.my.cnf');
		
		parent::connect($pCluster, $config['user'], $config['password']);
		if ($this->connect_error) {
			die('Database connection failed');
		}
		
		unset($config, $userinfo);
		
		$res = $this->select_db($pDatabase);
		if ($res === false) {
			die('Daatabase not found');
		}
	}

	/* fetchDBQueryResult()
	 fetching a database query result
	 @param query
	*/	
	public static function fetchDBQueryResult($query) {   
		$array = [];

		if ($query instanceof mysqli_stmt) {
			$query->store_result();
		   
			$variables = [];
			$data = [];
			$meta = $query->result_metadata();
		   
			while ($field = $meta->fetch_field()) {
				$variables[] = &$data[$field->name];
			}
		   
			call_user_func_array([$query, 'bind_result'], $variables);
		   
			$i = 0;
			while ($query->fetch()) {
				$array[$i] = [];
				foreach ($data as $k => $v)
					$array[$i][$k] = $v;
				$i++;
			}
		} elseif ($query instanceof mysqli_result) {
			while($row = $query->fetch_assoc()) {
				$array[] = $row;
			}
		}
		return $array;
	}
}
?>