var http = require('q-io/http');
var querystring = require('querystring');

function getResolvedRedirectPages(pages, fromLang, redirects) {
  return http.request('http://' + fromLang + '.wikipedia.org/w/api.php?' + querystring.stringify({
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

function getWikidataEntities(pages, fromLang) {
  return http.request('http://www.wikidata.org/w/api.php?' + querystring.stringify({
    action: 'wbgetentities',
    format: 'json',
    sites: fromLang + 'wiki',
    titles: pages.join('|')
  })).then(function (result) { return result.body.read(); }).then(function (result) {
    var entities = JSON.parse(result).entities || {};
    return Object.keys(entities).map(function (x) { return entities[x]; });
  });
}

function getLocalLink(titles, fromLang, toLang) {
  var pages = titles.map(function (x) { return x.replace(/_/g, ' '); });
  var redirects = {};
  return getResolvedRedirectPages(pages, fromLang, redirects).then(function (x) {
    return getWikidataEntities(x, fromLang);
  }).then(function (entities) {
    var equs = {};
    entities.forEach(function (x) {
      if (!x.sitelinks || !x.sitelinks[toLang + 'wiki']) { return; }
      equs[x.sitelinks[fromLang + 'wiki'].title] = x.sitelinks[toLang + 'wiki'].title;
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

if (require.main === module) { // development
  getLocalLink(process.argv.slice(2), 'en', 'fa').then(function (x) {
    console.log(x);
  }, function (e) {
    console.log(e.stack);
  });
}
