var url = require('url');
var querystring = require('querystring');
var resolve = require('./resolve.js');

var NodeCache = require("node-cache");
var caches = {};
var enableCache = true;

function server(req, res) {
  if (req.method === 'GET') {
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': '*'
    });
    var query = querystring.parse(url.parse(req.url).query);

    var from = (query.from || 'enwiki').toLowerCase().match(/[a-z_]{1,20}/)[0];
    if (from.indexOf("wiki") === -1) { from = from + 'wiki'; }
    var to = (query.to || 'fawiki').toLowerCase().match(/[a-z_]{1,20}/)[0];
    if (to.indexOf("wiki") === -1) { to = to + 'wiki'; }

    var pages = [].concat(query.p || query['p[]'] || []).splice(0, 25);

    var cached = {};
    if (enableCache) {
      var cache = caches[from + '@' + to];
      if (!cache) {
        cache = new NodeCache({ stdTTL: 600, checkperiod: 320 });
        caches[from + '@' + to] = cache;
      }

      cached = cache.get(pages);
      pages = pages.filter(function (page) { return !cached[page]; });
    }

    resolve(pages, from, to).then(function (result) {
      if (enableCache) {
        for (var key in result) {
          cache.set(key, result[key]);
        }
        for (var key in cached) {
          result[key] = cached[key];
        }
      }
      res.end(JSON.stringify(result));
    }, function (e) {
      res.end(JSON.stringify({ error: e.toString() }));
    });
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
