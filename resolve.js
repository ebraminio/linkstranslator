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
    var entities = JSON.parse(result).entities;
    return Object.keys(entities).map(function (x) { return entities[x]; });
  });
}

function getLocalLink(pages, fromLang, toLang) {
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
    pages.forEach(function (title) {
      var page = redirects[title] || title;
      if (equs[page]) { result[title] = equs[page]; }
    });
    return result;
  });
}

//getLocalLink(require('querystring').parse('p=I.R.Iran&p=Iran').p, 'en', 'fa')
//getLocalLink(['IR', 'Iran'], 'en', 'fa')
//  .then(function (x) { return console.log(x); }, function (x) { return console.log(x.stack); });
module.exports = getLocalLink;
