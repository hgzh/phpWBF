<?php
class phpWBF {
	
	/* curlHandle
	 contains the handle of the current curl session
	 @var int
	*/
	protected $curlHandle;
	
	/* site
	 contains the current site name
	 @var string
	*/
	protected $site;

	/* tokens
	 protocol used for requests (https or http)
	 @var string[string]
	*/
	protected $tokens = [];
	
	/* __construct()
	 class constructor
	 initializes curl and the relevant class members
	 @param string pSite
	*/
	public function __construct($pSite) {
		
		// set site name
		$this->site = $pSite;
		
		// init curl
		$curl = curl_init();
		if ($curl === false) {
			throw new Exception('curl initialization failed.');
		} else {
			$this->curlHandle = $curl;
		}
	}
	
	/* __destruct()
	 class destructor
	 closes curl
	*/
	public function __destruct() {
		curl_close($this->curlHandle);
	}

	/* log()
	 log action
	*/	
	public function log($pText) {
		echo "# (" . date('Y-m-d H:i:s') . ") . . " . $pText . "\r\n";
	}
	
	/* httpRequest()
	 sends a http request to the MediaWiki api using curl
	 @param array pArguments
	 @param string pMethod
	 @param string pTarget
	*/
	public function httpRequest($pArguments, $pMethod = 'POST', $pTarget = 'w/api.php') {
		
		// set base url
		$baseURL = 'https://' . 
				   $this->site . '/' . 
				   $pTarget;
		
		// parse argument string
		if (is_array($pArguments)) {
			$argString = 'format=json';
			foreach ($pArguments as $key => $val) {
				$argString .= '&' . strtolower($key);
				$argString .= '=' . $val;
			}
		} elseif (is_string($pArguments)) {
			$argString = $pArguments;
		} else {
			throw new Exception('unknown argument type');
		}
						
		// extend request with arguments
		$pMethod = strtoupper($pMethod);
		if ($argString !== '') {
			if ($pMethod === 'POST') {
				$requestURL = $baseURL;
				$postFields = $argString;
			} elseif ($pMethod === 'GET') {
				$requestURL = $baseURL . '?' .
							  $argString;
			} else {
				throw new Exception('unknown http request method.');
			}
		}
		
		if (!$requestURL) {
			throw new Exception('no arguments for http request found.');
		}
		
		// set curl options
		curl_setopt($this->curlHandle, CURLOPT_USERAGENT, 'phpWBF -- de:User:Hgzh');
		curl_setopt($this->curlHandle, CURLOPT_URL, $requestURL);
		curl_setopt($this->curlHandle, CURLOPT_ENCODING, "UTF-8");
		curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->curlHandle, CURLOPT_COOKIEFILE, realpath('Cookies.tmp'));
		curl_setopt($this->curlHandle, CURLOPT_COOKIEJAR, realpath('Cookies.tmp'));
		
		// if posted, add post fields
		if ($pMethod === 'POST' && $postFields != '') {
			curl_setopt($this->curlHandle, CURLOPT_POST, 1);
			curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, $postFields);
		} else {
			curl_setopt($this->curlHandle, CURLOPT_POST, 0);
			curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, '');
		}
		
		// perform request
		$rqResult = curl_exec($this->curlHandle);
		if ($rqResult === false) {
			throw new Exception('curl request failed: ' . curl_error($this->curlHandle));
		}
		
		return $rqResult;
	}
	
	/* requireToken()
	 managing tokens
	 @param string pType
	 @param boolean pForceUpdate
	*/	
	public function requireToken($pType, $pForceUpdate = false) {
		static $prevType = '';
  
		// reuse last token type
		if ($pType === '' && $prevType !== '') {
			$pType = $prevType;
		}
		$prevType = $pType;
				
		if (!isset($this->tokens[$pType]) || $this->tokens[$pType] == '' || $pForceUpdate === true) {
			// new token
			try {
				$result = $this->httpRequest([
						'action' => 'query',
						'meta'   => 'tokens',
						'type'   => $pType
					],
					'GET');
			} catch (Exception $e) {
				throw $e;
			}
			$tree  = json_decode($result, true);			
			$token = $tree['query']['tokens'][$pType . 'token'];
			if ($token === '') {
				throw new Exception('requireToken: no ' . $pType . 'token received.');
			}
			$this->tokens[$pType] = urlencode($token);
			return true;
		} else {
			// nothing got changed
			return false;
		}
	}
	
	/* loginUser()
	 performs a login to a MediaWiki site
	 @param string pUsername
	 @param string pPassword
	*/
	public function loginUser($pUsername, $pPassword) {
		
		$this->requireToken('login');
		
		// perform login
		try {
			$result = $this->httpRequest([
					'action'     => 'login',
					'lgname'     => urlencode($pUsername),
					'lgpassword' => urlencode($pPassword),
					'lgtoken'    => $this->tokens['login']
				]);
		} catch (Exception $e) {
			throw $e;
		}
		$tree     = json_decode($result, true);
		$lgResult = $tree['login']['result'];
		
		// manage result
		if ($lgResult == 'Success') {
			return true;
		} else {
			throw new Exception('login failed with message ' . $lgResult);
		}
	}
	
	/* loginProfile()
	 performs a login to a MediaWiki site using profile data
	 @param string pProfile
	*/
	public function loginProfile($pProfile) {
		$userinfo = posix_getpwuid(posix_getuid());
		
		$config = parse_ini_file($userinfo['dir'] . '/phpwbf-profile-' . $pProfile . '.cnf');
		
		$this->loginUser($config['user'], $config['password']);
		unset($config, $userinfo);
	}
	
	/* logoutUser()
	 closes the current login on a MediaWiki site
	*/
	public function logoutUser() {
		
		try {
			$this->httpRequest(['action' => 'logout']);
		} catch (Exception $e) {
			throw $e;
		}
		
	}

	/* getWikitext()
	 get the wikitext of given page
	 @param string/int pPage
	 @param int pOldID
	 @param boolean pRedirects
	*/	
	public function getWikitext($pPage, $pOldID = 0, $pRedirects = true) {
		
		// build basic request
		$request = [
						'action'  		=> 'parse',
						'prop'		    => 'wikitext',
						'formatversion' => 2
					];
		
		// page title, pageid or oldid
		if ($pOldID > 0) {
			$request['oldid'] = $pOldID;
		} elseif (is_numeric($pPage)) {
			$request['pageid'] = $pPage;
		} elseif (is_string($pPage)) {
			$request['page'] = urlencode($pPage);
		}
		
		// wheter to follow redirects
		if ($pRedirects === true && $pOldID === 0) {
			$request['redirects'] = 1;
		}
		
		// perform request
		try {
			$result = $this->httpRequest($request);
		} catch (Exception $e) {
			throw $e;
		}
		$tree = json_decode($result, true);
		$text = $tree['parse']['wikitext'];
		
		// return
		return $text;
		
	}
	
	/* editPage()
	 performs an edit on a MediaWiki page
	 @param string pTitle
	 @param string pNewText
	 @param string pSummary
	*/	
	public function editPage($pTitle, $pNewText, $pSummary) {
		
		$this->requireToken('csrf');
		
		// perform edit
		$request = [
						'action'  => 'edit',
						'title'   => urlencode($pTitle),
						'text'    => urlencode($pNewText),
						'token'   => $this->tokens['csrf'],
						'summary' => urlencode($pSummary)
					];
		try {
			$result = $this->httpRequest($request);
		} catch (Exception $e) {
			throw $e;
		}
		$tree    = json_decode($result, true);
		$editres = $tree['edit']['result'];
		
		// manage result
		if ($editres == 'Success') {
			return true;
		} else {
			throw new Exception('page edit failed with message ' . $editres);
		}
		
	}
	
	/* getEmbeddingsContent()
	 get embeddings of a page with their page content
	 @param string/int pPage
	 @param array pNs
	*/	
	public function getEmbeddingsContent($pPage, $pNs) {
		
		$pages     = [];
		$tree      = [];
		$cont      = [];
		$i     	   = 0;
		$qContinue = false;
		
		// initial request
		$stRequest = [
				'action'     	=> 'query',
				'generator'  	=> 'embeddedin',
				'prop'       	=> 'revisions',
				'rvprop'        => 'content',
				'formatversion' => 2,
				'geilimit'      => 100,
			];
					
		// page title or pageid
		if (is_numeric($pPage)) {
			$stRequest['geipageid'] = $pPage;
		} elseif (is_string($pPage)) {
			$stRequest['geititle'] = urlencode($pPage);
		}
		
		// namespaces
		$ns = '';
		foreach ($pNs as $val) {
			$ns .= $val . '|';
		}
		$ns = trim($ns, '|');
		if ($ns == '') {
			$ns = '*';
		}
		$stRequest['geinamespace'] = $ns;

		$request = $stRequest;		
		do {
			// perform request
			try {
				$result = $this->httpRequest($request, 'GET');
			} catch (Exception $e) {
				throw $e;
			}
			unset($tree);
			$tree = json_decode($result, true);
			
			// continuation
			if (isset($tree['batchcomplete'])) {
				unset($cont);
			}
			if (isset($tree['continue'])) {
				foreach ($tree['continue'] as $qcKey => $qcVal) {
					$cont[$qcKey] = $qcVal;
				}
			}
			if (isset($cont['continue'])) {
				$qContinue = true;
				$request   = $stRequest;
				foreach ($cont as $qcKey => $qcVal) {
					$request[$qcKey] = $qcVal;
				}
			} else {
				$qContinue = false;
			}
			
			if (!isset($tree['query']['pages'])) {
				continue;
			}
			
			// get page information
			foreach ($tree['query']['pages'] as $item) {
				// check if text given
				if (!isset($item['revisions'])) {
					continue;
				}
				$j = $i;
				// check if already existing
				foreach ($pages as $k => $v) {
					if (($v['id'] == $item['pageid']) && !isset($v['text'])) {
						$j = $k;
					}
				}
				$pages[$j]['title'] = $item['title'];
				$pages[$j]['ns']    = $item['ns'];
				$pages[$j]['id']    = $item['pageid'];
				if (isset($item['revisions'])) {
					$pages[$j]['text'] = $item['revisions'][0]['content'];
				}
				$i++;
			}
			
		} while ($qContinue == true);
		
		return $pages;
	}
	
	/* uniqueMultidimArray()
	 make multidimensional array unique
	 by Ghanshyam Katriya (anshkatriya at gmail)
	 http://php.net/manual/de/function.array-unique.php#116302
	 @param array array
	 @param string key
	*/
	public function uniqueMultidimArray($array, $key) {
		$temp_array = [];
		$key_array  = [];
		$i 			= 0;
	   
		foreach($array as $val) {
			if (!in_array($val[$key], $key_array)) {
				$key_array[$i] = $val[$key];
				$temp_array[$i] = $val;
			}
			$i++;
		}
		return $temp_array;
	}
	
	/* sortMultidimArray()
	 sort multidimensional array
	 by jimpoz at jimpoz dot com
	 http://de2.php.net/manual/de/function.array-multisort.php#100534
	*/
	public function sortMultidimArray() {
		$args = func_get_args();
		$data = array_shift($args);
		foreach ($args as $n => $field) {
			if (is_string($field)) {
				$tmp = [];
				foreach ($data as $key => $row)
					$tmp[$key] = $row[$field];
				$args[$n] = $tmp;
				}
		}
		$args[] = &$data;
		call_user_func_array('array_multisort', $args);
		return array_pop($args);
	}
	
	/* getCategoryMembersFlaggedInfo()
	 get members of a category with information about flagged revisions state
	 @param array pPages
	 @param string/value pPage
	 @param array pNs
	 @param array pType
	 @param int pDepth
	 @param bool pNoDupes
	 @param array pVisited
	*/	
	public function getCategoryMembersFlaggedInfo(&$pPages, $pPage, $pNs, $pType, $pDepth = 0, $pNoDupes = false, &$pVisited = []) {
		
		$pages     = [];
		$tree      = [];
		$cont      = [];
		$i     	   = 0;
		$qContinue = false;
		
		$datenowO = new DateTime();
		$datenow = $datenowO->getTimestamp();
		
		// initial request
		$stRequest = [
				'action'     	=> 'query',
				'generator'  	=> 'categorymembers',
				'prop'       	=> 'flagged',
				'formatversion' => 2,
				'gcmlimit'      => 500,
			];
					
		// page title or pageid
		if (is_numeric($pPage)) {
			$stRequest['gcmpageid'] = $pPage;
		} elseif (is_string($pPage)) {
			$stRequest['gcmtitle'] = urlencode($pPage);
		}
		
		// namespaces
		$ns = '';
		foreach ($pNs as $val) {
			$ns .= $val . '|';
		}
		$ns = trim($ns, '|');
		if ($ns == '') {
			$ns = '*';
		}
		$stRequest['gcmnamespace'] = $ns;
		
		// types (page, subcat, file)
		$type = '';
		foreach ($pType as $val) {
			$type .= $val . '|';
		}
		$type = trim($type, '|');
		if ($type == '') {
			$type = 'page|subcat|file';
		}
		$stRequest['gcmtype'] = $type;
		
		$request = $stRequest;
		do {						
			// perform request
			
			try {
				$result = $this->httpRequest($request, 'GET');
			} catch (Exception $e) {
				throw $e;
			}
			unset($tree);
			$tree = json_decode($result, true);
			
			// continuation
			if (isset($tree['batchcomplete'])) {
				unset($cont);
			}
			if (isset($tree['continue'])) {
				foreach ($tree['continue'] as $qcKey => $qcVal) {
					$cont[$qcKey] = $qcVal;
				}
			}
			if (isset($cont['continue'])) {
				$qContinue = true;
				$request   = $stRequest;
				foreach ($cont as $qcKey => $qcVal) {
					$request[$qcKey] = $qcVal;
				}
			} else {
				$qContinue = false;
			}
			
			if (!isset($tree['query']['pages'])) {
				continue;
			}
			
			// get page information
			foreach ($tree['query']['pages'] as $item) {
				
				// step into subcat
				if ($pDepth > 0 && $item['ns'] == 14) {
					$visited = false;
					foreach ($pVisited as $k => $v) {
						if ($v == $item['pageid']) {
							$visited = true;
						}
					}
					if ($visited == false) {
						$this->getCategoryMembersFlaggedInfo($pPages, $item['title'], $pNs, $pType, $pDepth - 1, $pNoDupes, $pVisited);
						$pVisited[] = $item['pageid'];
					}
				}
				
				unset($add);
				$add['title'] = $item['title'];
				$add['ns']    = $item['ns'];
				$add['id']    = $item['pageid'];
				if (isset($item['flagged'])) {
					if (isset($item['flagged']['pending_since'])) {
						$add['old']      = true;
						$add['oldsince'] = $item['flagged']['pending_since'];
						$dateold = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $add['oldsince']);
						$add['oldstamp'] = $dateold->getTimestamp();
						unset($dateold);
					} else {
						$add['old']      = false;
						$add['oldsince'] = false;
						$add['oldstamp'] = $datenow;
					}
				} else {
					$add['old']      = true;
					$add['oldsince'] = false;
					$add['oldstamp'] = 0;
				}
				$pPages[] = $add;
			}
			
		} while ($qContinue == true);
		
		if ($pNoDupes == true) {
			$pPages = $this->uniqueMultidimArray($pPages, 'id');
		}
	}

	/* getUnconnectedPages()
	 get unconnected pages on the wiki
	 @param array pNs
	 @param int pLimit
	 @param int pStartOffset
	 @param bool pSkipIwlinks
	*/		
	public function getUnconnectedPages($pNs, $pLimit = 1, $pStartOffset = 0, $pSkipIwlinks = true) {
		$pages     = [];
		$tree      = [];
		$cont      = [];
		$i     	   = 0;
		$qContinue = false;
		
		// initial request
		$stRequest = [
				'action'     	=> 'query',
				'list'  		=> 'querypage',
				'qppage'       	=> 'UnconnectedPages',
				'qpoffset'      => $pStartOffset,
				'formatversion' => 2,
			];
		
		// limit
		if ($pLimit > 500) {
			$stRequest['qplimit'] = 'max';
		} else {
			$stRequest['qplimit'] = $pLimit;
		}
		
		$request = $stRequest;		
		do {
			// perform request
			try {
				$result = $this->httpRequest($request, 'GET');
			} catch (Exception $e) {
				throw $e;
			}
			unset($tree);
			$tree = json_decode($result, true);
			
			// continuation
			if (isset($tree['batchcomplete'])) {
				unset($cont);
			}
			if (isset($tree['continue'])) {
				foreach ($tree['continue'] as $qcKey => $qcVal) {
					$cont[$qcKey] = $qcVal;
				}
			}
			if (isset($cont['continue'])) {
				$qContinue = true;
				$request   = $stRequest;
				foreach ($cont as $qcKey => $qcVal) {
					$request[$qcKey] = $qcVal;
				}
			} else {
				$qContinue = false;
			}
			
			if (!isset($tree['query']['querypage']['results'])) {
				continue;
			}
			
			// get information
			foreach ($tree['query']['querypage']['results'] as $item) {
				
				// check namespace
				if (array_search($item['ns'], $pNs) === false) {
					continue;
				}
				
				// check iwlinks
				if ($pSkipIwlinks === true && $item['databaseResult']['page_num_iwlinks'] > 0) {
					continue;
				}
				
				// append list
				$pages[$i]['id']    = $item['value'];
				$pages[$i]['ns']    = $item['ns'];
				$pages[$i]['title'] = $item['title'];
				
				$i++;
				
				// check for limit
				if ($i = $pLimit) {
					break 2;
				}
				
			}
			
		} while ($qContinue == true);
		
		return $pages;
	}
	
}

?>