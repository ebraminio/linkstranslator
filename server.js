var url = require('url');
var querystring = require('querystring');
var resolve = require('./resolve.js');
var Q = require('q');

var NodeCache = require("node-cache");
var caches = {};

function server(req, res) {
  if (req.method === 'GET') {
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': '*'
    });
    var query = querystring.parse(url.parse(req.url).query);
    try {
      var from = (query.from || 'enwiki').match(/[\w_]{1,20}/)[0];
      var to = (query.to || 'fawiki').match(/[\w_]{1,20}/)[0];
      var cache = caches[from + '@' + to];
      if (!cache) {
        cache = new NodeCache({ stdTTL: 600, checkperiod: 320 });
        caches[from + '@' + to] = cache;
      }
      var pages = [].concat(query.p || query['p[]'] || []).splice(0, 25);

      var cached = cache.get(pages);
      var notCached = pages.filter(function (page) { return !cached[page]; });

      (notCached.length ? resolve(notCached, from, to) : Q({})).then(function (result) {
        Object.keys(result).map(function (key) { cache.set(key, result[key]); });
        Object.keys(cached).map(function (key) { result[key] = cached[key]; });
        res.end(JSON.stringify(result));
      }, function (e) {
        res.end(JSON.stringify({ error: e.toString() }));
      });
    } catch (e) { res.end(JSON.stringify({ error: e.toString() })); };
  } else {
    res.writeHead(501);
    res.end();
  }
}

if (process.env.FCGI_MODE) { // called through fcgi
  require('node-fastcgi').createServer(server).listen();
} else { // called directly, development
  var port = process.argv[2] || 19876;
  require('http').createServer(server).listen(port);
  console.log('Server running at http://127.0.0.1:' + port + '/');
}
