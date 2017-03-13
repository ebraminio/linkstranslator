<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$USE_SQL = file_exists('../replica.my.cnf');

if ($USE_SQL) {
	$ini = parse_ini_file('../replica.my.cnf');
	$db = mysqli_connect('enwiki.labsdb', $ini['user'], $ini['password'], 'wikidatawiki_p');
	if ($db === false) {
		$USE_SQL = false;
	}
}

$json = json_encode(translateLinks(
	isset($_REQUEST['p']) ? (is_array($_REQUEST['p']) ? $_REQUEST['p'] : explode('|', $_REQUEST['p'])) : [],
	isset($_REQUEST['from']) ? $_REQUEST['from'] : 'enwiki',
	isset($_REQUEST['to']) ? $_REQUEST['to'] : 'fawiki',
	isset($_REQUEST['missings']) ? $_REQUEST['missings'] === 'true' : false
));
echo $json !== '[]' ? $json : '{}';

if ($USE_SQL) {
	mysqli_close($db);
}

function translateLinks($pages, $fromWiki, $toWiki, $missings) {
	global $USE_SQL, $db;

	// sanitize inputs
	$fromWiki = strtolower($fromWiki);
	if (preg_match('/^[a-z_]{1,20}$/', $fromWiki) === 0) { return []; };
	if (preg_match('/wiki$/', $fromWiki) === 0) { $fromWiki = $fromWiki . 'wiki'; }
	$toWiki = strtolower($toWiki);
	if (preg_match('/^[a-z_]{1,20}$/', $toWiki) === 0) { return []; };
	if (preg_match('/wiki$/', $toWiki) === 0) { $toWiki = $toWiki . 'wiki'; }

	$pages = array_unique($pages);

	if ($USE_SQL) {
		$fromWiki = mysqli_real_escape_string($db, $fromWiki);
		$toWiki = mysqli_real_escape_string($db, $toWiki);
	}
	//

	$redirects = [];
	$resolvedPages = getResolvedRedirectPages($pages, $fromWiki, $redirects);

	if ($toWiki === "wikidatawiki") {
		$equs = $USE_SQL
			? getWikidataIdSQL($resolvedPages, $fromWiki)
			: getWikidataId($resolvedPages, $fromWiki);
	} elseif ($toWiki === "imdbwiki") {
		$equs = getImdbIdWikidata($resolvedPages, $fromWiki);
	} else {
		$equs = $USE_SQL
			? getLocalNamesFromWikidataSQL($resolvedPages, $fromWiki, $toWiki)
			: getLocalNamesFromWikidata($resolvedPages, $fromWiki, $toWiki);
	}

	$result = [];
	foreach ($pages as $i) {
		$page = isset($redirects[$i]) ? $redirects[$i] : $i;
		if (isset($equs[$page])) {
			$result[$i] = $equs[$page];
		}

		$i = str_replace('_', ' ', $i);
		$page = isset($redirects[$i]) ? $redirects[$i] : $i;
		if (isset($equs[$page])) {
			$result[$i] = $equs[$page];
		}
	}

	if ($missings) {
		$missingsPages = array_diff($resolvedPages, array_keys($equs));
		$missingsStats = $USE_SQL
			? getMissingsInfoSQL($fromWiki, $missingsPages)
			: getMissingsInfo($fromWiki, $missingsPages);

		$missingsResult = [];
		foreach ($pages as $i) {
			$page = isset($redirects[$i]) ? $redirects[$i] : $i;
			if (isset($missingsStats[$page])) {
				$missingsResult[$i] = $missingsStats[$page];
			}

			$i = str_replace('_', ' ', $i);
			$page = isset($redirects[$i]) ? $redirects[$i] : $i;
			if (isset($missingsStats[$page])) {
				$missingsResult[$i] = $missingsStats[$page];
			}
		}

		$result['#missings'] = $missingsResult;
	}

	return $result;
}

function getMissingsInfo($fromWiki, $pages) {
	$host = dbNameToOrigin($fromWiki);
	$apiResult = multiRequest(array_map(function ($page) use ($host) {
		return [
			'url' => 'https://' . $host . '/w/api.php',
			'post' => http_build_query([
				'action' => 'query',
				'format' => 'json',
				'prop' => 'langlinks|links',
				'redirects' => '',
				'pllimit' => '500',
				'lllimit' => '500',
				'titles' => $page
			])
		];
	}, $pages));

	$missings = [];
	foreach ($apiResult as $a) {
		$x = json_decode($a, true);
		if (!isset($x['query']['pages'])) continue;
		$p = array_values($x['query']['pages'])[0];
		$e = $p['title'];
		if (isset($x['query']['redirects'])) $e = $x['query']['redirects'][0]['from'];
		if (isset($x['query']['normalized'])) $e = $x['query']['normalized'][0]['from'];
		$missings[$e] = [
			'langlinks' => isset($p['langlinks']) ? count($p['langlinks']) : 0,
			'links' => isset($p['links']) ? count($p['links']) : 0
		];
	}
	return $missings;
}

function getMissingsInfoSQL($fromWiki, $rawPages) {
	global $ini, $db;

	$pages = [];
	foreach ($rawPages as &$p) {
		$pages[] = mysqli_real_escape_string($db, $p);
	}

	$localDb = mysqli_connect('enwiki.labsdb', $ini['user'], $ini['password'], $fromWiki . '_p');

	$localPages = [];
	foreach ($pages as $p) {
		$localPages[] = str_replace(" ", "_", $p);
	}

	$query = "
SELECT pl_title, COUNT(*)
FROM pagelinks
WHERE pl_namespace = 0 AND pl_title IN ('" . implode("', '", $localPages) . "') GROUP BY pl_title;
";
	$dbResult = mysqli_query($localDb, $query);
	if (!$dbResult) { return []; }
	$backlinks = [];
	while ($match = $dbResult->fetch_row()) {
		$backlinks[str_replace("_", " ", $match[0])] = $match[1];
	}
	mysqli_free_result($dbResult);
	mysqli_close($localDb);

	$query = "
SELECT T1.ips_site_page, COUNT(*)
FROM wb_items_per_site T1 INNER JOIN wb_items_per_site T2 ON T1.ips_item_id = T2.ips_item_id
WHERE T1.ips_site_id = '$fromWiki' AND T1.ips_site_page IN ('" . implode("', '", $pages) . "')
GROUP BY T1.ips_site_page
";
	$dbResult = mysqli_query($db, $query);
	if (!$dbResult) { return []; }
	$langlinks = [];
	while ($match = $dbResult->fetch_row()) {
		$langlinks[$match[0]] = $match[1];
	}
	mysqli_free_result($dbResult);

	// merge results
	foreach ($pages as $p) {
		$missings[$p] = [
			'langlinks' => isset($langlinks[$p]) ? $langlinks[$p] - 1 : 0,
			'links' => isset($backlinks[$p]) ? $backlinks[$p] + 0 : 0
		];
	}
	return $missings;
}

function getImdbIdWikidata($pages, $fromWiki) {
	$apiResultArray = batchApi('wikidatawiki', $pages, function ($batch) use ($fromWiki) {
		return [
			'action' => 'wbgetentities',
			'format' => 'json',
			'sites' => $fromWiki,
			'titles' => implode('|', $batch),
			'props' => 'sitelinks|claims'
		];
	});
	$entities = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (isset($json['entities'])) {
			foreach ($json['entities'] as $entity) {
				$entities[] = $entity;
			}
		}
	}

	$equs = [];
	foreach ($entities as $entity) {
		if (!isset($entity['sitelinks'])) { continue; }

		// not updated Wikidata items may don't have title on their sitelinks
		$from = isset($entity['sitelinks'][$fromWiki]['title'])
			? $entity['sitelinks'][$fromWiki]['title']
			: $entity['sitelinks'][$fromWiki];

		if (!isset($entity['claims']['P345'][0]['mainsnak']['datavalue']['value']))
			continue;

		$to = $entity['claims']['P345'][0]['mainsnak']['datavalue']['value'];

		$equs[$from] = $to;
	}
	return $equs;
}

function getWikidataId($pages, $fromWiki) {
	$apiResultArray = batchApi('wikidatawiki', $pages, function ($batch) use ($fromWiki) {
		return [
			'action' => 'wbgetentities',
			'format' => 'json',
			'sites' => $fromWiki,
			'titles' => implode('|', $batch),
			'props' => 'sitelinks'
		];
	});
	$entities = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (isset($json['entities'])) {
			foreach ($json['entities'] as $entity) {
				$entities[] = $entity;
			}
		}
	}

	$equs = [];
	foreach ($entities as $entity) {
		// not updated Wikidata items may don't have title on their sitelinks
		$from = isset($entity['sitelinks'][$fromWiki]['title'])
			? $entity['sitelinks'][$fromWiki]['title']
			: $entity['sitelinks'][$fromWiki];

		$equs[$from] = $entity['id'];
	}
	return $equs;
}

function getWikidataIdSQL($rawPages, $fromWiki) {
	global $db;

	$pages = [];
	foreach ($rawPages as &$p) {
		$pages[] = mysqli_real_escape_string($db, $p);
	}

	$query = "
SELECT CONCAT('Q', ips_item_id), ips_site_page
FROM wb_items_per_site
WHERE ips_site_page IN ('" . implode("', '", $pages) . "') AND ips_site_id = '$fromWiki'
";
	$dbResult = mysqli_query($db, $query);
	if (!$dbResult) { return []; }
	$equs = [];
	while ($match = $dbResult->fetch_row()) {
		$equs[$match[1]] = $match[0];
	}
	mysqli_free_result($dbResult);
	return $equs;
}

function getLocalNamesFromWikidataSQL($rawPages, $fromWiki, $toWiki) {
	global $db;

	$pages = [];
	foreach ($rawPages as &$p) {
		$pages[] = mysqli_real_escape_string($db, $p);
	}

	$query = "
SELECT T2.ips_site_page, T1.ips_site_page
FROM wb_items_per_site T1 INNER JOIN wb_items_per_site T2 ON T1.ips_item_id = T2.ips_item_id AND T2.ips_site_id = '$toWiki'
WHERE T1.ips_site_id = '$fromWiki' AND T1.ips_site_page IN ('" . implode("', '", $pages) . "')
";
	$dbResult = mysqli_query($db, $query);
	if (!$dbResult) { return []; }
	$equs = [];
	while ($match = $dbResult->fetch_row()) {
		$equs[$match[1]] = $match[0];
	}
	mysqli_free_result($dbResult);
	return $equs;
}

function getLocalNamesFromWikidata($pages, $fromWiki, $toWiki) {
	$apiResultArray = batchApi('wikidatawiki', $pages, function ($batch) use ($fromWiki) {
		return [
			'action' => 'wbgetentities',
			'format' => 'json',
			'sites' => $fromWiki,
			'titles' => implode('|', $batch),
			'props' => 'sitelinks'
		];
	});
	$entities = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (isset($json['entities'])) {
			foreach ($json['entities'] as $entity) {
				$entities[] = $entity;
			}
		}
	}

	$equs = [];
	foreach ($entities as $entity) {
		if (!isset($entity['sitelinks']) || !isset($entity['sitelinks'][$toWiki])) { continue; }

		// not updated Wikidata items may don't have title on their sitelinks
		$from = isset($entity['sitelinks'][$fromWiki]['title'])
			? $entity['sitelinks'][$fromWiki]['title']
			: $entity['sitelinks'][$fromWiki];
		$to = isset($entity['sitelinks'][$toWiki]['title'])
			? $entity['sitelinks'][$toWiki]['title']
			: $entity['sitelinks'][$toWiki];

		$equs[$from] = $to;
	}
	return $equs;
}

function getResolvedRedirectPages($pages, $fromWiki, &$redirects) {
	$apiResultArray = batchApi($fromWiki, $pages, function ($batch) {
		return [
			'action' => 'query',
			'format' => 'json',
			'redirects' => '',
			'titles' => implode('|', $batch)
		];
	});
	$titles = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (!is_array($json) || !isset($json['query']['pages'])) { continue; }
		$query = $json['query'];
		$queryPages = $query['pages'];
		if (isset($query['redirects'])) {
			foreach ($query['redirects'] as $x) {
				$redirects[$x['from']] = $x['to'];
			}
		}
		if (isset($query['normalized'])) {
			foreach ($query['normalized'] as $x) {
				$redirects[$x['from']] = $x['to'];
			}
		}
		if (isset($query['redirects']) && isset($query['normalized'])) {
			foreach ($query['normalized'] as $x) {
				if (isset($redirects[$x['to']])) {
					$redirects[$x['from']] = $redirects[$x['to']];
				}
			}
		}
		foreach ($queryPages as $x) {
			if (!isset($x['missing'])) {
				$titles[] = $x['title'];
			}
		}
	}
	return $titles;
}

function dbNameToOrigin($dbName) {
	if ($dbName === 'wikidatawiki') { return 'www.wikidata.org'; }
	if ($dbName === 'commonswiki') { return 'commons.wikimedia.org'; }
	$p = explode('wiki', $dbName);
	return str_replace("_", "-", $p[0]) . '.wiki' . (isset($p[1]) && strlen($p[1]) ? $p[1] : 'pedia') . '.org';
}

function batchApi($dbName, $pages, $requestCreator) {
	$host = dbNameToOrigin($dbName);
	$batches = array_chunk($pages, 50);
	return multiRequest(array_map(function ($data) use ($host, $requestCreator) {
		return [
			'url' => 'https://' . $host . '/w/api.php',
			'post' => $requestCreator($data)
		];
	}, $batches));
}

// http://www.phpied.com/simultaneuos-http-requests-in-php-with-curl/
function multiRequest($data, $options = array()) {
 
  // array of curl handles
  $curly = array();
  // data to be returned
  $result = array();
 
  // multi handle
  $mh = curl_multi_init();
 
  // loop through $data and create curl handles
  // then add them to the multi-handle
  foreach ($data as $id => $d) {
 
    $curly[$id] = curl_init();
 
    $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
    curl_setopt($curly[$id], CURLOPT_URL,            $url);
    curl_setopt($curly[$id], CURLOPT_HEADER,         0);
    curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curly[$id], CURLOPT_USERAGENT,      'linkstranslator (github.com/ebraminio/linkstranslator)');

 
    // post?
    if (is_array($d)) {
      if (!empty($d['post'])) {
        curl_setopt($curly[$id], CURLOPT_POST,       1);
        curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $d['post']);
      }
    }
 
    // extra options?
    if (!empty($options)) {
      curl_setopt_array($curly[$id], $options);
    }
 
    curl_multi_add_handle($mh, $curly[$id]);
  }
 
  // execute the handles
  $running = null;
  do {
    curl_multi_exec($mh, $running);
  } while($running > 0);
 
 
  // get content and remove handles
  foreach($curly as $id => $c) {
    $result[$id] = curl_multi_getcontent($c);
    curl_multi_remove_handle($mh, $c);
  }
 
  // all done
  curl_multi_close($mh);
 
  return $result;
}
