var http = require('q-io/http');
var querystring = require('querystring');
var Q = require('q');

function dbNameToOrigin(dbName) {
  if (dbName === 'wikidatawiki') { return 'www.wikidata.org'; }
  if (dbName === 'commonswiki') { return 'commons.wikimedia.org'; }
  var p = dbName.split('wiki');
  return p[0].replace(/_/g, '-') + '.wiki' + (p[1] || 'pedia') + '.org';
}

function getResolvedRedirectPages(pages, fromWiki, redirects) {
  return http.request('http://' + dbNameToOrigin(fromWiki) + '/w/api.php?' + querystring.stringify({
    action: 'query',
    format: 'json',
    redirects: '',
    titles: pages.join('|')
  })).then(function (result) { return result.body.read(); }).then(function (result) {
    var result = JSON.parse(result);
    if (!result.query) { return []; }
    var pages = result.query.pages;
    (result.query.redirects || []).forEach(function (x) { redirects[x.from] = x.to; });
    return Object.keys(pages).map(function (x) { return pages[x].title; });
  });
}

function getWikidataEntities(pages, fromWiki) {
  return http.request('http://www.wikidata.org/w/api.php?' + querystring.stringify({
    action: 'wbgetentities',
    format: 'json',
    sites: fromWiki,
    titles: pages.join('|')
  })).then(function (result) { return result.body.read(); }).then(function (result) {
    var entities = JSON.parse(result).entities || {};
    return Object.keys(entities).map(function (x) { return entities[x]; });
  });
}

function getLocalLink(titles, fromWiki, toWiki) {
  if ((titles || []).length === 0) { return Q({}); }
  var pages = titles.map(function (x) { return x.replace(/_/g, ' '); });
  var redirects = {};

  // getResolvedRedirectPages and getWikidataEntities have 50 page limitation so
  var batches = [];
  for (var i = 0; i < titles.length; i += 50) {
    batches.push(titles.slice(i, i + 50));
  }

  return Q.all(batches.map(function () {
    return getResolvedRedirectPages(pages, fromWiki, redirects).then(function (x) {
      return getWikidataEntities(x, fromWiki);
    });
  })).then(function (entitiesArray) {
    var equs = {};

    // flat entitiesArray
    var entities = entitiesArray.reduce(function(a, b) { return a.concat(b); });

    for (var i in entities) {
      var entity = entities[i];
      if (!entity.sitelinks || !entity.sitelinks[toWiki]) { return; }

      // not updated Wikidata items may don't have title on their sitelinks
      var from = entity.sitelinks[fromWiki].title || entity.sitelinks[fromWiki];
      var to = entity.sitelinks[toWiki].title || entity.sitelinks[toWiki];

      equs[from] = to;
    }

    var result = {};
    titles.forEach(function (title) {
      var page = redirects[title] || title;
      if (equs[page]) { result[title] = equs[page]; }

      var normalized = title.replace(/_/g, ' ');
      page = redirects[normalized] || normalized;
      if (equs[page]) { result[title] = equs[page]; }
    });
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
