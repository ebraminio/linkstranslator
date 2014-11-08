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
  return getResolvedRedirectPages(pages, fromWiki, redirects).then(function (x) {
    return getWikidataEntities(x, fromWiki);
  }).then(function (entities) {
    var equs = {};
    entities.forEach(function (x) {
      if (!x.sitelinks || !x.sitelinks[toWiki]) { return; }
      equs[x.sitelinks[fromWiki].title] = x.sitelinks[toWiki].title;
    });

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
