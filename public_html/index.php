<?php
declare(strict_types=1);

error_reporting(-1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$useDb = file_exists('../replica.my.cnf');

if ($useDb) {
	$ini = parse_ini_file('../replica.my.cnf');
	$db = mysqli_connect('wikidatawiki.analytics.db.svc.eqiad.wmflabs', $ini['user'], $ini['password'], 'wikidatawiki_p');
	if ($db === false) {
		$useDb = false;
	}
}

echo json_encode((object)translateLinks(
	isset($_REQUEST['p']) ? (is_array($_REQUEST['p']) ? $_REQUEST['p'] : explode('|', $_REQUEST['p'])) : [],
	$_REQUEST['from'] ?? 'enwiki',
	$_REQUEST['to'] ?? 'fawiki',
	filter_var($_REQUEST['missings'] ?? '', FILTER_VALIDATE_BOOLEAN),
	$_REQUEST['fromCategory'] ?? null, # source wiki category
	$_REQUEST['notToCategory'] ?? null, # destination wiki cateegory
	$useDb
), JSON_UNESCAPED_UNICODE);

if ($useDb) {
	mysqli_close($db);
}

function translateLinks(array $pages, string $fromWiki, string $toWiki, bool $missings, ?string $fromCategory, ?string $notToCategory, bool $useDb): array {
	if (count($pages) === 0 && $fromCategory === null) {
		return ['#documentation' => 'A service to translate links based on Wikipedia language links, use it like: ?p=Earth|Moon|Human|Water&from=en&to=de Source: github.com/ebraminio/linkstranslator'];
	}

	$fromWiki = strtolower($fromWiki);
	if (preg_match('/^[a-z_]{1,20}$/', $fromWiki) === 0) { return ['#error' => 'Invalid "from" is provided']; };
	if (preg_match('/.wiki/', $fromWiki) === 0) { $fromWiki = $fromWiki . 'wiki'; }

	if ($fromCategory !== null) {
		if (!$useDb) { return ['#error' => 'Currently not supported without db access']; }
		$pages = getPagesOfCategorySQL($fromCategory, $fromWiki);
	}

	if ($toWiki === 'info') {
		return $useDb
			? getLinksInfoSQL(array_values($pages), $fromWiki)
			: getLinksInfo(array_values($pages), $fromWiki);
	}

	$toWiki = strtolower($toWiki);
	if (preg_match('/^[a-z_]{1,20}$/', $toWiki) === 0) { return ['#error' => 'Invalid "to" is provided']; };
	if (preg_match('/.wiki/', $toWiki) === 0) { $toWiki = $toWiki . 'wiki'; }

	$pages = array_unique($pages);

	$titlesMap = resolvePages($pages, $fromWiki);

	if ($fromWiki === $toWiki) {
		return $titlesMap;
	}

	$resolvedPages = array_unique(array_values($titlesMap));

	if ($toWiki === 'wikidatawiki') {
		$equs = $useDb
			? getWikidataIdSQL($resolvedPages, $fromWiki)
			: getWikidataId($resolvedPages, $fromWiki);
	} elseif ($toWiki === 'imdbwiki') {
		$equs = getImdbIdWikidata($resolvedPages, $fromWiki);
	} elseif ($toWiki === 'unicodewiki') {
		$equs = getUnicodeWikidata($resolvedPages, $fromWiki);
	} else {
		$equs = $useDb
			? getLocalNamesFromWikidataSQL($resolvedPages, $fromWiki, $toWiki)
			: getLocalNamesFromWikidata($resolvedPages, $fromWiki, $toWiki);
	}

	$result = [];
	foreach ($titlesMap as $p => $r) {
		if (isset($equs[$r])) {
			$result[$p] = $equs[$r];
		}
	}

	if ($missings) {
		$missingsPages = array_diff($resolvedPages, array_keys($equs));
		$missingsStats = $useDb
			? getLinksInfoSQL($missingsPages, $fromWiki)
			: getLinksInfo($missingsPages, $fromWiki);

		$missingsResult = [];
		foreach ($titlesMap as $p => $r) {
			if (isset($missingsStats[$r])) {
				$missingsResult[$p] = $missingsStats[$r];
			}
		}

		$result['#missings'] = $missingsResult;
	}

	if ($notToCategory !== null) {
		if (!$useDb) { return ['#error' => 'Currently not supported without db access']; }
		$pagesInCategory = getPagesOfCategorySQL($notToCategory, $toWiki);
		$filteredResult = [];
		foreach ($result as $p => $r) {
			if (!in_array($r, $pagesInCategory))
				$filteredResult[$p] = $r;
		}
		$result = $filteredResult;
	}

	return $result;
}

function getLinksInfo(array $pages, string $fromWiki): array {
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

function getLinksInfoSQL(array $rawPages, string $fromWiki): array {
	global $ini, $db;

	$pages = [];
	foreach ($rawPages as $p) {
		$pages[] = mysqli_real_escape_string($db, $p);
	}

	$localDb = mysqli_connect($fromWiki . '.analytics.db.svc.eqiad.wmflabs', $ini['user'], $ini['password'], $fromWiki . '_p');

	$localPages = [];
	foreach ($pages as $p) {
		$localPages[] = str_replace(' ', '_', $p);
	}

	$query = "
SELECT lt_title, COUNT(*)
FROM pagelinks INNER JOIN linktarget ON pl_target_id = lt_id
WHERE pl_from_namespace = 0 AND lt_namespace = 0 AND lt_title IN ('" . implode("', '", $localPages) . "')
GROUP BY lt_title;
";
	$dbResult = mysqli_query($localDb, $query);
	if (!$dbResult) {
		error_log(mysqli_error($localDb));
		error_log($query);
		return getLinksInfo($rawPages, $fromWiki);
	}
	$backlinks = [];
	while ($match = $dbResult->fetch_row()) {
		$backlinks[str_replace('_', ' ', $match[0])] = $match[1];
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
	if (!$dbResult) {
		error_log(mysqli_error($db));
		error_log($query);
		return getLinksInfo($rawPages, $fromWiki);
	}
	$langlinks = [];
	while ($match = $dbResult->fetch_row()) {
		$langlinks[$match[0]] = $match[1];
	}
	mysqli_free_result($dbResult);

	// merge results
	$missings = [];
	foreach ($pages as $p) {
		$missings[$p] = [
			'langlinks' => isset($langlinks[$p]) ? $langlinks[$p] - 1 : 0,
			'links' => isset($backlinks[$p]) ? $backlinks[$p] + 0 : 0
		];
	}
	return $missings;
}

function getPagesOfCategorySQL(string $p, string $fromWiki): array {
	global $ini;

	$localDb = mysqli_connect($fromWiki . '.analytics.db.svc.eqiad.wmflabs', $ini['user'], $ini['password'], $fromWiki . '_p');
	$p = mysqli_real_escape_string($localDb, str_replace(' ', '_', $p));
	$query = "
SELECT page_title
FROM categorylinks T1 INNER JOIN page T2 ON cl_from = page_id
WHERE cl_to = \"$p\" AND page_namespace = 0
";
	$dbResult = mysqli_query($localDb, $query);
	if (!$dbResult) {
		error_log(mysqli_error($localDb));
		error_log($query);
		return [];
	}
	$result = [];
	while ($match = $dbResult->fetch_row()) {
		$result[] = str_replace('_', ' ', $match[0]);
	}
	return $result;
}

function getImdbIdWikidata(array $pages, string $fromWiki): array {
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
		$from = $entity['sitelinks'][$fromWiki]['title'] ?? $entity['sitelinks'][$fromWiki];

		if (!isset($entity['claims']['P345'][0]['mainsnak']['datavalue']['value']))
			continue;

		$to = $entity['claims']['P345'][0]['mainsnak']['datavalue']['value'];

		$equs[$from] = $to;
	}
	return $equs;
}

function getUnicodeWikidata(array $pages, string $fromWiki): array {
	$apiResultArray = batchApi('wikidatawiki', $pages, function ($batch) use ($fromWiki) {
		return $fromWiki === 'wikidatawiki' ? [
			'action' => 'wbgetentities',
			'format' => 'json',
			'ids' => implode('|', $batch),
			'props' => 'claims'
		] : [
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
		if ($fromWiki === 'wikidatawiki') {
			$from = $entity['id'];
		} else {
			if (!isset($entity['sitelinks'])) { continue; }

			// not updated Wikidata items may don't have title on their sitelinks
			$from = $entity['sitelinks'][$fromWiki]['title'] ?? $entity['sitelinks'][$fromWiki];
		}

		if (!isset($entity['claims']['P487'][0]['mainsnak']['datavalue']['value']))
			continue;

		$to = $entity['claims']['P487'][0]['mainsnak']['datavalue']['value'];

		$equs[$from] = $to;
	}
	return $equs;
}

function getWikidataId(array $pages, string $fromWiki): array {
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
		$from = $entity['sitelinks'][$fromWiki]['title'] ?? $entity['sitelinks'][$fromWiki];

		$equs[$from] = $entity['id'];
	}
	return $equs;
}

function getWikidataIdSQL(array $rawPages, string $fromWiki): array {
	global $db;

	$pages = [];
	foreach ($rawPages as $p) {
		$pages[] = mysqli_real_escape_string($db, $p);
	}

	$query = "
SELECT CONCAT('Q', ips_item_id), ips_site_page
FROM wb_items_per_site
WHERE ips_site_page IN ('" . implode("', '", $pages) . "') AND ips_site_id = '$fromWiki'
";
	$dbResult = mysqli_query($db, $query);
	if (!$dbResult) {
		error_log(mysqli_error($db));
		error_log($query);
		return getWikidataId($rawPages, $fromWiki);
	}
	$equs = [];
	while ($match = $dbResult->fetch_row()) {
		$equs[$match[1]] = $match[0];
	}
	mysqli_free_result($dbResult);
	return $equs;
}

function getLocalNamesFromWikidataSQL(array $rawPages, string $fromWiki, string $toWiki): array {
	global $db;

	$pages = [];
	if ($fromWiki === 'wikidatawiki') {
		foreach ($rawPages as $p) {
			$pages[] = mysqli_real_escape_string($db, str_replace('Q', '', $p));
		}

		$query = "
SELECT ips_site_page, CONCAT('Q', ips_item_id)
FROM wb_items_per_site
WHERE ips_site_id = '$toWiki' AND ips_item_id IN ('" . implode("', '", $pages) . "')
";
	} else {
		foreach ($rawPages as $p) {
			$pages[] = mysqli_real_escape_string($db, $p);
		}

		$query = "
SELECT T2.ips_site_page, T1.ips_site_page
FROM wb_items_per_site T1 INNER JOIN wb_items_per_site T2 ON T1.ips_item_id = T2.ips_item_id AND T2.ips_site_id = '$toWiki'
WHERE T1.ips_site_id = '$fromWiki' AND T1.ips_site_page IN ('" . implode("', '", $pages) . "')
";
	}
	$dbResult = mysqli_query($db, $query);
	if (!$dbResult) {
		error_log(mysqli_error($db));
		error_log($query);
		return getLocalNamesFromWikidata($rawPages, $fromWiki, $toWiki);
	}
	if (!$dbResult) { return []; }
	$equs = [];
	while ($match = $dbResult->fetch_row()) {
		$equs[$match[1]] = $match[0];
	}
	mysqli_free_result($dbResult);
	return $equs;
}

function getLocalNamesFromWikidata(array $pages, string $fromWiki, string $toWiki): array {
	$apiResultArray = batchApi('wikidatawiki', $pages, function ($batch) use ($fromWiki) {
		return $fromWiki === 'wikidatawiki' ? [
			'action' => 'wbgetentities',
			'format' => 'json',
			'ids' => implode('|', $batch),
			'props' => 'sitelinks'
		] : [
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

		if ($fromWiki === 'wikidatawiki') {
			$from = $entity['id'];
		} else {
			// not updated Wikidata items may don't have title on their sitelinks
			$from = $entity['sitelinks'][$fromWiki]['title'] ?? $entity['sitelinks'][$fromWiki];
		}
		$to = $entity['sitelinks'][$toWiki]['title'] ?? $entity['sitelinks'][$toWiki];

		$equs[$from] = $to;
	}
	return $equs;
}

function resolvePages(array $pages, string $fromWiki): array {
	$apiResultArray = batchApi($fromWiki, $pages, function ($batch) {
		return [
			'action' => 'query',
			'format' => 'json',
			'redirects' => '',
			'titles' => implode('|', $batch)
		];
	});

	$normalizes = [];
	$redirects = [];
	$missings = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (!is_array($json) || !isset($json['query']['pages'])) { continue; }
		$query = $json['query'];
		$queryPages = $query['pages'];
		if (isset($query['normalized'])) {
			foreach ($query['normalized'] as $x) {
				$normalizes[$x['from']] = $x['to'];
			}
		}
		if (isset($query['redirects'])) {
			foreach ($query['redirects'] as $x) {
				$redirects[$x['from']] = $x['to'];
			}
		}
		foreach ($queryPages as $x) {
			if (isset($x['missing'])) {
				$missings[] = $x['title'];
			}
		}
	}

	$result = [];
	foreach ($pages as $p) {
		if (!in_array($p, $missings)) {
			$resolved = $p;
			if (isset($normalizes[$resolved])) {
				$resolved = $normalizes[$resolved];
			}
			if (isset($redirects[$resolved])) {
				$resolved = $redirects[$resolved];
			}
			$result[$p] = $resolved;
		}
	}
	return $result;
}

function dbNameToOrigin(string $dbName): string {
	if ($dbName === 'wikidatawiki') { return 'www.wikidata.org'; }
	if ($dbName === 'commonswiki') { return 'commons.wikimedia.org'; }
	$p = explode('wiki', $dbName);
	return str_replace('_', '-', $p[0]) . '.wiki' . (isset($p[1]) && strlen($p[1]) ? $p[1] : 'pedia') . '.org';
}

function batchApi(string $dbName, array $pages, callable $requestCreator): array {
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
function multiRequest(array $data, array $options = array()): array {

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
