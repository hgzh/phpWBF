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

	/* executeDBQuery()
	 creates a prepared statement and directly executes a database query
	 
	 @param (1) query string
	 @param (2) reference types (first parameter of mysqli's bind_param)
	 @param (3...) references (following parameters of mysqli's bind_param)
	*/
	public function executeDBQuery() {

		// at least 1 parameter is required
		$numParam = func_num_args();
		if ( $numParam < 1 ) {
			throw new Exception( 'executeQuery: wrong parameter count' );
		}

		// get all parameters
		$parList = func_get_args();

		$query = $this->prepare( $parList[0] );
		if ( $query === false ) {
			throw new Exception( $this->error, $this->errno );
		}

		// strip first parameter, hand the rest to bind_param
		unset( $parList[0] );

		// only, if parameters are left
		if ( count( $parList ) != 0 ) {
			call_user_func_array( [$query, 'bind_param'], $this->refValues( $parList ) );
		}

		// execute query
		$query->execute();

		// return statement object
		return $query;
	}	
	
	/* fetchDBQueryResult()
	 fetching a database query result
	 @param query
	*/	
	public static function fetchDBQueryResult( $query ) {   
		$array = [];

		if ( $query instanceof \mysqli_stmt ) {
			// mysqli_stmt

			// statement metadata
			$query->store_result();
			$variables = [];
			$data      = [];
			$meta      = $query->result_metadata();

			// sql field names
			while ( $field = $meta->fetch_field() ) {
				$variables[] = &$data[$field->name];
			}

			// bind results to field names
			call_user_func_array( [$query, 'bind_result'], $variables );
			
			// get data
			$i = 0;
			while ( $query->fetch() ) {
				$array[$i] = [];
				foreach ( $data as $k => $v )
					$array[$i][$k] = $v;
				$i++;
			}
			
		} elseif ( $query instanceof \mysqli_result ) {
			// mysqli_result

			// get all lines
			while ( $row = $query->fetch_assoc() ) {
				$array[] = $row;
			}
		}

		// return result array
		return $array;
	}
	
	/* refValues()
	  passes raw data by reference
	 
	  @param arr input array
	*/
	private function refValues( $arr ){
		if ( strnatcmp( phpversion(), '5.3' ) >= 0) {
			$refs = [];
			foreach ( $arr as $k1 => $v1 )
				$refs[$k1] = &$arr[$k1];
			return $refs;
		}
		return $arr;
	}	
}
?>