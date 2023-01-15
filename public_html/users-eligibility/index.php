<?php
declare(strict_types=1);
error_reporting(-1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode(main(
    +($_REQUEST['timestamp'] ?? '0'),
    isset($_REQUEST['usernames']) ? explode('|', $_REQUEST['usernames']) : [],
    $_REQUEST['dbname'] ?? 'enwiki',
));

function main(int $timestamp, array $rawUsernames, string $dbName): array {
    if ($timestamp === 0 || count($rawUsernames) === 0 || preg_match('/^[a-z_]{1,20}$/', $dbName) !== 1) {
        return ['#documentation' => 'Checks users eligibility, use it like ?usernames=Salgo60|Fabian_Roudra_Baroi&timestamp=1673719206937&dbname=enwiki Source: github.com/ebraminio/linkstranslator'];
    }

    $ini = parse_ini_file('../../replica.my.cnf');
    $db = mysqli_connect("$dbName.analytics.db.svc.eqiad.wmflabs", $ini['user'], $ini['password'], "${dbName}_p");

    $usernames = [];
    foreach ($rawUsernames as $u) {
        $usernames[] = mysqli_real_escape_string($db, str_replace("_", " ", $u));
    }

    # The following queries are borrowed initially from https://github.com/Huji/toolforge/blob/master/eliminator.php
    $actors = fetch_query($db, "
SELECT actor_name, actor_id
FROM actor
WHERE actor_name IN ('" . implode("', '", $usernames) . "')
");
    if (count($actors) === 0) return [];
    $actorSql = implode(", ", array_values($actors));
    $creationTime = fetch_query($db, "
SELECT log_actor, UNIX_TIMESTAMP(MIN(log_timestamp)) * 1000
FROM logging_userindex
WHERE log_type = 'newusers'
  AND log_action IN ('create', 'autocreate')
  AND log_actor IN ($actorSql)
GROUP BY log_actor
");
    # TODO: query only those without a creation log
    foreach (fetch_query($db, "
SELECT rev_actor, UNIX_TIMESTAMP(MIN(rev_timestamp)) * 1000
FROM revision_userindex
WHERE rev_actor IN ($actorSql)
GROUP BY rev_actor
") as $user => $firstEdit) {
        if (!isset($creationTime[$user]))
            $creationTime[$user] = $firstEdit;
    }
    $sixMonthsEdits = fetch_query($db, "
SELECT rev_actor, COUNT(*)
FROM revision_userindex JOIN page ON page_id = rev_page AND page_namespace = 0
WHERE rev_timestamp > DATE_SUB(FROM_UNIXTIME($timestamp / 1000), INTERVAL 6 MONTH)
  AND rev_timestamp < FROM_UNIXTIME($timestamp / 1000)
  AND rev_actor IN ($actorSql)
GROUP BY rev_actor
");

    $result = [];
    foreach ($actors as $user => $id) {
        $result[$user] = ['creationTime' => +$creationTime[$id], 'sixMonthsEdits' => +$sixMonthsEdits[$id]];
    }

    return $result;
}

function fetch_query(mysqli $db, string $query) {
    $q = mysqli_query($db, $query);
    $result = [];
    while ($row = $q->fetch_row()) $result[$row[0]] = $row[1];
    mysqli_free_result($q);
    return $result;
}
