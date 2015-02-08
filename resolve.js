var http = require('http');
var querystring = require('querystring');

function dbNameToOrigin(dbName) {
  if (dbName === 'wikidatawiki') { return 'www.wikidata.org'; }
  if (dbName === 'commonswiki') { return 'commons.wikimedia.org'; }
  var p = dbName.split('wiki');
  return p[0].replace(/_/g, '-') + '.wiki' + (p[1] || 'pedia') + '.org';
}

function api(host, data) {
  return new Promise(function (resolve) {
    var req = http.request({
      host: host,
      path: '/w/api.php?' + querystring.stringify(data),
      method: 'GET',
      headers: {
        'User-Agent': 'linkstranslator (github.com/ebraminio/linkstranslator)'
      }
    }, function (response) {
      var result = [];
      response.on('data', function (chunk) { result.push(chunk); });
      response.on('end', function () { resolve(result.join('')); });
    });
    // req.write(querystring.stringify(data)); for POST
    req.end();
  });
}

function getResolvedRedirectPages(pages, fromWiki, redirects) {
  return api(dbNameToOrigin(fromWiki), {
    action: 'query',
    format: 'json',
    redirects: '',
    titles: pages.join('|')
  }).then(function (result) {
    result = JSON.parse(result);
    if (!result.query) { return []; }
    var pages = result.query.pages;
    (result.query.redirects || []).forEach(function (x) { redirects[x.from] = x.to; });
    (result.query.normalized || []).forEach(function (x) { redirects[x.from] = x.to; });
    return Object.keys(pages).map(function (x) { return pages[x].title; });
  });
}

function getWikidataEntities(pages, fromWiki) {
  return api('www.wikidata.org', {
    action: 'wbgetentities',
    format: 'json',
    sites: fromWiki,
    titles: pages.join('|')
  }).then(function (result) {
    result = JSON.parse(result);
    var entities = result.entities || {};
    return Object.keys(entities).map(function (x) { return entities[x]; });
  });
}

function getLocalLink(titles, fromWiki, toWiki) {
  if ((titles || []).length === 0) { return Promise.resolve({}); }
  var pages = titles.map(function (x) { return x.replace(/_/g, ' '); });
  var redirects = {};

  // getResolvedRedirectPages and getWikidataEntities have 50 page limitation so
  var batches = [];
  for (var i = 0; i < titles.length; i += 20) {
    batches.push(titles.slice(i, i + 20));
  }

  return Promise.all(batches.map(function () {
    return getResolvedRedirectPages(pages, fromWiki, redirects).then(function (x) {
      return getWikidataEntities(x, fromWiki);
    });
  })).then(function (entitiesArray) {
    var equs = {};

    for (var i in entitiesArray) {
      var entities = entitiesArray[i];
      for (var j in entities) {
        var entity = entities[j];
        if (!entity.sitelinks || !entity.sitelinks[toWiki]) { continue; }

        // not updated Wikidata items may don't have title on their sitelinks
        var from = entity.sitelinks[fromWiki].title || entity.sitelinks[fromWiki];
        var to = entity.sitelinks[toWiki].title || entity.sitelinks[toWiki];

        equs[from] = to;
      }
    }

    var result = {};
    for (var i in titles) {
      var title = titles[i];
      var page = redirects[title] || title;
      if (equs[page]) { result[title] = equs[page]; }

      var normalized = title.replace(/_/g, ' ');
      page = redirects[normalized] || normalized;
      if (equs[page]) { result[title] = equs[page]; }
    }
    return result;
  });
}

module.exports = getLocalLink;

if (require.main === module) { // test and development
  var argv = require('minimist')(process.argv.slice(2));
  getLocalLink(argv._, argv.from || 'enwiki', argv.to || 'fawiki').then(function (x) {
    console.log(x);
  }, function (e) {
    console.log(e.stack);
  });
}
